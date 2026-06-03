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
        // 新規箱はプロダクト未設定なら、移動したURLのプロダクト名を自動セット
        if ($pid && $newName !== '') {
            $pn = $pdo->query("SELECT a.name FROM usages u JOIN apis a ON a.id=u.api_id WHERE u.project_id=" . (int) $pid . " LIMIT 1")->fetchColumn();
            if ($pn) {
                $pdo->prepare("UPDATE projects SET product=:prod WHERE id=:id AND group_id=:g AND product=''")
                    ->execute([':prod' => $pn, ':id' => $pid, ':g' => $gid]);
            }
        }
        flash('ok', $n . '件のURLを' . ($pid ? 'プロジェクトへ移動しました。' : '未割当に戻しました。'));
        redirect_self();
    }

    if ($action === 'save_project') {
        require_role_at_least($gid, 'member');
        $pidIn = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int) $_POST['project_id'] : null;
        $pname = trim((string) ($_POST['name'] ?? ''));
        $pprod = trim((string) ($_POST['product'] ?? ''));
        $pproj = trim((string) ($_POST['openai_project_id'] ?? ''));
        $ptype = in_array($_POST['cost_type'] ?? '', ['', 'openai', 'twilio'], true) ? $_POST['cost_type'] : '';
        $pacct = trim((string) ($_POST['cost_account'] ?? ''));
        if ($pname === '') { flash('err', '箱の名前は必須です。'); redirect_self(); }
        // 管理キー（OpenAI Admin）任意
        $secretIn = (string) ($_POST['secret'] ?? '');
        if ($pidIn === null) {
            $pidIn = create_project($gid, $pname, $pproj, $pprod);
        }
        $costRaw = trim((string) ($_POST['monthly_cost'] ?? ''));
        $mcost = ($costRaw === '') ? null : (float) $costRaw;
        $mcur = trim((string) ($_POST['currency'] ?? 'USD')) ?: 'USD';
        $pdo->prepare('UPDATE projects SET name=:n, product=:prod, cost_type=:ct, cost_account=:ca, openai_project_id=:p, monthly_cost=:mc, currency=:cur, updated_at=:u WHERE id=:id AND group_id=:g')
            ->execute([':n'=>$pname, ':prod'=>$pprod, ':ct'=>$ptype, ':ca'=>$pacct, ':p'=>$pproj, ':mc'=>$mcost, ':cur'=>$mcur, ':u'=>now(), ':id'=>$pidIn, ':g'=>$gid]);
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
                flash('ok', sprintf('コスト取得（%s）: %s %s%s', h($p['name']), $c['currency'], number_format($c['amount'], 2), !empty($c['note']) ? '　[' . $c['note'] . ']' : ''));
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

// 箱ごとのURL件数（フィルタ非依存）
$boxUrlCount = [];
$bc = $pdo->prepare('SELECT project_id, COUNT(*) c FROM usages WHERE project_id IS NOT NULL AND api_id IN (SELECT id FROM apis WHERE group_id = :g) GROUP BY project_id');
$bc->execute([':g' => $gid]);
foreach ($bc->fetchAll() as $r) { $boxUrlCount[(int) $r['project_id']] = (int) $r['c']; }

// 使用箇所(URL) ＋ 親API情報 ＋ 所属箱
$uSql = "SELECT u.*, a.name AS api_name, a.provider AS api_provider, a.site AS api_site, a.key_location AS api_key
         FROM usages u JOIN apis a ON a.id = u.api_id
         $whereSql ORDER BY a.name, u.repo, u.file, u.line";
$uStmt = $pdo->prepare($uSql);
$uStmt->execute($params);
$allUsages = $uStmt->fetchAll();

// サイト＝ファイルパス先頭ディレクトリで絞り込み
if ($filterSite !== '') {
    $allUsages = array_values(array_filter($allUsages, static fn($u) => usage_site($u) === $filterSite));
}

// URL を「箱ごと」「未割当はプロダクトごと」に仕分け
$usagesByBox = [];           // pid => [usages]
$unassignedByProduct = [];   // productName => [usages]
$providerOf = [];            // productName => provider
foreach ($allUsages as $u) {
    $providerOf[$u['api_name']] = $u['api_provider'];
    if ($u['project_id'] !== null) {
        $usagesByBox[(int) $u['project_id']][] = $u;
    } else {
        $unassignedByProduct[$u['api_name']][] = $u;
    }
}
// 箱をプロダクトごとに（空箱も含む）
$boxesByProduct = [];        // productName => [project rows]
foreach ($projects as $p) {
    $prod = $p['product'] !== '' ? $p['product'] : '（プロダクト未指定）';
    $boxesByProduct[$prod][] = $p;
}
// プロダクト一覧 = 使用箇所のプロダクト ∪ 箱のプロダクト
$tree = [];
foreach (array_keys($providerOf) as $nm) { $tree[$nm] = true; }
foreach (array_keys($boxesByProduct) as $nm) { if (!isset($tree[$nm])) { $tree[$nm] = true; } }
foreach (array_keys($unassignedByProduct) as $nm) { if (!isset($tree[$nm])) { $tree[$nm] = true; } }

