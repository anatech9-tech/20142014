// Toast Notification
function showToast(message, type = 'success') {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// AJAX Helper
async function api(url, options = {}) {
    try {
        const defaults = {
            headers: {
                'Content-Type': 'application/json',
            }
        };
        
        if (options.body && !(options.body instanceof FormData)) {
            options.body = JSON.stringify(options.body);
        } else if (options.body instanceof FormData) {
            delete defaults.headers['Content-Type'];
        }
        
        const response = await fetch(url, { ...defaults, ...options });
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || ({fa: 'خطایی رخ داد', ar: 'حدث خطأ', en: 'An error occurred'}[uiLanguage()] || 'An error occurred'));
        }
        
        return data;
    } catch (error) {
        showToast(error.message, 'error');
        throw error;
    }
}

// Confirm Dialog
function confirmAction(message) {
    return new Promise((resolve) => {
        if (confirm(message)) {
            resolve(true);
        } else {
            resolve(false);
        }
    });
}

// Locale-aware number and currency formatting
function uiLanguage() {
    return (document.documentElement.lang || 'fa').toLowerCase().split('-')[0];
}
function uiText(key) {
    var texts = {
        processing: {fa: 'در حال پردازش...', ar: 'جارٍ المعالجة...', en: 'Processing...'},
        print: {fa: 'چاپ', ar: 'طباعة', en: 'Print'},
        copied: {fa: 'کپی شد', ar: 'تم النسخ', en: 'Copied'},
        copyError: {fa: 'خطا در کپی', ar: 'خطأ في النسخ', en: 'Copy failed'}
    };
    var lang = uiLanguage();
    return (texts[key] && texts[key][lang]) || (texts[key] && texts[key].fa) || key;
}
function uiCurrencyLabel() {
    return {fa: 'تومان', ar: 'تومان', en: 'Toman'}[uiLanguage()] || 'تومان';
}
function uiFaGroup(n) {
    n = Number(n || 0);
    if (isNaN(n)) return '0';
    var neg = n < 0;
    var parts = Math.abs(n).toFixed(0).split('.');
    var int = parts[0], out = '';
    for (var i = 0; i < int.length; i++) {
        var remaining = int.length - i;
        out += int.charAt(i);
        if (remaining > 1 && remaining % 3 === 1) out += '،';
    }
    return (neg ? '-' : '') + out;
}
function formatNumber(num) {
    if (!num) return '0';
    return uiFaGroup(num);
}

// Format Currency
function formatCurrency(amount) {
    if (!amount) return '0 ' + uiCurrencyLabel();
    return uiFaGroup(amount) + ' ' + uiCurrencyLabel();
}

// Format Hours
function formatHours(hours) {
    if (!hours) return '0:00';
    const h = Math.floor(hours);
    const m = Math.round((hours - h) * 60);
    return `${h}:${m.toString().padStart(2, '0')}`;
}

// Date Helpers
function toJalali(dateStr) {
    if (!dateStr) return '';
    var m = String(dateStr).trim().match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (!m) return dateStr;
    var g_y = parseInt(m[1], 10), g_m = parseInt(m[2], 10), g_d = parseInt(m[3], 10);
    if (!g_y || !g_m || !g_d || g_m < 1 || g_m > 12 || g_d < 1 || g_d > 31) return dateStr;
    var g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    var gy = g_y - 1600, gm = g_m - 1, gd = g_d - 1;
    var g_day_no = 365 * gy + Math.floor((gy + 3) / 4) - Math.floor((gy + 99) / 100) + Math.floor((gy + 399) / 400);
    for (var i = 0; i < gm; ++i) g_day_no += g_days_in_month[i];
    if (gm > 1 && ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0))) g_day_no++;
    g_day_no += gd;
    var j_day_no = g_day_no - 79;
    var j_np = Math.floor(j_day_no / 12053);
    j_day_no %= 12053;
    var jy = 979 + 33 * j_np + 4 * Math.floor(j_day_no / 1461);
    j_day_no %= 1461;
    if (j_day_no >= 366) {
        jy += Math.floor((j_day_no - 1) / 365);
        j_day_no = (j_day_no - 1) % 365;
    }
    for (i = 0; i < 11 && j_day_no >= j_days_in_month[i]; ++i) j_day_no -= j_days_in_month[i];
    var jm = i + 1, jd = j_day_no + 1;
    var p2 = function (n) { return (n < 10 ? '0' : '') + n; };
    return jy + '/' + p2(jm) + '/' + p2(jd);
}

// Loading State
function setLoading(btn, loading) {
    if (loading) {
        btn.disabled = true;
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner"></span> ' + uiText('processing');
    } else {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
    }
}

