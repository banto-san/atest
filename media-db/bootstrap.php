<?php
declare(strict_types=1);

/**
 * 受注逆引きDB 共通ブートストラップ
 * --------------------------------------------------------------------------
 * セッション / data.json の読み書き / ログイン認証 / 画面共通ヘルパ をまとめる。
 * 各ページの先頭で require する。
 *
 * データは1ファイル(data.json)に保存するシンプルな構成。
 * （SQLite等は使わず、heteml 等の素のPHP共有ホスティングで単体動作する）
 */

mb_internal_encoding('UTF-8');

const MDB_APP_NAME  = '受注逆引きDB';
const MDB_DATA_FILE = __DIR__ . '/data.json';

/* ------------------------------------------------------------------ *
 *  セッション
 * ------------------------------------------------------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('mediadb');
    session_start();
}

/* ------------------------------------------------------------------ *
 *  小物ヘルパ
 * ------------------------------------------------------------------ */
/** HTMLエスケープ */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * 静的アセット(css/js)のURLにファイル更新時刻を付けてキャッシュ対策する。
 * 例: tailwind.css → tailwind.css?v=1717..  。アップし直すと自動で最新が読まれる。
 */
function mdb_asset(string $file): string
{
    $path = __DIR__ . '/' . $file;
    $v = is_file($path) ? (string) filemtime($path) : (string) time();
    return h($file . '?v=' . $v);
}

/** 指定URLへリダイレクトして終了 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** 今日(YYYY-MM-DD) */
function today_str(): string
{
    return date('Y-m-d');
}

/* ------------------------------------------------------------------ *
 *  設定（config.local.php / 環境変数）。APIキー等の秘密情報はここから読む。
 *  config.local.php は gitignore 済み・直アクセス禁止(.htaccess)。
 * ------------------------------------------------------------------ */
function mdb_config(string $key, $default = null)
{
    static $conf = null;
    if ($conf === null) {
        $conf = [];
        $file = __DIR__ . '/config.local.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $conf = $loaded;
            }
        }
    }
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    if (array_key_exists($key, $conf) && $conf[$key] !== '' && $conf[$key] !== null) {
        return $conf[$key];
    }
    return $default;
}

/* ------------------------------------------------------------------ *
 *  CSRF（フォーム / API共通）
 * ------------------------------------------------------------------ */
function csrf_token(): string
{
    if (empty($_SESSION['mdb_csrf'])) {
        $_SESSION['mdb_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mdb_csrf'];
}

function check_csrf(?string $sent): bool
{
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['mdb_csrf'] ?? '', $sent);
}

/* ------------------------------------------------------------------ *
 *  データ(data.json)の読み書き
 * ------------------------------------------------------------------ */
