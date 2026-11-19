/* ═══════════════════════════════════════════════════════════════
   راهنمای صفحه (تور) | Page Guide Tour
   ─────────────────────────────────────────────────────────────
   با دکمه «؟» کنار انتخاب زبان (یا دکمه شناور در صفحات مستقل)،
   برای هر صفحه یک تور راهنما اجرا می‌شود: دور هر بخش مربع
   (هایلایت) کشیده می‌شود و توضیح داده می‌شود آن بخش چیست.

   ویژگی‌ها:
   - روی همه صفحات: صفحات اصلی + مودال‌ها (وقتی باز می‌شوند) + صفحات چاپی/مستقل
   - استپ‌های «بازکننده فرم»: تور دکمه باز کردن فرم را نشان می‌دهد،
     کاربر فرم را باز می‌کند و تور خودش داخل فرم ادامه می‌دهد
   - جمع‌آوری داینامیک استپ‌ها (MutationObserver): مودالی که تازه باز
     شده خودکار به تور اضافه می‌شود
   - فقط فارسی، حالت تاریک، راست‌به‌چپ
   - CSS خودکفا: اگر استایل تور در صفحه نباشد خودش تزریق می‌کند
   ═══════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    if (window.__otEngine) return; // جلوگیری از اجرای دوباره
    window.__otEngine = 1;

    /* فقط فارسی */
    function T(map) {
        if (!map) return '';
        if (typeof map === 'string') return map;
        return map.fa || '';
    }

    var UI = { title: 'راهنمای صفحه', next: 'بعدی', prev: 'قبلی', close: 'بستن', done: 'پایان', of: 'از', skipMsg: 'برای این صفحه راهنمایی تعریف نشده است.', waitMsg: 'برای ادامه، این فرم را باز کن', notFound: 'فایل راهنمای صفحه (assets/js/guide_tour.js) روی سرور پیدا نشد.' };

    function uiT(key) { return UI[key] || ''; }

    /* ═══ CSS خودکفا ═══ */
    (function injectCss() {
        try {
            if (document.getElementById('ot-style')) return;
            var st = document.createElement('style');
            st.id = 'ot-style';
            st.textContent =
                '.ot-overlay{position:fixed;inset:0;background:rgba(10,14,30,.45);z-index:9990;cursor:pointer}' +
                '.ot-box{position:fixed;z-index:9991;border:2px solid #308e89;border-radius:14px;background:rgba(99,102,241,.08);box-shadow:0 0 0 4px rgba(99,102,241,.28),0 8px 30px rgba(0,0,0,.35);transition:all .25s ease;pointer-events:none}' +
                '.ot-tip{position:fixed;z-index:9992;background:var(--surface,#fff);color:var(--text,#242428);border:1px solid var(--border,#e3e3e9);border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.25);padding:14px 16px;width:300px;max-width:calc(100vw - 24px)}' +
                '.ot-tip h4{margin:0 0 6px;font-size:14px;color:#308e89;display:flex;align-items:center;gap:6px;font-weight:700}' +
                '.ot-tip h4 .ot-dot{width:8px;height:8px;border-radius:50%;background:#308e89;display:inline-block}' +
                '.ot-tip p{margin:0;font-size:12.5px;line-height:2;color:var(--text-secondary,#475569)}' +
                '.ot-tip .ot-nav{display:flex;align-items:center;justify-content:space-between;margin-top:12px;gap:6px}' +
                '.ot-tip .ot-counter{font-size:11px;color:#90909f;white-space:nowrap}' +
                '.ot-tip button{border:0;border-radius:8px;padding:6px 12px;font-size:12px;cursor:pointer;font-family:inherit;transition:opacity .15s}' +
                '.ot-tip button:hover{opacity:.85}' +
                '.ot-tip .ot-btn-prev{background:transparent;border:1px solid var(--border,#e3e3e9)!important;color:var(--text,#707080)}' +
                '.ot-tip .ot-btn-next{background:#308e89;color:#fff;font-weight:600}' +
                '.ot-tip .ot-btn-close{background:#fee2e2;color:#b91c1c}' +
                '.ot-fade{animation:otFade .2s ease}' +
                '@keyframes otFade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}' +
                '[dir="ltr"] .ot-tip{direction:ltr;text-align:left}' +
                '';
            (document.head || document.documentElement).appendChild(st);
        } catch (e) { /* بی‌خیال */ }
    })();

    /* ═══ استپ‌های اختصاصی صفحات ═══
       هر استپ: { sel: سلکتور، title، text، waitFor (اختیاری) }
       waitFor: تور این استپ را نشان می‌دهد تا کاربر آن را باز کند،
       بعد خودکار به استپ‌های داخل مودال می‌رود. */
    var STEPS = {
        common: [
            { sel: '.sidebar', title: 'منوی کناری', text: 'از اینجا به همه بخش‌های سیستم دسترسی داری. روی موبایل با دکمه ☰ باز می‌شود و با ضربه بیرون از منو بسته می‌شود.' },
            { sel: '.page-title', title: 'عنوان صفحه', text: 'نام صفحه فعلی و مسیر کاری‌ات را نشان می‌دهد.' },
            { sel: '#srvClock', title: 'ساعت سرور', text: 'ساعت و تاریخ سرور (تهران) است نه ساعت گوشی؛ برای ثبت گزارش و ساعت کاری به آن دقت کن.' },
            { sel: '#tourStartBtn', title: 'راهنمای صفحه (؟)', text: 'با زدن همین دکمه، تور راهنما شروع می‌شود و بخش‌های مختلف صفحه را یکی‌یکی با مربع نشانت می‌دهد.' },
            { sel: '#themeBtn', title: 'حالت روشن / تاریک', text: 'تم سایت را بین روشن و تاریک جابه‌جا کن؛ انتخاب‌ات ذخیره می‌شود.' },
            { sel: '.content-area', title: 'محتوای صفحه', text: 'محتوا و ابزارهای اصلی همین بخش است؛ بقیه راهنما روی بخش‌های همین ناحیه است.' }
        ],

        login: [
            { sel: '.login-box', title: 'فرم ورود', text: 'نام کاربری و رمز عبورت را وارد کن و دکمه ورود را بزن.' },
            { sel: '.login-form input[type="text"]', title: 'نام کاربری', text: 'نام کاربری‌ات که مدیر سیستم برایت ساخته است.' },
            { sel: '.login-form input[type="password"]', title: 'رمز عبور', text: 'رمز عبور اختصاصی خودت. اگر فراموش کردی به مدیر سیستم پیام بده.' },
            { sel: '.login-form .btn-primary', title: 'دکمه ورود', text: 'بعد از وارد کردن اطلاعات، با این دکمه وارد سیستم شو.' }
        ],

        dashboard: [
            { sel: '.stats-grid', title: 'آمار امروز', text: 'خلاصه سریع از وضعیت امروز: نیروهای حاضر، گزارش‌ها و...' },
            { sel: '.dash-panel-head', title: 'کارت‌های مدیریتی', text: 'هر کارت یک بخش از سیستم را خلاصه می‌کند؛ با کلیک روی «مشاهده» به همان بخش می‌روی.' },
            { sel: '.dashboard-chat-card', title: 'آخرین پیام‌های چت', text: 'آخرین گفتگوهای همکاران را همین‌جا می‌بینی.' },
            { sel: '.dashboard-channel-list', title: 'کانال‌ها', text: 'لیست کانال‌های گفتگو و اعضای هر کانال.' },
            { sel: '.dash-expand-panel', title: 'پنل بازشو', text: 'برخی کارت‌ها باز و بسته می‌شوند تا صفحه خلوت بماند.' }
        ],

        work_hours: [
            { sel: 'input[name="date"]', title: 'انتخاب تاریخ', text: 'تاریخ کاری را انتخاب کن؛ ثبت ساعت‌ها برای همان روز انجام می‌شود.' },
            { sel: '[onclick*="checkinSingleModal"]', title: 'ثبت ورود / خروج', text: 'با این دکمه ورود یا خروج یک نفر ثبت می‌شود. بزن تا فرمش را نشانت بدهم.', waitFor: '#checkinSingleModal' },
            { sel: '#checkinSingleModal input[name="user_id"]', title: 'انتخاب نفر', text: 'نفر موردنظر را انتخاب کن و نوع ثبت (ورود/خروج) را تعیین کن.' },
            { sel: '[onclick*="checkoutModal"]', title: 'خروج دستی', text: 'اگر کسی خروجش ثبت نشده، با این دکمه ساعت خروجش را وارد کن.', waitFor: '#checkoutModal' },
            { sel: '.batch-day-row', title: 'ثبت چندروزه', text: 'اگر چند روز را یکجا می‌خواهی ثبت کنی (مثلاً مأموریت)، از این بخش استفاده کن.' },
            { sel: '[onclick*="openBulkModal"]', title: 'ابزار گروهی', text: 'ثبت خروج، ویرایش یا حذف گروهی چند ردیف از همین‌جا انجام می‌شود.', waitFor: '#bulkModal' }
        ],

        forms: [
            { sel: '.forms-grid', title: 'فرم‌های روزانه', text: 'همه فرم‌های آماده چاپ سیستم (واحد عملیات، ایمنی، انبار و...) اینجا هستند.' },
            { sel: '.form-card:nth-of-type(1)', title: 'کارت فرم', text: 'روی هر کارت بزنی، فرم مربوطه در برگه جدید باز می‌شود؛ رنگ آیکون نشان‌دهنده واحد مربوطه است.' },
            { sel: '.icon-circle', title: 'آیکون فرم‌ها', text: 'هر رنگ یک واحد است: بنفش پرسنلی، نارنجی عملیات، سبز ایمنی و نارنجی تیره انبار.' }
        ],

        reports: [
            { sel: '#filterForm', title: 'فیلتر گزارش', text: 'بازه زمانی، نوع گزارش و واحد را انتخاب کن و گزارش بگیر.' },
            { sel: 'input[name="type"]', title: 'نوع گزارش', text: 'نوع گزارش را عوض کن: حقوق، ساعت کاری، پیمانکاران و...' },
            { sel: '#reportTable', title: 'جدول گزارش', text: 'نتایج گزارش همین‌جا نمایش داده می‌شود و می‌توانی خروجی Excel/چاپ بگیری.' },
            { sel: '#salaryChart', title: 'نمودار', text: 'نمای گرافیکی حقوق و دستمزد برای مقایسه سریع.' }
        ],

        chat: [
            { sel: '#chatSidebar', title: 'لیست گفتگوها', text: 'گفتگوها و کانال‌ها اینجا هستند؛ روی هر کدام بزن تا باز شود.' },
            { sel: '#chatMessages', title: 'پیام‌ها', text: 'گفتگوی بازشده همین‌جا نمایش داده می‌شود.' },
            { sel: '#chatInput', title: 'نوشتن پیام', text: 'پیامت را بنویس و با دکمه ارسال بفرست.' }
        ],

        inventory: [
            { sel: '[onclick*="addItemModal"]', title: 'افزودن آیتم انبار', text: 'آیتم جدید انبار را از این دکمه اضافه کن. بزن تا فرمش را نشانت بدهم.', waitFor: '#addItemModal' },
            { sel: '#addItemModal #addItemName', title: 'نام آیتم', text: 'نام آیتم و واحد شمارش را وارد کن؛ موجودی اولیه اختیاری است.' },
            { sel: '[onclick*="checkoutModal"]', title: 'خروج کالا', text: 'تحویل کالا به پرسنل/پیمانکار از اینجا ثبت می‌شود.', waitFor: '#checkoutModal' },
            { sel: '#checkoutModal #checkoutPersonId', title: 'دریافت‌کننده', text: 'نفر دریافت‌کننده و تعداد هر آیتم را مشخص کن.' }
        ],

        advances: [
            { sel: '[onclick*="requestModal"]', title: 'درخواست مساعده / حقوق', text: 'درخواست مساعده یا علی‌الحساب حقوق از همین دکمه ثبت می‌شود.', waitFor: '#requestModal' },
            { sel: '#requestModal input[name="amount"]', title: 'مبلغ و دلیل', text: 'مبلغ موردنظر و دلیل درخواست را وارد کن.' },
            { sel: '[onclick*="showPayModal"]', title: 'پرداخت مساعده', text: 'پرداخت هر ردیف با دکمه پرداخت همان ردیف انجام می‌شود.', waitFor: '#payModal' },
            { sel: '[onclick*="openManualPayment"]', title: 'پرداخت دستی', text: 'ثبت دستی واریز برای کسی که قبلاً نقد گرفته است.', waitFor: '#manualPaymentModal' },
            { sel: '[onclick*="openModal(\'debtModal\'"]', title: 'بدهی‌ها', text: 'لیست بدهی پرسنل به صندوق و تسویه آن‌ها.', waitFor: '#debtModal' }
        ],

        leaves: [
            { sel: '[onclick*="requestModal"]', title: 'درخواست مرخصی', text: 'درخواست مرخصی (روزانه/ساعتی) از همین دکمه ثبت می‌شود.', waitFor: '#requestModal' },
            { sel: '#requestModal #request_start_date', title: 'بازه مرخصی', text: 'تاریخ شروع و پایان و نوع مرخصی را انتخاب کن.' },
            { sel: '[onclick*="directModal"]', title: 'ثبت مستقیم مرخصی', text: 'مدیران می‌توانند بدون درخواست قبلی، مستقیم برای کسی مرخصی ثبت کنند.', waitFor: '#directModal' }
        ],

        salary: [
            { sel: 'input[name="payment_method"]', title: 'روش پرداخت', text: 'روش پرداخت (کارت، چک، نقد، حواله) را برای محاسبه انتخاب کن.' },
            { sel: '[onclick*="openSettleModal"]', title: 'تسویه حقوق', text: 'تسویه و پرداخت حقوق هر نفر با دکمه تسویه همان ردیف انجام می‌شود.', waitFor: '#settleModal' },
            { sel: '#settleModal #settleAmountBox', title: 'مبلغ تسویه', text: 'مبلغ قابل پرداخت را بررسی کن و پرداخت را ثبت کن.' }
        ],

        personnel: [
            { sel: '[onclick*="openAddModal"]', title: 'افزودن پرسنل', text: 'کارگر، کارمند یا پیمانکار جدید را از این دکمه اضافه کن.', waitFor: '#addModal' },
            { sel: '#addModal #add_username', title: 'حساب کاربری', text: 'نام کاربری و رمز عبور برای ورود نفر جدید ساخته می‌شود.' },
            { sel: '#editModal #edit_full_name', title: 'ویرایش مشخصات', text: 'با دکمه ویرایش هر ردیف، مشخصات، حقوق و بیمه همان نفر عوض می‌شود.' }
        ],

        daily_reports: [
            { sel: '.card-header', title: 'گزارش‌های روز', text: 'گزارش‌های امروز هر نفر با وضعیت تأیید نمایش داده می‌شود.' },
            { sel: '[onclick*="reportEditModal"]', title: 'ویرایش گزارش', text: 'متن گزارش، پیشرفت کار و کار فردا را از اینجا ویرایش کن.', waitFor: '#reportEditModal' },
            { sel: '#reportEditModal #edit_report_text', title: 'متن گزارش', text: 'شرح کار انجام‌شده و درصد پیشرفت را بنویس.' }
        ],

        settings: [
            { sel: '#menuOrderForm', title: 'ترتیب منو', text: 'ترتیب آیتم‌های منوی کناری را با جابه‌جا کردن تعیین کن.' },
            { sel: '#menuOrderList', title: 'لیست منو', text: 'با کشیدن هر آیتم، جای آن را عوض کن و ذخیره کن.' }
        ],

        work_orders: [
            { sel: '.card', title: 'دستور کارها', text: 'لیست دستور کارها و وضعیت هر کدام (جدید/در حال انجام/پایان‌یافته) همین‌جاست.' },
            { sel: '[onclick*="taskSendModal"]', title: 'ارسال دستور کار', text: 'دستور کار جدید برای یک نفر یا گروه ارسال می‌شود.', waitFor: '#taskSendModal' }
        ]
    };

    /* صفحات مستقل/چاپی که header.php ندارند — تور با دکمه شناور روی آن‌ها کار می‌کند */
    var DOC_PAGES = ['employee_payment_print', 'anbar_report', 'petty_cash_print', 'inventory_count_print', 'inventory_label_print', 'work_report', 'salary_detail'];

    /* ─── وضعیت تور ─── */
    var state = { active: false, steps: [], idx: 0, lastEl: null };
    var overlayEl = null, boxEl = null, tipEl = null;
    var rafPending = false, waitTimer = null, rcTimer = null, observer = null;

    function pageKey() {
        var m = (window.location.search || '').match(/[?&]page=([^&]+)/);
        return m ? decodeURIComponent(m[1]) : 'login';
    }

    function elFor(sel) {
        try { return document.querySelector(sel); } catch (e) { return null; }
    }

    function isRendered(el) {
        if (!el) return false;
        var r = el.getBoundingClientRect();
        if (!r || r.width <= 0 || r.height <= 0) return false;
        // عنصر داخل مودال بسته (display:none) هم ریجکت می‌شود
        if (el.offsetParent === null && r.height === 0) return false;
        return true;
    }

    /* ─── استپ‌های خودکار برای صفحات بدون استپ اختصاصی ─── */
    function autoSteps(usedEls) {
        var out = [];
        var used = usedEls || [];

        function add(el, title, text) {
            if (!el || used.indexOf(el) !== -1) return;
            used.push(el);
            out.push({ el: el, title: title, text: text });
        }

        var cardTitles = document.querySelectorAll('.card-header .card-title, .card-header h3, .card-header h2, .card-header h4');
        cardTitles.forEach(function (ct) {
            var card = ct.closest ? ct.closest('.card, .stat-card') : null;
            if (card) add(card, 'بخش: ' + ct.textContent.trim(), 'این کارت شامل اطلاعات و ابزارهای مربوط به «' + ct.textContent.trim() + '» است.');
        });

        var tables = document.querySelectorAll('table');
        if (tables.length) add(tables[0], 'جدول اطلاعات', 'داده‌های این صفحه در جدول نمایش داده می‌شود؛ بالای جدول معمولاً جستجو و ابزارهای ویرایش هست.');

        var forms = document.querySelectorAll('form');
        if (forms.length) add(forms[0], 'فرم این صفحه', 'ثبت و ویرایش اطلاعات از طریق همین فرم انجام می‌شود؛ فیلدهای ستاره‌دار اجباری‌اند.');

        var search = document.querySelector('input[type="search"], .search-input, input[placeholder*="جستجو"], input[placeholder*="Search"], input[placeholder*="بحث"]');
        if (search) add(search, 'جستجو', 'با تایپ کردن، بین ردیف‌ها جستجو کن.');

        var selects = document.querySelectorAll('select');
        if (selects.length) add(selects[0], 'انتخابگر', 'برای فیلتر کردن اطلاعات، گزینه موردنظر را انتخاب کن.');

        var primary = document.querySelector('.btn-primary, .btn-success');
        if (primary) add(primary, 'دکمه اصلی', 'دکمه اصلی ثبت/ذخیره این صفحه.');

        return out.slice(0, 8);
    }

    /* ─── استپ‌های صفحات مستقل (فرم‌های چاپی) ─── */
    function docSteps(usedEls) {
        var out = [];
        var used = usedEls || [];

        function add(el, title, text) {
            if (!el || used.indexOf(el) !== -1) return;
            used.push(el);
            out.push({ el: el, title: title, text: text });
        }

        add(elFor('.doc-header'), 'سربرگ سند', 'کد مدرک، تاریخ و اطلاعات واحد در سربرگ سند است.');
        add(elFor('.doc-title'), 'عنوان فرم', 'عنوان و نوع فرم همین‌جاست.');
        add(elFor('table'), 'جدول ثبت', 'ردیف‌های جدول را پر کن؛ ستون‌های ستاره‌دار اجباری‌اند.');
        add(elFor('form input[name="date"], input[type="date"]'), 'تاریخ', 'تاریخ گزارش/فرم.');
        add(elFor('textarea, input[type="text"].inp, .inp'), 'شرح و توضیحات', 'شرح کار و توضیحات تکمیلی را اینجا بنویس.');
        add(elFor('button[type="submit"], input[type="submit"], .btn-save, .btn-primary'), 'دکمه ذخیره', 'با این دکمه فرم ذخیره می‌شود.');
        add(elFor('.print-btn, button[onclick*="print"], .btn-print'), 'چاپ فرم', 'برای چاپ فیزیکی فرم از این دکمه استفاده کن.');

        return out;
    }

    function collectSteps() {
        var key = pageKey();
        var custom = (STEPS[key] || []).slice();
        var used = [];
        var steps = [];

        // ۱) استپ‌های اختصاصی صفحه (فقط عناصر دیده‌شونده؛ مودال بسته خودش رد می‌شود)
        custom.forEach(function (s) {
            var el = elFor(s.sel);
            if (el && isRendered(el)) {
                used.push(el);
                steps.push({ el: el, title: s.title, text: s.text, waitFor: s.waitFor });
            }
        });

        // ۲) استپ‌های خودکار/مستند
        if (DOC_PAGES.indexOf(key) !== -1) docSteps(used).forEach(function (s) { steps.push(s); });
        else autoSteps(used).forEach(function (s) { steps.push(s); });

        // ۳) استپ‌های مشترک (منو، نوار بالا و...)
        (STEPS.common || []).forEach(function (s) {
            var el = elFor(s.sel);
            if (el && isRendered(el) && used.indexOf(el) === -1) {
                used.push(el);
                steps.push({ el: el, title: s.title, text: s.text });
            }
        });

        return steps;
    }

    /* ─── ساخت DOM تور ─── */
    function buildDom() {
        if (overlayEl) return;

        overlayEl = document.createElement('div');
        overlayEl.className = 'ot-overlay';
        boxEl = document.createElement('div');
        boxEl.className = 'ot-box';
        tipEl = document.createElement('div');
        tipEl.className = 'ot-tip ot-fade';
        tipEl.innerHTML =
            '<h4><span class="ot-dot"></span><span class="ot-title"></span></h4>' +
            '<p class="ot-text"></p>' +
            '<div class="ot-nav">' +
            '  <button type="button" class="ot-btn-prev"></button>' +
            '  <span class="ot-counter"></span>' +
            '  <button type="button" class="ot-btn-next"></button>' +
            '  <button type="button" class="ot-btn-close"></button>' +
            '</div>';

        overlayEl.addEventListener('click', function () { next(); });
        tipEl.querySelector('.ot-btn-prev').addEventListener('click', function (e) { e.stopPropagation(); prev(); });
        tipEl.querySelector('.ot-btn-next').addEventListener('click', function (e) { e.stopPropagation(); next(); });
        tipEl.querySelector('.ot-btn-close').addEventListener('click', function (e) { e.stopPropagation(); close(); });

        document.body.appendChild(overlayEl);
        document.body.appendChild(boxEl);
        document.body.appendChild(tipEl);
    }

    function position(el) {
        var r = el.getBoundingClientRect();
        if (!r || r.width <= 0) return;
        boxEl.style.left = r.left + 'px';
        boxEl.style.top = r.top + 'px';
        boxEl.style.width = r.width + 'px';
        boxEl.style.height = r.height + 'px';

        var tw = tipEl.offsetWidth, th = tipEl.offsetHeight;
        var left = r.left + r.width / 2 - tw / 2;
        left = Math.max(8, Math.min(left, (window.innerWidth || 1024) - tw - 8));
        var top = r.bottom + 14;
        if (top + th > (window.innerHeight || 768) - 8) {
            top = r.top - th - 14;
            if (top < 8) top = Math.max(8, ((window.innerHeight || 768) - th) / 2);
        }
        tipEl.style.left = left + 'px';
        tipEl.style.top = top + 'px';
    }

    function render() {
        if (!state.active) return;
        var st = state.steps[state.idx];
        if (!st) { close(); return; }

        // عنصر فعلی دیگر دیده نمی‌شود (مثلاً مودالش بسته شده) → ردش کن
        if (!st.el || !isRendered(st.el)) {
            skipToNextRendered();
            return;
        }

        // فقط وقتی عنصر عوض شده اسکرول کن (تا با هر تغییر DOM نپرد)
        if (state.lastEl !== st.el) {
            state.lastEl = st.el;
            try { st.el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
        }

        var title = T(st.title), text = T(st.text);
        if (st.waitFor) {
            text = (text ? text + ' ' : '') + uiT('waitMsg');
        }
        tipEl.querySelector('.ot-title').textContent = title;
        tipEl.querySelector('.ot-text').textContent = text;
        tipEl.querySelector('.ot-btn-prev').textContent = uiT('prev');
        tipEl.querySelector('.ot-btn-next').textContent = state.idx === state.steps.length - 1 ? uiT('done') : uiT('next');
        tipEl.querySelector('.ot-btn-close').textContent = uiT('close');
        tipEl.querySelector('.ot-counter').textContent = (state.idx + 1) + ' ' + uiT('of') + ' ' + state.steps.length;
        tipEl.querySelector('.ot-btn-prev').style.visibility = state.idx === 0 ? 'hidden' : 'visible';

        var elNow = st.el;
        setTimeout(function () {
            if (state.active && state.steps[state.idx] && state.steps[state.idx].el === elNow) {
                position(elNow);
            }
        }, 380);

        // اگر استپ «بازکننده فرم» است، صبر کن تا فرم باز شود بعد برو جلو
        clearInterval(waitTimer);
        if (st.waitFor) {
            waitTimer = setInterval(function () {
                if (!state.active) { clearInterval(waitTimer); return; }
                var target = elFor(st.waitFor);
                if (target && isRendered(target)) {
                    clearInterval(waitTimer);
                    setTimeout(next, 450);
                }
            }, 400);
        }
    }

    function skipToNextRendered() {
        var guard = 0;
        while (state.active && state.idx < state.steps.length - 1 && (!state.steps[state.idx] || !state.steps[state.idx].el || !isRendered(state.steps[state.idx].el))) {
            state.idx++;
            if (++guard > 300) break;
        }
        if (state.idx >= state.steps.length) { close(); return; }
        if (state.steps[state.idx] && state.steps[state.idx].el && isRendered(state.steps[state.idx].el)) render();
        else close();
    }

    function next() {
        if (!state.active) return;
        if (state.idx < state.steps.length - 1) { state.idx++; render(); }
        else close();
    }
    function prev() {
        if (!state.active || state.idx === 0) return;
        state.idx--; render();
    }

    function onScrollResize() {
        if (!state.active || rafPending) return;
        rafPending = true;
        (window.requestAnimationFrame || function (fn) { setTimeout(fn, 16); })(function () {
            rafPending = false;
            if (state.active && state.steps[state.idx] && state.steps[state.idx].el) {
                position(state.steps[state.idx].el);
            }
        });
    }

    /* ─── جمع‌آوری داینامیک: مودال که باز شود، استپ‌هایش اضافه می‌شود ─── */
    function ensureObserver() {
        if (observer || typeof MutationObserver === 'undefined' || !document.body) return;
        observer = new MutationObserver(function () {
            if (state.active) scheduleRecollect();
        });
        observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
    }

    function scheduleRecollect() {
        clearTimeout(rcTimer);
        rcTimer = setTimeout(function () {
            if (!state.active) return;
            var prevEl = state.steps[state.idx] && state.steps[state.idx].el;
            var steps = collectSteps();
            if (!steps.length) { close(); return; }
            var changed = steps.length !== state.steps.length;
            state.steps = steps;
            var ni = 0;
            if (prevEl) {
                for (var i = 0; i < steps.length; i++) {
                    if (steps[i].el === prevEl) { ni = i; break; }
                }
            }
            state.idx = Math.min(ni, steps.length - 1);
            if (changed) render(); else if (!state.steps[state.idx] || !isRendered(state.steps[state.idx].el)) render();
        }, 250);
    }

    function start() {
        try {
            if (state.active) { close(); }
            var steps = collectSteps();
            if (!steps.length) { alert(uiT('skipMsg')); return; }
            buildDom();
            ensureObserver();
            state.active = true;
            state.steps = steps;
            state.idx = 0;
            state.lastEl = null;
            overlayEl.style.display = 'block';
            boxEl.style.display = 'block';
            tipEl.style.display = 'block';
            render();
        } catch (err) {
            try {
                alert('خطا در راهنمای صفحه: ' + (err && err.message ? err.message : err));
            } catch (e2) {}
        }
    }

    function close() {
        state.active = false;
        clearInterval(waitTimer);
        clearTimeout(rcTimer);
        if (overlayEl) overlayEl.style.display = 'none';
        if (boxEl) boxEl.style.display = 'none';
        if (tipEl) tipEl.style.display = 'none';
    }

    /* ─── کلیدهای صفحه‌کلید ─── */
    document.addEventListener('keydown', function (e) {
        if (!state.active) return;
        if (e.key === 'Escape') { close(); e.preventDefault(); }
        else if (e.key === 'ArrowRight') { next(); }
        else if (e.key === 'ArrowLeft') { prev(); }
    });

    window.addEventListener('scroll', onScrollResize, true);
    window.addEventListener('resize', onScrollResize);

    /* ─── API عمومی ─── */
    window.startPageTourReal = start;
    if (typeof window.startPageTour !== 'function') {
        window.startPageTour = start;
    }

    /* اگر کاربر قبل از بارگذاری این فایل دکمه را زده باشد، تور را شروع کن */
    if (window.__tourPending) {
        setTimeout(start, 400);
    }
})();
