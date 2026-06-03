<?php
declare(strict_types=1);

/**
 * api_banto_san 共通ブートストラップ
 * --------------------------------------------------------------------------
 * 設定読み込み / DB接続・スキーマ / セッション / 認証・認可ヘルパ /
 * Google OAuth ヘルパ をまとめる。各ページの先頭で require する。
 */

mb_internal_encoding('UTF-8');

// 致命的エラーを白画面の代わりに表示（診断用）。
register_shutdown_function(static function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); }
        echo "\n<pre style=\"background:#fff;color:#b42318;padding:12px;margin:12px;border:2px solid #b42318;white-space:pre-wrap;font-size:13px;z-index:99999;position:relative\">";
        echo "API番頭さん エラー:\n" . htmlspecialchars((string) $e['message'], ENT_QUOTES) . "\n"
           . htmlspecialchars((string) $e['file'], ENT_QUOTES) . ' : ' . (int) $e['line'];
        echo "</pre>";
    }
});

const APP_NAME = 'API番頭さん';
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

/** 許可メールドメインの一覧（未設定なら空＝制限なし） */
function allowed_email_domains(): array
{
    $raw = trim((string) config('ALLOWED_EMAIL_DOMAINS', ''));
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_map(static fn($d) => ltrim($d, '@'), $parts);
}

/** そのメールアドレスがログイン許可ドメインに属するか（未設定なら全許可） */
function email_domain_allowed(string $email): bool
{
    $allowed = allowed_email_domains();
    if (!$allowed) {
        return true;
    }
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    return in_array(strtolower(substr($email, $at + 1)), $allowed, true);
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

function h($s = ''): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/**
 * 単色のラインアイコン（インラインSVG・依存なし）。currentColor を継承するので
 * 文字色に追従する。絵文字の代替として使う。size は px。
 */
function icon(string $name, int $size = 18): string
{
    static $paths = [
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'noren'     => '<line x1="2.5" y1="6" x2="21.5" y2="6"/><path d="M4.5 6V19H19.5V6"/><line x1="9.5" y1="19" x2="9.5" y2="12"/><line x1="14.5" y1="19" x2="14.5" y2="12"/><circle cx="12" cy="11" r="1.7"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'key'       => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'box'       => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'product'   => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 12.3-9.17 4.16a2 2 0 0 1-1.66 0L2 12.3"/><path d="m22 17.3-9.17 4.16a2 2 0 0 1-1.66 0L2 17.3"/>',
        'globe'     => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'refresh'   => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/>',
        'lock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'gear'      => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'chevron'   => '<polyline points="9 18 15 12 9 6"/>',
        'up'        => '<polyline points="18 15 12 9 6 15"/>',
        'down'      => '<polyline points="6 9 12 15 18 9"/>',
        'left'      => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'right'     => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'plus'      => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'grip'      => '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
    ];
    $body = $paths[$name] ?? '';
    if ($body === '') { return ''; }
    if ($name === 'grip') {
        return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' . $body . '</svg>';
    }
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

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
            site          TEXT    NOT NULL DEFAULT '',
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

    // 手動並び順（API名ごとの表示順）
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS catalog_pref (
            group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            name     TEXT    NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (group_id, name)
        )
    SQL);

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

    // プロジェクト箱（名前＋任意でOpenAIのproj紐付け）。コスト・管理キーも保持。
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS projects (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id           INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            name               TEXT    NOT NULL,
            product            TEXT    NOT NULL DEFAULT '',
            cost_type          TEXT    NOT NULL DEFAULT '',
            cost_account       TEXT    NOT NULL DEFAULT '',
            openai_project_id  TEXT    NOT NULL DEFAULT '',
            secret_enc         TEXT,
            secret_hint        TEXT,
            secret_fp          TEXT,
            monthly_cost       REAL,
            balance            REAL,
            currency           TEXT    NOT NULL DEFAULT 'USD',
            created_at         TEXT    NOT NULL,
            updated_at         TEXT    NOT NULL
        )
    SQL);

    // コスト取得キー（名前付きクレデンシャル）。プロダクト/箱から選んで使う。
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS credentials (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id           INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            name               TEXT    NOT NULL,
            cost_type          TEXT    NOT NULL DEFAULT '',
            cost_account       TEXT    NOT NULL DEFAULT '',
            openai_project_id  TEXT    NOT NULL DEFAULT '',
            secret_enc         TEXT,
            secret_hint        TEXT,
            secret_fp          TEXT,
            created_at         TEXT    NOT NULL,
            updated_at         TEXT    NOT NULL
        )
    SQL);

    $cols = $pdo->query('PRAGMA table_info(apis)')->fetchAll();
    $hasGroup = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'group_id') { $hasGroup = true; break; }
    }
    if (!$hasGroup) {
        $pdo->exec('ALTER TABLE apis ADD COLUMN group_id INTEGER REFERENCES groups(id)');
    }

    // 「キー × サイト × コスト」対応: apis.site を後付け。
    $hasSite = false;
    foreach ($pdo->query('PRAGMA table_info(apis)') as $c) {
        if ($c['name'] === 'site') { $hasSite = true; break; }
    }
    if (!$hasSite) {
        $pdo->exec("ALTER TABLE apis ADD COLUMN site TEXT NOT NULL DEFAULT ''");
    }

    // キー暗号化保存: secret_enc(暗号文) / secret_hint(伏字) / secret_fp(指紋)
    // コスト取得用のプロジェクトID(cost_project): 空なら組織全体、指定で按分
    $apiCols = array_column($pdo->query('PRAGMA table_info(apis)')->fetchAll(), 'name');
    foreach (['secret_enc' => 'TEXT', 'secret_hint' => 'TEXT', 'secret_fp' => 'TEXT', 'cost_project' => 'TEXT'] as $col => $type) {
        if (!in_array($col, $apiCols, true)) {
            $pdo->exec("ALTER TABLE apis ADD COLUMN $col $type");
        }
    }

    // usages に所属プロジェクト(project_id)を後付け
    $uCols = array_column($pdo->query('PRAGMA table_info(usages)')->fetchAll(), 'name');
    if (!in_array('project_id', $uCols, true)) {
        $pdo->exec('ALTER TABLE usages ADD COLUMN project_id INTEGER');
    }

    // projects.product を後付け
    $projCols = array_column($pdo->query('PRAGMA table_info(projects)')->fetchAll(), 'name');
    foreach (['product' => "TEXT NOT NULL DEFAULT ''", 'cost_type' => "TEXT NOT NULL DEFAULT ''", 'cost_account' => "TEXT NOT NULL DEFAULT ''", 'balance' => 'REAL', 'credential_id' => 'INTEGER'] as $col => $def) {
        if (!in_array($col, $projCols, true)) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN $col $def");
        }
    }

    // catalog_pref（プロダクトのメタ）に、プロダクト既定のコスト取得キーを後付け
    $prefCols = array_column($pdo->query('PRAGMA table_info(catalog_pref)')->fetchAll(), 'name');
    if (!in_array('credential_id', $prefCols, true)) {
        $pdo->exec('ALTER TABLE catalog_pref ADD COLUMN credential_id INTEGER');
    }

    // 既存 cost_project → projects 箱へ自動移行（projects が空のときだけ）
    if ((int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() === 0) {
        $now = now();
        $rows = $pdo->query("SELECT group_id, cost_project, MIN(name) AS pname FROM apis WHERE IFNULL(cost_project,'') <> '' GROUP BY group_id, cost_project")->fetchAll();
        $ins = $pdo->prepare('INSERT INTO projects (group_id, name, product, openai_project_id, created_at, updated_at) VALUES (:g,:n,:prod,:p,:c,:c)');
        $asg = $pdo->prepare('UPDATE usages SET project_id = :pid WHERE api_id IN (SELECT id FROM apis WHERE group_id = :g AND cost_project = :cp)');
        foreach ($rows as $r) {
            $ins->execute([':g' => $r['group_id'], ':n' => $r['cost_project'], ':prod' => $r['pname'], ':p' => $r['cost_project'], ':c' => $now]);
            $pid = (int) $pdo->lastInsertId();
            $asg->execute([':pid' => $pid, ':g' => $r['group_id'], ':cp' => $r['cost_project']]);
        }
    }

    return $pdo;
}