/** 初期データ（data.json が無い時に一度だけ生成される） */
function mdb_default_data(): array
{
    return [
        'users' => [
            ['id' => 'u1', 'name' => 'システム管理者', 'loginId' => 'admin', 'password' => 'password', 'role' => 'admin'],
            ['id' => 'u2', 'name' => '営業担当',       'loginId' => 'sales', 'password' => '1234',     'role' => 'member'],
        ],
        'media' => [
            ['id' => 'm1', 'name' => 'イツザイ',     'domain' => 'itsuzai.jp'],
            ['id' => 'm2', 'name' => 'Sansan',       'domain' => 'sansan.com'],
            ['id' => 'm3', 'name' => 'リクナビ',     'domain' => 'rikunabi.com'],
            ['id' => 'm4', 'name' => 'マイナビ',     'domain' => 'mynavi.jp'],
            ['id' => 'm5', 'name' => 'Salesforce',   'domain' => 'salesforce.com'],
            ['id' => 'm6', 'name' => 'Google広告',   'domain' => 'ads.google.com'],
            ['id' => 'm7', 'name' => 'タクシー広告', 'domain' => 'tokyo-prime.jp'],
            ['id' => 'm8', 'name' => 'doda',         'domain' => 'doda.jp'],
        ],
        'clients' => [
            ['id' => 'c1', 'name' => '株式会社アルファ',     'industry' => 'IT・通信', 'orderDate' => '2026-06-02', 'address' => '東京都渋谷区神南1-2-3',        'sourceMediaId' => 'm1', 'usedMediaIds' => ['m1', 'm2', 'm6']],
            ['id' => 'c2', 'name' => 'ベータ建設工業',       'industry' => '建設',     'orderDate' => '2026-06-02', 'address' => '大阪府大阪市北区梅田2-4-6',     'sourceMediaId' => 'm3', 'usedMediaIds' => ['m3', 'm5']],
            ['id' => 'c3', 'name' => 'ガンマ商事',           'industry' => '卸売',     'orderDate' => '2026-06-01', 'address' => '愛知県名古屋市中区栄3-5-7',     'sourceMediaId' => 'm1', 'usedMediaIds' => ['m1', 'm4', 'm5', 'm7']],
            ['id' => 'c4', 'name' => 'デルタ不動産',         'industry' => '不動産',   'orderDate' => '2026-05-28', 'address' => '福岡県福岡市博多区博多駅前1-1-1', 'sourceMediaId' => 'm6', 'usedMediaIds' => ['m1', 'm6', 'm8']],
            ['id' => 'c5', 'name' => 'イプシロン医療法人',   'industry' => '医療',     'orderDate' => '2026-06-02', 'address' => '北海道札幌市中央区大通西4-1',   'sourceMediaId' => 'm2', 'usedMediaIds' => ['m2', 'm8']],
        ],
        // 独ドメげっとで除外する追加ドメイン（ユーザー編集分）。SNS/予約/ポータル等の定番は既定で除外。
        'excludeDomains' => [],
    ];
}

/** data.json を読み込む（無ければ初期データを作成）。常に users/media/clients を含む形に正規化。 */
function load_data(): array
{
    $data = null;
    if (is_file(MDB_DATA_FILE)) {
        $raw = file_get_contents(MDB_DATA_FILE);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }
    if ($data === null) {
        $data = mdb_default_data();
        save_data($data);
    }
    // キー欠落に備えて補完
    $data += ['users' => [], 'media' => [], 'clients' => [], 'excludeDomains' => []];

    // 権限(role)の補完：未設定なら loginId 'admin' を管理者、それ以外は一般に。
    foreach ($data['users'] as &$u) {
        if (!isset($u['role']) || !in_array($u['role'], ['admin', 'manager', 'member'], true)) {
            $u['role'] = (($u['loginId'] ?? '') === 'admin') ? 'admin' : 'member';
        }
    }
    unset($u);

    return $data;
}

/** data.json へ排他ロック付きで保存 */
function save_data(array $data): bool
{
    $normalized = [
        'users'          => array_values($data['users']          ?? []),
        'media'          => array_values($data['media']          ?? []),
        'clients'        => array_values($data['clients']        ?? []),
        'excludeDomains' => array_values($data['excludeDomains'] ?? []),
    ];
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return file_put_contents(MDB_DATA_FILE, $json, LOCK_EX) !== false;
}

/* ------------------------------------------------------------------ *
 *  認証（セッション内のユーザー）
 * ------------------------------------------------------------------ */
/** ログインID/パスワードが一致するユーザーを返す（無ければ null） */
function find_user_by_credentials(string $loginId, string $password): ?array
{
    foreach (load_data()['users'] as $u) {
        if (($u['loginId'] ?? '') === $loginId && (string) ($u['password'] ?? '') === $password) {
            return $u;
        }
    }
    return null;
}

/** 現在ログイン中のユーザー（未ログインなら null） */
function current_user(): ?array
{
    $id = $_SESSION['mdb_user_id'] ?? null;
    if (!$id) {
        return null;
    }
    foreach (load_data()['users'] as $u) {
        if (($u['id'] ?? '') === $id) {
            return $u;
        }
    }
    return null;
}

