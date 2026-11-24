<?php
/** Storefront and filterable catalogue, using only real shop data. */
declare(strict_types=1);
use Shop\Price, Shop\Products;

$shopTitle='فروشگاه'; $shopActive='shop'; $shopLanding=true;
require __DIR__ . '/_header.php';

$perPage=max(1,min(48,(int)Settings::get('shop_per_page',12)));
$category=(string)($_GET['category'] ?? '');
$q=mb_substr(trim((string)($_GET['q'] ?? '')),0,200);
$sort=(string)($_GET['sort'] ?? 'new');
if (!in_array($sort,['new','popular','price_asc','price_desc','oldest'],true)) $sort='new';
$pMin=max(0,(int)($_GET['price_min'] ?? 0)); $pMax=max(0,(int)($_GET['price_max'] ?? 0));
$inStock=!empty($_GET['instock']); $page=max(1,(int)($_GET['page'] ?? 1));
$result=Products::list(['category'=>$category,'q'=>$q,'sort'=>$sort,'price_min'=>$pMin,'price_max'=>$pMax,'stock'=>$inStock],$page,$perPage);
$categories=Products::categories(); $activeCat=null;
foreach ($categories as $c) if ($c['slug']===$category) $activeCat=$c;
$isLanding=$category==='' && $q==='' && $sort==='new' && !$pMin && !$pMax && !$inStock && $page===1;
function shop_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
function shopUrl(array $overrides=[]): string {
    global $category,$q,$sort,$pMin,$pMax,$inStock;
    $params=array_filter(['category'=>$category,'q'=>$q,'sort'=>$sort!=='new'?$sort:'','price_min'=>$pMin,'price_max'=>$pMax,'instock'=>$inStock?'1':''],fn($v)=>$v!=='' && $v!==null && $v!==0);
    foreach ($overrides as $k=>$v) { if ($v==='' || $v===null) unset($params[$k]); else $params[$k]=$v; }
    return '/shop/'.($params?'?'.http_build_query($params):'').'#shop-products';
}
?>
<?php if ($isLanding): ?>
<section class="market-hero" aria-labelledby="hero-title">
    <div class="hero-content"><span class="hero-kicker"><i></i> فروشگاه درخت سپید</span><h1 id="hero-title">یک انتخاب خوب،<br><span>برای هر روز.</span></h1><p>از کالاهای کاربردی تا محصولات خاص؛ با خیال راحت میان انتخاب‌ها بگردید و کالای مناسب خود را پیدا کنید.</p><div class="hero-buttons"><a class="market-button market-button-dark" href="#shop-products">دیدن محصولات <span aria-hidden="true">←</span></a><a class="hero-link" href="#shop-categories">دسته‌بندی‌ها <span aria-hidden="true">↙</span></a></div></div>
    <div class="hero-mark" aria-hidden="true"><span>THE SEPID</span><span>COLLECTION / 01</span></div>
</section>
<div class="market-benefits" aria-label="خدمات فروشگاه">
  <div><span class="benefit-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h11v10H4zM15 10h3l2 3v4h-5M7 19h.1M17 19h.1"/></svg></span><span><strong>انتخاب و بررسی کالا</strong><small>جزئیات محصولات را ببینید</small></span></div>
  <div><span class="benefit-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M8 14h4"/></svg></span><span><strong>پیگیری سفارش</strong><small>از حساب کاربری خود پیگیری کنید</small></span></div>
  <div><span class="benefit-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 0 1 16 0v4a2 2 0 0 1-2 2h-1v-6h3M4 12h3v6H6a2 2 0 0 1-2-2zM17 19c-1 1-3 2-5 2"/></svg></span><span><strong>همراهی در خرید</strong><small>برای پرسش‌ها با ما در تماس باشید</small></span></div>
