<?php
/**
 * فروشگاه — لیست محصولات (فیلتر، جستجو، مرتب‌سازی، صفحه‌بندی)
 */
declare(strict_types=1);

use Shop\Jalali, Shop\Price, Shop\Products;

$shopTitle  = 'فروشگاه';
$shopActive = 'shop';
require __DIR__ . '/_header.php';

$perPage = max(1, min(48, (int)Settings::get('shop_per_page', 12)));

$category = (string)($_GET['category'] ?? '');
$q        = trim((string)($_GET['q'] ?? ''));
$sort     = (string)($_GET['sort'] ?? 'new');
$pMin     = (int)($_GET['price_min'] ?? 0);
$pMax     = (int)($_GET['price_max'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));

$result   = Products::list([
    'category'  => $category,
    'q'         => $q,
    'sort'      => $sort,
    'price_min' => $pMin,
    'price_max' => $pMax,
    'stock'     => empty($_GET['instock']) ? false : true,
], $page, $perPage);

$categories = Products::categories();
$activeCat  = null;
foreach ($categories as $c) {
    if ($c['slug'] === $category) $activeCat = $c;
}

// URL پایه برای لینک‌های فیلتر
function shopUrl(array $overrides = []): string {
    global $category, $q, $sort, $pMin, $pMax;
    $params = array_filter([
        'category' => $category, 'q' => $q, 'sort' => ($sort !== 'new' ? $sort : ''),
        'price_min' => $pMin, 'price_max' => $pMax,
    ], fn($v) => $v !== '' && $v !== null);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
        else $params[$k] = $v;
    }
    $qs = $params ? '?' . http_build_query($params) : '';
    return '/shop/' . $qs;
}
?>

<div class="page-head">
    <div class="breadcrumb"><a href="/">خانه</a> / فروشگاه<?= $activeCat ? ' / ' . htmlspecialchars($activeCat['name_fa']) : '' ?></div>
    <h1><?= $activeCat ? htmlspecialchars($activeCat['name_fa']) : 'همه محصولات' ?></h1>
    <p>کالاهای سورس‌شده از چین با بهترین کیفیت و قیمت — به تومان و با نرخ لحظه‌ای دلار</p>
</div>

<div class="cat-chips">
    <a href="index.php" class="cat-chip <?= $category === '' ? 'active' : '' ?>">همه <small>(<?= Products::countAll() ?>)</small></a>
    <?php foreach ($categories as $c): ?>
        <a href="index.php?category=<?= $c['slug'] ?>" class="cat-chip <?= $category === $c['slug'] ? 'active' : '' ?>">
            <?= $c['icon'] ?> <?= htmlspecialchars($c['name_fa']) ?> <small>(<?= (int)$c['product_count'] ?>)</small>
        </a>
    <?php endforeach; ?>
</div>

<div class="list-toolbar">
    <form method="get" class="search-box">
        <?php if ($category): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="جستجوی محصول...">
        <button type="submit">جستجو</button>
    </form>
    <div class="toolbar-right">
        <form method="get" style="display:flex; gap:8px; align-items:center;">
            <?php if ($category): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
            <?php if ($q): ?><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
            <div class="price-filters">
                <input type="number" name="price_min" min="0" placeholder="از (تومان)" value="<?= $pMin ?: '' ?>">
                <span>تا</span>
                <input type="number" name="price_max" min="0" placeholder="تا (تومان)" value="<?= $pMax ?: '' ?>">
            </div>
            <select name="sort" onchange="this.form.submit()">
                <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>جدیدترین</option>
                <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>محبوب‌ترین</option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین</option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>گران‌ترین</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>قدیمی‌ترین</option>
            </select>
            <label style="font-size:.82rem; color:var(--shop-muted); display:flex; align-items:center; gap:5px;">
                <input type="checkbox" name="instock" value="1" <?= isset($_GET['instock']) ? 'checked' : '' ?> style="accent-color:var(--shop-gold)"> فقط موجود
            </label>
            <button type="submit" class="btn btn-ghost btn-sm">اعمال</button>
        </form>
    </div>
