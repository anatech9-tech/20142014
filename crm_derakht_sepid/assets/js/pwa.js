/* ═══ §13 PWA: ثبت Service Worker + پاپ‌آپ نصب + اجازه اعلان ═══ */
(function () {
    'use strict';

    // 1) ثبت Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').then(function (reg) {
                if (reg.waiting) reg.waiting.postMessage('skipWaiting');
            }).catch(function () { /* حالت نوشتاری/شبکه — بی‌صدا رد شو */ });
        });
    }

    // 2) پاپ‌آپ نصب (§13.4) — با اسنوز ۷ روزه
    var UI_LANG = (document.documentElement.lang || 'fa').toLowerCase().split('-')[0];
    var PWA_TEXT = {
        fa: {title: 'نصب اپلیکیشن کارگاه', body: 'دسترسی سریع‌تر و کار آفلاین — روی صفحه اصلی نصب کنید', install: 'نصب', later: 'بعداً'},
        ar: {title: 'تثبيت تطبيق الورشة', body: 'وصول أسرع والعمل دون اتصال — ثبّت التطبيق على الشاشة الرئيسية', install: 'تثبيت', later: 'لاحقاً'},
        en: {title: 'Install Workshop App', body: 'Faster access and offline work — install it on your home screen', install: 'Install', later: 'Later'}
    }[UI_LANG] || {
        title: 'نصب اپلیکیشن کارگاه', body: 'دسترسی سریع‌تر و کار آفلاین — روی صفحه اصلی نصب کنید', install: 'نصب', later: 'بعداً'
    };
    var deferredPrompt = null;
    var SNOOZE_KEY = 'pwa_install_snooze_until';

    function snoozed() {
        try {
            var until = parseInt(localStorage.getItem(SNOOZE_KEY) || '0', 10);
            return until && Date.now() < until;
        } catch (e) { return false; }
    }
    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches ||
               window.navigator.standalone === true;
    }

    function showInstallBanner() {
        if (!deferredPrompt || snoozed() || isStandalone()) return;
        if (document.getElementById('pwa-install-banner')) return;

        var b = document.createElement('div');
        b.id = 'pwa-install-banner';
        b.style.cssText = 'position:fixed;bottom:16px;left:16px;right:16px;max-width:420px;margin:0 auto;' +
            'background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-radius:16px;' +
            'padding:14px 16px;box-shadow:0 12px 40px rgba(0,0,0,.35);z-index:99998;direction:' + (document.documentElement.dir || 'rtl') + ';' +
            'display:flex;align-items:center;gap:12px;font-size:13px;animation:pwaSlideUp .35s ease';
        b.innerHTML =
            '<img src="assets/icons/icon-192.png" alt="" style="width:44px;height:44px;border-radius:10px;flex-shrink:0">' +
            '<div style="flex:1;line-height:1.7"><strong>' + PWA_TEXT.title + '</strong><br>' +
            '<span style="opacity:.92">' + PWA_TEXT.body + '</span></div>' +
            '<div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">' +
            '<button type="button" id="pwa-install-yes" style="background:#fff;color:#b45309;border:none;' +
            'border-radius:8px;padding:6px 14px;font-weight:700;cursor:pointer;font-family:inherit">' + PWA_TEXT.install + '</button>' +
            '<button type="button" id="pwa-install-no" style="background:rgba(255,255,255,.2);color:#fff;border:none;' +
            'border-radius:8px;padding:4px 14px;cursor:pointer;font-family:inherit;font-size:11px">' + PWA_TEXT.later + '</button>' +
            '</div>';

        var st = document.createElement('style');
        st.textContent = '@keyframes pwaSlideUp{from{transform:translateY(80px);opacity:0}to{transform:none;opacity:1}}';
        document.head.appendChild(st);
        document.body.appendChild(b);

        document.getElementById('pwa-install-yes').addEventListener('click', function () {
            b.remove();
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (res) {
                if (res.outcome === 'accepted') {
                    try { localStorage.setItem(SNOOZE_KEY, String(Date.now() + 365 * 86400000)); } catch (e) {}
                } else {
                    try { localStorage.setItem(SNOOZE_KEY, String(Date.now() + 7 * 86400000)); } catch (e) {}
                }
                deferredPrompt = null;
            });
        });
        document.getElementById('pwa-install-no').addEventListener('click', function () {
            b.remove();
            try { localStorage.setItem(SNOOZE_KEY, String(Date.now() + 7 * 86400000)); } catch (e) {}
        });
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        setTimeout(showInstallBanner, 2500); // کمی صبر تا صفحه کامل لود شود
    });
    window.addEventListener('appinstalled', function () {
        try { localStorage.setItem(SNOOZE_KEY, String(Date.now() + 365 * 86400000)); } catch (e) {}
        var b = document.getElementById('pwa-install-banner');
        if (b) b.remove();
    });

    // 3) اجازه اعلان (§13.5) — یک‌بار پس از اولین تعامل کاربر
    function askNotifPermission() {
        if (!('Notification' in window)) return;
        if (Notification.permission !== 'default') return;
        var asked = false;
        try { asked = !!localStorage.getItem('pwa_notif_asked'); } catch (e) {}
        if (asked) return;
        try { localStorage.setItem('pwa_notif_asked', '1'); } catch (e) {}
        Notification.requestPermission();
    }
    ['click', 'keydown', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, askNotifPermission, { once: true, passive: true });
    });
})();
