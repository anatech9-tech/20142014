<?php
/**
 * CRM — وظایف من (پیگیری‌ها / یادآوری‌ها)
 * §v20 (۲۰۲۶-۱۱-۱۰): مدیر هر تیم می‌تواند وظیفه را به اعضای زیرمجموعهٔ خودش
 * (هر نفر یا کل اعضای تیم) واگذار کند؛ وظیفه در «وظایف من» همان فرد نمایش داده می‌شود.
 */
$pageTitle = 'وظایف من';

require_login();
$me     = (int)($_SESSION['user_id'] ?? 0);
$canAll = is_strict_admin() || is_finance_officer();

// ── تیم‌هایی که من مدیرسش هستم + اعضای آن‌ها ─────────────────────
$myManagedTeams = [];
try {
    $teamSql = $canAll ? "SELECT id, name FROM teams ORDER BY name" : "SELECT id, name FROM teams WHERE manager_user_id = ? ORDER BY name";
    $teamSt  = $pdo->prepare($teamSql);
    if ($canAll) { $teamSt->execute([]); } else { $teamSt->execute([$me]); }
    $myManagedTeams = $teamSt->fetchAll();
} catch (Throwable $e) { $myManagedTeams = []; }

$managedTeamIds = array_map(function($t) { return (int)$t['id']; }, $myManagedTeams);
$teamMembers    = []; // team_id => list of users
$assignable     = []; // user_id => full_name (یکتا برای اعتبارسنجی روی سرور)
$assignable[$me] = (string)($_SESSION['full_name'] ?? 'من');
if ($managedTeamIds) {
    $in = implode(',', array_map('intval', $managedTeamIds));
    try {
        $rows = $pdo->query("SELECT id, full_name, team_id FROM users WHERE status='active' AND team_id IN ($in) ORDER BY full_name")->fetchAll();
        foreach ($rows as $r) {
            $teamMembers[(int)$r['team_id']][] = $r;
            $assignable[(int)$r['id']] = (string)$r['full_name'];
        }
    } catch (Throwable $e) { $teamMembers = []; }
}
$isTeamManager = !$canAll && !empty($managedTeamIds);
$canAssign     = $canAll || $isTeamManager;

// همهٔ کاربران فعال برای مدیر کل/مالی
$allActiveUsers = [];
if ($canAll) {
    try { $allActiveUsers = $pdo->query("SELECT id, full_name FROM users WHERE status='active' ORDER BY full_name")->fetchAll(); }
    catch (Throwable $e) { $allActiveUsers = []; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_task') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') { showToast('عنوان وظیفه الزامی است.', 'error'); redirect('index.php?page=crm_tasks'); }
        $dueDate = to_gregorian((string)($_POST['due_date'] ?? '')) ?: null;
        $priority = in_array($_POST['priority'] ?? 'normal', ['low', 'normal', 'high'], true) ? $_POST['priority'] : 'normal';
        $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;

        // ── تعیین گیرنده(گان) وظیفه ──
        $target   = (string)($_POST['assignee'] ?? 'self');
        $targetIds = [];
        if ($target === 'self' || $target === '') {
            $targetIds = [$me];
        } elseif ($canAssign && preg_match('/^user:(\d+)$/', $target, $tm)) {
            $uid = (int)$tm[1];
            if ($canAll) {
                $targetIds = [$uid]; // مدیر کل/مالی: هر کاربر فعال
            } elseif (isset($assignable[$uid])) {
                $targetIds = [$uid]; // مدیر تیم: فقط اعضای زیرمجموعهٔ خودش + خودش
            }
        } elseif ($canAssign && preg_match('/^team:(\d+)$/', $target, $tm)) {
            $tid = (int)$tm[1];
            if (in_array($tid, $managedTeamIds, true) && !empty($teamMembers[$tid])) {
                foreach ($teamMembers[$tid] as $m) $targetIds[] = (int)$m['id'];
            } elseif ($canAll) {
                try {
                    $st = $pdo->prepare("SELECT id FROM users WHERE status='active' AND team_id = ?");
                    $st->execute([$tid]);
                    $targetIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                } catch (Throwable $e) { $targetIds = []; }
            }
        }
        if (!$targetIds) {
            showToast('گیرندهٔ انتخاب‌شده معتبر نیست یا زیرمجموعهٔ شما نیست.', 'error');
            redirect('index.php?page=crm_tasks');
        }
        $ins = $pdo->prepare("INSERT INTO crm_tasks (user_id, customer_id, title, due_date, priority, created_by) VALUES (?,?,?,?,?,?)");
        foreach ($targetIds as $uid) {
            $ins->execute([$uid, $customerId, $title, $dueDate, $priority, $me]);
        }
        operations_audit($pdo, $me, 'crm_task_assign', 'crm_tasks', 0, $title . ' → ' . count($targetIds) . ' نفر');
        showToast(count($targetIds) > 1 ? ('وظیفه برای ' . count($targetIds) . ' نفر ثبت شد.') : 'وظیفه ثبت شد.');
        redirect('index.php?page=crm_tasks');
    }

    if ($action === 'toggle_task') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM crm_tasks WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && ((int)$row['user_id'] === $me || (int)$row['created_by'] === $me || $canAll)) {
            $new = $row['status'] === 'done' ? 'pending' : 'done';
            $pdo->prepare("UPDATE crm_tasks SET status = ? WHERE id = ?")->execute([$new, $id]);
            showToast($new === 'done' ? '✅ انجام شد.' : 'به حالت انجام‌نشده برگشت.');
        }
        redirect('index.php?page=crm_tasks');
    }

    if ($action === 'delete_task') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM crm_tasks WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && ((int)$row['user_id'] === $me || (int)$row['created_by'] === $me || $canAll)) {
            $pdo->prepare("DELETE FROM crm_tasks WHERE id = ?")->execute([$id]);
            showToast('وظیفه حذف شد.');
        }
        redirect('index.php?page=crm_tasks');
    }
}