</div>

<?php if ($result['items']): ?>
    <div class="result-count"><?= Price::faNumber($result['total']) ?> محصول یافت شد</div>
    <div class="products-grid">
        <?php foreach ($result['items'] as $p):
            $price = (int)$p['display_price'];
            $oldPrice = null;
            if ($p['currency'] === 'usd' && $p['old_price_toman']) {
                // قیمت کهنه دلاری نداریم؛ اگر قیمت کهنه ثبت شده همان را نشان بده
                $oldPrice = (int)$p['old_price_toman'];
            } elseif ($p['old_price_toman']) {
                $oldPrice = (int)$p['old_price_toman'];
            }
            $isNew = strtotime($p['created_at']) > time() - 86400 * 7;
            $out = (int)$p['stock'] < 1;
        ?>
        <div class="p-card">
            <a href="product.php?slug=<?= $p['slug'] ?>">
                <div class="img-wrap">
                    <img src="<?= htmlspecialchars($p['first_image']) ?>" alt="<?= htmlspecialchars($p['name_fa']) ?>" loading="lazy">
                    <div class="p-badges">
                        <?php if ($oldPrice && $oldPrice > $price): ?>
                            <span class="badge badge-sale"><?= Price::faNumber((int)(100 - $price * 100 / $oldPrice)) ?>٪ تخفیف</span>
                        <?php endif; ?>
                        <?php if ($isNew && (int)$p['created_at'] > 0 && strtotime($p['created_at']) > time() - 86400 * 7): ?>
                            <span class="badge badge-new">جدید</span>
                        <?php endif; ?>
                        <?php if ($p['currency'] === 'usd'): ?>
                            <span class="badge badge-usd">دلاری 💵</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($out): ?><span class="badge-out">ناموجود</span><?php endif; ?>
                </div>
            </a>
            <div class="p-body">
                <div class="p-cat"><?= $p['category_name'] ? htmlspecialchars($p['category_name']) : 'بدون دسته' ?></div>
                <div class="p-name"><a href="product.php?slug=<?= $p['slug'] ?>"><?= htmlspecialchars($p['name_fa']) ?></a></div>
                <div class="p-price-row">
                    <span class="p-price"><?= Price::faNumber($price) ?> <small>تومان</small></span>
                    <?php if ($oldPrice && $oldPrice > $price): ?>
                        <span class="p-old-price"><?= Price::faNumber($oldPrice) ?></span>
                    <?php endif; ?>
                </div>
                <div class="p-stock-line <?= !$out && (int)$p['stock'] <= 10 ? 'low' : '' ?>">
                    <?= $out ? 'ناموجود' : ((int)$p['stock'] <= 10 ? 'فقط ' . Price::faNumber((int)$p['stock']) . ' عدد باقی مانده' : 'موجود در انبار') ?>
                </div>
                <div class="p-actions">
                    <a href="product.php?slug=<?= $p['slug'] ?>" class="btn btn-ghost btn-sm">مشاهده</a>
                    <?php if (!$out): ?>
                        <button class="btn btn-gold btn-sm" data-add-cart="<?= $p['id'] ?>">سبد خرید 🛒</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                <?php if ($i === $result['page']): ?>
                    <span class="current"><?= Price::faNumber($i) ?></span>
                <?php else: ?>
                    <a href="<?= shopUrl(['page' => $i]) ?>"><?= Price::faNumber($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="empty-state">
        <div class="ico">🔍</div>
        <h3>محصولی پیدا نشد</h3>
        <p>فیلترها را تغییر دهید یا با ما تماس بگیرید — شاید بتوانیم آن را برایتان سورس کنیم!</p>
        <a href="index.php" class="btn btn-gold">نمایش همه محصولات</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
