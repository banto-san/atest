<?php
declare(strict_types=1);

/**
 * api_banto_san 共通ブートストラップ
 * --------------------------------------------------------------------------
 * 設定読み込み / DB接続・スキーマ / セッション / 認証・認可ヘルパ /
 * Google OAuth ヘルパ をまとめる。各ページの先頭で require する。
 */

mb_internal_encoding('UTF-8');

const APP_NAME = 'API番人さん';
const DB_FILE  = __DIR__ . '/data.sqlite';

// status の選択肢（手動フィールド）
const STATUSES = [
    'active'     => '稼働中',
    'unused'     => '未使用',
    'unknown'    => '確認中',
    'deprecated' => '廃止予定',
];

// ロールと権限ランク（数値が大きいほど強い権限）
const ROLES = [
    'owner'  => 'オーナー',
    'admin'  => '管理者',
    'member' => 'メンバー',
    'viewer' => '閲覧者',
];
const ROLE_RANK = ['viewer' => 0, 'member' => 1, 'admin' => 2, 'owner' => 3];

// スキャナ検出エンジン（redact_secrets / scan_directory / load_providers を提供）
require_once __DIR__ . '/lib/scanner.php';

/* ------------------------------------------------------------------ *
 *  設定
 * ------------------------------------------------------------------ */
function config(string $key, $default = null)
{
    static $conf = null;
    if ($conf === null) {
        $conf = [];
        $file = __DIR__ . '/config.local.php';
        if (is_file($file)) {
            $c = require $file;
            if (is_array($c)) {
                $conf = $c;
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

function config_bool(string $key): bool
{
    $v = config($key, false);
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
}

/* ------------------------------------------------------------------ *
 *  セッション
 * ------------------------------------------------------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('apibanto');
    session_start();
}

/* ------------------------------------------------------------------ *
 *  小物ヘルパ
 * ------------------------------------------------------------------ */
function now(): string { return date('Y-m-d H:i:s'); }

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('不正なリクエストです (CSRF)。ページを再読み込みしてください。');
    }
}

function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $url): void { header('Location: ' . $url); exit; }

function redirect_self(): void
{
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    redirect(strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . $qs : ''));
}

