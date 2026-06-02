<?php
declare(strict_types=1);

/**
 * スキャナ CLI（仕様書 §6）。
 * --------------------------------------------------------------------------
 * ローカル（または heteml の SSH 上）でリポジトリ/サイトを走査し、外部APIの
 * 使用箇所を検出する。--push でWeb APIへ送信して指定グループのカタログへ反映。
 *
 * 使い方:
 *   php scan.php --path /path/to/site                    # 検出結果を表示（JSON）
 *   php scan.php --path /path/to/site --repo mysite      # repo ラベルを指定
 *   php scan.php --path /path/to/site --out result.json  # ファイルに保存
 *   php scan.php --path /path/to/site --push \
 *       --endpoint https://example.com/atest/api_banto_san/api.php \
 *       --group 1
 *
 * 認証（--push 時）: 環境変数 APICATALOG_TOKEN に個人用トークンを設定。
 *   export APICATALOG_TOKEN="abt_xxxxx"
 * トークンは Web UI の「個人用トークン」画面で発行・失効できます。
 *
 * ※ コスト金額は取得しません（コードからは分からないため。Web UIで手動入力）。
 * ※ 再プッシュ時もサーバ側で手動フィールド（monthly_cost/notes/status 等）は保持されます。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "このスクリプトはコマンドラインで実行してください。\n");
    exit(1);
}

require __DIR__ . '/lib/scanner.php';

// --- 引数パース ---
$opts = getopt('', ['path:', 'repo:', 'out:', 'push', 'endpoint:', 'group:', 'token:', 'providers:', 'with-secrets', 'help']);
if (isset($opts['help']) || !isset($opts['path'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 0) ?: '');
    fwrite(STDOUT, <<<TXT
api_banto_san スキャナ CLI

  --path <dir>        走査するディレクトリ（必須）
  --repo <label>      usages に記録する repo 名（既定: ディレクトリ名）
  --out <file>        検出結果(JSON)を保存
  --push              Web API へ送信
  --endpoint <url>    push 先（例: https://<ドメイン>/atest/api_banto_san/api.php）
  --group <id>        送信先グループID
  --token <token>     個人用トークン（省略時は環境変数 APICATALOG_TOKEN）
  --providers <file>  プロバイダ定義JSON（既定: scanner/providers.json）
  --with-secrets      .env等の「キーの値」も取り込む（暗号化保存・取り扱い注意）
  --help              このヘルプ

例:
  php scan.php --path ../mysite --repo mysite
  APICATALOG_TOKEN=abt_xxx php scan.php --path ../mysite --push \\
    --endpoint https://example.com/atest/api_banto_san/api.php --group 1

TXT);
    exit(isset($opts['help']) ? 0 : 1);
}

$path = (string) $opts['path'];
$providersFile = (string) ($opts['providers'] ?? __DIR__ . '/scanner/providers.json');
$providers = load_providers($providersFile);
if (!$providers) {
    fwrite(STDERR, "プロバイダ定義が読み込めません: $providersFile\n");
    exit(1);
}

$repo = (string) ($opts['repo'] ?? basename(rtrim($path, '/\\')));

fwrite(STDERR, "走査中: $path (repo=$repo) ...\n");
try {
    $apis = scan_directory($path, $providers, ['repo' => $repo, 'secrets' => isset($opts['with-secrets'])]);
} catch (Throwable $e) {
    fwrite(STDERR, 'エラー: ' . $e->getMessage() . "\n");
    exit(1);
}

$usageTotal = array_sum(array_map(static fn($a) => count($a['usages']), $apis));
fwrite(STDERR, sprintf("検出: API %d 件 / 使用箇所 %d 件\n", count($apis), $usageTotal));

$payload = ['scanned_at' => date('c'), 'apis' => $apis];
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (isset($opts['out'])) {
    file_put_contents((string) $opts['out'], $json);
    fwrite(STDERR, '保存: ' . $opts['out'] . "\n");
}

if (!isset($opts['push'])) {
    fwrite(STDOUT, $json . "\n");
    exit(0);
}

// --- push ---
$endpoint = (string) ($opts['endpoint'] ?? '');
$group    = (int) ($opts['group'] ?? 0);
$token    = (string) ($opts['token'] ?? getenv('APICATALOG_TOKEN') ?: '');
if ($endpoint === '' || $group <= 0) {
    fwrite(STDERR, "--push には --endpoint と --group が必要です。\n");
    exit(1);
}
if ($token === '') {
    fwrite(STDERR, "個人用トークンがありません。--token か 環境変数 APICATALOG_TOKEN を設定してください。\n");
    exit(1);
}

$url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . 'action=push&group=' . $group;

// WAF対策: 本文を gzip+base64 でエンコードして送る
$jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
$headers  = ['Authorization: Bearer ' . $token];
if (function_exists('gzencode')) {
    $sendBody = base64_encode(gzencode($jsonBody, 6));
    $headers[] = 'X-Payload-Encoding: gzip-base64';
} else {
    $sendBody = base64_encode($jsonBody);
    $headers[] = 'X-Payload-Encoding: base64';
}
$headers[] = 'Content-Type: text/plain';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $sendBody,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 60,
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    fwrite(STDERR, "送信失敗: $err\n");
    exit(1);
}
fwrite(STDOUT, "HTTP $code\n$resp\n");
exit($code === 200 ? 0 : 1);
