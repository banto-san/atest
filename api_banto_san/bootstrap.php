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

/** USD→JPY 換算レート（config の USD_JPY_RATE、既定 150） */
function usd_jpy(): float
{
    $r = (float) config('USD_JPY_RATE', 150);
    return $r > 0 ? $r : 150.0;
}

/** 金額を JPY 換算（JPY/USD のみ対応、その他はそのまま） */
function to_jpy(float $amount, ?string $cur): float
{
    $cur = strtoupper((string) ($cur ?: 'JPY'));
    if ($cur === 'USD') { return $amount * usd_jpy(); }
    return $amount;
}

/** USD の金額の隣に出す「(約 ¥X)」ヒント（HTML）。JPY等は空文字。 */
function jpy_hint(?float $amount, ?string $cur): string
{
    if ($amount === null) { return ''; }
    if (strtoupper((string) ($cur ?: '')) !== 'USD') { return ''; }
    return '<span class="jpyhint">（JPY≈' . number_format($amount * usd_jpy()) . '）</span>';
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
        'help'      => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'menu'      => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'trash'     => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
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

/** ドット絵のアヒル（豆絞り手ぬぐい付き）マスコットを SVG で返す。$px=1ドットのサイズ。 */
function duck_svg(int $px = 8): string
{
    $grid = [
        '..................',
        '.........WWWWW....',
        '........WnWnWnW...',
        '........WWWWWWW...',
        '.........YYYYYY...',
        '........YYYYYYYY..',
        '........YYYYeYYYBB',
        '........YYYYYYYYBB',
        '.....YYYYYYYYYYY..',
        '...YYYYYYYYYYYYY..',
        '..YYYYYYYYYYYYYY..',
        '.YYYYggggYYYYYYY..',
        '.YYYYggggYYYYYYY..',
        '..YYYYYYYYYYYYYY..',
        '...YYYYYYYYYYYY...',
        '....oooooooooo....',
    ];
    $map = ['Y' => '#FFCE2B', 'g' => '#EFA417', 'o' => '#E2960F', 'e' => '#20303A',
            'B' => '#FF7A30', 'W' => '#FFFFFF', 'n' => '#1F3A8A'];
    $w = 18; $n = count($grid);
    $out = '<svg xmlns="http://www.w3.org/2000/svg" width="' . ($w * $px) . '" height="' . ($n * $px) . '" viewBox="0 0 ' . ($w * $px) . ' ' . ($n * $px) . '" shape-rendering="crispEdges" class="duck" aria-hidden="true">';
    foreach ($grid as $y => $row) {
        $len = strlen($row);
        for ($x = 0; $x < $len; $x++) {
            $ch = $row[$x];
            if (isset($map[$ch])) {
                $out .= '<rect x="' . ($x * $px) . '" y="' . ($y * $px) . '" width="' . $px . '" height="' . $px . '" fill="' . $map[$ch] . '"/>';
            }
        }
    }
    return $out . '</svg>';
}

/** プロダクト名/プロバイダ名から、ロゴ取得用のドメインを推定（既知のみ） */
function provider_domain(string $name): string
{
    $n = strtolower($name);
    $map = [
        'openai' => 'openai.com', 'anthropic' => 'anthropic.com', 'twilio' => 'twilio.com',
        'sendgrid' => 'sendgrid.com', 'stripe' => 'stripe.com', 'aws' => 'aws.amazon.com',
        'amazon' => 'aws.amazon.com', 'azure' => 'azure.microsoft.com', 'microsoft' => 'microsoft.com',
        'gcp' => 'cloud.google.com', 'google' => 'google.com', 'gemini' => 'deepmind.google',
        'dataforseo' => 'dataforseo.com', 'serpapi' => 'serpapi.com', 'here' => 'here.com',
        'tomtom' => 'tomtom.com', 'mapbox' => 'mapbox.com', 'vonage' => 'vonage.com', 'nexmo' => 'vonage.com',
        'slack' => 'slack.com', 'github' => 'github.com', 'line' => 'line.me', 'meta' => 'meta.com',
        'facebook' => 'facebook.com', 'cloudflare' => 'cloudflare.com', 'algolia' => 'algolia.com',
        'notion' => 'notion.so', 'spotify' => 'spotify.com', 'pinterest' => 'pinterest.com',
    ];
    foreach ($map as $k => $d) { if (strpos($n, $k) !== false) { return $d; } }
    return '';
}

/** 名前から決定的なブランド色 */
function badge_color(string $s): string
{
    $colors = ['#10a37f', '#f22f46', '#4285f4', '#ff9900', '#0078d4', '#635bff', '#e1306c',
               '#ff6b35', '#0ea5e9', '#8b5cf6', '#16a34a', '#ef4444', '#0aa5a5', '#d946ef'];
    return $colors[abs(crc32($s)) % count($colors)];
}

/** #rrggbb が明るい色か（白文字/黒文字の判定用） */
function badge_is_light(string $hex): bool
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) { return true; }
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160;
}

/**
 * プロダクト（会社）のロゴバッジ。
 * $color/$img でプロダクトごとに上書き可。未指定の既定は「白背景」。
 *   - $img があれば画像を表示
 *   - 無ければ 背景色（既定=白）＋頭文字、既知サービスはファビコンを重ねる
 */
function provider_badge(string $name, int $size = 34, ?string $color = null, ?string $img = null): string
{
    $name = trim($name) !== '' ? trim($name) : '?';
    $fs = (int) round($size * 0.5);
    $sz = 'width:' . $size . 'px;height:' . $size . 'px';
    if ($img !== null && trim($img) !== '') {
        return '<span class="plogo" style="' . $sz . ';background:#fff;border:1px solid var(--line)"><img class="full" src="' . h($img) . '" alt="" loading="lazy" onerror="this.remove()"></span>';
    }
    $bg = ($color !== null && trim($color) !== '') ? trim($color) : '#ffffff';
    $light = badge_is_light($bg);
    $txt = $light ? 'var(--accent)' : '#fff';
    $border = $light ? 'border:1px solid var(--line);' : '';
    $dom = provider_domain($name);
    $mono = h(mb_strtoupper(mb_substr($name, 0, 1)));
    $out = '<span class="plogo" style="' . $sz . ';background:' . h($bg) . ';color:' . $txt . ';' . $border . 'font-size:' . $fs . 'px">';
    $out .= '<span class="mono">' . $mono . '</span>';
    if ($dom !== '') {
        $out .= '<img src="https://www.google.com/s2/favicons?sz=64&domain=' . h($dom) . '" alt="" loading="lazy" onerror="this.remove()">';
    }
    return $out . '</span>';
}

