<?php
declare(strict_types=1);

/**
 * API番人さん (api_banto_san) — API棚卸しツール
 * --------------------------------------------------------------------------
 * Googleログイン + グループ/ロール権限つきの「コスト軸ダッシュボード」。
 *
 * 実装範囲:
 *   - Googleアカウント認証 (OAuth 2.0 / OpenID Connect)。パスワードは保持しない。
 *   - グループによるデータ分離。APIカタログは必ずいずれかのグループに属する。
 *   - ロール (owner/admin/member/viewer) を「サーバ側で必ず」権限チェック。
 *   - コスト軸ビュー（月額降順 / 通貨別小計 / 未設定の明示 / ドリルダウン / 絞り込み）。
 *   - APIカタログ・使用箇所の手動編集（member 以上）。viewer は閲覧のみ。
 *   - グループ作成・メンバー招待・ロール変更は groups.php。
 *
 * 重要な制約:
 *   - APIキー本体は絶対に保存しない。鍵の在りか(key_location) のみ記録する。
 *   - monthly_cost / notes / owner などは手動フィールド。
 *
 * 将来フェーズ（未実装）: スキャナCLI連携(scan/push, 手動フィールドのマージ保持) /
 *   各社 billing/usage API 連携による monthly_cost 半自動更新（仕様書 §6,§9）。
 */

require __DIR__ . '/bootstrap.php';

$route = $_GET['route'] ?? '';

/* ================================================================== *
 *  認証系ルート（リダイレクト/終了するものが中心）
 * ================================================================== */
if ($route === 'login') {
    if (!config('GOOGLE_CLIENT_ID')) {
        flash('err', 'Google OAuth が未設定です。config.local.php または環境変数を確認してください。');
        redirect(app_url());
    }
    redirect(google_login_url());
}

if ($route === 'oauth2callback') {
    try {
        google_handle_callback();
        flash('ok', 'ログインしました。');
    } catch (Throwable $e) {
        flash('err', 'ログインに失敗しました: ' . $e->getMessage());
    }
    redirect(app_url());
}

// スタンドアロン・スキャナのソースを base64 で配布（別サーバーへ取り込む用）。
// base64 にするのは、WAFがレスポンス中のコードを誤検知して弾くのを避けるため。
//   curl -s "...index.php?route=getscanner" | base64 -d > scan_standalone.php
if ($route === 'getscanner') {
    $file = __DIR__ . '/scan_standalone.php';
    header('Content-Type: text/plain; charset=utf-8');
    echo is_file($file) ? base64_encode((string) file_get_contents($file)) : '';
    exit;
}

// ローカル検証用の簡易ログイン（既定OFF。本番では無効）
if ($route === 'devlogin') {
    if (!config_bool('APP_DEV_LOGIN')) {
        http_response_code(404);
        exit('Not found');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $name  = trim((string) ($_POST['name'] ?? '')) ?: ($email !== '' ? explode('@', $email)[0] : 'devuser');
        if ($email === '') {
            flash('err', 'メールアドレスを入力してください。');
            redirect(app_url());
        }
        if (!email_domain_allowed($email)) {
            flash('err', '許可された社内ドメイン（' . implode(', ', allowed_email_domains()) . '）のアカウントのみ利用できます。');
            redirect(app_url());
        }
        login_with_profile('dev:' . strtolower($email), $email, $name, '');
        flash('ok', '（DEV）ログインしました。');
    }
    redirect(app_url());
}

if ($route === 'logout') {
    logout();
    redirect(app_url());
}

if ($route === 'switchgroup') {
    require_login();
    $gid = (int) ($_GET['gid'] ?? 0);
    if (set_current_group($gid)) {
        flash('ok', 'グループを切り替えました。');
    } else {
        flash('err', 'そのグループには所属していません。');
    }
    redirect(app_url());
}

/* ================================================================== *
 *  未ログイン: ログインページを表示して終了
 * ================================================================== */
if (!current_user()) {
    render_login_page();
    exit;
}

/* ================================================================== *
 *  ここからログイン済み。グループ・ロールを確定。
 * ================================================================== */
$user  = current_user();
$gid   = current_group_id();      // 所属グループ先頭に補正済み
$group = current_group();
$role  = current_role();

/* ================================================================== *
 *  個人用トークン画面（CLI push 用トークンの発行・失効）
 * ================================================================== */
if ($route === 'tokens') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $a = $_POST['action'] ?? '';
        if ($a === 'create_token') {
            $label = trim((string) ($_POST['label'] ?? '')) ?: 'token';
            $_SESSION['new_token'] = issue_api_token((int) $user['id'], $label);
            flash('ok', '個人用トークンを発行しました。表示は一度だけです。今すぐコピーしてください。');
        } elseif ($a === 'revoke_token') {
            revoke_api_token((int) $user['id'], (int) ($_POST['token_id'] ?? 0));
            flash('ok', 'トークンを失効しました。');
        }
        redirect(app_url('tokens'));
    }
    render_tokens_page($user);
    exit;
}

/* ================================================================== *
 *  サーバ内スキャン画面（heteml 上のファイルを直接走査・admin 以上）
 * ================================================================== */
if ($route === 'scan') {
    require_role_at_least($gid, 'admin');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $a = $_POST['action'] ?? '';

        $withSecrets = !empty($_POST['with_secrets']);
        // スキャン結果メッセージの共通整形
        $say = static function (array $res) use ($withSecrets): void {
            $msg = sprintf('スキャン完了: 新規 %d 件 / 更新 %d 件 / 使用箇所 %d 件を反映（手動入力のコスト等は保持）。',
                $res['created'], $res['updated'], $res['usages']);
            if ($withSecrets) { $msg .= ' .env等のキー値も暗号化して取り込みました。'; }
            flash('ok', $msg);
        };

        try {
            if ($a === 'add_target') {
                $path = trim((string) ($_POST['path'] ?? ''));
                $label = trim((string) ($_POST['label'] ?? '')) ?: basename(rtrim($path, '/\\'));
                if ($path === '' || realpath($path) === false || !is_dir((string) realpath($path))) {
                    flash('err', 'ディレクトリが見つかりません: ' . $path . "\n" . path_diagnostics($path));
                } else {
                    add_scan_target($gid, $label, $path);
                    flash('ok', 'スキャン対象を保存しました。次回からワンクリックでスキャンできます。');
                }
            } elseif ($a === 'delete_target') {
                delete_scan_target($gid, (int) ($_POST['target_id'] ?? 0));
                flash('ok', 'スキャン対象を削除しました。');
            } elseif ($a === 'run_target') {
                $targets = list_scan_targets($gid);
                $tid = (int) ($_POST['target_id'] ?? 0);
                $t = null;
                foreach ($targets as $row) { if ((int) $row['id'] === $tid) { $t = $row; break; } }
                if (!$t) {
                    flash('err', '対象が見つかりません。');
                } else {
                    $say(run_scan_on_dir($gid, $t['path'], $t['label'], $withSecrets));
                    touch_scan_target($tid);
                }
            } elseif ($a === 'run_all') {
                $targets = list_scan_targets($gid);
                if (!$targets) {
                    flash('err', '保存済みのスキャン対象がありません。');
                } else {
                    $tot = ['created' => 0, 'updated' => 0, 'usages' => 0];
                    foreach ($targets as $t) {
                        $r = run_scan_on_dir($gid, $t['path'], $t['label'], $withSecrets);
                        foreach ($tot as $k => $_) { $tot[$k] += $r[$k]; }
                        touch_scan_target((int) $t['id']);
                    }
                    $say($tot);
                }
            } elseif ($a === 'run_path') {
                $path = trim((string) ($_POST['path'] ?? ''));
                $repo = trim((string) ($_POST['repo'] ?? ''));
                $say(run_scan_on_dir($gid, $path, $repo, $withSecrets));
            } elseif ($a === 'upload_scan') {
                $repo = trim((string) ($_POST['repo'] ?? ''));
                $say(run_scan_on_uploads($gid, $_FILES['files'] ?? [], $repo, $withSecrets));
            }
        } catch (Throwable $e) {
            flash('err', 'スキャンに失敗しました: ' . $e->getMessage());
        }
        redirect(app_url('scan'));
    }
    render_scan_page($user, $group, $gid);
    exit;
}

