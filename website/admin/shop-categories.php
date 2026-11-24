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
Auth::requirePermission('shop.products');
$pageTitle = 'دسته‌بندی‌های فروشگاه';
$activeMenu = 'shop-categories';

$categories = DB::fetchAll(
    'SELECT cat.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = cat.id) AS total,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = cat.id AND p.is_active = 1) AS active_count
     FROM product_categories cat ORDER BY cat.display_order, cat.id'
);
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">دسته‌بندی‌ها (<?= count($categories) ?>)</h2>
        <button class="btn btn-primary" onclick="openCatForm(0)">➕ دسته جدید</button>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead><tr><th>آیکون</th><th>نام</th><th>اسلاگ</th><th>تعداد محصول</th><th>ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php if (empty($categories)): ?>
                <tr><td colspan="7" style="text-align:center; color:var(--text-2); padding:30px;">دسته‌ای ثبت نشده است</td></tr>
            <?php endif; ?>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td style="font-size:1.4rem;"><?= $c['icon'] ?></td>
                    <td><strong><?= htmlspecialchars($c['name_fa']) ?></strong><br><small style="color:var(--text-2);"><?= htmlspecialchars($c['name_en']) ?></small></td>
                    <td dir="ltr" style="text-align:right;"><?= htmlspecialchars($c['slug']) ?></td>
                    <td><?= Shop\Jalali::toPersianDigits((int)$c['active_count']) ?> / <?= Shop\Jalali::toPersianDigits((int)$c['total']) ?></td>
                    <td><?= (int)$c['display_order'] ?></td>
                    <td><span class="badge <?= $c['is_active'] ? 'success' : 'muted' ?>"><?= $c['is_active'] ? 'فعال' : 'غیرفعال' ?></span></td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm btn-secondary" onclick='openCatForm(<?= json_encode($c, JSON_UNESCAPED_UNICODE) ?>)'>ویرایش</button>
                        <button class="btn btn-sm btn-danger" data-confirm="حذف این دسته؟ (محصولات حذف نمی‌شوند)" onclick="delCat(<?= $c['id'] ?>)">🗑</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال -->
<div class="modal-backdrop" id="catModal">
    <div class="modal" style="max-width:460px;">
        <h3 id="catModalTitle">دسته جدید</h3>
        <form id="catForm" onsubmit="return saveCat(event)">
            <input type="hidden" name="id" id="catId" value="0">
            <div class="form-field" style="margin-bottom:14px;">
                <label>نام فارسی *</label>
                <input type="text" name="name_fa" id="catNameFa" required maxlength="100">
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label>نام انگلیسی</label>
                <input type="text" name="name_en" id="catNameEn" maxlength="100">
            </div>
            <div class="form-grid">
                <div class="form-field">
                    <label>آیکون (ایموجی)</label>
                    <input type="text" name="icon" id="catIcon" maxlength="16" value="📦">
                </div>
                <div class="form-field">
                    <label>ترتیب نمایش</label>
                    <input type="number" name="display_order" id="catOrder" value="0">
                </div>
            </div>
            <div class="form-field" style="margin-top:14px;">
                <label>وضعیت</label>
                <select name="is_active" id="catActive">
                    <option value="1">فعال</option>
                    <option value="0">غیرفعال</option>
                </select>
            </div>
            <div style="margin-top:20px; display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary">ذخیره</button>
                <button type="button" class="btn btn-secondary" data-close-modal="catModal">بستن</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCatForm(cat) {
    document.getElementById('catModalTitle').textContent = cat === 0 ? 'دسته جدید' : 'ویرایش دسته';
    document.getElementById('catId').value = cat === 0 ? 0 : cat.id;
    document.getElementById('catNameFa').value = cat === 0 ? '' : cat.name_fa;
    document.getElementById('catNameEn').value = cat === 0 ? '' : (cat.name_en || '');
    document.getElementById('catIcon').value = cat === 0 ? '📦' : (cat.icon || '📦');
    document.getElementById('catOrder').value = cat === 0 ? 0 : cat.display_order;
    document.getElementById('catActive').value = cat === 0 ? '1' : cat.is_active;
    openModal('catModal');
}
async function saveCat(e) {
    e.preventDefault();
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const data = Object.fromEntries(new FormData(document.getElementById('catForm')).entries());
    const res = await fetch('api/shop-categories.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data).toString()
    });
    const r = await res.json();
    if (r.success) location.reload();
    else alert(r.error || 'خطا');
    return false;
}
async function delCat(id) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('api/shop-categories.php', {
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
