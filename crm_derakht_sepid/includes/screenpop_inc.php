<?php
/**
 * بخش Screen Pop — پاپ‌آپ شناسایی مشتری هنگام زنگ (فقط کاربران دارای داخلی تلفنچی)
 * هم پاپ‌آپِ شناسایی هم دکمهٔ «📞 تماس» (Click-to-Call) را فراهم می‌کند.
 */
if (!isset($me) || (int)$me === 0) { $me = (int)($_SESSION['user_id'] ?? 0); }
$__wttp_ext = '';
try {
    $__st = $pdo->prepare("SELECT telefonchy_ext FROM users WHERE id = ?");
    $__st->execute([$me]);
    $__wttp_ext = trim((string)$__st->fetchColumn());
} catch (Throwable $e) {}
// CSRF توکن برای فراخوانی به APIها
if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = generate_token(); }
?>
<script>window.__CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;window.__WTTP_EXT = <?= json_encode($__wttp_ext, JSON_UNESCAPED_UNICODE) ?>;</script>
<div id="screenPop" class="screenpop" hidden role="alertdialog" aria-label="تماس ورودی">
    <div class="screenpop-head">
        <span class="sp-pulse" aria-hidden="true"></span>
        <span id="spDirection">تماس ورودی</span>
        <button type="button" class="sp-close" onclick="screenPopDismiss(this)" aria-label="بستن">✕</button>
    </div>
    <div class="sp-body" id="spBody" role="link" tabindex="-1" aria-label="باز کردن پروندهٔ مشتری">
        <div class="sp-number" id="spNumber" dir="ltr">—</div>
        <div class="sp-match" id="spMatch">شناسایی نشد</div>
        <div class="sp-meta" id="spMeta"></div>
    </div>
    <div class="sp-actions">
        <button type="button" id="spOpen" class="btn btn-primary sp-open-btn" style="display:none"><i data-lucide="folder-open"></i> باز کردن پرونده</button>
        <button type="button" class="btn btn-ghost btn-sm" id="spCallBack"><i data-lucide="phone-call"></i> بازگشت تماس</button>
    </div>
