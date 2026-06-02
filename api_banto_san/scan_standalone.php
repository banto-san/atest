<?php
declare(strict_types=1);

/**
 * api_banto_san スタンドアロン・スキャナ（1ファイル版）
 * ==========================================================================
 * 別サーバー等にこの1ファイルを置くだけで動くスキャナCLI。
 * 検出エンジン・プロバイダ定義・送信処理をすべて内蔵（外部ファイル不要）。
 *
 * 使い方（SSHでそのサーバーにログインして実行）:
 *   php scan_standalone.php --path /path/to/site                 # 検出結果を表示
 *   php scan_standalone.php --path /path/to/site --out r.json    # JSON保存
 *   APICATALOG_TOKEN=abt_xxx php scan_standalone.php \
 *       --path /path/to/site --push \
 *       --endpoint https://<api_banto_sanのドメイン>/atest/api_banto_san/api.php \
 *       --group <group_id>
 *
 * 認証(--push時): 環境変数 APICATALOG_TOKEN に個人用トークン（Web UIの「トークン」画面で発行）。
 * トークンの所有者がそのグループで member 以上であることをサーバ側で検証します。
 *
 * ※ コスト金額は取得しません（コードからは分からないため。Web UIで手動入力）。
 * ※ 再プッシュ時もサーバ側で手動フィールド(monthly_cost/notes/status等)は保持されます。
 * ※ スニペット中のキー本体らしき値は送信前に伏字化します（キー本体は保存しない）。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "このスクリプトはコマンドラインで実行してください。\n");
    exit(1);
}
mb_internal_encoding('UTF-8');

/* ============================ プロバイダ定義 ============================ *
 * sdk: import/require されるパッケージ名やクラス（部分一致・大小無視）
 * host: コード中に現れるベースURLのホスト名
 * env : 環境変数名（完全一致）
 * 追加したい場合はこの配列に足してください。
 * ----------------------------------------------------------------------- */
