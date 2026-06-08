<?php
declare(strict_types=1);

/**
 * 独ドメげっと：会社「自身の公式サイト（独自ドメイン）」を1件だけ特定して返すAPI
 * --------------------------------------------------------------------------
 * POST dokudome-search.php  body: { name, person, phone, industry }  ヘッダ: X-CSRF-Token
 * 認証: ログイン必須＋管理者のみ（有料APIのため）。
 * 返り値: { found:bool, url, domain, note }  または { error, message }
 *
 * SNS・予約・ポータル・求人・口コミ・無料HP等は除外し、ユーザー設定の除外ドメインも適用する。
 * 残った中で実際にアクセスできる先頭を「公式サイト」とみなす。無ければ空白(found:false)。
 */

require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(120);

function dd_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!current_user()) {
    dd_out(401, ['error' => 'unauthorized', 'message' => 'ログインが必要です。']);
}
if (!is_admin()) {
    dd_out(403, ['error' => 'forbidden', 'message' => 'この検索は管理者のみ実行できます。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dd_out(405, ['error' => 'method_not_allowed']);
}

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    dd_out(400, ['error' => 'invalid_json', 'message' => '無効なリクエストです。']);
}
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf'] ?? null);
if (!check_csrf($token)) {
    dd_out(400, ['error' => 'bad_csrf', 'message' => 'セッションが切れました。ページを再読み込みしてください。']);
}

$apiKey = (string) mdb_config('SEARCH_API_KEY', '');
if ($apiKey === '') {
    dd_out(400, ['error' => 'no_api_key', 'message' => '検索APIキーが未設定です。config.local.php に SEARCH_API_KEY を設定してください。']);
}
$model = (string) mdb_config('SEARCH_MODEL', 'gpt-4o-mini');

$name     = trim((string) ($body['name'] ?? ''));
$person   = trim((string) ($body['person'] ?? ''));
$phone    = trim((string) ($body['phone'] ?? ''));
$industry = trim((string) ($body['industry'] ?? ''));
if ($name === '' && $phone === '') {
    dd_out(400, ['error' => 'no_query', 'message' => '会社名または電話番号を入力してください。']);
}

// 除外ドメイン（既定の定番＋Excel由来の大量リスト＋ユーザー設定）。サフィックス/ラベルで照合。
$data        = load_data();
$userExclude = array_map('strval', $data['excludeDomains'] ?? []);
[$domSet, $kwSet] = dd_build_exclude_sets($userExclude);

// プロンプト（公式サイト＝独自ドメインを1つだけ）
$lines = ["会社名: {$name}"];
if ($person !== '')   { $lines[] = "担当者名: {$person}"; }
if ($phone !== '')    { $lines[] = "電話番号: {$phone}（番号の一致は強い手がかり）"; }
if ($industry !== '') { $lines[] = "業種: {$industry}"; }
$info = implode("\n", $lines);

$prompt = "あなたは企業調査の専門家です。必ずウェブ検索を使い、次の会社“自身が運営する公式ウェブサイト（独自ドメイン）”を1つだけ特定してください。\n"
        . $info . "\n"
        . "重要なルール:\n"
        . "・返してよいのは、その会社が自前で持つ公式サイト（独自ドメイン）のみ。\n"
        . "・次は絶対に含めない: SNS(facebook/x/instagram/LINE等)、予約・グルメ・美容(ホットペッパー/食べログ/ぐるなび等)、"
        . "地図・ポータル・口コミ(Google/Yahoo/エキテン/iタウンページ/NAVITIME等)、求人(Indeed/エンゲージ/マイナビ/スタンバイ等)、"
        . "無料ブログ・無料HP作成(アメブロ/note/Jimdo/Wix/FC2等)、その他他社が運営する媒体。\n"
        . "・確実に同じ会社の公式サイトだと判断できる場合のみ。確証がなければ「なし」。\n"
        . "・出力は公式サイトのURLを1行だけ。見つからなければ「なし」とだけ書く。出典(引用)も付ける。";

// OpenAI Responses API（網羅性high。弾かれたら素の構成で再試行）
$callOpenAI = function (array $tools, bool $force) use ($apiKey, $model, $prompt): array {
    $req = ['model' => $model, 'tools' => $tools, 'input' => $prompt];
    if ($force) {
        $req['tool_choice'] = ['type' => 'web_search_preview'];
    }
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($req, JSON_UNESCAPED_UNICODE),
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

$r = $callOpenAI([['type' => 'web_search_preview', 'search_context_size' => 'high']], true);
if ($r['status'] !== 200) {
    $r2 = $callOpenAI([['type' => 'web_search_preview']], false);
    if ($r2['status'] === 200) {
        $r = $r2;
    }
}
if ($r['status'] === 0 || $r['body'] === '') {
    dd_out(502, ['error' => 'connect_failed', 'message' => '検索APIに接続できませんでした：' . $r['error']]);
}
$json = json_decode($r['body'], true);
if ($r['status'] !== 200) {
    $msg = $json['error']['message'] ?? ('HTTP ' . $r['status']);
    dd_out(502, ['error' => 'api_error', 'message' => '検索APIエラー：' . $msg]);
}

// 候補URL（引用優先）→ 妥当ドメイン＆非除外だけに絞る（ドメイン単位で1つ）
$candidates = dd_collect_urls($json);
$filtered   = [];
foreach ($candidates as $u) {
    $host = dd_host($u);
    if ($host === '' || !dd_valid_domain($host)) {
        continue;
    }
    if (dd_is_excluded($host, $domSet, $kwSet)) {
        continue;
    }
    if (!isset($filtered[$host])) {
        $filtered[$host] = $u;
    }
}

// 実際にアクセスできるものだけ残し、先頭を公式サイトとみなす
$reachable = dd_filter_reachable(array_values($filtered));
$official  = $reachable[0] ?? null;

if ($official === null) {
    dd_out(200, ['found' => false, 'url' => '', 'domain' => '', 'note' => '独自ドメインは見つかりませんでした（空白）。']);
}
dd_out(200, ['found' => true, 'url' => $official, 'domain' => dd_host($official), 'note' => '']);


/* ============================ helpers ============================ */

/** 既定の定番除外ドメイン（parentレベル）。サブドメインもサフィックス一致で除外する。 */
function dd_builtin_domains(): array
{
    return [
        // SNS・ブログ
        'facebook.com', 'fb.com', 'instagram.com', 'twitter.com', 'x.com', 'tiktok.com',
        'youtube.com', 'youtu.be', 'linkedin.com', 'pinterest.com', 'threads.net',
        'line.me', 'lin.ee', 'note.com', 'ameblo.jp', 'ameba.jp', 'hatenablog.com',
        'hatena.ne.jp', 'blogspot.com', 'livedoor.jp', 'livedoor.blog', 'jugem.jp',
        'seesaa.net', 'webry.info', 'cocolog-nifty.com', 'exblog.jp', 'goo.ne.jp',
        // 予約・グルメ・美容
        'hotpepper.jp', 'tabelog.com', 'gnavi.co.jp', 'retty.me', 'ikyu.com', 'jalan.net',
        'rakuten.co.jp', 'owst.jp', 'foodre.jp', 'hitosara.com', 'ozmall.co.jp', 'epark.jp',
        'minimo.jp',
        // 地図・ポータル・口コミ
        'google.com', 'google.co.jp', 'goo.gl', 'yahoo.co.jp', 'navitime.co.jp',
        'mapion.co.jp', 'ekiten.jp', 'itp.ne.jp', 'its-mo.com', 'yelp.com', 'yelp.co.jp',
        // 求人
        'indeed.com', 'en-gage.net', 'mynavi.jp', 'rikunabi.com', 'doda.jp', 'baitoru.com',
        'townwork.net', 'stanby.com', 'wantedly.com', 'green-japan.com', 'type.jp',
        'jobmedley.com', 'itszai.jp',
        // 無料HP作成・ISP/レンタル・ECモール（独自ドメインではない）
        'jimdo.com', 'jimdofree.com', 'wix.com', 'wixsite.com', 'web.fc2.com', 'fc2.com',
        'weebly.com', 'wordpress.com', 'goope.jp', 'shopinfo.jp', 'thebase.in', 'base.shop',
        'stores.jp', 'myshopify.com', 'sakura.ne.jp', 'so-net.ne.jp', 'ocn.ne.jp',
        'coocan.jp', 'plala.or.jp', 'biglobe.ne.jp', 'xmbs.jp', 'jbplt.jp', 'main.jp',
        'sub.jp', 'client.jp', 'skr.jp', 'ftw.jp', 'crayonsite.com', 'webcrow.jp',
        // 企業情報DB
        'baseconnect.in', 'salesnow.jp', 'houjin.jp', 'alarmbox.jp', 'mapfan.com',
    ];
}

/** 既定の定番除外キーワード（ホストのラベル一致で除外） */
function dd_builtin_keywords(): array
{
    return ['hotpepper', 'tabelog', 'gnavi', 'ekiten', 'jimdo', 'wix', 'fc2', 'ameblo'];
}

/**
 * 除外セットを構築：[ドメイン集合(サフィックス一致用), キーワード集合(ラベル一致用)]。
 * 既定の定番＋Excel由来の大量リスト(exclude_domains.txt / exclude_keywords.txt)＋ユーザー設定を結合。
 */
function dd_build_exclude_sets(array $userExtra): array
{
    $domains = dd_builtin_domains();
    $df = __DIR__ . '/exclude_domains.txt';   // Excel由来（任意）
    if (is_file($df)) {
        foreach (file($df, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $d = dd_norm_domain($line);
            if ($d !== '') {
                $domains[] = $d;
            }
        }
    }
    foreach ($userExtra as $u) {   // 管理画面で追加した分
        $d = dd_norm_domain((string) $u);
        if ($d !== '') {
            $domains[] = $d;
        }
    }
    $keywords = dd_builtin_keywords();
    $kf = __DIR__ . '/exclude_keywords.txt';
    if (is_file($kf)) {
        foreach (file($kf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $k = strtolower(trim($line));
            if ($k !== '') {
                $keywords[] = $k;
            }
        }
    }
    return [array_fill_keys($domains, true), array_fill_keys($keywords, true)];
}

/** 入力ドメイン文字列を正規化（http/パス/www を除去して小文字に） */
function dd_norm_domain(string $d): string
{
    $d = strtolower(trim($d));
    $d = (string) preg_replace('#^https?://#', '', $d);
    $d = (string) preg_replace('#/.*$#', '', $d);     // パス以降を除去
    $d = (string) preg_replace('#^www\.#', '', $d);
    return trim($d);
}

/** URLからホスト（www除去・末尾ゴミ除去・小文字） */
function dd_host(string $u): string
{
    $h = parse_url($u, PHP_URL_HOST);
    if (!$h) {
        return '';
    }
    $h = (string) preg_replace('#^www\.#i', '', strtolower((string) $h));
    $h = (string) preg_replace('/[^a-z0-9.\-].*$/', '', $h);
    return trim($h, '.');
}

/** 実在しうるドメイン形式か（ASCII＋正しいTLD） */
function dd_valid_domain(string $host): bool
{
    if ($host === '' || strlen($host) > 253) {
        return false;
    }
    if (!preg_match('/^[\x21-\x7e]+$/', $host)) {
        return false;
    }
    return (bool) preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host);
}

/** host が除外対象か。ドメインはサフィックス一致、キーワードはラベル一致で判定（高速）。 */
function dd_is_excluded(string $host, array $domSet, array $kwSet): bool
{
    $labels = explode('.', $host);
    $n = count($labels);
    // サフィックス一致（host 自身 〜 親ドメイン。TLD単独は対象外）
    for ($i = 0; $i < $n - 1; $i++) {
        $suffix = implode('.', array_slice($labels, $i));
        if (isset($domSet[$suffix])) {
            return true;
        }
    }
    // ラベル一致（媒体名キーワード）
    foreach ($labels as $L) {
        if ($L !== '' && isset($kwSet[$L])) {
            return true;
        }
    }
    return false;
}

/** APIレスポンスから候補URLを集める（引用を先、本文を後） */
function dd_collect_urls($node): array
{
    $cite = [];
    $text = [];
    $walk = function ($n) use (&$walk, &$cite, &$text) {
        if (!is_array($n)) {
            return;
        }
        if (isset($n['url']) && is_string($n['url']) && preg_match('#^https?://#i', $n['url'])) {
            $cite[$n['url']] = $n['url'];
        }
        foreach ($n as $v) {
            if (is_string($v)) {
                if (preg_match_all('#https?://[^\s)"\'<>\]]+#', $v, $m)) {
                    foreach ($m[0] as $u) {
                        $u = rtrim($u, '.,);');
                        $text[$u] = $u;
                    }
                }
            } elseif (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($node);
    return array_values(array_merge(array_values($cite), array_values($text)));
}

/** 到達可能なURLだけを入力順で返す（GET＋ステータス＋ソフト404判定） */
function dd_filter_reachable(array $urls): array
{
    if (count($urls) === 0) {
        return $urls;
    }
    $urls   = array_slice($urls, 0, 15);
    $cap    = 120000;
    $bodies = [];
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $u) {
        $bodies[$i] = '';
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MediaDB/1.0)',
            CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$bodies, $i, $cap) {
                if (strlen($bodies[$i]) < $cap) {
                    $bodies[$i] .= $chunk;
                }
                return strlen($bodies[$i]) >= $cap ? 0 : strlen($chunk);
            },
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
        $statusOk = ($code >= 200 && $code < 400) || in_array($code, [401, 403, 405, 429], true);
        if (!$statusOk) {
            continue;
        }
        if ($code >= 200 && $code < 300 && dd_looks_not_found($bodies[$i] ?? '')) {
            continue;
        }
        $kept[] = $urls[$i];
    }
    curl_multi_close($mh);
    return $kept;
}

/** ページ本文の<title>が「見つかりません/404」等ならtrue（ソフト404検出） */
function dd_looks_not_found(string $body): bool
{
    if ($body === '') {
        return false;
    }
    $enc = mb_detect_encoding($body, ['UTF-8', 'SJIS-win', 'EUC-JP', 'ISO-2022-JP'], true) ?: 'UTF-8';
    if ($enc !== 'UTF-8') {
        $body = (string) @mb_convert_encoding($body, 'UTF-8', $enc);
    }
    if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
        return false;
    }
    $t = mb_strtolower(trim($m[1]));
    if ($t === '') {
        return false;
    }
    $markers = ['404', 'not found', 'page not found', "isn't available", 'ページが見つかり', '見つかりませんでした', 'お探しのページ', 'ページがありません', 'ページは存在しません', 'アカウントは存在しません', 'コンテンツが見つかり', 'ご利用いただけません'];
    foreach ($markers as $kw) {
        if (mb_strpos($t, mb_strtolower($kw)) !== false) {
            return true;
        }
    }
    return false;
}
