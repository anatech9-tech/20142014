<?php
/**
 * درخت سپید تجارت پارس — Main Entry Point
 * Loads dynamic content from DB and serves as the main page.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/Settings.php';
require_once BASE_PATH . '/includes/SiteContent.php';
require_once BASE_PATH . '/includes/I18n.php';
require_once BASE_PATH . '/includes/Analytics.php';
require_once BASE_PATH . '/includes/shop/Jalali.php';
require_once BASE_PATH . '/includes/shop/Price.php';
require_once BASE_PATH . '/includes/shop/Products.php';

use Shop\Price, Shop\Products;

// Track page view
Analytics::trackPageView(SITE_NAME);

// Load all dynamic data
$aboutSection   = SiteContent::section('about');
$heroSection    = SiteContent::section('hero');
$services       = SiteContent::services();
$team           = SiteContent::team();
$network        = SiteContent::network();
$whyUsItems     = SiteContent::whyUs();
$processSteps   = SiteContent::processSteps();

// Products (shop) — اگر جدول‌های فروشگاه موجود نباشند، بدون خطا خالی می‌ماند
try {
    $latestProducts = Products::latest(8);
    $popularProducts = Products::popular(8);
} catch (Throwable $e) {
    $latestProducts = [];
    $popularProducts = [];
}

// Settings with defaults
$siteName     = Settings::get('site_name', SITE_NAME);
$siteEmail    = Settings::get('site_email', SITE_EMAIL);
$sitePhone    = Settings::get('site_phone', SITE_PHONE);
$siteAddress  = Settings::get('site_address', 'دفتر مرکزی: ایران');
$heroTitle    = $heroSection['title'] ?: Settings::get('hero_title', 'درخت سپید تجارت پارس');
$heroSubtitle = $heroSection['subtitle'] ?: Settings::get('hero_subtitle', 'حمل‌ونقل بین‌المللی، ترخیص کالا و بازرگانی');
$heroTagline  = $heroSection['body'] ?: Settings::get('hero_tagline', 'ارتباط مطمئن ایران با جهان');
$seoTitle     = Settings::get('seo_meta_title', SITE_NAME);
$seoDesc      = Settings::get('seo_meta_description', '');
$seoOgImage   = Settings::get('seo_og_image', '');
$gaId         = Settings::get('google_analytics_id', '');

// Helpers
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// If we want the page to come from index.html (for full cache), check it
// For dynamic, we generate here.
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($seoDesc ?: $heroSubtitle) ?>">
    <meta property="og:title" content="<?= e($seoTitle) ?>">
    <meta property="og:description" content="<?= e($seoDesc ?: $heroSubtitle) ?>">
    <meta property="og:type" content="website">
    <?php if ($seoOgImage): ?><meta property="og:image" content="<?= e($seoOgImage) ?>"><?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <title><?= e($seoTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/shop.css">
    <link rel="preload" href="logo.webp" as="image" type="image/webp">
    <link rel="preload" href="textures/free3d_earth_albedo.jpg" as="image">
    <link rel="preload" href="textures/free3d_earth_bump.jpg" as="image">
    <link rel="preload" href="textures/free3d_earth_clouds.webp" as="image" type="image/webp">
    <link rel="preload" href="textures/free3d_earth_land_ocean_mask.png" as="image">
    <link rel="preload" href="textures/moon_texture_2k.jpg" as="image">
    <link rel="icon" href="logo.webp" type="image/webp">

    <script type="importmap">
    {
        "imports": {
            "three": "https://unpkg.com/three@0.170.0/build/three.module.js",
            "three/addons/": "https://unpkg.com/three@0.170.0/examples/jsm/",
            "gsap": "https://esm.sh/gsap@3.12.5",
            "gsap/ScrollTrigger": "https://esm.sh/gsap@3.12.5/ScrollTrigger",
            "lenis": "https://unpkg.com/lenis@1.1.0/dist/lenis.mjs"
        }
    }
    </script>

    <?php if ($gaId): ?>
    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($gaId) ?>"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '<?= e($gaId) ?>');
    </script>
    <?php endif; ?>
</head>
<body>
    <!-- Loading Screen -->
    <div class="loader" id="loader">
        <img src="logo.webp" alt="لوگو" class="loader-logo">
        <div class="loader-text">در حال بارگذاری...</div>
        <div class="loader-bar">
            <div class="loader-bar-fill" id="loaderBar"></div>
        </div>
    </div>

    <canvas id="galaxy"></canvas>
    <canvas id="webgl"></canvas>
    <img src="logo.webp" alt="" class="scroll-logo-anim" id="scrollLogoAnim" aria-hidden="true">

    <nav class="navbar" id="navbar">
        <div class="nav-brand">
            <img src="logo.webp" alt="<?= e($siteName) ?>" class="nav-logo">
            <span class="brand-text"><?= e($siteName) ?></span>
        </div>
        <div class="nav-links">
            <a href="#hero" class="nav-link active">خانه</a>
            <a href="#about" class="nav-link">درباره ما</a>
            <a href="#services" class="nav-link">خدمات</a>
            <a href="#network" class="nav-link">شبکه جهانی</a>
            <a href="#why" class="nav-link">چرا ما</a>
            <a href="#process" class="nav-link">فرآیند</a>
            <a href="#team" class="nav-link">تیم ما</a>
            <a href="/blog/" class="nav-link">بلاگ</a>
            <a href="/shop/" class="nav-link" style="color: var(--secondary); font-weight: 700;">🛒 فروشگاه</a>
            <a href="#contact" class="nav-link">تماس</a>
        </div>
        <button class="nav-toggle" id="navToggle" type="button" aria-label="باز کردن منو" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </nav>

    <main class="scroll-container">
        <section class="vh-100" id="hero">
            <div class="hero-content">
                <img src="logo.webp" alt="لوگوی <?= e($siteName) ?>" class="hero-logo">
                <h1 class="hero-title"><?= e($heroTitle) ?></h1>
                <p class="hero-subtitle"><?= e($heroSubtitle) ?></p>
                <p class="hero-tagline"><?= e($heroTagline) ?></p>
                <div class="hero-stats">
                    <div class="stat-item"><span class="stat-number">۱۰+</span><span class="stat-label">سال تجربه</span></div>
                    <div class="stat-item"><span class="stat-number">۵</span><span class="stat-label">دفتر بین‌المللی</span></div>
                    <div class="stat-item"><span class="stat-number">۲۴</span><span class="stat-label">ساعت پشتیبانی</span></div>
                </div>
                <a href="#contact" class="cta-button" onclick="trackConversion('cta_click', 'hero')">مشاوره رایگان</a>
            </div>
        </section>

        <section class="vh-100" id="about">
            <div class="section-content">
                <span class="section-tag">درباره ما</span>
                <h2 class="section-title"><?= e($aboutSection['title'] ?: 'تبدیل چالش‌های پیچیده لجستیک به عملیات موفق') ?></h2>
                <p class="section-desc"><?= e($aboutSection['body'] ?: 'بیش از ۱۰ سال تجربه در حوزه حمل‌ونقل بین‌المللی، ترخیص کالا و بازرگانی.') ?></p>
                <a href="#services" class="cta-button">کشف خدمات</a>
            </div>
        </section>

        <section class="vh-100" id="services">
            <div class="section-content">
                <span class="section-tag">خدمات ما</span>
                <h2 class="section-title">راه‌حل‌های جامع لجستیک و بازرگانی</h2>
                <div class="services-grid">
                    <?php foreach ($services as $s): ?>
                        <div class="service-card">
                            <div class="service-icon">
                                <?php if ($s['icon_path']): ?>
                                    <img src="<?= e($s['icon_path']) ?>" alt="<?= e($s['title_fa']) ?>">
                                <?php endif; ?>
                            </div>
                            <h3><?= e($s['title_fa']) ?></h3>
                            <p><?= e($s['description_fa']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="vh-100" id="network">
            <div class="section-content">
                <span class="section-tag">شبکه جهانی</span>
                <h2 class="section-title">حضور مستقیم در مراکز مهم جهانی</h2>
                <div class="network-grid">
                    <?php foreach ($network as $n): ?>
                        <div class="network-item">
                            <span class="network-flag"><?= e($n['flag_emoji'] ?? '🏳️') ?></span>
                            <span class="network-name"><?= e($n['country_name_fa']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="vh-100" id="why">
            <div class="section-content">
                <span class="section-tag">چرا ما؟</span>
                <h2 class="section-title">مزیت‌های رقابتی <?= e($siteName) ?></h2>
                <div class="why-grid">
                    <?php foreach ($whyUsItems as $w): ?>
                        <div class="why-item">
                            <div class="why-number"><?= e($w['number_label']) ?></div>
                            <h4><?= e($w['title_fa']) ?></h4>
                            <p><?= e($w['description_fa']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="vh-100" id="process">
            <div class="section-content">
                <span class="section-tag">فرآیند کاری</span>
                <h2 class="section-title">از مشاوره تا تحویل، در کنار شما هستیم</h2>
                <div class="process-timeline">
                    <?php foreach ($processSteps as $p): ?>
                        <div class="process-step">
                            <div class="step-number"><?= e($p['step_number']) ?></div>
                            <div class="step-content">
                                <h4><?= e($p['title_fa']) ?></h4>
                                <p><?= e($p['description_fa']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="vh-100" id="team">
            <div class="section-content">
                <span class="section-tag">تیم ما</span>
                <h2 class="section-title">تیمی متخصص برای مدیریت مسیرهای پیچیده تجارت جهانی</h2>
                <p class="section-desc">ترکیبی از تجربه لجستیک بین‌المللی، دانش گمرکی و شبکه ارتباطی گسترده.</p>
                <div class="team-grid chroma-grid">
                    <?php
                    $cardStyles = [
                        ['#d4af37', 'linear-gradient(145deg, rgba(212,175,55,0.32), rgba(0,0,0,0.72))'],
                        ['#c9a227', 'linear-gradient(180deg, rgba(201,162,39,0.30), rgba(0,0,0,0.72))'],
                        ['#f4d35e', 'linear-gradient(215deg, rgba(244,211,94,0.28), rgba(0,0,0,0.72))'],
                        ['#e6c75a', 'linear-gradient(195deg, rgba(230,199,90,0.28), rgba(0,0,0,0.72))'],
                    ];
                    foreach ($team as $i => $m):
                        $style = $cardStyles[$i % 4];
                    ?>
                        <article class="team-card chroma-card" style="--card-border: <?= $style[0] ?>; --card-gradient: <?= $style[1] ?>;">
                            <div class="chroma-img-wrapper">
                                <?php if ($m['image_path']): ?>
                                    <img src="<?= e($m['image_path']) ?>" alt="<?= e($m['name']) ?>" loading="lazy">
                                <?php endif; ?>
                            </div>
                            <footer class="chroma-info">
                                <h3 class="name"><?= e($m['name']) ?></h3>
                                <p class="role"><?= e($m['role']) ?></p>
                                <?php if ($m['handle']): ?><span class="handle"><?= e($m['handle']) ?></span><?php endif; ?>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php if (!empty($latestProducts) || !empty($popularProducts)): ?>
        <section id="shop" style="padding: 90px 24px; background: radial-gradient(ellipse at top, rgba(212,175,55,.06), transparent 60%);">
            <div style="max-width: 1240px; margin: 0 auto;">
                <div style="text-align:center; margin-bottom: 36px;">
                    <span class="section-tag">فروشگاه</span>
                    <h2 class="section-title" style="margin-top: 12px;">جدیدترین محصولات</h2>
                    <p style="color: var(--gray); margin-top: 10px;">کالاهای سورس‌شده از چین — قیمت به تومان با نرخ لحظه‌ای دلار</p>
                </div>

                <?php if (!empty($latestProducts)): ?>
                <div class="products-grid" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
                    <?php foreach (array_slice($latestProducts, 0, 4) as $p):
                        $pPrice = Price::productToman($p);
                        $pOut = (int)$p['stock'] < 1;
                    ?>
                        <div class="p-card">
                            <a href="/shop/product.php?slug=<?= $p['slug'] ?>">
                                <div class="img-wrap">
                                    <img src="<?= htmlspecialchars($p['first_image']) ?>" alt="<?= htmlspecialchars($p['name_fa']) ?>" loading="lazy">
                                    <div class="p-badges">
                                        <?php if ($p['currency'] === 'usd'): ?><span class="badge badge-usd">دلاری 💵</span><?php endif; ?>
                                    </div>
                                    <?php if ($pOut): ?><span class="badge-out">ناموجود</span><?php endif; ?>
                                </div>
                            </a>
                            <div class="p-body">
                                <div class="p-name"><a href="/shop/product.php?slug=<?= $p['slug'] ?>"><?= htmlspecialchars($p['name_fa']) ?></a></div>
                                <div class="p-price-row"><span class="p-price"><?= Price::faNumber($pPrice) ?> <small>تومان</small></span></div>
                                <div class="p-actions">
                                    <?php if (!$pOut): ?>
                                        <a href="/shop/product.php?slug=<?= $p['slug'] ?>" class="btn btn-gold btn-sm" style="flex:1;">مشاهده و خرید</a>
                                    <?php else: ?>
                                        <a href="/#contact" class="btn btn-ghost btn-sm" style="flex:1;">استعلام موجودی</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($popularProducts)): ?>
                <div style="text-align:center; margin: 60px 0 30px;">
                    <h2 class="section-title" style="font-size: 1.9rem;">🔥 پرفروش‌ترین‌ها</h2>
                </div>
                <div class="products-grid" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
                    <?php foreach (array_slice($popularProducts, 0, 4) as $p):
                        $pPrice = Price::productToman($p);
                        $pOut = (int)$p['stock'] < 1;
                    ?>
                        <div class="p-card">
                            <a href="/shop/product.php?slug=<?= $p['slug'] ?>">
                                <div class="img-wrap">
                                    <img src="<?= htmlspecialchars($p['first_image']) ?>" alt="<?= htmlspecialchars($p['name_fa']) ?>" loading="lazy">
                                    <div class="p-badges">
                                        <?php if ($p['currency'] === 'usd'): ?><span class="badge badge-usd">دلاری 💵</span><?php endif; ?>
                                    </div>
                                    <?php if ($pOut): ?><span class="badge-out">ناموجود</span><?php endif; ?>
                                </div>
                            </a>
                            <div class="p-body">
                                <div class="p-name"><a href="/shop/product.php?slug=<?= $p['slug'] ?>"><?= htmlspecialchars($p['name_fa']) ?></a></div>
                                <div class="p-price-row"><span class="p-price"><?= Price::faNumber($pPrice) ?> <small>تومان</small></span></div>
                                <div class="p-actions">
                                    <?php if (!$pOut): ?>
                                        <a href="/shop/product.php?slug=<?= $p['slug'] ?>" class="btn btn-gold btn-sm" style="flex:1;">مشاهده و خرید</a>
                                    <?php else: ?>
                                        <a href="/#contact" class="btn btn-ghost btn-sm" style="flex:1;">استعلام موجودی</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div style="text-align:center; margin-top: 50px;">
                    <a href="/shop/" class="btn btn-gold" style="padding: 14px 40px; font-size: 1.05rem;">مشاهده همه محصولات ←</a>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="vh-100" id="contact">
            <div class="section-content">
                <span class="section-tag">تماس با ما</span>
                <h2 class="section-title">آماده اتصال کسب‌وکار شما به جهان هستیم</h2>
                <div class="contact-container">
                    <div class="contact-card">
                        <h3><?= e($siteName) ?></h3>
                        <div class="contact-items">
                            <div class="contact-item">
                                <span class="contact-icon"><img src="assets/icons/icons8-place-marker-100.png" alt="آدرس"></span>
                                <span><?= e($siteAddress) ?></span>
                            </div>
                            <div class="contact-item">
                                <span class="contact-icon"><img src="assets/icons/icons8-outgoing-call-100.png" alt="تماس"></span>
                                <span><?= e($sitePhone) ?></span>
                            </div>
                            <div class="contact-item">
                                <span class="contact-icon"><img src="assets/icons/icons8-email-sign-100.png" alt="ایمیل"></span>
                                <span><?= e($siteEmail) ?></span>
                            </div>
                        </div>
                    </div>

                    <form class="contact-form" id="contactForm" novalidate>
                        <div class="hp-field" aria-hidden="true">
                            <label for="website">وبسایت</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="form-row">
                            <div class="form-field">
                                <label for="name">نام و نام خانوادگی <span class="required">*</span></label>
                                <input type="text" id="name" name="name" required minlength="2" maxlength="100" placeholder="مثلاً: علی محمدی" autocomplete="name">
                                <span class="field-error" data-for="name"></span>
                            </div>
                            <div class="form-field">
                                <label for="phone">تلفن</label>
                                <input type="tel" id="phone" name="phone" maxlength="30" placeholder="مثلاً: 09123456789" autocomplete="tel">
                                <span class="field-error" data-for="phone"></span>
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="email">ایمیل <span class="required">*</span></label>
                            <input type="email" id="email" name="email" required maxlength="200" placeholder="example@domain.com" autocomplete="email" dir="ltr">
                            <span class="field-error" data-for="email"></span>
                        </div>

                        <div class="form-field">
                            <label for="subject">موضوع</label>
                            <input type="text" id="subject" name="subject" maxlength="200" placeholder="مثلاً: استعلام قیمت حمل از چین">
                            <span class="field-error" data-for="subject"></span>
                        </div>

                        <div class="form-field">
                            <label for="message">پیام <span class="required">*</span></label>
                            <textarea id="message" name="message" required minlength="10" maxlength="5000" placeholder="جزئیات محموله، مبدأ، مقصد و زمان تقریبی را بنویسید..."></textarea>
                            <span class="field-error" data-for="message"></span>
                        </div>

                        <button type="submit" class="submit-btn" id="submitBtn">
                            <span class="spinner" aria-hidden="true"></span>
                            <span class="btn-text">ارسال پیام</span>
                        </button>
                        <div class="form-status" id="formStatus" role="status" aria-live="polite"></div>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="footer-content">
            <div class="footer-brand">
                <img src="logo.webp" alt="لوگوی <?= e($siteName) ?>" class="footer-logo">
                <span><?= e($siteName) ?></span>
            </div>
            <nav class="footer-links">
                <a href="#hero">خانه</a>
                <a href="#about">درباره ما</a>
                <a href="#services">خدمات</a>
                <a href="/blog/">بلاگ</a>
                <a href="/shop/">فروشگاه</a>
                <a href="/track/">ردیابی محموله</a>
                <a href="#contact">تماس</a>
            </nav>
            <div class="footer-copyright">
                <p>&copy; <?= date('Y') ?> <?= e($siteName) ?>. تمامی حقوق محفوظ است.</p>
            </div>
        </div>
    </footer>

    <script type="module" src="js/main.js"></script>
    <script type="module" src="js/contact-form.js"></script>
    <script>
    // Analytics tracking
    async function trackConversion(type, value) {
        try {
            await fetch('/api/track.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({type, value}),
            });
        } catch (e) {}
    }
    // Track phone/email clicks
    document.querySelectorAll('a[href^="tel:"]').forEach(a => a.addEventListener('click', () => trackConversion('phone_click', a.href)));
    document.querySelectorAll('a[href^="mailto:"]').forEach(a => a.addEventListener('click', () => trackConversion('email_click', a.href)));
    </script>
</body>
</html>