/** アプリのベース URL（末尾スラッシュなし）を返す */
function app_base_url(): string
{
    $configured = config('APP_BASE_URL');
    if ($configured) {
        return rtrim((string) $configured, '/');
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') === '443')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

/** route 付きの index.php URL を組み立てる */
function app_url(string $route = '', array $params = []): string
{
    $url = app_base_url() . '/index.php';
    if ($route !== '') {
        $params = ['route' => $route] + $params;
    }
    return $params ? $url . '?' . http_build_query($params) : $url;
}

function google_redirect_uri(): string
{
    $configured = config('GOOGLE_REDIRECT_URI');
    return $configured ? (string) $configured : app_url('oauth2callback');
}

/* ------------------------------------------------------------------ *
 *  DB 初期化 / マイグレーション
 * ------------------------------------------------------------------ */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            google_sub  TEXT    NOT NULL UNIQUE,
            email       TEXT    NOT NULL DEFAULT '',
            name        TEXT    NOT NULL DEFAULT '',
            avatar_url  TEXT    NOT NULL DEFAULT '',
            created_at  TEXT    NOT NULL
        )
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS groups (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            created_by  INTEGER REFERENCES users(id),
            created_at  TEXT    NOT NULL
        )
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS memberships (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id  INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            user_id   INTEGER NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
            role      TEXT    NOT NULL DEFAULT 'member',
            created_at TEXT   NOT NULL,
            UNIQUE(group_id, user_id)
        )
    SQL);

    // メール招待。該当メールのユーザーがログインした時点で自動的にメンバー化する。
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS invites (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id   INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            email      TEXT    NOT NULL,
            role       TEXT    NOT NULL DEFAULT 'member',
            invited_by INTEGER REFERENCES users(id),
            created_at TEXT    NOT NULL,
            UNIQUE(group_id, email)
        )
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS apis (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id      INTEGER REFERENCES groups(id) ON DELETE CASCADE,
            name          TEXT    NOT NULL,
            provider      TEXT    NOT NULL DEFAULT '',
            status        TEXT    NOT NULL DEFAULT 'unknown',
            monthly_cost  REAL,
            currency      TEXT    NOT NULL DEFAULT 'JPY',
            billing_url   TEXT    NOT NULL DEFAULT '',
            key_location  TEXT    NOT NULL DEFAULT '',
            docs_url      TEXT    NOT NULL DEFAULT '',
            owner         TEXT    NOT NULL DEFAULT '',
            notes         TEXT    NOT NULL DEFAULT '',
            detected_by   TEXT    NOT NULL DEFAULT '',
            last_scanned  TEXT,
            created_at    TEXT    NOT NULL,
            updated_at    TEXT    NOT NULL
        )
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS usages (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            api_id  INTEGER NOT NULL REFERENCES apis(id) ON DELETE CASCADE,
            repo    TEXT    NOT NULL DEFAULT '',
            file    TEXT    NOT NULL DEFAULT '',
            line    INTEGER,
            snippet TEXT    NOT NULL DEFAULT ''
        )
    SQL);

    // CLIプッシュ用の個人用トークン。トークン本体は保存せずハッシュのみ（仕様書 §4）。
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS api_tokens (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash   TEXT    NOT NULL UNIQUE,
            label        TEXT    NOT NULL DEFAULT '',
            last_used_at TEXT,
            revoked      INTEGER NOT NULL DEFAULT 0,
            created_at   TEXT    NOT NULL
        )
    SQL);

    // 保存済みスキャン対象（option 1: ワンクリック再スキャン用）
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS scan_targets (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id        INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            label           TEXT    NOT NULL DEFAULT '',
            path            TEXT    NOT NULL,
            last_scanned_at TEXT,
            created_at      TEXT    NOT NULL
        )
    SQL);

    // v1（グループ無し）DB からのマイグレーション: apis.group_id を後付け。
    $cols = $pdo->query('PRAGMA table_info(apis)')->fetchAll();
    $hasGroup = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'group_id') { $hasGroup = true; break; }
    }
    if (!$hasGroup) {
        $pdo->exec('ALTER TABLE apis ADD COLUMN group_id INTEGER REFERENCES groups(id)');
    }

    return $pdo;
}

/* ------------------------------------------------------------------ *
 *  認証（セッション内のユーザー）
 * ------------------------------------------------------------------ */
function current_user(): ?array
{
    static $cached = false, $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return $user = null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $uid]);
    $row = $stmt->fetch();
    return $user = ($row ?: null);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect(app_url());   // ログインページへ
    }
    return $u;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Google から取得したプロフィールでユーザーを upsert し、セッションを確立。
 * 初回ログイン時は保留中の招待を反映し、所属が無ければ既定グループを作成する。
 */
function login_with_profile(string $sub, string $email, string $name, string $avatar): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_sub = :sub');
    $stmt->execute([':sub' => $sub]);
    $user = $stmt->fetch();

    if ($user) {
        $pdo->prepare('UPDATE users SET email=:e, name=:n, avatar_url=:a WHERE id=:id')
            ->execute([':e' => $email, ':n' => $name, ':a' => $avatar, ':id' => $user['id']]);
        $user['email'] = $email; $user['name'] = $name; $user['avatar_url'] = $avatar;
    } else {
        $pdo->prepare('INSERT INTO users (google_sub, email, name, avatar_url, created_at) VALUES (:s,:e,:n,:a,:c)')
            ->execute([':s' => $sub, ':e' => $email, ':n' => $name, ':a' => $avatar, ':c' => now()]);
        $uid = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $uid]);
        $user = $stmt->fetch();
    }

    $_SESSION['user_id'] = (int) $user['id'];

    accept_pending_invites($user);
    ensure_default_group($user);

    return $user;
}