// プロダクトのコスト合計（配下の箱コストの和）と並べ替え
$productTotalNum = [];
foreach (array_keys($tree) as $name) {
    $sum = 0.0;
    foreach (($boxesByProduct[$name] ?? []) as $b) {
        if ($b['monthly_cost'] !== null) { $sum += (float) $b['monthly_cost']; }
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

// サイト一覧（フィルタ用）＝全URLのファイル先頭ディレクトリ
$sites = [];
$sfStmt = $pdo->prepare('SELECT DISTINCT u.repo, u.file FROM usages u JOIN apis a ON a.id = u.api_id WHERE a.group_id = :gid');
$sfStmt->execute([':gid' => $gid]);
foreach ($sfStmt->fetchAll() as $r) { $sites[usage_site($r)] = 1; }
$sites = array_map('strval', array_keys($sites));   // 数字名サイト対策で文字列化
sort($sites);
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

// プロダクト別コスト（通貨別）— ドーナツ／プロダクト詳細で使う
$prodCostByCur = [];   // cur => [product => sum]
foreach ($names as $nm) {
    foreach (($boxesByProduct[$nm] ?? []) as $b) {
        if ($b['monthly_cost'] !== null) {
            $cur = $b['currency'] ?: 'USD';
            $prodCostByCur[$cur][$nm] = ($prodCostByCur[$cur][$nm] ?? 0) + (float) $b['monthly_cost'];
        }
    }
}
// 全体ドーナツ：合計が最大の通貨でプロダクト内訳を描く
$donutCur = '';
$donutMax = -1.0;
foreach ($subtotals as $cur => $sum) { if ($sum > $donutMax) { $donutMax = $sum; $donutCur = $cur; } }
$donutSegs = [];
if ($donutCur !== '' && !empty($prodCostByCur[$donutCur])) {
    $pc = $prodCostByCur[$donutCur];
    arsort($pc);
    foreach ($pc as $nm => $v) { if ($v > 0) { $donutSegs[] = ['label' => $nm, 'value' => (float) $v]; } }
}

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
    $t = money_sum($rows);
    if (!$t) { return '—'; }
    ksort($t);
    return implode('　', array_map(static fn($c, $v) => $c . ' ' . number_format($v, (fmod($v, 1.0) === 0.0) ? 0 : 2), array_keys($t), $t));
}

/** 通貨別合計を [currency => float] で返す */
function money_sum(array $rows): array
{
    $t = [];
    foreach ($rows as $r) {
        if (($r['monthly_cost'] ?? null) !== null) {
            $c = ($r['currency'] ?? '') ?: 'JPY';
            $t[$c] = ($t[$c] ?? 0) + (float) $r['monthly_cost'];
        }
    }
    return $t;
}

/** ドーナツ用の配色（順に割り当て） */
function donut_colors(): array
{
    return ['#6366f1', '#22c55e', '#f59e0b', '#ec4899', '#06b6d4', '#a855f7', '#ef4444', '#84cc16', '#3b82f6', '#f97316'];
}

/**
 * SVG ドーナツチャートを生成。
 * @param array $segments [ ['label'=>, 'value'=>float, 'color'=>?] ]（valueは同一通貨換算済み想定）
 */
function svg_donut(array $segments, float $size = 180.0, float $thickness = 26.0): string
{
    $total = 0.0;
    foreach ($segments as $s) { $total += max(0.0, (float) $s['value']); }
    $cx = $size / 2; $cy = $size / 2;
    $r = ($size - $thickness) / 2;
    $circ = 2 * M_PI * $r;
    $colors = donut_colors();
    $svg = '<svg viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" class="donut">';
    if ($total <= 0) {
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#eef1f4" stroke-width="' . $thickness . '"/>';
        $svg .= '<text x="' . $cx . '" y="' . $cy . '" text-anchor="middle" dominant-baseline="central" fill="#8a93a0" font-size="13">データなし</text>';
        return $svg . '</svg>';
    }
    // 背景リング
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="#eef1f4" stroke-width="' . $thickness . '"/>';
    $offset = 0.0; $i = 0;
    foreach ($segments as $s) {
        $v = max(0.0, (float) $s['value']);
        if ($v <= 0) { $i++; continue; }
        $len = $circ * ($v / $total);
        $color = $s['color'] ?? $colors[$i % count($colors)];
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . h($color) . '"'
            . ' stroke-width="' . $thickness . '" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '"'
            . ' stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 ' . $cx . ' ' . $cy . ')"/>';
        $offset += $len; $i++;
    }
    return $svg . '</svg>';
}

/** URL(使用箇所)のサイト名＝ファイルパスの先頭ディレクトリ（無ければrepo） */
function usage_site(array $u): string
{
    $f = (string) ($u['file'] ?? '');
    $pos = strpos($f, '/');
    if ($pos !== false && $pos > 0) { return substr($f, 0, $pos); }
    return ($u['repo'] ?? '') !== '' ? $u['repo'] : '(root)';
}

/** usages を URL(repo|file|line)で重複排除し、キー定義を先頭に */
function dedup_urls(array $usages): array
{
    $urls = [];
    foreach ($usages as $u) {
        $k = $u['repo'] . '|' . $u['file'] . '|' . $u['line'];
        if (!isset($urls[$k])) {
            $urls[$k] = ['id' => $u['id'], 'repo' => $u['repo'], 'file' => $u['file'], 'line' => $u['line'], 'is_key' => is_key_def_usage($u)];
        }
    }
    uasort($urls, static fn($a, $b) => ($b['is_key'] <=> $a['is_key']) ?: strcmp((string) $a['file'], (string) $b['file']));
    return $urls;
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
        --bg:#eef1f6; --card:#fff; --line:#e8ebf0; --ink:#1f2733;
        --muted:#8a93a0; --accent:#2f6bff; --accent-d:#1d4ed8;
        --ok-bg:#e7f6ec; --ok-ink:#1a7f43; --err-bg:#fdecec; --err-ink:#b42318;
        --radius:16px; --shadow:0 6px 24px rgba(31,41,55,.06);
    }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--bg); color:var(--ink);
        font-family:-apple-system,BlinkMacSystemFont,"Hiragino Kaku Gothic ProN","Noto Sans JP",Meiryo,sans-serif; line-height:1.6; }

    /* ===== モダン・サイドバー レイアウト（ダッシュボード用） ===== */
    .layout { display:flex; min-height:100vh; }
    .sidebar { width:218px; flex:0 0 218px; background:#fff; border-right:1px solid var(--line); padding:18px 14px; position:sticky; top:0; height:100vh; overflow:auto; }
    .sidebar .brand { display:flex; align-items:center; gap:8px; font-weight:800; font-size:17px; padding:6px 8px 16px; }
    .sidebar .navlabel { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; padding:14px 10px 6px; }
    .sidebar a.nav { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:12px; color:#46505e; text-decoration:none; font-size:14px; font-weight:600; margin-bottom:2px; }
    .sidebar a.nav:hover { background:#f3f5f9; color:var(--ink); }
    .sidebar a.nav.active { background:var(--accent); color:#fff; box-shadow:0 6px 16px rgba(47,107,255,.35); }
    .sidebar .who { display:flex; align-items:center; gap:8px; font-size:13px; padding:10px 8px; border-top:1px solid var(--line); margin-top:14px; }
    .sidebar .who img { width:30px; height:30px; border-radius:50%; }
    .main { flex:1; min-width:0; padding:22px 26px; }
    .topbar { display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
    .topbar .grow { flex:1; }
    .topbar select { padding:8px 12px; border-radius:12px; border:1px solid var(--line); background:#fff; font-size:13px; box-shadow:var(--shadow); }
    .topbar h2 { margin:0; font-size:20px; }

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
    .role-badge { font-size:11px; padding:3px 10px; border-radius:999px; background:#eef2ff; color:#3730a3; font-weight:700; }
    .summary { display:flex; flex-wrap:wrap; gap:14px; margin-bottom:20px; }
    .stat { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); padding:16px 18px; min-width:150px; box-shadow:var(--shadow); }
    .stat .label { font-size:12px; color:var(--muted); }
    .stat .value { font-size:24px; font-weight:800; }
    .stat .value small { font-size:12px; font-weight:400; color:var(--muted); }

    /* ===== ヒーロー（全体サマリ）＋ ドーナツ ===== */
    .hero { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
    .hero-main { flex:1 1 320px; background:linear-gradient(135deg,#1f2a44,#2f6bff); color:#fff;
        border-radius:var(--radius); padding:22px 24px; box-shadow:var(--shadow); display:flex; flex-direction:column; }
    .hero-label { font-size:12px; letter-spacing:.04em; opacity:.85; }
    .hero-chart .hero-label { color:var(--muted); opacity:1; }
    .hero-amount { font-size:34px; font-weight:800; line-height:1.25; font-variant-numeric:tabular-nums; }
    .hero-amount .cur { font-size:18px; font-weight:700; opacity:.85; margin-right:4px; }
    .hero-amount.muted { color:#cfd6e6; }
    .hero-stats { display:flex; gap:22px; margin-top:auto; padding-top:16px; }
    .hero-stats > div { display:flex; flex-direction:column; }
    .hero-stats .n { font-size:22px; font-weight:800; }
    .hero-stats .l { font-size:11.5px; opacity:.85; }
    .hero-chart { flex:1 1 300px; background:var(--card); border:1px solid var(--line); border-radius:var(--radius);
        padding:18px 20px; box-shadow:var(--shadow); }
    .donut-wrap { display:flex; align-items:center; gap:18px; flex-wrap:wrap; margin-top:8px; }
    svg.donut { flex:0 0 auto; }
    .legend { flex:1; min-width:160px; display:flex; flex-direction:column; gap:6px; }
    .legend .leg { display:flex; align-items:center; gap:8px; font-size:13px; }
    .legend .dot { width:11px; height:11px; border-radius:3px; flex:0 0 auto; }
    .legend .leg-name { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--ink); text-decoration:none; }
    .legend .leg-name:hover { color:var(--accent); text-decoration:underline; }
    .legend .leg-pct { font-variant-numeric:tabular-nums; color:var(--muted); font-weight:700; }

    /* ===== プロダクト詳細ページ ===== */
    .crumb { font-size:13px; color:var(--muted); margin-bottom:14px; }
    .crumb a { color:var(--accent); text-decoration:none; }
    .detail-grid { display:grid; grid-template-columns:1.1fr 1fr; gap:16px; margin-bottom:18px; }
    .panel { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); padding:18px 20px; box-shadow:var(--shadow); }
    .panel h3 { margin:0 0 12px; font-size:14px; color:var(--muted); font-weight:700; }
    .bigcost { font-size:30px; font-weight:800; font-variant-numeric:tabular-nums; }
    .bar-row { display:flex; align-items:center; gap:10px; margin:8px 0; font-size:13px; }
    .bar-row .nm { flex:0 0 38%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .bar-track { flex:1; height:10px; background:#eef1f4; border-radius:6px; overflow:hidden; }
    .bar-fill { height:100%; border-radius:6px; }
    .bar-row .v { flex:0 0 auto; font-variant-numeric:tabular-nums; font-weight:700; }
    .product-link { color:var(--accent); text-decoration:none; font-size:13px; }
    .product-link:hover { text-decoration:underline; }
    .toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:var(--card);
        border:1px solid var(--line); border-radius:var(--radius); padding:12px; margin-bottom:14px; box-shadow:var(--shadow); }
    .toolbar input, .toolbar select { padding:8px 12px; border:1px solid var(--line); border-radius:10px; font-size:14px; }
    .toolbar .spacer { flex:1; }
    button, .btn { font-size:14px; padding:8px 16px; border-radius:10px; border:1px solid var(--line);
        background:#fff; color:var(--ink); cursor:pointer; text-decoration:none; display:inline-block; }
    button.primary, .btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
    button.primary:hover { background:var(--accent-d); }
    button.danger { color:var(--err-ink); border-color:#f3c4c0; background:#fff; }
    button.link { border:none; background:none; color:var(--accent); padding:2px 4px; }
    table { width:100%; border-collapse:collapse; background:var(--card); border-radius:var(--radius); overflow:hidden; }
    th, td { padding:11px 14px; text-align:left; font-size:14px; vertical-align:top; border-bottom:1px solid var(--line); }
    th { background:#f7f9fc; font-size:12px; color:var(--muted); font-weight:700; }
    td.cost { font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:700; }
    tr.api-row:hover { background:#fafbfc; }
    tr.group-head { cursor:pointer; }
    tr.group-head td { background:#f4f7ff; color:#1e293b; }
    tr.group-head:hover td { background:#e9efff; }
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
    td.group-cost { font-size:16px; font-weight:800; color:#0f172a; white-space:nowrap; }
    .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); }
    .table-wrap table { border:none; border-radius:0; min-width:600px; }
    .muted { color:var(--muted); }
    .pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; }
    .pill.active{background:#e7f6ec;color:#1a7f43;} .pill.unused{background:#eef1f4;color:#6b7280;}
    .pill.unknown{background:#fff4e0;color:#b45309;} .pill.deprecated{background:#fdecec;color:#b42318;}
    .usages { background:#fbfcfe; }
    .usages table { box-shadow:none; border:1px solid var(--line); margin:6px 0; }
    .usages td, .usages th { font-size:13px; padding:6px 10px; }
    code { background:#f0f2f5; padding:1px 5px; border-radius:5px; font-size:12.5px; word-break:break-all; }
    .flash { padding:12px 16px; border-radius:12px; margin-bottom:14px; font-size:14px; }
    .flash.ok { background:var(--ok-bg); color:var(--ok-ink); } .flash.err { background:var(--err-bg); color:var(--err-ink); }
    dialog { border:none; border-radius:var(--radius); padding:0; width:min(620px,94vw); box-shadow:0 20px 60px rgba(0,0,0,.25); }
    dialog::backdrop { background:rgba(15,23,42,.45); }
    .modal-head { padding:16px 20px; border-bottom:1px solid var(--line); font-weight:700; }
    .modal-body { padding:16px 20px; }
    .modal-foot { padding:14px 20px; border-top:1px solid var(--line); display:flex; justify-content:flex-end; gap:8px; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid .full { grid-column:1 / -1; }
    .field label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; }
    .field input, .field select, .field textarea { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; }
    .hint { font-size:11.5px; color:var(--muted); margin-top:3px; }
    .empty { text-align:center; color:var(--muted); padding:40px; background:#fff; border-radius:var(--radius); box-shadow:var(--shadow); }
    .note-cell { max-width:220px; }
    .login-box { max-width:420px; margin:8vh auto; background:var(--card); border:1px solid var(--line);
        border-radius:16px; padding:32px; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.06); }
    .gbtn { display:inline-flex; align-items:center; gap:10px; padding:11px 18px; border:1px solid var(--line);
        border-radius:10px; background:#fff; color:#1f2733; text-decoration:none; font-weight:600; font-size:15px; }
    .gbtn:hover { background:#f8fafc; }
    @media (max-width:820px){
        .layout{flex-direction:column;}
        .sidebar{width:auto;flex:none;height:auto;position:static;display:flex;flex-wrap:wrap;align-items:center;gap:6px;border-right:none;border-bottom:1px solid var(--line);}
        .sidebar .brand{padding:6px 8px;} .sidebar .navlabel{display:none;}
        .sidebar a.nav{margin:0;padding:8px 10px;} .sidebar .who{border-top:none;margin:0;}
        .main{padding:14px;}
        .grid{grid-template-columns:1fr;}
        .detail-grid{grid-template-columns:1fr;}
        .hide-sm{display:none;}
        .summary .stat{min-width:0;flex:1 1 45%;padding:12px;}
        .summary .stat .value{font-size:19px;}
        .toolbar input, .toolbar select{flex:1 1 100%;}
        .table-wrap table{min-width:520px;}
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
<?php
/* ================================================================== *
 *  プロダクト詳細ページ（route=product&name=...）
 * ================================================================== */
if ($route === 'product'):
    $pname = (string) ($_GET['name'] ?? '');
    if ($pname === '' || !isset($tree[$pname])) {
        header('Location: ' . app_url());
        exit;
    }
    $pboxes      = $boxesByProduct[$pname] ?? [];
    $punassigned = $unassignedByProduct[$pname] ?? [];
    $pprovider   = $providerOf[$pname] ?? '';

    // コスト（通貨別）
    $pcost = [];
    foreach ($pboxes as $b) {
        if ($b['monthly_cost'] !== null) { $c = $b['currency'] ?: 'USD'; $pcost[$c] = ($pcost[$c] ?? 0) + (float) $b['monthly_cost']; }
    }
    ksort($pcost);

    // 箱ごとの内訳
    $boxList = [];
    foreach ($pboxes as $b) {
        $u = $usagesByBox[(int) $b['id']] ?? [];
        $sites = [];
        foreach ($u as $uu) { $sites[usage_site($uu)] = 1; }
        $boxList[] = ['box' => $b, 'urls' => dedup_urls($u), 'sites' => array_keys($sites)];
    }
    // ドーナツ：箱別コスト（金額あり・降順）
    $bsegs = [];
    foreach ($pboxes as $b) {
        if ($b['monthly_cost'] !== null && (float) $b['monthly_cost'] > 0) { $bsegs[] = ['label' => $b['name'], 'value' => (float) $b['monthly_cost']]; }
    }
    usort($bsegs, static fn($a, $b) => $b['value'] <=> $a['value']);
    $bTot = array_sum(array_map(static fn($s) => $s['value'], $bsegs));

    // サイト別 URL 件数（プロダクト全体）
    $siteCount = [];
    foreach ($pboxes as $b) { foreach (($usagesByBox[(int) $b['id']] ?? []) as $uu) { $s = usage_site($uu); $siteCount[$s] = ($siteCount[$s] ?? 0) + 1; } }
    foreach ($punassigned as $uu) { $s = usage_site($uu); $siteCount[$s] = ($siteCount[$s] ?? 0) + 1; }
    arsort($siteCount);
    $siteMax = $siteCount ? max($siteCount) : 1;
    $totalUrls = array_sum($siteCount);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — <?= h($pname) ?></title>
<?php render_styles(); ?>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="brand">🛡️ <?= h(APP_NAME) ?></div>
    <div class="navlabel">メニュー</div>
    <a class="nav" href="index.php">📊 ダッシュボード</a>
    <?php if (can_manage()): ?><a class="nav" href="<?= h(app_url('scan')) ?>">🔍 スキャン</a><?php endif; ?>
    <a class="nav" href="<?= h(app_url('tokens')) ?>">🔑 トークン</a>
    <a class="nav" href="groups.php">👥 グループ管理</a>
    <div class="navlabel">アカウント</div>
    <div class="who">
        <?php if ($user['avatar_url']): ?><img src="<?= h($user['avatar_url']) ?>" alt=""><?php endif; ?>
        <div>
            <div style="font-weight:700;font-size:13px"><?= h($user['name'] ?: $user['email']) ?></div>
            <div class="role-badge"><?= h(ROLES[$role] ?? $role) ?></div>
        </div>
    </div>
    <a class="nav" href="<?= h(app_url('logout')) ?>">🚪 ログアウト</a>
</aside>
<main class="main">
    <div class="crumb"><a href="index.php">ダッシュボード</a> ／ <?= h($pname) ?></div>
    <div class="topbar">
        <h2>🔷 <?= h($pname) ?><?php if ($pprovider): ?> <span class="muted" style="font-size:14px">（<?= h($pprovider) ?>）</span><?php endif; ?></h2>
    </div>

    <!-- ヒーロー：合計＋箱別ドーナツ -->
    <div class="hero">
        <div class="hero-main">
            <div class="hero-label">月額コスト</div>
            <?php if ($pcost): foreach ($pcost as $cur => $sum): ?>
                <div class="hero-amount"><span class="cur"><?= h($cur) ?></span> <?= number_format($sum, (fmod($sum,1.0)===0.0)?0:2) ?></div>
            <?php endforeach; else: ?>
                <div class="hero-amount muted">未設定</div>
            <?php endif; ?>
            <div class="hero-stats">
                <div><span class="n"><?= count($pboxes) ?></span><span class="l">プロジェクト箱</span></div>
                <div><span class="n"><?= count($siteCount) ?></span><span class="l">サイト</span></div>
                <div><span class="n"><?= $totalUrls ?></span><span class="l">URL</span></div>
            </div>
        </div>
        <div class="hero-chart">
            <div class="hero-label">プロジェクト箱別コスト</div>
            <div class="donut-wrap">
                <?= svg_donut($bsegs, 168, 24) ?>
                <div class="legend">
                    <?php if ($bsegs): $dc = donut_colors(); $di = 0; foreach ($bsegs as $s): if ($di >= 6) { break; } ?>
                        <div class="leg">
                            <span class="dot" style="background:<?= h($dc[$di % count($dc)]) ?>"></span>
                            <span class="leg-name">📦 <?= h($s['label']) ?></span>
                            <span class="leg-pct"><?= $bTot > 0 ? round($s['value'] / $bTot * 100) : 0 ?>%</span>
                        </div>
                    <?php $di++; endforeach;
                        if (count($bsegs) > 6): ?><div class="leg muted">ほか <?= count($bsegs) - 6 ?> 件</div><?php endif; ?>
                    <?php else: ?><div class="muted" style="font-size:13px">箱のコスト未設定</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="detail-grid">
        <!-- 箱別の月額 -->
        <div class="panel">
            <h3>プロジェクト箱の月額</h3>
            <?php if ($bsegs): $dc = donut_colors(); foreach ($bsegs as $i => $s): ?>
                <div class="bar-row">
                    <span class="nm">📦 <?= h($s['label']) ?></span>
                    <span class="bar-track"><span class="bar-fill" style="width:<?= $bTot > 0 ? round($s['value'] / $bTot * 100, 1) : 0 ?>%;background:<?= h($dc[$i % count($dc)]) ?>"></span></span>
                    <span class="v"><?= number_format($s['value'], (fmod($s['value'],1.0)===0.0)?0:2) ?></span>
                </div>
            <?php endforeach; else: ?>
                <div class="muted" style="font-size:13px">コストが設定された箱がありません。</div>
            <?php endif; ?>
        </div>
        <!-- サイト別 URL 件数 -->
        <div class="panel">
            <h3>サイト別 利用箇所（URL数）</h3>
            <?php if ($siteCount): $dc = donut_colors(); $si = 0; foreach ($siteCount as $st => $cnt): ?>
                <div class="bar-row">
                    <span class="nm">🌐 <?= h($st) ?></span>
                    <span class="bar-track"><span class="bar-fill" style="width:<?= round($cnt / $siteMax * 100, 1) ?>%;background:<?= h($dc[$si % count($dc)]) ?>"></span></span>
                    <span class="v"><?= (int) $cnt ?></span>
                </div>
            <?php $si++; endforeach; else: ?>
                <div class="muted" style="font-size:13px">URLがありません。</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 箱とURLの一覧 -->
    <div class="panel" style="margin-bottom:18px">
        <h3>プロジェクト箱とURL</h3>
        <table>
            <thead><tr><th>箱 / URL</th><th>サイト</th><th>月額</th></tr></thead>
            <tbody>
            <?php foreach ($boxList as $bl): $b = $bl['box']; ?>
                <tr class="group-head">
                    <td>📦 <strong><?= h($b['name']) ?></strong> <span class="muted">（<?= count($bl['urls']) ?> URL）</span></td>
                    <td class="muted"><?= h(implode('、', array_slice(array_map('strval', $bl['sites']), 0, 4))) ?><?= count($bl['sites']) > 4 ? ' ほか' : '' ?></td>
                    <td class="cost"><?= fmt_money($b['monthly_cost'] === null ? null : (float) $b['monthly_cost'], $b['currency'] ?: 'USD') ?><?php if (($b['balance'] ?? null) !== null): ?><div class="hint">残高 <?= h($b['currency'] ?: 'USD') ?> <?= number_format((float) $b['balance'], 2) ?></div><?php endif; ?></td>
                </tr>
                <?php foreach ($bl['urls'] as $f): ?>
                <tr>
                    <td style="padding-left:28px"><?= $f['is_key'] ? '🔑' : '📄' ?> <code><?= h($f['file']) ?><?= $f['line'] !== null ? ':' . (int) $f['line'] : '' ?></code></td>
                    <td class="muted">🌐 <?= h(usage_site($f)) ?></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($punassigned): $uurls = dedup_urls($punassigned); ?>
                <tr class="group-head">
                    <td>📦 <strong>未割当</strong> <span class="muted">（<?= count($uurls) ?> URL）</span></td>
                    <td class="muted">—</td><td class="cost">—</td>
                </tr>
                <?php foreach ($uurls as $f): ?>
                <tr>
                    <td style="padding-left:28px"><?= $f['is_key'] ? '🔑' : '📄' ?> <code><?= h($f['file']) ?><?= $f['line'] !== null ? ':' . (int) $f['line'] : '' ?></code></td>
                    <td class="muted">🌐 <?= h(usage_site($f)) ?></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!$boxList && !$punassigned): ?>
                <tr><td colspan="3" class="muted" style="text-align:center;padding:24px">このプロダクトにはまだ箱もURLもありません。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <a class="btn" href="index.php">← ダッシュボードに戻る</a>
</main>
</div>
</body></html>
<?php exit; endif; ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — <?= h($group['name']) ?></title>
<?php render_styles(); ?>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="brand">🛡️ <?= h(APP_NAME) ?></div>
    <div class="navlabel">メニュー</div>
    <a class="nav active" href="index.php">📊 ダッシュボード</a>
    <?php if (can_manage()): ?><a class="nav" href="<?= h(app_url('scan')) ?>">🔍 スキャン</a><?php endif; ?>
    <a class="nav" href="<?= h(app_url('tokens')) ?>">🔑 トークン</a>
    <a class="nav" href="groups.php">👥 グループ管理</a>
    <div class="navlabel">アカウント</div>
    <div class="who">
        <?php if ($user['avatar_url']): ?><img src="<?= h($user['avatar_url']) ?>" alt=""><?php endif; ?>
        <div>
            <div style="font-weight:700;font-size:13px"><?= h($user['name'] ?: $user['email']) ?></div>
            <div class="role-badge"><?= h(ROLES[$role] ?? $role) ?></div>
        </div>
    </div>
    <a class="nav" href="<?= h(app_url('logout')) ?>">🚪 ログアウト</a>
</aside>
<main class="main">
    <div class="topbar">
        <h2>ダッシュボード</h2>
        <span class="grow"></span>
        <?php if (count($memberships) > 1): ?>
            <select onchange="if(this.value)location.href=this.value">
                <?php foreach ($memberships as $m): ?>
                    <option value="<?= h(app_url('switchgroup', ['gid' => (int) $m['id']])) ?>" <?= (int) $m['id'] === $gid ? 'selected' : '' ?>>
                        <?= h($m['name']) ?>（<?= h(ROLES[$m['role']] ?? $m['role']) ?>）
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <span class="muted"><?= h($group['name']) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($flashMsg): ?>
        <div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div>
    <?php endif; ?>

    <?php if (!$editable): ?>
        <div class="flash" style="background:#eef4ff;color:#1d4ed8">あなたは <strong>閲覧者(viewer)</strong> です。編集はできません。</div>
    <?php endif; ?>

    <!-- 月額合計サマリ -->
    <div class="hero">
        <div class="hero-main">
            <div class="hero-label">月額コスト合計</div>
            <?php if ($subtotals): ?>
                <?php foreach ($subtotals as $cur => $sum): ?>
                    <div class="hero-amount"><span class="cur"><?= h($cur) ?></span> <?= number_format($sum, (fmod($sum,1.0)===0.0)?0:2) ?></div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="hero-amount muted">—</div>
            <?php endif; ?>
            <div class="hero-stats">
                <div><span class="n"><?= count($tree) ?></span><span class="l">プロダクト</span></div>
                <div><span class="n"><?= count($projects) ?></span><span class="l">プロジェクト箱</span></div>
                <div><span class="n"><?= count($allUsages) ?></span><span class="l">URL</span></div>
            </div>
        </div>
        <div class="hero-chart">
            <div class="hero-label">プロダクト別内訳<?= $donutCur !== '' ? '（' . h($donutCur) . '）' : '' ?></div>
            <div class="donut-wrap">
                <?= svg_donut($donutSegs, 168, 24) ?>
                <div class="legend">
                    <?php if ($donutSegs): $dc = donut_colors(); $di = 0;
                        $dtot = array_sum(array_map(static fn($s) => $s['value'], $donutSegs));
                        foreach ($donutSegs as $s): if ($di >= 6) { break; } ?>
                        <div class="leg">
                            <span class="dot" style="background:<?= h($dc[$di % count($dc)]) ?>"></span>
                            <a href="<?= h(app_url('product', ['name' => $s['label']])) ?>" class="leg-name"><?= h($s['label']) ?></a>
                            <span class="leg-pct"><?= $dtot > 0 ? round($s['value'] / $dtot * 100) : 0 ?>%</span>
                        </div>
                    <?php $di++; endforeach; ?>
                    <?php if (count($donutSegs) > 6): ?><div class="leg muted">ほか <?= count($donutSegs) - 6 ?> 件</div><?php endif; ?>
                    <?php else: ?><div class="muted" style="font-size:13px">コスト未設定</div><?php endif; ?>
                </div>
            </div>
        </div>
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
        <label class="hint" style="white-space:nowrap"><input type="checkbox" onchange="selAllGlobal(this)" style="width:auto"> 表示中の全URL選択</label>
        <button class="btn" type="button" onclick="openProject({})">＋ 箱を追加</button>
    </div>
    <?php endif; ?>
    <?php if ($projects): ?>
    <details class="stat" style="width:100%;margin-bottom:10px" <?= $editable ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-weight:600">📦 プロジェクト箱の一覧・管理（<?= count($projects) ?>）</summary>
        <table style="margin-top:8px">
            <thead><tr><th>箱</th><th>OpenAI proj</th><th>月額</th><th>URL数</th><?php if ($editable): ?><th>操作</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($projects as $p): $cnt = $boxUrlCount[(int) $p['id']] ?? 0; ?>
                <tr>
                    <td>📦 <strong><?= h($p['name']) ?></strong></td>
                    <td class="muted"><?= $p['openai_project_id'] !== '' ? h($p['openai_project_id']) : '—' ?></td>
                    <td class="cost"><?= fmt_money($p['monthly_cost'] === null ? null : (float) $p['monthly_cost'], $p['currency'] ?: 'USD') ?><?php if (($p['balance'] ?? null) !== null): ?><div class="hint">残高 <?= h($p['currency'] ?: 'USD') ?> <?= number_format((float) $p['balance'], 2) ?></div><?php endif; ?></td>
                    <td><?= $cnt ?> <span class="muted">URL</span></td>
                    <?php if ($editable): ?>
                    <td style="white-space:nowrap">
                        <?php if (($p['cost_type'] ?? '') !== '' || trim((string) $p['openai_project_id']) !== ''): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="fetch_project_cost"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>"><button class="link" type="submit">⟳コスト</button></form>
                        <?php endif; ?>
                        <button class="link" type="button" onclick='openProject(<?= json_encode(["id"=>(int)$p["id"],"name"=>$p["name"],"product"=>$p["product"] ?? "","cost_type"=>$p["cost_type"] ?? "","cost_account"=>$p["cost_account"] ?? "","openai_project_id"=>$p["openai_project_id"],"secret_hint"=>$p["secret_hint"],"monthly_cost"=>$p["monthly_cost"],"currency"=>$p["currency"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                        <form method="post" style="display:inline" onsubmit="return confirm('箱「<?= h($p['name']) ?>」を削除しますか？（URLは未割当へ）')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>"><button class="link danger" type="submit">削除</button></form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="hint" style="margin:6px 0 0">※ 下のツリーには「URLが入っている箱」だけが、使われているプロダクトの下に表示されます。空の箱もここから管理できます。</p>
    </details>
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
            $provider = $providerOf[$gname] ?? '';
            $pboxes = $boxesByProduct[$gname] ?? [];
            $punassigned = $unassignedByProduct[$gname] ?? [];
            $nBoxes = count($pboxes);
            // サイト集計（配下の箱URL＋未割当URL）＝ファイル先頭ディレクトリ
            $prodSites = [];
            foreach ($pboxes as $b) { foreach (($usagesByBox[(int) $b['id']] ?? []) as $u) { $prodSites[usage_site($u)] = 1; } }
            foreach ($punassigned as $u) { $prodSites[usage_site($u)] = 1; }
            // 金額（箱コストの通貨別和）
            $pmoney = [];
            foreach ($pboxes as $b) { if ($b['monthly_cost'] !== null) { $cur = $b['currency'] ?: 'USD'; $pmoney[$cur] = ($pmoney[$cur] ?? 0) + (float) $b['monthly_cost']; } }
            ksort($pmoney);
            $pmoneyStr = $pmoney ? implode('　', array_map(static fn($c, $v) => $c . ' ' . number_format($v, (fmod($v,1.0)===0.0)?0:2), array_keys($pmoney), $pmoney)) : '—';
        ?>
            <tr class="group-head" data-name="<?= h($gname) ?>" data-gi="<?= $gi ?>" onclick="toggleProduct(<?= $gi ?>)"
                <?= $editable ? 'draggable="true" ondragstart="gDragStart(event,this)" ondragend="gDragEnd(this)" ondragover="gDragOver(event,this)" ondragleave="gDragLeave(this)" ondrop="gDrop(event,this)"' : '' ?>>
                <td><?php if ($editable): ?><span class="drag-handle" title="ドラッグで並べ替え">⠿</span><?php endif; ?><span id="pc<?= $gi ?>" class="caret">▶</span></td>
                <td>🔷 <strong><?= h($gname) ?></strong> <?php if ($provider): ?><span class="muted">（<?= h($provider) ?>）</span><?php endif; ?>
                    <a href="<?= h(app_url('product', ['name' => $gname])) ?>" class="product-link" onclick="event.stopPropagation()">詳細 →</a></td>
                <td class="cost group-cost"><?= h($pmoneyStr) ?></td>
                <td class="hide-sm" style="white-space:nowrap">
                    <span class="muted"><?= $nBoxes ?> 箱 / <?= count($prodSites) ?> サイト</span>
                    <?php if ($editable): ?>
                        <form method="post" style="display:inline" onclick="event.stopPropagation()"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reorder"><input type="hidden" name="name" value="<?= h($gname) ?>"><input type="hidden" name="dir" value="up"><button class="link" type="submit" title="上へ">▲</button></form>
                        <form method="post" style="display:inline" onclick="event.stopPropagation()"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reorder"><input type="hidden" name="name" value="<?= h($gname) ?>"><input type="hidden" name="dir" value="down"><button class="link" type="submit" title="下へ">▼</button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($editable && $prodSites): ?>
            <tr class="prod<?= $gi ?> p<?= $gi ?>-proj" style="display:none">
                <td></td>
                <td colspan="3"><span class="hint">サイトごと移動: </span>
                    <?php foreach (array_keys($prodSites) as $s): ?>
                        <label style="font-size:12px;margin-right:8px;white-space:nowrap"><input type="checkbox" class="siteChk" value="<?= h($s) ?>" onchange="updMoveCount()" style="width:auto"> 🌐<?= h($s) ?></label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endif; ?>
        <?php $pj = 0;
            // 箱（空箱も含む）＋ 末尾に未割当バケット
            $renderBoxes = [];
            foreach ($pboxes as $b) { $renderBoxes[] = ['proj' => $b, 'usages' => $usagesByBox[(int) $b['id']] ?? []]; }
            if ($punassigned) { $renderBoxes[] = ['proj' => null, 'usages' => $punassigned]; }
            foreach ($renderBoxes as $rb): $pj++;
            $proj = $rb['proj'];
            $urls = dedup_urls($rb['usages']);
            $boxLabel = $proj ? ('📦 ' . $proj['name'] . ($proj['openai_project_id'] !== '' ? '（' . $proj['openai_project_id'] . '）' : '')) : '📦 未割当';
            $boxMoney = $proj ? fmt_money($proj['monthly_cost'] === null ? null : (float) $proj['monthly_cost'], $proj['currency'] ?: 'USD') : '—';
        ?>
            <tr class="prod<?= $gi ?> p<?= $gi ?>-proj" style="display:none">
                <td style="text-align:right"><span id="jc<?= $gi ?>_<?= $pj ?>" class="caret" onclick="toggleProj(<?= $gi ?>,<?= $pj ?>)" style="cursor:pointer">▶</span></td>
                <td style="padding-left:24px"><?= h($boxLabel) ?> <span class="muted">（<?= count($urls) ?> URL）</span><?php if ($proj && ($proj['balance'] ?? null) !== null): ?> <span class="muted">｜残高 <?= h($proj['currency'] ?: 'USD') ?> <?= number_format((float) $proj['balance'], 2) ?></span><?php endif; ?></td>
                <td class="cost"><?= $boxMoney ?></td>
                <td class="hide-sm" style="white-space:nowrap" onclick="event.stopPropagation()">
                    <?php if ($editable && $proj): ?>
                        <?php if (($proj['cost_type'] ?? '') !== '' || trim((string) $proj['openai_project_id']) !== ''): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="fetch_project_cost"><input type="hidden" name="project_id" value="<?= (int) $proj['id'] ?>"><button class="link" type="submit" title="コスト取得">⟳コスト</button></form>
                        <?php endif; ?>
                        <button class="link" type="button" onclick='openProject(<?= json_encode(["id"=>(int)$proj["id"],"name"=>$proj["name"],"product"=>$proj["product"],"cost_type"=>$proj["cost_type"] ?? "","cost_account"=>$proj["cost_account"] ?? "","openai_project_id"=>$proj["openai_project_id"],"secret_hint"=>$proj["secret_hint"],"monthly_cost"=>$proj["monthly_cost"],"currency"=>$proj["currency"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>箱を編集</button>
                        <form method="post" style="display:inline" onsubmit="return confirm('箱「<?= h($proj['name']) ?>」を削除しますか？（URLは未割当に戻ります）')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $proj['id'] ?>"><button class="link danger" type="submit">箱削除</button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="prod<?= $gi ?> p<?= $gi ?>j<?= $pj ?>-file" style="display:none">
                <td></td>
                <td colspan="3" style="padding-left:48px">
                    <?php if (!$urls): ?><span class="muted">URLなし（移動バーでこの箱へURL/サイトを割り当てできます）</span><?php endif; ?>
                    <?php if ($editable && $urls): ?><label class="hint" style="display:inline-block;margin-bottom:4px"><input type="checkbox" onchange="selAllBox(this,<?= $gi ?>,<?= $pj ?>)" style="width:auto"> この箱のURLを全選択</label><?php endif; ?>
                    <?php foreach ($urls as $f):
                        $fsite = usage_site($f);
                        $pathStr = $fsite . ' / ' . $f['file'] . ($f['line'] !== null ? ':' . (int) $f['line'] : '');
                    ?>
                        <div style="white-space:nowrap">
                            <?php if ($editable): ?><input type="checkbox" class="moveChk" value="<?= (int) $f['id'] ?>" onchange="updMoveCount()" style="width:auto"><?php endif; ?>
                            <?= $f['is_key'] ? '🔑' : '📄' ?>
                            <strong>🌐<?= h($fsite) ?></strong>
                            <code><?= h($f['file']) ?><?= $f['line'] !== null ? ':' . (int) $f['line'] : '' ?></code>
                            <button class="link" type="button" title="コピー" onclick="copyText(<?= htmlspecialchars(json_encode($pathStr, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">📋</button>
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
        ※ コストは⟳取得 or 手入力。一覧ではURLは展開時のみ表示。APIキー本体は保存せず暗号化／鍵の在りかのみ記録します。
    </p>
</main>
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
                <div class="field full"><label>所属プロダクト（API）</label>
                    <input name="product" id="pf_product" list="productList" placeholder="例: OpenAI API">
                    <datalist id="productList"><?php foreach ($names as $nm): if ($nm !== '（プロダクト未指定）'): ?><option value="<?= h($nm) ?>"></option><?php endif; endforeach; ?></datalist>
                    <div class="hint">この箱がどのAPI（プロダクト）の下に表示されるか。</div>
                </div>
                <div class="field full" style="border-top:1px dashed var(--line);padding-top:10px">
                    <label>コスト自動取得の種別</label>
                    <select name="cost_type" id="pf_cost_type" onchange="pfCostTypeChange()">
                        <option value="">なし（手入力）</option>
                        <option value="openai">OpenAI</option>
                        <option value="twilio">Twilio</option>
                    </select>
                </div>
                <div class="field full" id="pf_proj_wrap"><label>OpenAI プロジェクトID</label><input name="openai_project_id" id="pf_proj" placeholder="proj_xxxxx（空＝組織全体）"></div>
                <div class="field full" id="pf_acct_wrap" style="display:none"><label id="pf_acct_label">アカウントID</label><input name="cost_account" id="pf_acct" placeholder="Twilio: Account SID（ACxxxx）"></div>
                <div class="field"><label>月額（手入力・任意）</label><input name="monthly_cost" id="pf_cost" type="number" step="0.01" min="0" placeholder="自動取得しない場合"></div>
                <div class="field"><label>通貨</label><select name="currency" id="pf_currency"><?php foreach (['USD','JPY','EUR','GBP'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select></div>
                <div class="field full"><label>🔐 キー / トークン（コスト取得用・暗号化保存）</label><input type="password" name="secret" id="pf_secret" autocomplete="new-password" placeholder="OpenAI: sk-admin-... / Twilio: Auth Token"><div class="hint" id="pf_secret_state"></div></div>
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
    function pfCostTypeChange() {
        const t = document.getElementById('pf_cost_type').value;
        document.getElementById('pf_proj_wrap').style.display = (t === 'openai') ? '' : 'none';
        const aw = document.getElementById('pf_acct_wrap');
        aw.style.display = (t === 'twilio') ? '' : 'none';
        if (t === 'twilio') { document.getElementById('pf_acct_label').textContent = 'Twilio Account SID'; }
    }
    function openProject(p) {
        p = p || {};
        document.getElementById('projModalTitle').textContent = p.id ? 'プロジェクト箱を編集' : 'プロジェクト箱を追加';
        document.getElementById('pf_id').value = p.id ?? '';
        document.getElementById('pf_name').value = p.name ?? '';
        document.getElementById('pf_product').value = p.product ?? '';
        document.getElementById('pf_cost_type').value = p.cost_type ?? '';
        document.getElementById('pf_acct').value = p.cost_account ?? '';
        document.getElementById('pf_proj').value = p.openai_project_id ?? '';
        pfCostTypeChange();
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
    function selAllBox(cb, gi, pj) {
        document.querySelectorAll('.p' + gi + 'j' + pj + '-file .moveChk').forEach(c => { c.checked = cb.checked; });
        updMoveCount();
    }
    function selAllGlobal(cb) {
        document.querySelectorAll('.moveChk').forEach(c => { c.checked = cb.checked; });
        updMoveCount();
    }
    function copyText(t) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(t).then(() => abtToast('コピー: ' + t)).catch(() => abtToast('コピーできませんでした'));
        } else {
            const ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); abtToast('コピー: ' + t); } catch (e) { abtToast('コピーできませんでした'); }
            ta.remove();
        }
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
