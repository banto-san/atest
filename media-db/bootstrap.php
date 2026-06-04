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
            ['id' => 'u1', 'name' => 'システム管理者', 'loginId' => 'admin', 'password' => 'password'],
            ['id' => 'u2', 'name' => '営業担当',       'loginId' => 'sales', 'password' => '1234'],
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
    $data += ['users' => [], 'media' => [], 'clients' => []];
    return $data;
}

/** data.json へ排他ロック付きで保存 */
function save_data(array $data): bool
{
    $normalized = [
        'users'   => array_values($data['users']   ?? []),
        'media'   => array_values($data['media']   ?? []),
        'clients' => array_values($data['clients'] ?? []),
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
 *  画面共通：サイドメニュー定義
 *  キー => [表示名, lucideアイコン名, リンク先ファイル]
 * ------------------------------------------------------------------ */
function nav_items(): array
{
    return [
        'register'    => ['データ登録・読込',      'database-zap',     'register.php'],
        'dashboard'   => ['受注一覧・ランキング',  'layout-dashboard', 'dashboard.php'],
        'flag-search' => ['フラグ(媒体)別検索',    'filter',           'flag-search.php'],
        'accounts'    => ['アカウント管理',        'users',            'accounts.php'],
    ];
}