</div>
<?php endif; ?>
<?php if ($categories): ?>
<section class="market-categories" id="shop-categories" aria-labelledby="categories-title">
  <div class="section-heading"><div><span class="section-kicker">کشف کنید</span><h2 id="categories-title">از کجا شروع کنیم؟</h2><p>دسته‌بندی موردنظرتان را انتخاب کنید</p></div><a class="section-more" href="/shop/#shop-products">همه محصولات <span aria-hidden="true">←</span></a></div>
  <div class="category-list">
    <?php foreach ($categories as $c): ?><a class="category-card<?= $category===$c['slug']?' is-active':'' ?>" href="<?= shop_h('/shop/?'.http_build_query(['category'=>$c['slug']]).'#shop-products') ?>"><span class="category-icon" aria-hidden="true"><?= shop_h($c['icon'] ?: '📦') ?></span><strong><?= shop_h($c['name_fa']) ?></strong><small><?= Price::faNumber((int)$c['product_count']) ?> کالا</small></a><?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<section class="market-catalog" id="shop-products" aria-labelledby="products-title">
  <div class="section-heading catalog-heading"><div><span class="section-kicker">فروشگاه درخت سپید</span><h2 id="products-title"><?= $activeCat ? shop_h($activeCat['name_fa']) : ($isLanding ? 'تازه‌های فروشگاه' : 'محصولات فروشگاه') ?></h2><p><?= Price::faNumber((int)$result['total']) ?> محصول <?= ($q!=='' || $category!=='')?'در نتایج شما':'برای دیدن و انتخاب' ?></p></div><a class="section-more" href="/shop/#shop-products">مشاهده همه <span aria-hidden="true">←</span></a></div>
  <form method="get" action="/shop/#shop-products" class="market-filters" aria-label="فیلتر محصولات">
    <?php if ($category!==''): ?><input type="hidden" name="category" value="<?= shop_h($category) ?>"><?php endif; ?>
    <?php if ($q!==''): ?><input type="hidden" name="q" value="<?= shop_h($q) ?>"><?php endif; ?>
    <span class="filters-label"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M7 12h10M10 17h4"/></svg> مرتب‌سازی و فیلتر</span>
    <label><span class="sr-only">مرتب‌سازی</span><select name="sort"><option value="new" <?= $sort==='new'?'selected':'' ?>>جدیدترین</option><option value="popular" <?= $sort==='popular'?'selected':'' ?>>محبوب‌ترین</option><option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?>>ارزان‌ترین</option><option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>گران‌ترین</option><option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>قدیمی‌ترین</option></select></label>
    <div class="filter-prices"><label><span class="sr-only">حداقل قیمت به تومان</span><input type="number" name="price_min" min="0" placeholder="از تومان" value="<?= $pMin ?: '' ?>"></label><span>تا</span><label><span class="sr-only">حداکثر قیمت به تومان</span><input type="number" name="price_max" min="0" placeholder="تا تومان" value="<?= $pMax ?: '' ?>"></label></div>
    <label class="filter-stock"><input type="checkbox" name="instock" value="1" <?= $inStock?'checked':'' ?>><span>فقط موجود</span></label><button class="filter-submit" type="submit">اعمال</button>
    <?php if (!$isLanding): ?><a class="filter-clear" href="/shop/#shop-products">پاک کردن فیلترها</a><?php endif; ?>
  </form>
  <?php if ($q!==''): ?><p class="search-summary">نتایج جست‌وجو برای «<?= shop_h($q) ?>»</p><?php endif; ?>
  <?php if ($result['items']): ?>
  <div class="products-grid">
    <?php foreach ($result['items'] as $p):
      $price=(int)$p['display_price']; $old=(int)($p['old_price_toman'] ?? 0); $out=(int)$p['stock']<1;
      $stamp=strtotime((string)($p['created_at'] ?? '')); $isNew=$stamp!==false && $stamp>time()-86400*7;
      $productUrl='/shop/product.php?'.http_build_query(['slug'=>$p['slug']]);
    ?>
    <article class="p-card">
      <a class="product-image-link" href="<?= shop_h($productUrl) ?>" aria-label="مشاهده <?= shop_h($p['name_fa']) ?>"><div class="img-wrap"><img src="<?= shop_h($p['first_image']) ?>" alt="<?= shop_h($p['name_fa']) ?>" loading="lazy"><div class="p-badges"><?php if ($old>$price && $price>0): ?><span class="badge badge-sale"><?= Price::faNumber((int)(100-$price*100/$old)) ?>٪ تخفیف</span><?php elseif ($isNew): ?><span class="badge badge-new">جدید</span><?php endif; ?></div><?php if ($out): ?><span class="badge-out">ناموجود</span><?php endif; ?></div></a>
      <div class="p-body"><div class="p-cat"><?= shop_h($p['category_name'] ?: 'محصولات فروشگاه') ?></div><h3 class="p-name"><a href="<?= shop_h($productUrl) ?>"><?= shop_h($p['name_fa']) ?></a></h3><div class="p-stock-line <?= !$out && (int)$p['stock']<=10?'low':'' ?>"><?= $out?'ناموجود':((int)$p['stock']<=10?'فقط '.Price::faNumber((int)$p['stock']).' عدد باقی مانده':'موجود در انبار') ?></div><div class="p-price-row"><?php if ($price>0): ?><span class="p-price"><?= Price::faNumber($price) ?> <small>تومان</small></span><?php if ($old>$price): ?><span class="p-old-price"><?= Price::faNumber($old) ?></span><?php endif; ?><?php else: ?><span class="p-price price-inquiry">استعلام قیمت</span><?php endif; ?></div><div class="p-actions"><a href="<?= shop_h($productUrl) ?>" class="product-detail">جزئیات <span aria-hidden="true">←</span></a><?php if (!$out && $price>0): ?><button type="button" class="product-add" data-add-cart="<?= (int)$p['id'] ?>" aria-label="افزودن <?= shop_h($p['name_fa']) ?> به سبد خرید"><span aria-hidden="true">＋</span></button><?php endif; ?></div></div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php if ($result['pages']>1): ?><nav class="pagination" aria-label="صفحه‌بندی محصولات"><?php for ($i=max(1, $result['page']-3);$i<=min($result['pages'], $result['page']+3);$i++): ?><?php if ($i===$result['page']): ?><span class="current" aria-current="page"><?= Price::faNumber($i) ?></span><?php else: ?><a href="<?= shop_h(shopUrl(['page'=>$i])) ?>"><?= Price::faNumber($i) ?></a><?php endif; ?><?php endfor; ?></nav><?php endif; ?>
  <?php else: ?><div class="market-empty"><span aria-hidden="true">⌕</span><h3>محصولی پیدا نشد</h3><p>جست‌وجو یا فیلترها را تغییر دهید، یا برای پیدا کردن کالای موردنظرتان با ما تماس بگیرید.</p><a class="market-button market-button-dark" href="/shop/#shop-products">نمایش همه محصولات</a></div><?php endif; ?>
</section>
<?php if ($isLanding): ?><section class="market-help"><div><span class="section-kicker">برای انتخاب بهتر</span><h2>دنبال کالای خاصی هستید؟</h2><p>اگر کالای موردنظرتان را پیدا نکردید، درخواستتان را با تیم ما در میان بگذارید.</p></div><a href="/#contact" class="market-button market-button-light">ارتباط با ما <span aria-hidden="true">←</span></a><span class="help-watermark" aria-hidden="true">✳</span></section><?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
