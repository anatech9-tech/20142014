<?php
/**
 * CRM — مدیریت تیم‌های فروش (فقط مدیر کل): نام/مدیر/وضعیت تیم + تخصیص اعضا
 */
$pageTitle = 'تیم‌های فروش';
$me = (int)($_SESSION['user_id'] ?? 0);
if (!is_strict_admin()) { showToast(t('error_access_denied'), 'error'); redirect('index.php?page=dashboard'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_team') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim((string)($_POST['name'] ?? ''));
        $mgr     = (int)($_POST['manager_user_id'] ?? 0) ?: null;
        $status  = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        if ($id > 0 && $name !== '') {
            $pdo->prepare("UPDATE teams SET name = ?, manager_user_id = ?, status = ? WHERE id = ?")->execute([$name, $mgr, $status, $id]);
            operations_audit($pdo, $me, 'crm_team_update', 'team', $id, $name);
            showToast('تیم به‌روزرسانی شد.');
        }
        redirect('index.php?page=crm_teams');
    }

    if ($action === 'assign_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $teamId = (int)($_POST['team_id'] ?? 0) ?: null;
        if ($userId > 0) {
            $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")->execute([$teamId, $userId]);
            showToast('تخصیص تیم ذخیره شد.');
        }
        redirect('index.php?page=crm_teams');
    }
}

$teams         = all_teams($pdo);
$users         = $pdo->query("SELECT id, full_name, role, team_id FROM users ORDER BY (role='admin'), full_name")->fetchAll();
$managerOptions = $users;

$rowStyle = 'display:grid;grid-template-columns:70px 1.1fr 1fr 110px 44px;gap:10px;align-items:center;padding:8px 12px;border-bottom:1px solid var(--border)';

include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2>تیم‌های فروش و اعضا</h2>
    <a href="index.php?page=crm_customers" class="btn btn-ghost"><i data-lucide="arrow-right"></i> CRM</a>
</div>

<div class="card animate-in">
    <div class="card-header"><h3 class="card-title">👥 تیم‌ها <span class="text-muted" style="font-size:11px;font-weight:400">(مدیر تیم = دیدن همهٔ مشتریان/سرنخ‌ها/معاملات تیم)</span></h3></div>
    <div style="display:flex;flex-direction:column">
        <div style="<?= $rowStyle ?>;background:var(--bg);font-size:11px;color:var(--text-secondary);border-bottom:2px solid var(--border)"><div>کد</div><div>نام تیم</div><div>مدیر تیم</div><div>وضعیت</div><div></div></div>
        <?php foreach ($teams as $tm): ?>
        <form method="post" style="<?= $rowStyle ?>;margin:0">
            <?= get_csrf_field() ?><input type="hidden" name="action" value="save_team"><input type="hidden" name="id" value="<?= (int)$tm['id'] ?>">
            <div dir="ltr"><code><?= htmlspecialchars($tm['code']) ?></code></div>
            <div><input name="name" class="form-control" value="<?= htmlspecialchars($tm['name']) ?>" required></div>
            <div><select name="manager_user_id" class="form-control"><option value="0">— بدون مدیر —</option>
                <?php foreach ($managerOptions as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)($tm['manager_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div><select name="status" class="form-control"><option value="active" <?= $tm['status'] === 'active' ? 'selected' : '' ?>>فعال</option><option value="inactive" <?= $tm['status'] !== 'active' ? 'selected' : '' ?>>غیرفعال</option></select></div>
            <div><button type="submit" class="btn btn-primary btn-sm"><i data-lucide="save"></i></button></div>
        </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="card animate-in">
    <div class="card-header"><h3 class="card-title">🧑‍💼 تخصیص اعضا به تیم</h3></div>
    <div style="display:flex;flex-direction:column">
        <div style="<?= $rowStyle ?>;grid-template-columns:50px 1fr 110px 1fr 44px;background:var(--bg);font-size:11px;color:var(--text-secondary);border-bottom:2px solid var(--border)"><div>#</div><div>نام</div><div>نقش</div><div>تیم</div><div></div></div>
        <?php foreach ($users as $u): ?>
        <form method="post" style="<?= $rowStyle ?>;grid-template-columns:50px 1fr 110px 1fr 44px;margin:0">
            <?= get_csrf_field() ?><input type="hidden" name="action" value="assign_user"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <div><?= (int)$u['id'] ?></div>
            <div><?= htmlspecialchars($u['full_name']) ?></div>
            <div><code style="font-size:11px"><?= htmlspecialchars($u['role']) ?></code></div>
            <div><select name="team_id" class="form-control"><option value="0">— بدون تیم —</option>
                <?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($u['team_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div><button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="check"></i></button></div>
        </form>
        <?php endforeach; ?>
        <?php if (!$users): ?><div class="text-muted text-center" style="padding:20px">هنوز کاربری وجود ندارد.</div><?php endif; ?>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