/* ------------------------------------------------------------------ *
 *  キーの暗号化（AES-256-GCM）。マスター鍵は config(APP_ENCRYPTION_KEY)。
 *  保存するのは暗号文のみ。DBが漏れても鍵が無ければ復号できない。
 * ------------------------------------------------------------------ */
function encryption_key(): ?string
{
    $b64 = trim((string) config('APP_ENCRYPTION_KEY', ''));
    if ($b64 === '') {
        return null;
    }
    $raw = base64_decode($b64, true);
    return ($raw !== false && strlen($raw) === 32) ? $raw : null;
}

function encryption_ready(): bool { return encryption_key() !== null; }

/** 平文 → base64(iv|tag|cipher)。鍵未設定/失敗なら null */
function encrypt_secret(string $plain): ?string
{
    $key = encryption_key();
    if ($key === null || !function_exists('openssl_encrypt')) {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $ct === false ? null : base64_encode($iv . $tag . $ct);
}

/** base64(iv|tag|cipher) → 平文。失敗なら null */
function decrypt_secret(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    $key = encryption_key();
    if ($key === null) {
        return null;
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}

/** 同じ鍵か判別するための指紋（値そのものは復元不可） */
function secret_fingerprint(string $plain): string { return substr(hash('sha256', $plain), 0, 16); }

/** 表示用の伏字（末尾4文字のみ） */
function secret_hint(string $plain): string
{
    $n = strlen($plain);
    return $n <= 4 ? str_repeat('•', max($n, 1)) : '••••' . substr($plain, -4);
}

/* ------------------------------------------------------------------ *
 *  コスト取得キー（名前付きクレデンシャル）。秘密値は復号して返さない。
 * ------------------------------------------------------------------ */
function list_credentials(int $gid): array
{
    $st = db()->prepare('SELECT id, name, cost_type, cost_account, openai_project_id, secret_hint, secret_fp FROM credentials WHERE group_id = :g ORDER BY name');
    $st->execute([':g' => $gid]);
    return $st->fetchAll();
}

function get_credential(int $gid, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM credentials WHERE id = :id AND group_id = :g');
    $st->execute([':id' => $id, ':g' => $gid]);
    return $st->fetch() ?: null;
}

/** クレデンシャルを作成/更新。$secret 空なら既存の秘密値を保持。新規で空なら null。戻り値: id */
function save_credential(int $gid, ?int $id, string $name, string $costType, string $account, string $openaiProj, string $secret): int
{
    $now = now();
    if ($id === null) {
        db()->prepare('INSERT INTO credentials (group_id, name, cost_type, cost_account, openai_project_id, created_at, updated_at) VALUES (:g,:n,:ct,:ca,:p,:c,:c)')
            ->execute([':g' => $gid, ':n' => $name, ':ct' => $costType, ':ca' => $account, ':p' => $openaiProj, ':c' => $now]);
        $id = (int) db()->lastInsertId();
    } else {
        db()->prepare('UPDATE credentials SET name=:n, cost_type=:ct, cost_account=:ca, openai_project_id=:p, updated_at=:u WHERE id=:id AND group_id=:g')
            ->execute([':n' => $name, ':ct' => $costType, ':ca' => $account, ':p' => $openaiProj, ':u' => $now, ':id' => $id, ':g' => $gid]);
    }
    if ($secret !== '' && encryption_ready()) {
        db()->prepare('UPDATE credentials SET secret_enc=:e, secret_hint=:h, secret_fp=:f WHERE id=:id AND group_id=:g')
            ->execute([':e' => encrypt_secret($secret), ':h' => secret_hint($secret), ':f' => secret_fingerprint($secret), ':id' => $id, ':g' => $gid]);
    }
    return $id;
}

function delete_credential(int $gid, int $id): void
{
    db()->prepare('UPDATE projects SET credential_id = NULL WHERE credential_id = :id AND group_id = :g')->execute([':id' => $id, ':g' => $gid]);
    db()->prepare('UPDATE catalog_pref SET credential_id = NULL WHERE credential_id = :id AND group_id = :g')->execute([':id' => $id, ':g' => $gid]);
    db()->prepare('DELETE FROM credentials WHERE id = :id AND group_id = :g')->execute([':id' => $id, ':g' => $gid]);
}

/** プロダクト既定のキーID（catalog_pref.credential_id） */
function product_credential_id(int $gid, string $product): ?int
{
    $st = db()->prepare('SELECT credential_id FROM catalog_pref WHERE group_id = :g AND name = :n');
    $st->execute([':g' => $gid, ':n' => $product]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? null : (int) $v;
}

/** プロダクト既定のキーを設定（catalog_pref を upsert、position は保持/既定0） */
function set_product_credential(int $gid, string $product, ?int $credId): void
{
    db()->prepare(
        'INSERT INTO catalog_pref (group_id, name, position, credential_id) VALUES (:g,:n,0,:c)
         ON CONFLICT(group_id, name) DO UPDATE SET credential_id = :c'
    )->execute([':g' => $gid, ':n' => $product, ':c' => $credId]);
}

/* ------------------------------------------------------------------ *
 *  プロジェクト箱（名前＋proj紐付け）と URL の所属管理
 * ------------------------------------------------------------------ */
function list_projects(int $gid): array
{
    $st = db()->prepare('SELECT * FROM projects WHERE group_id = :g ORDER BY name');
    $st->execute([':g' => $gid]);
    return $st->fetchAll();
}

function get_project(int $gid, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM projects WHERE id = :id AND group_id = :g');
    $st->execute([':id' => $id, ':g' => $gid]);
    return $st->fetch() ?: null;
}

function create_project(int $gid, string $name, string $projId, string $product = ''): int
{
    db()->prepare('INSERT INTO projects (group_id, name, product, openai_project_id, created_at, updated_at) VALUES (:g,:n,:prod,:p,:c,:c)')
        ->execute([':g' => $gid, ':n' => $name, ':prod' => $product, ':p' => $projId, ':c' => now()]);
    return (int) db()->lastInsertId();
}

/** プロダクト名(API名)の api 行を1つ返す。無ければ作成して id を返す。 */
function find_or_create_api(int $gid, string $product, string $provider = ''): int
{
    $st = db()->prepare('SELECT id FROM apis WHERE group_id = :g AND name = :n ORDER BY id LIMIT 1');
    $st->execute([':g' => $gid, ':n' => $product]);
    $id = $st->fetchColumn();
    if ($id) { return (int) $id; }
    db()->prepare('INSERT INTO apis (group_id, name, provider, created_at, updated_at) VALUES (:g,:n,:p,:c,:c)')
        ->execute([':g' => $gid, ':n' => $product, ':p' => $provider, ':c' => now()]);
    return (int) db()->lastInsertId();
}

/** 箱に「サイト(URL)」を手動で1件追加する。戻り値は usage id。 */
function add_manual_site(int $gid, int $projectId, string $product, string $site): int
{
    $apiId = find_or_create_api($gid, $product);
    db()->prepare('INSERT INTO usages (api_id, repo, file, line, snippet, project_id) VALUES (:a,:r,:f,NULL,:s,:p)')
        ->execute([':a' => $apiId, ':r' => $site, ':f' => $site, ':s' => '（手動登録）', ':p' => $projectId]);
    return (int) db()->lastInsertId();
}

/** URL(usage) 群を箱へ移動（null で未割当に戻す）。現在グループのusageのみ。 */
function assign_usages_to_project(int $gid, array $usageIds, ?int $projectId): int
{
    $ids = array_values(array_filter(array_map('intval', $usageIds)));
    if (!$ids) { return 0; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE usages SET project_id = ? WHERE id IN ($in) AND api_id IN (SELECT id FROM apis WHERE group_id = ?)";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$projectId], $ids, [$gid]));
    return $stmt->rowCount();
}

/** サイト（ファイルパス先頭ディレクトリ）配下の全URLを箱へ移動 */
function assign_site_to_project(int $gid, string $site, ?int $projectId): int
{
    $stmt = db()->prepare(
        'UPDATE usages SET project_id = :pid
         WHERE (file = :s OR file LIKE :sp) AND api_id IN (SELECT id FROM apis WHERE group_id = :g)'
    );
    $stmt->execute([':pid' => $projectId, ':s' => $site, ':sp' => $site . '/%', ':g' => $gid]);
    return $stmt->rowCount();
}

/** グループ内に保存済みの OpenAI Admin キー（箱優先、無ければエントリ）を1つ返す */
function group_openai_admin_key(int $gid): ?string
{
    foreach (['SELECT secret_enc FROM projects WHERE group_id = :g AND secret_enc IS NOT NULL LIMIT 1',
              "SELECT secret_enc FROM apis WHERE group_id = :g AND secret_enc IS NOT NULL AND LOWER(provider)='openai' LIMIT 1"] as $sql) {
        $st = db()->prepare($sql);
        $st->execute([':g' => $gid]);
        $enc = $st->fetchColumn();
        if ($enc) {
            $k = decrypt_secret((string) $enc);
            if ($k !== null) { return $k; }
        }
    }
    return null;
}

/**
 * 箱のコスト取得に使うキー（クレデンシャル）を優先順位で解決する。
 *   1) 箱が明示選択したキー(credential_id ＝ 上級者向け・通常は未設定)
 *   2) プロダクト既定のキー(catalog_pref.credential_id) ← 通常はこれ
 *   3) 箱の直接入力キー(secret_enc ＝ 旧データの保険)
 *   4) グループ内の任意のAdminキー（最後の保険・OpenAIのみ）
 * 箱の openai_project_id（識別子）は常に優先してプロジェクト絞り込みに使う。
 * 戻り値: ['cost_type','secret','cost_account','openai_project_id','source'] or null
 */
function resolve_cost_source(int $gid, array $project): ?array
{
    $boxProj = trim((string) ($project['openai_project_id'] ?? ''));
    $useCred = static function (array $cr, string $src) use ($boxProj): array {
        return [
            'cost_type'         => (string) $cr['cost_type'],
            'secret'            => decrypt_secret($cr['secret_enc'] ?? null),
            'cost_account'      => (string) $cr['cost_account'],
            // 箱側の識別子（プロジェクトID）があれば優先、無ければキー既定
            'openai_project_id' => $boxProj ?: (string) $cr['openai_project_id'],
            'source'            => $src,
        ];
    };
    // 1) 箱が明示選択したキー
    $cid = isset($project['credential_id']) && $project['credential_id'] !== null ? (int) $project['credential_id'] : null;
    if ($cid) {
        $cr = get_credential($gid, $cid);
        if ($cr) { return $useCred($cr, 'credential:' . $cr['name']); }
    }
    // 2) プロダクト既定のキー（通常はこれ）
    $pcid = product_credential_id($gid, (string) ($project['product'] ?? ''));
    if ($pcid) {
        $cr = get_credential($gid, $pcid);
        if ($cr) { return $useCred($cr, 'product:' . $cr['name']); }
    }
    // 3) 箱の直接入力キー（旧データの保険）
    $boxSecret = decrypt_secret($project['secret_enc'] ?? null);
    $boxType   = trim((string) ($project['cost_type'] ?? ''));
    if ($boxType === '') { $boxType = $boxProj !== '' ? 'openai' : ''; }
    if ($boxSecret !== null && $boxType !== '') {
        return ['cost_type' => $boxType, 'secret' => $boxSecret, 'cost_account' => trim((string) ($project['cost_account'] ?? '')),
                'openai_project_id' => $boxProj, 'source' => 'box'];
    }
    // 4) グループ内のAdminキー（OpenAIのみ・保険）
    if ($boxType === 'openai' || trim((string) ($project['openai_project_id'] ?? '')) !== '') {
        $k = group_openai_admin_key($gid);
        if ($k !== null) {
            return ['cost_type' => 'openai', 'secret' => $k, 'cost_account' => '',
                    'openai_project_id' => trim((string) ($project['openai_project_id'] ?? '')), 'source' => 'group'];
        }
    }
    return null;
}

/** 箱のコストを取得して保存。解決したクレデンシャルに従い各社へ振り分け。 */
function fetch_project_cost(int $gid, array $project): array
{
    $src = resolve_cost_source($gid, $project);
    if ($src === null) {
        throw new RuntimeException('この箱に使うキーが見つかりません。箱の編集で「使うキー」を選ぶか、プロダクトの既定キーを設定してください。');
    }
    $type    = $src['cost_type'];
    $secret  = $src['secret'];
    $account = $src['cost_account'];
    $balance = null;
    $project['openai_project_id'] = $src['openai_project_id'];   // 解決後の絞り込みIDを反映

    switch ($type) {
        case 'openai':
            $key = $secret ?? group_openai_admin_key($gid);
            if ($key === null) { throw new RuntimeException('OpenAI Admin キー(sk-admin-...)が見つかりません。箱の編集でキーを保存してください。'); }
            $c = cost_openai($key, trim((string) ($project['openai_project_id'] ?? '')));
            break;
        case 'twilio':
            if ($secret === null) { throw new RuntimeException('Twilioの Auth Token を箱の編集で保存してください。'); }
            $c = cost_twilio($account, $secret);
            try {
                $b = twilio_balance($account, $secret);
                $balance = $b['amount'];
                if (($c['currency'] ?? '') === '' && $b['currency'] !== '') { $c['currency'] = $b['currency']; }
            } catch (Throwable $e) {
                $c['note'] = ($c['note'] ?? '') . ' / 残高取得NG: ' . $e->getMessage();
            }
            break;
        default:
            throw new RuntimeException('この箱のコスト種別が未設定です。編集でコスト種別（OpenAI / Twilio 等）を選んでください。');
    }
    db()->prepare('UPDATE projects SET monthly_cost = :m, currency = :c, balance = COALESCE(:bal, balance), updated_at = :u WHERE id = :id AND group_id = :g')
        ->execute([':m' => $c['amount'], ':c' => $c['currency'], ':bal' => $balance, ':u' => now(), ':id' => (int) $project['id'], ':g' => $gid]);
    if ($balance !== null) { $c['note'] = ($c['note'] ?? '') . ' / 残高 ' . $c['currency'] . ' ' . number_format($balance, 2); }
    return $c;
}

/** Twilio 残高（Balance API）。['amount'=>float,'currency'=>string] */
function twilio_balance(string $sid, string $token): array
{
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Balance.json';
    $r = http_request('GET', $url, ['headers' => ['Authorization: Basic ' . base64_encode($sid . ':' . $token)]]);
    if ($r['status'] !== 200) { throw new RuntimeException('Twilio残高APIエラー (HTTP ' . $r['status'] . ')'); }
    $d = json_decode($r['body'], true);
    return ['amount' => (float) ($d['balance'] ?? 0), 'currency' => strtoupper((string) ($d['currency'] ?? 'USD'))];
}

/** Twilio 当月利用額（Account SID + Auth Token）。Usage Records API。 */
function cost_twilio(string $sid, string $token): array
{
    if ($sid === '' || $token === '') {
        throw new RuntimeException('Twilioは Account SID（アカウントID欄）と Auth Token（キー欄）が必要です。');
    }
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Usage/Records/ThisMonth.json?PageSize=200';
    $r = http_request('GET', $url, ['headers' => ['Authorization: Basic ' . base64_encode($sid . ':' . $token)]]);
    if ($r['status'] === 401) { throw new RuntimeException('Twilio認証に失敗しました（SID/Auth Tokenをご確認ください）。'); }
    if ($r['status'] !== 200) { throw new RuntimeException('Twilio APIエラー (HTTP ' . $r['status'] . ')。' . substr((string) $r['body'], 0, 200)); }
    $d = json_decode($r['body'], true);
    $records = $d['usage_records'] ?? [];
    $total = null;
    $sum = 0.0;
    $cur = '';
    $n = 0;
    foreach ($records as $rec) {
        $n++;
        $price = (float) ($rec['price'] ?? 0);
        if (!empty($rec['price_unit'])) { $cur = strtoupper((string) $rec['price_unit']); }
        if (($rec['category'] ?? '') === 'totalprice') {
            $total = $price;
        } else {
            $sum += $price;
        }
    }
    $amount = $total !== null ? $total : $sum;
    if ($cur === '') { $cur = 'USD'; }
    return ['amount' => round(abs($amount), 2), 'currency' => $cur, 'note' => "Twilio: {$n}レコード"];
}

/* ------------------------------------------------------------------ *
 *  コスト自動取得コネクタ（プラグイン式）。今は OpenAI に対応。
 * ------------------------------------------------------------------ */
/** そのプロバイダのコスト自動取得に対応しているか */
function cost_supported(string $provider): bool
{
    return in_array(strtolower(trim($provider)), ['openai'], true);
}

/**
 * エントリのコストを取得して ['amount'=>float,'currency'=>string] を返す。
 * 失敗時は分かりやすいメッセージで例外を投げる。
 */
function fetch_cost_for(array $api): array
{
    $provider = strtolower(trim((string) ($api['provider'] ?? '')));
    $key = decrypt_secret($api['secret_enc'] ?? null);
    if ($key === null) {
        if (!encryption_ready()) {
            throw new RuntimeException('暗号鍵(APP_ENCRYPTION_KEY)が未設定のため、保存キーを復号できません。');
        }
        throw new RuntimeException('このエントリにAPIキー(値)が保存されていません。編集画面でキーを保存してください。');
    }
    switch ($provider) {
        case 'openai': return cost_openai($key, trim((string) ($api['cost_project'] ?? '')) ?: null);
        default: throw new RuntimeException('このプロバイダのコスト自動取得には未対応です（現在 OpenAI のみ）。');
    }
}

/**
 * OpenAI 当月コスト（Admin キーが必要）。Costs API を使用。
 * $projectId 指定でそのプロジェクトのみ、空なら組織全体の合計。
 */
function cost_openai(string $key, ?string $projectId = null): array
{
    $start = strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');
    $url = 'https://api.openai.com/v1/organization/costs?start_time=' . $start . '&bucket_width=1d&limit=62';
    if ($projectId !== null && $projectId !== '') {
        // プロジェクト絞り込みは group_by=project_id を付け、結果側で該当IDのみ合計する
        $url .= '&project_ids=' . rawurlencode($projectId) . '&group_by=project_id';
    }
    $r = http_request('GET', $url, ['headers' => ['Authorization: Bearer ' . $key]]);

    if ($r['status'] === 401 || $r['status'] === 403) {
        throw new RuntimeException('OpenAIのコスト取得には組織の Admin キー(sk-admin-...) が必要です。通常のAPIキーでは取得できません（OpenAIの組織設定で発行してください）。');
    }
    if ($r['status'] !== 200) {
        throw new RuntimeException('OpenAI コストAPIエラー (HTTP ' . $r['status'] . ')。' . ($r['error'] ?? ''));
    }
    $d = json_decode($r['body'], true);
    $sum = 0.0;
    $cur = 'USD';
    $matched = 0;   // プロジェクト指定時、該当結果が見つかった数
    foreach (($d['data'] ?? []) as $bucket) {
        foreach (($bucket['results'] ?? []) as $res) {
            if ($projectId !== null && $projectId !== '') {
                if (($res['project_id'] ?? null) !== $projectId) {
                    continue;   // 指定プロジェクト以外は除外
                }
                $matched++;
            }
            $sum += (float) ($res['amount']['value'] ?? 0);
            if (!empty($res['amount']['currency'])) {
                $cur = strtoupper((string) $res['amount']['currency']);
            }
        }
    }
    if ($projectId !== null && $projectId !== '' && $matched === 0) {
        throw new RuntimeException('プロジェクト「' . $projectId . '」のコストが取得できませんでした。プロジェクトIDが正しいか、当月に課金があるかご確認ください。');
    }
    return ['amount' => round($sum, 2), 'currency' => $cur];
}

/* ------------------------------------------------------------------ *
 *  認証（セッション内のユーザー）
 * ------------------------------------------------------------------ */function current_user(): ?array
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
        'INSERT INTO apis (group_id, name, provider, site, status, monthly_cost, currency, billing_url, key_location, docs_url, owner, notes, detected_by, last_scanned, created_at, updated_at)
         VALUES (:gid,:name,:provider,:site,:status,:cost,:cur,:bill,:key,:docs,:owner,:notes,:det,:scan,:ca,:ua)'
    );
    $insUse = $pdo->prepare('INSERT INTO usages (api_id, repo, file, line, snippet) VALUES (:aid,:repo,:file,:line,:snip)');
    foreach ($samples as $s) {
        [$name,$provider,$status,$cost,$cur,$bill,$key,$docs,$owner,$notes,$usages] = $s;
        $insApi->execute([
            ':gid'=>$gid, ':name'=>$name, ':provider'=>$provider, ':site'=>'sample', ':status'=>$status, ':cost'=>$cost,
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
    $email = (string) ($info['email'] ?? '');

    // 社内ドメイン制限（設定時）。許可ドメイン以外はログインさせない。
    if (!email_domain_allowed($email)) {
        throw new RuntimeException('このアプリは許可された社内ドメイン（' . implode(', ', allowed_email_domains()) . '）のアカウントのみ利用できます。');
    }

    // 取得・保存するのは最小限（仕様書 §3）
    return login_with_profile(
        $sub,
        $email,
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

    // 「キー × サイト × コスト」: (name, key_location, site) 単位で1エントリ
    $findStmt = $pdo->prepare(
        "SELECT * FROM apis
         WHERE group_id = :g AND LOWER(name) = LOWER(:n)
           AND IFNULL(key_location,'') = :k AND IFNULL(site,'') = :s LIMIT 1"
    );
    $insStmt  = $pdo->prepare(
        'INSERT INTO apis (group_id, name, provider, site, status, currency, key_location, detected_by, last_scanned, created_at, updated_at)
         VALUES (:g,:name,:provider,:site,\'unknown\',\'JPY\',:key,:det,:scan,:ca,:ua)'
    );
    $updStmt  = $pdo->prepare(
        'UPDATE apis SET detected_by = :det, last_scanned = :scan, updated_at = :ua,
             provider = CASE WHEN provider = \'\' THEN :provider ELSE provider END
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

            // サイト = usages の repo（1スキャン内で一定）。明示指定があれば優先。
            $site = trim((string) ($a['site'] ?? ''));
            if ($site === '') {
                foreach (($a['usages'] ?? []) as $u) {
                    if (!empty($u['repo'])) { $site = (string) $u['repo']; break; }
                }
            }

            $findStmt->execute([':g' => $gid, ':n' => $name, ':k' => $keyLoc, ':s' => $site]);
            $existing = $findStmt->fetch();

            if ($existing) {
                $apiId = (int) $existing['id'];
                $updStmt->execute([':det' => $detStr, ':scan' => $nowTs, ':ua' => $nowTs, ':provider' => $provider, ':id' => $apiId]);
                $updated++;
            } else {
                $insStmt->execute([':g' => $gid, ':name' => $name, ':provider' => $provider, ':site' => $site, ':key' => $keyLoc, ':det' => $detStr, ':scan' => $nowTs, ':ca' => $nowTs, ':ua' => $nowTs]);
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

            // キー値の自動取り込み（取り込みON時のみ／暗号鍵が必要）
            if (!empty($a['secret']) && encryption_ready()) {
                $plain = (string) $a['secret'];
                $enc = encrypt_secret($plain);
                if ($enc !== null) {
                    $pdo->prepare('UPDATE apis SET secret_enc=:e, secret_hint=:h, secret_fp=:f WHERE id=:id')
                        ->execute([':e' => $enc, ':h' => secret_hint($plain), ':f' => secret_fingerprint($plain), ':id' => $apiId]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['created' => $created, 'updated' => $updated, 'usages' => $usageCount];
}

/** 旧形式（サイト未設定）のエントリを一括削除。移行用。戻り値: 削除件数 */
function delete_siteless_apis(int $gid): int
{
    $stmt = db()->prepare("DELETE FROM apis WHERE group_id = :g AND IFNULL(site,'') = ''");
    $stmt->execute([':g' => $gid]);
    return $stmt->rowCount();
}

/* ------------------------------------------------------------------ *
 *  手動並び順（API名ごと）
 * ------------------------------------------------------------------ */
/** name => position の連想配列 */
function group_positions(int $gid): array
{
    $st = db()->prepare('SELECT name, position FROM catalog_pref WHERE group_id = :g');
    $st->execute([':g' => $gid]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[$r['name']] = (int) $r['position']; }
    return $out;
}

/** 手動順での現在のグループ名リスト（position昇順→コスト降順） */
function ordered_group_names(int $gid): array
{
    $st = db()->prepare('SELECT name, SUM(IFNULL(monthly_cost,0)) AS tot FROM apis WHERE group_id = :g GROUP BY name');
    $st->execute([':g' => $gid]);
    $rows = $st->fetchAll();
    $pos = group_positions($gid);
    usort($rows, static function ($a, $b) use ($pos) {
        $pa = $pos[$a['name']] ?? PHP_INT_MAX;
        $pb = $pos[$b['name']] ?? PHP_INT_MAX;
        if ($pa !== $pb) { return $pa <=> $pb; }
        return ((float) $b['tot'] <=> (float) $a['tot']) ?: strcmp($a['name'], $b['name']);
    });
    return array_map(static fn($r) => $r['name'], $rows);
}

function set_group_position(int $gid, string $name, int $pos): void
{
    db()->prepare(
        'INSERT INTO catalog_pref (group_id, name, position) VALUES (:g,:n,:p)
         ON CONFLICT(group_id, name) DO UPDATE SET position = :p'
    )->execute([':g' => $gid, ':n' => $name, ':p' => $pos]);
}

/**
 * 現在の表示順（$orderedNames）を 1..N で確定保存し、$name を上(up)/下(down)へ1つ移動。
 */
function move_group(int $gid, array $orderedNames, string $name, string $dir): void
{
    $idx = array_search($name, $orderedNames, true);
    if ($idx === false) { return; }
    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($swap < 0 || $swap >= count($orderedNames)) { return; }
    [$orderedNames[$idx], $orderedNames[$swap]] = [$orderedNames[$swap], $orderedNames[$idx]];
    foreach ($orderedNames as $i => $n) { set_group_position($gid, $n, $i + 1); }
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
function run_scan_on_dir(int $gid, string $path, string $repo, bool $withSecrets = false): array
{
    $real = $path !== '' ? realpath($path) : false;
    if ($real === false || !is_dir($real)) {
        throw new RuntimeException('ディレクトリが見つかりません: ' . $path . "\n" . path_diagnostics($path));
    }
    $allowedRoot = config('SCAN_ALLOWED_ROOT');
    if ($allowedRoot) {
        $rootReal = realpath((string) $allowedRoot);
        if ($rootReal === false || strncmp($real, $rootReal, strlen($rootReal)) !== 0) {
            throw new RuntimeException('そのパスはスキャン許可ディレクトリ(SCAN_ALLOWED_ROOT)の外です。');
        }
    }
    $providers = load_providers(__DIR__ . '/scanner/providers.json');
    $found = scan_directory($real, $providers, ['repo' => ($repo !== '' ? $repo : basename($real)), 'secrets' => $withSecrets]);
    return merge_scan_results($gid, $found);
}

/**
 * パスが解決できない時に、原因特定の手がかりを文字列で返す（admin向け診断）。
 * 親フォルダの実際のサブフォルダ一覧や open_basedir 設定を示す。
 */
function path_diagnostics(string $path): string
{
    $bits = [];
    $ob = (string) ini_get('open_basedir');
    if ($ob !== '') {
        $bits[] = '⚠ open_basedir 制限あり（' . $ob . '）。この範囲外のフォルダはPHPから読めません。';
    }
    if (@file_exists($path)) {
        if (@is_dir($path)) {
            $bits[] = 'フォルダは存在しますが解決できません（' . (@is_readable($path) ? '読取可だがrealpath失敗' : '読取権限なし') . '）。';
        } else {
            $bits[] = 'これはフォルダではなくファイルです。フォルダのパスを指定してください。';
        }
    } else {
        $bits[] = '指定パスは存在しません。';
        $parent = dirname($path);
        if (@is_dir($parent)) {
            $entries = @scandir($parent);
            $dirs = is_array($entries)
                ? array_values(array_filter($entries, static fn($e) => $e !== '.' && $e !== '..' && @is_dir($parent . '/' . $e)))
                : [];
            $bits[] = '親フォルダ「' . $parent . '」内の実際のフォルダ: ' . (count($dirs) ? implode(' / ', array_slice($dirs, 0, 40)) : '(フォルダなし)');
        } else {
            $bits[] = '親フォルダ「' . $parent . '」も見えません（綴り違い or open_basedir 制限の可能性）。';
        }
    }
    $bits[] = '※ このアプリ自身の実パス: ' . (realpath(__DIR__) ?: __DIR__);
    return implode("\n", $bits);
}

/* ------------------------------------------------------------------ *
 *  アップロードのスキャン（PC/Gドライブのコード用）
 *  ZIP・単体ソースファイル(.py 等)・複数ファイルの混在を受け付ける。
 *  一時ディレクトリに安全に展開/保存して走査し、最後に削除する。
 * ------------------------------------------------------------------ */
const UPLOAD_MAX_BYTES   = 80 * 1024 * 1024;   // 合計80MB上限
const UPLOAD_MAX_ENTRIES = 8000;

/** $_FILES のエントリ（単体/複数いずれの形）を共通のリストへ正規化 */
function normalize_uploaded_files(array $f): array
{
    $out = [];
    if (!isset($f['name'])) {
        return $out;
    }
    if (is_array($f['name'])) {
        $n = count($f['name']);
        for ($i = 0; $i < $n; $i++) {
            if ((int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
    } elseif ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $out[] = $f;
    }
    return $out;
}

/** ZIP を指定ディレクトリへ安全に展開（Zip Slip対策・サイズ上限） */
function extract_zip_into(string $zipTmp, string $destDir, int &$totalBytes): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('このサーバでは ZIP 展開(ZipArchive)が使えません。ZIPにせず .py 等をそのままアップロードしてください。');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipTmp) !== true) {
        throw new RuntimeException('ZIPを開けませんでした。');
    }
    for ($i = 0; $i < $zip->numFiles && $i < UPLOAD_MAX_ENTRIES; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) {
            continue;
        }
        $name = str_replace('\\', '/', (string) $st['name']);
        if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
            continue;
        }
        $totalBytes += (int) $st['size'];
        if ($totalBytes > UPLOAD_MAX_BYTES) {
            $zip->close();
            throw new RuntimeException('展開サイズが大きすぎます（上限80MB）。');
        }
        $dest = $destDir . '/' . $name;
        if (str_ends_with($name, '/')) {
            @mkdir($dest, 0700, true);
            continue;
        }
        @mkdir(dirname($dest), 0700, true);
        $stream = $zip->getStream($st['name']);
        if ($stream) {
            $o = fopen($dest, 'wb');
            if ($o) {
                stream_copy_to_stream($stream, $o);
                fclose($o);
            }
            fclose($stream);
        }
    }
    $zip->close();
}

/**
 * アップロードされたファイル群（ZIP / 単体ソース / 複数）を走査してマージ。
 */
function run_scan_on_uploads(int $gid, array $filesEntry, string $repo, bool $withSecrets = false): array
{
    $files = normalize_uploaded_files($filesEntry);
    if (!$files) {
        throw new RuntimeException('ファイルが選択されていません。');
    }

    $tmpRoot = sys_get_temp_dir() . '/abt_up_' . bin2hex(random_bytes(6));
    if (!mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
        throw new RuntimeException('一時ディレクトリを作成できませんでした。');
    }

    $totalBytes = 0;
    $firstName  = '';
    try {
        foreach ($files as $file) {
            if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                continue;
            }
            $base = preg_replace('/[^A-Za-z0-9_.\-]/', '_', basename((string) $file['name'])) ?: 'upload';
            if ($firstName === '') {
                $firstName = $base;
            }
            if (preg_match('/\.zip$/i', $base)) {
                $sub = $tmpRoot . '/' . preg_replace('/\.zip$/i', '', $base);
                @mkdir($sub, 0700, true);
                extract_zip_into((string) $file['tmp_name'], $sub, $totalBytes);
            } else {
                $totalBytes += (int) ($file['size'] ?? 0);
                if ($totalBytes > UPLOAD_MAX_BYTES) {
                    throw new RuntimeException('合計サイズが大きすぎます（上限80MB）。');
                }
                $dst = $tmpRoot . '/' . $base;
                if (!move_uploaded_file((string) $file['tmp_name'], $dst)) {
                    @copy((string) $file['tmp_name'], $dst);
                }
            }
        }

        $label = $repo !== ''
            ? $repo
            : (count($files) === 1 ? preg_replace('/\.zip$/i', '', $firstName) : 'upload');
        $providers = load_providers(__DIR__ . '/scanner/providers.json');
        $found = scan_directory($tmpRoot, $providers, ['repo' => $label, 'secrets' => $withSecrets]);
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
