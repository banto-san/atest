<?php
declare(strict_types=1);

/**
 * Googleログインの入口とコールバック
 * --------------------------------------------------------------------------
 *  oauth.php                       … Googleの認可画面へリダイレクト（ログイン開始）
 *  oauth.php?code=...&state=...     … Googleからの戻り先（コード受取→ログイン確立）
 *
 * 有効化には config.local.php に GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET が必要。
 * ログイン可能ドメインは ALLOWED_EMAIL_DOMAINS（例: sk-t.com）で限定。
 */

require __DIR__ . '/bootstrap.php';

// 設定が無ければ通常ログインへ戻す
if (!google_enabled()) {
    $_SESSION['login_error'] = 'Googleログインは未設定です（管理者に連絡してください）。';
    redirect('index.php');
}

// Googleからの戻り（code か error が付いている）はコールバックとして処理する。
// ※ここを取りこぼすと、戻りを「開始」と誤認してGoogleへ送り返し、ログイン画面をループする。
$isCallback = isset($_GET['code']) || isset($_GET['error']) || (($_GET['action'] ?? '') === 'callback');

if ($isCallback) {
    try {
        $info = google_handle_callback();
        if (!mdb_email_allowed($info['email'])) {
            $doms = implode(', ', mdb_allowed_domains());
            $_SESSION['login_error'] = 'このアプリは ' . $doms . ' のGoogleアカウントのみ利用できます。';
            redirect('index.php');
        }
        login_with_google($info);
        redirect('index.php');
    } catch (Throwable $e) {
        $_SESSION['login_error'] = $e->getMessage();
        redirect('index.php');
    }
}

// 開始：Googleの認可画面へ
redirect(google_login_url());
