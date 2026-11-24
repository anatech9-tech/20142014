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
// ------------------
Auth::requirePermission('shop.products');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$product = $id > 0 ? DB::fetch('SELECT * FROM products WHERE id = ?', [$id]) : null;
if ($id > 0 && !$product) {
    die('محصول یافت نشد');
}
$categories = DB::fetchAll('SELECT * FROM product_categories ORDER BY display_order, id');

$pageTitle = $product ? 'ویرایش محصول' : 'محصول جدید';
$activeMenu = 'shop-products';

// نمایش قیمت دلاری معادل برای راهنمایی
$rate = Shop\Rates::getUsd();
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= $product ? 'ویرایش: ' . htmlspecialchars($product['name_fa']) : 'افزودن محصول جدید' ?></h2>
        <a href="shop-products.php" class="btn btn-secondary">← بازگشت</a>
    </div>

    <div id="formAlerts"></div>

    <form id="productForm" onsubmit="return saveProduct(event)">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-field full" style="grid-column:1/-1;">
                <label>نام محصول (فارسی) *</label>
                <input type="text" name="name_fa" required maxlength="200" value="<?= htmlspecialchars($product['name_fa'] ?? '') ?>">
            </div>
            <div class="form-field">
                <label>نام انگلیسی</label>
                <input type="text" name="name_en" maxlength="200" value="<?= htmlspecialchars($product['name_en'] ?? '') ?>">
            </div>
            <div class="form-field">
                <label>برند</label>
                <input type="text" name="brand" maxlength="100" value="<?= htmlspecialchars($product['brand'] ?? '') ?>">
            </div>
            <div class="form-field">
                <label>دسته‌بندی</label>
                <select name="category_id">
                    <option value="0">بدون دسته</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($product['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= $c['icon'] ?> <?= htmlspecialchars($c['name_fa']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label>کد محصول (SKU)</label>
                <input type="text" name="sku" maxlength="60" value="<?= htmlspecialchars($product['sku'] ?? '') ?>" placeholder="مثلاً EM-EB-001">
            </div>
        </div>

        <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:16px;">
            <div class="form-field">
                <label>نوع قیمت *</label>
                <select name="currency" id="currencySel" onchange="toggleCurrency(this.value)">
                    <option value="toman" <?= ($product['currency'] ?? 'toman') === 'toman' ? 'selected' : '' ?>>تومان (ثابت)</option>
                    <option value="usd" <?= ($product['currency'] ?? '') === 'usd' ? 'selected' : '' ?>>دلار (محاسبه لحظه‌ای به تومان)</option>
                </select>
            </div>
            <div class="form-field" id="priceTomanField">
                <label>قیمت (تومان) *</label>
                <input type="number" name="price_toman" min="0" step="10" id="priceToman"
                       value="<?= $product['price_toman'] ?? '' ?>">
            </div>
            <div class="form-field" id="priceUsdField" <?= ($product['currency'] ?? 'toman') !== 'usd' ? 'style="display:none"' : '' ?>>
                <label>قیمت (دلار) *</label>
                <input type="number" name="price_usd" min="0" step="0.01" id="priceUsd"
                       value="<?= $product['price_usd'] ?? '' ?>">
                <small style="color:var(--text-2);">معادل امروز ≈ <span id="usdHint"><?= $product['currency'] === 'usd' ? Shop\Price::faNumber(Shop\Price::usdToToman((float)$product['price_usd'], $rate)) : '—' ?></span> تومان (نرخ: <?= Shop\Price::faNumber($rate) ?>)</small>
            </div>
            <div class="form-field" id="oldPriceField">
                <label>قیمت قبل از تخفیف (تومان، اختیاری — برای بج تخفیف)</label>
                <input type="number" name="old_price_toman" min="0" step="10" value="<?= $product['old_price_toman'] ?? '' ?>">
            </div>
        </div>

        <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:16px;">
            <div class="form-field">
                <label>موجودی (تعداد) *</label>
                <input type="number" name="stock" min="0" required value="<?= $product['stock'] ?? 0 ?>">
            </div>
            <div class="form-field">
                <label>وزن (کیلوگرم)</label>
                <input type="number" name="weight_kg" min="0" step="0.01" value="<?= $product['weight_kg'] ?? '' ?>">
            </div>
        </div>

        <div class="form-field" style="margin-top:16px;">
            <label>توضیح کوتاه (زیر عنوان در لیست)</label>
            <input type="text" name="short_desc_fa" maxlength="500" value="<?= htmlspecialchars($product['short_desc_fa'] ?? '') ?>">
        </div>

        <div class="form-field" style="margin-top:16px;">
            <label>توضیحات کامل (HTML مجاز است)</label>
            <textarea name="description_fa" style="min-height:220px; font-family:monospace;"><?= htmlspecialchars($product['description_fa'] ?? '') ?></textarea>
            <small style="color:var(--text-2);">می‌توانید از تگ‌های <code>&lt;p&gt;</code>، <code>&lt;ul&gt;&lt;li&gt;</code> و <code>&lt;strong&gt;</code> استفاده کنید.</small>
        </div>

        <!-- تصاویر -->
        <div class="form-field" style="margin-top:16px;">
            <label>تصاویر محصول (تصویر اول = تصویر اصلی)</label>
            <div id="imageList" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;"></div>
            <input type="file" id="imageInput" accept="image/*" multiple onchange="uploadImages(this)">
            <small style="color:var(--text-2);">JPG/PNG/WEBP — حداکثر ۵ مگابایت برای هر فایل</small>
        </div>

        <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:20px;">
            <div class="form-field">
                <label>وضعیت</label>
                <select name="is_active">
                    <option value="1" <?= !$product || $product['is_active'] ? 'selected' : '' ?>>فعال (در فروشگاه نمایش داده شود)</option>
                    <option value="0" <?= $product && !$product['is_active'] ? 'selected' : '' ?>>غیرفعال</option>
                </select>
            </div>
            <div class="form-field">
                <label>محصول ویژه (محبوب)</label>
                <select name="is_featured">
                    <option value="0" <?= !$product || !$product['is_featured'] ? 'selected' : '' ?>>خیر</option>
                    <option value="1" <?= $product && $product['is_featured'] ? 'selected' : '' ?>>بله — در ویجت پرفروش‌ها بالاتر</option>
                </select>
            </div>
        </div>

        <div style="margin-top:24px; display:flex; gap:10px;">
            <button type="submit" class="btn btn-primary">💾 ذخیره محصول</button>
            <a href="shop-products.php" class="btn btn-secondary">انصراف</a>
        </div>
    </form>
</div>

<script>
const RATE = <?= $rate ?>;
let images = <?= json_encode(array_map(fn($p) => ['url' => $p], json_decode($product['images'] ?: '[]', true) ?: []), JSON_UNESCAPED_UNICODE) ?>;

// رندر لیست تصاویر
function renderImages() {
    const box = document.getElementById('imageList');
    box.innerHTML = '';
    images.forEach((img, i) => {
        const d = document.createElement('div');
        d.style.cssText = 'position:relative; width:90px; height:90px;';
        d.innerHTML = `<img src="${img.url}" style="width:90px;height:90px;border-radius:10px;object-fit:cover;border:1px solid rgba(255,255,255,.15);" alt="">
            ${i === 0 ? '<span style="position:absolute;bottom:4px;right:4px;background:#d4af37;color:#111;font-size:.65rem;font-weight:800;padding:1px 8px;border-radius:6px;">اصلی</span>' : ''}
            <button type="button" onclick="removeImage(${i})" style="position:absolute;top:-6px;left:-6px;width:22px;height:22px;border-radius:50%;border:none;background:#ef4444;color:#fff;cursor:pointer;font-size:.7rem;">✕</button>`;
        box.appendChild(d);
    });
}
function removeImage(i) { images.splice(i, 1); renderImages(); }
renderImages();

// آپلود تصویر
async function uploadImages(input) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    for (const file of input.files) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('subdir', 'products');
        fd.append('csrf_token', csrf);
        try {
            const res = await fetch('api/upload.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd });
            const r = await res.json();
            if (r.success) { images.push({ url: r.url }); renderImages(); }
            else alert(r.error || 'خطا در آپلود');
        } catch (e) { alert('خطای ارتباطی'); }
    }
    input.value = '';
}

// جابجایی تومان/دلار
function toggleCurrency(cur) {
    document.getElementById('priceTomanField').style.display = cur === 'toman' ? '' : 'none';
    document.getElementById('priceUsdField').style.display = cur === 'usd' ? '' : 'none';
    document.getElementById('usdHint').textContent = cur === 'usd' ? '—' : '—';
}
document.getElementById('priceUsd').addEventListener('input', function () {
    const v = parseFloat(this.value || 0);
    document.getElementById('usdHint').textContent = v > 0 ?
        Math.round(v * RATE / 10) * 10.toLocaleString('fa-IR') + ' تومان' : '—';
});

// ذخیره
async function saveProduct(e) {
    e.preventDefault();
    const cur = document.getElementById('currencySel').value;
    if (cur === 'toman' && !(parseInt(document.getElementById('priceToman').value || 0) > 0)) {
        alert('قیمت تومان را وارد کنید.'); return false;
    }
    if (cur === 'usd' && !(parseFloat(document.getElementById('priceUsd').value || 0) > 0)) {
        alert('قیمت دلار را وارد کنید.'); return false;
    }

    const data = Object.fromEntries(new FormData(document.getElementById('productForm')).entries());
    data.images = JSON.stringify(images.map(x => x.url));

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
        const res = await fetch('api/shop-products.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        });
        const r = await res.json();
        if (r.success) location.href = 'shop-products.php';
        else alert(r.error || 'خطا در ذخیره');
    } catch (err) { alert('خطای ارتباطی'); }
    btn.disabled = false;
    return false;
}
</script>
<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
