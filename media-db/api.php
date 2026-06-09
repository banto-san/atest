<?php
declare(strict_types=1);

/**
 * データ保存API
 * --------------------------------------------------------------------------
 * 各ページのフロント(Vue)から呼ばれるJSONエンドポイント。
 *   GET  api.php            … 現在のデータ(users/media/clients)を返す
 *   POST api.php            … 送られてきたデータで data.json を上書き保存
 *
 * 認証はPHPセッション（ログイン必須）。POST時は CSRF トークン(X-CSRF-Token)を検証。
 */

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/** JSONを返して終了 */
function json_out(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ログイン必須（未ログインは401）
if (!current_user()) {
    json_out(401, ['error' => 'unauthorized', 'message' => 'ログインが必要です。']);
}

// 取得
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(200, load_data());
}

// 保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 検証（ヘッダ優先、無ければボディの csrf）
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    $raw  = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        json_out(400, ['error' => 'invalid_json', 'message' => '無効なデータ形式です。']);
    }
    if ($token === null && isset($body['csrf'])) {
        $token = (string) $body['csrf'];
    }
    if (!check_csrf($token)) {
        json_out(400, ['error' => 'bad_csrf', 'message' => 'セッションが切れました。ページを再読み込みしてください。']);
    }

    // 必須キーの最低限チェック
    if (!isset($body['clients']) || !is_array($body['clients'])) {
        json_out(400, ['error' => 'invalid_payload', 'message' => 'clients が含まれていません。']);
    }

    // アカウント(users)・除外ドメインは管理者のみ変更可。非管理者の保存では既存を維持する。
    $current = load_data();
    $users          = is_admin() ? ($body['users'] ?? []) : ($current['users'] ?? []);
    $excludeDomains = is_admin() ? ($body['excludeDomains'] ?? ($current['excludeDomains'] ?? [])) : ($current['excludeDomains'] ?? []);

    $ok = save_data([
        'users'          => $users,
        'media'          => $body['media']   ?? [],
        'clients'        => $body['clients'] ?? [],
        'excludeDomains' => $excludeDomains,
    ]);

    if ($ok) {
        json_out(200, ['status' => 'success']);
    }
    json_out(500, ['error' => 'save_failed', 'message' => 'ファイル保存に失敗しました。']);
}

json_out(405, ['error' => 'method_not_allowed']);