// Print Helper
function printSection(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>${uiText('print')}</title>
            <link rel="stylesheet" href="assets/css/style.css">
            <style>
                body { font-family: Vazirmatn, sans-serif; direction: ${document.documentElement.dir || (uiLanguage() === 'en' ? 'ltr' : 'rtl')}; padding: 20px; }
                @media print { body { padding: 0; } }
            </style>
        </head>
        <body>${element.innerHTML}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// Export to Excel — پیاده‌سازی واقعی در assets/js/xlsx_export.js است.
// این نسخه فقط در صورت نبود آن فایل به‌عنوان پشتیبان عمل می‌کند.
function exportCSV(data, filename) {
    if (window.exportRowsToXlsx && data && data.length) {
        var headers = Object.keys(data[0]);
        var rows = [headers].concat(data.map(function (r) {
            return headers.map(function (h) { return r[h] == null ? '' : r[h]; });
        }));
        return window.exportRowsToXlsx(rows, filename, true);
    }
}

// Modal Helper - FIXED: robust, with icon refresh and null checks
function openModal(id) {
    try {
        const modal = document.getElementById(id);
        if (!modal) {
            console.error('Modal not found:', id);
            showToast('مودال پیدا نشد: ' + id, 'error');
            return false;
        }
        modal.classList.remove('hidden');
        modal.style.removeProperty('display');
        modal.style.display = 'flex';
        // Refresh lucide icons inside modal
        if (window.lucide) {
            try { lucide.createIcons(); } catch(e){}
        }
        // Focus first input if any
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput) {
            setTimeout(() => { try { firstInput.focus(); } catch(e){} }, 100);
        }
        return false;
    } catch(e) {
        console.error('openModal error:', e);
        return false;
    }
}

function closeModal(id) {
    try {
        const modal = document.getElementById(id);
        if (!modal) return false;
        modal.classList.add('hidden');
        modal.style.display = '';
        return false;
    } catch(e) {
        console.error('closeModal error:', e);
        return false;
    }
}

// Initialize Lucide icons after dynamic content
function refreshIcons() {
    if (window.lucide) {
        try { lucide.createIcons(); } catch(e){}
    }
}

// Debounce
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Persian number conversion
function toPersianNum(num) {
    const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return String(num).replace(/\d/g, d => persianDigits[d]);
}

// Copy to clipboard
async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        showToast(uiText('copied'), 'success');
    } catch {
        showToast(uiText('copyError'), 'error');
    }
}

// Image preview
function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => preview.src = e.target.result;
        reader.readAsDataURL(input.files[0]);
    }
}

// ─── Sidebar: کشوی موبایل + جمع‌شدن دسکتاپ (FIXED: null checks + try/catch) ───
(function(){
    try {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        const backdrop = document.getElementById('sidebarBackdrop');

        window.openMobileMenu = function () {
            try {
                if (!sidebar) return;
                sidebar.classList.add('mobile-open');
                if (backdrop) backdrop.classList.add('show');
                document.body.classList.add('menu-open');
                localStorage.setItem('sidebarMobileOpen', 'true');
            } catch(e){ console.warn('openMobileMenu error', e); }
        };

        window.closeMobileMenu = function () {
            try {
                if (!sidebar) return;
                sidebar.classList.remove('mobile-open');
                if (backdrop) backdrop.classList.remove('show');
                document.body.classList.remove('menu-open');
                localStorage.setItem('sidebarMobileOpen', 'false');
            } catch(e){ console.warn('closeMobileMenu error', e); }
        };

        // Restore collapsed state (only desktop)
        try {
            if (sidebar && localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth > 768) {
                sidebar.classList.add('collapsed');
                if (mainContent) mainContent.classList.add('sidebar-collapsed');
            }
            if (sidebar && localStorage.getItem('sidebarMobileOpen') === 'true' && window.innerWidth <= 768) {
                window.openMobileMenu();
            }
        } catch(e){}

        // Toggle button
        document.querySelectorAll('.toggle-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                try {
                    if (!sidebar) return;
                    if (this.id === 'mobileCloseBtn' || sidebar.classList.contains('mobile-open')) {
                        window.closeMobileMenu();
                        return;
                    }
                    const isCollapsed = sidebar.classList.contains('collapsed');
                    if (isCollapsed) {
                        sidebar.classList.remove('collapsed');
                        if (mainContent) mainContent.classList.remove('sidebar-collapsed');
                        localStorage.setItem('sidebarCollapsed', 'false');
                    } else {
                        sidebar.classList.add('collapsed');
                        if (mainContent) mainContent.classList.add('sidebar-collapsed');
                        localStorage.setItem('sidebarCollapsed', 'true');
                    }
                } catch(e){ console.warn('toggle-btn error', e); }
            });
        });

        document.querySelectorAll('.sidebar-nav .nav-item').forEach(function (a) {
            a.addEventListener('click', function () {
                try { localStorage.setItem('sidebarMobileOpen', 'false'); } catch(e){}
            });
        });

        document.addEventListener('click', function (e) {
            try {
                if (e.target && e.target.id === 'sidebarBackdrop') window.closeMobileMenu();
            } catch(e){}
        });
    } catch(e){
        console.warn('sidebar init error', e);
    }
})();

