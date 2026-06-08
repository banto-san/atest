<?php
declare(strict_types=1);

/**
 * 当月のOpenAI使用額を返すAPI
 * --------------------------------------------------------------------------
 * OpenAIの Costs API（管理者キー sk-admin-... が必要）から当月の使用額(USD)を取得し、
 * 円換算して返す。結果は cost_cache.json に30分キャッシュ（毎回叩かない）。
 *
 * OPENAI_PROJECT_ID を設定すると「そのプロジェクト分だけ」を表示（＝このサイト分）。
 * 取得は group_by=project_id でまとめて行い、プロジェクト分と組織全体の両方を集計する
 * （プロジェクト分が0で全体に利用がある場合は、キーの取り違えを知らせるヒントを返す）。
 *
 * GET cost.php → { enabled, scope, month, project, usd, orgUsd, jpy, rate, fetchedAt, note? }
 *               または { enabled:false } / { enabled:true, error }
 * 設定: config.local.php の OPENAI_ADMIN_KEY（必須）, USD_JPY_RATE（任意・既定155）, OPENAI_PROJECT_ID（任意）
 */

require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['enabled' => false, 'error' => 'unauthorized']);
    exit;
}

$adminKey = trim((string) mdb_config('OPENAI_ADMIN_KEY', ''));
if ($adminKey === '') {
    echo json_encode(['enabled' => false]);   // 未設定 → 表示しない
    exit;
}

$rate      = (float) mdb_config('USD_JPY_RATE', 155);
$projectId = trim((string) mdb_config('OPENAI_PROJECT_ID', ''));   // 指定でそのプロジェクト分のみ
$month     = gmdate('Y-m');
$cacheFile = __DIR__ . '/cost_cache.json';
$now       = time();

// キャッシュ（同月・同プロジェクト・30分以内）。レートは都度反映。
if (is_file($cacheFile)) {
    $c = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($c) && ($c['month'] ?? '') === $month && ($c['project'] ?? '') === $projectId && ($now - (int) ($c['fetchedAt'] ?? 0)) < 1800) {
        $c['rate'] = $rate;
        $c['jpy']  = (int) round(((float) ($c['usd'] ?? 0)) * $rate);
        echo json_encode($c, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 当月1日(UTC)から、プロジェクト別に集計して取得（絞り込みはサーバ任せにせず手元で合計）。
$start = strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');
$url   = 'https://api.openai.com/v1/organization/costs?start_time=' . $start . '&bucket_width=1d&limit=62&group_by=project_id';
$r = mdb_http('GET', $url, ['Authorization: Bearer ' . $adminKey]);

if ($r['status'] === 401 || $r['status'] === 403) {
    echo json_encode(['enabled' => true, 'error' => '金額の取得には管理者キー(sk-admin-)が必要です。通常のAPIキーでは取得できません。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($r['status'] !== 200) {
    echo json_encode(['enabled' => true, 'error' => '使用額を取得できませんでした (HTTP ' . $r['status'] . ')。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// プロジェクト分・組織全体をまとめて集計する。
$d          = json_decode($r['body'], true);
$orgUsd     = 0.0;
$projUsd    = 0.0;
$sawProject = false;   // results に project_id が入っていたか（group_byが効いたか）
foreach (($d['data'] ?? []) as $bucket) {
    foreach (($bucket['results'] ?? []) as $res) {
        $val = (float) ($res['amount']['value'] ?? 0);
        $orgUsd += $val;
        $pid = $res['project_id'] ?? null;
        if ($pid !== null) {
            $sawProject = true;
        }
        if ($projectId !== '' && $pid === $projectId) {
            $projUsd += $val;
        }
    }
}

$usd = $projectId !== '' ? $projUsd : $orgUsd;

$out = [
    'enabled'   => true,
    'scope'     => $projectId !== '' ? 'project' : 'org',
    'month'     => $month,
    'project'   => $projectId,
    'usd'       => round($usd, 2),
    'orgUsd'    => round($orgUsd, 2),
    'jpy'       => (int) round($usd * $rate),
    'rate'      => $rate,
    'fetchedAt' => $now,
];

// 診断ヒント：プロジェクト指定なのに0で、全体には利用がある＝キーの取り違え等の可能性。
if ($projectId !== '') {
    if (!$sawProject) {
        $out['note'] = 'プロジェクト別の内訳を取得できませんでした。Project ID（proj_…）が正しいか確認してください。';
    } elseif ($projUsd <= 0 && $orgUsd > 0) {
        $out['note'] = 'このプロジェクトの当月利用は$0です。検索キー(SEARCH_API_KEY)がこのプロジェクトで発行したものか確認してください（古いキーでの利用分は別プロジェクト扱いです）。';
    }
}

@file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
