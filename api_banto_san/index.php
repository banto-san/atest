<?php
declare(strict_types=1);

/**
 * API番人さん (api_banto_san) — API棚卸しツール v1
 * --------------------------------------------------------------------------
 * 仕様書「API棚卸しツール」のコア機能「コスト軸ダッシュボード」を、
 * heteml 等の素の PHP 共有ホスティングで単体動作するように実装したもの。
 *
 * 実装範囲 (v1):
 *   - APIカタログの一覧（コスト軸ビュー：月額降順 / 通貨別小計 / 未設定の明示）
 *   - 手動フィールドの追加・編集・削除（コストは手動入力）
 *   - 使用箇所(usages: repo/file/line) のドリルダウン表示・追加・削除
 *   - provider / status / 名前 による絞り込み・検索
 *
 * 仕様書に基づく重要な制約:
 *   - APIキー本体は絶対に保存しない。保存するのは「鍵のありか」(key_location) のみ。
 *   - monthly_cost / notes / owner などは手動フィールド。
 *   - usages / detected_by / last_scanned は本来スキャナCLIが更新する自動フィールド。
 *
 * 将来フェーズ（本ファイルでは未実装。仕様書 §3,§6,§9 参照）:
 *   - Googleアカウント認証 (OAuth/OIDC) と グループ／ロールによる権限分離
 *   - スキャナCLI (scan / push) との連携、再プッシュ時の手動フィールドのマージ保持
 *   - 各社 billing/usage API 連携による monthly_cost の半自動更新
 *
 * ストレージ: SQLite (PDO)。同ディレクトリに data.sqlite を自動生成。
 *            (リポジトリの .gitignore で *.sqlite は除外済み)
 */

session_start();
mb_internal_encoding('UTF-8');

/* ------------------------------------------------------------------ *
 *  設定
 * ------------------------------------------------------------------ */
const APP_NAME   = 'API番人さん';
const DB_FILE    = __DIR__ . '/data.sqlite';

// status の選択肢（手動フィールド）
const STATUSES = [
    'active'     => '稼働中',
    'unused'     => '未使用',
    'unknown'    => '確認中',
    'deprecated' => '廃止予定',
];