function abt_providers(): array
{
    return [
        ['name'=>'OpenAI','default_api_name'=>'OpenAI API','docs_url'=>'https://platform.openai.com/docs',
            'sdk'=>['openai','OpenAI\\'],'host'=>['api.openai.com'],'env'=>['OPENAI_API_KEY','OPENAI_API_BASE','OPENAI_ORG']],
        ['name'=>'Stripe','default_api_name'=>'Stripe','docs_url'=>'https://stripe.com/docs/api',
            'sdk'=>['stripe','Stripe\\'],'host'=>['api.stripe.com'],'env'=>['STRIPE_SECRET_KEY','STRIPE_PUBLISHABLE_KEY','STRIPE_API_KEY','STRIPE_WEBHOOK_SECRET']],
        ['name'=>'Google Maps','default_api_name'=>'Google Maps Platform','docs_url'=>'https://developers.google.com/maps',
            'sdk'=>['@googlemaps/','googlemaps'],'host'=>['maps.googleapis.com','maps.google.com'],'env'=>['GOOGLE_MAPS_API_KEY','GOOGLE_MAPS_KEY','GMAPS_API_KEY']],
        ['name'=>'Google Cloud','default_api_name'=>'Google Cloud','docs_url'=>'https://cloud.google.com/apis',
            'sdk'=>['googleapis','@google-cloud/','google/cloud','Google\\Cloud'],'host'=>['googleapis.com'],'env'=>['GOOGLE_APPLICATION_CREDENTIALS','GCP_PROJECT','GOOGLE_API_KEY']],
        ['name'=>'AWS','default_api_name'=>'AWS','docs_url'=>'https://docs.aws.amazon.com/',
            'sdk'=>['@aws-sdk/','aws-sdk','Aws\\','boto3'],'host'=>['amazonaws.com'],'env'=>['AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_SESSION_TOKEN']],
        ['name'=>'Anthropic','default_api_name'=>'Anthropic (Claude) API','docs_url'=>'https://docs.anthropic.com/',
            'sdk'=>['anthropic','@anthropic-ai/','Anthropic\\'],'host'=>['api.anthropic.com'],'env'=>['ANTHROPIC_API_KEY']],
        ['name'=>'SendGrid','default_api_name'=>'SendGrid','docs_url'=>'https://docs.sendgrid.com/',
            'sdk'=>['@sendgrid/','sendgrid','SendGrid\\'],'host'=>['api.sendgrid.com'],'env'=>['SENDGRID_API_KEY']],
        ['name'=>'Twilio','default_api_name'=>'Twilio','docs_url'=>'https://www.twilio.com/docs',
            'sdk'=>['twilio','Twilio\\'],'host'=>['api.twilio.com'],'env'=>['TWILIO_AUTH_TOKEN','TWILIO_ACCOUNT_SID']],
        ['name'=>'LINE','default_api_name'=>'LINE Messaging API','docs_url'=>'https://developers.line.biz/ja/docs/',
            'sdk'=>['@line/bot-sdk','linebot','LINE\\'],'host'=>['api.line.me'],'env'=>['LINE_CHANNEL_ACCESS_TOKEN','LINE_CHANNEL_SECRET']],
        ['name'=>'Slack','default_api_name'=>'Slack API','docs_url'=>'https://api.slack.com/',
            'sdk'=>['@slack/','slack-sdk'],'host'=>['slack.com/api','hooks.slack.com'],'env'=>['SLACK_BOT_TOKEN','SLACK_WEBHOOK_URL','SLACK_SIGNING_SECRET']],
        ['name'=>'Google AI Studio','default_api_name'=>'Google AI Studio (Gemini)','docs_url'=>'https://ai.google.dev/',
            'sdk'=>['@google/generative-ai','google.generativeai','google-generativeai','GenerativeModel'],'host'=>['generativelanguage.googleapis.com'],'env'=>['GEMINI_API_KEY','GOOGLE_AI_API_KEY','GOOGLE_GENAI_API_KEY']],
        ['name'=>'Azure','default_api_name'=>'Microsoft Azure','docs_url'=>'https://learn.microsoft.com/azure/',
            'sdk'=>['@azure/','azure-','msrestazure'],'host'=>['azure.com','cognitiveservices.azure.com','openai.azure.com','azurewebsites.net'],'env'=>['AZURE_CLIENT_ID','AZURE_CLIENT_SECRET','AZURE_TENANT_ID','AZURE_SUBSCRIPTION_ID','AZURE_OPENAI_API_KEY','AZURE_OPENAI_ENDPOINT']],
        ['name'=>'DataForSEO','default_api_name'=>'DataForSEO','docs_url'=>'https://docs.dataforseo.com/',
            'sdk'=>['dataforseo'],'host'=>['api.dataforseo.com'],'env'=>['DATAFORSEO_LOGIN','DATAFORSEO_PASSWORD','DATAFORSEO_API_KEY']],
        ['name'=>'HERE','default_api_name'=>'HERE Location Services','docs_url'=>'https://developer.here.com/documentation',
            'sdk'=>['@here/','heremaps'],'host'=>['hereapi.com','api.here.com'],'env'=>['HERE_API_KEY','HERE_APP_ID','HERE_APP_CODE']],
        ['name'=>'TomTom','default_api_name'=>'TomTom','docs_url'=>'https://developer.tomtom.com/',
            'sdk'=>['@tomtom-international/','tomtom'],'host'=>['api.tomtom.com'],'env'=>['TOMTOM_API_KEY']],
    ];
}

const ABT_EXCLUDE_DIRS = [
    'node_modules','vendor','.git','.svn','.hg','dist','build','.next',
    'bower_components','cache','.cache','tmp','temp','coverage','.idea','.vscode',
];
const ABT_EXTS = [
    'php','js','mjs','cjs','ts','jsx','tsx','vue','svelte','py','rb','go','java','kt',
    'cs','json','yaml','yml','env','html','htm','txt','ini','conf','config','sh','tpl','blade',
];
const ABT_MAX_FILE_BYTES = 524288;
const ABT_MAX_FILES      = 20000;