function render_styles(): void { ?>
<link rel="icon" type="image/svg+xml" href="<?= h(app_base_url()) ?>/favicon.svg">
<style>
    :root {
        --bg:#eef5fc; --card:#fff; --line:#e3ebf3; --ink:#21303d;
        --muted:#8a93a0; --accent:#2f7ad6; --accent-d:#1f5fb0; --gold:#f5b81e;
        --ok-bg:#e7f6ec; --ok-ink:#1a7f43; --err-bg:#fdecec; --err-ink:#b42318;
        --radius:16px; --shadow:0 6px 24px rgba(31,41,55,.06);
    }
    * { box-sizing:border-box; }
    body { margin:0; background-color:var(--bg); color:var(--ink);
        background-image:linear-gradient(rgba(47,122,214,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(47,122,214,.05) 1px, transparent 1px);
        background-size:28px 28px;
        font-family:-apple-system,BlinkMacSystemFont,"Hiragino Kaku Gothic ProN","Noto Sans JP",Meiryo,sans-serif; line-height:1.6; }

    /* ===== モダン・サイドバー レイアウト（ダッシュボード用） ===== */
    .layout { display:flex; min-height:100vh; }
    .sidebar { width:218px; flex:0 0 218px; background:#fff; border-right:1px solid var(--line); padding:18px 14px; position:sticky; top:0; height:100vh; overflow:auto; }
    .sidebar .brand { display:flex; align-items:center; gap:8px; font-weight:800; font-size:17px; padding:10px 10px 14px; margin-bottom:6px;
        color:#fff; background:var(--accent); border-radius:12px;
        border-bottom:5px solid; border-image:repeating-linear-gradient(90deg,#e0a93b 0 14px,#fff 14px 28px) 1; }
    .sidebar .brand .ic { color:var(--gold); }
    .sidebar .navlabel { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; padding:14px 10px 6px; }
    .sidebar a.nav { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:12px; color:#46505e; text-decoration:none; font-size:14px; font-weight:600; margin-bottom:2px; }
    .sidebar a.nav:hover { background:#f3f5f9; color:var(--ink); }
    .sidebar a.nav.active { background:var(--accent); color:#fff; box-shadow:0 6px 16px rgba(47,122,214,.34); }
    .sidebar .who { display:flex; align-items:center; gap:8px; font-size:13px; padding:10px 8px; border-top:1px solid var(--line); margin-top:14px; }
    .sidebar .who img { width:30px; height:30px; border-radius:50%; }
    .sidebar .who img.banto-mini { background:#eef5fc; object-fit:contain; }
    .banto-footer { margin-top:12px; padding-top:10px; border-top:1px solid var(--line); font-size:11.5px; color:var(--muted); display:flex; align-items:center; gap:6px; }
    .banto-footer img { width:20px; height:20px; }
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
    .hero-main { flex:1 1 320px; background:linear-gradient(135deg,#1f5fb0,#2f7ad6 55%,#5aa6ec); color:#fff;
        border-top:4px solid var(--gold);
        border-radius:var(--radius); padding:22px 24px; box-shadow:var(--shadow); display:flex; flex-direction:column; }
    .hero-label { font-size:12px; letter-spacing:.04em; opacity:.85; }
    .hero-chart .hero-label { color:var(--muted); opacity:1; }
    .hero-amount { font-size:34px; font-weight:800; line-height:1.25; font-variant-numeric:tabular-nums; }
    .hero-amount .cur { font-size:18px; font-weight:700; opacity:.85; margin-right:4px; }
    .hero-amount.muted { color:#cfd6e6; }
    .jpyhint { font-size:.5em; font-weight:400; opacity:.7; margin-left:2px; white-space:nowrap; }
    /* ヒーローの残高表示（青背景の上なので金色＝視認性◎） */
    .hero-balance { margin-top:12px; font-size:21px; font-weight:800; color:var(--gold); font-variant-numeric:tabular-nums; text-shadow:0 1px 2px rgba(0,0,0,.18); }
    .hero-balance .lbl { display:block; font-size:11.5px; font-weight:700; letter-spacing:.04em; color:#fff; opacity:.9; margin-bottom:2px; text-shadow:none; }
    .hero-balance .cur { font-size:13px; font-weight:700; opacity:.9; margin-right:3px; }
    .hero-balance .sep { color:rgba(255,255,255,.45); margin:0 8px; font-weight:400; }
    /* プロダクト詳細：箱→サイト→ファイル（使用箇所）の入れ子表示 */
    .site-group { margin:7px 0 9px; }
    .site-head { font-size:13px; color:var(--ink); display:flex; align-items:center; gap:5px; }
    .site-head .sc { color:var(--accent); }
    .url-row { white-space:nowrap; padding:1px 0 1px 24px; font-size:12.5px; color:var(--muted); display:flex; align-items:center; gap:5px; }
    .url-row code { color:var(--ink); background:transparent; padding:0; }
    tr.site-row > td { background:#f6faff; }
    tr.site-row strong { color:var(--ink); }
    /* カテゴリ切替タブ（シンプルなピル型） */
    .noren-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:20px; }
    .noren-tab { border:1px solid var(--line); cursor:pointer; background:var(--card); color:var(--muted); font-weight:700; font-size:13px;
        padding:8px 14px; border-radius:999px; transition:background .12s, color .12s, border-color .12s; }
    .noren-tab:hover { border-color:var(--accent); color:var(--accent); }
    .noren-tab.active { background:var(--accent); color:#fff; border-color:var(--accent); }
    .noren-tab .nt-n { display:inline-block; margin-left:6px; background:rgba(0,0,0,.08); border-radius:999px; padding:0 7px; font-size:11px; }
    .noren-tab.active .nt-n { background:rgba(255,255,255,.25); }
    /* カテゴリ見出し（シンプルな下線見出し） */
    .noren-head { display:flex; align-items:center; gap:10px; margin:0 0 16px; padding:0 0 10px; border-bottom:2px solid var(--line); }
    .noren-head .noren-cloth { font-weight:800; font-size:15px; color:var(--ink); }
    /* 湯気の仕切り線 */
    .steam-hr { height:14px; margin:4px 0 18px; background-repeat:repeat-x; background-position:center;
        background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='60' height='14' viewBox='0 0 60 14'><path d='M0 9 Q15 0 30 9 T60 9' fill='none' stroke='%232f7ad6' stroke-width='2' opacity='0.45'/></svg>"); }
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
    /* コンパクト表示（サイト別URL数など、補助的な内訳を小さく見せる） */
    .compact-bars h3 { font-size:13px; font-weight:700; color:var(--muted); margin:0 0 6px; }
    .compact-bars .bar-row { margin:2px 0; font-size:11.5px; gap:8px; }
    .compact-bars .bar-row .nm { flex-basis:48%; }
    .compact-bars .bar-row .nm .ic { width:13px; height:13px; }
    .compact-bars .bar-track { height:6px; }
    .product-link { color:var(--accent); text-decoration:none; font-size:13px; word-break:break-all; }
    .product-link:hover { text-decoration:underline; }
    /* プロダクト一覧の「詳細」ピルボタン */
    a.detail-btn { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:999px; font-size:12.5px; font-weight:700;
        color:#fff; background:linear-gradient(135deg,var(--accent),#5aa6ec); text-decoration:none; box-shadow:0 3px 10px rgba(47,122,214,.30);
        white-space:nowrap; transition:transform .12s, box-shadow .12s; }
    a.detail-btn:hover { transform:translateY(-1px); box-shadow:0 5px 14px rgba(47,122,214,.42); }
    a.detail-btn .ic { transition:transform .12s; }
    a.detail-btn:hover .ic { transform:translateX(3px); }
    /* 会社ロゴ・バッジ */
    .plogo { position:relative; display:inline-flex; align-items:center; justify-content:center; border-radius:50%;
        color:#fff; font-weight:800; flex:0 0 auto; vertical-align:middle; box-shadow:0 2px 6px rgba(31,48,61,.15); }
    .plogo .mono { line-height:1; }
    .badge-btn { border:none; background:none; padding:0; margin:0; cursor:pointer; position:relative; display:inline-flex; line-height:0; }
    .badge-btn .badge-pen { position:absolute; right:-5px; bottom:-5px; width:17px; height:17px; border-radius:50%; background:var(--accent);
        display:flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.25); }
    .badge-btn .badge-pen .ic { color:#fff; }
    .plogo img { position:absolute; top:16%; left:16%; width:68%; height:68%; object-fit:contain; }
    .plogo img.full { position:absolute; inset:0; top:0; left:0; width:100%; height:100%; object-fit:contain; border-radius:50%; }
    /* プロダクト・カード一覧 */
    .card-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; margin-bottom:16px; }
    .pcard { display:flex; flex-direction:column; gap:10px; background:var(--card); border:1px solid var(--line);
        border-radius:var(--radius); padding:16px; box-shadow:var(--shadow); text-decoration:none; color:var(--ink);
        transition:transform .12s, box-shadow .12s; position:relative; }
    .pcard:hover { transform:translateY(-3px); box-shadow:0 12px 28px rgba(31,48,61,.12); }
    .pcard-top { display:flex; align-items:center; gap:10px; }
    .pcard-name { font-weight:800; font-size:15px; line-height:1.25; }
    .pcard-prov { font-size:11.5px; }
    .pcard-amt { font-size:22px; font-weight:800; font-variant-numeric:tabular-nums; color:#0f172a; }
    .pcard-amt small { font-size:12px; color:var(--muted); font-weight:600; }
    .pcard-meta { font-size:12.5px; color:var(--muted); display:flex; gap:10px; flex-wrap:wrap; margin-top:auto; }
    .pcard .detail-btn { align-self:flex-start; margin-top:4px; }
    .pcard-diff { font-size:12px; font-weight:700; }
    .pcard-diff.up { color:#dc2626; } .pcard-diff.down { color:#16a34a; }
    .pcard.over { border-color:#f3b4b4; box-shadow:0 6px 20px rgba(220,38,38,.12); }
    .pcard-warn { position:absolute; top:10px; right:10px; background:#fdecec; color:#b42318; font-size:11px; font-weight:700;
        padding:2px 8px; border-radius:999px; display:inline-flex; align-items:center; gap:3px; }
    .pcard-warn .ic { color:#b42318; }
    @media (max-width:560px){ .card-grid{ grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; } .pcard{ padding:13px; } .pcard-amt{ font-size:18px; } }
    .guide-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px; align-items:stretch; }
    .guide-card { height:100%; }
    @media (max-width:680px){ .guide-grid { grid-template-columns:1fr; } }
    .guide-card .guide-row { display:flex; gap:8px; font-size:13px; padding:6px 0; border-top:1px solid var(--line); }
    .guide-card .guide-row:first-of-type { border-top:none; }
    .guide-label { flex:0 0 84px; color:var(--muted); font-weight:700; }
    .fab { position:fixed; right:22px; bottom:22px; z-index:200; display:inline-flex; align-items:center; gap:8px;
        padding:12px 18px; border-radius:999px; border:none; background:var(--accent); color:#fff; font-weight:700; font-size:14px;
        box-shadow:0 10px 26px rgba(47,122,214,.42); cursor:pointer; }
    .fab:hover { background:var(--accent-d); }
    .fab .ic { color:#fff; }
    .fab.active { background:var(--gold); color:#3a2a05; box-shadow:0 10px 26px rgba(224,169,59,.45); }
    .fab.active .ic { color:#3a2a05; }
    @media (max-width:560px){ .fab .fab-label{ display:none; } .fab{ padding:14px; } }
    /* 右端からのドロワー（非モーダル：開いたままページ操作OK） */
    .drawer { position:fixed; top:0; right:0; height:100vh; width:300px; max-width:86vw; background:var(--card);
        border-left:1px solid var(--line); box-shadow:-10px 0 30px rgba(15,23,42,.14); z-index:240;
        transform:translateX(105%); transition:transform .25s ease; display:flex; flex-direction:column; }
    .drawer.open { transform:translateX(0); }
    .drawer-head { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--line); }
    .drawer-body { padding:14px 16px; overflow:auto; flex:1; }
    .drawer-body .field { margin-bottom:10px; }
    .drawer-body .field label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; }
    .drawer-body .field input, .drawer-body .field select { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; }
    .drawer-foot { display:flex; justify-content:flex-end; gap:8px; padding:14px 16px; border-top:1px solid var(--line); }
    @media (max-width:820px){ .drawer { width:280px; } }
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
    .caret { display:inline-flex; align-items:center; color:#475569; }
    .caret .ic { transition:transform .15s ease; }
    .caret.open .ic { transform:rotate(90deg); }
    .ic { vertical-align:-0.16em; flex:0 0 auto; }
    .brandlogo { height:24px; width:auto; flex:0 0 auto; vertical-align:-6px; }
    button .ic, a.btn .ic, .link .ic, .product-link .ic { vertical-align:-0.18em; }
    .sidebar a.nav .ic, .sidebar .brand .ic { vertical-align:-0.22em; }
    .drag-handle { cursor:grab; color:#94a3b8; margin-right:4px; user-select:none; display:inline-flex; align-items:center; }
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
    /* 共通モーダル（abtConfirm/abtPrompt/abtAlert）の銭湯テイスト ── #__abtModal 限定 */
    #__abtModal { width:min(440px,92vw); border:none; border-top:6px solid var(--gold);
        border-radius:16px; padding:0; overflow:hidden; box-shadow:0 24px 70px rgba(31,58,138,.35);
        animation:abtpop .18s ease-out; }
    #__abtModal::backdrop { background:rgba(31,58,138,.4); backdrop-filter:blur(2px); }
    @keyframes abtpop { from { transform:translateY(10px) scale(.96); opacity:0; } to { transform:none; opacity:1; } }
    #__abtModal .abt-head { display:flex; align-items:center; gap:11px; padding:16px 20px;
        font-weight:800; font-size:15px; color:var(--ink); border-bottom:1px solid var(--line);
        background:linear-gradient(180deg,#eef5fc,#fff); }
    #__abtModal .abt-ic { width:32px; height:32px; flex:none; display:inline-flex; align-items:center;
        justify-content:center; border-radius:50%; background:var(--accent); color:#fff;
        box-shadow:0 0 0 3px rgba(245,184,30,.5); }
    #__abtModal .modal-body { padding:18px 20px; color:var(--ink); }
    #__abtModal .modal-foot { padding:14px 20px; background:#fafcff; }
    #__abtModal .modal-foot button { min-width:88px; font-weight:700; }
    #__abtModal .modal-foot button.danger { background:#b42318; color:#fff; border-color:transparent; }
    #__abtModal .modal-body input[type=text] { border:1px solid var(--line); border-radius:10px; padding:9px 12px; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid .full { grid-column:1 / -1; }
    .field label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; }
    .field input, .field select, .field textarea { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; }
    .hint { font-size:11.5px; color:var(--muted); margin-top:3px; }
    .empty { text-align:center; color:var(--muted); padding:40px; background:#fff; border-radius:var(--radius); box-shadow:var(--shadow); }
    .note-cell { max-width:220px; }
    .login-box { max-width:420px; margin:8vh auto; background:var(--card); border:1px solid var(--line);
        border-radius:16px; padding:32px; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.06); }
    .duck-hero { display:flex; justify-content:center; }
    .duck-hero .duck { filter:drop-shadow(0 4px 8px rgba(47,122,214,.25)); image-rendering:pixelated; }
    .duck-hero img.duckimg { filter:drop-shadow(0 4px 8px rgba(47,122,214,.22)); height:auto; }
    .duck-bob { animation:duckBob 3.2s ease-in-out infinite; transform-origin:50% 80%; will-change:transform; }
    @keyframes duckBob {
        0%   { transform:translateY(0) rotate(-4deg); }
        25%  { transform:translateY(-5px) rotate(0deg); }
        50%  { transform:translateY(0) rotate(4deg); }
        75%  { transform:translateY(-5px) rotate(0deg); }
        100% { transform:translateY(0) rotate(-4deg); }
    }
    @media (prefers-reduced-motion:reduce){ .duck-bob{ animation:none; } }
    .gbtn { display:inline-flex; align-items:center; gap:10px; padding:11px 18px; border:1px solid var(--line);
        border-radius:10px; background:#fff; color:#1f2733; text-decoration:none; font-weight:600; font-size:15px; }
    .gbtn:hover { background:#f8fafc; }
    .navtoggle{ display:none; margin-left:auto; border:none; background:rgba(255,255,255,.18); color:#fff; border-radius:9px; padding:6px 9px; cursor:pointer; align-items:center; }
    .navtoggle .ic{ color:#fff; }
    @media (max-width:820px){
        .layout{flex-direction:column;}
        .sidebar{width:auto;flex:none;height:auto;position:static;display:block;gap:0;border-right:none;border-bottom:1px solid var(--line);padding:8px 10px;}
        .sidebar .brand{display:flex;align-items:center;gap:8px;margin:0;padding:10px 12px;border-bottom:none;border-image:none;border-radius:10px;font-size:16px;}
        .navtoggle{display:inline-flex;}
        .sidebar .navlabel{display:none;}
        /* 既定は閉じる：ブランドだけ表示 */
        .sidebar a.nav, .sidebar .who{display:none;}
        /* 開いたとき：暖簾風ドロップダウン */
        .sidebar.open{background:#1f5fb0;border-radius:0 0 14px 14px;padding-bottom:12px;}
        .sidebar.open .brand{border-bottom:6px solid;border-image:repeating-linear-gradient(90deg,#e0a93b 0 16px,#fff 16px 32px) 1;border-radius:10px 10px 0 0;margin-bottom:8px;}
        .sidebar.open a.nav{display:flex;width:auto;margin:5px 10px;padding:11px 14px;font-size:14px;color:#eef1fb;background:rgba(255,255,255,.07);border-radius:10px;}
        .sidebar.open a.nav .ic{color:#dbe2f5;}
        .sidebar.open a.nav.active{background:var(--gold);color:#3a2a05;box-shadow:none;}
        .sidebar.open a.nav.active .ic{color:#3a2a05;}
        .sidebar.open .who{display:flex;margin:8px 10px 0;color:#dbe2f5;border-top:1px solid rgba(255,255,255,.15);padding-top:10px;}
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
<script>
/* 共通モーダル（ブラウザ標準の confirm/prompt/alert の置き換え）。全ページで利用可。 */
(function () {
    if (window.abtConfirm) { return; }
    function build() {
        var dlg = document.createElement('dialog');
        dlg.id = '__abtModal';
        dlg.style.maxWidth = '440px';
        var steam = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 13c-1.6-1.4-1.6-3 0-4.4S8.6 5 7 3.6"/><path d="M12 13c-1.6-1.4-1.6-3 0-4.4S13.6 5 12 3.6"/><path d="M17 13c-1.6-1.4-1.6-3 0-4.4S18.6 5 17 3.6"/><path d="M4 17.5h16"/><path d="M5 20.5h14"/></svg>';
        dlg.innerHTML =
            '<div class="abt-head"><span class="abt-ic">' + steam + '</span><span data-h>確認</span></div>' +
            '<div class="modal-body"><div data-msg style="white-space:pre-wrap;line-height:1.6"></div>' +
            '<input type="text" data-inp style="display:none;margin-top:12px;width:100%"></div>' +
            '<div class="modal-foot"><button type="button" data-cancel>キャンセル</button>' +
            '<button type="button" class="primary" data-ok>OK</button></div>';
        document.body.appendChild(dlg);
        return dlg;
    }
    function modal(o) {
        var dlg = document.getElementById('__abtModal') || build();
        dlg.querySelector('[data-h]').textContent = o.title || '確認';
        dlg.querySelector('[data-msg]').textContent = o.message || '';
        var inp = dlg.querySelector('[data-inp]');
        if (o.prompt) { inp.style.display = ''; inp.value = o.def || ''; } else { inp.style.display = 'none'; }
        var ok = dlg.querySelector('[data-ok]'), cancel = dlg.querySelector('[data-cancel]');
        ok.textContent = o.okText || 'OK';
        ok.className = o.danger ? 'danger' : 'primary';
        cancel.style.display = o.alert ? 'none' : '';
        function done() { ok.onclick = null; cancel.onclick = null; inp.onkeydown = null; }
        ok.onclick = function () { var v = o.prompt ? inp.value : true; done(); dlg.close(); if (o.onOk) { o.onOk(v); } };
        cancel.onclick = function () { done(); dlg.close(); };
        inp.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); ok.click(); } };
        dlg.showModal();
        if (o.prompt) { inp.focus(); inp.select(); }
    }
    window.abtConfirm = function (message, onOk, opts) {
        opts = opts || {};
        // 削除・失効など破壊的な操作は OK ボタンを赤に（明示指定があればそれを優先）
        var danger = (opts.danger !== undefined) ? opts.danger : /削除|失効|取り消/.test(message || '');
        modal({ message: message, onOk: onOk, title: opts.title || '確認', okText: opts.okText, danger: danger });
    };
    window.abtPrompt = function (message, def, onOk, opts) {
        opts = opts || {};
        modal({ message: message, prompt: true, def: def, title: opts.title || '入力', danger: false,
            onOk: function (v) { v = (v || '').trim(); if (v !== '') { onOk(v); } } });
    };
    window.abtAlert = function (message, opts) {
        opts = opts || {};
        modal({ message: message, alert: true, danger: false, title: opts.title || 'お知らせ', okText: '閉じる' });
    };
    /* インラインフォーム用: onsubmit="return abtConfirmForm(this,'メッセージ')" */
    window.abtConfirmForm = function (form, message, opts) {
        abtConfirm(message, function () { form.submit(); }, opts);
        return false;
    };
})();
</script>
<?php }

/** 共通サイドバー（全ページ共用）。$active: dashboard/scan/tokens/manage/guide/groups */
function render_sidebar(string $active = ''): void
{
    $user = current_user() ?? [];
    $role = current_role();
    $a = static fn(string $k): string => $active === $k ? ' active' : '';
    ?>
<aside class="sidebar">
    <div class="brand"><img class="brandlogo" src="<?= h(app_base_url()) ?>/logo.svg" alt=""> <?= h(APP_NAME) ?><button class="navtoggle" type="button" aria-label="メニュー" onclick="this.closest('.sidebar').classList.toggle('open')"><?= icon('menu', 22) ?></button></div>
    <div class="navlabel">メニュー</div>
    <a class="nav<?= $a('dashboard') ?>" href="index.php"><?= icon('dashboard') ?> ダッシュボード</a>
    <?php if (can_manage()): ?><a class="nav<?= $a('scan') ?>" href="<?= h(app_url('scan')) ?>"><?= icon('search') ?> スキャン</a><?php endif; ?>
    <a class="nav<?= $a('tokens') ?>" href="<?= h(app_url('tokens')) ?>"><?= icon('key') ?> トークン</a>
    <?php if (can_edit()): ?><a class="nav<?= $a('manage') ?>" href="<?= h(app_url('manage')) ?>"><?= icon('gear') ?> 管理</a><?php endif; ?>
    <?php if (can_edit()): ?><a class="nav<?= $a('accounts') ?>" href="<?= h(app_url('accounts')) ?>"><?= icon('lock') ?> アカウント管理</a><?php endif; ?>
    <a class="nav<?= $a('myaccounts') ?>" href="<?= h(app_url('myaccounts')) ?>"><?= icon('key') ?> 個人アカウント</a>
    <a class="nav<?= $a('guide') ?>" href="<?= h(app_url('guide')) ?>"><?= icon('help') ?> キーの取得ガイド</a>
    <a class="nav<?= $a('groups') ?>" href="groups.php"><?= icon('users') ?> グループ管理</a>
    <div class="navlabel">アカウント</div>
    <div class="who">
        <?php if (!empty($user['avatar_url'])): ?><img src="<?= h($user['avatar_url']) ?>" alt=""><?php else: ?><img src="<?= h(app_base_url()) ?>/duck2.png" alt="" class="banto-mini"><?php endif; ?>
        <div>
            <div style="font-weight:700;font-size:13px"><?= h(($user['name'] ?? '') ?: ($user['email'] ?? '')) ?></div>
            <?php if ($role !== null): ?><div class="role-badge"><?= h(ROLES[$role] ?? $role) ?></div><?php endif; ?>
        </div>
    </div>
    <a class="nav" href="<?= h(app_url('logout')) ?>"><?= icon('logout') ?> ログアウト</a>
    <div class="banto-footer"><img src="<?= h(app_base_url()) ?>/duck2.png" alt="" class="banto-mini"> ♨ 入浴中</div>
</aside>
<?php
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
            url           TEXT    NOT NULL DEFAULT '',
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
            cost_note          TEXT    NOT NULL DEFAULT '',
            cost_breakdown     TEXT,
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

    // アカウント（ID・パスワード）管理。パスワードは暗号化保存。
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS accounts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id    INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            owner_email TEXT    NOT NULL DEFAULT '',
            category    TEXT    NOT NULL DEFAULT '',
            service     TEXT    NOT NULL,
            login_id    TEXT    NOT NULL DEFAULT '',
            secret_enc  TEXT,
            secret_hint TEXT,
            secret_fp   TEXT,
            url         TEXT    NOT NULL DEFAULT '',
            notes       TEXT    NOT NULL DEFAULT '',
            logo_color  TEXT,
            logo_url    TEXT,
            updated_by  TEXT    NOT NULL DEFAULT '',
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        )
    SQL);

    // キー取得ガイド（プロバイダごとの「必要なもの・取得場所」。既定を上書き編集できる）
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS cost_guides (
            group_id   INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            ckey       TEXT    NOT NULL,
            title      TEXT    NOT NULL DEFAULT '',
            needs      TEXT    NOT NULL DEFAULT '',
            source     TEXT    NOT NULL DEFAULT '',
            url        TEXT    NOT NULL DEFAULT '',
            updated_at TEXT    NOT NULL,
            PRIMARY KEY (group_id, ckey)
        )
    SQL);

    // 月次コストスナップショット（箱単位・月ごと）。推移と前月比に使う。
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS cost_snapshots (
            group_id    INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            ym          TEXT    NOT NULL,
            project_id  INTEGER NOT NULL,
            product     TEXT    NOT NULL DEFAULT '',
            amount      REAL    NOT NULL DEFAULT 0,
            currency    TEXT    NOT NULL DEFAULT 'JPY',
            captured_at TEXT    NOT NULL,
            PRIMARY KEY (group_id, ym, project_id)
        )
    SQL);

    // 追加クレジット購入の記録（プロダクト単位の一時的な買い足し）
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS credit_purchases (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id   INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            product    TEXT    NOT NULL DEFAULT '',
            ym         TEXT    NOT NULL,
            amount     REAL    NOT NULL DEFAULT 0,
            currency   TEXT    NOT NULL DEFAULT 'JPY',
            note       TEXT    NOT NULL DEFAULT '',
            created_at TEXT    NOT NULL
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
    foreach (['secret_enc' => 'TEXT', 'secret_hint' => 'TEXT', 'secret_fp' => 'TEXT', 'cost_project' => 'TEXT', 'url' => "TEXT NOT NULL DEFAULT ''"] as $col => $type) {
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
    foreach (['product' => "TEXT NOT NULL DEFAULT ''", 'cost_type' => "TEXT NOT NULL DEFAULT ''", 'cost_account' => "TEXT NOT NULL DEFAULT ''", 'balance' => 'REAL', 'credential_id' => 'INTEGER', 'cost_note' => "TEXT NOT NULL DEFAULT ''", 'cost_breakdown' => 'TEXT'] as $col => $def) {
        if (!in_array($col, $projCols, true)) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN $col $def");
        }
    }

    // catalog_pref（プロダクトのメタ）に、プロダクト既定のコスト取得キーを後付け
    $prefCols = array_column($pdo->query('PRAGMA table_info(catalog_pref)')->fetchAll(), 'name');
    if (!in_array('credential_id', $prefCols, true)) {
        $pdo->exec('ALTER TABLE catalog_pref ADD COLUMN credential_id INTEGER');
    }
    foreach (['logo_color' => 'TEXT', 'logo_url' => 'TEXT', 'cost_alert' => 'REAL'] as $col => $type) {
        if (!in_array($col, $prefCols, true)) {
            $pdo->exec("ALTER TABLE catalog_pref ADD COLUMN $col $type");
        }
    }

    // groups にコスト一括更新の最終時刻（自動更新の間引きに使う）
    $grpCols = array_column($pdo->query('PRAGMA table_info(groups)')->fetchAll(), 'name');
    if (!in_array('last_cost_refresh', $grpCols, true)) {
        $pdo->exec('ALTER TABLE groups ADD COLUMN last_cost_refresh TEXT');
    }

    // accounts に「個人用（本人のみ閲覧）」の所有者メールを後付け。空=共有アカウント。
    $acctCols = array_column($pdo->query('PRAGMA table_info(accounts)')->fetchAll(), 'name');
    if (!in_array('owner_email', $acctCols, true)) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN owner_email TEXT NOT NULL DEFAULT ''");
    }
    // accounts にアイコンの見た目（背景色・画像URL）を後付け。
    foreach (['logo_color' => 'TEXT', 'logo_url' => 'TEXT'] as $col => $type) {
        if (!in_array($col, $acctCols, true)) {
            $pdo->exec("ALTER TABLE accounts ADD COLUMN $col $type");
        }
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

/**
 * プロダクトを丸ごと削除する。配下の箱・このプロダクト名のAPI（とURL）・
 * メタ(catalog_pref)・コスト履歴(cost_snapshots)・追加クレジット(credit_purchases)をまとめて消す。
 * 箱の中にあった「別プロダクト由来のURL」は削除せず未割当（project_id=NULL）に戻す。
 * 戻り値: ['apis'=>削除API数, 'boxes'=>削除箱数]
 */
function delete_product(int $gid, string $name): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 配下の箱ID
        $st = $pdo->prepare('SELECT id FROM projects WHERE group_id = :g AND product = :n');
        $st->execute([':g' => $gid, ':n' => $name]);
        $boxIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        // 箱の中のURLは一旦すべて未割当へ（別プロダクトのキーを巻き込み削除しないため）
        if ($boxIds) {
            $in = implode(',', array_fill(0, count($boxIds), '?'));
            $pdo->prepare("UPDATE usages SET project_id = NULL WHERE project_id IN ($in) AND api_id IN (SELECT id FROM apis WHERE group_id = ?)")
                ->execute(array_merge($boxIds, [$gid]));
        }

        // このプロダクト名のAPIを削除（usages は ON DELETE CASCADE で消える）
        $delApi = $pdo->prepare('DELETE FROM apis WHERE group_id = :g AND name = :n');
        $delApi->execute([':g' => $gid, ':n' => $name]);
        $nApi = $delApi->rowCount();

        // 箱を削除
        $delBox = $pdo->prepare('DELETE FROM projects WHERE group_id = :g AND product = :n');
        $delBox->execute([':g' => $gid, ':n' => $name]);
        $nBox = $delBox->rowCount();

        // メタ・履歴・クレジットを掃除
        $pdo->prepare('DELETE FROM catalog_pref WHERE group_id = :g AND name = :n')->execute([':g' => $gid, ':n' => $name]);
        $pdo->prepare('DELETE FROM cost_snapshots WHERE group_id = :g AND product = :n')->execute([':g' => $gid, ':n' => $name]);
        $pdo->prepare('DELETE FROM credit_purchases WHERE group_id = :g AND product = :n')->execute([':g' => $gid, ':n' => $name]);

        $pdo->commit();
        return ['apis' => $nApi, 'boxes' => $nBox];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * サイト名（＝ファイルの先頭フォルダ／手動サイトの登録名）をグループ内で一括変更する。
 * サイトは保存された実体ではなく usages.file の先頭セグメント等から算出されるため、
 * 該当 usages の file/repo を書き換える。戻り値: 変更した行数。
 */
function rename_site(int $gid, string $old, string $new): int
{
    $old = trim($old);
    $new = trim($new);
    if ($old === '' || $new === '' || $old === $new) { return 0; }
    $pdo = db();
    $st = $pdo->prepare('SELECT id, repo, file FROM usages WHERE api_id IN (SELECT id FROM apis WHERE group_id = :g)');
    $st->execute([':g' => $gid]);
    $rows = $st->fetchAll();
    $upd = $pdo->prepare('UPDATE usages SET repo = :r, file = :f WHERE id = :id');
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $file = (string) $r['file'];
            $repo = (string) $r['repo'];
            // 現在のサイト名（usage_site と同じ算出ロジック）
            $pos = strpos($file, '/');
            if ($pos !== false && $pos > 0) { $site = substr($file, 0, $pos); }
            else { $site = $repo !== '' ? $repo : '(root)'; }
            if ($site !== $old) { continue; }
            if ($pos !== false && $pos > 0) {
                $newFile = $new . substr($file, $pos);            // 先頭フォルダだけ置換（/以降は維持）
                $newRepo = ($repo === $old) ? $new : $repo;
            } else {
                $newFile = ($file === $old) ? $new : $file;       // 手動サイト等（スラッシュなし）
                $newRepo = ($repo === $old) ? $new : $repo;
            }
            if ($newFile !== $file || $newRepo !== $repo) {
                $upd->execute([':r' => $newRepo, ':f' => $newFile, ':id' => (int) $r['id']]);
                $n++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $n;
}

/* ------------------------------------------------------------------ *
 *  アカウント（ID・パスワード）管理。パスワードは暗号化保存。
 * ------------------------------------------------------------------ */
function list_accounts(int $gid): array
{
    // 共有アカウントのみ（owner_email='' = グループ共有。個人用は別関数で扱う）
    $st = db()->prepare("SELECT id, category, service, login_id, secret_hint, url, notes, logo_color, logo_url, updated_by, updated_at FROM accounts WHERE group_id = :g AND owner_email = '' ORDER BY category, service");
    $st->execute([':g' => $gid]);
    return $st->fetchAll();
}

function get_account(int $gid, int $id): ?array
{
    // 共有アカウントのみ取得（個人用は混在させない）
    $st = db()->prepare("SELECT * FROM accounts WHERE id = :id AND group_id = :g AND owner_email = ''");
    $st->execute([':id' => $id, ':g' => $gid]);
    return $st->fetch() ?: null;
}

function account_categories(int $gid): array
{
    $st = db()->prepare("SELECT DISTINCT category FROM accounts WHERE group_id = :g AND owner_email = '' AND category <> '' ORDER BY category");
    $st->execute([':g' => $gid]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/* ---- 個人用アカウント（本人のみ閲覧）。owner_email でグループ横断に本人のものだけを扱う ---- */

function list_my_accounts(string $owner): array
{
    $st = db()->prepare("SELECT id, category, service, login_id, secret_hint, url, notes, logo_color, logo_url, updated_at FROM accounts WHERE owner_email = :o AND owner_email <> '' ORDER BY category, service");
    $st->execute([':o' => $owner]);
    return $st->fetchAll();
}

function my_account_categories(string $owner): array
{
    $st = db()->prepare("SELECT DISTINCT category FROM accounts WHERE owner_email = :o AND owner_email <> '' AND category <> '' ORDER BY category");
    $st->execute([':o' => $owner]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** 個人用アカウントを作成/更新（本人のみ）。$password 空なら既存PWを保持。戻り値: id */
function save_my_account(int $gid, string $owner, ?int $id, string $category, string $service, string $login, string $url, string $notes, string $password, string $logoColor = '', string $logoUrl = ''): int
{
    $now = now();
    $lc = $logoColor !== '' ? $logoColor : null;
    $lu = $logoUrl !== '' ? $logoUrl : null;
    if ($id === null) {
        db()->prepare('INSERT INTO accounts (group_id, owner_email, category, service, login_id, url, notes, logo_color, logo_url, updated_by, created_at, updated_at) VALUES (:g,:o,:cat,:s,:l,:u,:n,:lc,:lu,:o,:c,:c)')
            ->execute([':g' => $gid, ':o' => $owner, ':cat' => $category, ':s' => $service, ':l' => $login, ':u' => $url, ':n' => $notes, ':lc' => $lc, ':lu' => $lu, ':c' => $now]);
        $id = (int) db()->lastInsertId();
    } else {
        db()->prepare("UPDATE accounts SET category=:cat, service=:s, login_id=:l, url=:u, notes=:n, logo_color=:lc, logo_url=:lu, updated_at=:t WHERE id=:id AND owner_email=:o AND owner_email <> ''")
            ->execute([':cat' => $category, ':s' => $service, ':l' => $login, ':u' => $url, ':n' => $notes, ':lc' => $lc, ':lu' => $lu, ':t' => $now, ':id' => $id, ':o' => $owner]);
    }
    if ($password !== '' && encryption_ready()) {
        db()->prepare("UPDATE accounts SET secret_enc=:e, secret_hint=:h, secret_fp=:f WHERE id=:id AND owner_email=:o AND owner_email <> ''")
            ->execute([':e' => encrypt_secret($password), ':h' => secret_hint($password), ':f' => secret_fingerprint($password), ':id' => $id, ':o' => $owner]);
    }
    return $id;
}

function delete_my_account(string $owner, int $id): void
{
    db()->prepare("DELETE FROM accounts WHERE id = :id AND owner_email = :o AND owner_email <> ''")->execute([':id' => $id, ':o' => $owner]);
}

/** 個人用アカウントの平文パスワードを返す（本人のみ） */
function reveal_my_account_password(string $owner, int $id): ?string
{
    $st = db()->prepare("SELECT secret_enc FROM accounts WHERE id = :id AND owner_email = :o AND owner_email <> ''");
    $st->execute([':id' => $id, ':o' => $owner]);
    $row = $st->fetch();
    if (!$row) { return null; }
    return decrypt_secret($row['secret_enc'] ?? null);
}

/** アカウントを作成/更新。$password 空なら既存PWを保持。戻り値: id */
function save_account(int $gid, ?int $id, string $category, string $service, string $login, string $url, string $notes, string $password, string $by, string $logoColor = '', string $logoUrl = ''): int
{
    $now = now();
    $lc = $logoColor !== '' ? $logoColor : null;
    $lu = $logoUrl !== '' ? $logoUrl : null;
    if ($id === null) {
        db()->prepare('INSERT INTO accounts (group_id, category, service, login_id, url, notes, logo_color, logo_url, updated_by, created_at, updated_at) VALUES (:g,:cat,:s,:l,:u,:n,:lc,:lu,:by,:c,:c)')
            ->execute([':g' => $gid, ':cat' => $category, ':s' => $service, ':l' => $login, ':u' => $url, ':n' => $notes, ':lc' => $lc, ':lu' => $lu, ':by' => $by, ':c' => $now]);
        $id = (int) db()->lastInsertId();
    } else {
        db()->prepare('UPDATE accounts SET category=:cat, service=:s, login_id=:l, url=:u, notes=:n, logo_color=:lc, logo_url=:lu, updated_by=:by, updated_at=:t WHERE id=:id AND group_id=:g')
            ->execute([':cat' => $category, ':s' => $service, ':l' => $login, ':u' => $url, ':n' => $notes, ':lc' => $lc, ':lu' => $lu, ':by' => $by, ':t' => $now, ':id' => $id, ':g' => $gid]);
    }
    if ($password !== '' && encryption_ready()) {
        db()->prepare('UPDATE accounts SET secret_enc=:e, secret_hint=:h, secret_fp=:f WHERE id=:id AND group_id=:g')
            ->execute([':e' => encrypt_secret($password), ':h' => secret_hint($password), ':f' => secret_fingerprint($password), ':id' => $id, ':g' => $gid]);
    }
    return $id;
}

function delete_account(int $gid, int $id): void
{
    db()->prepare('DELETE FROM accounts WHERE id = :id AND group_id = :g')->execute([':id' => $id, ':g' => $gid]);
}

/** アカウントの平文パスワードを返す（表示/コピー用。member以上が前提） */
function reveal_account_password(int $gid, int $id): ?string
{
    $a = get_account($gid, $id);
    if (!$a) { return null; }
    return decrypt_secret($a['secret_enc'] ?? null);
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

/** プロダクトメタ（logo_color/logo_url/credential_id）を name => row で返す */
function list_product_meta(int $gid): array
{
    $st = db()->prepare('SELECT name, credential_id, logo_color, logo_url, cost_alert FROM catalog_pref WHERE group_id = :g');
    $st->execute([':g' => $gid]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[$r['name']] = $r; }
    return $out;
}

/** 年月キー（'2026-06'）。$offset で過去/未来へ。 */
function month_key(int $offset = 0): string
{
    return date('Y-m', strtotime(date('Y-m-01') . " {$offset} month"));
}

/** 箱の現在の月額を当月スナップショットに記録（upsert） */
function snapshot_box(int $gid, array $project): void
{
    if (($project['monthly_cost'] ?? null) === null) { return; }
    db()->prepare(
        'INSERT INTO cost_snapshots (group_id, ym, project_id, product, amount, currency, captured_at)
         VALUES (:g,:ym,:pid,:prod,:amt,:cur,:at)
         ON CONFLICT(group_id, ym, project_id) DO UPDATE SET product=:prod, amount=:amt, currency=:cur, captured_at=:at'
    )->execute([
        ':g' => $gid, ':ym' => month_key(), ':pid' => (int) $project['id'],
        ':prod' => (string) ($project['product'] ?? ''), ':amt' => (float) $project['monthly_cost'],
        ':cur' => ($project['currency'] ?? 'JPY') ?: 'JPY', ':at' => now(),
    ]);
}

/** 全箱を当月スナップショットに記録。記録件数を返す。 */
function snapshot_all(int $gid): int
{
    $n = 0;
    foreach (list_projects($gid) as $p) {
        if ($p['monthly_cost'] !== null) { snapshot_box($gid, $p); $n++; }
    }
    return $n;
}

/** プロダクト×年月×通貨の合計スナップショット [product][ym][cur]=amount */
function product_month_snapshots(int $gid): array
{
    $st = db()->prepare('SELECT ym, product, currency, SUM(amount) amt FROM cost_snapshots WHERE group_id = :g GROUP BY ym, product, currency');
    $st->execute([':g' => $gid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['product']][(string) $r['ym']][(string) $r['currency']] = (float) $r['amt'];
    }
    return $out;
}

/** プロダクトの月次アラート閾値を保存（null で解除） */
function set_product_alert(int $gid, string $product, ?float $amount): void
{
    db()->prepare(
        'INSERT INTO catalog_pref (group_id, name, position, cost_alert) VALUES (:g,:n,0,:a)
         ON CONFLICT(group_id, name) DO UPDATE SET cost_alert = :a'
    )->execute([':g' => $gid, ':n' => $product, ':a' => $amount]);
}

/** 追加クレジット購入を記録 */
function add_credit_purchase(int $gid, string $product, float $amount, string $cur, string $note): int
{
    db()->prepare('INSERT INTO credit_purchases (group_id, product, ym, amount, currency, note, created_at) VALUES (:g,:p,:ym,:a,:c,:n,:t)')
        ->execute([':g' => $gid, ':p' => $product, ':ym' => month_key(), ':a' => $amount, ':c' => $cur ?: 'JPY', ':n' => $note, ':t' => now()]);
    return (int) db()->lastInsertId();
}

/** プロダクトのクレジット購入履歴（新しい順） */
function list_credit_purchases(int $gid, string $product): array
{
    $st = db()->prepare('SELECT * FROM credit_purchases WHERE group_id = :g AND product = :p ORDER BY created_at DESC');
    $st->execute([':g' => $gid, ':p' => $product]);
    return $st->fetchAll();
}

function delete_credit_purchase(int $gid, int $id): void
{
    db()->prepare('DELETE FROM credit_purchases WHERE id = :id AND group_id = :g')->execute([':id' => $id, ':g' => $gid]);
}

/** プロダクト×当月の追加クレジット合計（通貨別） [cur=>amount] */
function product_credit_this_month(int $gid, string $product): array
{
    $st = db()->prepare('SELECT currency, SUM(amount) amt FROM credit_purchases WHERE group_id = :g AND product = :p AND ym = :ym GROUP BY currency');
    $st->execute([':g' => $gid, ':p' => $product, ':ym' => month_key()]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(string) $r['currency']] = (float) $r['amt']; }
    return $out;
}

/** $_FILES['logo_file'] を uploads/logos に保存して公開URLを返す。無ければ null。 */
function save_uploaded_logo(int $gid, string $product): ?string
{
    $f = $_FILES['logo_file'] ?? null;
    if (!$f || (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) { return null; }
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) { throw new RuntimeException('画像アップロードに失敗しました（コード ' . (int) $f['error'] . '）。'); }
    if (($f['size'] ?? 0) > 2 * 1024 * 1024) { throw new RuntimeException('画像は2MB以内にしてください。'); }
    $info = @getimagesize($f['tmp_name']);
    if ($info === false) { throw new RuntimeException('画像として読み込めませんでした。'); }
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$info['mime']] ?? null;
    if ($ext === null) { throw new RuntimeException('PNG / JPEG / GIF / WebP のみ対応です。'); }
    $dir = __DIR__ . '/uploads/logos';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { throw new RuntimeException('保存先フォルダを作成できませんでした。'); }
    $fname = 'p' . substr(sha1($gid . '|' . $product), 0, 16) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) { throw new RuntimeException('画像を保存できませんでした（書き込み権限をご確認ください）。'); }
    return app_base_url() . '/uploads/logos/' . $fname . '?v=' . time();
}

/** 全箱のコストを一括取得（解決できる箱のみ）。['ok','fail','skip','errors'] を返す。 */
function refresh_all_costs(int $gid): array
{
    $ok = $fail = $skip = 0; $errors = [];
    foreach (list_projects($gid) as $p) {
        if (resolve_cost_source($gid, $p) === null) { $skip++; continue; }
        try { fetch_project_cost($gid, $p); $ok++; }
        catch (Throwable $e) { $fail++; $errors[] = '「' . (string) ($p['name'] ?? '') . '」: ' . $e->getMessage(); }
    }
    db()->prepare('UPDATE groups SET last_cost_refresh = :t WHERE id = :g')->execute([':t' => now(), ':g' => $gid]);
    return ['ok' => $ok, 'fail' => $fail, 'skip' => $skip, 'errors' => $errors];
}

/** 指定プロダクト配下の箱だけコスト一括取得。['ok','fail','skip','errors'] を返す。 */
function refresh_product_costs(int $gid, string $product): array
{
    $ok = $fail = $skip = 0; $errors = [];
    foreach (list_projects($gid) as $p) {
        if ((string) ($p['product'] ?? '') !== $product) { continue; }
        if (resolve_cost_source($gid, $p) === null) { $skip++; continue; }
        try { fetch_project_cost($gid, $p); $ok++; }
        catch (Throwable $e) { $fail++; $errors[] = '「' . (string) ($p['name'] ?? '') . '」: ' . $e->getMessage(); }
    }
    return ['ok' => $ok, 'fail' => $fail, 'skip' => $skip, 'errors' => $errors];
}

/** 最後の一括更新から $hours 時間以上経過しているか（自動更新の判定） */
function cost_refresh_stale(int $gid, int $hours = 6): bool
{
    $st = db()->prepare('SELECT last_cost_refresh FROM groups WHERE id = :g');
    $st->execute([':g' => $gid]);
    $v = $st->fetchColumn();
    if (!$v) { return true; }
    return (time() - strtotime((string) $v)) > $hours * 3600;
}

/** プロダクトのアイコン見た目（背景色／画像URL）を保存 */
function set_product_logo(int $gid, string $product, string $color, string $url): void
{
    db()->prepare(
        'INSERT INTO catalog_pref (group_id, name, position, logo_color, logo_url) VALUES (:g,:n,0,:c,:u)
         ON CONFLICT(group_id, name) DO UPDATE SET logo_color = :c, logo_url = :u'
    )->execute([':g' => $gid, ':n' => $product, ':c' => ($color !== '' ? $color : null), ':u' => ($url !== '' ? $url : null)]);
}

/* ------------------------------------------------------------------ *
 *  キー取得ガイド（プロバイダ別「必要なもの・取得場所」）。既定＋上書き。
 * ------------------------------------------------------------------ */
function default_guides(): array
{
    return [
        'openai' => ['title' => 'OpenAI（自動取得◯）',
            'needs'  => '種別=OpenAI / キー欄=Adminキー（sk-admin-… で始まる）。アカウントID欄は不要。プロジェクト別に集計したい場合は、各箱の「識別子」に proj_xxx を入れる。',
            'source' => 'OpenAI管理画面 → Settings → Organization → Admin keys で発行（組織オーナーのみ作成可）。proj_xxx は各プロジェクトの Settings → General に表示。',
            'url'    => 'https://platform.openai.com/settings/organization/admin-keys'],
        'anthropic' => ['title' => 'Anthropic / Claude（自動取得◯）',
            'needs'  => '種別=Anthropic / キー欄=Admin APIキー（sk-ant-admin… で始まる）。アカウントID欄は不要。',
            'source' => 'Anthropic Console → Settings → Admin keys（組織管理者のみ作成可）。',
            'url'    => 'https://console.anthropic.com/settings/admin-keys'],
        'twilio' => ['title' => 'Twilio（自動取得◯）',
            'needs'  => 'アカウントID欄=Account SID（ACで始まる）/ キー欄=Auth Token。',
            'source' => 'Twilio Console トップの「Account Info」に Account SID と Auth Token が表示。',
            'url'    => 'https://console.twilio.com/'],
        'dataforseo' => ['title' => 'DataForSEO（自動取得◯）',
            'needs'  => 'アカウントID欄=API login（メール）/ キー欄=API password（API専用パスワード。普段のログインPWとは別物）。',
            'source' => '管理画面の「API Access」ページに API login と API password が表示（再生成も可）。',
            'url'    => 'https://app.dataforseo.com/api-access'],
        'vonage' => ['title' => 'Vonage（自動取得◯／残高）',
            'needs'  => 'アカウントID欄=API Key / キー欄=API Secret。',
            'source' => 'Vonage API Dashboard トップの「API settings」に API key と API secret。',
            'url'    => 'https://dashboard.nexmo.com/'],
        'serpapi' => ['title' => 'SerpApi（自動取得◯／検索回数）',
            'needs'  => 'キー欄=API Key（アカウントID欄は空でOK）。',
            'source' => 'SerpApi ダッシュボードの「Your Account → Api Key」。',
            'url'    => 'https://serpapi.com/manage-api-key'],
        'here' => ['title' => 'HERE（手入力）',
            'needs'  => '自動取得は非対応。月額は手入力してください。',
            'source' => 'HERE platform の Usage / Billing を見て手入力。',
            'url'    => 'https://platform.here.com/'],
        'tomtom' => ['title' => 'TomTom（手入力）',
            'needs'  => '自動取得は非対応。月額は手入力してください。',
            'source' => 'TomTom Developer Dashboard の利用状況を参照。',
            'url'    => 'https://developer.tomtom.com/'],
        'google' => ['title' => 'Google Cloud（BigQuery・自動取得◯）',
            'needs'  => '種別=Google Cloud（BigQuery）/ アカウントID欄=BigQueryテーブル（project.dataset.gcp_billing_export_v1_XXXX）/ キー欄=サービスアカウントJSON。Maps・Vertex・Gemini等の有料Googleも全部ここに集約されます。',
            'source' => '①Cloud Billing →「BigQueryエクスポート」を有効化（データ蓄積に半日〜1日）②サービスアカウント作成し「BigQuery データ閲覧者」＋「BigQuery ジョブユーザー」を付与③JSONキーを発行。テーブル名はBigQueryのエクスポート先データセットで確認。',
            'url'    => 'https://console.cloud.google.com/billing'],
        'azure' => ['title' => 'Azure（将来対応）',
            'needs'  => '自動取得は将来対応予定（Cost Management API）。当面は手入力。',
            'source' => 'Azure Portal → コスト管理と請求。',
            'url'    => 'https://portal.azure.com/'],
    ];
}

/**
 * コスト種別(cost_type) → 入力ガイド（どの欄に何を入れるか＋取得元URL）。
 * 既定ガイド（静的・信頼できる文言）から作り、キー入力モーダルにその場で表示する用。
 */
function cred_guide_map(): array
{
    $g = default_guides();
    $alias = ['gcp_bq' => 'google'];   // セレクトの値→ガイドキーの読み替え
    $map = [];
    foreach (['openai', 'anthropic', 'twilio', 'dataforseo', 'vonage', 'serpapi', 'gcp_bq'] as $type) {
        $key = $alias[$type] ?? $type;
        if (isset($g[$key])) {
            $map[$type] = ['needs' => $g[$key]['needs'], 'url' => $g[$key]['url'], 'title' => $g[$key]['title']];
        }
    }
    return $map;
}

/** ガイド一覧（既定＋グループの上書きをマージ。custom=既定に無い独自項目） */
function list_guides(int $gid): array
{
    $def = default_guides();
    $ov  = [];
    $st = db()->prepare('SELECT * FROM cost_guides WHERE group_id = :g');
    $st->execute([':g' => $gid]);
    foreach ($st->fetchAll() as $r) { $ov[$r['ckey']] = $r; }
    $out = [];
    foreach ($def as $k => $e) {
        if (isset($ov[$k])) {
            $out[$k] = ['ckey' => $k, 'title' => $ov[$k]['title'], 'needs' => $ov[$k]['needs'], 'source' => $ov[$k]['source'], 'url' => $ov[$k]['url'], 'custom' => false, 'edited' => true];
        } else {
            $out[$k] = $e + ['ckey' => $k, 'custom' => false, 'edited' => false];
        }
    }
    foreach ($ov as $k => $r) {
        if (!isset($def[$k])) {
            $out[$k] = ['ckey' => $k, 'title' => $r['title'], 'needs' => $r['needs'], 'source' => $r['source'], 'url' => $r['url'], 'custom' => true, 'edited' => true];
        }
    }
    return $out;
}

function save_guide(int $gid, string $ckey, string $title, string $needs, string $source, string $url): void
{
    db()->prepare(
        'INSERT INTO cost_guides (group_id, ckey, title, needs, source, url, updated_at)
         VALUES (:g,:k,:t,:n,:s,:u,:at)
         ON CONFLICT(group_id, ckey) DO UPDATE SET title=:t, needs=:n, source=:s, url=:u, updated_at=:at'
    )->execute([':g' => $gid, ':k' => $ckey, ':t' => $title, ':n' => $needs, ':s' => $source, ':u' => $url, ':at' => now()]);
}

/** 上書きを削除（既定キーは既定に戻る／独自キーは消える） */
function delete_guide(int $gid, string $ckey): void
{
    db()->prepare('DELETE FROM cost_guides WHERE group_id = :g AND ckey = :k')->execute([':g' => $gid, ':k' => $ckey]);
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
        case 'anthropic':
            if ($secret === null) { throw new RuntimeException('Anthropic の Admin APIキー(sk-ant-admin...)を箱/プロダクトに保存してください。'); }
            $c = cost_anthropic($secret);
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
        case 'dataforseo':
            if ($account === '' || $secret === null) { throw new RuntimeException('DataForSEOは ログイン（アカウントID欄）と APIパスワード（キー欄）が必要です。'); }
            $c = cost_dataforseo($account, $secret);
            if (isset($c['balance'])) { $balance = $c['balance']; unset($c['balance']); }
            break;
        case 'vonage':
            if ($account === '' || $secret === null) { throw new RuntimeException('Vonageは API Key（アカウントID欄）と API Secret（キー欄）が必要です。'); }
            $b = cost_vonage($account, $secret);
            $balance = $b['amount'];
            $c = ['amount' => 0.0, 'currency' => $b['currency'], 'note' => 'Vonage: 残高のみ取得（当月利用額はAPI非対応）'];
            break;
        case 'serpapi':
            if ($secret === null) { throw new RuntimeException('SerpApiは API Key（キー欄）が必要です。'); }
            $c = cost_serpapi($secret);
            break;
        case 'gcp_bq':
            if ($secret === null) { throw new RuntimeException('Google Cloud は サービスアカウントJSON（キー欄）が必要です。'); }
            if ($account === '') { throw new RuntimeException('Google Cloud は BigQueryテーブル（アカウントID欄）が必要です。'); }
            $c = cost_gcp_bq($secret, $account);
            break;
        default:
            throw new RuntimeException('この箱のコスト種別が未設定です。編集でコスト種別（OpenAI / Twilio 等）を選んでください。');
    }
    $breakdown = (isset($c['breakdown']) && $c['breakdown']) ? json_encode($c['breakdown'], JSON_UNESCAPED_UNICODE) : null;
    db()->prepare('UPDATE projects SET monthly_cost = :m, currency = :c, balance = COALESCE(:bal, balance), cost_note = :note, cost_breakdown = :bd, updated_at = :u WHERE id = :id AND group_id = :g')
        ->execute([':m' => $c['amount'], ':c' => $c['currency'], ':bal' => $balance, ':note' => (string) ($c['note'] ?? ''), ':bd' => $breakdown, ':u' => now(), ':id' => (int) $project['id'], ':g' => $gid]);
    // 当月スナップショットに記録（推移・前月比用）
    $project['monthly_cost'] = $c['amount']; $project['currency'] = $c['currency'];
    snapshot_box($gid, $project);
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

/**
 * DataForSEO 残高・課金（login + APIパスワード、Basic認証）。
 * user_data から balance / total を取得。amount は使用合計(total-balance)を推定。
 */
function cost_dataforseo(string $login, string $password): array
{
    $url = 'https://api.dataforseo.com/v3/appendix/user_data';
    $r = http_request('GET', $url, ['headers' => ['Authorization: Basic ' . base64_encode($login . ':' . $password)]]);
    if ($r['status'] === 401) { throw new RuntimeException('DataForSEO認証に失敗しました（ログイン/パスワードをご確認ください）。'); }
    if ($r['status'] !== 200) { throw new RuntimeException('DataForSEO APIエラー (HTTP ' . $r['status'] . ')。' . substr((string) $r['body'], 0, 200)); }
    $d = json_decode($r['body'], true);
    $money = $d['tasks'][0]['result'][0]['money'] ?? [];
    $balance = isset($money['balance']) ? (float) $money['balance'] : null;
    $total   = isset($money['total']) ? (float) $money['total'] : null;
    $cur = strtoupper((string) ($money['currency'] ?? 'USD')) ?: 'USD';
    // 当月利用額はAPIで直接取れないため、累計利用(total-balance)を amount として参考表示
    $spent = ($total !== null && $balance !== null) ? max(0.0, $total - $balance) : 0.0;
    $out = ['amount' => round($spent, 2), 'currency' => $cur, 'note' => 'DataForSEO: 累計利用の推定（total-balance）'];
    if ($balance !== null) { $out['balance'] = round($balance, 2); }
    return $out;
}

/** Vonage 残高（API Key + API Secret）。['amount'=>float,'currency'=>string] */
function cost_vonage(string $apiKey, string $apiSecret): array
{
    $url = 'https://rest.nexmo.com/account/get-balance?api_key=' . rawurlencode($apiKey) . '&api_secret=' . rawurlencode($apiSecret);
    $r = http_request('GET', $url);
    if ($r['status'] === 401) { throw new RuntimeException('Vonage認証に失敗しました（API Key/Secretをご確認ください）。'); }
    if ($r['status'] !== 200) { throw new RuntimeException('Vonage APIエラー (HTTP ' . $r['status'] . ')。' . substr((string) $r['body'], 0, 200)); }
    $d = json_decode($r['body'], true);
    if (!isset($d['value'])) { throw new RuntimeException('Vonage残高の取得に失敗しました。'); }
    return ['amount' => round((float) $d['value'], 2), 'currency' => 'EUR'];
}

/**
 * SerpApi アカウント情報（API Key）。当月利用は検索回数ベース。
 * amount にはプラン月額(plan_monthly_price)を入れる（USD）。
 */
function cost_serpapi(string $apiKey): array
{
    $url = 'https://serpapi.com/account?api_key=' . rawurlencode($apiKey);
    $r = http_request('GET', $url);
    if ($r['status'] === 401) { throw new RuntimeException('SerpApi認証に失敗しました（API Keyをご確認ください）。'); }
    if ($r['status'] !== 200) { throw new RuntimeException('SerpApi APIエラー (HTTP ' . $r['status'] . ')。' . substr((string) $r['body'], 0, 200)); }
    $d = json_decode($r['body'], true);
    $price = isset($d['plan_monthly_price']) ? (float) $d['plan_monthly_price'] : 0.0;
    $used  = $d['this_month_usage'] ?? null;
    $left  = $d['total_searches_left'] ?? ($d['plan_searches_left'] ?? null);
    $note  = 'SerpApi: 当月 ' . ($used !== null ? (int) $used : '?') . ' 検索' . ($left !== null ? ' / 残 ' . (int) $left : '');
    return ['amount' => round($price, 2), 'currency' => 'USD', 'note' => $note];
}

/** サービスアカウントJSONから Google アクセストークンを取得（JWT Bearer） */
function gcp_access_token(string $saJson): string
{
    $sa = json_decode($saJson, true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        throw new RuntimeException('サービスアカウントJSONが不正です（client_email / private_key が必要）。');
    }
    $tokenUri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $b64 = static fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $now = time();
    $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = $b64(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/bigquery',
        'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600,
    ]));
    $input = $head . '.' . $claim;
    $sig = '';
    if (!openssl_sign($input, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('JWT署名に失敗しました（private_key をご確認ください）。');
    }
    $jwt = $input . '.' . $b64($sig);
    $r = http_request('POST', $tokenUri, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
    ]);
    if ($r['status'] !== 200) { throw new RuntimeException('Googleトークン取得に失敗 (HTTP ' . $r['status'] . ')。' . substr((string) $r['body'], 0, 200)); }
    $d = json_decode($r['body'], true);
    if (empty($d['access_token'])) { throw new RuntimeException('アクセストークンを取得できませんでした。'); }
    return (string) $d['access_token'];
}

/** Google Cloud 当月コスト（BigQuery 課金エクスポートを集計）。$table は project.dataset.table */
function cost_gcp_bq(string $saJson, string $table): array
{
    $r = gcp_bq_breakdown($saJson, $table, gmdate('Y-m-01'));
    if (!$r['has_data']) { return ['amount' => 0.0, 'currency' => 'JPY', 'note' => 'GCP: 当月データなし']; }
    return ['amount' => round(max(0.0, $r['total']), 2), 'currency' => $r['currency'], 'note' => 'GCP: BigQuery集計', 'breakdown' => $r['breakdown']];
}

/**
 * 指定月（$monthStart = 'YYYY-MM-01'）の Google Cloud コストを
 * サービス別に集計して返す。戻り値: ['currency','total','breakdown'=>[{service,amount}],'has_data']
 */
function gcp_bq_breakdown(string $saJson, string $table, string $monthStart): array
{
    $sa = json_decode($saJson, true);
    $project = is_array($sa) ? ($sa['project_id'] ?? '') : '';
    if ($project === '') { throw new RuntimeException('サービスアカウントJSONに project_id がありません。'); }
    if (!preg_match('/^[A-Za-z0-9._\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_*\-]+$/', $table)) {
        throw new RuntimeException('BigQueryテーブルは「project.dataset.table」形式で指定してください。');
    }
    if (!preg_match('/^\d{4}-\d{2}-01$/', $monthStart)) { $monthStart = gmdate('Y-m-01'); }
    $monthEnd = gmdate('Y-m-01', strtotime($monthStart . ' +1 month'));
    $token = gcp_access_token($saJson);
    // サービス別×通貨で集計（合計と内訳を1クエリで取得）。指定月の[月初, 翌月初)で絞る。
    $sql = "SELECT service.description AS svc, currency AS cur, "
         . "SUM(cost) + SUM(IFNULL((SELECT SUM(c.amount) FROM UNNEST(credits) c), 0)) AS net "
         . "FROM `{$table}` WHERE usage_start_time >= TIMESTAMP('{$monthStart} 00:00:00 UTC') "
         . "AND usage_start_time < TIMESTAMP('{$monthEnd} 00:00:00 UTC') "
         . "GROUP BY svc, cur ORDER BY net DESC";
    $url = 'https://bigquery.googleapis.com/bigquery/v2/projects/' . rawurlencode($project) . '/queries';
    $r = http_request('POST', $url, [
        'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        'body' => json_encode(['query' => $sql, 'useLegacySql' => false, 'timeoutMs' => 30000]),
    ]);
    if ($r['status'] !== 200) { throw new RuntimeException('BigQueryエラー (HTTP ' . $r['status'] . ')。' . substr((string) $r['body'], 0, 300)); }
    $d = json_decode($r['body'], true);
    if (!($d['jobComplete'] ?? false)) { throw new RuntimeException('BigQueryジョブが時間内に完了しませんでした。'); }
    $rows = $d['rows'] ?? [];
    if (!$rows) { return ['currency' => 'JPY', 'total' => 0.0, 'breakdown' => [], 'has_data' => false]; }
    // 通貨ごとの合計を出し、最も大きい通貨を代表として採用
    $byCur = [];
    foreach ($rows as $row) {
        $cur = strtoupper((string) ($row['f'][1]['v'] ?? 'JPY')) ?: 'JPY';
        $byCur[$cur] = ($byCur[$cur] ?? 0) + (float) ($row['f'][2]['v'] ?? 0);
    }
    arsort($byCur);
    $topCur = (string) array_key_first($byCur);
    // 代表通貨のサービス別内訳（金額が出ているものだけ、上位12件）
    $breakdown = [];
    foreach ($rows as $row) {
        $cur = strtoupper((string) ($row['f'][1]['v'] ?? 'JPY')) ?: 'JPY';
        if ($cur !== $topCur) { continue; }
        $svc = trim((string) ($row['f'][0]['v'] ?? '')) ?: '(その他)';
        $net = round((float) ($row['f'][2]['v'] ?? 0), 2);
        if ($net <= 0) { continue; }
        $breakdown[] = ['service' => $svc, 'amount' => $net];
    }
    usort($breakdown, static fn($a, $b) => $b['amount'] <=> $a['amount']);
    if (count($breakdown) > 12) {
        $rest = array_sum(array_map(static fn($x) => $x['amount'], array_slice($breakdown, 12)));
        $breakdown = array_slice($breakdown, 0, 12);
        if ($rest > 0) { $breakdown[] = ['service' => 'その他', 'amount' => round($rest, 2)]; }
    }
    return ['currency' => $topCur, 'total' => round((float) $byCur[$topCur], 2), 'breakdown' => $breakdown, 'has_data' => true];
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

/** Anthropic / Claude 当月コスト（Admin APIキー sk-ant-admin... が必要）。Cost Report API。 */
function cost_anthropic(string $key): array
{
    $start = gmdate('Y-m-01\T00:00:00\Z');
    // Cost Report API は limit が最大31（日次バケットで1か月分=最大31日）。
    $url = 'https://api.anthropic.com/v1/organizations/cost_report?starting_at=' . rawurlencode($start) . '&limit=31';
    $r = http_request('GET', $url, ['headers' => [
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ]]);
    if ($r['status'] === 401 || $r['status'] === 403) {
        throw new RuntimeException('Anthropicのコスト取得には組織の Admin APIキー(sk-ant-admin...) が必要です。通常のキーでは取得できません。');
    }
    if ($r['status'] !== 200) {
        throw new RuntimeException('Anthropic コストAPIエラー (HTTP ' . $r['status'] . ')。' . substr((string) $r['body'], 0, 200));
    }
    $d = json_decode($r['body'], true);
    $sum = 0.0;
    $cur = 'USD';
    // data[].results[] の中の amount を合算（金額キーの揺れに耐性を持たせる）
    foreach (($d['data'] ?? []) as $bucket) {
        foreach (($bucket['results'] ?? []) as $res) {
            if (isset($res['currency']) && $res['currency'] !== '') { $cur = strtoupper((string) $res['currency']); }
            if (isset($res['amount'])) {
                $sum += is_array($res['amount']) ? (float) ($res['amount']['value'] ?? 0) : (float) $res['amount'];
            }
        }
    }
    // Cost Report の amount はセント単位（最小通貨単位）で返るため 100 で割ってドル等に直す。
    return ['amount' => round($sum / 100, 2), 'currency' => $cur, 'note' => 'Anthropic: Cost Report'];
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
