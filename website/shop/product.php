<?php
/**
 * فروشگاه — صفحه محصول
 * URL: /shop/{slug} یا /shop/product.php?slug={slug}
 */
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
// ------------------

use Shop\Jalali, Shop\Price, Shop\Rates, Shop\Products, Shop\Customers;

$slug = (string)($_GET['slug'] ?? '');
$product = $slug !== '' ? Products::bySlug($slug) : null;

if (!$product || !(int)$product['is_active']) {
    http_response_code(404);
    $shopTitle = 'محصول یافت نشد';
    require __DIR__ . '/_header.php';
    echo '<div class="empty-state"><div class="ico">😕</div><h1>محصول یافت نشد</h1><p>محصولی با این آدرس وجود ندارد یا غیرفعال شده است.</p><a href="/shop/" class="btn btn-gold">بازگشت به فروشگاه</a></div>';
    require __DIR__ . '/_footer.php';
    exit;
}

Products::incViews((int)$product['id']);

$price      = Price::productToman($product);
$rate       = Rates::getUsd();
$usdProduct = ($product['currency'] ?? 'toman') === 'usd';
$oldPrice   = $product['old_price_toman'] ? (int)$product['old_price_toman'] : null;
$out        = (int)$product['stock'] < 1;
$shopUser   = Customers::user();

$shopTitle = $product['name_fa'];
require __DIR__ . '/_header.php';

$related  = Products::related($product, 4);
$reviews  = \DB::fetchAll(
    'SELECT r.*, (SELECT COUNT(*) FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id
       WHERE oi.product_id = r.product_id AND oi.id IS NOT NULL AND o.customer_id = r.customer_id) AS purchased
     FROM product_reviews r
     WHERE r.product_id = ? AND r.is_active = 1
     ORDER BY r.created_at DESC LIMIT 50',
    [(int)$product['id']]
);
$avgRating = count($reviews) ? round(array_sum(array_map(fn($r) => (int)$r['rating'], $reviews)) / count($reviews), 1) : 0;
?>

<div class="page-head">
    <div class="breadcrumb">
        <a href="/">خانه</a> / <a href="/shop/">فروشگاه</a> /
        <?php if ($product['category_slug']): ?><a href="/shop/index.php?category=<?= $product['category_slug'] ?>"><?= htmlspecialchars($product['category_name']) ?></a> / <?php endif; ?>
        <?= htmlspecialchars($product['name_fa']) ?>
    </div>
</div>

<div class="product-page">
    <div class="gallery">
        <div class="main-img">
            <img id="galleryMain" src="<?= htmlspecialchars($product['images'][0] ?? '/assets/placeholder.webp') ?>" alt="<?= htmlspecialchars($product['name_fa']) ?>">
        </div>
        <?php if (count($product['images']) > 1): ?>
            <div class="thumbs">
                <?php foreach ($product['images'] as $i => $img): ?>
                    <button type="button" data-thumb="<?= htmlspecialchars($img) ?>" class="<?= $i === 0 ? 'active' : '' ?>">
                        <img src="<?= htmlspecialchars($img) ?>" alt="تصویر <?= $i + 1 ?>" loading="lazy">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="p-info">
        <div class="p-meta">
            <?php if ($product['brand']): ?><span>برند: <?= htmlspecialchars($product['brand']) ?></span><?php endif; ?>
            <?php if ($product['sku']): ?><span>کد: <?= htmlspecialchars($product['sku']) ?></span><?php endif; ?>
            <?php if ($avgRating > 0): ?><span>⭐ <?= Jalali::toPersianDigits($avgRating) ?> از ۵</span><?php endif; ?>
        </div>
        <h1><?= htmlspecialchars($product['name_fa']) ?></h1>
        <?php if ($product['short_desc_fa']): ?><p style="color:var(--shop-muted); line-height:1.9; margin-bottom:16px;"><?= htmlspecialchars($product['short_desc_fa']) ?></p><?php endif; ?>

        <div class="price-block">
            <?php if ($oldPrice && $oldPrice > $price): ?>
                <div class="old">قبل: <?= Price::tomanLabel($oldPrice) ?> <span class="sale-tag"><?= Price::faNumber((int)(100 - $price * 100 / $oldPrice)) ?>٪ تخفیف</span></div>
            <?php endif; ?>
            <div class="now"><?= Price::faNumber($price) ?> <small>تومان</small></div>
            <?php if ($usdProduct): ?>
                <div class="rate-note">💵 قیمت بر اساس نرخ لحظه‌ای دلار (<?= Price::faNumber($rate) ?> تومان) — مبلغ نهایی در زمان ثبت سفارش محاسبه می‌شود</div>
            <?php endif; ?>
        </div>

        <div style="font-size:.92rem; margin-bottom:4px;">
            <?php if ($out): ?>
                <span style="color:var(--shop-red); font-weight:700;">❌ ناموجود — به‌زودی شارژ می‌شود</span>
            <?php elseif ((int)$product['stock'] <= 10): ?>
                <span style="color:var(--shop-red); font-weight:700;">⏳ فقط <?= Price::faNumber((int)$product['stock']) ?> عدد باقی مانده</span>
            <?php else: ?>
                <span style="color:var(--shop-green); font-weight:700;">✓ موجود در انبار</span>
            <?php endif; ?>
        </div>

        <div class="buy-row">
            <?php if (!$out): ?>
                <div class="qty-box">
                    <button type="button" data-qty-plus aria-label="افزایش">+</button>
                    <input type="number" id="qtyInput" value="1" min="1" max="<?= (int)$product['stock'] ?>">
                    <button type="button" data-qty-minus aria-label="کاهش">−</button>
                </div>
                <button class="btn btn-gold" data-add-cart="<?= $product['id'] ?>">افزودن به سبد خرید 🛒</button>
                <a href="/cart/" class="btn btn-outline" id="buyNow" onclick="setTimeout(()=>{location.href='/checkout/'},600)">خرید فوری ⚡</a>
            <?php else: ?>
                <a href="/contact.php" class="btn btn-gold btn-block">موجودی را از ما بپرسید ✉️</a>
            <?php endif; ?>
        </div>

        <ul class="p-features">
            <li>سورس‌شده مستقیم از کارخانه در چین</li>
            <li>ضمانت تعویض در صورت مشکل کارخانه‌ای</li>
            <li>امکان ارسال به سراسر کشور</li>
            <li><?= $usdProduct ? 'قیمت دلاری — محاسبه لحظه‌ای به تومان' : 'قیمت به تومان — بدون تورم نرخ ارز' ?></li>
        </ul>
    </div>