</div>
<style>
.screenpop{position:fixed;bottom:22px;left:22px;z-index:4000;width:400px;max-width:calc(100vw - 36px);background:var(--surface,#fff);border:2px solid var(--primary,#308e89);border-radius:18px;box-shadow:0 24px 60px rgba(2,44,40,.35);overflow:hidden;animation:spIn .35s cubic-bezier(.2,1.4,.4,1)}
@keyframes spIn{from{opacity:0;transform:translateY(26px) scale(.92)}to{opacity:1;transform:none}}
.screenpop-head{display:flex;align-items:center;gap:9px;background:linear-gradient(135deg,var(--primary,#308e89),#1e6f6a);color:#fff;padding:10px 14px;font-weight:800;font-size:13px}
.sp-pulse{width:10px;height:10px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 0 rgba(74,222,128,.6);animation:spPulse 1.2s infinite}
@keyframes spPulse{70%{box-shadow:0 0 0 9px rgba(74,222,128,0)}100%{box-shadow:0 0 0 0 rgba(74,222,128,0)}}
.sp-close{margin-inline-start:auto;background:rgba(255,255,255,.18);color:#fff;border:0;border-radius:8px;width:30px;height:30px;cursor:pointer;font-size:15px}
.sp-close:hover{background:rgba(255,255,255,.32)}
.sp-body{padding:16px 18px 8px}
.sp-body.sp-clickable{cursor:pointer;transition:background .15s}
.sp-body.sp-clickable:hover{background:rgba(48,142,137,.07)}
.sp-number{font-size:28px;font-weight:900;letter-spacing:.5px;direction:ltr;text-align:right;line-height:1.15}
.sp-match{font-size:15px;color:var(--text-secondary,#444);margin-top:6px;font-weight:800}
.sp-meta{font-size:11.5px;color:var(--muted,#888);margin-top:3px;min-height:16px}
.sp-actions{display:flex;gap:10px;padding:12px 18px 18px}
.sp-actions .btn{flex:1;justify-content:center;min-height:44px;font-size:14px;font-weight:800;border-radius:10px}
.sp-open-btn{flex:1.6}
@media (max-width:600px){.screenpop{bottom:12px;left:12px;right:12px;width:auto}}
</style>
<script>
(function () {
    if (window.__scrPopInit) return;
    window.__scrPopInit = true;

    var box = document.getElementById('screenPop');
    var dismissed = {};
    try { dismissed = JSON.parse(sessionStorage.getItem('spDismissed') || '{}'); } catch (e) { dismissed = {}; }

    function persistDismissed() {
        try { sessionStorage.setItem('spDismissed', JSON.stringify(dismissed)); } catch (e) {}
    }

    window.screenPopDismiss = function () {
        var cuid = box.getAttribute('data-cuid') || '';
        if (cuid) { dismissed[cuid] = 1; persistDismissed(); }
        box.hidden = true;
    };

    function hideForCuid(cuid) {
        if (cuid === box.getAttribute('data-cuid')) box.hidden = true;
    }

    function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    var lastHash = '';
    function render(events) {
        if (!events || !events.length) { box.hidden = true; lastHash = ''; return; }
        var ev = events[0];
        if (dismissed[ev.cuid || '']) { box.hidden = true; return; }
        var hash = (ev.cuid || '') + '|' + (ev.customer_id || 0) + '|' + (ev.lead_id || 0);
        if (hash === lastHash && !box.hidden) return;
        lastHash = hash;
        box.setAttribute('data-cuid', ev.cuid || '');
        document.getElementById('spDirection').textContent = ev.direction === 'outgoing' ? '📞 تماس خروجی (در حال برقراری)' : '📞 تماس ورودی';
        document.getElementById('spNumber').textContent = ev.number || '—';
        var matchEl = document.getElementById('spMatch');
        var metaEl = document.getElementById('spMeta');
        var openEl = document.getElementById('spOpen');
        var match = ev.customer || ev.lead || null;
        var bodyEl = document.getElementById('spBody');
        if (match) {
            matchEl.innerHTML = (ev.customer ? '👤 ' : '🧲 ') + esc(match.name);
            metaEl.textContent = (ev.customer_calls ? ('تعداد دفعات تماس: ' + ev.customer_calls + (ev.customer_last_call ? ' • آخرین: ' + ev.customer_last_call : '')) : (ev.customer ? 'مشتری ثبت‌شده' : 'سرنخ ثبت‌شده'));
            openEl.setAttribute('data-url', match.url || '');
            openEl.style.display = '';
            bodyEl.setAttribute('data-url', match.url || '');
            bodyEl.setAttribute('tabindex', '0');
            bodyEl.classList.add('sp-clickable');
            bodyEl.title = 'باز کردن پروندهٔ مشتری (در تب جدید)';
        } else {
            matchEl.innerHTML = '<span style="color:#b45309">مشتری/سرنخ ناشناخته — ثبت‌نام نشده</span>';
            metaEl.textContent = 'این شماره در CRM، مشتری یا سرنخ ندارد.';
            openEl.removeAttribute('data-url');
            openEl.style.display = 'none';
            bodyEl.removeAttribute('data-url');
            bodyEl.setAttribute('tabindex', '-1');
            bodyEl.classList.remove('sp-clickable');
            bodyEl.removeAttribute('title');
        }
        window.__spCurrent = ev;
        box.hidden = false;
        if (window.lucide && lucide.createIcons) lucide.createIcons();
    }

    function poll() {
        fetch('api/live_calls.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.enabled) return;
                render(d.events || []);
            })
            .catch(function () {});
    }
    setInterval(poll, 4000);
    poll();

    /* باز کردن پرونده: تب جدید (در صورت مسدود بودن window.open، همان تب) */
    function openMatchUrl(url) {
        if (!url) return;
        var w = null;
        try { w = window.open(url, '_blank', 'noopener'); } catch (err) { w = null; }
        if (!w) { window.location.href = url; }
    }
    document.getElementById('spOpen').addEventListener('click', function (e) {
        e.preventDefault();
        openMatchUrl(this.getAttribute('data-url') || '');
    });
    (function () {
        var bodyEl = document.getElementById('spBody');
        bodyEl.addEventListener('click', function () { openMatchUrl(this.getAttribute('data-url') || ''); });
        bodyEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openMatchUrl(this.getAttribute('data-url') || ''); }
        });
    })();

    /* دکمهٔ بازگشت تماس از داخل پاپ‌آپ */
    document.getElementById('spCallBack').addEventListener('click', function () {
        var ev = window.__spCurrent || {};
        if (ev.number) wttpCall(ev.number, ev.customer_id || null, ev.lead_id || null);
    });

    /* کلاینت Click-to-Call سراسری: wttpCall(number, customerId, leadId) */
    window.wttpCall = function (number, customerId, leadId) {
        var body = 'to=' + encodeURIComponent(number || '')
            + '&customer_id=' + encodeURIComponent(customerId || 0)
            + '&lead_id=' + encodeURIComponent(leadId || 0);
        fetch('api/click_to_call.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=utf-8', 'X-CSRF-Token': (window.__CSRF || '') },
            body: body, credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (d) {
            var msg = d && d.ok ? '📞 تماس از داخلی شما به ' + (d.to || number) + ' برقرار شد.' : ('خطا در برقراری تماس' + (d && d.message ? ': ' + d.message : ''));
            if (window.showToast) window.showToast(msg, d && d.ok ? 'success' : 'error'); else alert(msg);
        }).catch(function () { if (window.showToast) window.showToast('خطا در تماس با سرور', 'error'); });
    };
})();
</script>
