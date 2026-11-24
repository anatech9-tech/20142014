<?php
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Price.php';
require_once BASE_PATH . '/includes/shop/Rates.php';
require_once BASE_PATH . '/includes/shop/Jalali.php';
// ------------------
Auth::requirePermission('shop.products');
$pageTitle = 'محصولات فروشگاه';
$activeMenu = 'shop-products';

$q     = (string)($_GET['q'] ?? '');
$catId = (int)($_GET['category'] ?? 0);
$status = (string)($_GET['status'] ?? '');

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(p.name_fa LIKE ? OR p.name_en LIKE ? OR p.sku LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($catId > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $catId;
}
if ($status === 'active')    $where[] = 'p.is_active = 1';
if ($status === 'inactive')  $where[] = 'p.is_active = 0';
if ($status === 'out')       $where[] = 'p.stock = 0';

$products = DB::fetchAll(
    "SELECT p.*, c.name_fa AS category_name
     FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY p.created_at DESC, p.id DESC LIMIT 300",
    $params
);
$categories = DB::fetchAll('SELECT * FROM product_categories ORDER BY display_order, id');
$rate = Shop\Rates::getUsd();
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">محصولات فروشگاه (<?= count($products) ?>)</h2>
        <?php if (Auth::can('shop.products')): ?>
            <a href="shop-product-edit.php" class="btn btn-primary">➕ محصول جدید</a>
        <?php endif; ?>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
        <form method="get" style="display:flex; gap:8px; flex:1; min-width:260px;">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="جستجو (نام / کد)..." style="flex:1;">
            <select name="category">
                <option value="0">همه دسته‌ها</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name_fa']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">همه وضعیت‌ها</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>غیرفعال</option>
                <option value="out" <?= $status === 'out' ? 'selected' : '' ?>>ناموجود</option>
            </select>
            <button type="submit" class="btn btn-primary">فیلتر</button>
        </form>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>محصول</th><th>دسته</th><th>قیمت</th><th>موجودی</th><th>فروش</th>
                    <th>وضعیت</th><th>تاریخ</th><th>عملیات</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="8" style="text-align:center; color:var(--text-2); padding:30px;">محصولی ثبت نشده است</td></tr>
            <?php endif; ?>
            <?php foreach ($products as $p):
                $price = $p['currency'] === 'usd'
                    ? Shop\Price::faNumber(Shop\Price::usdToToman((float)$p['price_usd'], $rate)) . ' تومان (دلاری $' . $p['price_usd'] . ')'
                    : Shop\Price::faNumber((int)$p['price_toman']) . ' تومان';
            ?>
                <tr>
                    <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <?php $imgs = json_decode($p['images'] ?: '[]', true); ?>
                            <img src="<?= $imgs ? htmlspecialchars($imgs[0]) : '' ?>" style="width:44px;height:44px;border-radius:8px;object-fit:cover;background:#f4f5f7;" alt="">
                            <div>
                                <strong><?= htmlspecialchars($p['name_fa']) ?></strong>
                                <?php if ($p['sku']): ?><br><small style="color:var(--text-2);"><?= htmlspecialchars($p['sku']) ?></small><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><?= $p['category_name'] ? htmlspecialchars($p['category_name']) : '—' ?></td>
                    <td style="white-space:nowrap;"><?= $price ?></td>
                    <td>
                        <span class="badge <?= (int)$p['stock'] > 0 ? 'success' : 'error' ?>">
                            <?= Shop\Jalali::toPersianDigits((int)$p['stock']) ?>
                        </span>
                    </td>
                    <td><?= Shop\Jalali::toPersianDigits((int)$p['sold_count']) ?></td>
                    <td>
                        <span class="badge <?= $p['is_active'] ? 'success' : 'muted' ?>"><?= $p['is_active'] ? 'فعال' : 'غیرفعال' ?></span>
                        <?php if ($p['is_featured']): ?><span class="badge warning" style="margin-inline-start:4px;">ویژه</span><?php endif; ?>
                    </td>
                    <td><small><?= Shop\Jalali::date($p['created_at']) ?></small></td>
                    <td style="white-space:nowrap;">
                        <a href="shop-product-edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-secondary">ویرایش</a>
                        <button class="btn btn-sm btn-danger" data-confirm="حذف این محصول؟" onclick="delProduct(<?= $p['id'] ?>)">🗑</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function delProduct(id) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('api/shop-products.php', {
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
