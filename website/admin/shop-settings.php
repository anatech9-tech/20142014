<?php
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/Settings.php';
require_once BASE_PATH . '/includes/shop/Rates.php';
require_once BASE_PATH . '/includes/shop/Price.php';
require_once BASE_PATH . '/includes/shop/Jalali.php';
// ------------------
Auth::requirePermission('shop.settings');
$pageTitle = 'تنظیمات فروشگاه';
$activeMenu = 'shop-settings';

$vals = [
    'shop_name'            => (string)Settings::get('shop_name', 'فروشگاه درخت سپید'),
    'shop_shipping_cost'   => (string)Settings::get('shop_shipping_cost', '500000'),
    'shop_free_shipping_min' => (string)Settings::get('shop_free_shipping_min', '30000000'),
    'shop_usd_manual'      => (string)Settings::get('shop_usd_manual', ''),
    'shop_usd_default'     => (string)Settings::get('shop_usd_default', '950000'),
    'shop_rate_ttl'        => (string)Settings::get('shop_rate_ttl', '1800'),
    'shop_per_page'        => (string)Settings::get('shop_per_page', '12'),
];
$liveRate = Shop\Rates::getUsd();
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">تنظیمات فروشگاه</h2></div>

    <div id="shopSettingsAlert"></div>

    <form id="shopSettingsForm" onsubmit="return saveShopSettings(event)">
        <h3 style="color:var(--text-strong);">🏪 عمومی</h3>
        <div class="form-field" style="margin-bottom:14px;">
            <label>نام فروشگاه (در هدر صفحات فروشگاهی)</label>
            <input type="text" name="shop_name" value="<?= htmlspecialchars($vals['shop_name']) ?>" maxlength="100">
        </div>
        <div class="form-field" style="margin-bottom:20px;">
            <label>تعداد محصولات در هر صفحه فروشگاه</label>
            <input type="number" name="shop_per_page" min="4" max="48" value="<?= htmlspecialchars($vals['shop_per_page']) ?>">
        </div>

        <h3 style="color:var(--text-strong);">🚚 ارسال</h3>
        <div class="form-field" style="margin-bottom:14px;">
            <label>هزینه ارسال (تومان)</label>
            <input type="number" name="shop_shipping_cost" min="0" step="1000" value="<?= htmlspecialchars($vals['shop_shipping_cost']) ?>">
        </div>
        <div class="form-field" style="margin-bottom:20px;">
            <label>حداقل مبلغ ارسال رایگان (تومان — 0 یعنی ارسال رایگان فعال نیست)</label>
            <input type="number" name="shop_free_shipping_min" min="0" step="1000000" value="<?= htmlspecialchars($vals['shop_free_shipping_min']) ?>">
        </div>

        <h3 style="color:var(--text-strong);">💵 نرخ دلار</h3>
        <div style="background:rgba(212,175,55,.08); border:1px solid rgba(212,175,55,.3); border-radius:10px; padding:14px 18px; margin-bottom:16px;">
            نرخ فعلی مورد استفاده: <strong style="color:#d4af37; font-size:1.1rem;"><?= Shop\Price::faNumber($liveRate) ?> تومان</strong>
            <span style="color:var(--text-2); font-size:.85rem;">(اگر خالی باشد، سایت به‌صورت خودکار از منابع آنلاین چک می‌کند و <?= Shop\Jalali::toPersianDigits((int)(3600 / max(1, (int)$vals['shop_rate_ttl']) * 60)) ?> دقیقه یک‌بار به‌روزرسانی می‌کند)</span>
        </div>
        <div class="form-field" style="margin-bottom:14px;">
            <label>نرخ دلار دستی (تومان) — خالی بگذارید برای حالت خودکار</label>
            <input type="number" name="shop_usd_manual" min="0" step="1000" value="<?= htmlspecialchars($vals['shop_usd_manual']) ?>" placeholder="مثلاً 950000">
            <small>اگر منبع آنلاین در دسترس نباشد، سایت از نرخ خودکار/کش‌شده استفاده می‌کند و در بدترین حالت از نرخ پیش‌فرض زیر.</small>
        </div>
        <div class="form-field" style="margin-bottom:14px;">
            <label>نرخ پیش‌فرض دلار (بازگشتی — تومان)</label>
            <input type="number" name="shop_usd_default" min="0" step="1000" value="<?= htmlspecialchars($vals['shop_usd_default']) ?>">
        </div>
        <div class="form-field" style="margin-bottom:20px;">
            <label>دوره به‌روزرسانی نرخ (ثانیه)</label>
            <input type="number" name="shop_rate_ttl" min="60" max="86400" value="<?= htmlspecialchars($vals['shop_rate_ttl']) ?>">
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:10px;">💾 ذخیره تنظیمات</button>
    </form>
</div>

<script>
async function saveShopSettings(e) {
    e.preventDefault();
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const data = Object.fromEntries(new FormData(document.getElementById('shopSettingsForm')).entries());
    const res = await fetch('api/shop-settings.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data).toString()
    });
    const r = await res.json();
    const box = document.getElementById('shopSettingsAlert');
    if (r.success) {
        box.innerHTML = '<div class="alert success">تنظیمات ذخیره شد.</div>';
        setTimeout(() => location.reload(), 1200);
    } else {
        box.innerHTML = '<div class="alert error">' + (r.error || 'خطا') + '</div>';
    }
    return false;
}
</script>
<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
