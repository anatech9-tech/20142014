<?php
/**
 * CRM — مدیریت تیم‌های فروش (فقط مدیر کل)
 * §v20 (۲۰۲۶-۱۱-۱۰) بازطراحی: مدیرها با اسم، اعضای زیرمجموعهٔ هر مدیر در همان کارت تیم،
 * همه‌چیز قابل ویرایش، فاصلهٔ کافی بین کارت‌ها و ردیف‌ها.
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
        $code    = trim((string)($_POST['code'] ?? ''));
        if ($id > 0 && $name !== '') {
            $pdo->prepare("UPDATE teams SET name = ?, manager_user_id = ?, status = ? WHERE id = ?")->execute([$name, $mgr, $status, $id]);
            operations_audit($pdo, $me, 'crm_team_update', 'team', $id, $name);
            showToast('تیم به‌روزرسانی شد.');
        } elseif ($id === 0 && $name !== '') {
            // افزودن تیم جدید (مثلاً برای اجرای بذر سند نهایی روی دیتابیس‌های قدیمی)
            if ($code === '' || !preg_match('/^[a-z0-9_\-]+$/i', $code)) { $code = 'team_' . time(); }
            $dup = $pdo->prepare("SELECT id FROM teams WHERE code = ?");
            $dup->execute([$code]);
            if ($dup->fetch()) { $code = 'team_' . time(); }
            $pdo->prepare("INSERT INTO teams (code, name, manager_user_id, status) VALUES (?,?,?,'active')")->execute([$code, $name, $mgr]);
            $newId = (int)$pdo->lastInsertId();
            operations_audit($pdo, $me, 'crm_team_create', 'team', $newId, $name);
            showToast('تیم ساخته شد: ' . $name);
        }
        redirect('index.php?page=crm_teams');
    }

    if ($action === 'assign_user') {
        // اطمینان از وجود شمای §org پیش از به‌روزرسانی (self-heal؛ بدون حذف)
        ensure_org_roles_schema($pdo);
        $userId    = (int)($_POST['user_id'] ?? 0);
        $teamId    = (int)($_POST['team_id'] ?? 0) ?: null;
        $orgRoleId = (int)($_POST['org_role_id'] ?? 0) ?: null;
        if ($userId > 0) {
            if (db_has_column($pdo, 'users', 'org_role_id') && array_key_exists('org_role_id', $_POST)) {
                $pdo->prepare("UPDATE users SET team_id = ?, org_role_id = ? WHERE id = ?")->execute([$teamId, $orgRoleId, $userId]);
            } else {
                $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")->execute([$teamId, $userId]);
            }
            $nmSt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $nmSt->execute([$userId]);
            $nmVal = (string)($nmSt->fetchColumn() ?: '');
            operations_audit($pdo, $me, 'crm_org_assign', 'user', $userId, trim($nmVal . ' | سمت سازمانی: ' . ($orgRoleId ?? '∅') . ' | تیم: ' . ($teamId ?? '∅')));
            showToast('تخصیص کاربر ذخیره شد.');
        }
        redirect('index.php?page=crm_teams');
    }
}

$teams         = all_teams($pdo);
$orgRoles      = all_org_roles($pdo); // §org — self-heal شمای سمت‌های سازمانی
$hasOrgCol     = db_has_column($pdo, 'users', 'org_role_id');
$userCols      = $hasOrgCol ? 'id, full_name, role, team_id, org_role_id' : 'id, full_name, role, team_id';
$users         = $pdo->query("SELECT $userCols FROM users ORDER BY (role='admin'), full_name")->fetchAll();
$managerOptions = $users;

// ایندکس کاربران بر اساس تیم (و بدون‌تیم‌ها)
$usersByTeam = [];
$noTeam      = [];
$teamIds     = array_map(function($t) { return (int)$t['id']; }, $teams);
$teamNameById = [];
foreach ($teams as $t) { $teamNameById[(int)$t['id']] = (string)$t['name']; }
$userNameById = [];
foreach ($users as $u) { $userNameById[(int)$u['id']] = (string)$u['full_name']; }
foreach ($users as $u) {
    $tid = (int)($u['team_id'] ?? 0);
    if ($tid > 0 && in_array($tid, $teamIds, true)) { $usersByTeam[$tid][] = $u; } else { $noTeam[] = $u; }
}

$gridEdit    = 'display:grid;grid-template-columns:70px 1.2fr 1fr 110px 44px;gap:10px;align-items:center;padding:10px 12px';
$gridMember  = 'display:grid;grid-template-columns:1.15fr 84px 1.2fr 1.4fr 44px;gap:10px;align-items:center;padding:10px 12px;border-top:1px solid var(--border)';
$memberHead  = 'display:grid;grid-template-columns:1.15fr 84px 1.2fr 1.4fr 44px;gap:10px;align-items:center;padding:8px 12px;font-size:11px;color:var(--text-secondary);background:var(--bg)';

// رندر یک ردیف عضو قابل‌ویرایش (تیم + سمت سازمانی)
$renderMember = function (array $u) use ($teams, $orgRoles, $hasOrgCol, $gridMember) {
    ob_start(); ?>
    <form method="post" style="<?= $gridMember ?>;margin:0">
        <?= get_csrf_field() ?><input type="hidden" name="action" value="assign_user"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
        <div style="font-weight:600"><?= htmlspecialchars($u['full_name']) ?></div>
        <div><code style="font-size:11px"><?= htmlspecialchars($u['role']) ?></code></div>
        <div><select name="team_id" class="form-control"><option value="0">— بدون تیم —</option>
            <?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($u['team_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div><select name="org_role_id" class="form-control" <?= $hasOrgCol ? '' : 'disabled' ?>><option value="0">— بدون سمت —</option>
            <optgroup label="مرحلهٔ استقرار (۶ ماههٔ اول)">
                <?php foreach ($orgRoles as $or): if ($or['stage'] !== 'stage1') continue; ?><option value="<?= (int)$or['id'] ?>" <?= (int)($u['org_role_id'] ?? 0) === (int)$or['id'] ? 'selected' : '' ?>><?= htmlspecialchars($or['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <optgroup label="چارت توسعه‌یافته (پس از رشد)">
                <?php foreach ($orgRoles as $or): if ($or['stage'] !== 'growth') continue; ?><option value="<?= (int)$or['id'] ?>" <?= (int)($u['org_role_id'] ?? 0) === (int)$or['id'] ? 'selected' : '' ?>><?= htmlspecialchars($or['name']) ?></option><?php endforeach; ?>
            </optgroup>
        </select></div>
        <div><button type="submit" class="btn btn-ghost btn-sm" title="ذخیره"><i data-lucide="check"></i></button></div>
    </form>
    <?php
    return ob_get_clean();
};

include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2>تیم‌های فروش و اعضا</h2>
    <a href="index.php?page=crm_customers" class="btn btn-ghost"><i data-lucide="arrow-right"></i> CRM</a>
</div>

<div style="display:flex;flex-direction:column;gap:16px">

    <!-- افزودن تیم جدید -->
    <div class="card animate-in" style="margin:0">
        <div class="card-header"><h3 class="card-title">➕ افزودن تیم</h3></div>
        <form method="post" class="d-flex gap-2 align-center" style="padding:12px;flex-wrap:wrap">
            <?= get_csrf_field() ?><input type="hidden" name="action" value="save_team"><input type="hidden" name="id" value="0">
            <input name="name" class="form-control" style="max-width:260px" placeholder="نام تیم (مثلاً تیم توسعه بازار)" required>
            <input name="code" class="form-control" style="max-width:180px" placeholder="کد لاتین (اختیاری)" pattern="[A-Za-z0-9_\-]+" dir="ltr">
            <select name="manager_user_id" class="form-control" style="max-width:220px"><option value="0">— بدون مدیر —</option>
                <?php foreach ($managerOptions as $u): ?><option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i data-lucide="plus"></i> ساخت تیم</button>
        </form>
    </div>

    <!-- کارتِ هر تیم: مدیر تیم با اسمش + اعضای زیرمجموعه‌اش (همه قابل ویرایش) -->
    <?php foreach ($teams as $tm): $tid = (int)$tm['id']; $members = $usersByTeam[$tid] ?? []; $mgrName = $tid && (int)($tm['manager_user_id'] ?? 0) ? ($userNameById[(int)$tm['manager_user_id']] ?? '—') : '—'; ?>
    <div class="card animate-in" style="margin:0">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
            <h3 class="card-title">👥 <?= htmlspecialchars($tm['name']) ?>
                <span class="text-muted" style="font-size:12px;font-weight:400">— مدیر: <b><?= htmlspecialchars($mgrName) ?></b></span>
            </h3>
            <span class="tag <?= $tm['status'] === 'active' ? 'tag-active' : 'tag-holiday' ?>"><?= $tm['status'] === 'active' ? 'فعال' : 'غیرفعال' ?></span>
        </div>

        <!-- ویرایش مشخصات تیم -->
        <form method="post" style="<?= $gridEdit ?>;margin:0">
            <?= get_csrf_field() ?><input type="hidden" name="action" value="save_team"><input type="hidden" name="id" value="<?= $tid ?>">
            <div dir="ltr"><code><?= htmlspecialchars($tm['code']) ?></code></div>
            <div><input name="name" class="form-control" value="<?= htmlspecialchars($tm['name']) ?>" required placeholder="نام تیم"></div>
            <div><select name="manager_user_id" class="form-control" title="مدیر تیم"><option value="0">— بدون مدیر —</option>
                <?php foreach ($managerOptions as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)($tm['manager_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div><select name="status" class="form-control"><option value="active" <?= $tm['status'] === 'active' ? 'selected' : '' ?>>فعال</option><option value="inactive" <?= $tm['status'] !== 'active' ? 'selected' : '' ?>>غیرفعال</option></select></div>
            <div><button type="submit" class="btn btn-primary btn-sm" title="ذخیره تغییرات تیم"><i data-lucide="save"></i></button></div>
        </form>

        <!-- اعضای زیرمجموعهٔ این مدیر -->
        <div style="padding:10px 12px 6px;font-size:12px;color:var(--text-secondary);font-weight:600">
            اعضای زیرمجموعه <?= $mgrName !== '—' ? 'مدیر «' . htmlspecialchars($mgrName) . '»' : 'این تیم' ?> (<?= count($members) ?>)
        </div>
        <?php if ($members): ?>
        <div style="<?= $memberHead ?>"><div>نام</div><div>نقش</div><div>تیم</div><div>سمت سازمانی</div><div></div></div>
        <?php foreach ($members as $u) echo $renderMember($u); ?>
        <?php else: ?>
        <div class="text-muted" style="padding:10px 12px 14px;font-size:12px">هنوز عضوی در این تیم نیست — از بخش «افزودن عضو» یک کاربر انتخاب کنید.</div>
        <?php endif; ?>

        <!-- افزودن عضو جدید به این تیم -->
        <?php $candidates = array_values(array_filter($users, function($u) use ($tid) { return (int)($u['team_id'] ?? 0) !== $tid; })); ?>
        <?php if ($candidates): ?>
        <form method="post" class="d-flex gap-2 align-center" style="padding:12px;border-top:1px solid var(--border);flex-wrap:wrap">
            <?= get_csrf_field() ?><input type="hidden" name="action" value="assign_user"><input type="hidden" name="team_id" value="<?= $tid ?>">
            <select name="user_id" class="form-control" style="max-width:260px" required>
                <option value="0">انتخاب عضو جدید…</option>
                <?php foreach ($candidates as $u): $curTid = (int)($u['team_id'] ?? 0); ?><option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?><?= $curTid ? ' (تیم فعلی: ' . htmlspecialchars($teamNameById[$curTid] ?? '?') . ')' : '' ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="user-plus"></i> افزودن به تیم</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- کاربران بدون تیم -->
    <div class="card animate-in" style="margin:0">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
            <h3 class="card-title">🧑‍💼 بدون تیم <span class="text-muted" style="font-size:12px;font-weight:400">(<?= count($noTeam) ?>)</span></h3>
        </div>
        <?php if ($noTeam): ?>
        <div style="<?= $memberHead ?>"><div>نام</div><div>نقش</div><div>تیم</div><div>سمت سازمانی</div><div></div></div>
        <?php foreach ($noTeam as $u) echo $renderMember($u); ?>
        <?php else: ?>
        <div class="text-muted" style="padding:12px;font-size:12px">همهٔ کاربران عضو یک تیم هستند.</div>
        <?php endif; ?>
    </div>

</div>
<?php include 'includes/footer.php'; ?>
