</main>
<?php if (!empty($shopLanding)): ?>
<footer class="market-footer">
    <div class="wrap">
        <div class="market-footer-top">
            <div class="market-footer-brand"><a href="/shop/" class="market-brand"><img src="/logo.webp" alt=""><span><strong><?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?></strong><small>تجارت فراتر از مرزها</small></span></a><p>جایی برای پیدا کردن و بررسی کالاهای کاربردی، با همراهی تیم درخت سپید.</p></div>
            <div><h3>دسترسی سریع</h3><a href="/shop/#shop-products">همه محصولات</a><a href="/cart/">سبد خرید</a><a href="/account/">حساب کاربری</a></div>
            <div><h3>درخت سپید</h3><a href="/">سایت اصلی</a><a href="/track/">پیگیری محموله</a><a href="/blog/">بلاگ</a></div>
            <div><h3>کنار شما هستیم</h3><p>برای پرسش درباره کالاها و سفارش‌ها با تیم ما در ارتباط باشید.</p><a class="market-footer-contact" href="/#contact">راه‌های ارتباط با ما <span aria-hidden="true">←</span></a></div>
        </div>
        <div class="market-footer-bottom"><span>© <?= \Shop\Jalali::toPersianDigits(date('Y')) ?> <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?> — همه حقوق محفوظ است.</span><a href="/">بازگشت به سایت اصلی ↗</a></div>
    </div>
</footer>
<?php else: ?>
<footer class="shop-footer">
    <div class="wrap">
        <div style="margin-bottom:10px;">
            <a href="/shop/">فروشگاه</a> ·
            <a href="/blog/">بلاگ</a> ·
            <a href="/track/">ردیابی محموله</a> ·
            <a href="/#contact">تماس با ما</a> ·
            <a href="/account/">حساب کاربری</a>
        </div>
        <div><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars(SITE_NAME_EN, ENT_QUOTES, 'UTF-8') ?> | قیمت‌ها به تومان نمایش داده می‌شوند.</div>
        <div style="margin-top:6px; opacity:.7;">© <?= \Shop\Jalali::toPersianDigits(date('Y')) ?> — همه حقوق محفوظ است</div>
    </div>
</footer>
<?php endif; ?>
<script src="/js/shop.js?v=2"></script>
</body>
</html>