/** メール一致の招待をメンバーシップへ変換する */
function accept_pending_invites(array $user): void
{
    if ($user['email'] === '') {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM invites WHERE LOWER(email) = LOWER(:e)');
    $stmt->execute([':e' => $user['email']]);
    foreach ($stmt->fetchAll() as $inv) {
        $role = array_key_exists($inv['role'], ROLES) ? $inv['role'] : 'member';
        $pdo->prepare('INSERT OR IGNORE INTO memberships (group_id, user_id, role, created_at) VALUES (:g,:u,:r,:c)')
            ->execute([':g' => $inv['group_id'], ':u' => $user['id'], ':r' => $role, ':c' => now()]);
        $pdo->prepare('DELETE FROM invites WHERE id = :id')->execute([':id' => $inv['id']]);
    }
}

/** 所属グループが無いユーザーに既定グループを作成（owner）。初回はサンプルAPIも投入 */
function ensure_default_group(array $user): void
{
    $pdo = db();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM memberships WHERE user_id = ' . (int) $user['id'])->fetchColumn();
    if ($count > 0) {
        return;
    }
    $gname = ($user['name'] !== '' ? $user['name'] : 'マイ') . 'のグループ';
    $pdo->prepare('INSERT INTO groups (name, created_by, created_at) VALUES (:n,:u,:c)')
        ->execute([':n' => $gname, ':u' => $user['id'], ':c' => now()]);
    $gid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO memberships (group_id, user_id, role, created_at) VALUES (:g,:u,\'owner\',:c)')
        ->execute([':g' => $gid, ':u' => $user['id'], ':c' => now()]);
    $_SESSION['current_group_id'] = $gid;

    // システム全体でまだ API が無ければ、デモ用サンプルをこのグループへ投入。
    if ((int) $pdo->query('SELECT COUNT(*) FROM apis')->fetchColumn() === 0) {
        seed_sample_apis($gid);
    }
}

function seed_sample_apis(int $gid): void
{
    $pdo = db();
    $now = now();
    $samples = [
        ['OpenAI API', 'OpenAI', 'active', 12000, 'JPY', 'https://platform.openai.com/usage', 'env: OPENAI_API_KEY', 'https://platform.openai.com/docs', '開発チーム', 'GPT利用。月により変動。',
            [['web-app', 'src/lib/ai.ts', 42, "const client = new OpenAI({ apiKey: process.env.OPENAI_API_KEY })"]]],
        ['Stripe', 'Stripe', 'active', 30, 'USD', 'https://dashboard.stripe.com/billing', 'env: STRIPE_SECRET_KEY', 'https://stripe.com/docs/api', '請求担当', '決済。固定費あり。',
            [['shop', 'api/checkout.php', 18, "\\Stripe\\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));"]]],
        ['Google Maps Platform', 'Google', 'unknown', null, 'JPY', 'https://console.cloud.google.com/billing', 'env: GOOGLE_MAPS_API_KEY', 'https://developers.google.com/maps', '', '金額未確認。地図表示で使用。',
            [['web-app', 'public/map.js', 7, 'key=GOOGLE_MAPS_API_KEY']]],
    ];
    $insApi = $pdo->prepare(
        'INSERT INTO apis (group_id, name, provider, status, monthly_cost, currency, billing_url, key_location, docs_url, owner, notes, detected_by, last_scanned, created_at, updated_at)
         VALUES (:gid,:name,:provider,:status,:cost,:cur,:bill,:key,:docs,:owner,:notes,:det,:scan,:ca,:ua)'
    );
    $insUse = $pdo->prepare('INSERT INTO usages (api_id, repo, file, line, snippet) VALUES (:aid,:repo,:file,:line,:snip)');
    foreach ($samples as $s) {
        [$name,$provider,$status,$cost,$cur,$bill,$key,$docs,$owner,$notes,$usages] = $s;
        $insApi->execute([
            ':gid'=>$gid, ':name'=>$name, ':provider'=>$provider, ':status'=>$status, ':cost'=>$cost,
            ':cur'=>$cur, ':bill'=>$bill, ':key'=>$key, ':docs'=>$docs, ':owner'=>$owner,
            ':notes'=>$notes, ':det'=>'sample', ':scan'=>$now, ':ca'=>$now, ':ua'=>$now,
        ]);
        $aid = (int) $pdo->lastInsertId();
        foreach ($usages as $u) {
            $insUse->execute([':aid'=>$aid, ':repo'=>$u[0], ':file'=>$u[1], ':line'=>$u[2], ':snip'=>$u[3]]);
        }
    }
}

/* ------------------------------------------------------------------ *
 *  グループ / メンバーシップ / 認可
 * ------------------------------------------------------------------ */
/** ログインユーザーが所属する全グループ（role 付き） */
function my_memberships(): array
{
    $u = current_user();
    if (!$u) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT g.id, g.name, m.role
         FROM memberships m JOIN groups g ON g.id = m.group_id
         WHERE m.user_id = :u ORDER BY g.name'
    );
    $stmt->execute([':u' => $u['id']]);
    return $stmt->fetchAll();
}

