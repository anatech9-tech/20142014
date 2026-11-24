<?php
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Jalali.php';
require_once BASE_PATH . '/includes/shop/Price.php';
// ------------------
Auth::requirePermission('shop.coupons');
$pageTitle = 'کدهای تخفیف';
$activeMenu = 'shop-coupons';

$coupons = DB::fetchAll('SELECT * FROM discount_codes ORDER BY created_at DESC LIMIT 200');
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">کدهای تخفیف (<?= count($coupons) ?>)</h2>
        <button class="btn btn-primary" onclick="openCouponForm()">➕ کد جدید</button>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead><tr><th>کد</th><th>نوع</th><th>مبلغ/درصد</th><th>حداقل سفارش</th><th>استفاده</th><th>انقضا</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php if (empty($coupons)): ?>
                <tr><td colspan="8" style="text-align:center; color:var(--text-2); padding:30px;">کدی ثبت نشده است</td></tr>
            <?php endif; ?>
            <?php foreach ($coupons as $cp): ?>
                <tr>
                    <td dir="ltr" style="text-align:right; font-weight:800; color:#d4af37;"><?= htmlspecialchars($cp['code']) ?></td>
                    <td><?= $cp['type'] === 'percent' ? 'درصدی' : 'مبلغ ثابت' ?></td>
                    <td><?= $cp['type'] === 'percent' ? Shop\Jalali::toPersianDigits((float)$cp['value']) . '٪' : Shop\Price::faNumber((int)$cp['value']) . ' تومان' ?></td>
                    <td><?= (int)$cp['min_amount'] ? Shop\Price::faNumber((int)$cp['min_amount']) . ' تومان' : '—' ?></td>
                    <td><?= Shop\Jalali::toPersianDigits((int)$cp['used_count']) ?><?= $cp['max_uses'] ? ' / ' . Shop\Jalali::toPersianDigits((int)$cp['max_uses']) : '' ?></td>
                    <td><small><?= $cp['expires_at'] ? Shop\Jalali::date($cp['expires_at']) : 'بدون انقضا' ?></small></td>
                    <td><span class="badge <?= $cp['is_active'] ? 'success' : 'muted' ?>"><?= $cp['is_active'] ? 'فعال' : 'غیرفعال' ?></span></td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm btn-secondary" onclick='openCouponForm(<?= json_encode($cp, JSON_UNESCAPED_UNICODE) ?>)'>ویرایش</button>
                        <button class="btn btn-sm btn-danger" data-confirm="حذف این کد؟" onclick="delCoupon(<?= $cp['id'] ?>)">🗑</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-backdrop" id="cpModal">
    <div class="modal" style="max-width:480px;">
        <h3 id="cpModalTitle">کد تخفیف جدید</h3>
        <form id="cpForm" onsubmit="return saveCoupon(event)">
            <input type="hidden" name="id" id="cpId" value="0">
            <div class="form-field" style="margin-bottom:14px;">
                <label>کد تخفیف * (حروف بزرگ لاتین/اعداد)</label>
                <input type="text" name="code" id="cpCode" required pattern="[A-Za-z0-9_\-]+" maxlength="40" style="text-transform:uppercase;">
            </div>
            <div class="form-grid">
                <div class="form-field">
                    <label>نوع تخفیف</label>
                    <select name="type" id="cpType" onchange="document.getElementById('cpValueType').style.display = this.value === 'fixed' ? '' : 'none'">
                        <option value="percent">درصد</option>
                        <option value="fixed">مبلغ ثابت (تومان)</option>
                    </select>
                </div>
                <div class="form-field" id="cpValueType">
                    <label>مقدار</label>
                    <input type="number" name="value" id="cpValue" min="1" step="1" value="10">
                </div>
            </div>
            <div class="form-grid" style="margin-top:14px;">
                <div class="form-field">
                    <label>حداقل مبلغ سفارش (تومان، 0 = بدون محدودیت)</label>
                    <input type="number" name="min_amount" id="cpMin" min="0" value="0">
                </div>
                <div class="form-field">
                    <label>حداکثر استفاده (0 = نامحدود)</label>
                    <input type="number" name="max_uses" id="cpMax" min="0" value="0">
                </div>
            </div>
            <div class="form-field" style="margin-top:14px;">
                <label>تاریخ انقضا (اختیاری)</label>
                <input type="datetime-local" name="expires_at" id="cpExpires">
            </div>
            <div class="form-field" style="margin-top:14px;">
                <label>وضعیت</label>
                <select name="is_active" id="cpActive"><option value="1">فعال</option><option value="0">غیرفعال</option></select>
            </div>
            <div style="margin-top:20px; display:flex; gap:10px;">
                <button type="submit" class="btn btn-primary">ذخیره</button>
                <button type="button" class="btn btn-secondary" data-close-modal="cpModal">بستن</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCouponForm(cp) {
    document.getElementById('cpModalTitle').textContent = cp === undefined ? 'کد تخفیف جدید' : 'ویرایش کد';
    document.getElementById('cpId').value = cp ? cp.id : 0;
    document.getElementById('cpCode').value = cp ? cp.code : '';
    document.getElementById('cpType').value = cp ? cp.type : 'percent';
    document.getElementById('cpValueType').style.display = cp && cp.type === 'fixed' ? '' : 'none';
    document.getElementById('cpValue').value = cp ? cp.value : 10;
    document.getElementById('cpMin').value = cp ? cp.min_amount : 0;
    document.getElementById('cpMax').value = cp ? cp.max_uses : 0;
    document.getElementById('cpExpires').value = cp && cp.expires_at ? cp.expires_at.slice(0, 16) : '';
    document.getElementById('cpActive').value = cp ? cp.is_active : 1;
    openModal('cpModal');
}
async function saveCoupon(e) {
    e.preventDefault();
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const data = Object.fromEntries(new FormData(document.getElementById('cpForm')).entries());
    data.expires_at = data.expires_at ? data.expires_at.replace('T', ' ') + ':00' : '';
    const res = await fetch('api/shop-coupons.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data).toString()
    });
    const r = await res.json();
    if (r.success) location.reload();
    else alert(r.error || 'خطا');
    return false;
}
async function delCoupon(id) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('api/shop-coupons.php', {
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
