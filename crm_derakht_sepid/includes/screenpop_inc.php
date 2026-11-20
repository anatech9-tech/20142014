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
    <div class="sp-body">
        <div class="sp-number" id="spNumber">—</div>
        <div class="sp-match" id="spMatch">شناسایی نشد</div>
        <div class="sp-meta" id="spMeta"></div>
    </div>
    <div class="sp-actions">
        <a id="spOpen" class="btn btn-primary btn-sm" href="#"><i data-lucide="user"></i> باز کردن پرونده</a>
        <button type="button" class="btn btn-ghost btn-sm" id="spCallBack"><i data-lucide="phone-call"></i> بازگشت تماس</button>
    </div>
</div>
<style>
.screenpop{position:fixed;bottom:18px;left:18px;z-index:4000;width:330px;max-width:calc(100vw - 36px);background:var(--surface,#fff);border:1px solid var(--primary,#308e89);border-radius:14px;box-shadow:0 18px 50px rgba(2,44,40,.28);overflow:hidden;animation:spIn .35s cubic-bezier(.2,1.4,.4,1)}
@keyframes spIn{from{opacity:0;transform:translateY(26px) scale(.92)}to{opacity:1;transform:none}}
.screenpop-head{display:flex;align-items:center;gap:9px;background:linear-gradient(135deg,var(--primary,#308e89),#1e6f6a);color:#fff;padding:10px 14px;font-weight:800;font-size:13px}
.sp-pulse{width:10px;height:10px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 0 rgba(74,222,128,.6);animation:spPulse 1.2s infinite}
@keyframes spPulse{70%{box-shadow:0 0 0 9px rgba(74,222,128,0)}100%{box-shadow:0 0 0 0 rgba(74,222,128,0)}}
.sp-close{margin-inline-start:auto;background:rgba(255,255,255,.18);color:#fff;border:0;border-radius:8px;width:26px;height:26px;cursor:pointer;font-size:14px}
.sp-close:hover{background:rgba(255,255,255,.32)}
.sp-body{padding:12px 14px 6px}
.sp-number{font-size:22px;font-weight:900;letter-spacing:.5px;direction:ltr;text-align:right}
.sp-match{font-size:13px;color:var(--text-secondary,#444);margin-top:4px;font-weight:700}
.sp-meta{font-size:11px;color:var(--muted,#888);margin-top:2px;min-height:15px}
.sp-actions{display:flex;gap:8px;padding:10px 14px 14px}
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
        if (match) {
            matchEl.innerHTML = (ev.customer ? '👤 ' : '🧲 ') + esc(match.name);
            metaEl.textContent = (ev.customer_calls ? ('تعداد دفعات تماس: ' + ev.customer_calls + (ev.customer_last_call ? ' • آخرین: ' + ev.customer_last_call : '')) : (ev.customer ? 'مشتری ثبت‌شده' : 'سرنخ ثبت‌شده'));
            openEl.href = match.url;
            openEl.style.display = '';
        } else {
            matchEl.innerHTML = '<span style="color:#b45309">مشتری/سرنخ ناشناخته — ثبت‌نام نشده</span>';
            metaEl.textContent = 'این شماره در CRM، مشتری یا سرنخ ندارد.';
            openEl.removeAttribute('href');
            openEl.style.display = 'none';
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