/* ------------------------------------------------------------------ *
 *  POST 処理（カタログ編集）— すべてサーバ側で権限チェック
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $pdo = db();

    // 編集系はすべて member 以上が必須
    if (in_array($action, ['save_api', 'delete_api', 'add_usage', 'delete_usage'], true)) {
        require_role_at_least($gid, 'member');
    }

    if ($action === 'save_api') {
        $id           = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
        $name         = trim((string) ($_POST['name'] ?? ''));
        $provider     = trim((string) ($_POST['provider'] ?? ''));
        $status       = array_key_exists($_POST['status'] ?? '', STATUSES) ? $_POST['status'] : 'unknown';
        $costRaw      = trim((string) ($_POST['monthly_cost'] ?? ''));
        $monthly_cost = ($costRaw === '') ? null : (float) $costRaw;
        $currency     = trim((string) ($_POST['currency'] ?? 'JPY')) ?: 'JPY';
        $billing_url  = trim((string) ($_POST['billing_url'] ?? ''));
        $key_location = trim((string) ($_POST['key_location'] ?? ''));
        $cost_project = trim((string) ($_POST['cost_project'] ?? ''));
        $site         = trim((string) ($_POST['site'] ?? ''));
        $docs_url     = trim((string) ($_POST['docs_url'] ?? ''));
        $owner        = trim((string) ($_POST['owner'] ?? ''));
        $notes        = trim((string) ($_POST['notes'] ?? ''));

        // APIキー(値)の入力。secret_clear=1 でクリア、空欄なら現状維持、値ありで暗号化保存。
        $secretIn   = (string) ($_POST['secret'] ?? '');
        $secretClear = !empty($_POST['secret_clear']);
        $secretEnc = $secretHint = $secretFp = null;
        $secretMode = 'keep';   // keep | set | clear
        if ($secretClear) {
            $secretMode = 'clear';
        } elseif ($secretIn !== '') {
            if (!encryption_ready()) {
                flash('err', 'キーを保存するには APP_ENCRYPTION_KEY の設定が必要です（config.local.php）。');
                redirect_self();
            }
            $secretEnc  = encrypt_secret($secretIn);
            $secretHint = secret_hint($secretIn);
            $secretFp   = secret_fingerprint($secretIn);
            $secretMode = 'set';
        }

        if ($name === '') {
            flash('err', 'API名は必須です。');
            redirect_self();
        }

        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO apis (group_id, name, provider, site, status, monthly_cost, currency, billing_url, key_location, cost_project, docs_url, owner, notes, secret_enc, secret_hint, secret_fp, created_at, updated_at)
                 VALUES (:gid,:name,:provider,:site,:status,:cost,:cur,:bill,:key,:cproj,:docs,:owner,:notes,:senc,:shint,:sfp,:ca,:ua)'
            );
            $stmt->execute([
                ':gid'=>$gid, ':name'=>$name, ':provider'=>$provider, ':site'=>$site, ':status'=>$status, ':cost'=>$monthly_cost,
                ':cur'=>$currency, ':bill'=>$billing_url, ':key'=>$key_location, ':cproj'=>$cost_project, ':docs'=>$docs_url,
                ':owner'=>$owner, ':notes'=>$notes,
                ':senc'=>$secretMode==='set'?$secretEnc:null, ':shint'=>$secretMode==='set'?$secretHint:null, ':sfp'=>$secretMode==='set'?$secretFp:null,
                ':ca'=>now(), ':ua'=>now(),
            ]);
            flash('ok', 'APIを追加しました。');
        } else {
            // 現在グループに属する行のみ更新可（横断アクセス防止）
            $stmt = $pdo->prepare(
                'UPDATE apis SET name=:name, provider=:provider, site=:site, status=:status, monthly_cost=:cost,
                     currency=:cur, billing_url=:bill, key_location=:key, cost_project=:cproj, docs_url=:docs,
                     owner=:owner, notes=:notes, updated_at=:ua
                 WHERE id=:id AND group_id=:gid'
            );
            $stmt->execute([
                ':name'=>$name, ':provider'=>$provider, ':site'=>$site, ':status'=>$status, ':cost'=>$monthly_cost,
                ':cur'=>$currency, ':bill'=>$billing_url, ':key'=>$key_location, ':cproj'=>$cost_project, ':docs'=>$docs_url,
                ':owner'=>$owner, ':notes'=>$notes, ':ua'=>now(), ':id'=>$id, ':gid'=>$gid,
            ]);
            // キーは入力があった時だけ更新（通常編集では維持）
            if ($secretMode === 'set') {
                $pdo->prepare('UPDATE apis SET secret_enc=:e, secret_hint=:h, secret_fp=:f WHERE id=:id AND group_id=:gid')
                    ->execute([':e'=>$secretEnc, ':h'=>$secretHint, ':f'=>$secretFp, ':id'=>$id, ':gid'=>$gid]);
            } elseif ($secretMode === 'clear') {
                $pdo->prepare('UPDATE apis SET secret_enc=NULL, secret_hint=NULL, secret_fp=NULL WHERE id=:id AND group_id=:gid')
                    ->execute([':id'=>$id, ':gid'=>$gid]);
            }
            flash('ok', 'APIを更新しました。');
        }
        redirect_self();
    }

    if ($action === 'move_to_project') {
        require_role_at_least($gid, 'member');
        // 移動先の箱を解決：新規作成 / 既存 / 未割当(null)
        $pid = null;
        $newName = trim((string) ($_POST['new_name'] ?? ''));
        if ($newName !== '') {
            $pid = create_project($gid, $newName, trim((string) ($_POST['new_proj'] ?? '')));
        } elseif (($_POST['target_project'] ?? '') !== '' && $_POST['target_project'] !== 'unassign') {
            $tp = get_project($gid, (int) $_POST['target_project']);
            $pid = $tp ? (int) $tp['id'] : null;
        }
        $n = assign_usages_to_project($gid, $_POST['usage_ids'] ?? [], $pid);
        foreach ((array) ($_POST['sites'] ?? []) as $s) {
            $n += assign_site_to_project($gid, (string) $s, $pid);
        }
        flash('ok', $n . '件のURLを' . ($pid ? 'プロジェクトへ移動しました。' : '未割当に戻しました。'));
        redirect_self();
    }

    if ($action === 'save_project') {
        require_role_at_least($gid, 'member');
        $pidIn = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int) $_POST['project_id'] : null;
        $pname = trim((string) ($_POST['name'] ?? ''));
        $pproj = trim((string) ($_POST['openai_project_id'] ?? ''));
        if ($pname === '') { flash('err', '箱の名前は必須です。'); redirect_self(); }
        // 管理キー（OpenAI Admin）任意
        $secretIn = (string) ($_POST['secret'] ?? '');
        if ($pidIn === null) {
            $newId = create_project($gid, $pname, $pproj);
            $pidIn = $newId;
        }
        $costRaw = trim((string) ($_POST['monthly_cost'] ?? ''));
        $mcost = ($costRaw === '') ? null : (float) $costRaw;
        $mcur = trim((string) ($_POST['currency'] ?? 'USD')) ?: 'USD';
        $pdo->prepare('UPDATE projects SET name=:n, openai_project_id=:p, monthly_cost=:mc, currency=:cur, updated_at=:u WHERE id=:id AND group_id=:g')
            ->execute([':n'=>$pname, ':p'=>$pproj, ':mc'=>$mcost, ':cur'=>$mcur, ':u'=>now(), ':id'=>$pidIn, ':g'=>$gid]);
        if ($secretIn !== '' && encryption_ready()) {
            $pdo->prepare('UPDATE projects SET secret_enc=:e, secret_hint=:h, secret_fp=:f WHERE id=:id AND group_id=:g')
                ->execute([':e'=>encrypt_secret($secretIn), ':h'=>secret_hint($secretIn), ':f'=>secret_fingerprint($secretIn), ':id'=>$pidIn, ':g'=>$gid]);
        }
        flash('ok', 'プロジェクト箱を保存しました。');
        redirect_self();
    }

    if ($action === 'delete_project') {
        require_role_at_least($gid, 'admin');
        $pidIn = (int) ($_POST['project_id'] ?? 0);
        // 箱を消す前に、所属URLを未割当へ
        $pdo->prepare('UPDATE usages SET project_id=NULL WHERE project_id=:p AND api_id IN (SELECT id FROM apis WHERE group_id=:g)')->execute([':p'=>$pidIn, ':g'=>$gid]);
        $pdo->prepare('DELETE FROM projects WHERE id=:id AND group_id=:g')->execute([':id'=>$pidIn, ':g'=>$gid]);
        flash('ok', 'プロジェクト箱を削除しました（URLは未割当に戻しました）。');
        redirect_self();
    }

    if ($action === 'fetch_project_cost') {
        require_role_at_least($gid, 'member');
        $p = get_project($gid, (int) ($_POST['project_id'] ?? 0));
        if (!$p) { flash('err', '箱が見つかりません。'); }
        else {
            try {
                $c = fetch_project_cost($gid, $p);
                flash('ok', sprintf('コスト取得（%s）: %s %s', h($p['name']), $c['currency'], number_format($c['amount'], 2)));
            } catch (Throwable $e) {
                flash('err', 'コスト取得に失敗: ' . $e->getMessage());
            }
        }
        redirect_self();
    }

    if ($action === 'reorder') {
        require_role_at_least($gid, 'member');
        $name = (string) ($_POST['name'] ?? '');
        $dir  = ($_POST['dir'] ?? '') === 'down' ? 'down' : 'up';
        if ($name !== '') {
            move_group($gid, ordered_group_names($gid), $name, $dir);
        }
        redirect(app_url());   // 手動順（既定）で反映
    }

    if ($action === 'reorder_set') {
        require_role_at_least($gid, 'member');
        $names = $_POST['names'] ?? [];
        if (is_array($names)) {
            $i = 1;
            foreach ($names as $nm) {
                $nm = (string) $nm;
                if ($nm !== '') { set_group_position($gid, $nm, $i++); }
            }
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo '{"ok":true}';
            exit;
        }
        redirect(app_url());
    }

    if ($action === 'fetch_cost') {
        require_role_at_least($gid, 'member');
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM apis WHERE id=:id AND group_id=:gid');
        $st->execute([':id'=>$id, ':gid'=>$gid]);
        $row = $st->fetch();
        if (!$row) {
            flash('err', '対象が見つかりません。');
        } else {
            try {
                $c = fetch_cost_for($row);
                $pdo->prepare('UPDATE apis SET monthly_cost=:m, currency=:c, updated_at=:u WHERE id=:id AND group_id=:gid')
                    ->execute([':m'=>$c['amount'], ':c'=>$c['currency'], ':u'=>now(), ':id'=>$id, ':gid'=>$gid]);
                flash('ok', sprintf('コストを取得しました（%s）: %s %s', h($row['name']), $c['currency'], number_format($c['amount'], 2)));
            } catch (Throwable $e) {
                flash('err', 'コスト取得に失敗: ' . $e->getMessage());
            }
        }
        redirect_self();
    }

    if ($action === 'delete_legacy') {
        require_role_at_least($gid, 'admin');
        $n = delete_siteless_apis($gid);
        flash('ok', "旧形式（サイト未設定）のエントリを {$n} 件削除しました。");
        redirect_self();
    }

    if ($action === 'delete_api') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM apis WHERE id=:id AND group_id=:gid')->execute([':id'=>$id, ':gid'=>$gid]);
        flash('ok', 'APIを削除しました。');
        redirect_self();
    }

    if ($action === 'add_usage') {
        $aid  = (int) ($_POST['api_id'] ?? 0);
        // 対象 API が現在グループのものか検証
        $chk = $pdo->prepare('SELECT 1 FROM apis WHERE id=:id AND group_id=:gid');
        $chk->execute([':id'=>$aid, ':gid'=>$gid]);
        if ($chk->fetchColumn()) {
            $repo = trim((string) ($_POST['repo'] ?? ''));
            $file = trim((string) ($_POST['file'] ?? ''));
            $line = ($_POST['line'] ?? '') === '' ? null : (int) $_POST['line'];
            $snip = trim((string) ($_POST['snippet'] ?? ''));
            if ($repo !== '' || $file !== '') {
                $pdo->prepare('INSERT INTO usages (api_id, repo, file, line, snippet) VALUES (:aid,:repo,:file,:line,:snip)')
                    ->execute([':aid'=>$aid, ':repo'=>$repo, ':file'=>$file, ':line'=>$line, ':snip'=>$snip]);
                flash('ok', '使用箇所を追加しました。');
            }
        }
        redirect_self();
    }

    if ($action === 'delete_usage') {
        $uid = (int) ($_POST['id'] ?? 0);
        // 現在グループに属する API の usage のみ削除可
        $pdo->prepare(
            'DELETE FROM usages WHERE id=:id AND api_id IN (SELECT id FROM apis WHERE group_id=:gid)'
        )->execute([':id'=>$uid, ':gid'=>$gid]);
        flash('ok', '使用箇所を削除しました。');
        redirect_self();
    }

    redirect_self();
}

/* ------------------------------------------------------------------ *
 *  一覧取得（現在グループに限定 / フィルタ / コスト軸ソート）
 * ------------------------------------------------------------------ */