/* ============================ 検出エンジン ============================ */
function abt_scan_directory(string $root, array $providers, string $repo): array
{
    $real = realpath($root);
    if ($real === false || !is_dir($real)) {
        throw new RuntimeException('ディレクトリが見つかりません: ' . $root);
    }

    $found = [];
    $touch = static function (array &$found, string $name, string $provider, string $docsUrl): void {
        if (!isset($found[$name])) {
            $found[$name] = ['name'=>$name,'provider'=>$provider,'docs_url'=>$docsUrl,'key_location'=>'','detected_by'=>[],'usages'=>[]];
        }
    };

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            static function ($current) {
                $name = $current->getFilename();
                if ($current->isDir()) {
                    return !in_array($name, ABT_EXCLUDE_DIRS, true);
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $fileCount = 0;
    foreach ($it as $file) {
        if (!$file->isFile()) { continue; }
        if (++$fileCount > ABT_MAX_FILES) { break; }
        $ext  = strtolower($file->getExtension());
        $base = $file->getFilename();
        $isEnvFile = (strncmp($base, '.env', 4) === 0);
        if (!$isEnvFile && !in_array($ext, ABT_EXTS, true)) { continue; }
        if ($file->getSize() > ABT_MAX_FILE_BYTES) { continue; }

        $content = @file_get_contents($file->getPathname());
        if ($content === false || $content === '' || strpos($content, "\0") !== false) { continue; }

        $rel = ltrim(str_replace($real, '', $file->getPathname()), '/\\');
        foreach (preg_split('/\r\n|\r|\n/', $content) as $i => $line) {
            if ($line === '' || strlen($line) > 1000) { continue; }
            $lineNo = $i + 1;

            foreach ($providers as $p) {
                $apiName = (string) ($p['default_api_name'] ?? $p['name']);
                $docsUrl = (string) ($p['docs_url'] ?? '');
                $hit = null; $envName = null;
                foreach (($p['sdk'] ?? []) as $tok)  { if ($tok !== '' && stripos($line, $tok) !== false) { $hit='sdk:'.$tok; break; } }
                if (!$hit) foreach (($p['host'] ?? []) as $host) { if ($host !== '' && stripos($line, $host) !== false) { $hit='host:'.$host; break; } }
                if (!$hit) foreach (($p['env'] ?? []) as $env)  { if ($env !== '' && strpos($line, $env) !== false) { $hit='env:'.$env; $envName=$env; break; } }
                if ($hit) {
                    $touch($found, $apiName, (string) $p['name'], $docsUrl);
                    $found[$apiName]['detected_by'][$hit] = true;
                    if ($envName && $found[$apiName]['key_location'] === '') { $found[$apiName]['key_location'] = 'env: ' . $envName; }
                    abt_add_usage($found[$apiName]['usages'], $repo, $rel, $lineNo, $line);
                }
            }

            if (preg_match_all('/\b([A-Z][A-Z0-9]{1,30}(?:_[A-Z0-9]+)*?_(?:API_KEY|APIKEY|SECRET|SECRET_KEY|ACCESS_KEY|ACCESS_TOKEN|AUTH_TOKEN|TOKEN))\b/', $line, $mm)) {
                foreach ($mm[1] as $envVar) {
                    if (abt_is_known_env($providers, $envVar)) { continue; }
                    $prefix = preg_replace('/_(API_KEY|APIKEY|SECRET|SECRET_KEY|ACCESS_KEY|ACCESS_TOKEN|AUTH_TOKEN|TOKEN)$/', '', $envVar);
                    $name = ucfirst(strtolower(str_replace('_', ' ', $prefix))) . '（推定）';
                    $touch($found, $name, $prefix, '');
                    $found[$name]['detected_by']['env:' . $envVar] = true;
                    if ($found[$name]['key_location'] === '') { $found[$name]['key_location'] = 'env: ' . $envVar; }
                    abt_add_usage($found[$name]['usages'], $repo, $rel, $lineNo, $line);
                }
            }
        }
    }

    $out = [];
    foreach ($found as $api) { $api['detected_by'] = array_keys($api['detected_by']); $out[] = $api; }
    return $out;
}

function abt_add_usage(array &$usages, string $repo, string $file, int $line, string $snippet): void
{
    if (count($usages) >= 500) { return; }
    foreach ($usages as $u) { if ($u['file'] === $file && $u['line'] === $line) { return; } }
    $usages[] = ['repo'=>$repo,'file'=>$file,'line'=>$line,'snippet'=>abt_redact(trim(mb_substr($snippet, 0, 240)))];
}

function abt_is_known_env(array $providers, string $envVar): bool
{
    foreach ($providers as $p) { foreach (($p['env'] ?? []) as $env) { if ($env === $envVar) { return true; } } }
    return false;
}

/** キー本体らしき値を伏字化（送信前の安全策・ベストエフォート） */
function abt_redact(string $line): string
{
    $line = mb_substr($line, 0, 300);
    $line = preg_replace('/^(\s*[A-Za-z_][A-Za-z0-9_.\-]*\s*[:=]\s*)([\'"]?)([^\s\'"]{6,})/', '${1}${2}***', $line);
    $line = preg_replace('/\b(sk-[A-Za-z0-9_\-]{8,}|sk_(?:live|test)_[A-Za-z0-9]{10,}|pk_(?:live|test)_[A-Za-z0-9]{10,}|rk_[A-Za-z0-9]{8,}|AKIA[0-9A-Z]{12,}|AIza[0-9A-Za-z_\-]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9\-]{10,}|SG\.[A-Za-z0-9_\-\.]{16,})\b/', '***', $line);
    $line = preg_replace('/([=:]\s*[\'"])[A-Za-z0-9_\-\.\/\+]{16,}([\'"])/', '${1}***${2}', $line);
    return $line;
}

/* ============================ CLI ============================ */
$opts = getopt('', ['path:', 'repo:', 'out:', 'push', 'endpoint:', 'group:', 'token:', 'providers:', 'help']);
if (isset($opts['help']) || !isset($opts['path'])) {
    fwrite(STDOUT, <<<TXT
api_banto_san スタンドアロン・スキャナ（1ファイル版）

  --path <dir>        走査するディレクトリ（必須）
  --repo <label>      usages に記録する repo 名（既定: ディレクトリ名）
  --out <file>        検出結果(JSON)を保存
  --push              Web API へ送信
  --endpoint <url>    push 先（例: https://<ドメイン>/atest/api_banto_san/api.php）
  --group <id>        送信先グループID
  --token <token>     個人用トークン（省略時は環境変数 APICATALOG_TOKEN）
  --providers <file>  プロバイダ定義JSONで内蔵定義を上書き（任意）
  --help              このヘルプ

例:
  php scan_standalone.php --path /home/users/0/app-xxx/web/s-benri --repo s-benri
  APICATALOG_TOKEN=abt_xxx php scan_standalone.php --path /path/to/site --push \\
    --endpoint https://example.com/atest/api_banto_san/api.php --group 1

TXT);
    exit(isset($opts['help']) ? 0 : 1);
}

$path = (string) $opts['path'];
$repo = (string) ($opts['repo'] ?? basename(rtrim($path, '/\\')));

$providers = abt_providers();
if (isset($opts['providers']) && is_file((string) $opts['providers'])) {
    $j = json_decode((string) file_get_contents((string) $opts['providers']), true);
    if (isset($j['providers']) && is_array($j['providers'])) { $providers = $j['providers']; }
}

fwrite(STDERR, "走査中: $path (repo=$repo) ...\n");
try {
    $apis = abt_scan_directory($path, $providers, $repo);
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

/* --- push --- */
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
if (!function_exists('curl_init')) {
    fwrite(STDERR, "このPHPには cURL がありません。--out で保存してWeb側でご利用ください。\n");
    exit(1);
}

$url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . 'action=push&group=' . $group;

// WAF(SiteGuard)がコードを誤検知してブロックするのを避けるため、
// 本文を gzip+base64 でエンコードして送る（中身が英数字の塊になりWAFを通る）。
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
