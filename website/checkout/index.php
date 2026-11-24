<?php
/**
 * فروشگاه — تسویه حساب (فرم ارسال)
 */
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
// ------------------

use Shop\Price, Shop\Cart, Shop\Customers;

$shopTitle  = 'تسویه حساب';
$shopActive = 'checkout';
require __DIR__ . '/../shop/_header.php';

$summary = Cart::summary();

if (empty($summary['items'])) {
    header('Location: /cart/');
    exit;
}

$shopUser = Customers::user();
$prefill = [
    'name'          => $shopUser['full_name'] ?? '',
    'phone'         => $shopUser['phone'] ?? '',
    'email'         => $shopUser['email'] ?? '',
    'province'      => $shopUser['province'] ?? '',
    'city'          => $shopUser['city'] ?? '',
    'postal_code'   => $shopUser['postal_code'] ?? '',
    'address'       => $shopUser['address'] ?? '',
];
?>

<div class="page-head">
    <div class="breadcrumb"><a href="/">خانه</a> / <a href="/shop/">فروشگاه</a> / <a href="/cart/">سبد خرید</a> / تسویه</div>
    <h1>تسویه حساب</h1>
</div>

<div class="steps">
    <div class="step done">سبد خرید</div>
    <div class="step active">اطلاعات ارسال</div>
    <div class="step">تأیید و ثبت</div>
</div>

<div class="checkout-layout">
    <form id="checkoutForm" class="form-card" novalidate>
        <h3 style="color:#fff; margin:0 0 6px;">📦 آدرس و اطلاعات ارسال</h3>
        <?php if ($shopUser): ?>
            <div class="alert info">اطلاعات شما از حساب کاربری پر شده است — در صورت نیاز ویرایش کنید.</div>
        <?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label>نام و نام خانوادگی <span class="req">*</span></label>
                <input type="text" name="name" required value="<?= htmlspecialchars($prefill['name']) ?>" maxlength="100">
            </div>
            <div class="field">
                <label>شماره موبایل <span class="req">*</span></label>
                <input type="tel" name="phone" required value="<?= htmlspecialchars($prefill['phone']) ?>" placeholder="09xxxxxxxxx" maxlength="20" inputmode="numeric">
            </div>
            <div class="field">
                <label>ایمیل <span style="color:var(--shop-muted); font-weight:400;">(اختیاری — برای رسید)</span></label>
                <input type="email" name="email" value="<?= htmlspecialchars($prefill['email']) ?>" maxlength="200">
            </div>
            <div class="field">
                <label>استان</label>
                <input type="text" name="province" value="<?= htmlspecialchars($prefill['province']) ?>" maxlength="100" placeholder="مثلاً تهران">
            </div>
            <div class="field">
                <label>شهر</label>
                <input type="text" name="city" value="<?= htmlspecialchars($prefill['city']) ?>" maxlength="100">
            </div>
            <div class="field">
                <label>کد پستی</label>
                <input type="text" name="postal_code" value="<?= htmlspecialchars($prefill['postal_code']) ?>" maxlength="20" inputmode="numeric" placeholder="۱۰ رقمی">
            </div>
            <div class="field full">
                <label>آدرس کامل <span class="req">*</span></label>
                <textarea name="address" required minlength="10" placeholder="خیابان، کوچه، پلاک، واحد..."><?= htmlspecialchars($prefill['address']) ?></textarea>
            </div>
            <div class="field full">
                <label>یادداشت برای فروشنده (اختیاری)</label>
                <textarea name="notes" placeholder="مثلاً: زمان مناسب تماس، توضیح دقیق‌تر آدرس..."></textarea>
            </div>
        </div>

        <div class="alert" id="checkoutErrors" style="display:none;"></div>
        <button type="submit" class="btn btn-gold btn-block" id="checkoutBtn" style="margin-top:10px;">
            ثبت نهایی سفارش — <?= Price::tomanLabel($summary['total']) ?>
        </button>
        <p style="color:var(--shop-muted); font-size:.8rem; margin-top:12px; text-align:center;">
            با ثبت سفارش، اطلاعات شما فقط برای ارسال کالا استفاده می‌شود. پرداخت پس از تأیید سفارش توسط تیم ما انجام می‌شود (کارت به‌صورت حضوری/واریز — درگاه بانکی در نسخه بعدی).
        </p>
    </form>

    <div class="summary-card">
        <h3>سفارش شما</h3>
        <?php foreach ($summary['items'] as $it): $p = $it['product']; ?>
            <div style="display:flex; gap:10px; align-items:center; padding:10px 0; border-bottom:1px solid var(--shop-line);">
                <img src="<?= htmlspecialchars($p['first_image']) ?>" style="width:52px; height:52px; border-radius:10px; object-fit:cover; background:#f4f5f7;" alt="">
                <div style="flex:1; min-width:0;">
                    <div style="font-size:.85rem; font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= htmlspecialchars($p['name_fa']) ?>
                    </div>
                    <div style="color:var(--shop-muted); font-size:.78rem;">× <?= Price::faNumber($it['qty']) ?></div>
                </div>
                <div style="color:var(--shop-gold); font-weight:700; font-size:.88rem; white-space:nowrap;">
                    <?= Price::faNumber($it['line_total']) ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="sum-row"><span>جمع کالاها</span><span><?= Price::faNumber($summary['subtotal']) ?></span></div>
        <?php if ($summary['discount'] > 0): ?>
            <div class="sum-row"><span>تخفیف (<?= htmlspecialchars($summary['coupon']) ?>)</span><span style="color:var(--shop-green);">− <?= Price::faNumber($summary['discount']) ?></span></div>
        <?php endif; ?>
        <div class="sum-row"><span>ارسال</span><span><?= $summary['shipping'] ? Price::faNumber($summary['shipping']) . ' تومان' : 'رایگان 🎉' ?></span></div>
        <div class="sum-row total"><span>مجموع</span><span class="val"><?= Price::faNumber($summary['total']) ?> تومان</span></div>
    </div>
</div>

<script>
document.getElementById('checkoutForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const errBox = document.getElementById('checkoutErrors');
    const btn = document.getElementById('checkoutBtn');
    errBox.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'در حال ثبت...';
    try {
        const data = Object.fromEntries(new FormData(this).entries());
        const res = await fetch('api/checkout.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
        });
        const r = await res.json();
        if (r.success) {
            window.location.href = `/checkout/success.php?n=${encodeURIComponent(r.order_no)}&t=${encodeURIComponent(r.token)}`;
        } else {
            const msgs = r.errors || [r.error || 'خطا در ثبت سفارش'];
            errBox.innerHTML = msgs.map(m => `<div class="alert error">${m}</div>`).join('');
            errBox.style.display = 'block';
            errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            btn.disabled = false;
            btn.innerHTML = 'ثبت نهایی سفارش — <?= Price::tomanLabel($summary['total']) ?>';
        }
    } catch (err) {
        errBox.innerHTML = '<div class="alert error">خطای ارتباط با سرور. دوباره تلاش کنید.</div>';
        errBox.style.display = 'block';
        btn.disabled = false;
    }
});
</script>

<?php require __DIR__ . '/../shop/_footer.php'; ?>
