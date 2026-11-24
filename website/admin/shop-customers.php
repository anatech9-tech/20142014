<?php
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Jalali.php';
// ------------------
Auth::requirePermission('shop.customers');
$pageTitle = 'مشتریان فروشگاه';
$activeMenu = 'shop-customers';

$q = trim((string)($_GET['q'] ?? ''));
$where = '1=1';
$params = [];
if ($q !== '') {
    $where = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR username LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$customers = DB::fetchAll(
    "SELECT cu.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = cu.id) AS orders_count,
            (SELECT COALESCE(SUM(total_toman),0) FROM orders o WHERE o.customer_id = cu.id AND o.order_status != 'cancelled') AS total_spent
     FROM customer_users cu WHERE $where
     ORDER BY cu.created_at DESC LIMIT 300",
    $params
);
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">مشتریان (<?= count($customers) ?>)</h2>
        <form method="get" style="display:flex; gap:8px;">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="جستجو (نام/ایمیل/موبایل)...">
            <button type="submit" class="btn btn-primary">جستجو</button>
        </form>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead><tr><th>مشتری</th><th>موبایل</th><th>شهر</th><th>تعداد سفارش</th><th>مجموع خرید</th><th>عضویت</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php if (empty($customers)): ?>
                <tr><td colspan="8" style="text-align:center; color:var(--text-2); padding:30px;">مشتری ثبت‌شده‌ای نیست</td></tr>
            <?php endif; ?>
            <?php foreach ($customers as $cu): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($cu['full_name']) ?></strong><br>
                        <small style="color:var(--text-2);" dir="ltr"><?= htmlspecialchars($cu['email']) ?></small>
                    </td>
                    <td dir="ltr" style="text-align:right;"><?= htmlspecialchars($cu['phone']) ?: '—' ?></td>
                    <td><?= htmlspecialchars($cu['city'] ?: '—') ?></td>
                    <td><?= Shop\Jalali::toPersianDigits((int)$cu['orders_count']) ?></td>
                    <td><?= Shop\Jalali::toPersianDigits((int)$cu['total_spent']) ?></td>
                    <td><small><?= Shop\Jalali::date($cu['created_at']) ?></small></td>
                    <td><span class="badge <?= $cu['is_active'] ? 'success' : 'error' ?>"><?= $cu['is_active'] ? 'فعال' : 'مسدود' ?></span></td>
                    <td>
                        <button class="btn btn-sm <?= $cu['is_active'] ? 'btn-danger' : 'btn-success' ?>" onclick="toggleCust(<?= $cu['id'] ?>, <?= $cu['is_active'] ? 0 : 1 ?>)">
                            <?= $cu['is_active'] ? 'مسدودسازی' : 'فعال‌سازی' ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function toggleCust(id, active) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('api/shop-customers.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle&id=' + id + '&active=' + active
    });
    const r = await res.json();
    if (r.success) location.reload();
    else alert(r.error || 'خطا');
}
</script>
<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
