<?php
// Permission helpers
$is_admin = is_strict_admin() || is_project_manager();
$is_nazer = is_nazer_kol();
$is_karfarma = is_karfarma();
$is_yaser = (($_SESSION['username'] ?? '') === 'yaser');
$is_javidhse = (($_SESSION['username'] ?? '') === 'javidhse');
$perms = $_SESSION['permissions'] ?? [];
if (!function_exists('p')) {
    function p($perm) { global $perms, $is_admin; return $is_admin || in_array($perm, $perms) || in_array('workshop_manager', $perms); }
}
$currentPage = $_GET['page'] ?? 'dashboard';

// برچسب نقش و حروف اول نام برای بلوک حساب در پایین سایدبار
$__role_label = 'کاربر';
if ($is_admin) {
    $__role_label = 'مدیر کل';
} elseif (function_exists('is_project_manager') && is_project_manager()) {
    $__role_label = 'مدیر پروژه';
} elseif (function_exists('is_workshop_manager') && is_workshop_manager()) {
    $__role_label = 'مدیر واحد';
} elseif ($is_nazer) {
    $__role_label = 'ناظر کل';
} elseif ($is_karfarma) {
    $__role_label = 'کارفرما';
}
$__full_name = trim((string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'کاربر')));
$__initials = '';
foreach (preg_split('/\s+/', $__full_name) as $__w) {
    if ($__w === '') continue;
    $__initials .= mb_substr($__w, 0, 1, 'UTF-8');
    if (mb_strlen($__initials, 'UTF-8') >= 2) break;
}
$__initials = $__initials !== '' ? $__initials : '؟';
$__avatar = '';
try {
    $avatarStmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ?');
    $avatarStmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $avatarCandidate = (string)$avatarStmt->fetchColumn();
    if (strpos($avatarCandidate, 'uploads/avatars/') === 0 && basename($avatarCandidate) === substr($avatarCandidate, strlen('uploads/avatars/')) && is_file(__DIR__ . '/../' . $avatarCandidate)) $__avatar = $avatarCandidate;
} catch (Throwable $e) {}


// ═══ §v11: تشخیص «حالت ناظر» روی صفحه جاری (برای بنر + غیرفعال‌شدن دکمه‌های ثبت) ═══
$__is_viewer_page = false;
if (!access_is_special_account()) {
    $__vk = access_gate_page_map()[$currentPage] ?? null;
    if ($currentPage === 'reports') {
        $__vk = access_report_type_keys()[$_GET['type'] ?? 'salary'] ?? null;
    } elseif ($__vk === null && in_array($currentPage, access_personal_keys(), true)) {
        $__vk = $currentPage; // صفحات پایه: فقط وقتی تیک «ناظر» خورده باشد
    }
    if ($__vk && access_mode($__vk) === 'viewer') $__is_viewer_page = true;
}
// §v20: پالت رنگیِ ذخیره‌شده روی پروفایل کاربر (هر بار ورود اعمال می‌شود)
$__user_palette = '';
try {
    ensure_palette_schema($pdo);
    if (!empty($_SESSION['user_id'])) {
        $stp = $pdo->prepare("SELECT palette FROM users WHERE id = ?");
        $stp->execute([(int)$_SESSION['user_id']]);
        $tmp = (string)($stp->fetchColumn() ?: '');
        if (in_array($tmp, ['teal','ocean','orchid','ruby','amber','emerald'], true)) $__user_palette = $tmp;
    }
} catch (Throwable $e) {}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = generate_token(); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? t('app_name'), ENT_QUOTES, 'UTF-8') ?></title>
    <!-- ═══ §13: PWA ═══ -->
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#308e89">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(t('app_name'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css?v=20261106c">
    <link rel="stylesheet" href="assets/css/glass.css">
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
<script>
/* بارگذار راهنمای صفحه (تور) — خودش فایل guide_tour.js را می‌آورد */
(function () {
    if (window.__otLoader) return;
    window.__otLoader = 1;
    var tries = 0;
    function removeFloatingTourButtons() {
        document.querySelectorAll('#tourStartBtn').forEach(function (btn) {
            if (!btn.closest('.topbar-right')) btn.remove();
        });
        document.querySelectorAll('.tour-fab, .tour-floating, [data-tour-floating]').forEach(function (btn) {
            btn.remove();
        });
    }
    removeFloatingTourButtons();
    new MutationObserver(removeFloatingTourButtons).observe(document.documentElement, { childList: true, subtree: true });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', removeFloatingTourButtons);
    }
    function inject() {
        if (document.getElementById('guideTourScript')) return;
        var s = document.createElement('script');
        s.id = 'guideTourScript';
        s.src = 'assets/js/guide_tour.js?v=20260902';
        document.head.appendChild(s);
    }
    window.startPageTour = function () {
        if (window.startPageTourReal) { window.startPageTourReal(); return; }
        inject();
        tries = 0;
        var iv = setInterval(function () {
            if (window.startPageTourReal) { clearInterval(iv); window.startPageTourReal(); return; }
            if (++tries > 60) { clearInterval(iv); alert('فایل راهنمای صفحه (assets/js/guide_tour.js) روی سرور پیدا نشد؛ لطفاً آن را آپلود کنید.'); }
        }, 100);
    };
    inject(); /* پیش‌بارگذاری */
})();
</script>
    <script>
    (function() {
        var theme = localStorage.getItem('theme') || 'light';
        if (theme === 'dark') document.documentElement.classList.add('dark');
        var palettes = ['teal', 'ocean', 'orchid', 'ruby', 'amber', 'emerald'];
        try {
            var pl = localStorage.getItem('palette');
            document.documentElement.setAttribute('data-palette', palettes.indexOf(pl) >= 0 ? pl : 'teal');
        } catch (e) { document.documentElement.setAttribute('data-palette', 'teal'); }
    })();
    </script>
