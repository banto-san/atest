<?php
declare(strict_types=1);

/**
 * API番頭さん (api_banto_san) — API棚卸しツール
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

    if ($action === 'add_site') {
        require_role_at_least($gid, 'member');
        $proj = get_project($gid, (int) ($_POST['project_id'] ?? 0));
        $site = trim((string) ($_POST['site'] ?? ''));
        if (!$proj) { flash('err', '箱が見つかりません。'); }
        elseif ($site === '') { flash('err', 'サイト名を入力してください。'); }
        elseif (trim((string) $proj['product']) === '') { flash('err', '先に箱のプロダクト（API）を設定してください。'); }
        else {
            add_manual_site($gid, (int) $proj['id'], trim((string) $proj['product']), $site);
            flash('ok', 'サイト「' . $site . '」を箱「' . $proj['name'] . '」に追加しました。');
        }
        redirect_self();
    }

    if ($action === 'rename_site') {
        require_role_at_least($gid, 'member');
        $old = trim((string) ($_POST['old_site'] ?? ''));
        $new = trim((string) ($_POST['new_site'] ?? ''));
        if ($old === '' || $new === '') { flash('err', 'サイト名を入力してください。'); redirect_self(); }
        if ($old === $new) { redirect_self(); }
        $n = rename_site($gid, $old, $new);
        flash('ok', sprintf('サイト「%s」を「%s」に変更しました（%d件）。', $old, $new, $n));
        redirect_self();
    }

    if ($action === 'save_project') {
        require_role_at_least($gid, 'member');
        $pidIn = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int) $_POST['project_id'] : null;
        $pname = trim((string) ($_POST['name'] ?? ''));
        $pprod = trim((string) ($_POST['product'] ?? ''));
        $pproj = trim((string) ($_POST['openai_project_id'] ?? ''));
        $ptype = (string) ($_POST['cost_type'] ?? '');
        if (!in_array($ptype, ['', 'openai', 'anthropic', 'twilio', 'dataforseo', 'vonage', 'serpapi', 'gcp_bq'], true)) { $ptype = ''; }
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
        $credRaw = (string) ($_POST['credential_id'] ?? '');
        $credId = ctype_digit($credRaw) ? (int) $credRaw : null;   // 数値=キー選択 / それ以外=継承・直接入力
        $pdo->prepare('UPDATE projects SET name=:n, product=:prod, cost_type=:ct, cost_account=:ca, openai_project_id=:p, monthly_cost=:mc, currency=:cur, credential_id=:cid, updated_at=:u WHERE id=:id AND group_id=:g')
            ->execute([':n'=>$pname, ':prod'=>$pprod, ':ct'=>$ptype, ':ca'=>$pacct, ':p'=>$pproj, ':mc'=>$mcost, ':cur'=>$mcur, ':cid'=>$credId, ':u'=>now(), ':id'=>$pidIn, ':g'=>$gid]);
        if ($secretIn !== '' && encryption_ready()) {
            $pdo->prepare('UPDATE projects SET secret_enc=:e, secret_hint=:h, secret_fp=:f WHERE id=:id AND group_id=:g')
                ->execute([':e'=>encrypt_secret($secretIn), ':h'=>secret_hint($secretIn), ':f'=>secret_fingerprint($secretIn), ':id'=>$pidIn, ':g'=>$gid]);
        }
        if ($mcost !== null) { $sp = get_project($gid, $pidIn); if ($sp) { snapshot_box($gid, $sp); } }
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

    if ($action === 'delete_product') {
        require_role_at_least($gid, 'admin');
        $prod = (string) ($_POST['product'] ?? '');
        if ($prod === '' || $prod === '（プロダクト未指定）') {
            flash('err', 'このプロダクトは削除できません。');
            redirect_self();
        }
        try {
            $r = delete_product($gid, $prod);
            flash('ok', sprintf('プロダクト「%s」を削除しました（API %d件・箱 %d件）。他プロダクトのキーを含むURLは未割当に戻しました。', $prod, $r['apis'], $r['boxes']));
        } catch (Throwable $e) {
            flash('err', 'プロダクトの削除に失敗: ' . $e->getMessage());
        }
        // 削除後は対象プロダクトが無くなるためダッシュボードへ
        header('Location: ' . app_url());
        exit;
    }

    if ($action === 'save_credential') {
        require_role_at_least($gid, 'member');
        $cidIn = isset($_POST['credential_id']) && $_POST['credential_id'] !== '' ? (int) $_POST['credential_id'] : null;
        $cname = trim((string) ($_POST['name'] ?? ''));
        $ctype = (string) ($_POST['cost_type'] ?? '');
        if (!in_array($ctype, ['', 'openai', 'anthropic', 'twilio', 'dataforseo', 'vonage', 'serpapi', 'gcp_bq'], true)) { $ctype = ''; }
        $cacct = trim((string) ($_POST['cost_account'] ?? ''));
        $cproj = trim((string) ($_POST['openai_project_id'] ?? ''));
        $csecret = (string) ($_POST['secret'] ?? '');
        if (trim($csecret) === '' && trim((string) ($_POST['secret_json'] ?? '')) !== '') { $csecret = (string) $_POST['secret_json']; }
        if ($cname === '') { flash('err', 'キーの名前は必須です。'); redirect_self(); }
        if ($cidIn === null && $csecret === '') { flash('err', 'キーの値を入力してください。'); redirect_self(); }
        if ($csecret !== '' && !encryption_ready()) { flash('err', 'APP_ENCRYPTION_KEY が未設定のため、キーを保存できません。'); redirect_self(); }
        save_credential($gid, $cidIn, $cname, $ctype, $cacct, $cproj, $csecret);
        flash('ok', 'コスト取得キーを保存しました。');
        redirect_self();
    }

    if ($action === 'delete_credential') {
        require_role_at_least($gid, 'admin');
        delete_credential($gid, (int) ($_POST['credential_id'] ?? 0));
        flash('ok', 'コスト取得キーを削除しました。');
        redirect_self();
    }

    if ($action === 'save_guide') {
        require_role_at_least($gid, 'admin');
        $gkey = trim((string) ($_POST['ckey'] ?? ''));
        if ($gkey === '') { $gkey = 'c' . substr(preg_replace('/[^a-z0-9]/', '', strtolower((string) ($_POST['title'] ?? ''))) ?: (string) time(), 0, 24); }
        $gtitle = trim((string) ($_POST['title'] ?? ''));
        if ($gtitle === '') { flash('err', 'タイトルを入力してください。'); redirect_self(); }
        save_guide($gid, $gkey, $gtitle, trim((string) ($_POST['needs'] ?? '')), trim((string) ($_POST['source'] ?? '')), trim((string) ($_POST['url'] ?? '')));
        flash('ok', 'ガイドを保存しました。');
        redirect_self();
    }

    if ($action === 'delete_guide') {
        require_role_at_least($gid, 'admin');
        delete_guide($gid, (string) ($_POST['ckey'] ?? ''));
        flash('ok', 'ガイドを既定に戻しました（独自項目は削除）。');
        redirect_self();
    }

    if ($action === 'set_product_credential') {
        require_role_at_least($gid, 'member');
        $prod = (string) ($_POST['product'] ?? '');
        $credRaw = (string) ($_POST['credential_id'] ?? '');
        $credId = ctype_digit($credRaw) ? (int) $credRaw : null;
        if ($prod !== '') { set_product_credential($gid, $prod, $credId); }
        flash('ok', 'プロダクトの既定キーを設定しました。');
        redirect_self();
    }

    if ($action === 'save_product_logo') {
        require_role_at_least($gid, 'member');
        $prod = (string) ($_POST['product'] ?? '');
        $color = trim((string) ($_POST['logo_color'] ?? ''));
        $url = trim((string) ($_POST['logo_url'] ?? ''));
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $color = ''; }
        try {
            $up = save_uploaded_logo($gid, $prod);
            if ($up !== null) { $url = $up; }
        } catch (Throwable $e) { flash('err', $e->getMessage()); redirect_self(); }
        if ($url !== '' && !preg_match('#^https?://#i', $url)) { $url = ''; }
        if ($prod !== '') { set_product_logo($gid, $prod, $color, $url); }
        flash('ok', 'アイコンの見た目を保存しました。');
        redirect_self();
    }

    if ($action === 'refresh_costs') {
        require_role_at_least($gid, 'member');
        $r = refresh_all_costs($gid);
        if (($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            echo json_encode($r);
            exit;
        }
        flash('ok', sprintf('コストを一括更新しました（成功 %d / 失敗 %d / 対象外 %d）。', $r['ok'], $r['fail'], $r['skip']));
        redirect_self();
    }

    if ($action === 'refresh_product') {
        require_role_at_least($gid, 'member');
        $prod = (string) ($_POST['product'] ?? '');
        if ($prod === '') { flash('err', 'プロダクトが指定されていません。'); redirect_self(); }
        $r = refresh_product_costs($gid, $prod);
        flash('ok', sprintf('「%s」のコストを更新しました（成功 %d / 失敗 %d / 対象外 %d）。', $prod, $r['ok'], $r['fail'], $r['skip']));
        redirect_self();
    }

    if ($action === 'add_credit') {
        require_role_at_least($gid, 'member');
        $prod = (string) ($_POST['product'] ?? '');
        $amt = (float) ($_POST['amount'] ?? 0);
        $cur = trim((string) ($_POST['currency'] ?? 'JPY')) ?: 'JPY';
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($prod === '' || $amt <= 0) { flash('err', '金額を正しく入力してください。'); }
        else { add_credit_purchase($gid, $prod, $amt, $cur, $note); flash('ok', '追加クレジット ' . h($cur) . ' ' . number_format($amt) . ' を記録しました。'); }
        redirect_self();
    }

    if ($action === 'delete_credit') {
        require_role_at_least($gid, 'member');
        delete_credit_purchase($gid, (int) ($_POST['id'] ?? 0));
        flash('ok', '追加クレジットの記録を削除しました。');
        redirect_self();
    }

    if ($action === 'save_account') {
        require_role_at_least($gid, 'member');
        $aid = isset($_POST['account_id']) && $_POST['account_id'] !== '' ? (int) $_POST['account_id'] : null;
        $svc = trim((string) ($_POST['service'] ?? ''));
        if ($svc === '') { flash('err', 'サービス名は必須です。'); redirect_self(); }
        $pw = (string) ($_POST['password'] ?? '');
        if ($pw !== '' && !encryption_ready()) { flash('err', 'APP_ENCRYPTION_KEY が未設定のため、パスワードを保存できません。'); redirect_self(); }
        [$lcolor, $lurl] = account_logo_inputs();
        $aid = save_account(
            $gid, $aid,
            trim((string) ($_POST['category'] ?? '')), $svc,
            trim((string) ($_POST['login_id'] ?? '')), trim((string) ($_POST['url'] ?? '')),
            trim((string) ($_POST['notes'] ?? '')), $pw,
            (string) ($user['email'] ?? ''), $lcolor, $lurl
        );
        try {
            $up = save_uploaded_logo($gid, 'acct#' . $aid);
            if ($up !== null) { db()->prepare("UPDATE accounts SET logo_url=:u WHERE id=:id AND group_id=:g AND owner_email=''")->execute([':u' => $up, ':id' => $aid, ':g' => $gid]); }
        } catch (Throwable $e) { flash('err', $e->getMessage()); redirect_self(); }
        flash('ok', 'アカウントを保存しました。');
        redirect_self();
    }

    if ($action === 'delete_account') {
        require_role_at_least($gid, 'admin');
        delete_account($gid, (int) ($_POST['account_id'] ?? 0));
        flash('ok', 'アカウントを削除しました。');
        redirect_self();
    }

    if ($action === 'reveal_account') {
        require_role_at_least($gid, 'member');
        $pw = reveal_account_password($gid, (int) ($_POST['account_id'] ?? 0));
        header('Content-Type: application/json');
        echo json_encode(['password' => $pw]);
        exit;
    }

    /* ---- 個人アカウント（本人のみ。ロール不問・本人のメールで限定） ---- */
    if ($action === 'save_my_account') {
        $me = (string) ($user['email'] ?? '');
        if ($me === '') { flash('err', 'ログイン情報が取得できませんでした。'); redirect_self(); }
        $aid = isset($_POST['account_id']) && $_POST['account_id'] !== '' ? (int) $_POST['account_id'] : null;
        $svc = trim((string) ($_POST['service'] ?? ''));
        if ($svc === '') { flash('err', 'サービス名は必須です。'); redirect_self(); }
        $pw = (string) ($_POST['password'] ?? '');
        if ($pw !== '' && !encryption_ready()) { flash('err', 'APP_ENCRYPTION_KEY が未設定のため、パスワードを保存できません。'); redirect_self(); }
        [$lcolor, $lurl] = account_logo_inputs();
        $aid = save_my_account(
            $gid, $me, $aid,
            trim((string) ($_POST['category'] ?? '')), $svc,
            trim((string) ($_POST['login_id'] ?? '')), trim((string) ($_POST['url'] ?? '')),
            trim((string) ($_POST['notes'] ?? '')), $pw, $lcolor, $lurl
        );
        try {
            $up = save_uploaded_logo($gid, 'acct#' . $aid);
            if ($up !== null) { db()->prepare("UPDATE accounts SET logo_url=:u WHERE id=:id AND owner_email=:o")->execute([':u' => $up, ':id' => $aid, ':o' => $me]); }
        } catch (Throwable $e) { flash('err', $e->getMessage()); redirect_self(); }
        flash('ok', '個人アカウントを保存しました。');
        redirect_self();
    }

    if ($action === 'delete_my_account') {
        $me = (string) ($user['email'] ?? '');
        if ($me !== '') { delete_my_account($me, (int) ($_POST['account_id'] ?? 0)); }
        flash('ok', '個人アカウントを削除しました。');
        redirect_self();
    }

    if ($action === 'reveal_my_account') {
        $me = (string) ($user['email'] ?? '');
        $pw = $me !== '' ? reveal_my_account_password($me, (int) ($_POST['account_id'] ?? 0)) : null;
        header('Content-Type: application/json');
        echo json_encode(['password' => $pw]);
        exit;
    }

    if ($action === 'save_product_alert') {
        require_role_at_least($gid, 'member');
        $prod = (string) ($_POST['product'] ?? '');
        $raw = trim((string) ($_POST['cost_alert'] ?? ''));
        $amt = ($raw === '') ? null : (float) $raw;
        if ($prod !== '') { set_product_alert($gid, $prod, $amt); }
        flash('ok', $amt === null ? 'コスト警告しきい値を解除しました。' : 'コスト警告しきい値を保存しました。');
        redirect_self();
    }

    if ($action === 'snapshot_all') {
        require_role_at_least($gid, 'member');
        $n = snapshot_all($gid);
        flash('ok', $n . '件の箱を今月（' . month_key() . '）のスナップショットに記録しました。');
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

    if ($action === 'delete_usages') {
        require_role_at_least($gid, 'member');
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['usage_ids'] ?? []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("DELETE FROM usages WHERE id IN ($in) AND api_id IN (SELECT id FROM apis WHERE group_id = ?)");
            $st->execute(array_merge($ids, [$gid]));
            flash('ok', $st->rowCount() . '件のURLを削除しました。');
        } else {
            flash('err', '削除するURLが選択されていません。');
        }
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

// コスト取得キー（名前付きクレデンシャル）
$credentials = list_credentials($gid);
$credById = [];
foreach ($credentials as $c) { $credById[(int) $c['id']] = $c; }
// プロダクト既定キーを持つプロダクト名の集合（⟳コストボタン表示判定に使う）
$prodCredSet = [];
foreach ($pdo->query("SELECT name FROM catalog_pref WHERE group_id = " . (int) $gid . " AND credential_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $pn) { $prodCredSet[(string) $pn] = true; }
// プロダクトのアイコン見た目（色/画像）
$prodMeta = list_product_meta($gid);
// 月次スナップショット（前月比・推移用）
$snap = product_month_snapshots($gid);
$ymNow = month_key();
$ymPrev = month_key(-1);

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
// 全体ドーナツ：全通貨を JPY 換算してプロダクト内訳を描く（USDもJPYも一緒に）
$donutCur = 'JPY換算';
$prodJpy = [];
foreach ($prodCostByCur as $cur => $byProd) {
    foreach ($byProd as $nm => $v) { $prodJpy[$nm] = ($prodJpy[$nm] ?? 0) + to_jpy((float) $v, $cur); }
}
arsort($prodJpy);
$donutSegs = [];
foreach ($prodJpy as $nm => $v) { if ($v > 0) { $donutSegs[] = ['label' => $nm, 'value' => (float) $v]; } }

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
    return h($cur) . ' ' . $n . jpy_hint($cost, $cur);
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

/** アカウントのアイコン入力（背景色・画像URL）を検証して [color, url] を返す */
function account_logo_inputs(): array
{
    $color = trim((string) ($_POST['logo_color'] ?? ''));
    if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $color = ''; }
    $url = trim((string) ($_POST['logo_url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) { $url = ''; }
    return [$color, $url];
}

/** 共通スタイル */

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
<header class="app"><h1><img class="brandlogo" src="<?= h(app_base_url()) ?>/logo.svg" alt=""> <?= h(APP_NAME) ?></h1><span class="tag">API棚卸しダッシュボード</span></header>
<div class="wrap">
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>
    <div class="login-box">
        <div class="duck-hero"><img class="duckimg duck-bob" src="<?= h(app_base_url()) ?>/duck2.png" alt="" style="width:104px"></div>
        <h2 style="margin-top:0"><?= h(APP_NAME) ?></h2>
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
<div class="layout">
<?php render_sidebar('tokens'); ?>
<main class="main">
    <div class="topbar"><h2><?= icon('key') ?> 個人用トークン</h2></div>
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
                        <form method="post" onsubmit="return abtConfirmForm(this, 'このトークンを失効しますか？')">
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
</main></div></body></html>
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
<div class="layout">
<?php render_sidebar('scan'); ?>
<main class="main">
    <div class="topbar"><h2><?= icon('search') ?> スキャン</h2><span class="grow"></span><span class="muted"><?= h($group['name']) ?></span></div>
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>

    <p class="hint" style="margin-top:0">ソースを走査して外部APIの使用箇所を自動検出し、<strong><?= h($group['name']) ?></strong> のカタログに反映します。
    手動入力したコスト・メモ・status は<strong>上書きされません</strong>。使用箇所スニペットのキー値は伏字化します。
    <code>node_modules</code>/<code>vendor</code>/<code>.git</code> 等は自動除外。</p>

    <!-- キー値の自動取り込み（オプトイン） -->
    <div class="stat" style="width:100%;margin-bottom:14px;<?= encryption_ready() ? 'background:#f0fdf4;border-color:#86efac' : 'background:#fff4e0;border-color:#facc15' ?>">
        <label style="display:flex;align-items:center;gap:8px;font-weight:600;<?= encryption_ready() ? '' : 'color:#92400e' ?>">
            <input type="checkbox" id="withSecretsToggle" style="width:auto" <?= encryption_ready() ? '' : 'disabled' ?>>
            <?= icon('lock', 15) ?> .env等に書かれた「キーの値」も暗号化して取り込む（コスト自動取得用）
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
                            <form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '「<?= h($t['label']) ?>」を削除しますか？')">
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
</main>
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

/** API追加/編集・プロジェクト箱 編集モーダル＋その操作JS（dashboard/詳細で共用） */
function render_modals(string $csrf, array $names, array $credentials): void
{
    if (!can_edit()) { return; }
    ?>
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
                    <label><?= icon('lock', 15) ?> APIキー（値）— コスト自動取得用</label>
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
                    <div class="hint"><?= icon('refresh', 15) ?> コスト時、IDを入れると<strong>そのプロジェクトのみ</strong>、空なら<strong>組織全体の合計</strong>を取得します。OpenAIのプロジェクト設定で確認できます。</div>
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
                    <label>識別子（プロジェクトID等・任意）</label>
                    <input name="openai_project_id" id="pf_proj" placeholder="OpenAI: proj_xxxxx（空＝組織全体）">
                    <div class="hint">コスト取得キーは<strong>プロダクトの既定キー</strong>を使います。この箱だけのID（OpenAIのプロジェクトID等）があれば入力。<a href="<?= h(app_url('manage')) ?>#credpanel">キーを管理</a></div>
                </div>
                <div class="field"><label>月額（手入力・任意）</label><input name="monthly_cost" id="pf_cost" type="number" step="0.01" min="0" placeholder="自動取得しない場合"></div>
                <div class="field"><label>通貨</label><select name="currency" id="pf_currency"><?php foreach (['USD','JPY','EUR','GBP'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('projDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>

<!-- コスト取得キー（クレデンシャル）編集モーダル -->
<dialog id="credDialog">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_credential">
        <input type="hidden" name="credential_id" id="cf_id" value="">
        <div class="modal-head" id="credModalTitle">コスト取得キー</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field full"><label>キーの名前 <span style="color:#b42318">*</span></label><input name="name" id="cf_name" required placeholder="例: OpenAI 本番Admin"><div class="hint">箱やプロダクトの「使うキー」で表示される名前です。</div></div>
                <div class="field full"><label>種別</label>
                    <select name="cost_type" id="cf_cost_type" onchange="cfTypeChange()">
                        <option value="">なし</option>
                        <option value="openai">OpenAI（Admin キー）</option>
                        <option value="anthropic">Anthropic / Claude（Admin）</option>
                        <option value="twilio">Twilio</option>
                        <option value="dataforseo">DataForSEO</option>
                        <option value="vonage">Vonage</option>
                        <option value="serpapi">SerpApi</option>
                        <option value="gcp_bq">Google Cloud（BigQuery）</option>
                    </select>
                    <div class="hint">各種別で必要な項目・取得場所は <a href="<?= h(app_url('guide')) ?>" target="_blank">キーの取得ガイド</a> を参照。</div>
                </div>
                <div class="field full" id="cf_acct_wrap" style="display:none"><label id="cf_acct_label">アカウントID</label><input name="cost_account" id="cf_acct" placeholder="Twilio: Account SID（ACxxxx）"></div>
                <div class="field full" id="cf_proj_wrap"><label>OpenAI プロジェクトID（既定・任意）</label><input name="openai_project_id" id="cf_proj" placeholder="proj_xxxxx（空＝組織全体）"><div class="hint">箱側で個別指定があればそちらが優先されます。</div></div>
                <div class="field full" id="cf_secret_wrap"><label><?= icon('lock', 15) ?> キー / トークン（暗号化保存）</label><input type="password" name="secret" id="cf_secret" autocomplete="new-password" placeholder="OpenAI: sk-admin-... / Twilio: Auth Token"><div class="hint" id="cf_secret_state"></div></div>
                <div class="field full" id="cf_json_wrap" style="display:none"><label><?= icon('lock', 15) ?> サービスアカウントJSON（暗号化保存）</label><textarea name="secret_json" id="cf_secret_json" rows="4" placeholder='{"type":"service_account", ... } を貼り付け'></textarea><div class="hint">BigQuery 閲覧＋ジョブ実行権限のあるサービスアカウントのJSONキー。</div></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('credDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>

<!-- サイトを箱に手動追加 -->
<dialog id="siteDialog">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="add_site">
        <input type="hidden" name="project_id" id="sf_pid" value="">
        <div class="modal-head">サイトを追加</div>
        <div class="modal-body">
            <div class="hint" id="sf_box" style="margin-bottom:8px"></div>
            <div class="field"><label>サイト名 <span style="color:#b42318">*</span></label>
                <input name="site" id="sf_site" required placeholder="例: shopA / shopA/app">
                <div class="hint">この箱に手動でサイト（URL）を1件追加します。スラッシュを含めると先頭がサイト名になります。</div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('siteDialog').close()">キャンセル</button>
            <button type="submit" class="primary">追加</button>
        </div>
    </form>
</dialog>
<script>
    const projDialog = document.getElementById('projDialog');
    const credDialog = document.getElementById('credDialog');
    const siteDialog = document.getElementById('siteDialog');
    function openSite(pid, boxName) {
        document.getElementById('sf_pid').value = pid;
        document.getElementById('sf_site').value = '';
        document.getElementById('sf_box').textContent = '箱: ' + (boxName || '');
        siteDialog.showModal();
    }
    function openProject(p) {
        p = p || {};
        document.getElementById('projModalTitle').textContent = p.id ? 'プロジェクト箱を編集' : 'プロジェクト箱を追加';
        document.getElementById('pf_id').value = p.id ?? '';
        document.getElementById('pf_name').value = p.name ?? '';
        document.getElementById('pf_product').value = p.product ?? '';
        document.getElementById('pf_proj').value = p.openai_project_id ?? '';
        document.getElementById('pf_cost').value = (p.monthly_cost === null || p.monthly_cost === undefined) ? '' : p.monthly_cost;
        document.getElementById('pf_currency').value = p.currency || 'USD';
        projDialog.showModal();
    }
    function cfTypeChange() {
        const t = document.getElementById('cf_cost_type').value;
        const acctTypes = { twilio:'Twilio Account SID', vonage:'Vonage API Key', dataforseo:'DataForSEO ログイン（メール）', gcp_bq:'BigQuery テーブル（project.dataset.table）' };
        document.getElementById('cf_proj_wrap').style.display = (t === 'openai' || t === '') ? '' : 'none';
        document.getElementById('cf_acct_wrap').style.display = acctTypes[t] ? '' : 'none';
        if (acctTypes[t]) { document.getElementById('cf_acct_label').textContent = acctTypes[t]; }
        const gcp = (t === 'gcp_bq');
        document.getElementById('cf_secret_wrap').style.display = gcp ? 'none' : '';
        document.getElementById('cf_json_wrap').style.display = gcp ? '' : 'none';
        const ph = { openai:'sk-admin-...', anthropic:'sk-ant-admin...', twilio:'Twilio Auth Token', vonage:'Vonage API Secret', dataforseo:'DataForSEO APIパスワード', serpapi:'SerpApi API Key' };
        document.getElementById('cf_secret').placeholder = ph[t] || 'キー / トークン';
    }
    function openCred(c) {
        c = c || {};
        document.getElementById('credModalTitle').textContent = c.id ? 'キーを編集' : 'キーを追加';
        document.getElementById('cf_id').value = c.id ?? '';
        document.getElementById('cf_name').value = c.name ?? '';
        document.getElementById('cf_cost_type').value = c.cost_type ?? '';
        document.getElementById('cf_acct').value = c.cost_account ?? '';
        document.getElementById('cf_proj').value = c.openai_project_id ?? '';
        document.getElementById('cf_secret').value = '';
        document.getElementById('cf_secret_json').value = '';
        document.getElementById('cf_secret_state').textContent = c.secret_hint ? ('現在: ' + c.secret_hint + '（変更時のみ入力）') : 'キー未保存';
        cfTypeChange();
        credDialog.showModal();
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
            a.secret_hint ? ('現在: ' + a.secret_hint + '（変更する時だけ入力）') : 'キー未保存';
        dialog.showModal();
    }
    // トースト＋コピー＋with_secrets（共用）
    function abtToast(msg) {
        const t = document.getElementById('abtToast'); if (!t) return;
        t.textContent = msg; t.classList.add('show');
        clearTimeout(t._tid); t._tid = setTimeout(() => t.classList.remove('show'), 1500);
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
</script>
    <?php
}
?>
<?php
/* ================================================================== *
 *  アカウント管理ページ（route=accounts）：ID・パスワード
 * ================================================================== */
if ($route === 'accounts'):
    if (!$editable) { header('Location: ' . app_url()); exit; }
    $accounts = list_accounts($gid);
    $acctCats = account_categories($gid);
    // カテゴリーごとにまとめる（空は「その他」）
    $byCat = [];
    foreach ($accounts as $a) { $byCat[$a['category'] !== '' ? $a['category'] : 'その他'][] = $a; }
    ksort($byCat);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — アカウント管理</title>
<?php render_styles(); ?>
</head>
<body>
<div class="layout">
<?php render_sidebar('accounts'); ?>
<main class="main">
    <div class="topbar"><h2><?= icon('lock') ?> アカウント管理（ID・パスワード）</h2>
        <span class="grow"></span>
        <button type="button" class="primary" onclick="openAccount({})"><?= icon('plus', 15) ?> アカウントを追加</button>
    </div>
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>
    <?php if (!encryption_ready()): ?><div class="flash" style="background:#fff4e0;color:#92400e">🔐 パスワードを保存するには <code>config.local.php</code> に <code>APP_ENCRYPTION_KEY</code> を設定してください。</div><?php endif; ?>
    <p class="hint" style="margin:0 0 14px">サービスのログインID・パスワードを暗号化して保管します。パスワードは「表示」を押した時だけ見えます。社内アカウントのみ閲覧可。</p>
    <div class="steam-hr"></div>

    <?php if (!$accounts): ?>
        <div class="empty">まだ登録がありません。「アカウントを追加」から登録してください。</div>
    <?php else: ?>
        <!-- 暖簾タブ：カテゴリー切り替え -->
        <div class="noren-tabs">
            <button type="button" class="noren-tab active" data-cat="__all" onclick="acctTab('__all', this)">すべて<span class="nt-n"><?= count($accounts) ?></span></button>
            <?php foreach ($byCat as $cat => $rows): ?>
                <button type="button" class="noren-tab" data-cat="<?= h($cat) ?>" onclick="acctTab(<?= htmlspecialchars(json_encode($cat, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, this)"><?= h($cat) ?><span class="nt-n"><?= count($rows) ?></span></button>
            <?php endforeach; ?>
        </div>
    <?php foreach ($byCat as $cat => $rows): ?>
        <div class="panel acct-cat" data-cat="<?= h($cat) ?>" style="margin-bottom:16px">
            <div class="noren-head"><span class="noren-cloth"><?= h($cat) ?></span><span class="muted" style="font-weight:400;font-size:13px">（<?= count($rows) ?>）</span></div>
            <table>
                <thead><tr><th>サービス</th><th>ログインID</th><th>パスワード</th><th class="hide-sm">メモ</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $a): ?>
                    <tr>
                        <td><span style="display:inline-flex;align-items:center;gap:8px"><?= provider_badge($a['service'], 26, $a['logo_color'] ?? null, $a['logo_url'] ?? null) ?> <span><strong><?= h($a['service']) ?></strong><?php if ($a['url'] !== ''): ?><br><a href="<?= h($a['url']) ?>" target="_blank" rel="noopener" class="product-link" style="font-size:12px"><?= icon('right', 12) ?> ログイン</a><?php endif; ?></span></span></td>
                        <td><?php if ($a['login_id'] !== ''): ?><code><?= h($a['login_id']) ?></code> <button class="link" type="button" title="コピー" onclick="copyText(<?= htmlspecialchars(json_encode($a['login_id'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= icon('copy', 14) ?></button><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td style="white-space:nowrap">
                            <?php if ($a['secret_hint']): ?>
                                <code class="pwmask" id="pw<?= (int) $a['id'] ?>">••••••••</code>
                                <button class="link" type="button" onclick="revealPw(<?= (int) $a['id'] ?>)" title="表示/隠す"><?= icon('search', 14) ?></button>
                                <button class="link" type="button" onclick="copyPw(<?= (int) $a['id'] ?>)" title="コピー"><?= icon('copy', 14) ?></button>
                            <?php else: ?><span class="muted">未設定</span><?php endif; ?>
                        </td>
                        <td class="hide-sm note-cell"><?= nl2br(h($a['notes'])) ?: '<span class="muted">—</span>' ?></td>
                        <td style="white-space:nowrap">
                            <button class="link" type="button" onclick='openAccount(<?= json_encode(["id"=>(int)$a["id"],"category"=>$a["category"],"service"=>$a["service"],"login_id"=>$a["login_id"],"url"=>$a["url"],"notes"=>$a["notes"],"secret_hint"=>$a["secret_hint"],"logo_color"=>$a["logo_color"],"logo_url"=>$a["logo_url"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                            <?php if (can_manage()): ?><form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '「<?= h($a['service']) ?>」を削除しますか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_account"><input type="hidden" name="account_id" value="<?= (int) $a['id'] ?>"><button class="link danger" type="submit"><?= icon('trash', 14) ?></button></form><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; endif; ?>

    <a class="btn" href="index.php"><?= icon('left', 15) ?> ダッシュボードに戻る</a>
</main>
</div>
<div id="abtToast"></div>
<dialog id="accountDialog">
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_account">
        <input type="hidden" name="account_id" id="af_id" value="">
        <div class="modal-head" id="accountModalTitle">アカウント</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field"><label>カテゴリー</label><input name="category" id="af_category" list="acctCatList" placeholder="例: API / Google / サーバ"><datalist id="acctCatList"><?php foreach (['API','Google','サーバ・インフラ','ドメイン','SaaS','SNS','その他'] as $c): ?><option value="<?= h($c) ?>"></option><?php endforeach; foreach ($acctCats as $c): ?><option value="<?= h($c) ?>"></option><?php endforeach; ?></datalist></div>
                <div class="field"><label>サービス名 <span style="color:#b42318">*</span></label><input name="service" id="af_service" required placeholder="例: OpenAI Console"></div>
                <div class="field"><label>ログインID（メール等）</label><input name="login_id" id="af_login" placeholder="例: ops@example.com"></div>
                <div class="field"><label>ログインURL</label><input name="url" id="af_url" type="url" placeholder="https://..."></div>
                <div class="field full"><label><?= icon('lock', 15) ?> パスワード（暗号化保存）</label><input type="text" name="password" id="af_password" autocomplete="off" placeholder="空欄なら現状維持"><div class="hint" id="af_pw_state"></div></div>
                <div class="field full"><label>メモ（2段階認証の連絡先など）</label><textarea name="notes" id="af_notes" rows="2"></textarea></div>
                <div class="field"><label>アイコン背景色</label><input type="color" name="logo_color" id="af_logo_color" value="#ffffff" style="width:64px;height:40px;padding:2px"></div>
                <div class="field"><label>アイコン画像をアップロード</label><input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp"></div>
                <div class="field full"><label>または画像URL</label><input type="url" name="logo_url" id="af_logo_url" placeholder="https://.../icon.png"><div class="hint">画像が背景色より優先。空のURL＋ファイル無しで画像を解除（PNG/JPEG/GIF/WebP・2MBまで）。</div></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('accountDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>
<script>
    const accountDialog = document.getElementById('accountDialog');
    const ACC_CSRF = '<?= h($csrf) ?>';
    const pwShown = {};
    function acctTab(cat, btn) {
        document.querySelectorAll('.noren-tab').forEach(t => t.classList.remove('active'));
        if (btn) btn.classList.add('active');
        document.querySelectorAll('.acct-cat').forEach(p => {
            p.style.display = (cat === '__all' || p.getAttribute('data-cat') === cat) ? '' : 'none';
        });
    }
    function openAccount(a) {
        a = a || {};
        document.getElementById('accountModalTitle').textContent = a.id ? 'アカウントを編集' : 'アカウントを追加';
        document.getElementById('af_id').value = a.id ?? '';
        document.getElementById('af_category').value = a.category ?? '';
        document.getElementById('af_service').value = a.service ?? '';
        document.getElementById('af_login').value = a.login_id ?? '';
        document.getElementById('af_url').value = a.url ?? '';
        document.getElementById('af_notes').value = a.notes ?? '';
        document.getElementById('af_password').value = '';
        document.getElementById('af_pw_state').textContent = a.secret_hint ? ('現在: ' + a.secret_hint + '（変更時のみ入力）') : 'パスワード未設定';
        document.getElementById('af_logo_color').value = a.logo_color || '#ffffff';
        document.getElementById('af_logo_url').value = (a.logo_url || '').replace(/\?v=\d+$/, '');
        const lf = accountDialog.querySelector('input[name=logo_file]'); if (lf) { lf.value = ''; }
        accountDialog.showModal();
    }
    function fetchPw(id) {
        const b = new URLSearchParams();
        b.append('csrf', ACC_CSRF); b.append('action', 'reveal_account'); b.append('account_id', id);
        return fetch('index.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:b }).then(r => r.json());
    }
    function revealPw(id) {
        const el = document.getElementById('pw' + id);
        if (pwShown[id]) { el.textContent = '••••••••'; pwShown[id] = false; return; }
        fetchPw(id).then(d => { el.textContent = (d && d.password != null) ? d.password : '(取得失敗)'; pwShown[id] = true; }).catch(() => { el.textContent = '(取得失敗)'; });
    }
    function copyPw(id) {
        fetchPw(id).then(d => { if (d && d.password != null) copyText(d.password); else abtToast('取得できませんでした'); }).catch(() => abtToast('取得できませんでした'));
    }
    function copyText(t) {
        if (navigator.clipboard) { navigator.clipboard.writeText(t).then(() => abtToast('コピーしました')).catch(() => abtToast('コピーできませんでした')); }
        else { const ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');abtToast('コピーしました');}catch(e){} ta.remove(); }
    }
    function abtToast(msg){ const t=document.getElementById('abtToast'); if(!t)return; t.textContent=msg; t.classList.add('show'); clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('show'),1500); }
</script>
</body></html>
<?php exit; endif; ?>
<?php
/* ================================================================== *
 *  個人アカウントページ（route=myaccounts）：本人だけが見られるID・パスワード
 * ================================================================== */
if ($route === 'myaccounts'):
    $me = (string) ($user['email'] ?? '');
    $accounts = list_my_accounts($me);
    $acctCats = my_account_categories($me);
    // カテゴリーごとにまとめる（空は「その他」）
    $byCat = [];
    foreach ($accounts as $a) { $byCat[$a['category'] !== '' ? $a['category'] : 'その他'][] = $a; }
    ksort($byCat);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — 個人アカウント</title>
<?php render_styles(); ?>
</head>
<body>
<div class="layout">
<?php render_sidebar('myaccounts'); ?>
<main class="main">
    <div class="topbar"><h2><?= icon('key') ?> 個人アカウント（自分専用）</h2>
        <span class="grow"></span>
        <button type="button" class="primary" onclick="openAccount({})"><?= icon('plus', 15) ?> アカウントを追加</button>
    </div>
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>
    <?php if (!encryption_ready()): ?><div class="flash" style="background:#fff4e0;color:#92400e">🔐 パスワードを保存するには <code>config.local.php</code> に <code>APP_ENCRYPTION_KEY</code> を設定してください。</div><?php endif; ?>
    <p class="hint" style="margin:0 0 14px">あなた個人で作ったアカウントを暗号化して保管します。<strong>ここに登録したものは本人（<?= h($me) ?>）だけが閲覧でき、他のメンバーや管理者には表示されません。</strong>グループを切り替えても表示されます。</p>
    <div class="steam-hr"></div>

    <?php if (!$accounts): ?>
        <div class="empty">まだ登録がありません。「アカウントを追加」から、あなた専用のID・パスワードを登録できます。</div>
    <?php else: ?>
        <!-- タブ：カテゴリー切り替え -->
        <div class="noren-tabs">
            <button type="button" class="noren-tab active" data-cat="__all" onclick="acctTab('__all', this)">すべて<span class="nt-n"><?= count($accounts) ?></span></button>
            <?php foreach ($byCat as $cat => $rows): ?>
                <button type="button" class="noren-tab" data-cat="<?= h($cat) ?>" onclick="acctTab(<?= htmlspecialchars(json_encode($cat, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, this)"><?= h($cat) ?><span class="nt-n"><?= count($rows) ?></span></button>
            <?php endforeach; ?>
        </div>
    <?php foreach ($byCat as $cat => $rows): ?>
        <div class="panel acct-cat" data-cat="<?= h($cat) ?>" style="margin-bottom:16px">
            <div class="noren-head"><span class="noren-cloth"><?= h($cat) ?></span><span class="muted" style="font-weight:400;font-size:13px">（<?= count($rows) ?>）</span></div>
            <table>
                <thead><tr><th>サービス</th><th>ログインID</th><th>パスワード</th><th class="hide-sm">メモ</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $a): ?>
                    <tr>
                        <td><span style="display:inline-flex;align-items:center;gap:8px"><?= provider_badge($a['service'], 26, $a['logo_color'] ?? null, $a['logo_url'] ?? null) ?> <span><strong><?= h($a['service']) ?></strong><?php if ($a['url'] !== ''): ?><br><a href="<?= h($a['url']) ?>" target="_blank" rel="noopener" class="product-link" style="font-size:12px"><?= icon('right', 12) ?> ログイン</a><?php endif; ?></span></span></td>
                        <td><?php if ($a['login_id'] !== ''): ?><code><?= h($a['login_id']) ?></code> <button class="link" type="button" title="コピー" onclick="copyText(<?= htmlspecialchars(json_encode($a['login_id'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= icon('copy', 14) ?></button><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td style="white-space:nowrap">
                            <?php if ($a['secret_hint']): ?>
                                <code class="pwmask" id="pw<?= (int) $a['id'] ?>">••••••••</code>
                                <button class="link" type="button" onclick="revealPw(<?= (int) $a['id'] ?>)" title="表示/隠す"><?= icon('search', 14) ?></button>
                                <button class="link" type="button" onclick="copyPw(<?= (int) $a['id'] ?>)" title="コピー"><?= icon('copy', 14) ?></button>
                            <?php else: ?><span class="muted">未設定</span><?php endif; ?>
                        </td>
                        <td class="hide-sm note-cell"><?= nl2br(h($a['notes'])) ?: '<span class="muted">—</span>' ?></td>
                        <td style="white-space:nowrap">
                            <button class="link" type="button" onclick='openAccount(<?= json_encode(["id"=>(int)$a["id"],"category"=>$a["category"],"service"=>$a["service"],"login_id"=>$a["login_id"],"url"=>$a["url"],"notes"=>$a["notes"],"secret_hint"=>$a["secret_hint"],"logo_color"=>$a["logo_color"],"logo_url"=>$a["logo_url"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                            <form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '「<?= h($a['service']) ?>」を削除しますか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_my_account"><input type="hidden" name="account_id" value="<?= (int) $a['id'] ?>"><button class="link danger" type="submit"><?= icon('trash', 14) ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; endif; ?>

    <a class="btn" href="index.php"><?= icon('left', 15) ?> ダッシュボードに戻る</a>
</main>
</div>
<div id="abtToast"></div>
<dialog id="accountDialog">
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_my_account">
        <input type="hidden" name="account_id" id="af_id" value="">
        <div class="modal-head" id="accountModalTitle">個人アカウント</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field"><label>カテゴリー</label><input name="category" id="af_category" list="acctCatList" placeholder="例: API / Google / サーバ"><datalist id="acctCatList"><?php foreach (['API','Google','サーバ・インフラ','ドメイン','SaaS','SNS','その他'] as $c): ?><option value="<?= h($c) ?>"></option><?php endforeach; foreach ($acctCats as $c): ?><option value="<?= h($c) ?>"></option><?php endforeach; ?></datalist></div>
                <div class="field"><label>サービス名 <span style="color:#b42318">*</span></label><input name="service" id="af_service" required placeholder="例: OpenAI Console"></div>
                <div class="field"><label>ログインID（メール等）</label><input name="login_id" id="af_login" placeholder="例: me@example.com"></div>
                <div class="field"><label>ログインURL</label><input name="url" id="af_url" type="url" placeholder="https://..."></div>
                <div class="field full"><label><?= icon('lock', 15) ?> パスワード（暗号化保存）</label><input type="text" name="password" id="af_password" autocomplete="off" placeholder="空欄なら現状維持"><div class="hint" id="af_pw_state"></div></div>
                <div class="field full"><label>メモ（2段階認証の連絡先など）</label><textarea name="notes" id="af_notes" rows="2"></textarea></div>
                <div class="field"><label>アイコン背景色</label><input type="color" name="logo_color" id="af_logo_color" value="#ffffff" style="width:64px;height:40px;padding:2px"></div>
                <div class="field"><label>アイコン画像をアップロード</label><input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp"></div>
                <div class="field full"><label>または画像URL</label><input type="url" name="logo_url" id="af_logo_url" placeholder="https://.../icon.png"><div class="hint">画像が背景色より優先。空のURL＋ファイル無しで画像を解除（PNG/JPEG/GIF/WebP・2MBまで）。</div></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('accountDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>
<script>
    const accountDialog = document.getElementById('accountDialog');
    const ACC_CSRF = '<?= h($csrf) ?>';
    const pwShown = {};
    function acctTab(cat, btn) {
        document.querySelectorAll('.noren-tab').forEach(t => t.classList.remove('active'));
        if (btn) btn.classList.add('active');
        document.querySelectorAll('.acct-cat').forEach(p => {
            p.style.display = (cat === '__all' || p.getAttribute('data-cat') === cat) ? '' : 'none';
        });
    }
    function openAccount(a) {
        a = a || {};
        document.getElementById('accountModalTitle').textContent = a.id ? '個人アカウントを編集' : '個人アカウントを追加';
        document.getElementById('af_id').value = a.id ?? '';
        document.getElementById('af_category').value = a.category ?? '';
        document.getElementById('af_service').value = a.service ?? '';
        document.getElementById('af_login').value = a.login_id ?? '';
        document.getElementById('af_url').value = a.url ?? '';
        document.getElementById('af_notes').value = a.notes ?? '';
        document.getElementById('af_password').value = '';
        document.getElementById('af_pw_state').textContent = a.secret_hint ? ('現在: ' + a.secret_hint + '（変更時のみ入力）') : 'パスワード未設定';
        document.getElementById('af_logo_color').value = a.logo_color || '#ffffff';
        document.getElementById('af_logo_url').value = (a.logo_url || '').replace(/\?v=\d+$/, '');
        const lf = accountDialog.querySelector('input[name=logo_file]'); if (lf) { lf.value = ''; }
        accountDialog.showModal();
    }
    function fetchPw(id) {
        const b = new URLSearchParams();
        b.append('csrf', ACC_CSRF); b.append('action', 'reveal_my_account'); b.append('account_id', id);
        return fetch('index.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:b }).then(r => r.json());
    }
    function revealPw(id) {
        const el = document.getElementById('pw' + id);
        if (pwShown[id]) { el.textContent = '••••••••'; pwShown[id] = false; return; }
        fetchPw(id).then(d => { el.textContent = (d && d.password != null) ? d.password : '(取得失敗)'; pwShown[id] = true; }).catch(() => { el.textContent = '(取得失敗)'; });
    }
    function copyPw(id) {
        fetchPw(id).then(d => { if (d && d.password != null) copyText(d.password); else abtToast('取得できませんでした'); }).catch(() => abtToast('取得できませんでした'));
    }
    function copyText(t) {
        if (navigator.clipboard) { navigator.clipboard.writeText(t).then(() => abtToast('コピーしました')).catch(() => abtToast('コピーできませんでした')); }
        else { const ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');abtToast('コピーしました');}catch(e){} ta.remove(); }
    }
    function abtToast(msg){ const t=document.getElementById('abtToast'); if(!t)return; t.textContent=msg; t.classList.add('show'); clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('show'),1500); }
</script>
</body></html>
<?php exit; endif; ?>
<?php
/* ================================================================== *
 *  ガイドページ（route=guide）：各キーの「必要なもの・取得場所」
 * ================================================================== */
if ($route === 'guide'):
    $guides = list_guides($gid);
    $canEditGuide = can_manage();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — キーの取得ガイド</title>
<?php render_styles(); ?>
</head>
<body>
<div class="layout">
<?php render_sidebar('guide'); ?>
<main class="main">
    <div class="topbar"><h2><?= icon('help') ?> キーの取得ガイド</h2>
        <span class="grow"></span>
        <?php if ($canEditGuide): ?><button class="btn" type="button" onclick="openGuide({})"><?= icon('plus', 15) ?> 項目を追加</button><?php endif; ?>
    </div>
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>
    <p class="hint" style="margin:0 0 14px">各コスト取得キーを「管理 → コスト取得キー → キーを追加」で登録するときに、ここを見れば必要な項目と取得場所がわかります。</p>

    <div class="guide-grid">
        <?php foreach ($guides as $g): ?>
            <div class="panel guide-card">
                <h3 style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                    <?= icon('key', 16) ?> <?= h($g['title']) ?>
                    <?php if ($g['edited']): ?><span class="pill" style="background:#fff4e0;color:#92400e">編集済</span><?php endif; ?>
                </h3>
                <div class="guide-row"><span class="guide-label">必要なもの</span><span><?= nl2br(h($g['needs'])) ?: '<span class="muted">—</span>' ?></span></div>
                <div class="guide-row"><span class="guide-label">取得場所</span><span><?= nl2br(h($g['source'])) ?: '<span class="muted">—</span>' ?></span></div>
                <?php if (trim((string) $g['url']) !== ''): ?>
                    <div class="guide-row"><span class="guide-label">リンク</span><a href="<?= h($g['url']) ?>" target="_blank" rel="noopener" class="product-link"><?= h($g['url']) ?> ↗</a></div>
                <?php endif; ?>
                <?php if ($canEditGuide): ?>
                <div style="margin-top:10px;display:flex;gap:6px">
                    <button class="link" type="button" onclick='openGuide(<?= json_encode(["ckey"=>$g["ckey"],"title"=>$g["title"],"needs"=>$g["needs"],"source"=>$g["source"],"url"=>$g["url"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                    <?php if ($g['edited']): ?>
                    <form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '「<?= h($g['title']) ?>」を<?= $g['custom'] ? '削除' : '既定に戻' ?>しますか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_guide"><input type="hidden" name="ckey" value="<?= h($g['ckey']) ?>"><button class="link danger" type="submit"><?= $g['custom'] ? '削除' : '既定に戻す' ?></button></form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <a class="btn" href="index.php" style="margin-top:16px"><?= icon('left', 15) ?> ダッシュボードに戻る</a>
</main>
</div>
<?php if ($canEditGuide): ?>
<dialog id="guideDialog">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_guide">
        <input type="hidden" name="ckey" id="gf_ckey" value="">
        <div class="modal-head" id="guideModalTitle">ガイドを編集</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field full"><label>タイトル <span style="color:#b42318">*</span></label><input name="title" id="gf_title" required placeholder="例: OpenAI（自動取得◯）"></div>
                <div class="field full"><label>必要なもの</label><textarea name="needs" id="gf_needs" rows="3" placeholder="保存時に入れる項目（種別 / アカウントID欄 / キー欄 など）"></textarea></div>
                <div class="field full"><label>取得場所</label><textarea name="source" id="gf_source" rows="3" placeholder="どの画面で取得できるか"></textarea></div>
                <div class="field full"><label>リンク（URL）</label><input name="url" id="gf_url" type="url" placeholder="https://..."></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('guideDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>
<script>
    const guideDialog = document.getElementById('guideDialog');
    function openGuide(g) {
        g = g || {};
        document.getElementById('guideModalTitle').textContent = g.ckey ? 'ガイドを編集' : '項目を追加';
        document.getElementById('gf_ckey').value = g.ckey ?? '';
        document.getElementById('gf_title').value = g.title ?? '';
        document.getElementById('gf_needs').value = g.needs ?? '';
        document.getElementById('gf_source').value = g.source ?? '';
        document.getElementById('gf_url').value = g.url ?? '';
        guideDialog.showModal();
    }
</script>
<?php endif; ?>
</body></html>
<?php exit; endif; ?>
<?php
/* ================================================================== *
 *  管理ページ（route=manage）：コスト取得キー＋プロジェクト箱の管理
 * ================================================================== */
if ($route === 'manage'):
    if (!$editable) { header('Location: ' . app_url()); exit; }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — 管理</title>
<?php render_styles(); ?>
</head>
<body>
<div class="layout">
<?php render_sidebar('manage'); ?>
<main class="main">
    <div class="topbar"><h2><?= icon('gear') ?> 管理</h2></div>
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= nl2br(h($flashMsg[1])) ?></div><?php endif; ?>

    <!-- コスト取得キーの管理 -->
    <div class="panel" id="credpanel" style="margin-bottom:18px">
        <h3 style="display:flex;align-items:center;gap:8px"><?= icon('key', 16) ?> コスト取得キーの管理（<?= count($credentials) ?>）
            <span class="grow" style="flex:1"></span>
            <button class="btn" type="button" onclick="openCred({})"><?= icon('plus', 15) ?> キーを追加</button></h3>
        <p class="hint" style="margin:0 0 8px">OpenAI Adminキーや Twilio トークンを名前を付けて登録。プロダクトや箱の「使うキー」で選んで使い回せます。</p>
        <?php if ($credentials): ?>
        <table>
            <thead><tr><th>名前</th><th>種別</th><th>既定 proj/SID</th><th>キー</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($credentials as $cr): ?>
                <tr>
                    <td><?= icon('key', 15) ?> <strong><?= h($cr['name']) ?></strong></td>
                    <td class="muted"><?= $cr['cost_type'] !== '' ? h(strtoupper($cr['cost_type'])) : '—' ?></td>
                    <td class="muted"><?= h($cr['openai_project_id'] ?: $cr['cost_account'] ?: '—') ?></td>
                    <td class="muted"><?= $cr['secret_hint'] ? h($cr['secret_hint']) : '<span style="color:#b42318">未保存</span>' ?></td>
                    <td style="white-space:nowrap">
                        <button class="link" type="button" onclick='openCred(<?= json_encode(["id"=>(int)$cr["id"],"name"=>$cr["name"],"cost_type"=>$cr["cost_type"],"cost_account"=>$cr["cost_account"],"openai_project_id"=>$cr["openai_project_id"],"secret_hint"=>$cr["secret_hint"]], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                        <?php if (can_manage()): ?><form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, 'キー「<?= h($cr['name']) ?>」を削除しますか？（このキーを使っている箱/プロダクトは未選択に戻ります）')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_credential"><input type="hidden" name="credential_id" value="<?= (int) $cr['id'] ?>"><button class="link danger" type="submit">削除</button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><p class="hint">まだ登録されていません。</p><?php endif; ?>
        <?php if (!encryption_ready()): ?><p class="hint" style="color:#92400e">⚠ APP_ENCRYPTION_KEY が未設定のため、キーの値は保存できません（config.local.php に設定してください）。</p><?php endif; ?>
    </div>

    <!-- プロジェクト箱の一覧・管理 -->
    <div class="panel" style="margin-bottom:18px">
        <h3 style="display:flex;align-items:center;gap:8px"><?= icon('box', 16) ?> プロジェクト箱の一覧・管理（<?= count($projects) ?>）
            <span class="grow" style="flex:1"></span>
            <form method="post" style="display:inline;margin-right:6px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="snapshot_all"><button class="btn" type="submit" title="全箱の現在の月額を今月の履歴に記録"><?= icon('refresh', 15) ?> 今月を記録</button></form>
            <button class="btn" type="button" onclick="openProject({})"><?= icon('plus', 15) ?> 箱を追加</button></h3>
        <?php if ($projects): ?>
        <table>
            <thead><tr><th>箱</th><th>プロダクト</th><th>OpenAI proj</th><th>月額</th><th>URL数</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($projects as $p): $cnt = $boxUrlCount[(int) $p['id']] ?? 0; ?>
                <tr>
                    <td><?= icon('box', 15) ?> <strong><?= h($p['name']) ?></strong></td>
                    <td class="muted"><?= $p['product'] !== '' ? h($p['product']) : '—' ?></td>
                    <td class="muted"><?= $p['openai_project_id'] !== '' ? h($p['openai_project_id']) : '—' ?></td>
                    <td class="cost"><?= fmt_money($p['monthly_cost'] === null ? null : (float) $p['monthly_cost'], $p['currency'] ?: 'USD') ?><?php if (($p['balance'] ?? null) !== null): ?><div class="hint">残高 <?= h($p['currency'] ?: 'USD') ?> <?= number_format((float) $p['balance'], 2) ?></div><?php endif; ?></td>
                    <td><?= $cnt ?> <span class="muted">URL</span></td>
                    <td style="white-space:nowrap">
                        <?php if (($p['cost_type'] ?? '') !== '' || trim((string) $p['openai_project_id']) !== '' || ($p['credential_id'] ?? null) || isset($prodCredSet[(string) ($p['product'] ?? '')])): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="fetch_project_cost"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>"><button class="link" type="submit"><?= icon('refresh', 15) ?> コスト</button></form>
                        <?php endif; ?>
                        <button class="link" type="button" onclick='openSite(<?= (int)$p["id"] ?>, <?= json_encode($p["name"], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>＋サイト</button>
                        <button class="link" type="button" onclick='openProject(<?= json_encode(["id"=>(int)$p["id"],"name"=>$p["name"],"product"=>$p["product"] ?? "","cost_type"=>$p["cost_type"] ?? "","cost_account"=>$p["cost_account"] ?? "","openai_project_id"=>$p["openai_project_id"],"secret_hint"=>$p["secret_hint"],"monthly_cost"=>$p["monthly_cost"],"currency"=>$p["currency"],"credential_id"=>$p["credential_id"] ?? null], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                        <?php if (can_manage()): ?><form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '箱「<?= h($p['name']) ?>」を削除しますか？（URLは未割当へ）')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>"><button class="link danger" type="submit">削除</button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><p class="hint">まだ箱がありません。</p><?php endif; ?>
    </div>

    <a class="btn" href="index.php"><?= icon('left', 15) ?> ダッシュボードに戻る</a>
</main>
</div>
<div id="abtToast"></div>
<?php render_modals($csrf, $names, $credentials); ?>
</body></html>
<?php exit; endif; ?>
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
    $pCredId     = product_credential_id($gid, $pname);   // このプロダクトの既定キー

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
    // ドーナツ：箱別コスト（JPY換算・降順。USD/JPY混在でも一緒に表示）
    $bsegs = [];
    foreach ($pboxes as $b) {
        if ($b['monthly_cost'] !== null && (float) $b['monthly_cost'] > 0) { $bsegs[] = ['label' => $b['name'], 'value' => to_jpy((float) $b['monthly_cost'], $b['currency'] ?: 'USD')]; }
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
<?php render_sidebar(''); ?>
<main class="main">
    <div class="crumb"><a href="index.php">ダッシュボード</a> ／ <?= h($pname) ?></div>
    <div class="topbar">
        <h2 style="display:inline-flex;align-items:center;gap:10px"><?php if ($editable): ?><button type="button" class="badge-btn" onclick="openLogo()" title="アイコンを変更"><?= provider_badge($pname, 36, ($prodMeta[$pname]['logo_color'] ?? null), ($prodMeta[$pname]['logo_url'] ?? null)) ?><span class="badge-pen"><?= icon('gear', 12) ?></span></button><?php else: ?><?= provider_badge($pname, 36, ($prodMeta[$pname]['logo_color'] ?? null), ($prodMeta[$pname]['logo_url'] ?? null)) ?><?php endif; ?> <?= h($pname) ?><?php if ($pprovider): ?> <span class="muted" style="font-size:14px">（<?= h($pprovider) ?>）</span><?php endif; ?></h2>
        <span class="grow"></span>
        <?php if ($editable): ?>
            <form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '「<?= h($pname) ?>」配下の箱のコストを今すぐ取得します。よろしいですか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="refresh_product"><input type="hidden" name="product" value="<?= h($pname) ?>"><button type="submit" class="btn"><?= icon('refresh', 15) ?> コスト更新</button></form>
            <button type="button" class="btn" onclick="openProject({product: <?= htmlspecialchars(json_encode($pname, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>})"><?= icon('plus', 15) ?> 箱を追加</button>
            <button type="button" class="primary" onclick="openCreate()"><?= icon('plus', 15) ?> API を追加</button>
            <?php if (can_manage()): ?>
            <form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, 'プロダクト「<?= h($pname) ?>」を丸ごと削除します。\n配下の箱・このプロダクトのAPI/URL登録・コスト履歴・追加クレジットも削除されます。\n（他プロダクトのキーを含むURLは未割当に戻ります）\n\n本当に削除しますか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="product" value="<?= h($pname) ?>"><button type="submit" class="btn" style="color:#b42318;border-color:#f0c4bd"><?= icon('trash', 15) ?> プロダクト削除</button></form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ヒーロー：合計＋箱別ドーナツ -->
    <div class="hero">
        <div class="hero-main">
            <div class="hero-label">月額コスト</div>
            <?php if ($pcost): foreach ($pcost as $cur => $sum): ?>
                <div class="hero-amount"><span class="cur"><?= h($cur) ?></span> <?= number_format($sum, (fmod($sum,1.0)===0.0)?0:2) ?><?= jpy_hint((float) $sum, $cur) ?></div>
            <?php endforeach; else: ?>
                <div class="hero-amount muted">未設定</div>
            <?php endif; ?>
            <?php
            // 残高（Twilio/DataForSEO 等）：このプロダクト配下の箱の残高を通貨ごとに合算
            $pbalByCur = [];
            foreach ($pboxes as $b) { if (($b['balance'] ?? null) !== null) { $bc = $b['currency'] ?: 'USD'; $pbalByCur[$bc] = ($pbalByCur[$bc] ?? 0) + (float) $b['balance']; } }
            ?>
            <?php if ($pbalByCur): ?>
                <div class="hero-balance"><span class="lbl">残高</span><?php $bi = 0; foreach ($pbalByCur as $bc => $bsum): ?><?php if ($bi++): ?><span class="sep">/</span><?php endif; ?><span class="cur"><?= h($bc) ?></span><?= number_format($bsum, 2) ?><?= jpy_hint((float) $bsum, $bc) ?><?php endforeach; ?></div>
            <?php endif; ?>
            <div class="hero-stats">
                <div><span class="n"><?= count($pboxes) ?></span><span class="l">プロジェクト箱</span></div>
                <div><span class="n"><?= count($siteCount) ?></span><span class="l">サイト</span></div>
                <div><span class="n"><?= $totalUrls ?></span><span class="l">URL</span></div>
            </div>
        </div>
        <div class="hero-chart">
            <div class="hero-label">プロジェクト箱別コスト（JPY換算）</div>
            <div class="donut-wrap">
                <?= svg_donut($bsegs, 168, 24) ?>
                <div class="legend">
                    <?php if ($bsegs): $dc = donut_colors(); $di = 0; foreach ($bsegs as $s): if ($di >= 6) { break; } ?>
                        <div class="leg">
                            <span class="dot" style="background:<?= h($dc[$di % count($dc)]) ?>"></span>
                            <span class="leg-name"><?= icon('box') ?> <?= h($s['label']) ?></span>
                            <span class="leg-pct"><?= $bTot > 0 ? round($s['value'] / $bTot * 100) : 0 ?>%</span>
                        </div>
                    <?php $di++; endforeach;
                        if (count($bsegs) > 6): ?><div class="leg muted">ほか <?= count($bsegs) - 6 ?> 件</div><?php endif; ?>
                    <?php else: ?><div class="muted" style="font-size:13px">箱のコスト未設定</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php
        // ===== コスト推移・前月比・しきい値 =====
        $dCur = ''; $dVal = 0.0;
        foreach ($pcost as $cur => $v) { if ($v > $dVal) { $dVal = $v; $dCur = $cur; } }
        if ($dCur === '') { $dCur = 'JPY'; }
        $months = []; for ($i = 5; $i >= 0; $i--) { $months[] = month_key(-$i); }
        $series = []; $smax = 0.0;
        foreach ($months as $ym) {
            $val = ($ym === $ymNow) ? (float) ($pcost[$dCur] ?? 0) : (float) ($snap[$pname][$ym][$dCur] ?? 0);
            $series[$ym] = $val; if ($val > $smax) { $smax = $val; }
        }
        $curV = (float) ($pcost[$dCur] ?? 0);
        $prevV = (float) ($snap[$pname][$ymPrev][$dCur] ?? 0);
        $dpct = ($prevV > 0) ? ($curV - $prevV) / $prevV * 100 : null;
        $alertV = (($prodMeta[$pname]['cost_alert'] ?? null) !== null) ? (float) $prodMeta[$pname]['cost_alert'] : null;
        $overV = ($alertV !== null && $curV > $alertV);
    ?>
    <div class="panel" style="margin-bottom:18px">
        <h3><?= icon('refresh', 16) ?> コスト推移（<?= h($dCur) ?>・月次）</h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:baseline;margin-bottom:10px">
            <div><span class="muted" style="font-size:12px">今月</span> <strong style="font-size:20px"><?= h($dCur) ?> <?= number_format($curV, (fmod($curV,1.0)===0.0)?0:2) ?></strong><?= jpy_hint($curV, $dCur) ?></div>
            <?php if ($dpct !== null): $up = $dpct >= 0; ?>
                <div class="<?= $up ? 'pcard-diff up' : 'pcard-diff down' ?>" style="font-size:14px"><?= $up ? '▲' : '▼' ?> <?= abs(round($dpct, 1)) ?>% <span class="muted">先月（<?= h($dCur) ?> <?= number_format($prevV) ?>）比</span></div>
            <?php else: ?><div class="muted" style="font-size:12px">前月データなし</div><?php endif; ?>
            <?php if ($overV): ?><span class="pcard-warn" style="position:static"><?= icon('refresh', 12) ?> しきい値超過</span><?php endif; ?>
        </div>
        <?php if ($smax > 0): foreach ($series as $ym => $val): ?>
            <div class="bar-row">
                <span class="nm" style="flex:0 0 64px"><?= h(substr($ym, 5)) ?>月</span>
                <span class="bar-track"><span class="bar-fill" style="width:<?= round($val / $smax * 100, 1) ?>%;background:<?= $ym === $ymNow ? 'var(--accent)' : '#9bbbe8' ?>"></span></span>
                <span class="v"><?= number_format($val, (fmod($val,1.0)===0.0)?0:2) ?></span>
            </div>
        <?php endforeach; else: ?>
            <p class="hint">まだ履歴がありません。コスト取得（⟳）や保存をすると当月のスナップショットが記録され、翌月から推移が見られます。</p>
        <?php endif; ?>
        <?php if ($editable && $pname !== '（プロダクト未指定）'): ?>
        <form method="post" class="toolbar" style="margin:12px 0 0;box-shadow:none;border:none;padding:0;background:none;align-items:flex-end">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save_product_alert">
            <input type="hidden" name="product" value="<?= h($pname) ?>">
            <label style="font-size:12px;color:var(--muted)">使いすぎ警告のしきい値（<?= h($dCur) ?>/月）<br><input type="number" name="cost_alert" min="0" step="1" value="<?= $alertV !== null ? (int) $alertV : '' ?>" placeholder="例: 50000" style="width:160px"></label>
            <button class="primary" type="submit">保存</button>
            <span class="hint">今月の合計がこの額を超えると「使いすぎ」表示。空欄で解除。</span>
        </form>
        <?php endif; ?>
    </div>

    <div class="detail-grid">
        <!-- 箱別の月額 -->
        <div class="panel">
            <h3>プロジェクト箱の月額（JPY換算）</h3>
            <?php if ($bsegs): $dc = donut_colors(); foreach ($bsegs as $i => $s): ?>
                <div class="bar-row">
                    <span class="nm"><?= icon('box') ?> <?= h($s['label']) ?></span>
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
                    <span class="nm"><?= icon('globe', 15) ?> <?= h($st) ?></span>
                    <span class="bar-track"><span class="bar-fill" style="width:<?= round($cnt / $siteMax * 100, 1) ?>%;background:<?= h($dc[$si % count($dc)]) ?>"></span></span>
                    <span class="v"><?= (int) $cnt ?></span>
                </div>
            <?php $si++; endforeach; else: ?>
                <div class="muted" style="font-size:13px">URLがありません。</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($editable && $pname !== '（プロダクト未指定）'): ?>
    <!-- このプロダクトの既定キー -->
    <div class="panel" style="margin-bottom:18px">
        <h3><?= icon('key', 16) ?> このプロダクトのコスト取得キー（既定）</h3>
        <form method="post" class="toolbar" style="margin:0;box-shadow:none;border:none;padding:0;background:none">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="set_product_credential">
            <input type="hidden" name="product" value="<?= h($pname) ?>">
            <select name="credential_id">
                <option value="">（未設定）</option>
                <?php foreach ($credentials as $cr): ?>
                    <option value="<?= (int) $cr['id'] ?>" <?= $pCredId === (int) $cr['id'] ? 'selected' : '' ?>><?= h($cr['name']) ?>（<?= h($cr['cost_type'] !== '' ? strtoupper($cr['cost_type']) : '種別なし') ?>）</option>
                <?php endforeach; ?>
            </select>
            <button class="primary" type="submit">保存</button>
            <span class="hint">配下の箱が個別キー未設定のとき、このキーでコスト取得します。<a href="<?= h(app_url('manage')) ?>#credpanel">キーを管理</a></span>
        </form>
        <p class="hint" style="margin:10px 0 0">アイコン（見た目）の変更は、上部の<strong>アイコンをクリック</strong>してください。</p>
    </div>
    <?php endif; ?>

    <?php if ($pname !== '（プロダクト未指定）'):
        $creditMonth = product_credit_this_month($gid, $pname);
        $creditList = list_credit_purchases($gid, $pname);
    ?>
    <!-- 追加クレジット（買い足し）の記録 -->
    <div class="panel" style="margin-bottom:18px">
        <h3><?= icon('plus', 16) ?> 追加クレジット（買い足し）
            <?php if ($creditMonth): ?><span class="muted" style="font-weight:400;font-size:13px">／ 今月 <?php $cf = true; foreach ($creditMonth as $cur => $amt): echo ($cf ? '' : '・') . h($cur) . ' ' . number_format($amt, (fmod($amt,1.0)===0.0)?0:2); $cf = false; endforeach; ?></span><?php endif; ?>
        </h3>
        <p class="hint" style="margin:0 0 8px">月額とは別に、クレジットが切れて買い足した分などをここに記録できます（推移とは別管理の履歴）。</p>
        <?php if ($editable): ?>
        <form method="post" class="toolbar" style="margin:0 0 10px;box-shadow:none;border:none;padding:0;background:none;align-items:flex-end">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="add_credit">
            <input type="hidden" name="product" value="<?= h($pname) ?>">
            <label style="font-size:12px;color:var(--muted)">金額<br><input type="number" name="amount" min="0" step="0.01" required style="width:120px" placeholder="例: 5000"></label>
            <label style="font-size:12px;color:var(--muted)">通貨<br><select name="currency"><?php foreach (['JPY','USD','EUR','GBP'] as $cu): ?><option value="<?= $cu ?>"><?= $cu ?></option><?php endforeach; ?></select></label>
            <label style="font-size:12px;color:var(--muted);flex:1;min-width:160px">メモ（任意）<br><input type="text" name="note" style="width:100%" placeholder="例: クレジット$50追加"></label>
            <button class="primary" type="submit">記録</button>
        </form>
        <?php endif; ?>
        <?php if ($creditList): ?>
        <table>
            <thead><tr><th>日時</th><th>金額</th><th>メモ</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($creditList as $cp): ?>
                <tr>
                    <td class="muted"><?= h(substr((string) $cp['created_at'], 0, 10)) ?></td>
                    <td class="cost"><?= h($cp['currency']) ?> <?= number_format((float) $cp['amount'], (fmod((float)$cp['amount'],1.0)===0.0)?0:2) ?><?= jpy_hint((float) $cp['amount'], $cp['currency']) ?></td>
                    <td><?= h($cp['note']) ?: '<span class="muted">—</span>' ?></td>
                    <?php if ($editable): ?><td style="white-space:nowrap"><form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, 'この記録を削除しますか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_credit"><input type="hidden" name="id" value="<?= (int) $cp['id'] ?>"><button class="link danger" type="submit"><?= icon('trash', 15) ?></button></form></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><p class="hint">まだ記録がありません。</p><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 箱とURLの一覧（クリックで開閉・既定は閉じる） -->
    <div class="panel" style="margin-bottom:18px">
        <h3>プロジェクト箱とURL <span class="muted" style="font-weight:400">／ プロジェクト <?= count($pboxes) ?> 箱<?= $punassigned ? '＋未割当' : '' ?>（箱をクリックでURLを展開）</span></h3>
        <?php if ($editable): ?>
        <div class="toolbar" style="margin:0 0 10px">
            <label class="hint" style="white-space:nowrap"><input type="checkbox" onchange="selAllDetail(this)" style="width:auto"> 全URL選択</label>
            <button class="danger" type="button" onclick="doDeleteDetail()"><?= icon('trash', 15) ?> 選択を削除</button>
            <span id="ddCount" class="hint"></span>
            <span class="hint">※ URLの☑を選んでまとめて削除できます（箱を開くと表示されます）。</span>
        </div>
        <?php endif; ?>
        <table>
            <thead><tr><th style="width:28px"></th><th>箱 / URL</th><th>サイト</th><th>月額</th><?php if ($editable): ?><th class="hide-sm">操作</th><?php endif; ?></tr></thead>
            <tbody>
            <?php $bi = 0; foreach ($boxList as $bl): $bi++; $b = $bl['box']; ?>
                <tr class="group-head" onclick="toggleBox(<?= $bi ?>)">
                    <td><span id="bc<?= $bi ?>" class="caret"><?= icon('chevron', 16) ?></span></td>
                    <td><?= icon('box') ?> <strong><?= h($b['name']) ?></strong> <span class="muted">（<?= count($bl['urls']) ?> URL）</span></td>
                    <td class="muted"><?= h(implode('、', array_slice(array_map('strval', $bl['sites']), 0, 4))) ?><?= count($bl['sites']) > 4 ? ' ほか' : '' ?></td>
                    <td class="cost"><?= fmt_money($b['monthly_cost'] === null ? null : (float) $b['monthly_cost'], $b['currency'] ?: 'USD') ?><?php if (($b['balance'] ?? null) !== null): ?><div class="hint">残高 <?= h($b['currency'] ?: 'USD') ?> <?= number_format((float) $b['balance'], 2) ?></div><?php endif; ?><?php if (trim((string) ($b['cost_note'] ?? '')) !== ''): ?><div class="hint" style="white-space:normal;max-width:240px">ⓘ <?= h($b['cost_note']) ?></div><?php endif; ?></td>
                    <?php if ($editable): ?>
                    <td class="hide-sm" style="white-space:nowrap" onclick="event.stopPropagation()">
                        <?php if (($b['cost_type'] ?? '') !== '' || trim((string) $b['openai_project_id']) !== '' || ($b['credential_id'] ?? null) || $pCredId !== null): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="fetch_project_cost"><input type="hidden" name="project_id" value="<?= (int) $b['id'] ?>"><button class="link" type="submit" title="コスト取得"><?= icon('refresh', 15) ?> コスト</button></form>
                        <?php endif; ?>
                        <button class="link" type="button" onclick='openSite(<?= (int)$b["id"] ?>, <?= json_encode($b["name"], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>＋サイト</button>
                        <button class="link" type="button" onclick='openProject(<?= json_encode(["id"=>(int)$b["id"],"name"=>$b["name"],"product"=>$b["product"] ?? "","cost_type"=>$b["cost_type"] ?? "","cost_account"=>$b["cost_account"] ?? "","openai_project_id"=>$b["openai_project_id"],"secret_hint"=>$b["secret_hint"],"monthly_cost"=>$b["monthly_cost"],"currency"=>$b["currency"],"credential_id"=>$b["credential_id"] ?? null], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                        <form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '箱「<?= h($b['name']) ?>」を削除しますか？（URLは未割当に戻ります）')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $b['id'] ?>"><button class="link danger" type="submit">削除</button></form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php
                    // サイト（＝ファイル先頭フォルダ）ごとにページ(URL)をまとめる
                    $bySite = [];
                    foreach ($bl['urls'] as $f) { $bySite[usage_site($f)][] = $f; }
                    ksort($bySite);
                ?>
                <?php foreach ($bySite as $site => $sfiles): ?>
                <tr class="box<?= $bi ?>-url site-row" style="display:none">
                    <td></td>
                    <td style="padding-left:20px"><?= icon('globe', 15) ?> <strong><?= h($site) ?></strong><?php if ($editable): ?> <button class="link" type="button" onclick='renameSite(<?= htmlspecialchars(json_encode($site, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)' title="サイト名を変更"><?= icon('gear', 13) ?></button><?php endif; ?></td>
                    <td class="muted"><?= count($sfiles) ?> ページ</td>
                    <td></td><?php if ($editable): ?><td class="hide-sm"></td><?php endif; ?>
                </tr>
                <?php foreach ($sfiles as $f):
                    $pathStr = $site . ' / ' . $f['file'] . ($f['line'] !== null ? ':' . (int) $f['line'] : ''); ?>
                <tr class="box<?= $bi ?>-url" style="display:none">
                    <td><?php if ($editable): ?><input type="checkbox" class="dchk" value="<?= (int) $f['id'] ?>" style="width:auto"><?php endif; ?></td>
                    <td style="padding-left:42px"><?= $f['is_key'] ? icon('key', 15) : icon('file', 15) ?> <code><?= h($f['file']) ?><?= $f['line'] !== null ? ':' . (int) $f['line'] : '' ?></code>
                        <button class="link" type="button" title="コピー" onclick="copyText(<?= htmlspecialchars(json_encode($pathStr, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= icon('copy', 15) ?></button></td>
                    <td></td>
                    <td></td><?php if ($editable): ?><td class="hide-sm" style="white-space:nowrap"><form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, 'このURLを削除しますか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_usage"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="link danger" type="submit" title="URLを削除"><?= icon('trash', 15) ?></button></form></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($punassigned): $uurls = dedup_urls($punassigned); $bi++; ?>
                <tr class="group-head" onclick="toggleBox(<?= $bi ?>)">
                    <td><span id="bc<?= $bi ?>" class="caret"><?= icon('chevron', 16) ?></span></td>
                    <td><?= icon('box') ?> <strong>未割当</strong> <span class="muted">（<?= count($uurls) ?> URL）</span></td>
                    <td class="muted">—</td><td class="cost">—</td><?php if ($editable): ?><td class="hide-sm"></td><?php endif; ?>
                </tr>
                <?php
                    $uBySite = [];
                    foreach ($uurls as $f) { $uBySite[usage_site($f)][] = $f; }
                    ksort($uBySite);
                ?>
                <?php foreach ($uBySite as $site => $sfiles): ?>
                <tr class="box<?= $bi ?>-url site-row" style="display:none">
                    <td></td>
                    <td style="padding-left:20px"><?= icon('globe', 15) ?> <strong><?= h($site) ?></strong><?php if ($editable): ?> <button class="link" type="button" onclick='renameSite(<?= htmlspecialchars(json_encode($site, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)' title="サイト名を変更"><?= icon('gear', 13) ?></button><?php endif; ?></td>
                    <td class="muted"><?= count($sfiles) ?> ページ</td>
                    <td></td><?php if ($editable): ?><td class="hide-sm"></td><?php endif; ?>
                </tr>
                <?php foreach ($sfiles as $f):
                    $pathStr = $site . ' / ' . $f['file'] . ($f['line'] !== null ? ':' . (int) $f['line'] : ''); ?>
                <tr class="box<?= $bi ?>-url" style="display:none">
                    <td><?php if ($editable): ?><input type="checkbox" class="dchk" value="<?= (int) $f['id'] ?>" style="width:auto"><?php endif; ?></td>
                    <td style="padding-left:42px"><?= $f['is_key'] ? icon('key', 15) : icon('file', 15) ?> <code><?= h($f['file']) ?><?= $f['line'] !== null ? ':' . (int) $f['line'] : '' ?></code>
                        <button class="link" type="button" title="コピー" onclick="copyText(<?= htmlspecialchars(json_encode($pathStr, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= icon('copy', 15) ?></button></td>
                    <td></td>
                    <td></td><?php if ($editable): ?><td class="hide-sm" style="white-space:nowrap"><form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, 'このURLを削除しますか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_usage"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="link danger" type="submit" title="URLを削除"><?= icon('trash', 15) ?></button></form></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!$boxList && !$punassigned): ?>
                <tr><td colspan="<?= $editable ? 5 : 4 ?>" class="muted" style="text-align:center;padding:24px">このプロダクトにはまだ箱もURLもありません。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <a class="btn" href="index.php"><?= icon('left', 15) ?> ダッシュボードに戻る</a>
</main>
</div>
<div id="abtToast"></div>
<?php render_modals($csrf, $names, $credentials); ?>
<?php if ($editable): ?>
<!-- アイコンの見た目（ヘッダのアイコンクリックで開く） -->
<dialog id="logoDialog">
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_product_logo">
        <input type="hidden" name="product" value="<?= h($pname) ?>">
        <div class="modal-head" style="display:flex;align-items:center;gap:10px"><?= provider_badge($pname, 28, ($prodMeta[$pname]['logo_color'] ?? null), ($prodMeta[$pname]['logo_url'] ?? null)) ?> アイコンの見た目</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field"><label>背景色</label><input type="color" name="logo_color" value="<?= h($prodMeta[$pname]['logo_color'] ?? '#ffffff') ?>" style="width:64px;height:40px;padding:2px"></div>
                <div class="field"><label>画像をアップロード</label><input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp"></div>
                <div class="field full"><label>または画像URL</label><input type="url" name="logo_url" value="<?= h(preg_replace('/\?v=\d+$/', '', (string) ($prodMeta[$pname]['logo_url'] ?? ''))) ?>" placeholder="https://.../logo.png"></div>
            </div>
            <p class="hint" style="margin:8px 0 0">既定は白背景。色／アップロード（PNG・JPEG・GIF・WebP、2MBまで）／画像URL のいずれか。画像が色より優先。空のURL＋ファイル無しで画像を解除。</p>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('logoDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<script>
    function toggleBox(bi) {
        const caret = document.getElementById('bc' + bi);
        const open = !caret.classList.contains('open');
        document.querySelectorAll('.box' + bi + '-url').forEach(r => r.style.display = open ? 'table-row' : 'none');
        caret.classList.toggle('open', open);
    }
    function openLogo() { const d = document.getElementById('logoDialog'); if (d) d.showModal(); }
    const DETAIL_CSRF = '<?= h($csrf) ?>';
    function renameSite(oldName) {
        abtPrompt('サイト名を変更します。\n（このグループ全体で「' + oldName + '」をまとめて変更します）', oldName, function (nv) {
            if (nv === oldName) { return; }
            const f = document.createElement('form');
            f.method = 'post';
            const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
            add('csrf', DETAIL_CSRF); add('action', 'rename_site'); add('old_site', oldName); add('new_site', nv);
            document.body.appendChild(f); f.submit();
        });
    }
    function ddCountUpd() {
        const n = document.querySelectorAll('.dchk:checked').length;
        const el = document.getElementById('ddCount'); if (el) el.textContent = n ? (n + ' 件選択中') : '';
    }
    function selAllDetail(cb) {
        document.querySelectorAll('.dchk').forEach(c => { c.checked = cb.checked; });
        // 全選択時はすべての箱を開いて見えるように
        if (cb.checked) { document.querySelectorAll('[id^="bc"]').forEach(c => { if (!c.classList.contains('open')) { const bi = c.id.slice(2); toggleBox(bi); } }); }
        ddCountUpd();
    }
    document.addEventListener('change', e => { if (e.target && e.target.classList && e.target.classList.contains('dchk')) ddCountUpd(); });
    function doDeleteDetail() {
        const ids = [...document.querySelectorAll('.dchk:checked')].map(c => c.value);
        if (!ids.length) { abtAlert('削除するURLを☑で選択してください（箱を開くと表示されます。「全URL選択」も可）。'); return; }
        abtConfirm('選択した ' + ids.length + ' 件のURLを削除します。取り消せません。よろしいですか？', function () {
            const f = document.createElement('form'); f.method = 'post'; f.action = location.href;
            const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
            add('csrf', DETAIL_CSRF); add('action', 'delete_usages');
            ids.forEach(id => add('usage_ids[]', id));
            document.body.appendChild(f); f.submit();
        });
    }
</script>
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
<?php render_sidebar('dashboard'); ?>
<main class="main">
    <div class="topbar">
        <h2>ダッシュボード</h2>
        <span class="grow"></span>
        <?php if ($editable): ?>
            <form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '全プロジェクト箱のコストを今すぐ取得します。よろしいですか？（数が多いと少し時間がかかります）')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="refresh_costs"><button type="submit" class="btn"><?= icon('refresh', 15) ?> コスト一括更新</button></form>
            <button type="button" class="primary" onclick="openCreate()"><?= icon('plus', 15) ?> API を追加</button>
        <?php endif; ?>
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

    <?php if ($editable && cost_refresh_stale($gid)): ?>
    <!-- 自動コスト更新（最後の更新から一定時間でバックグラウンド取得→更新があれば再読込） -->
    <script>
        (function () {
            if (sessionStorage.getItem('abtAutoCost') === '<?= h($ymNow) ?>') return; // 同一月で多重起動防止の保険
            const b = new URLSearchParams();
            b.append('csrf', '<?= h($csrf) ?>'); b.append('action', 'refresh_costs'); b.append('ajax', '1');
            fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: b })
                .then(r => r.json())
                .then(d => { sessionStorage.setItem('abtAutoCost', '<?= h($ymNow) ?>'); if (d && d.ok > 0) { location.reload(); } })
                .catch(() => {});
        })();
    </script>
    <?php endif; ?>

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
                    <div class="hero-amount"><span class="cur"><?= h($cur) ?></span> <?= number_format($sum, (fmod($sum,1.0)===0.0)?0:2) ?><?= jpy_hint((float) $sum, $cur) ?></div>
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
            <?= icon('lock', 15) ?> コスト自動取得に向けて<strong>キーの暗号化保存</strong>を使うには、<code>config.local.php</code> に <code>APP_ENCRYPTION_KEY</code> を設定してください。<br>
            <span class="hint">生成: サーバーで <code>php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"</code> を実行し、出た文字列を設定。</span>
        </div>
    <?php endif; ?>

    <?php if ($legacyCount > 0 && can_manage()): ?>
        <div class="flash" style="background:#fff4e0;color:#92400e;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span>旧形式（サイト未設定）のエントリが <strong><?= $legacyCount ?></strong> 件あります。<strong>まず再スキャンしてサイト別の新エントリを作ってから</strong>、古いものを削除して整理してください。<br><span class="hint">※削除すると、その旧エントリに手入力したコスト等も消えます。必要な値は新エントリへ移してから削除を。</span></span>
            <form method="post" style="margin:0" onsubmit="return abtConfirmForm(this, 'サイト未設定のエントリ <?= $legacyCount ?> 件を削除します（手入力した内容も消えます）。よろしいですか？')">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete_legacy">
                <button class="danger" type="submit">古いエントリを削除</button>
            </form>
        </div>
    <?php endif; ?>

    <?php $filterActive = ($q !== '' || $filterSite !== '' || $filterProv !== '' || $filterStatus !== ''); ?>
    <!-- 絞り込み・並び替え（右下の追従ボタン → 右端からドロワーが出る・非モーダル） -->
    <button type="button" id="filterFab" class="fab<?= $filterActive ? ' active' : '' ?>" onclick="toggleFilter()" title="絞り込み・並び替え" aria-expanded="false">
        <?= icon('search', 20) ?><span class="fab-label">絞り込み<?= $filterActive ? '（適用中）' : '' ?></span>
    </button>
    <aside id="filterDrawer" class="drawer" aria-hidden="true">
        <form method="get">
            <div class="drawer-head">
                <strong>絞り込み・並び替え</strong>
                <button type="button" class="link" onclick="toggleFilter(false)" title="閉じる"><?= icon('right', 18) ?></button>
            </div>
            <div class="drawer-body">
                <p class="hint" style="margin:0 0 6px">開いたまま一覧をコピーして貼り付けできます。</p>
                <div class="field"><label>検索</label><input type="search" name="q" value="<?= h($q) ?>" placeholder="API / サイト / キー / メモ"></div>
                <div class="field"><label>サイト</label><select name="site"><option value="">すべて</option><?php foreach ($sites as $s): ?><option value="<?= h($s) ?>" <?= $s === $filterSite ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>provider</label><select name="provider"><option value="">すべて</option><?php foreach ($providers as $p): ?><option value="<?= h($p) ?>" <?= $p === $filterProv ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>status</label><select name="status"><option value="">すべて</option><?php foreach (STATUSES as $k => $v): ?><option value="<?= h($k) ?>" <?= $k === $filterStatus ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>並び順</label><select name="sort"><option value="manual" <?= $sort === 'manual' ? 'selected' : '' ?>>手動の並び順</option><option value="cost" <?= $sort === 'cost' ? 'selected' : '' ?>>金額順</option><option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>プロバイダ名順</option></select></div>
            </div>
            <div class="drawer-foot">
                <a class="btn" href="index.php">クリア</a>
                <button class="primary" type="submit">適用</button>
            </div>
        </form>
    </aside>
    <script>
        function toggleFilter(force) {
            const d = document.getElementById('filterDrawer'), f = document.getElementById('filterFab');
            const open = (force === undefined) ? !d.classList.contains('open') : force;
            d.classList.toggle('open', open);
            d.setAttribute('aria-hidden', open ? 'false' : 'true');
            f.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { const q = d.querySelector('input[name="q"]'); if (q) q.focus(); }
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') toggleFilter(false); });
    </script>

    <!-- プロダクト → プロジェクト箱 → URL ビュー -->
    <?php if (!$tree): ?>
        <div class="empty"><div class="duck-hero" style="margin-bottom:10px"><img class="duckimg" src="<?= h(app_base_url()) ?>/duck2.png" alt="" style="width:96px"></div>該当するデータがありません。<?= $editable ? '「＋ API を追加」または「スキャン」で取り込んでください。' : '' ?></div>
    <?php else: ?>
    <div class="steam-hr"></div>
    <!-- プロダクト・カード一覧 -->
    <div class="card-grid">
        <?php foreach ($names as $gname):
            $cprov = $providerOf[$gname] ?? '';
            $cboxes = $boxesByProduct[$gname] ?? [];
            $cunassigned = $unassignedByProduct[$gname] ?? [];
            $csites = [];
            foreach ($cboxes as $b) { foreach (($usagesByBox[(int) $b['id']] ?? []) as $u) { $csites[usage_site($u)] = 1; } }
            foreach ($cunassigned as $u) { $csites[usage_site($u)] = 1; }
            $cmoney = [];
            foreach ($cboxes as $b) { if ($b['monthly_cost'] !== null) { $cur = $b['currency'] ?: 'USD'; $cmoney[$cur] = ($cmoney[$cur] ?? 0) + (float) $b['monthly_cost']; } }
            ksort($cmoney);
            $cmeta = $prodMeta[$gname] ?? null;
            // 主要通貨で前月比＋しきい値判定
            $domCur = ''; $domVal = 0.0;
            foreach ($cmoney as $cur => $v) { if ($v > $domVal) { $domVal = $v; $domCur = $cur; } }
            $prevVal = ($domCur !== '') ? ($snap[$gname][$ymPrev][$domCur] ?? null) : null;
            $diffPct = ($prevVal !== null && $prevVal > 0) ? ($domVal - $prevVal) / $prevVal * 100 : null;
            $alert = (($cmeta['cost_alert'] ?? null) !== null) ? (float) $cmeta['cost_alert'] : null;
            $over = ($alert !== null && $domVal > $alert);
        ?>
        <a class="pcard<?= $over ? ' over' : '' ?>" href="<?= h(app_url('product', ['name' => $gname])) ?>">
            <?php if ($over): ?><span class="pcard-warn"><?= icon('refresh', 12) ?> 使いすぎ</span><?php endif; ?>
            <div class="pcard-top">
                <?= provider_badge($gname, 44, $cmeta['logo_color'] ?? null, $cmeta['logo_url'] ?? null) ?>
                <div style="min-width:0">
                    <div class="pcard-name"><?= h($gname) ?></div>
                    <?php if ($cprov): ?><div class="pcard-prov muted"><?= h($cprov) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="pcard-amt"><?php if ($cmoney): $first = true; foreach ($cmoney as $cur => $v): ?><?= $first ? '' : '<br>' ?><small><?= h($cur) ?></small> <?= number_format($v, (fmod($v,1.0)===0.0)?0:2) ?><?= jpy_hint((float) $v, $cur) ?><?php $first = false; endforeach; else: ?><span class="muted" style="font-size:15px">未設定</span><?php endif; ?></div>
            <?php if ($diffPct !== null): $up = $diffPct >= 0; ?>
                <div class="pcard-diff <?= $up ? 'up' : 'down' ?>"><?= $up ? '▲' : '▼' ?> <?= abs(round($diffPct)) ?>% <span class="muted">先月比</span></div>
            <?php elseif ($alert !== null): ?>
                <div class="pcard-diff muted">上限 <?= h($domCur ?: '') ?> <?= number_format($alert) ?></div>
            <?php endif; ?>
            <div class="pcard-meta"><span><?= icon('box', 14) ?> <?= count($cboxes) ?> 箱</span><span><?= icon('globe', 14) ?> <?= count($csites) ?> サイト</span></div>
            <span class="detail-btn">詳細 <?= icon('right', 14) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <details class="stat" style="width:100%;margin-bottom:10px">
    <summary style="cursor:pointer;font-weight:600"><?= icon('gear', 15) ?> URLの割り当て・並び替え（詳細管理）</summary>
    <?php if ($editable): ?>
    <p class="hint" style="margin:8px 0">展開：プロダクト → プロジェクト箱 → URL。URL/サイトの☑を選び、下で「箱へ移動」できます。</p>
    <div class="toolbar" style="margin-bottom:10px">
        <span>選択を移動 →</span>
        <select id="moveTargetSel" onchange="document.getElementById('moveNew').style.display=this.value==='new'?'flex':'none'">
            <option value="unassign">（未割当に戻す）</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= h($p['name']) ?><?= $p['openai_project_id'] !== '' ? '（' . h($p['openai_project_id']) . '）' : '' ?></option>
            <?php endforeach; ?>
            <option value="new">＋ 新しい箱を作る…</option>
        </select>
        <span id="moveNew" style="display:none;gap:6px;align-items:center">
            <input id="moveNewName" placeholder="箱の名前" style="width:130px">
            <input id="moveNewProj" placeholder="proj_xxx（任意）" style="width:140px">
        </span>
        <button class="primary" type="button" onclick="doMove()">箱へ移動</button>
        <button class="danger" type="button" onclick="doDeleteSelected()"><?= icon('trash', 15) ?> 選択を削除</button>
        <span id="moveCount" class="hint"></span>
        <span class="spacer"></span>
        <label class="hint" style="white-space:nowrap"><input type="checkbox" onchange="selAllGlobal(this)" style="width:auto"> 表示中の全URL選択</label>
        <button class="btn" type="button" onclick="openProject({})"><?= icon('plus', 15) ?> 箱を追加</button>
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
                <td><?php if ($editable): ?><span class="drag-handle" title="ドラッグで並べ替え"><?= icon('grip', 16) ?></span><?php endif; ?><span id="pc<?= $gi ?>" class="caret"><?= icon('chevron', 16) ?></span></td>
                <td><span style="display:flex;align-items:center;gap:8px"><?= provider_badge($gname, 30, $prodMeta[$gname]['logo_color'] ?? null, $prodMeta[$gname]['logo_url'] ?? null) ?> <strong><?= h($gname) ?></strong><?php if ($provider): ?> <span class="muted">（<?= h($provider) ?>）</span><?php endif; ?>
                    <a href="<?= h(app_url('product', ['name' => $gname])) ?>" class="detail-btn" style="margin-left:auto" onclick="event.stopPropagation()">詳細 <?= icon('right', 14) ?></a></span></td>
                <td class="cost group-cost"><?= h($pmoneyStr) ?></td>
                <td class="hide-sm" style="white-space:nowrap">
                    <span class="muted"><?= $nBoxes ?> 箱 / <?= count($prodSites) ?> サイト</span>
                    <?php if ($editable): ?>
                        <form method="post" style="display:inline" onclick="event.stopPropagation()"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reorder"><input type="hidden" name="name" value="<?= h($gname) ?>"><input type="hidden" name="dir" value="up"><button class="link" type="submit" title="上へ"><?= icon('up', 15) ?></button></form>
                        <form method="post" style="display:inline" onclick="event.stopPropagation()"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reorder"><input type="hidden" name="name" value="<?= h($gname) ?>"><input type="hidden" name="dir" value="down"><button class="link" type="submit" title="下へ"><?= icon('down', 15) ?></button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($editable && $prodSites): ?>
            <tr class="prod<?= $gi ?> p<?= $gi ?>-proj" style="display:none">
                <td></td>
                <td colspan="3"><span class="hint">サイトごと移動: </span>
                    <?php foreach (array_keys($prodSites) as $s): ?>
                        <label style="font-size:12px;margin-right:8px;white-space:nowrap"><input type="checkbox" class="siteChk" value="<?= h($s) ?>" onchange="updMoveCount()" style="width:auto"> <?= icon('globe', 15) ?> <?= h($s) ?></label>
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
            $boxLabel = $proj ? ($proj['name'] . ($proj['openai_project_id'] !== '' ? '（' . $proj['openai_project_id'] . '）' : '')) : '未割当';
            $boxMoney = $proj ? fmt_money($proj['monthly_cost'] === null ? null : (float) $proj['monthly_cost'], $proj['currency'] ?: 'USD') : '—';
        ?>
            <tr class="prod<?= $gi ?> p<?= $gi ?>-proj" style="display:none">
                <td style="text-align:right"><span id="jc<?= $gi ?>_<?= $pj ?>" class="caret" onclick="toggleProj(<?= $gi ?>,<?= $pj ?>)" style="cursor:pointer"><?= icon('chevron', 16) ?></span></td>
                <td style="padding-left:24px"><?= icon('box') ?> <?= h($boxLabel) ?> <span class="muted">（<?= count($urls) ?> URL）</span><?php if ($proj && ($proj['balance'] ?? null) !== null): ?> <span class="muted">｜残高 <?= h($proj['currency'] ?: 'USD') ?> <?= number_format((float) $proj['balance'], 2) ?></span><?php endif; ?><?php if ($proj && trim((string) ($proj['cost_note'] ?? '')) !== ''): ?><div class="hint" style="margin:2px 0 0 24px">ⓘ <?= h($proj['cost_note']) ?></div><?php endif; ?></td>
                <td class="cost"><?= $boxMoney ?></td>
                <td class="hide-sm" style="white-space:nowrap" onclick="event.stopPropagation()">
                    <?php if ($editable && $proj): ?>
                        <?php if (($proj['cost_type'] ?? '') !== '' || trim((string) $proj['openai_project_id']) !== '' || ($proj['credential_id'] ?? null) || isset($prodCredSet[(string) ($proj['product'] ?? '')])): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="fetch_project_cost"><input type="hidden" name="project_id" value="<?= (int) $proj['id'] ?>"><button class="link" type="submit" title="コスト取得"><?= icon('refresh', 15) ?> コスト</button></form>
                        <?php endif; ?>
                        <button class="link" type="button" onclick='openSite(<?= (int)$proj["id"] ?>, <?= json_encode($proj["name"], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>＋サイト</button>
                        <button class="link" type="button" onclick='openProject(<?= json_encode(["id"=>(int)$proj["id"],"name"=>$proj["name"],"product"=>$proj["product"],"cost_type"=>$proj["cost_type"] ?? "","cost_account"=>$proj["cost_account"] ?? "","openai_project_id"=>$proj["openai_project_id"],"secret_hint"=>$proj["secret_hint"],"monthly_cost"=>$proj["monthly_cost"],"currency"=>$proj["currency"],"credential_id"=>$proj["credential_id"] ?? null], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'>箱を編集</button>
                        <form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, '箱「<?= h($proj['name']) ?>」を削除しますか？（URLは未割当に戻ります）')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $proj['id'] ?>"><button class="link danger" type="submit">箱削除</button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="prod<?= $gi ?> p<?= $gi ?>j<?= $pj ?>-file" style="display:none">
                <td></td>
                <td colspan="3" style="padding-left:48px">
                    <?php if (!$urls): ?><span class="muted">URLなし（移動バーでこの箱へURL/サイトを割り当てできます）</span><?php endif; ?>
                    <?php if ($editable && $urls): ?><label class="hint" style="display:inline-block;margin-bottom:4px"><input type="checkbox" onchange="selAllBox(this,<?= $gi ?>,<?= $pj ?>)" style="width:auto"> この箱のURLを全選択</label><?php endif; ?>
                    <?php
                        // サイト（＝ファイルの先頭フォルダ）ごとにページ(URL)をまとめる
                        $bySite = [];
                        foreach ($urls as $f) { $bySite[usage_site($f)][] = $f; }
                        ksort($bySite);
                    ?>
                    <?php foreach ($bySite as $site => $sfiles): ?>
                        <div class="site-group">
                            <div class="site-head"><span class="sc"><?= icon('globe', 15) ?></span> <strong><?= h($site) ?></strong> <span class="muted" style="font-weight:400">（<?= count($sfiles) ?> ページ）</span><?php if ($editable): ?> <button class="link" type="button" onclick='renameSite(<?= htmlspecialchars(json_encode($site, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)' title="サイト名を変更"><?= icon('gear', 13) ?></button><?php endif; ?></div>
                            <?php foreach ($sfiles as $f):
                                $pathStr = $site . ' / ' . $f['file'] . ($f['line'] !== null ? ':' . (int) $f['line'] : '');
                            ?>
                                <div class="url-row">
                                    <?php if ($editable): ?><input type="checkbox" class="moveChk" value="<?= (int) $f['id'] ?>" onchange="updMoveCount()" style="width:auto"><?php endif; ?>
                                    <?= $f['is_key'] ? icon('key', 14) : icon('file', 14) ?>
                                    <code><?= h($f['file']) ?><?= $f['line'] !== null ? ':' . (int) $f['line'] : '' ?></code>
                                    <button class="link" type="button" title="コピー" onclick="copyText(<?= htmlspecialchars(json_encode($pathStr, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= icon('copy', 14) ?></button>
                                    <?php if ($editable): ?><form method="post" style="display:inline" onsubmit="return abtConfirmForm(this, 'このURLを削除しますか？')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_usage"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="link danger" type="submit" title="URLを削除"><?= icon('trash', 14) ?></button></form><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; /* boxes */ ?>
        <?php endforeach; /* products */ ?>
        </tbody>
    </table>
    </div>
    </details>
    <?php endif; ?>

    <p class="hint" style="margin-top:18px">
        ※ コストは<?= icon('refresh', 14) ?>取得 or 手入力。一覧ではURLは展開時のみ表示。APIキー本体は保存せず暗号化／鍵の在りかのみ記録します。
    </p>
</main>
</div>
<div id="abtToast"></div>

<?php render_modals($csrf, $names, $credentials); ?>
<script>
    // ---- ドラッグ＆ドロップ並べ替え（PC、リロードなし） ----
    const ABT_CSRF = '<?= h($csrf) ?>';
    function renameSite(oldName) {
        abtPrompt('サイト名を変更します。\n（このグループ全体で「' + oldName + '」をまとめて変更します）', oldName, function (nv) {
            if (nv === oldName) { return; }
            const f = document.createElement('form');
            f.method = 'post';
            const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
            add('csrf', ABT_CSRF); add('action', 'rename_site'); add('old_site', oldName); add('new_site', nv);
            document.body.appendChild(f); f.submit();
        });
    }
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
        if (!uids.length && !sites.length) { abtAlert('移動するURLまたはサイトを☑で選択してください。'); return; }
        const sel = document.getElementById('moveTargetSel').value;
        let label = '未割当';
        const f = document.createElement('form'); f.method = 'post'; f.action = 'index.php';
        const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
        add('csrf', ABT_CSRF); add('action', 'move_to_project');
        if (sel === 'new') {
            const nm = document.getElementById('moveNewName').value.trim();
            if (!nm) { abtAlert('新しい箱の名前を入れてください。'); return; }
            add('new_name', nm); add('new_proj', document.getElementById('moveNewProj').value.trim());
            label = '新しい箱「' + nm + '」';
        } else if (sel !== 'unassign') {
            add('target_project', sel);
            label = '選択した箱';
        }
        abtConfirm('URL ' + uids.length + ' 件 / サイト ' + sites.length + ' 件を ' + label + ' へ移動します。よろしいですか？', function () {
            uids.forEach(id => add('usage_ids[]', id));
            sites.forEach(s => add('sites[]', s));
            document.body.appendChild(f); f.submit();
        }, { danger: false });
    }
    function doDeleteSelected() {
        const uids = [...document.querySelectorAll('.moveChk:checked')].map(c => c.value);
        if (!uids.length) { abtAlert('削除するURLを☑で選択してください（「表示中の全URL選択」も使えます）。'); return; }
        abtConfirm('選択した ' + uids.length + ' 件のURLを削除します。取り消せません。よろしいですか？', function () {
            const f = document.createElement('form'); f.method = 'post'; f.action = 'index.php';
            const add = (k, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
            add('csrf', ABT_CSRF); add('action', 'delete_usages');
            uids.forEach(id => add('usage_ids[]', id));
            document.body.appendChild(f); f.submit();
        });
    }

    // プロダクト展開 → プロジェクト行を表示（閉じる時はファイルも畳む）
    function toggleProduct(gi) {
        const caret = document.getElementById('pc' + gi);
        const open = !caret.classList.contains('open');
        document.querySelectorAll('.p' + gi + '-proj').forEach(r => r.style.display = open ? 'table-row' : 'none');
        caret.classList.toggle('open', open);
        if (!open) {
            document.querySelectorAll('.prod' + gi + '[class*="-file"]').forEach(r => r.style.display = 'none');
            document.querySelectorAll('[id^="jc' + gi + '_"]').forEach(c => c.classList.remove('open'));
        }
    }
    // プロジェクト展開 → そのキーファイル行を表示
    function toggleProj(gi, pj) {
        const caret = document.getElementById('jc' + gi + '_' + pj);
        const open = !caret.classList.contains('open');
        document.querySelectorAll('.p' + gi + 'j' + pj + '-file').forEach(r => r.style.display = open ? 'table-row' : 'none');
        caret.classList.toggle('open', open);
    }
</script>
</body>
</html>
