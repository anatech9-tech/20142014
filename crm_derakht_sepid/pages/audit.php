<?php
$pageTitle = t('nav_audit');

$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 50;
$offset = ($page_num - 1) * $per_page;

$filter_user = $_GET['user'] ?? '';
$filter_action = $_GET['action_filter'] ?? '';
// §v12: فیلتر تاریخ (شمسی) + فیلتر نام کاربری/نام
$filter_date_raw = trim($_GET['date'] ?? '');
$filter_uname = trim($_GET['uname'] ?? '');
$filter_date_g = '';
if ($filter_date_raw !== '') {
    $filter_date_g = (strpos($filter_date_raw, '/') !== false) ? to_gregorian($filter_date_raw) : $filter_date_raw;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_g)) $filter_date_g = '';
}

$where = "1=1";
$params = [];

if ($filter_user) {
    $where .= " AND al.user_id = ?";
    $params[] = $filter_user;
}
if ($filter_uname !== '') {
    $where .= " AND (u.username LIKE ? OR u.full_name LIKE ?)";
    $like = '%' . $filter_uname . '%';
    $params[] = $like; $params[] = $like;
}
if ($filter_date_g !== '') {
    $where .= " AND DATE(al.created_at) = ?";
    $params[] = $filter_date_g;
}
if ($filter_action) {
    $where .= " AND al.action = ?";
    $params[] = $filter_action;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON al.user_id = u.id WHERE {$where}");
$count_stmt->execute($params);
$total = $count_stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT al.*, u.full_name FROM audit_log al LEFT JOIN users u ON al.user_id = u.id WHERE {$where} ORDER BY al.created_at DESC LIMIT {$per_page} OFFSET {$offset}");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Users for filter
$stmt = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name");
$users = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_audit') ?></h2>
    <span class="text-muted"><?= number_format($total) ?> <?= t('رکورد') ?></span>
</div>

<div class="card mb-4 animate-in">
    <form method="GET" class="d-flex gap-2">
        <input type="hidden" name="page" value="audit">
        <select name="user" class="form-control" style="width:150px">
            <option value=""><?= t('همه') ?> <?= t('کاربران') ?></option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>><?= $u['full_name'] ?></option>
            <?php endforeach; ?>
        </select>
        <select name="action_filter" class="form-control" style="width:140px">
            <option value=""><?= t('همه') ?> <?= t('wh_actions') ?></option>
            <option value="login" <?= $filter_action == 'login' ? 'selected' : '' ?>><?= t('btn_checkin') ?></option>
            <option value="create" <?= $filter_action == 'create' ? 'selected' : '' ?>><?= t('ایجاد') ?></option>
            <option value="update" <?= $filter_action == 'update' ? 'selected' : '' ?>><?= t('btn_edit') ?></option>
            <option value="delete" <?= $filter_action == 'delete' ? 'selected' : '' ?>><?= t('btn_delete') ?></option>
            <option value="transfer" <?= $filter_action == 'transfer' ? 'selected' : '' ?>><?= t('btn_transfer') ?></option>
        </select>
        <input type="text" name="uname" class="form-control" style="width:140px" placeholder="<?= t('per_username') ?> <?= t('یا') ?> <?= t('wh_name') ?>..." value="<?= htmlspecialchars($filter_uname) ?>">
        <input type="text" name="date" class="form-control jalali-date" style="width:125px" placeholder="<?= t('wh_date') ?> (<?= t('wh_day') ?>)" value="<?= $filter_date_g ? to_jalali($filter_date_g) : htmlspecialchars($filter_date_raw) ?>">
        <button type="submit" class="btn btn-ghost"><?= t('btn_filter') ?></button>
        <?php if ($filter_uname !== '' || $filter_date_g !== '' || $filter_user !== '' || $filter_action !== ''): ?>
        <a href="index.php?page=audit" class="btn btn-ghost" style="color:var(--danger)">✕ <?= t('btn_delete') ?> <?= t('فیلترها') ?></a>
        <?php endif; ?>
    </form>
</div>

<div class="card animate-in">
    <div class="table-container"><table>
        <thead><tr><th><?= t('زمان') ?></th><th><?= t('کاربر') ?></th><th><?= t('wh_actions') ?></th><th><?= t('sal_type') ?></th><th><?= t('شناسه') ?></th><th><?= t('مقدار قبلی') ?></th><th><?= t('مقدار') ?> <?= t('جدید') ?> / <?= t('adv_reason') ?></th></tr></thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td style="font-size:12px"><?= to_jalali($l['created_at']) ?> <?= substr($l['created_at'], 11, 5) ?></td>
                <td><?= $l['full_name'] ?: strtr(t('کاربر حذف‌شده #{id}'), ['{id}' => (string)$l['user_id']]) ?></td>
                <td><span class="tag tag-<?= $l['action'] == 'create' ? 'active' : ($l['action'] == 'delete' ? 'rejected' : 'pending') ?>"><?= $l['action'] ?></span></td>
                <td><?= $l['entity_type'] ?></td>
                <td><?= $l['entity_id'] ?: '-' ?></td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($l['old_value'] ?: '-') ?>"><?= $l['old_value'] ?: '-' ?></td><td style="max-width:260px;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($l['new_value'] ?: '-') ?>"><?= $l['new_value'] ?: '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php if ($total > $per_page): ?>
<div class="pagination mt-4">
    <?php for ($i = 1; $i <= ceil($total/$per_page); $i++): ?>
    <a href="index.php?page=audit&p=<?= $i ?>&user=<?= $filter_user ?>&action_filter=<?= $filter_action ?>&uname=<?= urlencode($filter_uname) ?>&date=<?= urlencode($filter_date_raw) ?>" class="<?= $i == $page_num ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