[$scopeSql, $scopeParams] = crm_scope_where($pdo, 'c');

// وظایف: مدیر کل/مالی همه را می‌بینند؛ مدیر تیم وظایف خودش + اعضای تیم‌های زیرمجموعه‌اش + آنچه واگذار کرده؛ بقیه فقط مال خودشان
if ($canAll) {
    $scopeTasks = '';
    $scopeParamsT = [];
} else {
    $inIds = $managedTeamIds ? implode(',', array_map('intval', $managedTeamIds)) : '0';
    $scopeTasks = " WHERE (user_id = ? OR created_by = ? OR user_id IN (SELECT id FROM users WHERE team_id IN ($inIds)))";
    $scopeParamsT = [$me, $me];
}
$tasksSt = $pdo->prepare("SELECT t.*, c.name AS customer_name, u.full_name AS assignee_name, cb.full_name AS assigner_name
    FROM crm_tasks t
    LEFT JOIN crm_customers c ON c.id = t.customer_id
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN users cb ON cb.id = t.created_by
    $scopeTasks
    ORDER BY FIELD(t.status,'pending','done','cancelled'), (t.due_date IS NULL), t.due_date, t.id DESC LIMIT 300");
$tasksSt->execute($scopeParamsT);
$tasks = $tasksSt->fetchAll();

$stCust = $pdo->prepare("SELECT c.id, c.name FROM crm_customers c WHERE c.deleted_at IS NULL AND ($scopeSql) ORDER BY c.name LIMIT 500");
$stCust->execute($scopeParams);
$customerOptions = $stCust->fetchAll();

$pendingCount = 0; $overdueCount = 0;
foreach ($tasks as $t) {
    if ($t['status'] === 'pending') {
        $pendingCount++;
        if ($t['due_date'] && $t['due_date'] < date('Y-m-d')) $overdueCount++;
    }
}

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2>وظایف من</h2>
    <button class="btn btn-primary" onclick="openModal('taskModal')"><i data-lucide="plus"></i> وظیفهٔ جدید</button>
</div>

<?php if ($canAssign): ?>
<div class="card animate-in" style="padding:10px 14px;margin-bottom:16px;font-size:12px;color:var(--text-secondary)">
    👤 شما مدیر <?= count($myManagedTeams) ?> تیم هستید — می‌توانید وظیفه را به اعضای زیرمجموعهٔ خودتان یا کل اعضای یک تیم واگذار کنید؛ هر فرد وظیفه‌اش را در «وظایف من» خودش می‌بیند.
</div>
<?php endif; ?>

<div class="stats-grid animate-in">
    <div class="stat-card glass-gradient-1"><div class="stat-icon primary"><i data-lucide="list-todo"></i></div><div class="stat-info"><h3><?= $pendingCount ?></h3><p>در انتظار انجام</p></div></div>
    <div class="stat-card glass-gradient-2"><div class="stat-icon" style="color:#dc2626"><i data-lucide="alarm-clock"></i></div><div class="stat-info"><h3><?= $overdueCount ?></h3><p>عقب‌افتاده از سررسید</p></div></div>
</div>

<div class="card animate-in"><div class="table-container"><table>
    <thead><tr><th>✓</th><th>وظیفه</th><th>برای</th><th>مشتری</th><th>سررسید</th><th>اولویت</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($tasks as $t):
        $overdue = $t['status'] === 'pending' && $t['due_date'] && $t['due_date'] < date('Y-m-d');
        $forMe = (int)$t['user_id'] === $me;
        $assignedByMe = (int)$t['created_by'] === $me && !$forMe; ?>
        <tr style="<?= $t['status'] === 'done' ? 'opacity:.55' : '' ?><?= $overdue ? ';background:rgba(220,38,38,.06)' : '' ?>">
            <td><form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="toggle_task"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-ghost btn-sm" title="تغییر وضعیت"><?= $t['status'] === 'done' ? '☑️' : '⬜' ?></button></form></td>
            <td style="<?= $t['status'] === 'done' ? 'text-decoration:line-through' : '' ?>"><?= htmlspecialchars($t['title']) ?><?php if ($assignedByMe): ?> <span class="tag" style="background:rgba(37,99,235,.12);color:#2563eb;font-size:10px">واگذارشده توسط من</span><?php endif; ?></td>
            <td><?= htmlspecialchars($t['assignee_name'] ?: '—') ?></td>
            <td><?= $t['customer_name'] ? htmlspecialchars($t['customer_name']) : '—' ?></td>
            <td><?= $t['due_date'] ? to_jalali($t['due_date']) : '—' ?><?= $overdue ? ' <span class="tag" style="background:rgba(220,38,38,.15);color:#dc2626">عقب‌افتاده</span>' : '' ?></td>
            <td><?= $t['priority'] === 'high' ? '🔴 زیاد' : ($t['priority'] === 'low' ? '⚪ کم' : '🟡 عادی') ?></td>
            <td><?= $t['status'] === 'done' ? 'انجام شد' : ($t['status'] === 'cancelled' ? 'لغو' : 'در انتظار') ?></td>
            <td><form method="post" style="display:inline" onsubmit="return confirm('وظیفه حذف شود؟');"><?= get_csrf_field() ?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-ghost btn-sm"><i data-lucide="trash-2"></i></button></form></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$tasks): ?><tr><td colspan="8" class="text-center text-muted" style="padding:26px">وظیفه‌ای ثبت نشده است. 🎉</td></tr><?php endif; ?>
    </tbody>
</table></div></div>

<!-- مودال وظیفه جدید -->
<div class="modal" id="taskModal"><div class="modal-content">
    <div class="modal-header"><h3>وظیفهٔ جدید</h3><button class="modal-close" onclick="closeModal('taskModal')">&times;</button></div>
    <form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="add_task">
        <div class="modal-body">
            <?php if ($canAssign): ?>
            <div class="form-group"><label class="form-label">واگذاری به</label>
                <select name="assignee" class="form-control">
                    <option value="self">خودم</option>
                    <?php if ($canAll && $allActiveUsers): ?>
                    <optgroup label="همهٔ کاربران">
                        <?php foreach ($allActiveUsers as $u): if ((int)$u['id'] === $me) continue; ?><option value="user:<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php foreach ($myManagedTeams as $t): $tid = (int)$t['id']; $members = $teamMembers[$tid] ?? []; ?>
                    <optgroup label="تیم <?= htmlspecialchars($t['name']) ?> (<?= count($members) ?> نفر)">
                        <?php foreach ($members as $m): ?><option value="user:<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?></option><?php endforeach; ?>
                        <?php if ($members): ?><option value="team:<?= $tid ?>">— کل اعضای تیم <?= htmlspecialchars($t['name']) ?> —</option><?php endif; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="assignee" value="self">
            <?php endif; ?>
            <div class="form-group"><label class="form-label">عنوان *</label><input name="title" class="form-control" required placeholder="مثلاً تماس با شرکت X برای پیگیری پروفرما"></div>
            <div class="form-group"><label class="form-label">مشتری (اختیاری)</label><select name="customer_id" class="form-control"><option value="0">—</option>
                <?php foreach ($customerOptions as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group"><label class="form-label">سررسید</label><input name="due_date" class="form-control jalali-date" placeholder="۱۴۰۵/۰۸/۳۰"></div>
                <div class="form-group"><label class="form-label">اولویت</label><select name="priority" class="form-control"><option value="normal">🟡 عادی</option><option value="high">🔴 زیاد</option><option value="low">⚪ کم</option></select></div>
            </div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">ثبت وظیفه</button></div>
    </form>
</div></div>

<?php include 'includes/footer.php'; ?>