/** 現在のグループID。未設定・非所属なら所属グループの先頭に補正 */
function current_group_id(): ?int
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    $memberships = my_memberships();
    if (!$memberships) {
        return null;
    }
    $ids = array_map(static fn($m) => (int) $m['id'], $memberships);
    $cur = $_SESSION['current_group_id'] ?? null;
    if ($cur !== null && in_array((int) $cur, $ids, true)) {
        return (int) $cur;
    }
    $_SESSION['current_group_id'] = $ids[0];
    return $ids[0];
}

function set_current_group(int $gid): bool
{
    $ids = array_map(static fn($m) => (int) $m['id'], my_memberships());
    if (!in_array($gid, $ids, true)) {
        return false;   // 非所属グループへは切替不可（サーバ側チェック）
    }
    $_SESSION['current_group_id'] = $gid;
    return true;
}

function current_group(): ?array
{
    $gid = current_group_id();
    if ($gid === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM groups WHERE id = :id');
    $stmt->execute([':id' => $gid]);
    return $stmt->fetch() ?: null;
}

/** 指定グループにおけるログインユーザーのロール（非所属なら null） */
function role_in_group(int $gid): ?string
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    $stmt = db()->prepare('SELECT role FROM memberships WHERE group_id = :g AND user_id = :u');
    $stmt->execute([':g' => $gid, ':u' => $u['id']]);
    $r = $stmt->fetchColumn();
    return $r === false ? null : (string) $r;
}

function current_role(): ?string
{
    $gid = current_group_id();
    return $gid === null ? null : role_in_group($gid);
}

function role_rank(?string $role): int
{
    return $role !== null && isset(ROLE_RANK[$role]) ? ROLE_RANK[$role] : -1;
}

/** 指定グループで role 以上の権限を持つか */
function has_role_at_least(int $gid, string $role): bool
{
    return role_rank(role_in_group($gid)) >= (ROLE_RANK[$role] ?? 99);
}

/** 権限が無ければ 403 で停止（サーバ側の必須チェック） */
function require_role_at_least(int $gid, string $role): void
{
    if (!has_role_at_least($gid, $role)) {
        http_response_code(403);
        exit('この操作を行う権限がありません。');
    }
}

// 現在グループに対する簡易判定（カタログ閲覧/編集、メンバー管理、グループ削除）
function can_view(): bool   { return role_rank(current_role()) >= ROLE_RANK['viewer']; }
function can_edit(): bool   { return role_rank(current_role()) >= ROLE_RANK['member']; }
function can_manage(): bool { return role_rank(current_role()) >= ROLE_RANK['admin']; }
function can_delete_group(): bool { return current_role() === 'owner'; }

/* ------------------------------------------------------------------ *
 *  HTTP（Google OAuth 用。cURL 優先、無ければ stream）
 * ------------------------------------------------------------------ */
function http_request(string $method, string $url, array $opts = []): array
{
    $headers = $opts['headers'] ?? [];
    $body    = $opts['body']    ?? null;   // string

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['status' => 0, 'body' => '', 'error' => $err];
        }
        return ['status' => $code, 'body' => (string) $resp, 'error' => ''];
    }

    // フォールバック: stream context
    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return ['status' => $code, 'body' => $resp === false ? '' : $resp, 'error' => $resp === false ? 'request failed' : ''];
}

/* ------------------------------------------------------------------ *
 *  Google OAuth フロー
 * ------------------------------------------------------------------ */