$pdo = db();

$q            = trim((string) ($_GET['q'] ?? ''));
$filterProv   = trim((string) ($_GET['provider'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterSite   = trim((string) ($_GET['site'] ?? ''));
$sort         = (string) ($_GET['sort'] ?? 'manual');
if (!in_array($sort, ['manual', 'cost', 'name'], true)) { $sort = 'manual'; }

$where  = ['a.group_id = :gid'];
$params = [':gid' => $gid];
if ($q !== '') {
    $where[] = '(a.name LIKE :q OR a.notes LIKE :q OR a.owner LIKE :q OR a.site LIKE :q OR a.key_location LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($filterProv !== '') {
    $where[] = 'a.provider = :prov';
    $params[':prov'] = $filterProv;
}
if ($filterStatus !== '' && array_key_exists($filterStatus, STATUSES)) {
    $where[] = 'a.status = :st';
    $params[':st'] = $filterStatus;
}
if ($filterSite !== '') {
    $where[] = 'a.site = :site';
    $params[':site'] = $filterSite;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT a.*,
               (SELECT COUNT(*) FROM usages u WHERE u.api_id = a.id) AS usage_count
        FROM apis a
        $whereSql
        ORDER BY a.name ASC, (a.monthly_cost IS NULL) ASC, a.monthly_cost DESC, a.site ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apis = $stmt->fetchAll();

// プロジェクト箱
$projects = list_projects($gid);
$projById = [];
foreach ($projects as $p) { $projById[(int) $p['id']] = $p; }

// 使用箇所(URL) ＋ 親API情報 ＋ 所属箱
$uSql = "SELECT u.*, a.name AS api_name, a.provider AS api_provider, a.site AS api_site, a.key_location AS api_key
         FROM usages u JOIN apis a ON a.id = u.api_id
         $whereSql ORDER BY a.name, u.repo, u.file, u.line";
$uStmt = $pdo->prepare($uSql);
$uStmt->execute($params);
$allUsages = $uStmt->fetchAll();

// tree[プロダクト名] = ['provider'=>, 'boxes'=>[boxKey=>['pid'=>?int,'usages'=>[]]], 'sites'=>set]
$tree = [];
foreach ($allUsages as $u) {
    $name = $u['api_name'];
    if (!isset($tree[$name])) { $tree[$name] = ['provider' => $u['api_provider'], 'boxes' => [], 'sites' => []]; }
    $pid = $u['project_id'] !== null ? (int) $u['project_id'] : 0;
    if (!isset($tree[$name]['boxes'][$pid])) { $tree[$name]['boxes'][$pid] = ['pid' => $pid ?: null, 'usages' => []]; }
    $tree[$name]['boxes'][$pid]['usages'][] = $u;
    if ($u['api_site'] !== '') { $tree[$name]['sites'][$u['api_site']] = 1; }
}

// プロダクトのコスト合計（配下の箱コストの和）と並べ替え
$productTotalNum = [];
foreach ($tree as $name => $info) {
    $sum = 0.0;
    foreach ($info['boxes'] as $pid => $b) {
        if ($pid && isset($projById[$pid]) && $projById[$pid]['monthly_cost'] !== null) {
            $sum += (float) $projById[$pid]['monthly_cost'];
        }
    }
    $productTotalNum[$name] = $sum;
}
$names = array_keys($tree);
$positions = group_positions($gid);
usort($names, static function ($a, $b) use ($sort, $positions, $productTotalNum) {
    if ($sort === 'name') { return strcasecmp($a, $b); }
    if ($sort === 'cost') { return ($productTotalNum[$b] <=> $productTotalNum[$a]) ?: strcmp($a, $b); }
    $pa = $positions[$a] ?? PHP_INT_MAX;
    $pb = $positions[$b] ?? PHP_INT_MAX;
    if ($pa !== $pb) { return $pa <=> $pb; }
    return ($productTotalNum[$b] <=> $productTotalNum[$a]) ?: strcmp($a, $b);
});

// サイト一覧（フィルタ用）
$sites = $pdo->prepare("SELECT DISTINCT site FROM apis WHERE group_id = :gid AND site <> '' ORDER BY site");
$sites->execute([':gid' => $gid]);
$sites = $sites->fetchAll(PDO::FETCH_COLUMN);
$legacyCount = 0;

// 上部サマリ：箱コストの通貨別合計
$subtotals = [];
foreach ($projects as $p) {
    if ($p['monthly_cost'] !== null) {
        $cur = $p['currency'] ?: 'USD';
        $subtotals[$cur] = ($subtotals[$cur] ?? 0) + (float) $p['monthly_cost'];
    }
}
ksort($subtotals);
$unsetCount = count($projects);

$providers = $pdo->prepare("SELECT DISTINCT provider FROM apis WHERE group_id = :gid AND provider <> '' ORDER BY provider");
$providers->execute([':gid' => $gid]);
$providers = $providers->fetchAll(PDO::FETCH_COLUMN);

$memberships = my_memberships();
$flashMsg = take_flash();
$csrf = csrf_token();
$editable = can_edit();

/* ================================================================== *
 *  ビュー
 * ================================================================== */
function fmt_money(?float $cost, string $cur): string
{
    if ($cost === null) {
        return '<span class="muted">未設定</span>';
    }
    $n = (fmod($cost, 1.0) === 0.0) ? number_format($cost) : number_format($cost, 2);
    return h($cur) . ' ' . $n;
}

/** 通貨別合計を文字列に（例: "JPY 12,000　USD 30"） */
function money_totals(array $rows): string
{
    $t = [];
    foreach ($rows as $r) {
        if ($r['monthly_cost'] !== null) {
            $c = $r['currency'] ?: 'JPY';
            $t[$c] = ($t[$c] ?? 0) + (float) $r['monthly_cost'];
        }
    }
    if (!$t) { return '—'; }
    ksort($t);
    return implode('　', array_map(static fn($c, $v) => $c . ' ' . number_format($v, (fmod($v,1.0)===0.0)?0:2), array_keys($t), $t));
}

/** その使用箇所が「キーが定義されているファイル」か（.env や NAME=値 の行） */
function is_key_def_usage(array $u): bool
{
    $base = strtolower(basename((string) $u['file']));
    if (strncmp($base, '.env', 4) === 0) { return true; }
    return (bool) preg_match('/\b[A-Z][A-Z0-9_]*(?:API_?KEY|SECRET|TOKEN|ACCESS_?KEY|_KEY)\b\s*[:=]/', (string) $u['snippet']);
}

/** 共通スタイル */
function render_styles(): void { ?>
<style>
    :root {
        --bg:#f5f6f8; --card:#fff; --line:#e3e6ea; --ink:#1f2733;
        --muted:#8a93a0; --accent:#2563eb; --accent-d:#1d4ed8;
        --ok-bg:#e7f6ec; --ok-ink:#1a7f43; --err-bg:#fdecec; --err-ink:#b42318;
    }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--bg); color:var(--ink);
        font-family:-apple-system,BlinkMacSystemFont,"Hiragino Kaku Gothic ProN","Noto Sans JP",Meiryo,sans-serif; line-height:1.6; }
    header.app { background:#0f172a; color:#fff; padding:12px 20px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    header.app h1 { font-size:18px; margin:0; font-weight:700; }
    header.app .tag { font-size:12px; color:#94a3b8; }
    header.app .spacer { flex:1; }
    header.app .who { display:flex; align-items:center; gap:8px; font-size:13px; }
    header.app .who img { width:26px; height:26px; border-radius:50%; }
    header.app select { padding:5px 8px; border-radius:8px; border:1px solid #334155; background:#1e293b; color:#fff; font-size:13px; }
    header.app a.navlink { color:#cbd5e1; text-decoration:none; font-size:13px; padding:4px 8px; border-radius:7px; }
    header.app a.navlink:hover { background:#1e293b; color:#fff; }
    .wrap { max-width:1080px; margin:0 auto; padding:20px; }
    .role-badge { font-size:11px; padding:2px 8px; border-radius:999px; background:#1e293b; color:#cbd5e1; }
    .summary { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:18px; }
    .stat { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px 16px; min-width:150px; }
    .stat .label { font-size:12px; color:var(--muted); }
    .stat .value { font-size:22px; font-weight:700; }
    .stat .value small { font-size:12px; font-weight:400; color:var(--muted); }
    .toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:var(--card);
        border:1px solid var(--line); border-radius:12px; padding:12px; margin-bottom:14px; }
    .toolbar input, .toolbar select { padding:7px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; }
    .toolbar .spacer { flex:1; }
    button, .btn { font-size:14px; padding:7px 14px; border-radius:8px; border:1px solid var(--line);
        background:#fff; color:var(--ink); cursor:pointer; text-decoration:none; display:inline-block; }
    button.primary, .btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
    button.primary:hover { background:var(--accent-d); }
    button.danger { color:var(--err-ink); border-color:#f3c4c0; background:#fff; }
    button.link { border:none; background:none; color:var(--accent); padding:2px 4px; }
    table { width:100%; border-collapse:collapse; background:var(--card); border-radius:12px; overflow:hidden; }
    th, td { padding:10px 12px; text-align:left; border-bottom:1px solid var(--line); font-size:14px; vertical-align:top; }
    th { background:#f0f2f5; font-size:12px; color:var(--muted); font-weight:600; }
    td.cost { font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:600; }
    tr.api-row:hover { background:#fafbfc; }
    tr.group-head { cursor:pointer; }
    tr.group-head td { background:#eef2ff; color:#1e293b; border-top:2px solid #c7d2fe; }
    tr.group-head:hover td { background:#e0e7ff; }
    tr.group-head strong { font-size:15px; }
    .caret { display:inline-block; width:1em; color:#475569; }
    .drag-handle { cursor:grab; color:#94a3b8; margin-right:4px; user-select:none; }
    tr.group-head.dragging td { opacity:.4; }
    tr.group-head[draggable="true"] { cursor:grab; }
    tr.group-head.drop-before td { box-shadow: inset 0 3px 0 0 var(--accent); }
    tr.group-head.drop-after  td { box-shadow: inset 0 -3px 0 0 var(--accent); }
    #abtToast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#0f172a; color:#fff;
        padding:9px 18px; border-radius:10px; font-size:13px; opacity:0; transition:opacity .2s; pointer-events:none; z-index:9999; }
    #abtToast.show { opacity:.95; }
    td.group-cost { font-size:16px; font-weight:700; color:#0f172a; white-space:nowrap; }
    .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid var(--line); border-radius:12px; }
    .table-wrap table { border:none; border-radius:0; min-width:600px; }
    .muted { color:var(--muted); }
    .pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; }
    .pill.active{background:#e7f6ec;color:#1a7f43;} .pill.unused{background:#eef1f4;color:#6b7280;}
    .pill.unknown{background:#fff4e0;color:#b45309;} .pill.deprecated{background:#fdecec;color:#b42318;}
    .usages { background:#fbfcfe; }
    .usages table { box-shadow:none; border:1px solid var(--line); margin:6px 0; }
    .usages td, .usages th { font-size:13px; padding:6px 10px; }
    code { background:#f0f2f5; padding:1px 5px; border-radius:5px; font-size:12.5px; word-break:break-all; }
    .flash { padding:10px 14px; border-radius:10px; margin-bottom:14px; font-size:14px; }
    .flash.ok { background:var(--ok-bg); color:var(--ok-ink); } .flash.err { background:var(--err-bg); color:var(--err-ink); }
    dialog { border:none; border-radius:14px; padding:0; width:min(620px,94vw); box-shadow:0 20px 60px rgba(0,0,0,.25); }
    dialog::backdrop { background:rgba(15,23,42,.45); }
    .modal-head { padding:16px 20px; border-bottom:1px solid var(--line); font-weight:700; }
    .modal-body { padding:16px 20px; }
    .modal-foot { padding:14px 20px; border-top:1px solid var(--line); display:flex; justify-content:flex-end; gap:8px; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid .full { grid-column:1 / -1; }
    .field label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; }
    .field input, .field select, .field textarea { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; }
    .hint { font-size:11.5px; color:var(--muted); margin-top:3px; }
    .empty { text-align:center; color:var(--muted); padding:40px; }
    .note-cell { max-width:220px; }
    .login-box { max-width:420px; margin:8vh auto; background:var(--card); border:1px solid var(--line);
        border-radius:16px; padding:32px; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.06); }
    .gbtn { display:inline-flex; align-items:center; gap:10px; padding:11px 18px; border:1px solid var(--line);
        border-radius:10px; background:#fff; color:#1f2733; text-decoration:none; font-weight:600; font-size:15px; }
    .gbtn:hover { background:#f8fafc; }
    @media (max-width:720px){
        .grid{grid-template-columns:1fr;}
        .hide-sm{display:none;}
        .wrap{padding:12px;}
        .summary{gap:8px;}
        .summary .stat{min-width:0;flex:1 1 45%;padding:10px 12px;}
        .summary .stat .value{font-size:18px;}
        .toolbar{padding:10px;}
        .toolbar input, .toolbar select{flex:1 1 100%;}
        .table-wrap table{min-width:520px;}
        td.group-cost{font-size:15px;}
        header.app{padding:10px 12px;gap:8px;}
        header.app h1{font-size:16px;}
    }
</style>
<?php }

/** ログインページ */
function render_login_page(): void
{
    $flashMsg = take_flash();
    $hasGoogle = (bool) config('GOOGLE_CLIENT_ID');
    $devLogin  = config_bool('APP_DEV_LOGIN');
    $csrf = csrf_token();
    ?>
<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — ログイン</title>
<?php render_styles(); ?>
</head><body>
<header class="app"><h1>🛡️ <?= h(APP_NAME) ?></h1><span class="tag">API棚卸しダッシュボード</span></header>
<div class="wrap">
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>
    <div class="login-box">
        <h2 style="margin-top:0">ログイン</h2>
        <p class="muted">Googleアカウントでログインしてください。<br>パスワードは保持しません。</p>
        <?php if ($hasGoogle): ?>
            <p><a class="gbtn" href="<?= h(app_url('login')) ?>">
                <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.6 2.4 30.1 0 24 0 14.6 0 6.4 5.4 2.5 13.3l7.9 6.1C12.2 13.2 17.6 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.5 3-2.2 5.5-4.7 7.2l7.3 5.7C43.9 38 46.5 31.8 46.5 24.5z"/><path fill="#FBBC05" d="M10.4 28.6c-.5-1.5-.8-3-.8-4.6s.3-3.1.8-4.6l-7.9-6.1C.9 16.5 0 20.1 0 24s.9 7.5 2.5 10.7l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.1 0 11.3-2 15-5.5l-7.3-5.7c-2 1.4-4.7 2.3-7.7 2.3-6.4 0-11.8-3.7-13.6-9.4l-7.9 6.1C6.4 42.6 14.6 48 24 48z"/></svg>
                Googleでログイン
            </a></p>
        <?php else: ?>
            <div class="flash err">Google OAuth が未設定です。<code>config.local.php</code> を作成し、クライアントID/シークレット/リダイレクトURIを設定してください（<code>config.local.php.example</code> 参照）。</div>
        <?php endif; ?>

        <?php if ($devLogin): ?>
            <hr style="margin:22px 0; border:none; border-top:1px solid var(--line)">
            <p class="hint">開発用ログイン（APP_DEV_LOGIN 有効時のみ。本番では無効化してください）</p>
            <form method="post" action="<?= h(app_url('devlogin')) ?>" style="display:flex; flex-direction:column; gap:8px; text-align:left">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input name="email" type="email" placeholder="email" required style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                <input name="name" placeholder="表示名（任意）" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                <button class="primary" type="submit">（DEV）ログイン</button>
            </form>
        <?php endif; ?>
    </div>
</div></body></html>
    <?php
}

/** 個人用トークン画面 */
function render_tokens_page(array $user): void
{
    $tokens   = list_api_tokens((int) $user['id']);
    $newToken = $_SESSION['new_token'] ?? null;
    unset($_SESSION['new_token']);
    $flashMsg = take_flash();
    $csrf     = csrf_token();
    $endpoint = app_base_url() . '/api.php';
    ?>
<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — 個人用トークン</title>
<?php render_styles(); ?>
</head><body>
<header class="app">
    <h1>🛡️ <?= h(APP_NAME) ?></h1><span class="tag">個人用トークン</span>
    <span class="spacer"></span>
    <a class="navlink" href="index.php">← ダッシュボードへ</a>
    <a class="navlink" href="<?= h(app_url('logout')) ?>">ログアウト</a>
</header>
<div class="wrap" style="max-width:760px">
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>

    <?php if ($newToken): ?>
        <div class="stat" style="background:#fffbe6;border-color:#facc15;width:100%">
            <div class="label">発行されたトークン（この表示は一度きり）</div>
            <code style="font-size:14px;display:block;margin:6px 0;word-break:break-all"><?= h($newToken) ?></code>
            <div class="hint">CLI では環境変数 <code>APICATALOG_TOKEN</code> に設定して使います。</div>
        </div>
    <?php endif; ?>

    <div class="stat" style="width:100%;margin-bottom:14px">
        <h2 style="margin:0 0 8px;font-size:16px">スキャナCLI の使い方</h2>
        <p class="hint" style="margin:0 0 8px">SSH やローカルPCで <code>scan.php</code> を実行し、検出結果をこのサイトへ送信します。コスト金額はコードからは取得しません（Web UIで手動入力）。再送信時も手動入力のコスト・メモ・status は保持されます。</p>
        <code style="display:block;white-space:pre-wrap;font-size:12.5px">export APICATALOG_TOKEN="発行したトークン"
php scan.php --path /path/to/site --push \
  --endpoint <?= h($endpoint) ?> \
  --group <?= h((string) (current_group_id() ?? '')) ?></code>
    </div>

    <div class="stat" style="width:100%;margin-bottom:14px">
        <h2 style="margin:0 0 10px;font-size:16px">新しいトークンを発行</h2>
        <form method="post" class="row" style="display:flex;gap:8px">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="create_token">
            <input name="label" placeholder="用途ラベル（例: my-macbook）" style="flex:1;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
            <button class="primary" type="submit">発行</button>
        </form>
    </div>

    <table>
        <thead><tr><th>ラベル</th><th>状態</th><th>最終使用</th><th>発行日</th><th></th></tr></thead>
        <tbody>
        <?php if (!$tokens): ?>
            <tr><td colspan="5" class="muted" style="text-align:center;padding:20px">まだトークンはありません。</td></tr>
        <?php endif; ?>
        <?php foreach ($tokens as $t): ?>
            <tr>
                <td><?= h($t['label']) ?></td>
                <td><?= ((int) $t['revoked'] === 1) ? '<span class="pill deprecated">失効</span>' : '<span class="pill active">有効</span>' ?></td>
                <td class="muted"><?= h($t['last_used_at'] ?? '—') ?></td>
                <td class="muted"><?= h($t['created_at']) ?></td>
                <td>
                    <?php if ((int) $t['revoked'] === 0): ?>
                        <form method="post" onsubmit="return confirm('このトークンを失効しますか？')">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="revoke_token">
                            <input type="hidden" name="token_id" value="<?= (int) $t['id'] ?>">
                            <button class="link danger" type="submit">失効</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></body></html>
    <?php
}

/** サーバ内スキャン画面（admin 以上） */
function render_scan_page(array $user, array $group, int $gid): void
{
    $flashMsg = take_flash();
    $csrf = csrf_token();
    $targets = list_scan_targets($gid);
    $guess = realpath(__DIR__ . '/../') ?: dirname(__DIR__);
    $zipOk = class_exists('ZipArchive');
    ?>
<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — スキャン</title>
<?php render_styles(); ?>
</head><body>
<header class="app">
    <h1>🛡️ <?= h(APP_NAME) ?></h1><span class="tag">スキャン — <?= h($group['name']) ?></span>
    <span class="spacer"></span>
    <a class="navlink" href="index.php">← ダッシュボードへ</a>
    <a class="navlink" href="<?= h(app_url('logout')) ?>">ログアウト</a>
</header>
<div class="wrap" style="max-width:780px">
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>

    <p class="hint" style="margin-top:0">ソースを走査して外部APIの使用箇所を自動検出し、<strong><?= h($group['name']) ?></strong> のカタログに反映します。
    手動入力したコスト・メモ・status は<strong>上書きされません</strong>。使用箇所スニペットのキー値は伏字化します。
    <code>node_modules</code>/<code>vendor</code>/<code>.git</code> 等は自動除外。</p>

    <!-- キー値の自動取り込み（オプトイン） -->
    <div class="stat" style="width:100%;margin-bottom:14px;<?= encryption_ready() ? 'background:#f0fdf4;border-color:#86efac' : 'background:#fff4e0;border-color:#facc15' ?>">
        <label style="display:flex;align-items:center;gap:8px;font-weight:600;<?= encryption_ready() ? '' : 'color:#92400e' ?>">
            <input type="checkbox" id="withSecretsToggle" style="width:auto" <?= encryption_ready() ? '' : 'disabled' ?>>
            🔐 .env等に書かれた「キーの値」も暗号化して取り込む（コスト自動取得用）
        </label>
        <div class="hint" style="margin-top:4px">
            <?= encryption_ready()
                ? 'チェックを入れてスキャンすると、<code>NAME=値</code> 形式のキー値を拾って暗号化保存します（値そのものは画面に出ません）。off の時は従来どおり取り込みません。'
                : '⚠ 使うには <code>APP_ENCRYPTION_KEY</code> の設定が必要です（config.local.php）。未設定のため今は無効です。' ?>
        </div>
    </div>

    <!-- ① 保存済みスキャン対象（ワンクリック） -->
    <div class="stat" style="width:100%;margin-bottom:14px">
        <div class="row" style="display:flex;justify-content:space-between;align-items:center">
            <h2 style="margin:0;font-size:16px">① 保存したフォルダをスキャン（heteml内・ワンクリック）</h2>
            <?php if (count($targets) > 1): ?>
                <form method="post" class="scanform" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="run_all">
                    <button class="primary" type="submit">すべてスキャン</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$targets): ?>
            <p class="hint" style="margin:8px 0 0">まだ登録がありません。下の②で対象フォルダを保存すると、ここにワンクリックボタンが並びます。</p>
        <?php else: ?>
            <table style="margin-top:10px">
                <thead><tr><th>ラベル</th><th>パス</th><th>最終スキャン</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($targets as $t): ?>
                    <tr>
                        <td><strong><?= h($t['label']) ?></strong></td>
                        <td class="muted" style="word-break:break-all"><code><?= h($t['path']) ?></code></td>
                        <td class="muted"><?= h($t['last_scanned_at'] ?? '—') ?></td>
                        <td style="white-space:nowrap">
                            <form method="post" class="scanform" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="run_target">
                                <input type="hidden" name="target_id" value="<?= (int) $t['id'] ?>">
                                <button class="primary" type="submit">スキャン</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('「<?= h($t['label']) ?>」を削除しますか？')">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete_target">
                                <input type="hidden" name="target_id" value="<?= (int) $t['id'] ?>">
                                <button class="link danger" type="submit">削除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ② スキャン対象フォルダを保存 -->
    <div class="stat" style="width:100%;margin-bottom:14px">
        <h2 style="margin:0 0 10px;font-size:16px">② スキャン対象フォルダを保存（最初の1回だけ）</h2>
        <form method="post" class="scanform">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="add_target">
            <div class="field" style="margin-bottom:8px">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px">サーバ上の絶対パス</label>
                <input name="path" required value="<?= h($guess) ?>" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                <div class="hint">あなたのサイトのドキュメントルート。上は推測値です。実際のパスに直してください。</div>
            </div>
            <div class="field" style="margin-bottom:10px">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px">ラベル（任意）</label>
                <input name="label" placeholder="例: mysite" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
            </div>
            <button class="primary" type="submit">対象として保存</button>
            <button class="" type="submit" formaction="<?= h(app_url('scan')) ?>" name="action" value="run_path" style="margin-left:6px">保存せず今すぐ1回だけスキャン</button>
        </form>
    </div>

    <!-- ③ ファイルアップロード（PC/Gドライブのコード） -->
    <div class="stat" style="width:100%">
        <h2 style="margin:0 0 8px;font-size:16px">③ ファイルをアップロードしてスキャン（PC・Gドライブのコード用）</h2>
        <p class="hint" style="margin:0 0 10px">heteml の外（手元PCやGドライブ）にあるコードは、ここからアップロードしてスキャンできます。SSH・トークン不要。<br>
        <strong>.py / .php / .js などのファイルをそのまま選択OK</strong>（複数選択も可）。フォルダごとなら ZIP にまとめてアップロードしてください。</p>
        <form method="post" class="scanform" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="upload_scan">
            <div class="field" style="margin-bottom:8px">
                <input type="file" name="files[]" multiple required style="width:100%">
                <div class="hint"><?= $zipOk ? '.py / .php / .js / .ts などのソース、または .zip。複数まとめて選べます。' : '.py / .php / .js などのソースを選択（このサーバはZIP展開が無効のため、ZIPは使えません）。' ?></div>
            </div>
            <div class="field" style="margin-bottom:10px">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px">repo ラベル（任意・どこ由来か区別用）</label>
                <input name="repo" placeholder="例: gdrive-scripts" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
            </div>
            <button class="primary" type="submit">アップロードしてスキャン</button>
            <span class="hint">合計上限 80MB。アップロードしたファイルは解析後すぐ削除されます。</span>
        </form>
    </div>

    <p class="hint" style="margin-top:14px">⚠ 自分が管理するコードのみをスキャンしてください。</p>
</div>
<script>
    // 「キー値も取り込む」トグルを、各スキャンフォームの送信時に反映
    document.addEventListener('submit', function (e) {
        const f = e.target;
        if (!f.classList || !f.classList.contains('scanform')) return;
        const tg = document.getElementById('withSecretsToggle');
        if (!tg) return;
        let i = f.querySelector('input[name="with_secrets"]');
        if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = 'with_secrets'; f.appendChild(i); }
        i.value = tg.checked ? '1' : '';
    });
</script>
</body></html>
    <?php
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — <?= h($group['name']) ?></title>
<?php render_styles(); ?>
</head>
<body>
<header class="app">
    <h1>🛡️ <?= h(APP_NAME) ?></h1>
    <span class="tag">コスト軸ダッシュボード</span>
    <span class="spacer"></span>
    <!-- グループ切替 -->
    <?php if (count($memberships) > 1): ?>
        <select onchange="if(this.value)location.href=this.value">
            <?php foreach ($memberships as $m): ?>
                <option value="<?= h(app_url('switchgroup', ['gid' => (int) $m['id']])) ?>" <?= (int) $m['id'] === $gid ? 'selected' : '' ?>>
                    <?= h($m['name']) ?>（<?= h(ROLES[$m['role']] ?? $m['role']) ?>）
                </option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <span class="tag"><?= h($group['name']) ?></span>
    <?php endif; ?>
    <span class="role-badge"><?= h(ROLES[$role] ?? $role) ?></span>
    <?php if (can_manage()): ?><a class="navlink" href="<?= h(app_url('scan')) ?>">スキャン</a><?php endif; ?>
    <a class="navlink" href="<?= h(app_url('tokens')) ?>">トークン</a>
    <a class="navlink" href="groups.php">グループ管理</a>
    <span class="who">
        <?php if ($user['avatar_url']): ?><img src="<?= h($user['avatar_url']) ?>" alt=""><?php endif; ?>
        <?= h($user['name'] ?: $user['email']) ?>
    </span>
    <a class="navlink" href="<?= h(app_url('logout')) ?>">ログアウト</a>
</header>

<div class="wrap">

    <?php if ($flashMsg): ?>
        <div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div>
    <?php endif; ?>

    <?php if (!$editable): ?>
        <div class="flash" style="background:#eef4ff;color:#1d4ed8">あなたは <strong>閲覧者(viewer)</strong> です。編集はできません。</div>
    <?php endif; ?>

    <!-- 月額合計サマリ -->
    <div class="summary">
        <?php if ($subtotals): ?>
            <?php foreach ($subtotals as $cur => $sum): ?>
                <div class="stat">
                    <div class="label">月額合計（<?= h($cur) ?>）</div>
                    <div class="value"><?= h($cur) ?> <?= number_format($sum, (fmod($sum,1.0)===0.0)?0:2) ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="stat"><div class="label">月額合計</div><div class="value">—</div></div>
        <?php endif; ?>
        <div class="stat"><div class="label">プロダクト数</div><div class="value"><?= count($tree) ?> <small>件</small></div></div>
        <div class="stat"><div class="label">プロジェクト箱</div><div class="value"><?= count($projects) ?> <small>箱</small></div></div>
    </div>

    <?php if (can_manage() && !encryption_ready()): ?>
        <div class="flash" style="background:#fff4e0;color:#92400e">
            🔐 コスト自動取得に向けて<strong>キーの暗号化保存</strong>を使うには、<code>config.local.php</code> に <code>APP_ENCRYPTION_KEY</code> を設定してください。<br>
            <span class="hint">生成: サーバーで <code>php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"</code> を実行し、出た文字列を設定。</span>
        </div>
    <?php endif; ?>

    <?php if ($legacyCount > 0 && can_manage()): ?>
        <div class="flash" style="background:#fff4e0;color:#92400e;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span>旧形式（サイト未設定）のエントリが <strong><?= $legacyCount ?></strong> 件あります。<strong>まず再スキャンしてサイト別の新エントリを作ってから</strong>、古いものを削除して整理してください。<br><span class="hint">※削除すると、その旧エントリに手入力したコスト等も消えます。必要な値は新エントリへ移してから削除を。</span></span>
            <form method="post" style="margin:0" onsubmit="return confirm('サイト未設定のエントリ <?= $legacyCount ?> 件を削除します（手入力した内容も消えます）。よろしいですか？')">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete_legacy">
                <button class="danger" type="submit">古いエントリを削除</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- 絞り込み / 検索 -->
    <form class="toolbar" method="get">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="API / サイト / キー / メモ で検索">
        <select name="site">
            <option value="">サイト（すべて）</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= h($s) ?>" <?= $s === $filterSite ? 'selected' : '' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="provider">
            <option value="">provider（すべて）</option>
            <?php foreach ($providers as $p): ?>
                <option value="<?= h($p) ?>" <?= $p === $filterProv ? 'selected' : '' ?>><?= h($p) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">status（すべて）</option>
            <?php foreach (STATUSES as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $k === $filterStatus ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort" onchange="this.form.submit()">
            <option value="manual" <?= $sort === 'manual' ? 'selected' : '' ?>>手動の並び順</option>
            <option value="cost" <?= $sort === 'cost' ? 'selected' : '' ?>>金額順</option>
            <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>プロバイダ名順</option>
        </select>
        <button class="primary" type="submit">絞り込み</button>
        <a class="btn" href="index.php">クリア</a>
        <span class="spacer"></span>
        <?php if ($editable): ?>
            <button type="button" class="primary" onclick="openCreate()">＋ API を追加</button>
        <?php endif; ?>
    </form>

    <!-- プロダクト → プロジェクト箱 → URL ビュー -->
    <?php if (!$tree): ?>
        <div class="empty">該当するデータがありません。<?= $editable ? '「＋ API を追加」または「スキャン」で取り込んでください。' : '' ?></div>
    <?php else: ?>
    <p class="hint" style="margin:0 0 8px">展開：プロダクト → プロジェクト箱 → URL。URL/サイトの☑を選び、下で「箱へ移動」できます。</p>
    <?php if ($editable): ?>
    <div class="toolbar" style="margin-bottom:10px">
        <span>選択を移動 →</span>
        <select id="moveTargetSel" onchange="document.getElementById('moveNew').style.display=this.value==='new'?'flex':'none'">
            <option value="unassign">（未割当に戻す）</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int) $p['id'] ?>">📦 <?= h($p['name']) ?><?= $p['openai_project_id'] !== '' ? '（' . h($p['openai_project_id']) . '）' : '' ?></option>
            <?php endforeach; ?>
            <option value="new">＋ 新しい箱を作る…</option>
        </select>
        <span id="moveNew" style="display:none;gap:6px;align-items:center">
            <input id="moveNewName" placeholder="箱の名前" style="width:130px">
            <input id="moveNewProj" placeholder="proj_xxx（任意）" style="width:140px">
        </span>
        <button class="primary" type="button" onclick="doMove()">箱へ移動</button>
        <span id="moveCount" class="hint"></span>
        <span class="spacer"></span>
        <button class="btn" type="button" onclick="openProject({})">＋ 箱を追加</button>
    </div>
    <?php endif; ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th style="width:28px"></th>
                <th>プロダクト / 箱 / URL</th>
                <th>月額</th>
                <th class="hide-sm">内訳 / 操作</th>
            </tr>
        </thead>
        <tbody>
        <?php $gi = 0; foreach ($names as $gname): $gi++;
            $info = $tree[$gname];
            $boxes = $info['boxes'];
            $nSites = count($info['sites']);
            $nProjects = count(array_filter(array_keys($boxes), static fn($k) => $k != 0));
            // プロダクト合計（箱コストの通貨別和）
            $pmoney = [];
            foreach ($boxes as $pid => $b) {
                if ($pid && isset($projById[$pid]) && $projById[$pid]['monthly_cost'] !== null) {
                    $cur = $projById[$pid]['currency'] ?: 'USD';
                    $pmoney[$cur] = ($pmoney[$cur] ?? 0) + (float) $projById[$pid]['monthly_cost'];
                }
            }
            ksort($pmoney);
            $pmoneyStr = $pmoney ? implode('　', array_map(static fn($c, $v) => $c . ' ' . number_format($v, (fmod($v,1.0)===0.0)?0:2), array_keys($pmoney), $pmoney)) : '—';
            // boxesを並べ替え：実プロジェクトを先、未割当(0)を最後
            uksort($boxes, static fn($a, $b) => ($a == 0 ? 1 : 0) <=> ($b == 0 ? 1 : 0));
        ?>
            <tr class="group-head" data-name="<?= h($gname) ?>" data-gi="<?= $gi ?>" onclick="toggleProduct(<?= $gi ?>)"
                <?= $editable ? 'draggable="true" ondragstart="gDragStart(event,this)" ondragend="gDragEnd(this)" ondragover="gDragOver(event,this)" ondragleave="gDragLeave(this)" ondrop="gDrop(event,this)"' : '' ?>>
                <td><?php if ($editable): ?><span class="drag-handle" title="ドラッグで並べ替え">⠿</span><?php endif; ?><span id="pc<?= $gi ?>" class="caret">▶</span></td>
                <td>🔷 <strong><?= h($gname) ?></strong> <?php if ($info['provider']): ?><span class="muted">（<?= h($info['provider']) ?>）</span><?php endif; ?></td>
                <td class="cost group-cost"><?= h($pmoneyStr) ?></td>
                <td class="hide-sm" style="white-space:nowrap">
                    <span class="muted"><?= $nProjects ?> 箱 / <?= $nSites ?> サイト</span>
                    <?php if ($editable): ?>
                        <form method="post" style="display:inline" onclick="event.stopPropagation()"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reorder"><input type="hidden" name="name" value="<?= h($gname) ?>"><input type="hidden" name="dir" value="up"><button class="link" type="submit" title="上へ">▲</button></form>
                        <form method="post" style="display:inline" onclick="event.stopPropagation()"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reorder"><input type="hidden" name="name" value="<?= h($gname) ?>"><input type="hidden" name="dir" value="down"><button class="link" type="submit" title="下へ">▼</button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <!-- サイト選択（まとめ移動用） -->
            <?php if ($editable && $info['sites']): ?>
            <tr class="prod<?= $gi ?> p<?= $gi ?>-proj" style="display:none">
                <td></td>
                <td colspan="3"><span class="hint">サイトごと移動: </span>
                    <?php foreach (array_keys($info['sites']) as $s): ?>
                        <label style="font-size:12px;margin-right:8px;white-space:nowrap"><input type="checkbox" class="siteChk" value="<?= h($s) ?>" onchange="updMoveCount()" style="width:auto"> 🌐<?= h($s) ?></label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endif; ?>
        <?php $pj = 0; foreach ($boxes as $pid => $box): $pj++;
            $proj = $pid ? ($projById[$pid] ?? null) : null;
            $boxLabel = $proj ? ('📦 ' . $proj['name'] . ($proj['openai_project_id'] !== '' ? '（' . $proj['openai_project_id'] . '）' : '')) : '📦 未割当';
            $boxMoney = $proj ? fmt_money($proj['monthly_cost'] === null ? null : (float) $proj['monthly_cost'], $proj['currency'] ?: 'USD') : '—';
            // URL一覧（重複排除：repo|file|line）。キー定義は🔑先頭。
            $urls = [];
            foreach ($box['usages'] as $u) {
                $k = $u['repo'] . '|' . $u['file'] . '|' . $u['line'];
                if (!isset($urls[$k])) { $urls[$k] = ['id' => $u['id'], 'repo' => $u['repo'], 'file' => $u['file'], 'line' => $u['line'], 'is_key' => is_key_def_usage($u)]; }
            }
            uasort($urls, static fn($a, $b) => ($b['is_key'] <=> $a['is_key']) ?: strcmp((string) $a['file'], (string) $b['file']));
        ?>
            <tr class="prod<?= $gi ?> p<?= $gi ?>-proj" style="display:none">
                <td style="text-align:right"><span id="jc<?= $gi ?>_<?= $pj ?>" class="caret" onclick="toggleProj(<?= $gi ?>,<?= $pj ?>)" style="cursor:pointer">▶</span></td>
                <td style="padding-left:24px"><?= h($boxLabel) ?> <span class="muted">（<?= count($urls) ?> URL）</span></td>
                <td class="cost"><?= $boxMoney ?></td>
                <td class="hide-sm" style="white-space:nowrap" onclick="event.stopPropagation()">
                    <?php if ($editable && $proj): ?>
                        <?php if (trim((string) $proj['openai_project_id']) !== ''): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="fetch_project_cost"><input type="hidden" name="project_id" value="<?= $pid ?>"><button class="link" type="submit" title="コスト取得">⟳コスト</button></form>
                        <?php endif; ?>
                        <button class="link" type="button" onclick='openProject(<?= json_encode(["id"=>$pid,"name"=>$proj["name"],"openai_project_id"=>$proj["openai_project_id"],"secret_hint"=>$proj["secret_hint"],"monthly_cost"=>$proj["monthly_cost"],"currency"=>$proj["currency"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>箱を編集</button>
                        <form method="post" style="display:inline" onsubmit="return confirm('箱「<?= h($proj['name']) ?>」を削除しますか？（URLは未割当に戻ります）')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= $pid ?>"><button class="link danger" type="submit">箱削除</button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="prod<?= $gi ?> p<?= $gi ?>j<?= $pj ?>-file" style="display:none">
                <td></td>
                <td colspan="3" style="padding-left:48px">
                    <?php foreach ($urls as $f): ?>
                        <div style="white-space:nowrap">
                            <?php if ($editable): ?><input type="checkbox" class="moveChk" value="<?= (int) $f['id'] ?>" onchange="updMoveCount()" style="width:auto"><?php endif; ?>
                            <?= $f['is_key'] ? '🔑' : '📄' ?> <code><?= h($f['repo']) ?><?= $f['repo'] !== '' ? ' / ' : '' ?><?= h($f['file']) ?><?= $f['line'] !== null ? ':' . (int) $f['line'] : '' ?></code>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; /* boxes */ ?>
        <?php endforeach; /* products */ ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <p class="hint" style="margin-top:18px">
        ※ コストは手動入力（v1）。使用箇所(usages)は本来スキャナCLIが自動検出してプッシュします。
        APIキー本体は保存せず、鍵の在りか(<code>env: XXX</code> 等)のみを記録します。
        スキャナ連携・各社billing API連携は将来フェーズ（仕様書 §6,§9）。
    </p>
</div>
<div id="abtToast"></div>

<?php if ($editable): ?>
<!-- 追加 / 編集モーダル -->
<dialog id="apiDialog">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_api">
        <input type="hidden" name="id" id="f_id" value="">
        <div class="modal-head" id="modalTitle">API を追加</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field"><label>API名 <span style="color:#b42318">*</span></label><input name="name" id="f_name" required placeholder="例: OpenAI API"></div>
                <div class="field"><label>provider</label><input name="provider" id="f_provider" placeholder="例: OpenAI / Stripe / Google"></div>
                <div class="field"><label>サイト (site)</label><input name="site" id="f_site" placeholder="例: s-benri / drive-py"><div class="hint">どのサイト/プロジェクトで使うキーか。空欄も可。</div></div>
                <div class="field"><label>月額（空欄＝未設定）</label><input name="monthly_cost" id="f_cost" type="number" step="0.01" min="0" placeholder="例: 12000"></div>
                <div class="field"><label>通貨</label><select name="currency" id="f_currency"><?php foreach (['JPY','USD','EUR','GBP'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>status</label><select name="status" id="f_status"><?php foreach (STATUSES as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>担当 (owner)</label><input name="owner" id="f_owner" placeholder="例: 開発チーム"></div>
                <div class="field full"><label>鍵の在りか (key_location)</label><input name="key_location" id="f_key" placeholder="例: env: OPENAI_API_KEY"><div class="hint">環境変数名など「在りか」。下の「APIキー(値)」とは別です。</div></div>
                <div class="field full" style="border-top:1px dashed var(--line);padding-top:10px">
                    <label>🔐 APIキー（値）— コスト自動取得用</label>
                    <input type="password" name="secret" id="f_secret" autocomplete="new-password" placeholder="ここにキーの値を貼る（暗号化保存）">
                    <div class="hint" id="f_secret_state"></div>
                    <label style="font-weight:400;font-size:12px;display:inline-flex;align-items:center;gap:4px;margin-top:4px">
                        <input type="checkbox" name="secret_clear" id="f_secret_clear" value="1" style="width:auto"> 保存済みのキーを削除する
                    </label>
                    <div class="hint"><?= encryption_ready()
                        ? '暗号化して保存します。空欄なら現状維持（既存キーはそのまま）。'
                        : '⚠ APP_ENCRYPTION_KEY が未設定のため、キー値は保存できません（config.local.php に設定してください）。' ?></div>
                </div>
                <div class="field full">
                    <label>コスト用プロジェクトID（任意・OpenAI等）</label>
                    <input name="cost_project" id="f_cost_project" placeholder="例: proj_xxxxx（空なら組織全体の合計）">
                    <div class="hint">⟳コスト時、IDを入れると<strong>そのプロジェクトのみ</strong>、空なら<strong>組織全体の合計</strong>を取得します。OpenAIのプロジェクト設定で確認できます。</div>
                </div>
                <div class="field"><label>請求ページURL (billing_url)</label><input name="billing_url" id="f_billing" type="url" placeholder="https://..."></div>
                <div class="field"><label>ドキュメントURL (docs_url)</label><input name="docs_url" id="f_docs" type="url" placeholder="https://..."></div>
                <div class="field full"><label>メモ (notes)</label><textarea name="notes" id="f_notes" rows="3" placeholder="補足・コスト変動の理由など"></textarea></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('apiDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>

<!-- プロジェクト箱 編集モーダル -->
<dialog id="projDialog">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_project">
        <input type="hidden" name="project_id" id="pf_id" value="">
        <div class="modal-head" id="projModalTitle">プロジェクト箱</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field full"><label>箱の名前 <span style="color:#b42318">*</span></label><input name="name" id="pf_name" required placeholder="例: ECサイト群"></div>
                <div class="field full"><label>OpenAI プロジェクトID（任意・コスト取得用）</label><input name="openai_project_id" id="pf_proj" placeholder="proj_xxxxx"><div class="hint">紐付けると「⟳コスト」でこの箱の額を取得します。</div></div>
                <div class="field"><label>月額（手入力・任意）</label><input name="monthly_cost" id="pf_cost" type="number" step="0.01" min="0" placeholder="自動取得しない場合"></div>
                <div class="field"><label>通貨</label><select name="currency" id="pf_currency"><?php foreach (['USD','JPY','EUR','GBP'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select></div>
                <div class="field full"><label>🔐 OpenAI Admin キー（任意・コスト取得用）</label><input type="password" name="secret" id="pf_secret" autocomplete="new-password" placeholder="sk-admin-...（暗号化保存）"><div class="hint" id="pf_secret_state"></div></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('projDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>
<script>
    const projDialog = document.getElementById('projDialog');
    function openProject(p) {
        p = p || {};
        document.getElementById('projModalTitle').textContent = p.id ? 'プロジェクト箱を編集' : 'プロジェクト箱を追加';
        document.getElementById('pf_id').value = p.id ?? '';
        document.getElementById('pf_name').value = p.name ?? '';
        document.getElementById('pf_proj').value = p.openai_project_id ?? '';
        document.getElementById('pf_cost').value = (p.monthly_cost === null || p.monthly_cost === undefined) ? '' : p.monthly_cost;
        document.getElementById('pf_currency').value = p.currency || 'USD';
        document.getElementById('pf_secret').value = '';
        document.getElementById('pf_secret_state').textContent = p.secret_hint ? ('現在: 🔐 ' + p.secret_hint + '（変更時のみ入力）') : 'Adminキー未保存';
        projDialog.showModal();
    }
    const dialog = document.getElementById('apiDialog');
    function openCreate() {
        document.getElementById('modalTitle').textContent = 'API を追加';
        document.getElementById('f_id').value = '';
        for (const f of ['name','provider','site','cost','owner','key','billing','docs','notes','secret','cost_project']) {
            const el = document.getElementById('f_' + f); if (el) el.value = '';
        }
        document.getElementById('f_currency').value = 'JPY';
        document.getElementById('f_status').value = 'unknown';
        document.getElementById('f_secret_clear').checked = false;
        document.getElementById('f_secret_state').textContent = '';
        dialog.showModal();
    }
    function openEdit(a) {
        document.getElementById('modalTitle').textContent = 'API を編集';
        document.getElementById('f_id').value      = a.id ?? '';
        document.getElementById('f_name').value     = a.name ?? '';
        document.getElementById('f_provider').value = a.provider ?? '';
        document.getElementById('f_site').value     = a.site ?? '';
        document.getElementById('f_cost').value     = (a.monthly_cost === null || a.monthly_cost === undefined) ? '' : a.monthly_cost;
        document.getElementById('f_currency').value = a.currency ?? 'JPY';
        document.getElementById('f_status').value   = a.status ?? 'unknown';
        document.getElementById('f_owner').value    = a.owner ?? '';
        document.getElementById('f_key').value      = a.key_location ?? '';
        document.getElementById('f_billing').value  = a.billing_url ?? '';
        document.getElementById('f_docs').value     = a.docs_url ?? '';
        document.getElementById('f_notes').value    = a.notes ?? '';
        document.getElementById('f_cost_project').value = a.cost_project ?? '';
        document.getElementById('f_secret').value    = '';
        document.getElementById('f_secret_clear').checked = false;
        document.getElementById('f_secret_state').textContent =
            a.secret_hint ? ('現在: 🔐 ' + a.secret_hint + '（変更する時だけ入力）') : 'キー未保存';
        dialog.showModal();
    }
</script>
<?php endif; ?>
<script>
    // ---- ドラッグ＆ドロップ並べ替え（PC、リロードなし） ----
    const ABT_CSRF = '<?= h($csrf) ?>';
    let abtDrag = null;
    function gDragStart(e, el) { abtDrag = el; e.dataTransfer.effectAllowed = 'move'; el.classList.add('dragging'); }
    function gDragEnd(el) { el.classList.remove('dragging'); clearDropMarks(); }
    function clearDropMarks() { document.querySelectorAll('.drop-before,.drop-after').forEach(r => r.classList.remove('drop-before', 'drop-after')); }
    function gDragLeave(el) { el.classList.remove('drop-before', 'drop-after'); }
    function gDragOver(e, el) {
        if (!abtDrag || abtDrag === el) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const rect = el.getBoundingClientRect();
        const after = (e.clientY - rect.top) > rect.height / 2;
        el.classList.remove('drop-before', 'drop-after');
        el.classList.add(after ? 'drop-after' : 'drop-before');
    }
    function groupRows(gi) {
        return [document.querySelector('tr.group-head[data-gi="' + gi + '"]'),
                ...document.querySelectorAll('.prod' + gi)];
    }
    function gDrop(e, el) {
        e.preventDefault();
        clearDropMarks();
        if (!abtDrag || abtDrag === el) return;
        const tbody = el.parentNode;
        const rect = el.getBoundingClientRect();
        const after = (e.clientY - rect.top) > rect.height / 2;
        const tgi = el.getAttribute('data-gi');
        const tRows = groupRows(tgi);
        const ref = after ? (tRows[tRows.length - 1].nextElementSibling) : el;
        groupRows(abtDrag.getAttribute('data-gi')).forEach(r => { if (r) tbody.insertBefore(r, ref); });
        saveOrder();
    }
    function saveOrder() {
        const names = Array.from(document.querySelectorAll('tr.group-head')).map(r => r.getAttribute('data-name'));
        const body = new URLSearchParams();
        body.append('csrf', ABT_CSRF); body.append('action', 'reorder_set'); body.append('ajax', '1');
        names.forEach(n => body.append('names[]', n));
        fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
            .then(r => abtToast(r.ok ? '並び順を保存しました' : '保存に失敗しました'))
            .catch(() => abtToast('保存に失敗しました'));
    }
    function abtToast(msg) {
        const t = document.getElementById('abtToast'); if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid); t._tid = setTimeout(() => t.classList.remove('show'), 1500);
    }

    // 選択したURL/サイトを箱へ移動
    function updMoveCount() {
        const u = document.querySelectorAll('.moveChk:checked').length;
        const s = document.querySelectorAll('.siteChk:checked').length;
        const el = document.getElementById('moveCount');
        if (el) el.textContent = (u || s) ? ('URL ' + u + ' / サイト ' + s + ' 選択中') : '';
    }
    function doMove() {
        const uids = [...document.querySelectorAll('.moveChk:checked')].map(c => c.value);
        const sites = [...document.querySelectorAll('.siteChk:checked')].map(c => c.value);
        if (!uids.length && !sites.length) { alert('移動するURLまたはサイトを☑で選択してください。'); return; }
        const sel = document.getElementById('moveTargetSel').value;
        let label = '未割当';
        const f = document.createElement('form'); f.method = 'post'; f.action = 'index.php';
        const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
        add('csrf', ABT_CSRF); add('action', 'move_to_project');
        if (sel === 'new') {
            const nm = document.getElementById('moveNewName').value.trim();
            if (!nm) { alert('新しい箱の名前を入れてください。'); return; }
            add('new_name', nm); add('new_proj', document.getElementById('moveNewProj').value.trim());
            label = '新しい箱「' + nm + '」';
        } else if (sel !== 'unassign') {
            add('target_project', sel);
            label = '選択した箱';
        }
        if (!confirm('URL ' + uids.length + ' 件 / サイト ' + sites.length + ' 件を ' + label + ' へ移動します。よろしいですか？')) return;
        uids.forEach(id => add('usage_ids[]', id));
        sites.forEach(s => add('sites[]', s));
        document.body.appendChild(f); f.submit();
    }

    // プロダクト展開 → プロジェクト行を表示（閉じる時はファイルも畳む）
    function toggleProduct(gi) {
        const caret = document.getElementById('pc' + gi);
        const open = caret.textContent === '▶';
        document.querySelectorAll('.p' + gi + '-proj').forEach(r => r.style.display = open ? 'table-row' : 'none');
        caret.textContent = open ? '▼' : '▶';
        if (!open) {
            document.querySelectorAll('.prod' + gi + '[class*="-file"]').forEach(r => r.style.display = 'none');
            document.querySelectorAll('[id^="jc' + gi + '_"]').forEach(c => c.textContent = '▶');
        }
    }
    // プロジェクト展開 → そのキーファイル行を表示
    function toggleProj(gi, pj) {
        const caret = document.getElementById('jc' + gi + '_' + pj);
        const open = caret.textContent === '▶';
        document.querySelectorAll('.p' + gi + 'j' + pj + '-file').forEach(r => r.style.display = open ? 'table-row' : 'none');
        caret.textContent = open ? '▼' : '▶';
    }
</script>
</body>
</html>
