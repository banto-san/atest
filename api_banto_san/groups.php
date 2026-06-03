<?php
declare(strict_types=1);

/**
 * グループ管理 — 作成 / メンバー招待(メール) / ロール変更 / 削除。
 * すべての更新操作はサーバ側で権限チェックする。
 *   - グループ作成: ログインユーザーなら誰でも（作成者が owner）
 *   - メンバー/招待/ロール管理: admin 以上
 *   - グループ削除: owner のみ
 */

require __DIR__ . '/bootstrap.php';

$user = require_login();
$pdo  = db();

/* ------------------------------------------------------------------ *
 *  POST 処理
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_group') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash('err', 'グループ名を入力してください。');
            redirect('groups.php');
        }
        $pdo->prepare('INSERT INTO groups (name, created_by, created_at) VALUES (:n,:u,:c)')
            ->execute([':n' => $name, ':u' => $user['id'], ':c' => now()]);
        $newGid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO memberships (group_id, user_id, role, created_at) VALUES (:g,:u,\'owner\',:c)')
            ->execute([':g' => $newGid, ':u' => $user['id'], ':c' => now()]);
        set_current_group($newGid);
        flash('ok', 'グループ「' . $name . '」を作成しました。');
        redirect('groups.php?gid=' . $newGid);
    }

    // 以降は対象グループに対する操作。gid を検証。
    $gid = (int) ($_POST['gid'] ?? 0);
    if ($gid <= 0 || role_in_group($gid) === null) {
        http_response_code(403);
        exit('そのグループにアクセスできません。');
    }

    if ($action === 'invite') {
        require_role_at_least($gid, 'admin');
        $email = trim((string) ($_POST['email'] ?? ''));
        $role  = array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : 'member';
        if ($role === 'owner') { $role = 'admin'; }   // 招待で owner は付与しない
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('err', '有効なメールアドレスを入力してください。');
            redirect('groups.php?gid=' . $gid);
        }
        // 既にメンバーか
        $exists = $pdo->prepare(
            'SELECT 1 FROM memberships m JOIN users u ON u.id = m.user_id
             WHERE m.group_id = :g AND LOWER(u.email) = LOWER(:e)'
        );
        $exists->execute([':g' => $gid, ':e' => $email]);
        if ($exists->fetchColumn()) {
            flash('err', 'そのユーザーは既にメンバーです。');
            redirect('groups.php?gid=' . $gid);
        }
        $pdo->prepare(
            'INSERT INTO invites (group_id, email, role, invited_by, created_at) VALUES (:g,:e,:r,:by,:c)
             ON CONFLICT(group_id, email) DO UPDATE SET role = excluded.role'
        )->execute([':g' => $gid, ':e' => $email, ':r' => $role, ':by' => $user['id'], ':c' => now()]);
        flash('ok', $email . ' を招待しました（そのメールでGoogleログインすると参加します）。');
        redirect('groups.php?gid=' . $gid);
    }

    if ($action === 'cancel_invite') {
        require_role_at_least($gid, 'admin');
        $iid = (int) ($_POST['invite_id'] ?? 0);
        $pdo->prepare('DELETE FROM invites WHERE id = :id AND group_id = :g')->execute([':id' => $iid, ':g' => $gid]);
        flash('ok', '招待を取り消しました。');
        redirect('groups.php?gid=' . $gid);
    }

    if ($action === 'change_role') {
        require_role_at_least($gid, 'admin');
        $targetUid = (int) ($_POST['user_id'] ?? 0);
        $newRole   = array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : null;
        if ($newRole === null) {
            redirect('groups.php?gid=' . $gid);
        }
        // 最後の owner を降格させない
        if ($newRole !== 'owner' && is_last_owner($gid, $targetUid)) {
            flash('err', '最後のオーナーのロールは変更できません。');
            redirect('groups.php?gid=' . $gid);
        }
        // owner への昇格は owner のみ可
        if ($newRole === 'owner' && current_role_for($gid) !== 'owner') {
            flash('err', 'オーナーへの変更はオーナーのみ可能です。');
            redirect('groups.php?gid=' . $gid);
        }
        $pdo->prepare('UPDATE memberships SET role = :r WHERE group_id = :g AND user_id = :u')
            ->execute([':r' => $newRole, ':g' => $gid, ':u' => $targetUid]);
        flash('ok', 'ロールを変更しました。');
        redirect('groups.php?gid=' . $gid);
    }

    if ($action === 'remove_member') {
        require_role_at_least($gid, 'admin');
        $targetUid = (int) ($_POST['user_id'] ?? 0);
        if (is_last_owner($gid, $targetUid)) {
            flash('err', '最後のオーナーは削除できません。');
            redirect('groups.php?gid=' . $gid);
        }
        $pdo->prepare('DELETE FROM memberships WHERE group_id = :g AND user_id = :u')
            ->execute([':g' => $gid, ':u' => $targetUid]);
        flash('ok', 'メンバーを削除しました。');
        redirect('groups.php?gid=' . $gid);
    }

    if ($action === 'delete_group') {
        if (current_role_for($gid) !== 'owner') {
            http_response_code(403);
            exit('グループを削除できるのはオーナーのみです。');
        }
        $pdo->prepare('DELETE FROM groups WHERE id = :g')->execute([':g' => $gid]);
        unset($_SESSION['current_group_id']);
        flash('ok', 'グループを削除しました。');
        redirect('groups.php');
    }

    redirect('groups.php');
}

/* ------------------------------------------------------------------ *
 *  ヘルパ（このページ用）
 * ------------------------------------------------------------------ */
