<?php
/**
 * فروشگاه — سبد خرید
 */
declare(strict_types=1);

use Shop\Price, Shop\Cart;

$shopTitle  = 'سبد خرید';
$shopActive = 'cart';
require __DIR__ . '/../shop/_header.php';

$summary = Cart::summary();
$freeMin = (int)Settings::get('shop_free_shipping_min', 0);
$toFree  = $freeMin > 0 ? max(0, $freeMin - max(0, $summary['subtotal'] - $summary['discount'])) : 0;
?>

<div class="page-head">
    <div class="breadcrumb"><a href="/">خانه</a> / <a href="/shop/">فروشگاه</a> / سبد خرید</div>
    <h1>سبد خرید شما 🛒</h1>
    <p><?= Price::faNumber($summary['count']) ?> قلم کالا</p>
</div>

<?php if (empty($summary['items'])): ?>
    <div class="empty-state">
        <div class="ico">🛒</div>
        <h3>سبد خرید شما خالی است</h3>
        <p>محصولات مورد علاقه خود را به سبد اضافه کنید.</p>
        <a href="/shop/" class="btn btn-gold">مشاهده فروشگاه</a>
    </div>
<?php else: ?>
    <div class="cart-layout">
        <div class="cart-items">
            <?php foreach ($summary['items'] as $it): $p = $it['product']; ?>
                <div class="cart-item">
                    <img class="ci-img" src="<?= htmlspecialchars($p['first_image']) ?>" alt="">
                    <div>
                        <div class="ci-name"><a href="/shop/product.php?slug=<?= $p['slug'] ?>"><?= htmlspecialchars($p['name_fa']) ?></a></div>
                        <div class="ci-unit">
                            قیمت واحد: <?= Price::tomanLabel($it['unit_price']) ?>
                            <?php if ($p['currency'] === 'usd'): ?><span style="color:var(--shop-muted);"> (دلاری 💵)</span><?php endif; ?>
                        </div>
                        <button class="ci-remove" data-cart-remove="<?= $p['id'] ?>" title="حذف از سبد">🗑</button>
                    </div>
                    <div class="ci-controls">
                        <div class="qty-box">
                            <button type="button" onclick="adj(this,1)">+</button>
                            <input type="number" value="<?= $it['qty'] ?>" min="1" max="<?= (int)$p['stock'] ?>" data-cart-qty="<?= $p['id'] ?>">
                            <button type="button" onclick="adj(this,-1)">−</button>
                        </div>
                        <div class="ci-total"><?= Price::faNumber($it['line_total']) ?> تومان</div>
                    </div>
                </div>
            <?php endforeach; ?>
            <script>
                function adj(btn, d) {
                    const inp = btn.parentElement.querySelector('input');
                    const v = Math.max(1, Math.min(parseInt(inp.max || 999, 10), (parseInt(inp.value || 1, 10) || 1) + d));
                    inp.value = v;
                    inp.dispatchEvent(new Event('change'));
                }
            </script>
        </div>

        <div class="summary-card">
            <h3>خلاصه سفارش</h3>

            <?php if ($summary['coupon']): ?>
                <div class="coupon-applied">
                    <span>🏷️ کوپن: <strong><?= htmlspecialchars($summary['coupon']) ?></strong> (<?= Price::faNumber($summary['discount']) ?> تومان)</span>
                    <button type="button" id="couponRemove" title="حذف کوپن">✕</button>
                </div>
            <?php else: ?>
                <form id="couponForm">
                    <div class="coupon-row">
                        <input type="text" name="code" placeholder="کد تخفیف (اختیاری)" maxlength="40">
                        <button type="submit" class="btn btn-ghost btn-sm">اعمال</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="sum-row"><span>جمع کالاها</span><span><?= Price::tomanLabel($summary['subtotal']) ?></span></div>
            <?php if ($summary['discount'] > 0): ?>
                <div class="sum-row"><span>تخفیف</span><span style="color:var(--shop-green);">− <?= Price::faNumber($summary['discount']) ?> تومان</span></div>
            <?php endif; ?>
            <div class="sum-row">
                <span>هزینه ارسال</span>
                <?php if ($summary['shipping'] === 0): ?><span class="free">رایگان 🎉</span>
                <?php else: ?><span><?= Price::faNumber($summary['shipping']) ?> تومان</span><?php endif; ?>
            </div>
            <?php if ($toFree > 0): ?>
                <div class="sum-row" style="font-size:.8rem; color:var(--shop-muted);">
                    <span>با خرید <?= Price::faNumber($toFree) ?> تومان دیگر، ارسال رایگان می‌شود</span>
                </div>
            <?php endif; ?>
            <div class="sum-row total"><span>مبلغ قابل پرداخت</span><span class="val"><?= Price::faNumber($summary['total']) ?> تومان</span></div>
            <a href="/checkout/" class="btn btn-gold btn-block" style="margin-top:16px;">ادامه فرآیند خرید ←</a>
            <a href="/shop/" class="btn btn-ghost btn-block" style="margin-top:10px;">ادامه خرید</a>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../shop/_footer.php'; ?>