/* ------------------------------------------------------------------ *
 *  DB 初期化
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
        CREATE TABLE IF NOT EXISTS apis (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT    NOT NULL,
            provider      TEXT    NOT NULL DEFAULT '',
            status        TEXT    NOT NULL DEFAULT 'unknown',
            monthly_cost  REAL,                 -- NULL = 未設定（合計から除外）
            currency      TEXT    NOT NULL DEFAULT 'JPY',
            billing_url   TEXT    NOT NULL DEFAULT '',
            key_location  TEXT    NOT NULL DEFAULT '',  -- 例: "env: OPENAI_API_KEY"（鍵本体は保存しない）
            docs_url      TEXT    NOT NULL DEFAULT '',
            owner         TEXT    NOT NULL DEFAULT '',
            notes         TEXT    NOT NULL DEFAULT '',
            detected_by   TEXT    NOT NULL DEFAULT '',  -- 自動フィールド（スキャナ用）
            last_scanned  TEXT,                          -- 自動フィールド（スキャナ用）
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

    seed_if_empty($pdo);
    return $pdo;
}

/** 初回のみ、空ならサンプルデータを投入して画面が空にならないようにする */
function seed_if_empty(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM apis')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $now = now();
    $samples = [
        ['OpenAI API', 'OpenAI', 'active', 12000, 'JPY', 'https://platform.openai.com/usage', 'env: OPENAI_API_KEY', 'https://platform.openai.com/docs', '開発チーム', 'GPT利用。月により変動。',
            [['web-app', 'src/lib/ai.ts', 42, "const client = new OpenAI({ apiKey: process.env.OPENAI_API_KEY })"]]],
        ['Stripe', 'Stripe', 'active', 30, 'USD', 'https://dashboard.stripe.com/billing', 'env: STRIPE_SECRET_KEY', 'https://stripe.com/docs/api', '請求担当', '決済。固定費あり。',
            [['shop', 'api/checkout.php', 18, "\\Stripe\\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));"]]],
        ['Google Maps Platform', 'Google', 'unknown', null, 'JPY', 'https://console.cloud.google.com/billing', 'env: GOOGLE_MAPS_API_KEY', 'https://developers.google.com/maps', '', '金額未確認。地図表示で使用。',
            [['web-app', 'public/map.js', 7, "key=GOOGLE_MAPS_API_KEY"]]],
    ];
    $insApi = $pdo->prepare(
        'INSERT INTO apis (name, provider, status, monthly_cost, currency, billing_url, key_location, docs_url, owner, notes, detected_by, last_scanned, created_at, updated_at)
         VALUES (:name,:provider,:status,:cost,:cur,:bill,:key,:docs,:owner,:notes,:det,:scan,:ca,:ua)'
    );
    $insUse = $pdo->prepare(
        'INSERT INTO usages (api_id, repo, file, line, snippet) VALUES (:aid,:repo,:file,:line,:snip)'
    );
    foreach ($samples as $s) {
        [$name,$provider,$status,$cost,$cur,$bill,$key,$docs,$owner,$notes,$usages] = $s;
        $insApi->execute([
            ':name'=>$name, ':provider'=>$provider, ':status'=>$status, ':cost'=>$cost,
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
 *  ヘルパ
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

function redirect_self(): void
{
    // POST 後のリロード二重送信を防ぐため、現在のクエリを保ったまま GET へ
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . $qs : ''));
    exit;
}

/** 金額表示 */
function fmt_money(?float $cost, string $cur): string
{
    if ($cost === null) {
        return '<span class="muted">未設定</span>';
    }
    $n = (fmod($cost, 1.0) === 0.0)
        ? number_format($cost)
        : number_format($cost, 2);
    return h($cur) . ' ' . $n;
}

/* ------------------------------------------------------------------ *
 *  POST 処理（追加 / 編集 / 削除）
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'save_api') {
        $id           = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
        $name         = trim((string) ($_POST['name'] ?? ''));
        $provider     = trim((string) ($_POST['provider'] ?? ''));
        $status       = array_key_exists($_POST['status'] ?? '', STATUSES) ? $_POST['status'] : 'unknown';
        $costRaw      = trim((string) ($_POST['monthly_cost'] ?? ''));
        $monthly_cost = ($costRaw === '') ? null : (float) $costRaw;     // 空 = 未設定
        $currency     = trim((string) ($_POST['currency'] ?? 'JPY')) ?: 'JPY';
        $billing_url  = trim((string) ($_POST['billing_url'] ?? ''));
        $key_location = trim((string) ($_POST['key_location'] ?? ''));
        $docs_url     = trim((string) ($_POST['docs_url'] ?? ''));
        $owner        = trim((string) ($_POST['owner'] ?? ''));
        $notes        = trim((string) ($_POST['notes'] ?? ''));

        if ($name === '') {
            $_SESSION['flash'] = ['err', 'API名は必須です。'];
            redirect_self();
        }

        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO apis (name, provider, status, monthly_cost, currency, billing_url, key_location, docs_url, owner, notes, created_at, updated_at)
                 VALUES (:name,:provider,:status,:cost,:cur,:bill,:key,:docs,:owner,:notes,:ca,:ua)'
            );
            $stmt->execute([
                ':name'=>$name, ':provider'=>$provider, ':status'=>$status, ':cost'=>$monthly_cost,
                ':cur'=>$currency, ':bill'=>$billing_url, ':key'=>$key_location, ':docs'=>$docs_url,
                ':owner'=>$owner, ':notes'=>$notes, ':ca'=>now(), ':ua'=>now(),
            ]);
            $_SESSION['flash'] = ['ok', 'APIを追加しました。'];
        } else {
            $stmt = $pdo->prepare(
                'UPDATE apis SET name=:name, provider=:provider, status=:status, monthly_cost=:cost,
                     currency=:cur, billing_url=:bill, key_location=:key, docs_url=:docs,
                     owner=:owner, notes=:notes, updated_at=:ua
                 WHERE id=:id'
            );
            $stmt->execute([
                ':name'=>$name, ':provider'=>$provider, ':status'=>$status, ':cost'=>$monthly_cost,
                ':cur'=>$currency, ':bill'=>$billing_url, ':key'=>$key_location, ':docs'=>$docs_url,
                ':owner'=>$owner, ':notes'=>$notes, ':ua'=>now(), ':id'=>$id,
            ]);
            $_SESSION['flash'] = ['ok', 'APIを更新しました。'];
        }
        redirect_self();
    }

    if ($action === 'delete_api') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM apis WHERE id=:id')->execute([':id'=>$id]);
        $_SESSION['flash'] = ['ok', 'APIを削除しました。'];
        redirect_self();
    }

    if ($action === 'add_usage') {
        $aid  = (int) ($_POST['api_id'] ?? 0);
        $repo = trim((string) ($_POST['repo'] ?? ''));
        $file = trim((string) ($_POST['file'] ?? ''));
        $line = ($_POST['line'] ?? '') === '' ? null : (int) $_POST['line'];
        $snip = trim((string) ($_POST['snippet'] ?? ''));
        if ($aid > 0 && ($repo !== '' || $file !== '')) {
            $pdo->prepare('INSERT INTO usages (api_id, repo, file, line, snippet) VALUES (:aid,:repo,:file,:line,:snip)')
                ->execute([':aid'=>$aid, ':repo'=>$repo, ':file'=>$file, ':line'=>$line, ':snip'=>$snip]);
            $_SESSION['flash'] = ['ok', '使用箇所を追加しました。'];
        }
        redirect_self();
    }

    if ($action === 'delete_usage') {
        $uid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM usages WHERE id=:id')->execute([':id'=>$uid]);
        $_SESSION['flash'] = ['ok', '使用箇所を削除しました。'];
        redirect_self();
    }

    redirect_self();
}

/* ------------------------------------------------------------------ *
 *  一覧取得（フィルタ / 検索 / コスト軸ソート）
 * ------------------------------------------------------------------ */
$pdo = db();

$q             = trim((string) ($_GET['q'] ?? ''));
$filterProv    = trim((string) ($_GET['provider'] ?? ''));
$filterStatus  = trim((string) ($_GET['status'] ?? ''));

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR notes LIKE :q OR owner LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($filterProv !== '') {
    $where[] = 'provider = :prov';
    $params[':prov'] = $filterProv;
}
if ($filterStatus !== '' && array_key_exists($filterStatus, STATUSES)) {
    $where[] = 'status = :st';
    $params[':st'] = $filterStatus;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// コスト軸：月額の降順。未設定(NULL)は末尾へ。
$sql = "SELECT a.*,
               (SELECT COUNT(DISTINCT u.repo) FROM usages u WHERE u.api_id = a.id AND u.repo <> '') AS repo_count,
               (SELECT COUNT(*)               FROM usages u WHERE u.api_id = a.id)                  AS usage_count
        FROM apis a
        $whereSql
        ORDER BY (a.monthly_cost IS NULL) ASC, a.monthly_cost DESC, a.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apis = $stmt->fetchAll();

// 使用箇所をまとめて取得（ドリルダウン用）
$usagesByApi = [];
foreach ($pdo->query('SELECT * FROM usages ORDER BY repo, file, line') as $u) {
    $usagesByApi[(int) $u['api_id']][] = $u;
}

// 通貨別 月額小計 / 未設定件数
$subtotals = [];
$unsetCount = 0;
foreach ($apis as $a) {
    if ($a['monthly_cost'] === null) {
        $unsetCount++;
        continue;
    }
    $cur = $a['currency'] ?: 'JPY';
    $subtotals[$cur] = ($subtotals[$cur] ?? 0) + (float) $a['monthly_cost'];
}
ksort($subtotals);

// provider のユニーク一覧（フィルタ用）
$providers = $pdo->query("SELECT DISTINCT provider FROM apis WHERE provider <> '' ORDER BY provider")
                 ->fetchAll(PDO::FETCH_COLUMN);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — API棚卸しダッシュボード</title>
<style>
    :root {
        --bg: #f5f6f8; --card: #fff; --line: #e3e6ea; --ink: #1f2733;
        --muted: #8a93a0; --accent: #2563eb; --accent-d: #1d4ed8;
        --ok-bg: #e7f6ec; --ok-ink: #1a7f43; --err-bg: #fdecec; --err-ink: #b42318;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; background: var(--bg); color: var(--ink);
        font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN",
                     "Noto Sans JP", Meiryo, sans-serif; line-height: 1.6;
    }
    header.app {
        background: #0f172a; color: #fff; padding: 14px 20px;
        display: flex; align-items: center; gap: 12px;
    }
    header.app h1 { font-size: 18px; margin: 0; font-weight: 700; }
    header.app .tag { font-size: 12px; color: #94a3b8; }
    .wrap { max-width: 1080px; margin: 0 auto; padding: 20px; }
    .summary { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; }
    .stat {
        background: var(--card); border: 1px solid var(--line); border-radius: 12px;
        padding: 12px 16px; min-width: 150px;
    }
    .stat .label { font-size: 12px; color: var(--muted); }
    .stat .value { font-size: 22px; font-weight: 700; }
    .stat .value small { font-size: 12px; font-weight: 400; color: var(--muted); }
    .toolbar {
        display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        background: var(--card); border: 1px solid var(--line); border-radius: 12px;
        padding: 12px; margin-bottom: 14px;
    }
    .toolbar input, .toolbar select {
        padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px;
    }
    .toolbar .spacer { flex: 1; }
    button, .btn {
        font-size: 14px; padding: 7px 14px; border-radius: 8px; border: 1px solid var(--line);
        background: #fff; color: var(--ink); cursor: pointer; text-decoration: none; display: inline-block;
    }
    button.primary, .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    button.primary:hover { background: var(--accent-d); }
    button.danger { color: var(--err-ink); border-color: #f3c4c0; background: #fff; }
    button.link { border: none; background: none; color: var(--accent); padding: 2px 4px; }
    table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 12px; overflow: hidden; }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--line); font-size: 14px; vertical-align: top; }
    th { background: #f0f2f5; font-size: 12px; color: var(--muted); font-weight: 600; }
    td.cost { font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 600; }
    tr.api-row:hover { background: #fafbfc; }
    .muted { color: var(--muted); }
    .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .pill.active     { background: #e7f6ec; color: #1a7f43; }
    .pill.unused     { background: #eef1f4; color: #6b7280; }
    .pill.unknown    { background: #fff4e0; color: #b45309; }
    .pill.deprecated { background: #fdecec; color: #b42318; }
    .usages { background: #fbfcfe; }
    .usages table { box-shadow: none; border: 1px solid var(--line); margin: 6px 0; }
    .usages td, .usages th { font-size: 13px; padding: 6px 10px; }
    code { background: #f0f2f5; padding: 1px 5px; border-radius: 5px; font-size: 12.5px; word-break: break-all; }
    .flash { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
    .flash.ok  { background: var(--ok-bg);  color: var(--ok-ink); }
    .flash.err { background: var(--err-bg); color: var(--err-ink); }
    dialog {
        border: none; border-radius: 14px; padding: 0; width: min(620px, 94vw);
        box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }
    dialog::backdrop { background: rgba(15,23,42,.45); }
    .modal-head { padding: 16px 20px; border-bottom: 1px solid var(--line); font-weight: 700; }
    .modal-body { padding: 16px 20px; }
    .modal-foot { padding: 14px 20px; border-top: 1px solid var(--line); display: flex; justify-content: flex-end; gap: 8px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .grid .full { grid-column: 1 / -1; }
    .field label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
    .field input, .field select, .field textarea {
        width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; font-family: inherit;
    }
    .hint { font-size: 11.5px; color: var(--muted); margin-top: 3px; }
    .empty { text-align: center; color: var(--muted); padding: 40px; }
    .note-cell { max-width: 220px; }
    @media (max-width: 720px) {
        .grid { grid-template-columns: 1fr; }
        .hide-sm { display: none; }
    }
</style>
</head>
<body>
<header class="app">
    <h1>🛡️ <?= h(APP_NAME) ?></h1>
    <span class="tag">API棚卸しダッシュボード（コスト軸）</span>
</header>

<div class="wrap">

    <?php if ($flash): ?>
        <div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <!-- 月額合計サマリ（通貨別小計） -->
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
        <div class="stat">
            <div class="label">登録API数</div>
            <div class="value"><?= count($apis) ?> <small>件</small></div>
        </div>
        <div class="stat">
            <div class="label">金額未設定</div>
            <div class="value"><?= $unsetCount ?> <small>件（合計に含まず）</small></div>
        </div>
    </div>

    <!-- 絞り込み / 検索 -->
    <form class="toolbar" method="get">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="名前 / メモ / 担当者で検索">
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
        <button class="primary" type="submit">絞り込み</button>
        <a class="btn" href="?">クリア</a>
        <span class="spacer"></span>
        <button type="button" class="primary" onclick="openCreate()">＋ API を追加</button>
    </form>

    <!-- コスト軸ビュー（主役） -->
    <?php if (!$apis): ?>
        <div class="empty">該当するAPIがありません。「＋ API を追加」から登録してください。</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th style="width:32px"></th>
                <th>API名</th>
                <th class="hide-sm">provider</th>
                <th>月額</th>
                <th class="hide-sm">使用リポジトリ</th>
                <th>status</th>
                <th class="hide-sm note-cell">メモ / 担当</th>
                <th style="width:120px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($apis as $a):
            $aid = (int) $a['id'];
            $uses = $usagesByApi[$aid] ?? [];
        ?>
            <tr class="api-row">
                <td>
                    <?php if ($uses): ?>
                        <button class="link" type="button" onclick="toggleUsage(<?= $aid ?>)" id="tg<?= $aid ?>" title="使用箇所を表示">▶</button>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= h($a['name']) ?></strong>
                    <?php if ($a['docs_url']): ?>
                        <a href="<?= h($a['docs_url']) ?>" target="_blank" rel="noopener" class="muted" title="ドキュメント">📄</a>
                    <?php endif; ?>
                    <?php if ($a['key_location']): ?>
                        <div class="hint">🔑 <?= h($a['key_location']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="hide-sm"><?= h($a['provider']) ?: '<span class="muted">—</span>' ?></td>
                <td class="cost">
                    <?= fmt_money($a['monthly_cost'] === null ? null : (float) $a['monthly_cost'], $a['currency']) ?>
                    <?php if ($a['billing_url']): ?>
                        <a href="<?= h($a['billing_url']) ?>" target="_blank" rel="noopener" class="muted" title="請求ページ">💳</a>
                    <?php endif; ?>
                </td>
                <td class="hide-sm"><?= (int) $a['repo_count'] ?> <span class="muted">リポジトリ / <?= (int) $a['usage_count'] ?> 箇所</span></td>
                <td><span class="pill <?= h($a['status']) ?>"><?= h(STATUSES[$a['status']] ?? $a['status']) ?></span></td>
                <td class="hide-sm note-cell">
                    <?php if ($a['owner']): ?><div class="muted">👤 <?= h($a['owner']) ?></div><?php endif; ?>
                    <?= nl2br(h(mb_strimwidth($a['notes'], 0, 60, '…'))) ?>
                </td>
                <td>
                    <button class="link" type="button"
                        onclick='openEdit(<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('「<?= h($a['name']) ?>」を削除しますか？')">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete_api">
                        <input type="hidden" name="id" value="<?= $aid ?>">
                        <button class="link danger" type="submit">削除</button>
                    </form>
                </td>
            </tr>

            <!-- ドリルダウン：使用箇所 (repo / file / line) -->
            <tr class="usages" id="us<?= $aid ?>" style="display:none">
                <td colspan="8">
                    <table>
                        <thead><tr><th>repo</th><th>file</th><th>line</th><th>snippet</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($uses as $u): ?>
                            <tr>
                                <td><?= h($u['repo']) ?></td>
                                <td><?= h($u['file']) ?></td>
                                <td><?= $u['line'] !== null ? (int) $u['line'] : '' ?></td>
                                <td><?php if ($u['snippet'] !== ''): ?><code><?= h($u['snippet']) ?></code><?php endif; ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('この使用箇所を削除しますか？')">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_usage">
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button class="link danger" type="submit">削除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form method="post" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="add_usage">
                        <input type="hidden" name="api_id" value="<?= $aid ?>">
                        <input name="repo" placeholder="repo" style="padding:6px 8px;border:1px solid var(--line);border-radius:7px">
                        <input name="file" placeholder="file" style="padding:6px 8px;border:1px solid var(--line);border-radius:7px">
                        <input name="line" placeholder="line" type="number" style="width:80px;padding:6px 8px;border:1px solid var(--line);border-radius:7px">
                        <input name="snippet" placeholder="snippet（任意）" style="flex:1;min-width:160px;padding:6px 8px;border:1px solid var(--line);border-radius:7px">
                        <button type="submit">使用箇所を追加</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p class="hint" style="margin-top:18px">
        ※ 本画面は v1（コストは手動入力）。使用箇所(usages)は本来スキャナCLIが自動検出してプッシュします。
        Googleログイン・グループ権限・スキャナ連携・各社billing API連携は将来フェーズです（仕様書 §3,§6,§9）。
        APIキー本体は保存せず、鍵の在りか(<code>env: XXX</code> 等)のみを記録します。
    </p>
</div>

<!-- 追加 / 編集モーダル -->
<dialog id="apiDialog">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_api">
        <input type="hidden" name="id" id="f_id" value="">
        <div class="modal-head" id="modalTitle">API を追加</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field">
                    <label>API名 <span style="color:#b42318">*</span></label>
                    <input name="name" id="f_name" required placeholder="例: OpenAI API">
                </div>
                <div class="field">
                    <label>provider</label>
                    <input name="provider" id="f_provider" placeholder="例: OpenAI / Stripe / Google">
                </div>
                <div class="field">
                    <label>月額（空欄＝未設定）</label>
                    <input name="monthly_cost" id="f_cost" type="number" step="0.01" min="0" placeholder="例: 12000">
                </div>
                <div class="field">
                    <label>通貨</label>
                    <select name="currency" id="f_currency">
                        <?php foreach (['JPY','USD','EUR','GBP'] as $c): ?>
                            <option value="<?= $c ?>"><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>status</label>
                    <select name="status" id="f_status">
                        <?php foreach (STATUSES as $k => $v): ?>
                            <option value="<?= h($k) ?>"><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>担当 (owner)</label>
                    <input name="owner" id="f_owner" placeholder="例: 開発チーム">
                </div>
                <div class="field full">
                    <label>鍵の在りか (key_location)</label>
                    <input name="key_location" id="f_key" placeholder="例: env: OPENAI_API_KEY">
                    <div class="hint">⚠ APIキー本体は入力しないでください。環境変数名など「在りか」のみ。</div>
                </div>
                <div class="field">
                    <label>請求ページURL (billing_url)</label>
                    <input name="billing_url" id="f_billing" type="url" placeholder="https://...">
                </div>
                <div class="field">
                    <label>ドキュメントURL (docs_url)</label>
                    <input name="docs_url" id="f_docs" type="url" placeholder="https://...">
                </div>
                <div class="field full">
                    <label>メモ (notes)</label>
                    <textarea name="notes" id="f_notes" rows="3" placeholder="補足・コスト変動の理由など"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('apiDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>

<script>
    const dialog = document.getElementById('apiDialog');

    function openCreate() {
        document.getElementById('modalTitle').textContent = 'API を追加';
        document.getElementById('f_id').value = '';
        for (const f of ['name','provider','cost','owner','key','billing','docs','notes']) {
            const el = document.getElementById('f_' + f);
            if (el) el.value = '';
        }
        document.getElementById('f_currency').value = 'JPY';
        document.getElementById('f_status').value = 'unknown';
        dialog.showModal();
    }

    function openEdit(a) {
        document.getElementById('modalTitle').textContent = 'API を編集';
        document.getElementById('f_id').value       = a.id ?? '';
        document.getElementById('f_name').value      = a.name ?? '';
        document.getElementById('f_provider').value  = a.provider ?? '';
        document.getElementById('f_cost').value      = (a.monthly_cost === null || a.monthly_cost === undefined) ? '' : a.monthly_cost;
        document.getElementById('f_currency').value  = a.currency ?? 'JPY';
        document.getElementById('f_status').value    = a.status ?? 'unknown';
        document.getElementById('f_owner').value     = a.owner ?? '';
        document.getElementById('f_key').value       = a.key_location ?? '';
        document.getElementById('f_billing').value   = a.billing_url ?? '';
        document.getElementById('f_docs').value      = a.docs_url ?? '';
        document.getElementById('f_notes').value     = a.notes ?? '';
        dialog.showModal();
    }

    function toggleUsage(id) {
        const row = document.getElementById('us' + id);
        const tg  = document.getElementById('tg' + id);
        const open = row.style.display === 'none';
        row.style.display = open ? 'table-row' : 'none';
        if (tg) tg.textContent = open ? '▼' : '▶';
    }
</script>
</body>
</html>
