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

// プロンプト：必ずウェブ検索し、見つかったURLを本文にも列挙させる（取りこぼし防止）
$prompt = "あなたは企業の掲載先を調べる調査アシスタントです。"
        . "必ずウェブ検索ツールを使って、次の会社が掲載・登録されている実在のウェブページ"
        . "（公式サイト、ポータル/媒体サイト、SNS、口コミ、地図、求人サイト等）を調べてください。\n"
        . "会社名: {$name}\n住所: {$address}\n"
        . "同じ会社だと確信できるページについて、実際にアクセスできる完全なURL（http から始まる形）を本文に1行ずつ列挙してください。"
        . "存在しないURL・例示・省略形（…）は書かないでください。出典(引用)も必ず付けてください。";

// OpenAI Responses API 呼び出し（まず検索を強制。弾かれたら強制なしで再試行）
$callOpenAI = function (bool $force) use ($apiKey, $model, $prompt): array {
    $body = [
        'model' => $model,
        'tools' => [['type' => 'web_search_preview']],
        'input' => $prompt,
    ];
    if ($force) {
        $body['tool_choice'] = ['type' => 'web_search_preview'];
    }
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['status' => $code, 'body' => $resp === false ? '' : (string) $resp, 'error' => $resp === false ? $err : ''];
};

$r = $callOpenAI(true);
if ($r['status'] !== 200) {
    $r2 = $callOpenAI(false);   // tool_choice等で弾かれたら強制なしで再試行
    if ($r2['status'] === 200) {
        $r = $r2;
    }
}

if ($r['status'] === 0 || $r['body'] === '') {
    search_out(502, ['error' => 'connect_failed', 'message' => '検索APIに接続できませんでした：' . $r['error']]);
}
$json = json_decode($r['body'], true);
if ($r['status'] !== 200) {
    $msg = $json['error']['message'] ?? ('HTTP ' . $r['status']);
    search_out(502, ['error' => 'api_error', 'message' => '検索APIエラー：' . $msg]);
}

// 応答からURL収集 ＋ AIの本文（0件時の原因確認用）
$found  = extract_found_media($json);
if (mdb_config('VERIFY_URLS', true)) {
    $found = filter_reachable($found);   // 実際にアクセスできるURLだけに絞る（リンク切れ除外）
}
$aiText = extract_text($json);

$client['foundMedia'] = $found;
$client['searchedAt'] = date('Y-m-d H:i');
if (count($found) === 0) {
    $client['searchNote'] = mb_substr(trim($aiText), 0, 300);   // 0件のときAIの返答を残す
} else {
    unset($client['searchNote']);
}
$data['clients'][$idx] = $client;
save_data($data);

search_out(200, [
    'status'     => 'success',
    'count'      => count($found),
    'foundMedia' => $found,
    'searchedAt' => $client['searchedAt'],
    'note'       => $client['searchNote'] ?? '',
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

    $noise = ['openai.com', 'bing.com', 'www.bing.com', 'google.com', 'www.google.com', 'duckduckgo.com', 'search.brave.com', 'vertexaisearch.cloud.google.com'];
    $found = [];
    foreach ($urls as $u) {
        $host = parse_url($u, PHP_URL_HOST);
        if (!$host) {
            continue;
        }
        $host = preg_replace('#^www\.#i', '', strtolower((string) $host));
        $host = preg_replace('/[^a-z0-9.\-].*$/', '', $host);  // 末尾のゴミ（…等）以降を切り捨て
        $host = trim($host, '.');
        if ($host === '' || in_array($host, $noise, true)) {
            continue;
        }
        if (!mdb_valid_domain($host)) {     // 「…」などURLでないものを除外
            continue;
        }
        // URL自体が非ASCIIで壊れている場合は、ドメインだけの安全なURLに置き換える
        $cleanUrl = preg_match('/^[\x21-\x7e]+$/', $u) ? $u : ('https://' . $host);
        $found[] = ['url' => $cleanUrl, 'domain' => $host, 'title' => ($titles[$u] ?? '')];
    }
    return $found;
}

/** ドメインが実在しうる形式か（ASCIIのみ・正しいTLD）を厳密に判定。「…」等のゴミを弾く。 */
function mdb_valid_domain(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '' || strlen($host) > 253) {
        return false;
    }
    if (!preg_match('/^[\x21-\x7e]+$/', $host)) {   // 表示可能ASCII以外（…・全角等）は不可
        return false;
    }
    return (bool) preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host);
}

/**
 * 到達可能なURLだけに絞る（リンク切れ等を除外）。curl_multi で並列に確認する。
 * - 「あり」とみなす: 2xx/3xx、または存在するがブロック/制限される 401/403/405/429
 * - 除外: 接続不可/DNS失敗(0)・404・410・5xx
 * - 全滅した場合は誤検知の可能性があるため、元のリストをそのまま返す
 */
function filter_reachable(array $found): array
{
    if (count($found) === 0) {
        return $found;
    }
    $found = array_slice($found, 0, 30);   // 多すぎる場合の上限（時間対策）
    $mh = curl_multi_init();
    $handles = [];
    foreach ($found as $i => $m) {
        $ch = curl_init($m['url']);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,    // HEADで軽く確認
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 7,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,   // 到達確認のみのため緩める
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MediaDB/1.0)',
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0);

    $kept = [];
    foreach ($handles as $i => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $ok = ($code >= 200 && $code < 400) || in_array($code, [401, 403, 405, 429], true);
        if ($ok) {
            $kept[] = $found[$i];
        }
    }
    curl_multi_close($mh);
    return $kept !== [] ? $kept : $found;   // 全滅時は誤検知回避で元を返す
}

/** APIレスポンスからAIの本文テキストを取り出す（0件時の原因確認・表示用） */
function extract_text($node): string
{
    $out = [];
    // まず output_text の text を集める
    $walk = function ($n) use (&$walk, &$out) {
        if (!is_array($n)) {
            return;
        }
        if (($n['type'] ?? '') === 'output_text' && isset($n['text']) && is_string($n['text'])) {
            $out[] = $n['text'];
        }
        foreach ($n as $v) {
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($node);
    // 取れなければ 'text' キーをかき集める
    if (!$out) {
        $walk2 = function ($n) use (&$walk2, &$out) {
            if (!is_array($n)) {
                return;
            }
            if (isset($n['text']) && is_string($n['text'])) {
                $out[] = $n['text'];
            }
            foreach ($n as $v) {
                if (is_array($v)) {
                    $walk2($v);
                }
            }
        };
        $walk2($node);
    }
    return trim(implode("\n", array_values(array_unique($out))));
}