// Auto-hide alerts + modal overlay/escape handlers
document.addEventListener('DOMContentLoaded', () => {
    try {
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(el => {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 500);
            });
        }, 5000);
        
        // Close modal on overlay click - FIXED: check classList safely
        document.addEventListener('click', function(e) {
            try {
                if (e.target && e.target.classList && e.target.classList.contains('modal-overlay')) {
                    // §v13: باید display اینلاین هم پاک شود؛ openModal آن را flex می‌کند
                    // و اگر باقی بماند، دفعهٔ بعد hidden با display:flex می‌جنگد و
                    // مودال دیگر هرگز باز نمی‌شود (دکمه‌ها بی‌اثر به نظر می‌رسند).
                    e.target.classList.add('hidden');
                    e.target.style.display = '';
                }
            } catch(err){}
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            try {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => {
                        m.classList.add('hidden');
                        m.style.display = '';
                    });
                    if (window.closeMobileMenu) window.closeMobileMenu();
                }
            } catch(err){}
        });

        // Global error handler to show toast for uncaught errors (helps debug "no reaction")
        window.addEventListener('error', function(e){
            console.error('Global JS error:', e.error || e.message);
            // Uncomment for debugging: showToast('خطای جاوااسکریپت: ' + (e.message||''), 'error');
        });

    } catch(e){ console.warn('DOMContentLoaded init error', e); }
});