/** 現在のユーザーが管理者(role=admin)か。※アカウント編集・除外リスト編集はこれが必要。 */
function is_admin(): bool
{
    $u = current_user();
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

/**
 * API（検索など課金処理）を実行できる権限か（admin か manager）。
 * - admin   : 全権限（アカウント編集も可）
 * - manager : API利用は可。アカウント/管理者の編集は不可
 * - member  : 閲覧のみ（API不可）
 */
function can_use_api(): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'] ?? '', ['admin', 'manager'], true);
}

/** 未ログインならログイン画面へ飛ばす。ログイン中ならユーザー行を返す。 */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('index.php');
    }
    return $u;
}

/** ログイン状態を確立 */
function do_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['mdb_user_id'] = $user['id'];
}

/** ログアウト */
function do_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ------------------------------------------------------------------ *
 *  Googleログイン（OAuth 2.0 / OpenID Connect）
 *  config.local.php に GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET を設定すると有効化。
 *  ALLOWED_EMAIL_DOMAINS（例: sk-t.com）でログイン可能ドメインを限定できる。
 * ------------------------------------------------------------------ */
/** Googleログインが使える設定になっているか */
function google_enabled(): bool
{
    return (string) mdb_config('GOOGLE_CLIENT_ID', '') !== '' && (string) mdb_config('GOOGLE_CLIENT_SECRET', '') !== '';
}

/** このアプリのベースURL（末尾スラッシュなし） */
function mdb_base_url(): string
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') === '443')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

/** GoogleコールバックURL（oauth.php）。Google Cloud側の「承認済みリダイレクトURI」に登録する値。 */
function google_redirect_uri(): string
{
    $c = mdb_config('GOOGLE_REDIRECT_URI', '');
    return $c ? (string) $c : mdb_base_url() . '/oauth.php';
}

/** ログイン許可ドメインの配列（未設定なら空＝制限なし） */
function mdb_allowed_domains(): array
{
    $raw = trim((string) mdb_config('ALLOWED_EMAIL_DOMAINS', ''));
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_map(static fn($d) => ltrim($d, '@'), $parts);
}

/** そのメールがログイン許可ドメインか（未設定なら全許可） */
function mdb_email_allowed(string $email): bool
{
    $allowed = mdb_allowed_domains();
    if (!$allowed) {
        return true;
    }
    $at = strrpos($email, '@');
    return $at !== false && in_array(strtolower(substr($email, $at + 1)), $allowed, true);
}

/** 管理者にするメールの一覧（既定 ＋ config の ADMIN_EMAILS）。小文字で返す。 */
function mdb_admin_emails(): array
{
    $list = ['a.yasugi@sk-t.com'];   // 既定の管理者
    $raw  = trim((string) mdb_config('ADMIN_EMAILS', ''));
    if ($raw !== '') {
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
            $list[] = $e;
        }
    }
    return array_values(array_unique(array_map('strtolower', $list)));
}

/** そのメールは管理者扱いか */
function mdb_is_admin_email(string $email): bool
{
    return in_array(strtolower(trim($email)), mdb_admin_emails(), true);
}

/** 簡易HTTP（curl優先）。戻り値: ['status'=>int,'body'=>string,'error'=>string] */
function mdb_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['status' => $code, 'body' => $resp === false ? '' : (string) $resp, 'error' => $resp === false ? $err : ''];
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return ['status' => $code, 'body' => $resp === false ? '' : $resp, 'error' => $resp === false ? 'request failed' : ''];
}

