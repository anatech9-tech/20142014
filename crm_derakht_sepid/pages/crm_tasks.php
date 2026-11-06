<?php
/**
 * CRM — وظایف من (پیگیری‌ها / یادآوری‌ها)
 */
$pageTitle = 'وظایف من';

require_login();
$me     = (int)($_SESSION['user_id'] ?? 0);
$canAll = is_strict_admin() || is_finance_officer();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_task') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') { showToast('عنوان وظیفه الزامی است.', 'error'); redirect('index.php?page=crm_tasks'); }
        $dueDate = to_gregorian((string)($_POST['due_date'] ?? '')) ?: null;
        $priority = in_array($_POST['priority'] ?? 'normal', ['low', 'normal', 'high'], true) ? $_POST['priority'] : 'normal';
        $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
        $pdo->prepare("INSERT INTO crm_tasks (user_id, customer_id, title, due_date, priority, created_by) VALUES (?,?,?,?,?,?)")
            ->execute([$me, $customerId, $title, $dueDate, $priority, $me]);
        showToast('وظیفه ثبت شد.');
        redirect('index.php?page=crm_tasks');
    }

    if ($action === 'toggle_task') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM crm_tasks WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && ((int)$row['user_id'] === $me || $canAll)) {
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
        if ($row && ((int)$row['user_id'] === $me || $canAll)) {
            $pdo->prepare("DELETE FROM crm_tasks WHERE id = ?")->execute([$id]);
            showToast('وظیفه حذف شد.');
        }
        redirect('index.php?page=crm_tasks');
    }
}

[$scopeSql, $scopeParams] = crm_scope_where($pdo, 'c');

// وظایف: مدیر کل/مالی همه را می‌بینند؛ بقیه فقط مال خودشان
$scopeTasks = $canAll ? '' : ' WHERE user_id = ' . $me;
$tasks = $pdo->query("SELECT t.*, c.name AS customer_name FROM crm_tasks t LEFT JOIN crm_customers c ON c.id = t.customer_id" . $scopeTasks . " ORDER BY FIELD(t.status,'pending','done','cancelled'), (t.due_date IS NULL), t.due_date, t.id DESC LIMIT 300")->fetchAll();

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

<div class="stats-grid animate-in">
    <div class="stat-card glass-gradient-1"><div class="stat-icon primary"><i data-lucide="list-todo"></i></div><div class="stat-info"><h3><?= $pendingCount ?></h3><p>در انتظار انجام</p></div></div>
    <div class="stat-card glass-gradient-2"><div class="stat-icon" style="color:#dc2626"><i data-lucide="alarm-clock"></i></div><div class="stat-info"><h3><?= $overdueCount ?></h3><p>عقب‌افتاده از سررسید</p></div></div>
</div>

<div class="card animate-in"><div class="table-container"><table>
    <thead><tr><th>✓</th><th>وظیفه</th><th>مشتری</th><th>سررسید</th><th>اولویت</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($tasks as $t):
        $overdue = $t['status'] === 'pending' && $t['due_date'] && $t['due_date'] < date('Y-m-d'); ?>
        <tr style="<?= $t['status'] === 'done' ? 'opacity:.55' : '' ?><?= $overdue ? ';background:rgba(220,38,38,.06)' : '' ?>">
            <td><form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="toggle_task"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-ghost btn-sm" title="تغییر وضعیت"><?= $t['status'] === 'done' ? '☑️' : '⬜' ?></button></form></td>
            <td style="<?= $t['status'] === 'done' ? 'text-decoration:line-through' : '' ?>"><?= htmlspecialchars($t['title']) ?></td>
            <td><?= $t['customer_name'] ? htmlspecialchars($t['customer_name']) : '—' ?></td>
            <td><?= $t['due_date'] ? to_jalali($t['due_date']) : '—' ?><?= $overdue ? ' <span class="tag" style="background:rgba(220,38,38,.15);color:#dc2626">عقب‌افتاده</span>' : '' ?></td>
            <td><?= $t['priority'] === 'high' ? '🔴 زیاد' : ($t['priority'] === 'low' ? '⚪ کم' : '🟡 عادی') ?></td>
            <td><?= $t['status'] === 'done' ? 'انجام شد' : ($t['status'] === 'cancelled' ? 'لغو' : 'در انتظار') ?></td>
            <td><form method="post" style="display:inline" onsubmit="return confirm('وظیفه حذف شود؟');"><?= get_csrf_field() ?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-ghost btn-sm"><i data-lucide="trash-2"></i></button></form></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$tasks): ?><tr><td colspan="7" class="text-center text-muted" style="padding:26px">وظیفه‌ای ثبت نشده است. 🎉</td></tr><?php endif; ?>
    </tbody>
</table></div></div>

<!-- مودال وظیفه جدید -->
<div class="modal" id="taskModal"><div class="modal-content">
    <div class="modal-header"><h3>وظیفهٔ جدید</h3><button class="modal-close" onclick="closeModal('taskModal')">&times;</button></div>
    <form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="add_task">
        <div class="modal-body">
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