// ─── پالت رنگی (۶×۲) + زنگ اعلان + جستجوی سراسری (Ctrl+Q / Ctrl+K) — الگوی مرجع ───
(function () {
    'use strict';

    var PALETTES = ['teal', 'ocean', 'orchid', 'ruby', 'amber', 'emerald'];
    function validPalette(n) { return PALETTES.indexOf(n) >= 0 ? n : ''; }
    var SAVED_PALETTE = validPalette(window.__PALETTE || '') || validPalette((function(){ try { return localStorage.getItem('palette') || ''; } catch (e) { return ''; } })()) || 'teal';
    var currentPalette = SAVED_PALETTE;

    function applyPalette(name, save) {
        name = validPalette(name) || 'teal';
        document.documentElement.setAttribute('data-palette', name);
        if (save !== false) { try { localStorage.setItem('palette', name); } catch (e) {} }
        document.querySelectorAll('.palette-swatch').forEach(function (el) {
            var on = el.getAttribute('data-palette') === name;
            el.classList.toggle('active', on);
            el.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function savePaletteOnServer(name) {
        try {
            var body = 'csrf_token=' + encodeURIComponent(window.__CSRF || '') + '&palette=' + encodeURIComponent(name);
            fetch('index.php?action=save_palette', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
                credentials: 'same-origin'
            }).catch(function () {});
        } catch (e) {}
    }

    function markPalette(name) {
        currentPalette = validPalette(name) || 'teal';
        applyPalette(currentPalette, true);
        savePaletteOnServer(currentPalette);
    }

    // کلیک روی هر نمونه‌رنگ = اعمال + ذخیره روی پروفایل (هر بار ورود اعمال می‌شود)
    document.addEventListener('click', function (e) {
        var sw = e.target && e.target.closest ? e.target.closest('.palette-swatch') : null;
        if (sw) {
            markPalette(sw.getAttribute('data-palette'));
            closePalettePop();
        }
    });
    // هاور/فوکوس = پیش‌نمایش زنده؛ خروج از کانتینر = بازگشت به پالت ذخیره‌شده
    document.addEventListener('mouseover', function (e) {
        var sw = e.target && e.target.closest ? e.target.closest('.palette-swatch') : null;
        if (sw && sw.closest('[data-palette-preview]')) applyPalette(sw.getAttribute('data-palette'), false);
    });
    document.addEventListener('focusin', function (e) {
        var sw = e.target && e.target.closest ? e.target.closest('.palette-swatch') : null;
        if (sw && sw.closest('[data-palette-preview]')) applyPalette(sw.getAttribute('data-palette'), false);
    });
    document.querySelectorAll('[data-palette-preview]').forEach(function (box) {
        box.addEventListener('mouseleave', function () { applyPalette(currentPalette, false); });
        box.addEventListener('focusout', function () { if (!box.contains(document.activeElement)) applyPalette(currentPalette, false); });
    });

    function closePalettePop() {
        var pop = document.getElementById('palettePop');
        var btn = document.getElementById('paletteBtn');
        if (pop) pop.classList.add('hidden');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }
    function togglePalettePop() {
        var pop = document.getElementById('palettePop');
        var btn = document.getElementById('paletteBtn');
        if (!pop) return;
        var willOpen = pop.classList.contains('hidden');
        pop.classList.toggle('hidden', !willOpen);
        if (btn) btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen && window.lucide) { try { lucide.createIcons(); } catch (e) {} }
    }
    var paletteBtn = document.getElementById('paletteBtn');
    if (paletteBtn) paletteBtn.addEventListener('click', function (e) { e.stopPropagation(); togglePalettePop(); });
    document.addEventListener('click', function (e) {
        var pop = document.getElementById('palettePop');
        if (pop && !pop.classList.contains('hidden') && !pop.contains(e.target)) closePalettePop();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePalettePop(); });

    applyPalette(SAVED_PALETTE, false);

    /* زنگ اعلان: باز/بسته شدن پاپ‌اوور */
    try {
        var bellBtn = document.getElementById('bellBtn');
        var bellPop = document.getElementById('bellPopover');
        if (bellBtn && bellPop) {
            bellBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = !bellPop.classList.contains('hidden');
                bellPop.classList.toggle('hidden');
                bellBtn.setAttribute('aria-expanded', open ? 'false' : 'true');
                if (!open && window.lucide) { try { lucide.createIcons(); } catch (err) {} }
            });
            document.addEventListener('click', function (e) {
                if (bellPop.classList.contains('hidden')) return;
                if (bellPop.contains(e.target) || bellBtn.contains(e.target)) return;
                bellPop.classList.add('hidden');
                bellBtn.setAttribute('aria-expanded', 'false');
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { bellPop.classList.add('hidden'); bellBtn.setAttribute('aria-expanded', 'false'); }
            });
        }
    } catch (e) { console.warn('bell popover init error', e); }

    /* جستجوی inline سربرگ (بدون پاپ‌آپ) */
    try {
        var input = document.getElementById('topbarSearchInput');
        var results = document.getElementById('topbarSearchResults');
        var items = Array.isArray(window.__NAV_SEARCH) ? window.__NAV_SEARCH : [];

        function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
        function hitHtml(it, i) {
            return '<a class="search-hit' + (i === 0 ? ' active' : '') + '" role="option" href="index.php?page=' + encodeURIComponent(it.key) + '"><i data-lucide="arrow-left"></i><span>' + esc(it.label) + '</span></a>';
        }
        function renderSearch(filter) {
            if (!results) return;
            var q = (filter || '').trim();
            var hits = items.filter(function (it) { return !q || String(it.label).indexOf(q) !== -1 || String(it.key).toLowerCase().indexOf(q.toLowerCase()) !== -1; });
            results.hidden = false;
            if (!hits.length) {
                results.innerHTML = '<div class="search-empty">نتیجه‌ای برای «' + esc(q) + '» پیدا نشد.</div>';
                return;
            }
            results.innerHTML = hits.slice(0, 12).map(hitHtml).join('');
            if (window.lucide) { try { lucide.createIcons(); } catch (err) {} }
        }
        function closeSearch() { if (results) results.hidden = true; }
        function clearSearch() { if (input) { input.value = ''; } closeSearch(); }

        if (input) {
            input.addEventListener('input', function () { renderSearch(input.value); });
            input.addEventListener('focus', function () { renderSearch(input.value); });
            input.addEventListener('keydown', function (e) {
                var open = results && !results.hidden;
                if (e.key === 'Enter') {
                    var first = open ? results.querySelector('.search-hit') : null;
                    if (first) { e.preventDefault(); window.location.href = first.getAttribute('href'); }
                } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    if (!open) return;
                    e.preventDefault();
                    var nodes = Array.prototype.slice.call(results.querySelectorAll('.search-hit'));
                    if (!nodes.length) return;
                    var idx = nodes.findIndex(function (n) { return n.classList.contains('active'); });
                    idx = e.key === 'ArrowDown' ? Math.min(nodes.length - 1, idx + 1) : Math.max(0, idx - 1);
                    nodes.forEach(function (n, i) { n.classList.toggle('active', i === idx); });
                    if (nodes[idx].scrollIntoView) nodes[idx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    clearSearch();
                    input.blur();
                }
            });
        }
        document.addEventListener('click', function (e) {
            var box = document.getElementById('topbarSearch');
            if (box && !box.contains(e.target)) closeSearch();
        });
        document.addEventListener('keydown', function (e) {
            var k = (e.key || '').toLowerCase();
            if ((e.ctrlKey || e.metaKey) && (k === 'q' || k === 'k' || e.key === 'ق')) {
                e.preventDefault();
                if (input) { input.focus(); input.select(); }
            }
        });
    } catch (e) { console.warn('inline search init error', e); }
})();
