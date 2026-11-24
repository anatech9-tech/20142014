<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('shipments.view');
$pageTitle = 'مدیریت محموله‌ها';
$activeMenu = 'shipments';

$status = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';
$where = []; $params = [];
if ($status) { $where[] = 'status = ?'; $params[] = $status; }
if ($search) {
    $where[] = '(tracking_code LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s]);
}
$sql = 'SELECT * FROM shipments' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC LIMIT 200';
$shipments = DB::fetchAll($sql, $params);
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">محموله‌ها</h2>
        <?php if (Auth::can('shipments.edit')): ?>
            <a href="shipment-edit.php" class="btn btn-primary">➕ محموله جدید</a>
        <?php endif; ?>
    </div>

    <form method="get" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="جستجو (کد رهگیری، نام، ایمیل)..." style="flex: 1; min-width: 200px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 9px 14px; color: var(--text-strong);">
        <select name="status" data-auto-submit style="background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 9px 14px; color: var(--text);">
            <option value="">همه</option>
            <option value="registered" <?= $status === 'registered' ? 'selected' : '' ?>>ثبت شده</option>
            <option value="picked_up" <?= $status === 'picked_up' ? 'selected' : '' ?>>جمع‌آوری شده</option>
            <option value="in_transit" <?= $status === 'in_transit' ? 'selected' : '' ?>>در حال حمل</option>
            <option value="customs_clearance" <?= $status === 'customs_clearance' ? 'selected' : '' ?>>ترخیص گمرک</option>
            <option value="out_for_delivery" <?= $status === 'out_for_delivery' ? 'selected' : '' ?>>آماده تحویل</option>
            <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>تحویل شده</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
            <option value="on_hold" <?= $status === 'on_hold' ? 'selected' : '' ?>>در انتظار</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">جستجو</button>
    </form>

    <?php if (empty($shipments)): ?>
        <div class="empty"><div class="empty-icon">📦</div>محموله‌ای یافت نشد</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>کد رهگیری</th><th>مشتری</th><th>مسیر</th><th>سرویس</th><th>وزن</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($shipments as $s): ?>
                    <tr>
                        <td>
                            <strong style="color: var(--secondary);"><?= htmlspecialchars($s['tracking_code']) ?></strong>
                            <?php if ($s['is_public']): ?>
                                <br><small><a href="/track/<?= urlencode($s['tracking_code']) ?>" target="_blank">👁️ عمومی</a></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($s['customer_name']) ?>
                            <?php if ($s['customer_email']): ?><br><small style="color:var(--text-2);"><?= htmlspecialchars($s['customer_email']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <small><?= htmlspecialchars($s['origin_country']) ?> → <?= htmlspecialchars($s['destination_country']) ?></small>
                        </td>
                        <td><span class="badge"><?= $s['service_type'] ?></span></td>
                        <td><small><?= $s['weight_kg'] ? $s['weight_kg'] . ' kg' : '-' ?></small></td>
                        <td><span class="badge"><?= $s['status'] ?></span></td>
                        <td><small><?= date('Y/m/d', strtotime($s['created_at'])) ?></small></td>
                        <td>
                            <a href="shipment-edit.php?id=<?= $s['id'] ?>" class="btn btn-sm">مدیریت</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