function current_role_for(int $gid): ?string { return role_in_group($gid); }

function is_last_owner(int $gid, int $uid): bool
{
    $pdo = db();
    $role = $pdo->prepare('SELECT role FROM memberships WHERE group_id = :g AND user_id = :u');
    $role->execute([':g' => $gid, ':u' => $uid]);
    if ($role->fetchColumn() !== 'owner') {
        return false;
    }
    $owners = (int) $pdo->query("SELECT COUNT(*) FROM memberships WHERE group_id = $gid AND role = 'owner'")->fetchColumn();
    return $owners <= 1;
}

/* ------------------------------------------------------------------ *
 *  表示データ
 * ------------------------------------------------------------------ */
$memberships = my_memberships();

// 表示対象グループ（?gid= 指定 or 現在グループ）。必ず所属チェック。
$viewGid = (int) ($_GET['gid'] ?? (current_group_id() ?? 0));
$viewRole = $viewGid > 0 ? role_in_group($viewGid) : null;
if ($viewGid > 0 && $viewRole === null) {
    $viewGid = current_group_id() ?? 0;
    $viewRole = $viewGid > 0 ? role_in_group($viewGid) : null;
}

$viewGroup = null;
$members = [];
$invites = [];
if ($viewGid > 0) {
    $stmt = $pdo->prepare('SELECT * FROM groups WHERE id = :id');
    $stmt->execute([':id' => $viewGid]);
    $viewGroup = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.avatar_url, m.role
         FROM memberships m JOIN users u ON u.id = m.user_id
         WHERE m.group_id = :g
         ORDER BY CASE m.role WHEN \'owner\' THEN 0 WHEN \'admin\' THEN 1 WHEN \'member\' THEN 2 ELSE 3 END, u.name'
    );
    $stmt->execute([':g' => $viewGid]);
    $members = $stmt->fetchAll();

    if (role_rank($viewRole) >= ROLE_RANK['admin']) {
        $stmt = $pdo->prepare('SELECT * FROM invites WHERE group_id = :g ORDER BY created_at DESC');
        $stmt->execute([':g' => $viewGid]);
        $invites = $stmt->fetchAll();
    }
}

$canManage = $viewRole !== null && role_rank($viewRole) >= ROLE_RANK['admin'];
$isOwner   = $viewRole === 'owner';
$flashMsg  = take_flash();
$csrf      = csrf_token();

