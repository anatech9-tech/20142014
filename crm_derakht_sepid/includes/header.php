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
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang_dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? t('app_name'), ENT_QUOTES, 'UTF-8') ?></title>
    <!-- ═══ §13: PWA ═══ -->
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#16233f">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(t('app_name'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css?v=20260905">
    <link rel="stylesheet" href="assets/css/glass.css">
    <link rel="stylesheet" href="assets/css/arash_coin.css">
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
                'rest_track'     => ['rest_track', 'coffee', 'استراحت', true],
                'salary'         => ['salary', 'wallet', t('nav_salary'), true],
                'arash_coins'    => ['arash_coins', 'circle-dollar-sign', t('nav_arash_coins'), true],
                'advances'       => ['advances', 'banknote', t('nav_advances'), true],
                'leaves'         => ['leaves', 'calendar-off', t('nav_leaves'), true],
                'work_orders'    => ['work_orders', 'clipboard-list', t('nav_work_orders'), true],
                'reports'        => ['reports', 'bar-chart-3', t('nav_reports'), $is_nazer || access_group_mode('reports') !== null || has_permission('view_reports') || has_permission('office_manager')],
                'workshops'      => ['workshops', 'building-2', t('nav_workshops'), ($is_nazer || access_mode('workshops') !== null || has_permission('manage_workshops')) && !is_workshop_manager()],
                'subcontractors' => ['subcontractors', 'briefcase', t('nav_subcontractors'), $is_nazer || access_mode('subcontractors') !== null || has_permission('manage_subcontractors') || has_permission('office_manager')],
                'petty_cash'     => ['petty_cash', 'coins', t('nav_petty_cash'), true],
                'bonus_penalty'  => ['bonus_penalty', 'award', t('nav_bonus_penalty'), $is_nazer || access_mode('bonus_penalty') !== null || has_permission('manage_bonus_penalty') || has_permission('office_manager')],
                'contracts'      => ['contracts', 'file-text', t('nav_contracts'), $is_nazer || access_mode('contracts') !== null || has_permission('manage_contracts')],
                'audit'          => ['audit', 'history', t('nav_audit'), $is_nazer || access_mode('audit') !== null || has_permission('audit_log')],
                'settings'       => ['settings', 'settings', t('nav_settings'), $is_nazer || access_mode('settings') !== null || $is_admin],
                'inventory'      => ['inventory', 'package', t('nav_inventory'), $is_nazer || access_mode('inventory') !== null || has_permission('manage_inventory')],
                'profile'        => ['profile', 'user', t('nav_profile'), true],
                'chat'           => ['chat', 'message-circle', t('nav_chat'), true],
                'daily_reports'  => ['daily_reports', 'file-text', t('nav_daily_reports'), true],
                'archive'        => ['archive', 'folder', t('nav_archive'), $is_nazer || access_mode('archive') !== null || $is_admin],
                'forms'          => ['forms', 'file-text', t('nav_forms'), $is_nazer || $is_yaser || $is_javidhse || access_group_mode('forms') !== null || $is_admin],
                // §phase2 CRM: درخت سپید
                'crm_customers'  => ['crm_customers', 'building-2', t('nav_crm_customers'), true],
                'crm_leads'      => ['crm_leads', 'magnet', t('nav_crm_leads'), true],
                'crm_deals'      => ['crm_deals', 'trending-up', t('nav_crm_deals'), true],
                'crm_tasks'      => ['crm_tasks', 'list-todo', t('nav_crm_tasks'), true],
                'crm_teams'      => ['crm_teams', 'users-round', t('nav_crm_teams'), $is_admin],
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

            foreach ($ordered_keys as $nav_key):
                [$np, $nic, $nlabel, $nvisible] = $nav_map[$nav_key];
                if (!$nvisible) continue;
            ?>
            <a href="index.php?page=<?= $np ?>" class="nav-item <?= $currentPage == $np ? 'active' : '' ?>"><i data-lucide="<?= $nic ?>"></i><span><?= $nlabel ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <a href="index.php?page=logout" class="nav-item logout"><i data-lucide="log-out"></i><span><?= t('nav_logout') ?></span></a>
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
                <?php
                // WTTP COIN جای آیکون چت در بالای سایت: موجودی همیشه از دفترکل خوانده می‌شود.
                $header_coin_balance = arash_coin_balance($pdo, (int)($_SESSION['user_id'] ?? 0));
                ?>
                <a href="index.php?page=arash_coins" class="arash-coin-balance-link" title="<?= htmlspecialchars(t('مشاهده کیف پول و فروشگاه WTTP COIN'), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="assets/icons/wttp-coin-small.png" class="arash-coin-inline" alt="WTTP COIN">
                    <span data-arash-coin-balance><?= number_format($header_coin_balance) ?></span>
                    <span style="font-size:10px">AC</span>
                </a>
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
                .topbar-right>.icon-btn:hover,.topbar-right>.topbar-control:hover,.topbar-right>.arash-coin-balance-link:hover,.topbar-right>.ws-switcher-btn:hover,#srvClock:hover{transform:translateY(-1px) scale(1.04);box-shadow:0 6px 18px rgba(30,58,95,.35)}
                .topbar-right>div:not(#srvCalendar){height:38px;display:inline-flex;align-items:center}
                .topbar-right>div:not(#srvCalendar)>#langBtn{border:1px solid var(--border);border-radius:10px;box-sizing:border-box;transition:transform .2s ease,box-shadow .2s ease,background .2s ease}
                .topbar-right>div:not(#srvCalendar)>#langBtn:hover{transform:translateY(-1px) scale(1.04);box-shadow:0 6px 18px rgba(30,58,95,.35)}
                .lang-flag-svg{display:inline-flex;width:22px;height:15px;line-height:0;flex:0 0 auto}.lang-flag-svg svg{display:block;width:100%;height:100%;border-radius:2px;box-shadow:0 0 0 1px rgba(15,23,42,.18)}
                #langDropdown .lang-flag-svg{width:24px;height:16px}
                .topbar-right>.tour-header-btn{width:38px!important;height:38px!important;min-width:38px!important;border-radius:10px!important}
                .topbar-right>.arash-coin-balance-link{height:38px;min-height:38px;box-sizing:border-box;padding:0 10px;border:1px solid var(--border);border-radius:10px;white-space:nowrap;transition:transform .2s ease,box-shadow .2s ease}
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
                @media(max-width:640px){#srvClock{min-width:auto;width:38px;padding:2px 4px}#srvClockDate{display:none}.topbar-right{gap:4px}.topbar-right>.icon-btn,.topbar-right>.topbar-control,.topbar-right>.tour-header-btn,.topbar-right>div:not(#srvCalendar)>#langBtn{width:34px!important;height:34px!important;min-width:34px!important}.topbar-right>.arash-coin-balance-link{height:34px;min-height:34px;padding:0 6px}.topbar-right>.ws-switcher-btn{height:34px;min-height:34px;padding:4px 7px;font-size:10px}#srvCalendar{position:fixed;top:58px;inset-inline:10px;width:auto}}
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
                <!-- راهنمای صفحه (تور) — دکمه کنار انتخاب زبان -->
                <button id="tourStartBtn" class="icon-btn tour-header-btn" title="<?= ['fa'=>'راهنمای صفحه (؟)','ar'=>'دليل الصفحة (؟)','en'=>'Page guide (?)'][$lang] ?? 'Page guide' ?>" onclick="startPageTour()">؟</button>
                <!-- Language Switcher -->
                <div style="position:relative">
                    <button id="langBtn" onclick="document.getElementById('langDropdown').classList.toggle('hidden')" class="icon-btn" title="<?= htmlspecialchars(t('ui_language'), ENT_QUOTES, 'UTF-8') ?>"><?= lang_flag_svg($lang) ?></button>
                    <div id="langDropdown" class="hidden" style="position:absolute;top:100%;<?= $lang_dir === 'rtl' ? 'right' : 'left' ?>:0;background:var(--surface);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);margin-top:5px;overflow:hidden;z-index:200;min-width:140px">
                        <a href="?lang=fa" style="display:flex;align-items:center;gap:8px;padding:10px 14px;text-decoration:none;color:var(--text);font-size:13px;transition:background 0.2s <?= $lang === 'fa' ? 'background:rgba(99,102,241,0.1)' : '' ?>" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='<?= $lang === 'fa' ? 'rgba(99,102,241,0.1)' : 'transparent' ?>'"><?= lang_flag_svg('fa') ?> فارسی <?= $lang === 'fa' ? '✓' : '' ?></a>
                        <a href="?lang=ar" style="display:flex;align-items:center;gap:8px;padding:10px 14px;text-decoration:none;color:var(--text);font-size:13px;transition:background 0.2s <?= $lang === 'ar' ? 'background:rgba(99,102,241,0.1)' : '' ?>" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='<?= $lang === 'ar' ? 'rgba(99,102,241,0.1)' : 'transparent' ?>'"><?= lang_flag_svg('ar') ?> العربية <?= $lang === 'ar' ? '✓' : '' ?></a>
                        <a href="?lang=en" style="display:flex;align-items:center;gap:8px;padding:10px 14px;text-decoration:none;color:var(--text);font-size:13px;transition:background 0.2s <?= $lang === 'en' ? 'background:rgba(99,102,241,0.1)' : '' ?>" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background='<?= $lang === 'en' ? 'rgba(99,102,241,0.1)' : 'transparent' ?>'"><?= lang_flag_svg('en') ?> English <?= $lang === 'en' ? '✓' : '' ?></a>
                    </div>
                </div>
                <button id="themeBtn" onclick="var t = document.documentElement; if (t.classList.contains('dark')) { t.classList.remove('dark'); localStorage.setItem('theme', 'light'); this.querySelector('i').setAttribute('data-lucide', 'moon'); } else { t.classList.add('dark'); localStorage.setItem('theme', 'dark'); this.querySelector('i').setAttribute('data-lucide', 'sun'); } lucide.createIcons();" class="icon-btn" title="<?= htmlspecialchars(t('ui_theme'), ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="moon"></i></button>
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
