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
function uiNumberLocale() {
    var lang = uiLanguage();
    return lang === 'en' ? 'en-US' : (lang === 'ar' ? 'ar' : 'fa-IR');
}
function uiCurrencyLabel() {
    return {fa: 'تومان', ar: 'تومان', en: 'Toman'}[uiLanguage()] || 'تومان';
}
function formatNumber(num) {
    if (!num) return '0';
    return Number(num).toLocaleString(uiNumberLocale());
}

// Format Currency
function formatCurrency(amount) {
    if (!amount) return '0 ' + uiCurrencyLabel();
    return Number(amount).toLocaleString(uiNumberLocale()) + ' ' + uiCurrencyLabel();
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