</head>
<body class="<?= $__is_viewer_page ? 'viewer-mode' : '' ?>">
<?php if ($__is_viewer_page): ?>
<style>
/* §v11: حالت ناظر — دکمه‌های ثبت فرم‌ها غیرفعال به نظر می‌رسند (سیاست اصلی سمت سرور است) */
.viewer-mode form button[type="submit"], .viewer-mode form input[type="submit"] { opacity:.45; pointer-events:none; filter:grayscale(.4); }
</style>
<?php endif; ?>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <?php if (!empty($_SESSION['workshop_logo'])): ?>
                <img src="<?= $_SESSION['workshop_logo'] ?>" alt="logo" style="width:35px;height:35px;border-radius:8px;object-fit:cover">
                <?php else: ?>
                <img src="assets/images/logo-96.png" alt="درخت سپید تجارت پارس" style="width:38px;height:38px;border-radius:9px;object-fit:cover">
                <?php endif; ?>
                <span class="workshop-name"><?= htmlspecialchars($_SESSION['workshop_name'] ?? t('app_name')) ?></span>
            </div>
            <button class="toggle-btn" type="button" id="mobileCloseBtn" title="<?= htmlspecialchars(t('ui_toggle_menu'), ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="chevrons-right"></i></button>
        </div>
        <nav class="sidebar-nav">
            <!-- منوی عادی — ترتیب آیتم‌ها از تنظیمات قابل تغییره -->
            <?php
            $nav_map = [
                'dashboard'      => ['dashboard', 'layout-dashboard', t('nav_dashboard'), true],
                'work_hours'     => ['work_hours', 'clock', t('nav_work_hours'), true],
                'personnel'      => ['personnel', 'users', t('nav_personnel'), $is_nazer || access_mode('personnel') !== null || has_permission('manage_personnel') || has_permission('office_manager')],
                'salary'         => ['salary', 'wallet', t('nav_salary'), true],
                'advances'       => ['advances', 'banknote', t('nav_advances'), true],
                'leaves'         => ['leaves', 'calendar-off', t('nav_leaves'), true],
                'reports'        => ['reports', 'bar-chart-3', t('nav_reports'), $is_nazer || access_group_mode('reports') !== null || has_permission('view_reports') || has_permission('office_manager')],
                'workshops'      => ['workshops', 'building-2', t('nav_workshops'), ($is_nazer || access_mode('workshops') !== null || has_permission('manage_workshops')) && !is_workshop_manager()],
                'petty_cash'     => ['petty_cash', 'coins', t('nav_petty_cash'), true],
                'contracts'      => ['contracts', 'file-text', t('nav_contracts'), $is_nazer || access_mode('contracts') !== null || has_permission('manage_contracts')],
                'audit'          => ['audit', 'history', t('nav_audit'), $is_nazer || access_mode('audit') !== null || has_permission('audit_log')],
                'settings'       => ['settings', 'settings', t('nav_settings'), $is_nazer || access_mode('settings') !== null || $is_admin],
                'inventory'      => ['inventory', 'package', t('nav_inventory'), $is_nazer || access_mode('inventory') !== null || has_permission('manage_inventory')],
                'profile'        => ['profile', 'user', t('nav_profile'), true],
                'chat'           => ['chat', 'message-circle', t('nav_chat'), true],
                'archive'        => ['archive', 'folder', t('nav_archive'), $is_nazer || access_mode('archive') !== null || $is_admin],
                'forms'          => ['forms', 'file-text', t('nav_forms'), $is_nazer || $is_yaser || $is_javidhse || access_group_mode('forms') !== null || $is_admin],
                'notifications'  => ['notifications', 'bell', t('nav_notifications'), true],
                // §phase2 CRM: درخت سپید
                'crm_customers'  => ['crm_customers', 'building-2', t('nav_crm_customers'), true],
                'crm_products' => ['crm_products', 'package-search', 'بانک کالا', true],
                'crm_cold_numbers' => ['crm_cold_numbers', 'phone-outgoing', 'شماره‌های سرد', true],
                'crm_leads'      => ['crm_leads', 'magnet', t('nav_crm_leads'), true],
                'site_inquiries' => ['site_inquiries', 'inbox', 'درخواست‌های سایت', true],
                'shop_orders' => ['shop_orders', 'shopping-bag', 'سفارش‌های فروشگاه', true],
                'site_blog_metadata' => ['site_blog_metadata', 'book-open', 'مقاله‌های سایت', $is_admin],
                'site_content_drafts' => ['site_content_drafts', 'file-text', 'پیش‌نویس محتوای سایت', $is_admin],
                'shop_category_groups' => ['shop_category_groups', 'folder', 'گروه‌های داخلی دسته‌ها', $is_admin],
                'shop_category_metadata' => ['shop_category_metadata', 'layers', 'نمای دسته‌های فروشگاه', $is_admin],
                'shop_coupon_metadata' => ['shop_coupon_metadata', 'ticket', 'نمای کوپن‌های فروشگاه', $is_admin],
                'site_shipment_metadata' => ['site_shipment_metadata', 'package', 'محموله‌های مستقل سایت', $is_admin],
                'crm_deals'      => ['crm_deals', 'trending-up', t('nav_crm_deals'), true],
                'quotes'         => ['quotes', 'receipt-text', t('nav_quotes'), true],
                'crm_tasks'      => ['crm_tasks', 'list-todo', t('nav_crm_tasks'), true],
                'crm_teams'      => ['crm_teams', 'users-round', t('nav_crm_teams'), $is_admin],
                'calls'             => ['calls', 'phone', t('nav_calls'), $is_admin],
            ];

            // شمارندهٔ پیام‌های خوانده‌نشده + ۵ اعلان اخیر برای زنگ سربرگ (بی‌خطا؛ اگر جدول/نشست نباشد خالی می‌ماند)
            $__unread_notifs = 0;
            $__recent_notifs = [];
            try {
                if (!empty($_SESSION['user_id']) && isset($pdo)) {
                    $uns = $pdo->prepare("SELECT COUNT(*) FROM notification_recipients WHERE user_id = ? AND is_read = 0");
                    $uns->execute([(int)$_SESSION['user_id']]);
                    $__unread_notifs = (int)$uns->fetchColumn();
                    $rst = $pdo->prepare("SELECT n.title, n.created_at, nr.is_read FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id WHERE nr.user_id = ? ORDER BY n.created_at DESC LIMIT 5");
                    $rst->execute([(int)$_SESSION['user_id']]);
                    $__recent_notifs = $rst->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) {}
            $nav_badges = ['notifications' => $__unread_notifs];

            // ═══ منوی گروهی بازشونده (الگوی مرجع کارکت) ═══
            $nav_groups = [
                'finance' => ['label' => t('nav_grp_finance'), 'icon' => 'wallet',         'items' => ['work_hours', 'salary', 'advances', 'petty_cash', 'reports']],
                'hr'      => ['label' => t('nav_grp_hr'),      'icon' => 'users',          'items' => ['personnel', 'leaves', 'contracts']],
                'ops'     => ['label' => t('nav_grp_ops'),     'icon' => 'package',        'items' => ['forms', 'inventory', 'workshops']],
                'crm'     => ['label' => t('nav_grp_crm'),     'icon' => 'trending-up',    'items' => ['crm_customers', 'crm_products', 'crm_cold_numbers', 'crm_leads', 'site_inquiries', 'shop_orders', 'shop_category_metadata', 'shop_category_groups', 'shop_coupon_metadata', 'site_shipment_metadata', 'site_content_drafts', 'site_blog_metadata', 'crm_deals', 'quotes', 'crm_tasks', 'crm_teams', 'calls']],
                'comms'   => ['label' => t('nav_grp_comms'),   'icon' => 'message-circle', 'items' => ['chat', 'notifications', 'archive']],
                'mgmt'    => ['label' => t('nav_grp_mgmt'),    'icon' => 'settings',       'items' => ['audit', 'settings', 'profile']],
            ];

            // ترتیب دلخواه از تنظیمات (menu_order) + آیتم‌های جدید ته لیست
            $menu_order_raw = '';
            try {
                $stmo = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'menu_order'");
                $stmo->execute();
                $menu_order_raw = (string)$stmo->fetchColumn();
            } catch (Exception $e) {}
            $ordered_keys = [];
            foreach (array_filter(array_map('trim', explode(',', $menu_order_raw))) as $k) {
                if (isset($nav_map[$k]) && !in_array($k, $ordered_keys)) $ordered_keys[] = $k;
            }
            foreach (array_keys($nav_map) as $k) {
                if (!in_array($k, $ordered_keys)) $ordered_keys[] = $k;
            }

            // §11: منوی javidhse و کارفرما فقط فرم‌ها + پروفایل
            if ($is_javidhse || $is_karfarma) {
                $ordered_keys = ['forms', 'profile'];
                $nav_map['forms'][3] = true;
                $nav_map['profile'][3] = true;
            }

            // §phase2: صفحات غیرفعال‌شده برای غیرادمین‌ها (تنظیمات: disabled_pages — فهرستی از کلیدهای nav_map با ویرگول)
            if (!$is_admin) {
                try {
                    $stdp = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'disabled_pages'");
                    $stdp->execute();
                    $disabled_raw = (string)$stdp->fetchColumn();
                } catch (Exception $e) {
                    $disabled_raw = '';
                }
                $disabled_keys = array_filter(array_map('trim', explode(',', $disabled_raw)));
                if ($disabled_keys) {
                    $ordered_keys = array_values(array_filter($ordered_keys, function ($k) use ($disabled_keys) {
                        return !in_array($k, $disabled_keys, true) || $k === 'dashboard';
                    }));
                }
            }

            // حساب‌های ویژه (javidhse / کارفرما): همهٔ گروه‌های فعال باز شوند چون منوی بسیار کوتاهی دارند
            $force_open_all = $is_javidhse || $is_karfarma;
            // فهرست بخش‌های قابل جستجو (فقط صفحات مجاز همین کاربر) برای Ctrl+Q
            $__search_items = ($is_javidhse || $is_karfarma) ? [] : [['key' => 'dashboard', 'label' => t('nav_dashboard')]];
            foreach ($ordered_keys as $k) {
                if ($nav_map[$k][3]) $__search_items[] = ['key' => $nav_map[$k][0], 'label' => $nav_map[$k][2]];
            }
            $__render_link = function ($nav_key) use ($nav_map, $currentPage, $nav_badges) {
                [$np, $nic, $nlabel] = $nav_map[$nav_key];
                $active = $currentPage == $np ? 'active' : '';
                $badge = '';
                if (!empty($nav_badges[$nav_key]) && $nav_badges[$nav_key] > 0) {
                    $badge = '<span class="nav-count">' . min(99, (int)$nav_badges[$nav_key]) . '</span>';
                }
                echo '<a href="index.php?page=' . $np . '" class="nav-item ' . $active . '"><i data-lucide="' . $nic . '"></i><span>' . $nlabel . '</span>' . $badge . '</a>';
            };
            ?>
            <?php if (!$is_javidhse && !$is_karfarma): ?>
            <a href="index.php?page=dashboard" class="nav-item nav-dashboard <?= $currentPage == 'dashboard' ? 'active' : '' ?>"><i data-lucide="layout-dashboard"></i><span><?= t('nav_dashboard') ?></span></a>
            <?php endif; ?>
            <?php
            $assigned_keys = [];
            foreach ($nav_groups as $gkey => $grp):
                $visible_items = [];
                foreach ($ordered_keys as $k) {
                    if (in_array($k, $grp['items'], true) && $nav_map[$k][3]) $visible_items[] = $k;
                }
                if (!$visible_items) continue;
                $assigned_keys = array_merge($assigned_keys, $visible_items);
                $contains_current = in_array($currentPage, $visible_items, true);
                $open = $contains_current || $force_open_all;
            ?>
            <div class="nav-group <?= $open ? 'open' : '' ?>" data-group="<?= $gkey ?>">
                <button type="button" class="nav-group-title" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                    <i data-lucide="<?= $grp['icon'] ?>"></i>
                    <span><?= htmlspecialchars($grp['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <i data-lucide="chevron-down" class="nav-chev"></i>
                </button>
                <div class="nav-children" role="group">
                    <?php foreach ($visible_items as $nav_key) $__render_link($nav_key); ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php
            // کلیدهایی که در هیچ گروهی تعریف نشده‌اند → گروه «سایر» (برای آینده‌نگری)
            $leftover = [];
            foreach ($ordered_keys as $k) {
                if (!in_array($k, $assigned_keys, true) && $k !== 'dashboard' && $nav_map[$k][3]) $leftover[] = $k;
            }
            if ($leftover):
                $contains_current = in_array($currentPage, $leftover, true);
                $open = $contains_current || $force_open_all;
            ?>
            <div class="nav-group <?= $open ? 'open' : '' ?>" data-group="other">
                <button type="button" class="nav-group-title" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                    <i data-lucide="more-horizontal"></i>
                    <span><?= htmlspecialchars(t('nav_grp_other'), ENT_QUOTES, 'UTF-8') ?></span>
                    <i data-lucide="chevron-down" class="nav-chev"></i>
                </button>
                <div class="nav-children" role="group">
                    <?php foreach ($leftover as $nav_key) $__render_link($nav_key); ?>
                </div>
            </div>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer account-footer">
            <a href="index.php?page=profile" class="account-user" title="پروفایل من">
                <span class="account-avatar"><?php if ($__avatar !== ''): ?><img src="<?= htmlspecialchars($__avatar, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%"><?php else: ?><?= htmlspecialchars($__initials, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                <span class="account-meta"><strong><?= htmlspecialchars($__full_name, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($__role_label, ENT_QUOTES, 'UTF-8') ?></small></span>
            </a>
            <a href="index.php?page=logout" class="icon-btn account-logout" title="<?= t('nav_logout') ?>" aria-label="<?= t('nav_logout') ?>"><i data-lucide="log-out"></i></a>
        </div>
    </div>
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileMenu()"></div>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button onclick="openMobileMenu()" class="icon-btn mobile-menu-btn" id="mobileMenuBtn" style="display:none"><i data-lucide="menu"></i></button>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? t('nav_dashboard'), ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="topbar-right">
                <div class="topbar-search" id="topbarSearch">
                    <i data-lucide="search" class="topbar-search-icon"></i>
                    <input id="topbarSearchInput" type="text" placeholder="جستجوی بخش‌ها…" autocomplete="off" spellcheck="false" aria-label="جستجوی بخش‌ها">
                    <kbd>Ctrl Q</kbd>
                    <div id="topbarSearchResults" class="topbar-search-results" role="listbox" hidden></div>
                </div>
                <?php
                // ساعت سرور، نه ساعت دستگاه. اختلاف سه دقیقه‌ای یا بیشتر با ساعت دستگاه هشدار می‌دهد.
                $__fa_days = localized_weekday_names();
                $__j_now = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));
                $__server_jdate = $__fa_days[(int)date('w')] . ' ' . $__j_now[2] . ' ' . jalali_month_name($__j_now[1]) . ' ' . $__j_now[0];
                ?>
                <?php
                $__holiday_map = [];
                try {
                    $hst = $pdo->prepare("SELECT holiday_date, title FROM holidays WHERE holiday_date BETWEEN ? AND ?");
                    $hst->execute([date('Y-m-d', strtotime('-400 days')), date('Y-m-d', strtotime('+400 days'))]);
                    foreach ($hst->fetchAll(PDO::FETCH_ASSOC) as $hr) $__holiday_map[$hr['holiday_date']] = (string)$hr['title'];
                } catch (Throwable $e) {}
                ?>
                <div id="srvClock" role="button" tabindex="0" aria-haspopup="dialog" aria-expanded="false" data-epoch="<?= (int)round(microtime(true) * 1000) ?>" data-tz="<?= htmlspecialchars(date_default_timezone_get()) ?>" data-holidays="<?= htmlspecialchars(json_encode($__holiday_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('ui_server_clock'), ENT_QUOTES, 'UTF-8') ?>" style="display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1.22;padding:3px 9px;border:1px solid var(--border);border-radius:10px;background:var(--surface);min-width:102px;user-select:none;cursor:pointer">
                    <span id="srvClockTime" style="font-weight:700;font-size:13px;color:var(--text);font-variant-numeric:tabular-nums;letter-spacing:.4px"><?= date('H:i:s') ?> <span id="srvClockWarn" style="display:none;cursor:help">⚠️</span></span>
                    <span id="srvClockDate" style="font-size:10px;color:var(--text-secondary);white-space:nowrap"><?= $__server_jdate ?></span>
                </div>
                <div id="srvCalendar" hidden role="dialog" aria-label="<?= htmlspecialchars(t('تقویم'), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="srv-cal-head"><button type="button" class="srv-cal-nav" id="srvCalPrev" aria-label="<?= htmlspecialchars(t('ماه قبل'), ENT_QUOTES, 'UTF-8') ?>">‹</button><strong id="srvCalTitle"></strong><button type="button" class="srv-cal-nav" id="srvCalNext" aria-label="<?= htmlspecialchars(t('ماه بعد'), ENT_QUOTES, 'UTF-8') ?>">›</button></div>
                    <div class="srv-cal-grid" id="srvCalGrid"></div>
                    <div class="srv-cal-detail" id="srvCalDetail" hidden></div>
                </div>
                <style>
                .topbar-right{position:relative;gap:8px;align-items:center;flex-wrap:nowrap}
                .topbar-right>.icon-btn,.topbar-right>.topbar-control{width:38px;height:38px;min-width:38px;padding:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:10px;box-sizing:border-box;transition:transform .2s ease,box-shadow .2s ease,background .2s ease}
                .topbar-right>.icon-btn:hover,.topbar-right>.topbar-control:hover,.topbar-right>.ws-switcher-btn:hover,#srvClock:hover{transform:translateY(-1px) scale(1.04);box-shadow:0 6px 18px rgba(30,58,95,.35)}
                .topbar-right>div:not(#srvCalendar){height:38px;display:inline-flex;align-items:center}
                .topbar-right>.tour-header-btn{width:38px!important;height:38px!important;min-width:38px!important;border-radius:10px!important}
                .topbar-right>.ws-switcher-btn{height:38px;min-height:38px;display:inline-flex;align-items:center;white-space:nowrap;border:1px solid var(--border);border-radius:10px;transition:transform .2s ease,box-shadow .2s ease}
                #srvClock{height:38px;min-width:112px;box-sizing:border-box}
                #srvCalendar{position:absolute;top:calc(100% + 8px);inset-inline-end:0;width:310px;padding:12px;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);z-index:500;cursor:default}
                #srvCalendar[hidden]{display:none}
                .srv-cal-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
                .srv-cal-head strong{font-size:13px}
                .srv-cal-nav{width:30px;height:30px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);cursor:pointer}
                .srv-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center;direction:rtl}
                .srv-cal-week{font-size:10px;color:var(--text-secondary);padding:3px 0}
                .srv-cal-day{min-height:31px;border:1px solid transparent;border-radius:7px;background:transparent;color:var(--text);font:inherit;font-size:11px;cursor:pointer;padding:3px}
                .srv-cal-day:hover,.srv-cal-day.is-selected{background:rgba(99,102,241,.12);border-color:var(--primary)}
                .srv-cal-day.is-holiday{color:#dc2626;font-weight:700;background:rgba(239,68,68,.08)}
                .srv-cal-detail{min-height:30px;margin-top:9px;padding:7px 9px;border-radius:8px;background:rgba(239,68,68,.08);color:#b91c1c;font-size:11px;line-height:1.6}
                @media(max-width:640px){#srvClock{min-width:auto;width:38px;padding:2px 4px}#srvClockDate{display:none}.topbar-right{gap:4px}.topbar-right>.icon-btn,.topbar-right>.topbar-control,.topbar-right>.tour-header-btn,.topbar-right>.ws-switcher-btn{height:34px;min-height:34px;padding:4px 7px;font-size:10px}#srvCalendar{position:fixed;top:58px;inset-inline:10px;width:auto}}
                </style>
                <script>
                (function(){
                    var box=document.getElementById('srvClock'); if(!box)return;
                    var server0=parseInt(box.getAttribute('data-epoch'),10), client0=Date.now(), tz=box.getAttribute('data-tz')||'Asia/Tehran';
                    var timeEl=document.getElementById('srvClockTime'), dateEl=document.getElementById('srvClockDate'), warnEl=document.getElementById('srvClockWarn');
                    var fmtTime,fmtDate;
                    try { fmtTime=new Intl.DateTimeFormat('fa-IR',{timeZone:tz,hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}); fmtDate=new Intl.DateTimeFormat('fa-IR',{timeZone:tz,weekday:'long',year:'numeric',month:'long',day:'numeric'}); } catch(e){}
                    function draw(){
                        var now=server0+(Date.now()-client0), d=new Date(now);
                        if(fmtTime&&fmtDate){ timeEl.firstChild.nodeValue=fmtTime.format(d)+' '; dateEl.textContent=fmtDate.format(d); }
                        var diff=Math.round(Math.abs(Date.now()-now)/60000);
                        warnEl.style.display=diff>=3?'inline':'none';
                        if(diff>=3) warnEl.title=<?= json_encode(t('ui_server_clock_mismatch'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>.replace('{minutes}', diff);
                    }
                    draw(); setInterval(draw,1000);
                })();
                </script>
                <script>
                (function(){
                    var box=document.getElementById('srvClock'), cal=document.getElementById('srvCalendar'); if(!box||!cal)return;
                    var holidays=JSON.parse(box.getAttribute('data-holidays')||'{}'), grid=document.getElementById('srvCalGrid'), title=document.getElementById('srvCalTitle'), detail=document.getElementById('srvCalDetail');
                    var faMonths=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'], faWeek=['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'], nf=new Intl.NumberFormat('fa-IR');
                    var fmt=new Intl.DateTimeFormat('en-US-u-ca-persian',{timeZone:'Asia/Tehran',year:'numeric',month:'numeric',day:'numeric'});
                    function parts(d){var o={};fmt.formatToParts(d).forEach(function(p){if(p.type==='year'||p.type==='month'||p.type==='day')o[p.type]=+p.value});return o}
                    var gfmt=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'});
                    var dates=[]; for(var i=-400;i<=400;i++){var d=new Date(Date.now()+i*86400000),p=parts(d);dates.push({d:d,g:gfmt.format(d),y:p.year,m:p.month,day:p.day});}
                    var now=parts(new Date()), cy=now.year, cm=now.month;
                    function render(){
                        title.textContent=(faMonths[cm-1]||cm)+' '+nf.format(cy); grid.innerHTML='';
                        faWeek.forEach(function(w){var x=document.createElement('div');x.className='srv-cal-week';x.textContent=w;grid.appendChild(x)});
                        var list=dates.filter(function(x){return x.y===cy&&x.m===cm}).sort(function(a,b){return a.d-b.d}); if(!list.length)return;
                        var first=(list[0].d.getDay()+1)%7; for(var z=0;z<first;z++){var blank=document.createElement('div');grid.appendChild(blank)}
                        list.forEach(function(x){var b=document.createElement('button');b.type='button';b.className='srv-cal-day';b.textContent=nf.format(x.day);if(holidays[x.g])b.classList.add('is-holiday');b.onclick=function(){grid.querySelectorAll('.is-selected').forEach(function(q){q.classList.remove('is-selected')});b.classList.add('is-selected');if(holidays[x.g]){detail.hidden=false;detail.textContent='تعطیل: '+holidays[x.g]}else{detail.hidden=true;detail.textContent=''}};grid.appendChild(b)});
                    }
                    function toggle(){var open=cal.hidden;cal.hidden=!open;box.setAttribute('aria-expanded',open?'true':'false');if(open)render()}
                    box.addEventListener('click',function(e){e.stopPropagation();toggle()});box.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();toggle()}});
                    document.getElementById('srvCalPrev').onclick=function(e){e.stopPropagation();cm--;if(cm<1){cm=12;cy--}render()};document.getElementById('srvCalNext').onclick=function(e){e.stopPropagation();cm++;if(cm>12){cm=1;cy++}render()};
                    document.addEventListener('click',function(e){if(!cal.hidden&&!cal.contains(e.target)&&e.target!==box){cal.hidden=true;box.setAttribute('aria-expanded','false')}});
                })();
                </script>
                <!-- زنگ اعلان‌ها + آخرین ۵ اعلان -->
                <div class="notif-wrap">
                    <button id="bellBtn" class="icon-btn" type="button" title="اعلان‌های اخیر" aria-haspopup="dialog" aria-expanded="false" aria-label="اعلان‌ها"><i data-lucide="bell"></i><?php if ($__unread_notifs > 0): ?><span class="bell-badge"><?= min(99, $__unread_notifs) ?></span><?php endif; ?></button>
                    <div id="bellPopover" class="topbar-popover hidden" role="dialog" aria-label="اعلان‌های اخیر">
                        <div class="popover-head"><strong>اعلان‌های اخیر</strong><a href="index.php?page=notifications">مشاهده همه ←</a></div>
                        <?php if (empty($__recent_notifs)): ?>
                        <div class="popover-empty">📭 اعلانی وجود ندارد.</div>
                        <?php else: foreach ($__recent_notifs as $rn): ?>
                        <a class="notif-item<?= empty($rn['is_read']) ? ' unread' : '' ?>" href="index.php?page=notifications">
                            <span class="notif-text"><?= htmlspecialchars(mb_substr((string)$rn['title'], 0, 90), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="notif-time"><?= htmlspecialchars(to_jalali(substr((string)($rn['created_at'] ?? ''), 0, 10)), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <!-- راهنمای صفحه (تور) -->
                <button id="tourStartBtn" class="icon-btn tour-header-btn" title="راهنمای صفحه (؟)" onclick="startPageTour()">؟</button>
                <button id="themeBtn" onclick="var t = document.documentElement; if (t.classList.contains('dark')) { t.classList.remove('dark'); localStorage.setItem('theme', 'light'); this.querySelector('i').setAttribute('data-lucide', 'moon'); } else { t.classList.add('dark'); localStorage.setItem('theme', 'dark'); this.querySelector('i').setAttribute('data-lucide', 'sun'); } lucide.createIcons();" class="icon-btn" title="<?= htmlspecialchars(t('ui_theme'), ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="moon"></i></button>
                <div class="topbar-palette" id="topbarPalette">
                    <button id="paletteBtn" class="icon-btn" type="button" title="پالت رنگی" aria-label="پالت رنگی" aria-haspopup="dialog" aria-expanded="false"><i data-lucide="palette"></i></button>
                    <div id="palettePop" class="palette-pop hidden" role="dialog" aria-label="پالت رنگی" data-palette-preview>
                        <div class="palette-pop-list">
<?php include __DIR__ . '/palette_swatches.php'; ?>
                        </div>
                    </div>
                </div>
                <?php if (can_see_all_workshops()): ?>
                <a href="index.php?page=workshops" class="btn btn-ghost btn-sm ws-switcher-btn"><?= t('nav_switch_workshop') ?></a>
                <?php endif; ?>
            </div>
        </header>
        <section class="content-area">

<?php if ($__is_viewer_page): ?>
<div class="alert animate-in" style="background:#eff6ff;border:1px solid #3b82f6;color:#1d4ed8;display:flex;align-items:center;gap:8px">
    <span style="font-size:18px">👁️</span>
    <span><b><?= htmlspecialchars(t('viewer_title'), ENT_QUOTES, 'UTF-8') ?></b> — <?= htmlspecialchars(t('viewer_message'), ENT_QUOTES, 'UTF-8') ?></span>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['toast_message'])): ?>
<div class="alert alert-<?= ($_SESSION['toast_type'] ?? 'success') === 'error' ? 'danger' : ($_SESSION['toast_type'] ?? 'success') ?> animate-in">
    <i data-lucide="check-circle"></i>
    <?= htmlspecialchars($_SESSION['toast_message']) ?>
</div>
<?php endif; ?>
<?php $_SESSION['toast_message'] = null; $_SESSION['toast_type'] = null; ?>

<script>
window.__NAV_SEARCH = <?= json_encode($__search_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.__PALETTE = <?= json_encode($__user_palette) ?>;
window.__CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
// ‏§v20: اعداد با ممیز هزارگان «،» سه‌رقم سه‌رقم‏
window.fmtNum = function (n, decimals) {
    n = Number(n || 0);
    if (isNaN(n)) return '0';
    if (decimals === undefined) decimals = 0;
    var negative = n < 0;
    var parts = Math.abs(n).toFixed(decimals).split('.');
    var int = parts[0], out = '';
    for (var i = 0; i < int.length; i++) {
        var remaining = int.length - i;
        out += int.charAt(i);
        if (remaining > 1 && remaining % 3 === 1) out += '،';
    }
    if (decimals > 0) out += '.' + (parts[1] || '');
    return (negative ? '-' : '') + out;
};
</script>

<script>
// Save sidebar scroll position
(function() {
    var nav = document.querySelector('.sidebar-nav');
    if (!nav) return;
    var key = 'sidebar_scroll_' + window.location.pathname;
    var saved = sessionStorage.getItem(key);
    if (saved) nav.scrollTop = parseInt(saved);
    window.addEventListener('beforeunload', function() {
        sessionStorage.setItem(key, nav.scrollTop);
    });
})();
</script>
<script>
// گروه‌های بازشوندهٔ منو — وضعیت هر گروه در localStorage ذخیره می‌شود؛
// گروهی که صفحهٔ فعلی را دارد همیشه باز می‌ماند.
(function () {
    function readStates() {
        try { return JSON.parse(localStorage.getItem('menuGroupState') || '{}'); } catch (e) { return {}; }
    }
    document.querySelectorAll('.nav-group').forEach(function (g) {
        var key = g.getAttribute('data-group');
        var states = readStates();
        if (!g.classList.contains('open') && Object.prototype.hasOwnProperty.call(states, key)) {
            g.classList.toggle('open', !!states[key]);
            var btn = g.querySelector('.nav-group-title');
            if (btn) btn.setAttribute('aria-expanded', g.classList.contains('open') ? 'true' : 'false');
        }
    });
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.nav-group-title') : null;
        if (!btn) return;
        var g = btn.closest('.nav-group');
        if (!g) return;
        g.classList.toggle('open');
        var isOpen = g.classList.contains('open');
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        try {
            var states = readStates();
            states[g.getAttribute('data-group')] = isOpen;
            localStorage.setItem('menuGroupState', JSON.stringify(states));
        } catch (err) {}
    });
})();
</script>
