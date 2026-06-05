<?php
declare(strict_types=1);

/**
 * 他媒体検索API（このサイト自身が検索する）
 * --------------------------------------------------------------------------
 * 受注客1件について「会社名＋住所」でOpenAIのWeb検索を実行し、見つかった
 * 掲載URL・ドメインを data.json のその顧客(foundMedia)に保存して返す。
 *
 * POST search.php  body: { "id": "<clientId>" }  ヘッダ: X-CSRF-Token
 * 認証: ログイン必須。APIキーは config.local.php の SEARCH_API_KEY。
 */

require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(120);

function search_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!current_user()) {
    search_out(401, ['error' => 'unauthorized', 'message' => 'ログインが必要です。']);
}
// 検索はAPI課金が発生するため管理者のみ実行可
if (!is_admin()) {
    search_out(403, ['error' => 'forbidden', 'message' => '他媒体の検索は管理者のみ実行できます。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    search_out(405, ['error' => 'method_not_allowed']);
}

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    search_out(400, ['error' => 'invalid_json', 'message' => '無効なリクエストです。']);
}
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf'] ?? null);
if (!check_csrf($token)) {
    search_out(400, ['error' => 'bad_csrf', 'message' => 'セッションが切れました。ページを再読み込みしてください。']);
}

$apiKey = (string) mdb_config('SEARCH_API_KEY', '');
if ($apiKey === '') {
    search_out(400, ['error' => 'no_api_key', 'message' => '検索APIキーが未設定です。config.local.php に SEARCH_API_KEY を設定してください。']);
}
$model = (string) mdb_config('SEARCH_MODEL', 'gpt-4o-mini');

// 対象顧客を取得
$data = load_data();
$id = (string) ($body['id'] ?? '');
$idx = null;
foreach ($data['clients'] as $i => $c) {
    if (($c['id'] ?? '') === $id) { $idx = $i; break; }
}
if ($idx === null) {
    search_out(404, ['error' => 'client_not_found', 'message' => '対象の顧客が見つかりませんでした。']);
}
$client  = $data['clients'][$idx];
$name    = trim((string) ($client['name'] ?? ''));
$address = trim((string) ($client['address'] ?? ''));
if ($name === '') {
    search_out(400, ['error' => 'no_name', 'message' => '会社名が空です。']);
}

// プロンプト（同一企業に絞ってURLを多く挙げてもらう）
$prompt = "次の会社が掲載・登録されているウェブページ（公式サイト、ポータル/媒体サイト、SNS、口コミ、地図、求人サイトなど）を"
        . "ウェブ検索で調べ、同じ会社だと確信できるページのURLをできるだけ多く挙げてください。\n"
        . "会社名: {$name}\n住所: {$address}\n"
        . "見つけた各ページは必ず出典(URL)付きで示してください。";

$payload = json_encode([
    'model' => $model,
    'tools' => [['type' => 'web_search_preview']],
    'input' => $prompt,
], JSON_UNESCAPED_UNICODE);

// OpenAI Responses API 呼び出し
$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    search_out(502, ['error' => 'connect_failed', 'message' => '検索APIに接続できませんでした：' . $curlErr]);
}
$json = json_decode($resp, true);
if ($httpCode !== 200) {
    $msg = $json['error']['message'] ?? ('HTTP ' . $httpCode);
    search_out(502, ['error' => 'api_error', 'message' => '検索APIエラー：' . $msg]);
}

// 応答からURLを収集（'url'キーを再帰収集 ＋ テキスト中のURLも抽出）
$found = extract_found_media($json);

// 保存
$client['foundMedia'] = $found;
$client['searchedAt'] = date('Y-m-d H:i');
$data['clients'][$idx] = $client;
save_data($data);

search_out(200, [
    'status'     => 'success',
    'count'      => count($found),
    'foundMedia' => $found,
    'searchedAt' => $client['searchedAt'],
]);

/**
 * APIレスポンス(JSON)から掲載URLを抽出し、[{url, domain, title}] を返す。
 * - 引用(url_citation等)の 'url' を再帰的に収集
 * - テキスト中の http(s) URL も拾う
 * - 検索エンジン等のノイズドメインは除外、URL単位で重複排除
 */
function extract_found_media($node): array
{
    $urls = [];
    $titles = [];
    $walk = function ($n) use (&$walk, &$urls, &$titles) {
        if (!is_array($n)) {
            return;
        }
        if (isset($n['url']) && is_string($n['url']) && preg_match('#^https?://#i', $n['url'])) {
            $urls[$n['url']] = $n['url'];
            if (!empty($n['title']) && is_string($n['title'])) {
                $titles[$n['url']] = $n['title'];
            }
        }
        foreach ($n as $v) {
            if (is_string($v)) {
                if (preg_match_all('#https?://[^\s)"\'<>\]]+#', $v, $m)) {
                    foreach ($m[0] as $u) {
                        $u = rtrim($u, '.,);');
                        $urls[$u] = $u;
                    }
                }
            } elseif (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($node);

    $noise = ['openai.com', 'bing.com', 'google.com', 'www.google.com', 'duckduckgo.com', 'vertexaisearch.cloud.google.com'];
    $found = [];
    foreach ($urls as $u) {
        $host = parse_url($u, PHP_URL_HOST);
        if (!$host) {
            continue;
        }
        $host = preg_replace('#^www\.#i', '', strtolower($host));
        if (in_array($host, $noise, true)) {
            continue;
        }
        $found[] = ['url' => $u, 'domain' => $host, 'title' => ($titles[$u] ?? '')];
    }
    return $found;
}
