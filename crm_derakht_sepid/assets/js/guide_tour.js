/* ═══════════════════════════════════════════════════════════════
   OmranP — راهنمای صفحه (تور) | Page Guide Tour
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
   - فارسی / عربی / انگلیسی، حالت تاریک، RTL / LTR
   - CSS خودکفا: اگر استایل تور در صفحه نباشد خودش تزریق می‌کند
   ═══════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    if (window.__otEngine) return; // جلوگیری از اجرای دوباره
    window.__otEngine = 1;

    var LANG = (document.documentElement.lang || 'fa').toLowerCase().slice(0, 2);

    function T(map) {
        if (!map) return '';
        if (typeof map === 'string') return map;
        return map[LANG] || map.fa || '';
    }

    var UI = {
        fa: { title: 'راهنمای صفحه', next: 'بعدی', prev: 'قبلی', close: 'بستن', done: 'پایان', of: 'از', skipMsg: 'برای این صفحه راهنمایی تعریف نشده است.', waitMsg: 'برای ادامه، این فرم را باز کن', notFound: 'فایل راهنمای صفحه (assets/js/guide_tour.js) روی سرور پیدا نشد.' },
        ar: { title: 'دليل الصفحة', next: 'التالي', prev: 'السابق', close: 'إغلاق', done: 'إنهاء', of: 'من', skipMsg: 'لا يوجد دليل لهذه الصفحة.', waitMsg: 'لفتح هذا النموذج للمتابعة', notFound: 'ملف الدليل غير موجود على الخادم.' },
        en: { title: 'Page guide', next: 'Next', prev: 'Previous', close: 'Close', done: 'Done', of: 'of', skipMsg: 'No guide defined for this page.', waitMsg: 'Open this form to continue', notFound: 'The guide file (assets/js/guide_tour.js) was not found on the server.' }
    };

    function uiT(key) { return T(UI[LANG] ? UI[LANG][key] : null); }

    /* ═══ CSS خودکفا ═══ */
    (function injectCss() {
        try {
            if (document.getElementById('ot-style')) return;
            var st = document.createElement('style');
            st.id = 'ot-style';
            st.textContent =
                '.ot-overlay{position:fixed;inset:0;background:rgba(10,14,30,.45);z-index:9990;cursor:pointer}' +
                '.ot-box{position:fixed;z-index:9991;border:2px solid #6366f1;border-radius:14px;background:rgba(99,102,241,.08);box-shadow:0 0 0 4px rgba(99,102,241,.28),0 8px 30px rgba(0,0,0,.35);transition:all .25s ease;pointer-events:none}' +
                '.ot-tip{position:fixed;z-index:9992;background:var(--surface,#fff);color:var(--text,#1e293b);border:1px solid var(--border,#e2e8f0);border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.25);padding:14px 16px;width:300px;max-width:calc(100vw - 24px)}' +
                '.ot-tip h4{margin:0 0 6px;font-size:14px;color:#6366f1;display:flex;align-items:center;gap:6px;font-weight:700}' +
                '.ot-tip h4 .ot-dot{width:8px;height:8px;border-radius:50%;background:#6366f1;display:inline-block}' +
                '.ot-tip p{margin:0;font-size:12.5px;line-height:2;color:var(--text-secondary,#475569)}' +
                '.ot-tip .ot-nav{display:flex;align-items:center;justify-content:space-between;margin-top:12px;gap:6px}' +
                '.ot-tip .ot-counter{font-size:11px;color:#94a3b8;white-space:nowrap}' +
                '.ot-tip button{border:0;border-radius:8px;padding:6px 12px;font-size:12px;cursor:pointer;font-family:inherit;transition:opacity .15s}' +
                '.ot-tip button:hover{opacity:.85}' +
                '.ot-tip .ot-btn-prev{background:transparent;border:1px solid var(--border,#cbd5e1)!important;color:var(--text,#334155)}' +
                '.ot-tip .ot-btn-next{background:#6366f1;color:#fff;font-weight:600}' +
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
            { sel: '.sidebar', title: { fa: 'منوی کناری', ar: 'القائمة الجانبية', en: 'Sidebar menu' }, text: { fa: 'از اینجا به همه بخش‌های سیستم دسترسی داری. روی موبایل با دکمه ☰ باز می‌شود و با ضربه بیرون از منو بسته می‌شود.', ar: 'من هنا تصل إلى جميع أقسام النظام. على الجوال يُفتح بزر ☰ ويُغلق بالنقر خارجه.', en: 'Access all sections from here. On mobile it opens with the ☰ button and closes by tapping outside.' } },
            { sel: '.page-title', title: { fa: 'عنوان صفحه', ar: 'عنوان الصفحة', en: 'Page title' }, text: { fa: 'نام صفحه فعلی و مسیر کاری‌ات را نشان می‌دهد.', ar: 'يعرض اسم الصفحة الحالية.', en: 'Shows the current page name.' } },
            { sel: '#srvClock', title: { fa: 'ساعت سرور', ar: 'ساعة الخادم', en: 'Server clock' }, text: { fa: 'ساعت و تاریخ سرور (تهران) است نه ساعت گوشی؛ برای ثبت گزارش و ساعت کاری به آن دقت کن.', ar: 'ساعة الخادم (طهران) وليست ساعة الجهاز؛ انتبه لها عند تسجيل التقارير.', en: 'Server time (Tehran), not your device clock — rely on it when logging reports.' } },
            { sel: '#langBtn', title: { fa: 'انتخاب زبان', ar: 'اختيار اللغة', en: 'Language switcher' }, text: { fa: 'زبان رابط کاربری را بین فارسی، عربی و انگلیسی تغییر بده.', ar: 'غيّر لغة الواجهة بين الفارسية والعربية والإنجليزية.', en: 'Switch the UI language between Farsi, Arabic and English.' } },
            { sel: '#tourStartBtn', title: { fa: 'راهنمای صفحه (؟)', ar: 'دليل الصفحة (؟)', en: 'Page guide (?)' }, text: { fa: 'با زدن همین دکمه، تور راهنما شروع می‌شود و بخش‌های مختلف صفحه را یکی‌یکی با مربع نشانت می‌دهد.', ar: 'اضغط هذا الزر لبدء جولة الشرح لهذه الصفحة.', en: 'Click this button to start the guide tour for this page.' } },
            { sel: '#themeBtn', title: { fa: 'حالت روشن / تاریک', ar: 'الوضع الفاتح / الداكن', en: 'Light / dark theme' }, text: { fa: 'تم سایت را بین روشن و تاریک جابه‌جا کن؛ انتخاب‌ات ذخیره می‌شود.', ar: 'بدّل مظهر الموقع بين الفاتح والداكن.', en: 'Toggle light/dark theme; your choice is saved.' } },
            { sel: '.content-area', title: { fa: 'محتوای صفحه', ar: 'محتوى الصفحة', en: 'Page content' }, text: { fa: 'محتوا و ابزارهای اصلی همین بخش است؛ بقیه راهنما روی بخش‌های همین ناحیه است.', ar: 'المحتوى الرئيسي للصفحة هنا.', en: 'The main content and tools live here.' } }
        ],

        login: [
            { sel: '.login-box', title: { fa: 'فرم ورود', ar: 'نموذج الدخول', en: 'Login form' }, text: { fa: 'نام کاربری و رمز عبورت را وارد کن و دکمه ورود را بزن.', ar: 'أدخل اسم المستخدم وكلمة المرور واضغط دخول.', en: 'Enter your username and password, then press login.' } },
            { sel: '.login-form input[type="text"]', title: { fa: 'نام کاربری', ar: 'اسم المستخدم', en: 'Username' }, text: { fa: 'نام کاربری‌ات که مدیر سیستم برایت ساخته است.', ar: 'اسم المستخدم الذي أنشأه مدير النظام.', en: 'The username your system admin created for you.' } },
            { sel: '.login-form input[type="password"]', title: { fa: 'رمز عبور', ar: 'كلمة المرور', en: 'Password' }, text: { fa: 'رمز عبور اختصاصی خودت. اگر فراموش کردی به مدیر سیستم پیام بده.', ar: 'كلمة المرور الخاصة بك.', en: 'Your personal password. If forgotten, ask the admin.' } },
            { sel: '.login-form .btn-primary', title: { fa: 'دکمه ورود', ar: 'زر الدخول', en: 'Login button' }, text: { fa: 'بعد از وارد کردن اطلاعات، با این دکمه وارد سیستم شو.', ar: 'اضغط للدخول إلى النظام.', en: 'Press to sign in.' } }
        ],

        dashboard: [
            { sel: '.stats-grid', title: { fa: 'آمار امروز', ar: 'إحصائيات اليوم', en: 'Today’s stats' }, text: { fa: 'خلاصه سریع از وضعیت امروز: نیروهای حاضر، گزارش‌ها و...', ar: 'ملخص سريع لحالة اليوم.', en: 'Quick summary of today’s status.' } },
            { sel: '.dash-panel-head', title: { fa: 'کارت‌های مدیریتی', ar: 'البطاقات الإدارية', en: 'Management cards' }, text: { fa: 'هر کارت یک بخش از سیستم را خلاصه می‌کند؛ با کلیک روی «مشاهده» به همان بخش می‌روی.', ar: 'كل بطاقة تلخص قسمًا من النظام.', en: 'Each card summarizes a section; click to open it.' } },
            { sel: '.dashboard-chat-card', title: { fa: 'آخرین پیام‌های چت', ar: 'آخر رسائل الدردشة', en: 'Latest chat messages' }, text: { fa: 'آخرین گفتگوهای همکاران را همین‌جا می‌بینی.', ar: 'أحدث محادثات الزملاء هنا.', en: 'See the latest team conversations here.' } },
            { sel: '.dashboard-channel-list', title: { fa: 'کانال‌ها', ar: 'القنوات', en: 'Channels' }, text: { fa: 'لیست کانال‌های گفتگو و اعضای هر کانال.', ar: 'قائمة قنوات الدردشة وأعضائها.', en: 'List of chat channels and their members.' } },
            { sel: '.dash-expand-panel', title: { fa: 'پنل بازشو', ar: 'لوحة قابلة للطي', en: 'Expandable panel' }, text: { fa: 'برخی کارت‌ها باز و بسته می‌شوند تا صفحه خلوت بماند.', ar: 'بعض البطاقات قابلة للطي.', en: 'Some cards can be expanded/collapsed.' } }
        ],

        work_hours: [
            { sel: 'input[name="date"]', title: { fa: 'انتخاب تاریخ', ar: 'اختيار التاريخ', en: 'Pick a date' }, text: { fa: 'تاریخ کاری را انتخاب کن؛ ثبت ساعت‌ها برای همان روز انجام می‌شود.', ar: 'اختر تاريخ العمل؛ يُسجل وقت العمل لذلك اليوم.', en: 'Choose the work date; hours are logged for that day.' } },
            { sel: '[onclick*="checkinSingleModal"]', title: { fa: 'ثبت ورود / خروج', ar: 'تسجيل الحضور/الانصراف', en: 'Check in / out' }, text: { fa: 'با این دکمه ورود یا خروج یک نفر ثبت می‌شود. بزن تا فرمش را نشانت بدهم.', ar: 'سجّل حضور أو انصراف شخص ما.', en: 'Log a person’s check-in or check-out.' }, waitFor: '#checkinSingleModal' },
            { sel: '#checkinSingleModal input[name="user_id"]', title: { fa: 'انتخاب نفر', ar: 'اختيار الشخص', en: 'Select person' }, text: { fa: 'نفر موردنظر را انتخاب کن و نوع ثبت (ورود/خروج) را تعیین کن.', ar: 'اختر الشخص ونوع التسجيل.', en: 'Pick the person and the log type.' } },
            { sel: '[onclick*="checkoutModal"]', title: { fa: 'خروج دستی', ar: 'انصراف يدوي', en: 'Manual checkout' }, text: { fa: 'اگر کسی خروجش ثبت نشده، با این دکمه ساعت خروجش را وارد کن.', ar: 'أدخل وقت الانصراف يدويًا.', en: 'Enter a missing check-out time manually.' }, waitFor: '#checkoutModal' },
            { sel: '.batch-day-row', title: { fa: 'ثبت چندروزه', ar: 'تسجيل متعدد الأيام', en: 'Multi-day entry' }, text: { fa: 'اگر چند روز را یکجا می‌خواهی ثبت کنی (مثلاً مأموریت)، از این بخش استفاده کن.', ar: 'استخدم هذا القسم لتسجيل عدة أيام دفعة واحدة.', en: 'Use this to log several days at once (e.g. missions).' } },
            { sel: '[onclick*="openBulkModal"]', title: { fa: 'ابزار گروهی', ar: 'أدوات جماعية', en: 'Bulk tools' }, text: { fa: 'ثبت خروج، ویرایش یا حذف گروهی چند ردیف از همین‌جا انجام می‌شود.', ar: 'تسجيل أو تعديل أو حذف جماعي.', en: 'Bulk check-out, edit or delete rows here.' }, waitFor: '#bulkModal' }
        ],

        forms: [
            { sel: '.forms-grid', title: { fa: 'فرم‌های روزانه', ar: 'النماذج اليومية', en: 'Daily forms' }, text: { fa: 'همه فرم‌های آماده چاپ سیستم (اجرا، HSE، انبار و...) اینجا هستند.', ar: 'جميع نماذج الطباعة هنا.', en: 'All printable system forms live here.' } },
            { sel: '.form-card:nth-of-type(1)', title: { fa: 'کارت فرم', ar: 'بطاقة النموذج', en: 'Form card' }, text: { fa: 'روی هر کارت بزنی، فرم مربوطه در برگه جدید باز می‌شود؛ رنگ آیکون نشان‌دهنده واحد مربوطه است.', ar: 'اضغط لفتح النموذج في تبويب جديد.', en: 'Click a card to open that form in a new tab.' } },
            { sel: '.icon-circle', title: { fa: 'آیکون فرم‌ها', ar: 'أيقونات النماذج', en: 'Form icons' }, text: { fa: 'هر رنگ یک واحد است: بنفش پرسنلی، نارنجی اجرا، سبز HSE و نارنجی تیره انبار.', ar: 'لون كل أيقونة يحدد القسم المعني.', en: 'Each icon’s color shows which unit the form belongs to.' } }
        ],

        reports: [
            { sel: '#filterForm', title: { fa: 'فیلتر گزارش', ar: 'فلتر التقرير', en: 'Report filters' }, text: { fa: 'بازه زمانی، نوع گزارش و کارگاه را انتخاب کن و گزارش بگیر.', ar: 'اختر الفترة ونوع التقرير والورشة.', en: 'Pick the period, report type and workshop.' } },
            { sel: 'input[name="type"]', title: { fa: 'نوع گزارش', ar: 'نوع التقرير', en: 'Report type' }, text: { fa: 'نوع گزارش را عوض کن: حقوق، ساعت کاری، پیمانکاران و...', ar: 'غيّر نوع التقرير.', en: 'Switch between salary, work hours, contractors…' } },
            { sel: '#reportTable', title: { fa: 'جدول گزارش', ar: 'جدول التقرير', en: 'Report table' }, text: { fa: 'نتایج گزارش همین‌جا نمایش داده می‌شود و می‌توانی خروجی Excel/چاپ بگیری.', ar: 'نتائج التقرير هنا مع إمكانية التصدير.', en: 'Results are shown here with Excel/print export.' } },
            { sel: '#salaryChart', title: { fa: 'نمودار', ar: 'الرسم البياني', en: 'Chart' }, text: { fa: 'نمای گرافیکی حقوق و دستمزد برای مقایسه سریع.', ar: 'عرض بياني للرواتب.', en: 'Visual salary overview for quick comparison.' } }
        ],

        chat: [
            { sel: '#chatSidebar', title: { fa: 'لیست گفتگوها', ar: 'قائمة المحادثات', en: 'Conversation list' }, text: { fa: 'گفتگوها و کانال‌ها اینجا هستند؛ روی هر کدام بزن تا باز شود.', ar: 'المحادثات والقنوات هنا.', en: 'Conversations and channels; click to open.' } },
            { sel: '#chatMessages', title: { fa: 'پیام‌ها', ar: 'الرسائل', en: 'Messages' }, text: { fa: 'گفتگوی بازشده همین‌جا نمایش داده می‌شود.', ar: 'المحادثة المفتوحة تظهر هنا.', en: 'The opened conversation appears here.' } },
            { sel: '#chatInput', title: { fa: 'نوشتن پیام', ar: 'كتابة رسالة', en: 'Message input' }, text: { fa: 'پیامت را بنویس و با دکمه ارسال بفرست.', ar: 'اكتب رسالتك وأرسلها.', en: 'Type your message and hit send.' } }
        ],

        inventory: [
            { sel: '[onclick*="addItemModal"]', title: { fa: 'افزودن آیتم انبار', ar: 'إضافة صنف للمستودع', en: 'Add inventory item' }, text: { fa: 'آیتم جدید انبار را از این دکمه اضافه کن. بزن تا فرمش را نشانت بدهم.', ar: 'أضف صنفًا جديدًا من هنا.', en: 'Add a new inventory item here.' }, waitFor: '#addItemModal' },
            { sel: '#addItemModal #addItemName', title: { fa: 'نام آیتم', ar: 'اسم الصنف', en: 'Item name' }, text: { fa: 'نام آیتم و واحد شمارش را وارد کن؛ موجودی اولیه اختیاری است.', ar: 'أدخل اسم الصنف ووحدة القياس.', en: 'Enter the item name and unit; initial stock is optional.' } },
            { sel: '[onclick*="checkoutModal"]', title: { fa: 'خروج کالا', ar: 'صرف المواد', en: 'Check out items' }, text: { fa: 'تحویل کالا به پرسنل/پیمانکار از اینجا ثبت می‌شود.', ar: 'تسجيل صرف المواد للأفراد.', en: 'Log items issued to people here.' }, waitFor: '#checkoutModal' },
            { sel: '#checkoutModal #checkoutPersonId', title: { fa: 'دریافت‌کننده', ar: 'المستلم', en: 'Recipient' }, text: { fa: 'نفر دریافت‌کننده و تعداد هر آیتم را مشخص کن.', ar: 'حدد المستلم وكمية كل صنف.', en: 'Select the recipient and quantities.' } }
        ],

        advances: [
            { sel: '[onclick*="requestModal"]', title: { fa: 'درخواست مساعده / حقوق', ar: 'طلب سلفة / راتب', en: 'Request advance / salary' }, text: { fa: 'درخواست مساعده یا علی‌الحساب حقوق از همین دکمه ثبت می‌شود.', ar: 'سجل طلب سلفة من هنا.', en: 'Request an advance or salary payment here.' }, waitFor: '#requestModal' },
            { sel: '#requestModal input[name="amount"]', title: { fa: 'مبلغ و دلیل', ar: 'المبلغ والسبب', en: 'Amount & reason' }, text: { fa: 'مبلغ موردنظر و دلیل درخواست را وارد کن.', ar: 'أدخل المبلغ والسبب.', en: 'Enter the amount and the reason.' } },
            { sel: '[onclick*="showPayModal"]', title: { fa: 'پرداخت مساعده', ar: 'دفع السلفة', en: 'Pay advance' }, text: { fa: 'پرداخت هر ردیف با دکمه پرداخت همان ردیف انجام می‌شود.', ar: 'ادفع سلفة كل صف.', en: 'Pay each row via its pay button.' }, waitFor: '#payModal' },
            { sel: '[onclick*="openManualPayment"]', title: { fa: 'پرداخت دستی', ar: 'دفع يدوي', en: 'Manual payment' }, text: { fa: 'ثبت دستی واریز برای کسی که قبلاً نقد گرفته است.', ar: 'تسجيل دفع يدوي.', en: 'Record a manual cash payment.' }, waitFor: '#manualPaymentModal' },
            { sel: '[onclick*="openModal(\'debtModal\'"]', title: { fa: 'بدهی‌ها', ar: 'الديون', en: 'Debts' }, text: { fa: 'لیست بدهی پرسنل به صندوق و تسویه آن‌ها.', ar: 'قائمة ديون الأفراد.', en: 'Personnel debts to the fund.' }, waitFor: '#debtModal' }
        ],

        leaves: [
            { sel: '[onclick*="requestModal"]', title: { fa: 'درخواست مرخصی', ar: 'طلب إجازة', en: 'Request leave' }, text: { fa: 'درخواست مرخصی (روزانه/ساعتی) از همین دکمه ثبت می‌شود.', ar: 'سجل طلب إجازة من هنا.', en: 'Request leave (daily or hourly) here.' }, waitFor: '#requestModal' },
            { sel: '#requestModal #request_start_date', title: { fa: 'بازه مرخصی', ar: 'فترة الإجازة', en: 'Leave period' }, text: { fa: 'تاریخ شروع و پایان و نوع مرخصی را انتخاب کن.', ar: 'اختر تاريخ البداية والنهاية ونوع الإجازة.', en: 'Pick start/end dates and the leave type.' } },
            { sel: '[onclick*="directModal"]', title: { fa: 'ثبت مستقیم مرخصی', ar: 'تسجيل إجازة مباشر', en: 'Direct leave entry' }, text: { fa: 'مدیران می‌توانند بدون درخواست قبلی، مستقیم برای کسی مرخصی ثبت کنند.', ar: 'يمكن للمدراء تسجيل إجازة مباشرة.', en: 'Admins can add leave directly.' }, waitFor: '#directModal' }
        ],

        salary: [
            { sel: 'input[name="payment_method"]', title: { fa: 'روش پرداخت', ar: 'طريقة الدفع', en: 'Payment method' }, text: { fa: 'روش پرداخت (کارت، چک، نقد، حواله) را برای محاسبه انتخاب کن.', ar: 'اختر طريقة الدفع.', en: 'Choose how payments are made.' } },
            { sel: '[onclick*="openSettleModal"]', title: { fa: 'تسویه حقوق', ar: 'تسوية الراتب', en: 'Settle salary' }, text: { fa: 'تسویه و پرداخت حقوق هر نفر با دکمه تسویه همان ردیف انجام می‌شود.', ar: 'سوِّ راتب كل صف.', en: 'Settle each row via its button.' }, waitFor: '#settleModal' },
            { sel: '#settleModal #settleAmountBox', title: { fa: 'مبلغ تسویه', ar: 'مبلغ التسوية', en: 'Settle amount' }, text: { fa: 'مبلغ قابل پرداخت را بررسی کن و پرداخت را ثبت کن.', ar: 'تحقق من المبلغ وسجل الدفع.', en: 'Review the amount and confirm the payment.' } }
        ],

        personnel: [
            { sel: '[onclick*="openAddModal"]', title: { fa: 'افزودن پرسنل', ar: 'إضافة موظف', en: 'Add personnel' }, text: { fa: 'کارگر، کارمند یا پیمانکار جدید را از این دکمه اضافه کن.', ar: 'أضف عاملًا أو موظفًا أو مقاولًا.', en: 'Add a new worker, employee or contractor.' }, waitFor: '#addModal' },
            { sel: '#addModal #add_username', title: { fa: 'حساب کاربری', ar: 'حساب المستخدم', en: 'User account' }, text: { fa: 'نام کاربری و رمز عبور برای ورود نفر جدید ساخته می‌شود.', ar: 'أنشئ اسم المستخدم وكلمة المرور.', en: 'Create the new user’s username and password.' } },
            { sel: '#editModal #edit_full_name', title: { fa: 'ویرایش مشخصات', ar: 'تعديل البيانات', en: 'Edit details' }, text: { fa: 'با دکمه ویرایش هر ردیف، مشخصات، حقوق و بیمه همان نفر عوض می‌شود.', ar: 'عدّل بيانات الشخص.', en: 'Edit a person’s details, salary and insurance.' } }
        ],

        daily_reports: [
            { sel: '.card-header', title: { fa: 'گزارش‌های روز', ar: 'تقارير اليوم', en: 'Daily reports' }, text: { fa: 'گزارش‌های امروز هر نفر با وضعیت تأیید نمایش داده می‌شود.', ar: 'تقارير اليوم لكل شخص.', en: 'Today’s reports per person.' } },
            { sel: '[onclick*="reportEditModal"]', title: { fa: 'ویرایش گزارش', ar: 'تعديل التقرير', en: 'Edit report' }, text: { fa: 'متن گزارش، پیشرفت کار و کار فردا را از اینجا ویرایش کن.', ar: 'عدّل نص التقرير.', en: 'Edit the report text and progress.' }, waitFor: '#reportEditModal' },
            { sel: '#reportEditModal #edit_report_text', title: { fa: 'متن گزارش', ar: 'نص التقرير', en: 'Report text' }, text: { fa: 'شرح کار انجام‌شده و درصد پیشرفت را بنویس.', ar: 'اكتب وصف العمل المنجز.', en: 'Describe the work done and progress.' } }
        ],

        settings: [
            { sel: '#menuOrderForm', title: { fa: 'ترتیب منو', ar: 'ترتيب القائمة', en: 'Menu order' }, text: { fa: 'ترتیب آیتم‌های منوی کناری را با جابه‌جا کردن تعیین کن.', ar: 'رتب عناصر القائمة.', en: 'Reorder the sidebar menu items.' } },
            { sel: '#menuOrderList', title: { fa: 'لیست منو', ar: 'قائمة العناصر', en: 'Menu list' }, text: { fa: 'با کشیدن هر آیتم، جای آن را عوض کن و ذخیره کن.', ar: 'اسحب العناصر وأعد ترتيبها.', en: 'Drag items to reorder, then save.' } }
        ],

        work_orders: [
            { sel: '.card', title: { fa: 'دستور کارها', ar: 'أوامر العمل', en: 'Work orders' }, text: { fa: 'لیست دستور کارها و وضعیت هر کدام (جدید/در حال انجام/پایان‌یافته) همین‌جاست.', ar: 'قائمة أوامر العمل.', en: 'Work orders and their status.' } },
            { sel: '[onclick*="taskSendModal"]', title: { fa: 'ارسال دستور کار', ar: 'إرسال أمر عمل', en: 'Send work order' }, text: { fa: 'دستور کار جدید برای یک نفر یا گروه ارسال می‌شود.', ar: 'أرسل أمر عمل لشخص أو مجموعة.', en: 'Send a new work order to a person or team.' }, waitFor: '#taskSendModal' }
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
            if (card) add(card, { fa: 'بخش: ' + ct.textContent.trim(), en: 'Section: ' + ct.textContent.trim() }, { fa: 'این کارت شامل اطلاعات و ابزارهای مربوط به «' + ct.textContent.trim() + '» است.', en: 'This card contains the info and tools for this section.' });
        });

        var tables = document.querySelectorAll('table');
        if (tables.length) add(tables[0], { fa: 'جدول اطلاعات', ar: 'جدول المعلومات', en: 'Data table' }, { fa: 'داده‌های این صفحه در جدول نمایش داده می‌شود؛ بالای جدول معمولاً جستجو و ابزارهای ویرایش هست.', ar: 'تُعرض بيانات الصفحة في الجدول.', en: 'Page data is displayed in this table.' });

        var forms = document.querySelectorAll('form');
        if (forms.length) add(forms[0], { fa: 'فرم این صفحه', ar: 'نموذج هذه الصفحة', en: 'Page form' }, { fa: 'ثبت و ویرایش اطلاعات از طریق همین فرم انجام می‌شود؛ فیلدهای ستاره‌دار اجباری‌اند.', ar: 'يتم التسجيل والتعديل عبر هذا النموذج.', en: 'Use this form to add or edit data; star fields are required.' });

        var search = document.querySelector('input[type="search"], .search-input, input[placeholder*="جستجو"], input[placeholder*="Search"], input[placeholder*="بحث"]');
        if (search) add(search, { fa: 'جستجو', ar: 'البحث', en: 'Search' }, { fa: 'با تایپ کردن، بین ردیف‌ها جستجو کن.', ar: 'ابحث بين الصفوف بالكتابة.', en: 'Type to search through the rows.' });

        var selects = document.querySelectorAll('select');
        if (selects.length) add(selects[0], { fa: 'انتخابگر', ar: 'قائمة اختيار', en: 'Selector' }, { fa: 'برای فیلتر کردن اطلاعات، گزینه موردنظر را انتخاب کن.', ar: 'اختر خيارًا لتصفية البيانات.', en: 'Pick an option to filter the data.' });

        var primary = document.querySelector('.btn-primary, .btn-success');
        if (primary) add(primary, { fa: 'دکمه اصلی', ar: 'الزر الرئيسي', en: 'Primary button' }, { fa: 'دکمه اصلی ثبت/ذخیره این صفحه.', ar: 'زر الحفظ/التسجيل الرئيسي.', en: 'The main save/submit button on this page.' });

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

        add(elFor('.doc-header'), { fa: 'سربرگ سند', en: 'Document header' }, { fa: 'کد مدرک، تاریخ و اطلاعات کارگاه در سربرگ سند است.', en: 'Document code, date and workshop info.' });
        add(elFor('.doc-title'), { fa: 'عنوان فرم', en: 'Form title' }, { fa: 'عنوان و نوع فرم همین‌جاست.', en: 'The form’s title.' });
        add(elFor('table'), { fa: 'جدول ثبت', en: 'Entry table' }, { fa: 'ردیف‌های جدول را پر کن؛ ستون‌های ستاره‌دار اجباری‌اند.', en: 'Fill the table rows; starred columns are required.' });
        add(elFor('form input[name="date"], input[type="date"]'), { fa: 'تاریخ', en: 'Date' }, { fa: 'تاریخ گزارش/فرم.', en: 'The form date.' });
        add(elFor('textarea, input[type="text"].inp, .inp'), { fa: 'شرح و توضیحات', en: 'Notes' }, { fa: 'شرح کار و توضیحات تکمیلی را اینجا بنویس.', en: 'Write additional notes here.' });
        add(elFor('button[type="submit"], input[type="submit"], .btn-save, .btn-primary'), { fa: 'دکمه ذخیره', en: 'Save button' }, { fa: 'با این دکمه فرم ذخیره می‌شود.', en: 'Save the form.' });
        add(elFor('.print-btn, button[onclick*="print"], .btn-print'), { fa: 'چاپ فرم', en: 'Print' }, { fa: 'برای چاپ فیزیکی فرم از این دکمه استفاده کن.', en: 'Print the form.' });

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
