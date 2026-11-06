/* ═══════════════════════════════════════════════════════════════
   Jalali Mini Datepicker (§4)
   سبک، بدون وابستگی، RTL — روی input.jalali-date به‌صورت خودکار فعال می‌شود.
   - ورود دستی هم مجاز است (پیست/تایپ)؛ با الگوی YYYY/MM/DD ولیدیت می‌شود
   - ناوبری ماه، دکمه «امروز»، بستن با کلیک بیرون یا Esc
   ═══════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    /* --- تبدیل تاریخ (الگوریتم استاندارد jdf، مطابق خروجی PHP پروژه) --- */
    function g2j(gy, gm, gd) {
        var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var gy2 = (gm > 2) ? (gy + 1) : gy;
        var days = 355666 + (365 * gy) + (Math.floor((gy2 + 3) / 4)) - (Math.floor((gy2 + 99) / 100)) + (Math.floor((gy2 + 399) / 400)) + gd + gdm[gm - 1];
        var jy = -1595 + (33 * Math.floor(days / 12053));
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var jm, jd;
        if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
        else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }
        return [jy, jm, jd];
    }
    function j2g(jy, jm, jd) {
        jy += 1595;
        var days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + (Math.floor(((jy % 33) + 3) / 4)) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        var gy = 400 * Math.floor(days / 146097);
        days %= 146097;
        if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
        gy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var gd = days + 1;
        var sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gm;
        for (gm = 0; gm < 13 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
        return [gy, gm, gd];
    }
    function jMonthDays(jy, jm) { return (jm <= 6) ? 31 : (jm <= 11 ? 30 : (jIsLeap(jy) ? 30 : 29)); }
    function jIsLeap(jy) { var r = jy % 33; return (r === 1 || r === 5 || r === 9 || r === 13 || r === 17 || r === 22 || r === 26 || r === 30); }

    var UI_LANG = (document.documentElement.lang || 'fa').toLowerCase().split('-')[0];
    var CALENDAR_I18N = {
        fa: {
            months: ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
            days: ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'],
            prev: 'ماه قبل', next: 'ماه بعد', today: 'امروز', close: 'بستن'
        },
        ar: {
            months: ['فروردين', 'أرديبهشت', 'خرداد', 'تير', 'مرداد', 'شهريور', 'مهر', 'آبان', 'آذر', 'دي', 'بهمن', 'إسفند'],
            days: ['سبت', 'أحد', 'اثن', 'ثلث', 'أرب', 'خمي', 'جمع'],
            prev: 'الشهر السابق', next: 'الشهر التالي', today: 'اليوم', close: 'إغلاق'
        },
        en: {
            months: ['Farvardin', 'Ordibehesht', 'Khordad', 'Tir', 'Mordad', 'Shahrivar', 'Mehr', 'Aban', 'Azar', 'Dey', 'Bahman', 'Esfand'],
            days: ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            prev: 'Previous month', next: 'Next month', today: 'Today', close: 'Close'
        }
    };
    var CALENDAR_TEXT = CALENDAR_I18N[UI_LANG] || CALENDAR_I18N.fa;
    var MONTHS = CALENDAR_TEXT.months;
    var DOW = CALENDAR_TEXT.days;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function todayJ() { var d = new Date(); return g2j(d.getFullYear(), d.getMonth() + 1, d.getDate()); }

    /* --- استایل --- */
    function injectCSS() {
        if (document.getElementById('jalali-dp-css')) return;
        var s = document.createElement('style');
        s.id = 'jalali-dp-css';
        s.textContent = [
            '.jdp-pop{position:absolute;z-index:99999;background:var(--bg-primary,#fff);border:1px solid var(--border,#e2e8f0);border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.18);padding:10px;width:264px;font-family:inherit;direction:' + (document.documentElement.dir || 'rtl') + ';user-select:none}',
            '.jdp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:4px}',
            '.jdp-title{font-weight:700;font-size:14px;color:var(--text-primary,#0f172a);flex:1;text-align:center}',
            '.jdp-nav{border:1px solid var(--border,#e2e8f0);background:var(--bg-secondary,#f8fafc);border-radius:8px;width:28px;height:28px;cursor:pointer;font-size:13px;color:var(--text-primary,#334155);display:flex;align-items:center;justify-content:center}',
            '.jdp-nav:hover{background:#f59e0b;color:#fff;border-color:#f59e0b}',
            '.jdp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center}',
            '.jdp-dow{font-size:10px;color:var(--text-secondary,#64748b);padding:3px 0;font-weight:600}',
            '.jdp-day{font-size:12px;padding:5px 0;border-radius:8px;cursor:pointer;color:var(--text-primary,#0f172a)}',
            '.jdp-day:hover{background:#fde68a}',
            '.jdp-day.sel{background:#f59e0b;color:#fff;font-weight:700}',
            '.jdp-day.tdy{outline:1.5px dashed #f59e0b;outline-offset:-2px}',
            '.jdp-day.oth{opacity:.30}',
            '.jdp-foot{display:flex;justify-content:space-between;margin-top:8px;gap:6px}',
            '.jdp-foot button{flex:1;border:1px solid var(--border,#e2e8f0);background:var(--bg-secondary,#f8fafc);border-radius:8px;padding:5px;font-size:12px;cursor:pointer;color:var(--text-primary,#334155)}',
            '.jdp-foot button:hover{background:#f59e0b;color:#fff}'
        ].join('\n');
        document.head.appendChild(s);
    }

    /* --- ساخت پاپ‌آپ برای یک اینپوت --- */
    var openPop = null;

    function closePop() {
        if (openPop) { openPop.remove(); openPop = null; }
        document.removeEventListener('mousedown', outsideClick, true);
    }
    function outsideClick(e) {
        if (openPop && !openPop.contains(e.target) && e.target !== openPop._input) closePop();
    }

    function attach(input) {
        if (input._jdpAttached) return;
        input._jdpAttached = true;
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('inputmode', 'numeric');
        if (!input.placeholder || input.placeholder.indexOf('/') === -1) input.placeholder = 'YYYY/MM/DD';

        input.addEventListener('focus', function () { openFor(input); });
        input.addEventListener('click', function () { if (!openPop) openFor(input); });

        // ورود دستی: نرمال‌سازی و ولیدیشن هنگام خروج
        input.addEventListener('blur', function () {
            setTimeout(function () { // اجازه بده کلیک روی پاپ‌آپ اول پردازش شود
                var v = input.value.trim();
                if (v === '') return;
                v = v.replace(/-/g, '/').replace(/\./g, '/');
                var m = v.match(/^(\d{3,4})\/(\d{1,2})\/(\d{1,2})$/);
                if (m) {
                    var jy = +m[1], jm = +m[2], jd = +m[3];
                    if (jm >= 1 && jm <= 12 && jd >= 1 && jd <= jMonthDays(jy, jm)) {
                        input.value = jy + '/' + pad(jm) + '/' + pad(jd);
                        input.classList.remove('jdp-invalid');
                    } else input.classList.add('jdp-invalid');
                } else input.classList.add('jdp-invalid');
                if (input.classList.contains('jdp-invalid')) {
                    input.style.borderColor = '#ef4444';
                } else input.style.borderColor = '';
            }, 180);
        });
    }

    function parseVal(input, fallback) {
        var m = (input.value || '').match(/^(\d{4})\/(\d{2})\/(\d{2})$/);
        if (m) return [+m[1], +m[2], +m[3]];
        return fallback;
    }

    function openFor(input) {
        closePop();
        injectCSS();
        var tj = todayJ();
        var state = { jy: 0, jm: 0, jd: 0 };
        var cur = parseVal(input, tj);
        state.jy = cur[0]; state.jm = cur[1]; state.jd = cur[2];

        var pop = document.createElement('div');
        pop.className = 'jdp-pop';
        pop._input = input;

        function render() {
            var days = jMonthDays(state.jy, state.jm);
            var g = j2g(state.jy, state.jm, 1);
            var firstDow = new Date(g[0], g[1] - 1, g[2]).getDay(); // 0=Sun..6=Sat
            var startIdx = (firstDow + 1) % 7; // شنبه=0 ... جمعه=6
            var html = '<div class="jdp-head">'
                + '<button type="button" class="jdp-nav" data-a="pm" title="' + CALENDAR_TEXT.prev + '">&#x2039;</button>'
                + '<div class="jdp-title">' + MONTHS[state.jm - 1] + ' ' + state.jy + '</div>'
                + '<button type="button" class="jdp-nav" data-a="nm" title="' + CALENDAR_TEXT.next + '">&#x203A;</button>'
                + '</div><div class="jdp-grid">';
            DOW.forEach(function (d) { html += '<div class="jdp-dow">' + d + '</div>'; });
            for (var i = 0; i < startIdx; i++) html += '<div class="jdp-day oth"></div>';
            for (var d = 1; d <= days; d++) {
                var cls = 'jdp-day';
                if (d === state.jd && input.value === state.jy + '/' + pad(state.jm) + '/' + pad(d)) cls += ' sel';
                if (d === tj[2] && state.jm === tj[1] && state.jy === tj[0]) cls += ' tdy';
                html += '<div class="' + cls + '" data-d="' + d + '">' + d + '</div>';
            }
            html += '</div><div class="jdp-foot">'
                + '<button type="button" data-a="today">' + CALENDAR_TEXT.today + '</button>'
                + '<button type="button" data-a="close">' + CALENDAR_TEXT.close + '</button>'
                + '</div>';
            pop.innerHTML = html;
        }
        render();

        pop.addEventListener('click', function (e) {
            var t = e.target;
            if (t.classList.contains('jdp-day') && t.dataset.d) {
                state.jd = +t.dataset.d;
                input.value = state.jy + '/' + pad(state.jm) + '/' + pad(state.jd);
                input.classList.remove('jdp-invalid');
                input.style.borderColor = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
                closePop();
                return;
            }
            var a = t.dataset && t.dataset.a;
            if (a === 'nm') { state.jm++; if (state.jm > 12) { state.jm = 1; state.jy++; } render(); }
            else if (a === 'pm') { state.jm--; if (state.jm < 1) { state.jm = 12; state.jy--; } render(); }
            else if (a === 'today') {
                state.jy = tj[0]; state.jm = tj[1]; state.jd = tj[2];
                input.value = state.jy + '/' + pad(state.jm) + '/' + pad(state.jd);
                input.classList.remove('jdp-invalid'); input.style.borderColor = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
                closePop();
            }
            else if (a === 'close') closePop();
        });

        document.body.appendChild(pop);
        var r = input.getBoundingClientRect();
        var pw = 264;
        var left = window.scrollX + ((document.documentElement.dir || 'rtl') === 'rtl' ? r.right - pw : r.left);
        if (left < window.scrollX + 4) left = window.scrollX + 4;
        pop.style.left = left + 'px';
        pop.style.top = (window.scrollY + r.bottom + 4) + 'px';
        openPop = pop;
        document.addEventListener('mousedown', outsideClick, true);
    }

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePop(); });
    window.addEventListener('resize', closePop);

    function scan() {
        document.querySelectorAll('input.jalali-date').forEach(attach);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
    else scan();
    // مودال‌هایی که بعداً DOM می‌سازند
    if (window.MutationObserver) {
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                if (muts[i].addedNodes.length) { scan(); break; }
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
    // API عمومی در صورت نیاز
    window.JalaliDP = { open: openFor, attach: attach, g2j: g2j, j2g: j2g };
})();