function google_login_url(): string
{
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $params = [
        'client_id'     => (string) config('GOOGLE_CLIENT_ID'),
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
 * OAuth コールバック処理。成功でユーザーを返し、失敗で例外を投げる。
 */
function google_handle_callback(): array
{
    $state = $_GET['state'] ?? '';
    $sess  = $_SESSION['oauth_state'] ?? '';
    unset($_SESSION['oauth_state']);
    if ($state === '' || !hash_equals($sess, $state)) {
        throw new RuntimeException('state が一致しません（CSRFの疑い）。もう一度ログインしてください。');
    }
    if (isset($_GET['error'])) {
        throw new RuntimeException('Google認証がキャンセルされました: ' . (string) $_GET['error']);
    }
    $code = $_GET['code'] ?? '';
    if ($code === '') {
        throw new RuntimeException('認可コードがありません。');
    }

    // 1) コード → トークン交換
    $tok = http_request('POST', 'https://oauth2.googleapis.com/token', [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query([
            'code'          => $code,
            'client_id'     => (string) config('GOOGLE_CLIENT_ID'),
            'client_secret' => (string) config('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => google_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ]),
    ]);
    if ($tok['status'] !== 200) {
        throw new RuntimeException('トークン取得に失敗しました (HTTP ' . $tok['status'] . ')。' . $tok['error']);
    }
    $tokData = json_decode($tok['body'], true);
    $accessToken = $tokData['access_token'] ?? '';
    if ($accessToken === '') {
        throw new RuntimeException('アクセストークンが取得できませんでした。');
    }

    // 2) userinfo 取得（sub/email/name/picture）
    $ui = http_request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
        'headers' => ['Authorization: Bearer ' . $accessToken],
    ]);
    if ($ui['status'] !== 200) {
        throw new RuntimeException('ユーザー情報の取得に失敗しました (HTTP ' . $ui['status'] . ')。');
    }
    $info = json_decode($ui['body'], true);
    $sub  = (string) ($info['sub'] ?? '');
    if ($sub === '') {
        throw new RuntimeException('Google の安定ID(sub)が取得できませんでした。');
    }

    // 取得・保存するのは最小限（仕様書 §3）
    return login_with_profile(
        $sub,
        (string) ($info['email'] ?? ''),
        (string) ($info['name'] ?? ''),
        (string) ($info['picture'] ?? '')
    );
}

/* ------------------------------------------------------------------ *
 *  CLI 個人用トークン（push 用）。本体は保存せずハッシュのみ（仕様書 §4）。
 * ------------------------------------------------------------------ */
/** 新規トークンを発行し、本体（一度だけ表示用）を返す */
function issue_api_token(int $userId, string $label): string
{
    $plain = 'abt_' . bin2hex(random_bytes(24));   // 表示はこの一度きり
    db()->prepare('INSERT INTO api_tokens (user_id, token_hash, label, created_at) VALUES (:u,:h,:l,:c)')
        ->execute([':u' => $userId, ':h' => hash('sha256', $plain), ':l' => $label, ':c' => now()]);
    return $plain;
}

/** Bearer トークンを検証し、有効ならユーザー行を返す。last_used_at を更新。 */
function verify_api_token(string $plain): ?array
{
    if ($plain === '') {
        return null;
    }
    $hash = hash('sha256', $plain);
    $stmt = db()->prepare(
        'SELECT t.id AS token_id, u.* FROM api_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = :h AND t.revoked = 0'
    );
    $stmt->execute([':h' => $hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    db()->prepare('UPDATE api_tokens SET last_used_at = :t WHERE id = :id')
        ->execute([':t' => now(), ':id' => $row['token_id']]);
    return $row;
}

function list_api_tokens(int $userId): array
{
    $stmt = db()->prepare('SELECT id, label, last_used_at, revoked, created_at FROM api_tokens WHERE user_id = :u ORDER BY created_at DESC');
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}

function revoke_api_token(int $userId, int $tokenId): void
{
    db()->prepare('UPDATE api_tokens SET revoked = 1 WHERE id = :id AND user_id = :u')
        ->execute([':id' => $tokenId, ':u' => $userId]);
}

/* ------------------------------------------------------------------ *
 *  スキャン結果のマージ（in-app スキャン / push API 共通）
 * --------------------------------------------------------------------
 *  自動フィールド（usages / detected_by / last_scanned / 空なら provider・
 *  key_location）のみ更新し、手動フィールド（monthly_cost / notes / status /
 *  owner / currency / billing_url / docs_url）は保持する（仕様書 §4）。
 *  戻り値: ['created'=>n, 'updated'=>n, 'usages'=>n]
 * ------------------------------------------------------------------ */
function merge_scan_results(int $gid, array $apis): array
{
    $pdo = db();
    $created = $updated = $usageCount = 0;
    $nowTs = now();

    $findStmt = $pdo->prepare('SELECT * FROM apis WHERE group_id = :g AND LOWER(name) = LOWER(:n) LIMIT 1');
    $insStmt  = $pdo->prepare(
        'INSERT INTO apis (group_id, name, provider, status, currency, key_location, detected_by, last_scanned, created_at, updated_at)
         VALUES (:g,:name,:provider,\'unknown\',\'JPY\',:key,:det,:scan,:ca,:ua)'
    );
    $updStmt  = $pdo->prepare(
        'UPDATE apis SET detected_by = :det, last_scanned = :scan, updated_at = :ua,
             provider = CASE WHEN provider = \'\' THEN :provider ELSE provider END,
             key_location = CASE WHEN key_location = \'\' THEN :key ELSE key_location END
         WHERE id = :id'
    );
    $delUse = $pdo->prepare('DELETE FROM usages WHERE api_id = :id');
    $insUse = $pdo->prepare('INSERT INTO usages (api_id, repo, file, line, snippet) VALUES (:aid,:repo,:file,:line,:snip)');

    $pdo->beginTransaction();
    try {
        foreach ($apis as $a) {
            $name = trim((string) ($a['name'] ?? ($a['provider'] ?? '')));
            if ($name === '') {
                continue;
            }
            $provider = trim((string) ($a['provider'] ?? ''));
            $keyLoc   = trim((string) ($a['key_location'] ?? ''));
            $detected = $a['detected_by'] ?? [];
            $detStr   = is_array($detected) ? implode(', ', array_slice(array_unique($detected), 0, 20)) : (string) $detected;

            $findStmt->execute([':g' => $gid, ':n' => $name]);
            $existing = $findStmt->fetch();

            if ($existing) {
                $apiId = (int) $existing['id'];
                $updStmt->execute([':det' => $detStr, ':scan' => $nowTs, ':ua' => $nowTs, ':provider' => $provider, ':key' => $keyLoc, ':id' => $apiId]);
                $updated++;
            } else {
                $insStmt->execute([':g' => $gid, ':name' => $name, ':provider' => $provider, ':key' => $keyLoc, ':det' => $detStr, ':scan' => $nowTs, ':ca' => $nowTs, ':ua' => $nowTs]);
                $apiId = (int) $pdo->lastInsertId();
                $created++;
            }

            // usages は自動フィールド: 今回の検出結果で置き換える
            $delUse->execute([':id' => $apiId]);
            foreach (($a['usages'] ?? []) as $u) {
                $insUse->execute([
                    ':aid'  => $apiId,
                    ':repo' => (string) ($u['repo'] ?? ''),
                    ':file' => (string) ($u['file'] ?? ''),
                    ':line' => isset($u['line']) && $u['line'] !== '' ? (int) $u['line'] : null,
                    ':snip' => redact_secrets((string) ($u['snippet'] ?? '')),
                ]);
                $usageCount++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['created' => $created, 'updated' => $updated, 'usages' => $usageCount];
}

/* ------------------------------------------------------------------ *
 *  保存済みスキャン対象（option 1）
 * ------------------------------------------------------------------ */
function list_scan_targets(int $gid): array
{
    $stmt = db()->prepare('SELECT * FROM scan_targets WHERE group_id = :g ORDER BY label, id');
    $stmt->execute([':g' => $gid]);
    return $stmt->fetchAll();
}

function add_scan_target(int $gid, string $label, string $path): void
{
    db()->prepare('INSERT INTO scan_targets (group_id, label, path, created_at) VALUES (:g,:l,:p,:c)')
        ->execute([':g' => $gid, ':l' => $label, ':p' => $path, ':c' => now()]);
}

function delete_scan_target(int $gid, int $id): void
{
    db()->prepare('DELETE FROM scan_targets WHERE id = :id AND group_id = :g')->execute([':id' => $id, ':g' => $gid]);
}

function touch_scan_target(int $id): void
{
    db()->prepare('UPDATE scan_targets SET last_scanned_at = :t WHERE id = :id')->execute([':t' => now(), ':id' => $id]);
}

/* ------------------------------------------------------------------ *
 *  スキャン実行（ディレクトリ）— in-app スキャンの共通処理
 *  SCAN_ALLOWED_ROOT が設定されていればその配下のみ許可。
 *  戻り値: merge_scan_results の結果
 * ------------------------------------------------------------------ */
function run_scan_on_dir(int $gid, string $path, string $repo): array
{
    $real = $path !== '' ? realpath($path) : false;
    if ($real === false || !is_dir($real)) {
        throw new RuntimeException('ディレクトリが見つかりません: ' . $path);
    }
    $allowedRoot = config('SCAN_ALLOWED_ROOT');
    if ($allowedRoot) {
        $rootReal = realpath((string) $allowedRoot);
        if ($rootReal === false || strncmp($real, $rootReal, strlen($rootReal)) !== 0) {
            throw new RuntimeException('そのパスはスキャン許可ディレクトリ(SCAN_ALLOWED_ROOT)の外です。');
        }
    }
    $providers = load_providers(__DIR__ . '/scanner/providers.json');
    $found = scan_directory($real, $providers, ['repo' => ($repo !== '' ? $repo : basename($real))]);
    return merge_scan_results($gid, $found);
}

/* ------------------------------------------------------------------ *
 *  ZIP アップロードのスキャン（PC/Gドライブのコード用）
 *  アップロードされた zip を一時ディレクトリに安全に展開して走査する。
 * ------------------------------------------------------------------ */
function run_scan_on_zip(int $gid, array $file, string $repo): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('このサーバでは ZIP 展開(ZipArchive)が使えません。CLI/Pythonスキャナをご利用ください。');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('ファイルのアップロードに失敗しました。');
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        throw new RuntimeException('ZIPを開けませんでした。');
    }

    // 展開先の一時ディレクトリ
    $tmpRoot = sys_get_temp_dir() . '/abt_scan_' . bin2hex(random_bytes(6));
    if (!mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
        throw new RuntimeException('一時ディレクトリを作成できませんでした。');
    }

    $maxEntries = 8000;
    $maxBytes   = 80 * 1024 * 1024;   // 展開後 80MB 上限
    $totalBytes = 0;

    try {
        for ($i = 0; $i < $zip->numFiles && $i < $maxEntries; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $name = (string) $st['name'];
            // Zip Slip 対策: 絶対パス・.. を含むエントリは拒否
            $name = str_replace('\\', '/', $name);
            if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                continue;
            }
            $totalBytes += (int) $st['size'];
            if ($totalBytes > $maxBytes) {
                throw new RuntimeException('展開サイズが大きすぎます（上限80MB）。CLI/Pythonスキャナをご利用ください。');
            }
            $dest = $tmpRoot . '/' . $name;
            if (str_ends_with($name, '/')) {
                @mkdir($dest, 0700, true);
                continue;
            }
            @mkdir(dirname($dest), 0700, true);
            $stream = $zip->getStream($st['name']);
            if ($stream) {
                $out = fopen($dest, 'wb');
                if ($out) {
                    stream_copy_to_stream($stream, $out);
                    fclose($out);
                }
                fclose($stream);
            }
        }
        $zip->close();

        $label = $repo !== '' ? $repo : preg_replace('/\.zip$/i', '', (string) ($file['name'] ?? 'upload'));
        $providers = load_providers(__DIR__ . '/scanner/providers.json');
        $found = scan_directory($tmpRoot, $providers, ['repo' => $label]);
        $res = merge_scan_results($gid, $found);
    } finally {
        rrmdir($tmpRoot);
    }
    return $res;
}

/** ディレクトリを再帰削除 */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}