</div>

<div class="p-tabs">
    <div class="tab-btns">
        <button type="button" class="active" data-tab="tabDesc">توضیحات</button>
        <button type="button" data-tab="tabReviews">نظرات و امتیازات (<?= Price::faNumber(count($reviews)) ?>)</button>
    </div>

    <div id="tabDesc" class="tab-panel active">
        <?php if ($product['description_fa']): ?>
            <?= $product['description_fa'] /* تمیزشده هنگام ذخیره در ادمین */ ?>
        <?php else: ?>
            <p style="color:var(--shop-muted);">توضیحاتی برای این محصول ثبت نشده است.</p>
        <?php endif; ?>
    </div>

    <div id="tabReviews" class="tab-panel">
        <?php if ($shopUser): ?>
            <form id="reviewForm" class="review-form">
                <h3>ثبت نظر</h3>
                <div class="stars-input" id="starsInput">
                    <input type="hidden" name="rating" id="reviewRating" value="5">
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                        <button type="button" class="<?= $s <= 5 ? 'on' : '' ?>" data-star="<?= $s ?>">★</button>
                    <?php endfor; ?>
                </div>
                <div class="field" style="margin-bottom:14px;">
                    <textarea name="body" required minlength="10" maxlength="2000" placeholder="تجربه خودتان را درباره این محصول بنویسید..."></textarea>
                </div>
                <button type="submit" class="btn btn-gold btn-sm">ثبت نظر</button>
            </form>
        <?php else: ?>
            <div class="alert info">برای ثبت نظر، ابتدا <a href="/account/login">وارد حساب کاربری</a> شوید.</div>
        <?php endif; ?>

        <?php if ($reviews): ?>
            <?php foreach ($reviews as $rv): ?>
                <div class="review-item">
                    <div class="review-head">
                        <strong><?= htmlspecialchars($rv['customer_name']) ?></strong>
                        <span class="review-stars"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></span>
                        <span class="review-date"><?= Jalali::dateTime($rv['created_at']) ?></span>
                        <?php if (!empty($rv['purchased'])): ?><span class="review-verified">خریدار تأیید شده ✓</span><?php endif; ?>
                    </div>
                    <div class="review-body"><?= nl2br(htmlspecialchars($rv['body'])) ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color:var(--shop-muted);">هنوز نظری ثبت نشده — اولین نفر باشید!</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($related): ?>
    <div style="padding: 30px 0 40px;">
        <h2 style="color:#fff; margin-bottom:20px;">محصولات مرتبط</h2>
        <div class="products-grid">
            <?php foreach ($related as $rp): $rpPrice = Price::productToman($rp); ?>
                <div class="p-card">
                    <a href="product.php?slug=<?= $rp['slug'] ?>">
                        <div class="img-wrap"><img src="<?= htmlspecialchars($rp['first_image']) ?>" alt="<?= htmlspecialchars($rp['name_fa']) ?>" loading="lazy"></div>
                    </a>
                    <div class="p-body">
                        <div class="p-name"><a href="product.php?slug=<?= $rp['slug'] ?>"><?= htmlspecialchars($rp['name_fa']) ?></a></div>
                        <div class="p-price-row"><span class="p-price"><?= Price::faNumber($rpPrice) ?> <small>تومان</small></span></div>
                        <div class="p-actions">
                            <?php if ((int)$rp['stock'] > 0): ?>
                                <button class="btn btn-gold btn-sm" data-add-cart="<?= $rp['id'] ?>">سبد خرید 🛒</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