/** Googleの認可URL（stateをセッションに保存） */
function google_login_url(): string
{
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $params = [
        'client_id'     => (string) mdb_config('GOOGLE_CLIENT_ID'),
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Googleコールバック処理。成功で ['email','name','picture'] を返し、失敗で例外。
 */
function google_handle_callback(): array
{
    $state = $_GET['state'] ?? '';
    $sess  = $_SESSION['oauth_state'] ?? '';
    unset($_SESSION['oauth_state']);
    if ($state === '' || !hash_equals($sess, $state)) {
        throw new RuntimeException('認証の照合に失敗しました（state不一致）。もう一度お試しください。');
    }
    if (isset($_GET['error'])) {
        throw new RuntimeException('Google認証がキャンセルされました。');
    }
    $code = $_GET['code'] ?? '';
    if ($code === '') {
        throw new RuntimeException('認可コードがありません。');
    }
    $tok = mdb_http('POST', 'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'code'          => $code,
            'client_id'     => (string) mdb_config('GOOGLE_CLIENT_ID'),
            'client_secret' => (string) mdb_config('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => google_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ])
    );
    if ($tok['status'] !== 200) {
        throw new RuntimeException('トークン取得に失敗しました (HTTP ' . $tok['status'] . ')。');
    }
    $accessToken = json_decode($tok['body'], true)['access_token'] ?? '';
    if ($accessToken === '') {
        throw new RuntimeException('アクセストークンが取得できませんでした。');
    }
    $ui = mdb_http('GET', 'https://openidconnect.googleapis.com/v1/userinfo', ['Authorization: Bearer ' . $accessToken]);
    if ($ui['status'] !== 200) {
        throw new RuntimeException('ユーザー情報の取得に失敗しました。');
    }
    $info  = json_decode($ui['body'], true) ?: [];
    $email = strtolower(trim((string) ($info['email'] ?? '')));
    if ($email === '') {
        throw new RuntimeException('メールアドレスが取得できませんでした。');
    }
    return ['email' => $email, 'name' => (string) ($info['name'] ?? ''), 'picture' => (string) ($info['picture'] ?? '')];
}

/**
 * Googleプロフィールでログイン確立。data.json の users をメールで照合し、
 * 無ければ（ドメイン許可済み前提で）自動作成する。戻り値: user 配列。
 */
function login_with_google(array $info): array
{
    $data  = load_data();
    $email = strtolower($info['email']);
    $idx = null;
    foreach ($data['users'] as $i => $u) {
        if (strtolower((string) ($u['email'] ?? '')) === $email) { $idx = $i; break; }
    }
    $isAdmin = mdb_is_admin_email($email);
    if ($idx === null) {
        $user = [
            'id'      => 'u' . time() . random_int(100, 999),
            'name'    => $info['name'] !== '' ? $info['name'] : $email,
            'loginId' => $email,
            'password' => '',          // Googleログイン専用（パスワードなし）
            'email'   => $email,
            'auth'    => 'google',
            'role'    => $isAdmin ? 'admin' : 'member',
        ];
        $data['users'][] = $user;
        save_data($data);
    } else {
        $user = $data['users'][$idx];
        $changed = false;
        // 表示名が空なら補完
        if (($user['name'] ?? '') === '' && $info['name'] !== '') {
            $data['users'][$idx]['name'] = $info['name'];
            $user['name'] = $info['name'];
            $changed = true;
        }
        // 管理者メールなら管理者へ昇格（既に管理者ならそのまま）
        if ($isAdmin && ($user['role'] ?? '') !== 'admin') {
            $data['users'][$idx]['role'] = 'admin';
            $user['role'] = 'admin';
            $changed = true;
        }
        if ($changed) {
            save_data($data);
        }
    }
    do_login($user);
    return $user;
}

/* ------------------------------------------------------------------ *
 *  画面共通：サイドメニュー定義
 *  キー => [表示名, lucideアイコン名, リンク先ファイル]
 * ------------------------------------------------------------------ */
function nav_items(): array
{
    return [
        'home'        => ['ホーム',                'home',             'index.php'],
        'register'    => ['データ登録・読込',      'database-zap',     'register.php'],
        'dashboard'   => ['受注一覧・ランキング',  'layout-dashboard', 'dashboard.php'],
        'flag-search' => ['フラグ(媒体)別検索',    'filter',           'flag-search.php'],
        'dokudome'    => ['独ドメげっと',          'globe',            'dokudome.php'],
        'accounts'    => ['アカウント管理',        'users',            'accounts.php'],
    ];
}