/* small style helper reuse */
function gstyles(): void { ?>
<style>
    :root{--bg:#f5f6f8;--card:#fff;--line:#e3e6ea;--ink:#1f2733;--muted:#8a93a0;--accent:#2563eb;--accent-d:#1d4ed8;--ok-bg:#e7f6ec;--ok-ink:#1a7f43;--err-bg:#fdecec;--err-ink:#b42318;}
    *{box-sizing:border-box;}
    .ic{vertical-align:-0.16em;}
    header.app h1 .ic{vertical-align:-0.18em;}
    body{margin:0;background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Hiragino Kaku Gothic ProN","Noto Sans JP",Meiryo,sans-serif;line-height:1.6;}
    header.app{background:#0f172a;color:#fff;padding:12px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    header.app h1{font-size:18px;margin:0;font-weight:700;}
    header.app .spacer{flex:1;}
    header.app a.navlink{color:#cbd5e1;text-decoration:none;font-size:13px;padding:4px 8px;border-radius:7px;}
    header.app a.navlink:hover{background:#1e293b;color:#fff;}
    .wrap{max-width:980px;margin:0 auto;padding:20px;}
    .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:18px;}
    .card h2{margin:0 0 12px;font-size:16px;}
    table{width:100%;border-collapse:collapse;}
    th,td{padding:9px 10px;text-align:left;border-bottom:1px solid var(--line);font-size:14px;vertical-align:middle;}
    th{background:#f0f2f5;font-size:12px;color:var(--muted);font-weight:600;}
    .muted{color:var(--muted);}
    button,.btn{font-size:14px;padding:7px 12px;border-radius:8px;border:1px solid var(--line);background:#fff;color:var(--ink);cursor:pointer;text-decoration:none;display:inline-block;}
    button.primary,.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;}
    button.danger{color:var(--err-ink);border-color:#f3c4c0;}
    input,select{padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;}
    .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;background:#eef1f4;color:#475569;}
    .pill.owner{background:#fef3c7;color:#92400e;} .pill.admin{background:#e0e7ff;color:#3730a3;}
    .pill.member{background:#e7f6ec;color:#1a7f43;} .pill.viewer{background:#eef1f4;color:#6b7280;}
    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
    .flash{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:14px;}
    .flash.ok{background:var(--ok-bg);color:var(--ok-ink);} .flash.err{background:var(--err-bg);color:var(--err-ink);}
    .grouplist a{display:block;padding:8px 10px;border-radius:8px;text-decoration:none;color:var(--ink);}
    .grouplist a.active{background:#eef4ff;color:var(--accent);font-weight:600;}
    .grouplist a:hover{background:#f5f6f8;}
    .hint{font-size:12px;color:var(--muted);}
</style>
<?php }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?= h(app_base_url()) ?>/favicon.svg">
<title><?= h(APP_NAME) ?> — グループ管理</title>
<?php gstyles(); ?>
</head>
<body>
<header class="app">
    <h1><?= icon('onsen', 20) ?> <?= h(APP_NAME) ?></h1>
    <span style="font-size:13px;color:#94a3b8">グループ管理</span>
    <span class="spacer"></span>
    <a class="navlink" href="index.php"><?= icon('left', 14) ?> ダッシュボードへ</a>
    <span style="font-size:13px;color:#cbd5e1"><?= h($user['name'] ?: $user['email']) ?></span>
    <a class="navlink" href="<?= h(app_url('logout')) ?>">ログアウト</a>
</header>

<div class="wrap">
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= h($flashMsg[1]) ?></div><?php endif; ?>

    <div style="display:grid;grid-template-columns:240px 1fr;gap:18px;align-items:start">
        <!-- 左: グループ一覧 + 作成 -->
        <div>
            <div class="card">
                <h2>あなたのグループ</h2>
                <div class="grouplist">
                    <?php foreach ($memberships as $m): ?>
                        <a href="groups.php?gid=<?= (int) $m['id'] ?>" class="<?= (int) $m['id'] === $viewGid ? 'active' : '' ?>">
                            <?= h($m['name']) ?>
                            <span class="pill <?= h($m['role']) ?>" style="float:right"><?= h(ROLES[$m['role']] ?? $m['role']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$memberships): ?><p class="muted">所属グループがありません。</p><?php endif; ?>
                </div>
            </div>
            <div class="card">
                <h2>グループを作成</h2>
                <form method="post" class="row">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="create_group">
                    <input name="name" placeholder="グループ名" required style="flex:1">
                    <button class="primary" type="submit">作成</button>
                </form>
                <p class="hint" style="margin-bottom:0">作成者はオーナーになります。</p>
            </div>
        </div>

        <!-- 右: 選択中グループの詳細 -->
        <div>
            <?php if (!$viewGroup): ?>
                <div class="card"><p class="muted">左からグループを選択するか、新規作成してください。</p></div>
            <?php else: ?>
                <div class="card">
                    <div class="row" style="justify-content:space-between">
                        <h2 style="margin:0"><?= h($viewGroup['name']) ?></h2>
                        <span class="pill <?= h($viewRole) ?>">あなた: <?= h(ROLES[$viewRole] ?? $viewRole) ?></span>
                    </div>
                </div>

                <!-- メンバー -->
                <div class="card">
                    <h2>メンバー（<?= count($members) ?>）</h2>
                    <table>
                        <thead><tr><th>ユーザー</th><th>メール</th><th>ロール</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($members as $mem): ?>
                            <tr>
                                <td><?= h($mem['name'] ?: '(名前未設定)') ?><?= (int) $mem['id'] === (int) $user['id'] ? ' <span class="muted">(あなた)</span>' : '' ?></td>
                                <td class="muted"><?= h($mem['email']) ?></td>
                                <td>
                                    <?php if ($canManage && !((int) $mem['id'] === (int) $user['id'] && $viewRole === 'owner')): ?>
                                        <form method="post" class="row" style="gap:4px">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="gid" value="<?= $viewGid ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $mem['id'] ?>">
                                            <select name="role" onchange="this.form.submit()">
                                                <?php foreach (ROLES as $rk => $rv): ?>
                                                    <?php if ($rk === 'owner' && !$isOwner) continue; ?>
                                                    <option value="<?= h($rk) ?>" <?= $rk === $mem['role'] ? 'selected' : '' ?>><?= h($rv) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    <?php else: ?>
                                        <span class="pill <?= h($mem['role']) ?>"><?= h(ROLES[$mem['role']] ?? $mem['role']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canManage): ?>
                                <td>
                                    <?php if (!is_last_owner($viewGid, (int) $mem['id'])): ?>
                                        <form method="post" onsubmit="return confirm('<?= h($mem['name'] ?: $mem['email']) ?> をグループから削除しますか？')">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="remove_member">
                                            <input type="hidden" name="gid" value="<?= $viewGid ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $mem['id'] ?>">
                                            <button class="danger" type="submit">削除</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 招待（admin 以上） -->
                <?php if ($canManage): ?>
                <div class="card">
                    <h2>メンバーを招待</h2>
                    <form method="post" class="row">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="invite">
                        <input type="hidden" name="gid" value="<?= $viewGid ?>">
                        <input name="email" type="email" placeholder="招待するメールアドレス" required style="flex:1;min-width:200px">
                        <select name="role">
                            <?php foreach (['member','admin','viewer'] as $rk): ?>
                                <option value="<?= $rk ?>" <?= $rk === 'member' ? 'selected' : '' ?>><?= h(ROLES[$rk]) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="primary" type="submit">招待</button>
                    </form>
                    <p class="hint">招待されたメールアドレスでGoogleログインすると、自動的にこのグループに参加します。</p>

                    <?php if ($invites): ?>
                        <table style="margin-top:12px">
                            <thead><tr><th>保留中の招待</th><th>ロール</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($invites as $inv): ?>
                                <tr>
                                    <td><?= h($inv['email']) ?></td>
                                    <td><span class="pill <?= h($inv['role']) ?>"><?= h(ROLES[$inv['role']] ?? $inv['role']) ?></span></td>
                                    <td>
                                        <form method="post">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="cancel_invite">
                                            <input type="hidden" name="gid" value="<?= $viewGid ?>">
                                            <input type="hidden" name="invite_id" value="<?= (int) $inv['id'] ?>">
                                            <button class="danger" type="submit">取消</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- 危険操作（owner のみ） -->
                <?php if ($isOwner): ?>
                <div class="card" style="border-color:#f3c4c0">
                    <h2 style="color:var(--err-ink)">グループの削除</h2>
                    <p class="hint">グループ・メンバーシップ・APIカタログがすべて削除されます。元に戻せません。</p>
                    <form method="post" onsubmit="return confirm('本当に「<?= h($viewGroup['name']) ?>」を削除しますか？元に戻せません。')">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete_group">
                        <input type="hidden" name="gid" value="<?= $viewGid ?>">
                        <button class="danger" type="submit">このグループを削除</button>
                    </form>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
