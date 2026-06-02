<?php
declare(strict_types=1);

/**
 * Push API エンドポイント（スキャナCLI 用・仕様書 §6）。
 * --------------------------------------------------------------------------
 * POST /api.php?action=push&group=<group_id>
 *   ヘッダ: Authorization: Bearer <個人用トークン>
 *   ボディ: JSON { "apis": [ {name, provider, key_location, detected_by[], usages[]} ] }
 *
 * 認証は個人用トークン（Web UIの設定画面で発行）。トークン所有者がそのグループの
 * member 以上であることをサーバ側で検証し、自動フィールドのみマージする。
 */

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

// Bearer トークン取得（全アクション共通）
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('apache_request_headers')) {
    $hdrs = apache_request_headers();
    $auth = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
}
if (!preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
    json_out(401, ['error' => 'missing_token', 'hint' => 'Authorization: Bearer <token>']);
}
$tokenUser = verify_api_token(trim($m[1]));
if (!$tokenUser) {
    json_out(401, ['error' => 'invalid_token']);
}

/** トークン所有者の所属グループ（診断用） */
function token_user_memberships(int $uid): array
{
    $st = db()->prepare(
        'SELECT m.group_id, g.name, m.role FROM memberships m JOIN groups g ON g.id = m.group_id WHERE m.user_id = :u ORDER BY m.group_id'
    );
    $st->execute([':u' => $uid]);
    return $st->fetchAll();
}

// 診断: このトークンが誰で、どのグループに何の権限で属しているかを返す
if ($action === 'whoami') {
    json_out(200, [
        'ok'          => true,
        'token_user'  => ['id' => (int) $tokenUser['id'], 'email' => $tokenUser['email'], 'name' => $tokenUser['name']],
        'memberships' => token_user_memberships((int) $tokenUser['id']),
        'hint'        => 'memberships に出ているグループ番号(group_id)を --group に使い、role が member 以上である必要があります。',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'push') {
    json_out(404, ['error' => 'not_found', 'hint' => 'POST /api.php?action=push&group=<id> または GET ?action=whoami']);
}

$gid = (int) ($_GET['group'] ?? 0);
if ($gid <= 0) {
    json_out(400, ['error' => 'missing_group']);
}

// トークン所有者がそのグループの member 以上か（サーバ側で必ずチェック）
$roleStmt = db()->prepare('SELECT role FROM memberships WHERE group_id = :g AND user_id = :u');
$roleStmt->execute([':g' => $gid, ':u' => $tokenUser['id']]);
$role = $roleStmt->fetchColumn();
if ($role === false || role_rank((string) $role) < ROLE_RANK['member']) {
    // 診断情報つきで返す（なぜ403かが分かるように）
    json_out(403, [
        'error'           => 'forbidden',
        'hint'            => 'このグループで member 以上の権限が必要です。下の your_memberships のグループ番号を --group に指定してください。',
        'token_user'      => ['id' => (int) $tokenUser['id'], 'email' => $tokenUser['email']],
        'requested_group' => $gid,
        'role_in_group'   => $role === false ? null : $role,
        'your_memberships'=> token_user_memberships((int) $tokenUser['id']),
    ]);
}

$raw = (string) file_get_contents('php://input');

// WAF(SiteGuard)対策: 本文は gzip+base64 でエンコードされて届くことがある。
// X-Payload-Encoding ヘッダがあれば復号する（無ければ素のJSONとして扱う＝後方互換）。
$enc = $_SERVER['HTTP_X_PAYLOAD_ENCODING'] ?? '';
if ($enc !== '') {
    $dec = base64_decode($raw, true);
    if ($dec !== false) {
        if (stripos($enc, 'gzip') !== false && function_exists('gzdecode')) {
            $gun = @gzdecode($dec);
            $raw = ($gun !== false) ? $gun : $dec;
        } else {
            $raw = $dec;
        }
    }
}

$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['apis']) || !is_array($body['apis'])) {
    json_out(400, ['error' => 'invalid_payload', 'hint' => '{ "apis": [ ... ] }']);
}

try {
    $result = merge_scan_results($gid, $body['apis']);
} catch (Throwable $e) {
    json_out(500, ['error' => 'merge_failed', 'detail' => $e->getMessage()]);
}

json_out(200, ['ok' => true, 'group' => $gid] + $result);
