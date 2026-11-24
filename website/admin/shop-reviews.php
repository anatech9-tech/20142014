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
Auth::requirePermission('shop.reviews');
$pageTitle = 'دیدگاه‌های محصولات';
$activeMenu = 'shop-reviews';

$reviews = DB::fetchAll(
    'SELECT r.*, p.name_fa AS product_name, p.slug AS product_slug
     FROM product_reviews r
     JOIN products p ON p.id = r.product_id
     ORDER BY r.created_at DESC LIMIT 300'
);
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">دیدگاه‌ها (<?= count($reviews) ?>)</h2>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead><tr><th>مشتری</th><th>محصول</th><th>امتیاز</th><th>متن</th><th>تاریخ</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php if (empty($reviews)): ?>
                <tr><td colspan="7" style="text-align:center; color:var(--text-2); padding:30px;">دیدگاهی ثبت نشده است</td></tr>
            <?php endif; ?>
            <?php foreach ($reviews as $rv): ?>
                <tr>
                    <td><?= htmlspecialchars($rv['customer_name']) ?></td>
                    <td>
                        <a href="/shop/product.php?slug=<?= $rv['product_slug'] ?>" target="_blank">
                            <?= htmlspecialchars($rv['product_name']) ?>
                        </a>
                    </td>
                    <td style="color:#d4af37; letter-spacing:2px;"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></td>
                    <td style="max-width:340px;"><span style="display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;"><?= htmlspecialchars($rv['body']) ?></span></td>
                    <td><small><?= Shop\Jalali::dateTime($rv['created_at']) ?></small></td>
                    <td><span class="badge <?= $rv['is_active'] ? 'success' : 'muted' ?>"><?= $rv['is_active'] ? 'نمایش داده می‌شود' : 'مخفی' ?></span></td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm btn-secondary" onclick="toggleReview(<?= $rv['id'] ?>, <?= $rv['is_active'] ? 0 : 1 ?>)">
                            <?= $rv['is_active'] ? 'مخفی کردن' : 'نمایش' ?>
                        </button>
                        <button class="btn btn-sm btn-danger" data-confirm="حذف این دیدگاه؟" onclick="delReview(<?= $rv['id'] ?>)">🗑</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function toggleReview(id, active) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('api/shop-reviews.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle&id=' + id + '&active=' + active
    });
    const r = await res.json();
    if (r.success) location.reload();
    else alert(r.error || 'خطا');
}
async function delReview(id) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('api/shop-reviews.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&id=' + id
    });
    const r = await res.json();
    if (r.success) location.reload();
    else alert(r.error || 'خطا');
}
</script>
<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
