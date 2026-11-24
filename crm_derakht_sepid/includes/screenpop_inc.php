<?php
/** پنجرهٔ تماس برای داخلی کاربر جاری؛ انتقال تماس از این API پشتیبانی نمی‌شود. */
$__wttp_ext = '';
try {
    $__st = $pdo->prepare('SELECT telefonchy_ext FROM users WHERE id = ?');
    $__st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $__wttp_ext = trim((string)$__st->fetchColumn());
} catch (Throwable $e) {}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = generate_token();
?>
<script>window.__CSRF = <?= json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;window.__WTTP_EXT = <?= json_encode($__wttp_ext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<div id="screenPop" class="sp-overlay" hidden>
    <section class="screenpop" role="dialog" aria-modal="true" aria-labelledby="spDirection" tabindex="-1">
        <div class="screenpop-head"><span class="sp-pulse" aria-hidden="true"></span><strong id="spDirection">تماس ورودی</strong><button type="button" class="sp-close" onclick="screenPopDismiss()" aria-label="بستن پنجره">✕</button></div>
        <div class="sp-body">
            <div class="sp-summary"><div><div class="sp-label">شماره تماس</div><div class="sp-number" id="spNumber" dir="ltr">—</div></div><div><div class="sp-label">مشتری / شرکت</div><div class="sp-match" id="spMatch">—</div><div class="sp-meta" id="spMeta"></div></div></div>
            <div id="spDetails"></div>
            <div class="sp-notice">اطلاعات حضور بر اساس ثبت‌های موجود است و به معنی در دسترس بودن قطعی نیست. انتقال تماس از این پنجره هنوز توسط API موجود پشتیبانی نشده است.</div>
        </div>
        <div class="sp-actions"><button type="button" id="spOpen" class="btn btn-primary" hidden>باز کردن پرونده</button><button type="button" class="btn btn-ghost" id="spCallBack">تماس خروجی با مشتری</button><button type="button" class="btn btn-ghost" onclick="screenPopDismiss()">بستن</button></div>
    </section>
</div>
<style>
.sp-overlay[hidden], .sp-actions [hidden]{display:none!important}.sp-overlay{position:fixed;inset:0;z-index:4000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:18px}.screenpop{width:min(900px,100%);max-height:calc(100vh - 36px);display:flex;flex-direction:column;background:var(--surface,#fff);border:1px solid var(--primary,#308e89);border-radius:20px;box-shadow:0 24px 80px rgba(2,44,40,.3);overflow:hidden;animation:spIn .25s ease-out}.screenpop:focus{outline:none}@keyframes spIn{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}.screenpop-head{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#187e79,#164e63);color:#fff;padding:16px 20px;font-size:15px}.sp-pulse{width:10px;height:10px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 5px rgba(74,222,128,.15)}.sp-close{margin-inline-start:auto;background:rgba(255,255,255,.18);color:#fff;border:0;border-radius:8px;width:36px;height:36px;cursor:pointer;font-size:16px}.sp-body{padding:20px;overflow:auto}.sp-summary{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:16px;background:linear-gradient(120deg,#f0fdfa,#eff6ff);border:1px solid #ccebe7;border-radius:14px;color:#0f172a}.sp-label{font-size:11px;color:#64748b}.sp-number{font-size:23px;font-weight:900;text-align:right}.sp-match{font-size:18px;font-weight:800}.sp-meta{font-size:11px;color:#64748b;margin-top:5px}.sp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}.sp-card{border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:14px;min-width:0}.sp-card h4{font-size:13px;margin:0 0 10px;color:var(--primary,#187e79)}.sp-row{font-size:12px;line-height:1.9;overflow-wrap:anywhere}.sp-row+ .sp-row{border-top:1px solid var(--border,#e2e8f0);padding-top:7px;margin-top:7px}.sp-muted{font-size:11px;color:var(--text-muted,#64748b)}.sp-notice{font-size:11px;line-height:1.8;color:#9a6700;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:9px 12px;margin-top:14px}.sp-actions{display:flex;gap:8px;padding:12px 20px 18px;flex-wrap:wrap}.sp-actions .btn{justify-content:center}@media(max-width:600px){.sp-overlay{padding:8px}.screenpop{max-height:calc(100vh - 16px)}.sp-body{padding:12px}.sp-grid,.sp-summary{grid-template-columns:1fr}.sp-actions{padding:10px 12px 12px}}
</style>
<script>
(function () {
    if (window.__scrPopInit) return;
    window.__scrPopInit = true;
    var box=document.getElementById('screenPop'), dialog=box.querySelector('.screenpop'), lastFocused=null, lastHash='', current=null;
    var dismissed={};
    try { dismissed=JSON.parse(sessionStorage.getItem('spDismissed') || '{}') || {}; } catch(e) { dismissed={}; }
    function key(ev) { return ev.cuid ? 'call:'+ev.cuid : 'event:'+ev.id; }
    window.screenPopDismiss=function () {
        if (current) { dismissed[key(current)]=1; try { var keys=Object.keys(dismissed); if(keys.length>100) keys.slice(0,keys.length-100).forEach(function(k){delete dismissed[k];}); sessionStorage.setItem('spDismissed',JSON.stringify(dismissed)); } catch(e) {} }
        box.hidden=true; current=null; lastHash=''; if (lastFocused && lastFocused.focus) lastFocused.focus();
    };
    box.addEventListener('keydown',function(e){
        if(e.key==='Escape'){e.preventDefault();window.screenPopDismiss();return;}
        if(e.key==='Tab'){
            var buttons=Array.prototype.filter.call(dialog.querySelectorAll('button:not([disabled])'),function(el){return !el.hidden && el.offsetParent!==null;});
            if(!buttons.length){e.preventDefault();return;}
            if(e.shiftKey && (document.activeElement===buttons[0] || document.activeElement===dialog)){e.preventDefault();buttons[buttons.length-1].focus();}
            else if(!e.shiftKey && document.activeElement===buttons[buttons.length-1]){e.preventDefault();buttons[0].focus();}
        }
    });
    function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
    function text(v){return esc(v || '—');}
    function card(title, content){return '<div class="sp-card"><h4>'+title+'</h4>'+content+'</div>';}
    function row(label,value){return '<div class="sp-row"><span class="sp-muted">'+label+': </span>'+text(value)+'</div>';}
    function renderDetails(d){
        if(!d) return '<div class="sp-card" style="margin-top:14px">جزئیات پرونده در دسترس نیست.</div>';
        function person(label,name,avatar){
            var image = avatar && /^uploads\/avatars\/[A-Za-z0-9_.-]+$/.test(avatar) ? '<img src="'+esc(avatar)+'" alt="" style="width:30px;height:30px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-left:6px">' : '';
            return '<div class="sp-row"><span class="sp-muted">'+label+': </span>'+image+text(name)+'</div>';
        }
        var info=row('شهر',d.city)+row('آدرس',d.address)+person('ثبت‌کننده',d.creator,d.creator_avatar)+person('کارشناس مسئول پیگیری',d.owner,d.owner_avatar)+row('داخلی کارشناس مسئول',d.owner_ext)+row('وضعیت کارشناس',d.owner_status);
        if (!d.can_open) info += '<div class="sp-muted">نمایش کامل صفحهٔ مشتری فقط برای مسئول، ثبت‌کننده و مدیر مربوط مجاز است.</div>';
        var contacts=(d.contacts||[]).map(function(c){return '<div class="sp-row">'+text(c.name)+(c.position?' <span class="sp-muted">('+esc(c.position)+')</span>':'')+'</div>';}).join('') || '<div class="sp-muted">مخاطبی ثبت نشده است.</div>';
        var activities=(d.activities||[]).map(function(a){return '<div class="sp-row"><b>'+text(a.subject)+'</b><div class="sp-muted">'+esc(a.user||'نامشخص')+' • '+esc(a.date||'')+'</div>'+(a.body?'<div>'+esc(a.body)+'</div>':'')+(a.outcome?'<div class="sp-muted">نتیجه: '+esc(a.outcome)+'</div>':'')+'</div>';}).join('') || '<div class="sp-muted">فعالیتی ثبت نشده است.</div>';
        var tasks=(d.tasks||[]).map(function(t){return '<div class="sp-row">'+text(t.title)+'<div class="sp-muted">'+esc(t.user||'نامشخص')+(t.date?' • '+esc(t.date):'')+'</div></div>';}).join('') || '<div class="sp-muted">پیگیری باز ثبت نشده است.</div>';
        var deals=(d.deals||[]).map(function(t){return '<div class="sp-row">'+text(t.title)+' <span class="sp-muted">— '+text(t.stage)+' ('+esc(({open:'باز',won:'موفق',lost:'ناموفق'})[t.status]||t.status||'نامشخص')+')</span></div>';}).join('') || '<div class="sp-muted">معامله‌ای ثبت نشده است.</div>';
        return '<div class="sp-grid">'+card('مشخصات و مسئول',info)+card('افراد رابط',contacts)+card('آخرین فعالیت‌ها',activities)+card('پیگیری‌های باز',tasks)+card('روند معامله‌ها',deals)+'</div>';
    }
    function render(events){
        var ev=(events||[]).find(function(e){return !dismissed[key(e)];});
        if(!ev){if(current && !box.hidden) document.getElementById('spDirection').textContent='تماس / آخرین اطلاعات دریافتی'; return;}
        var hash=JSON.stringify(ev); if(hash===lastHash && !box.hidden) return;
        if(box.hidden){lastFocused=document.activeElement;box.hidden=false;dialog.focus();}
        lastHash=hash; current=ev; window.__spCurrent=ev;
        document.getElementById('spDirection').textContent=ev.direction==='outgoing'?'تماس خروجی':'تماس ورودی';
        document.getElementById('spNumber').textContent=ev.number||'—';
        var match=ev.customer||ev.lead; document.getElementById('spMatch').textContent=match ? match.name : (ev.crm_restricted?'جزئیات CRM در دسترس نیست':'مشتری / سرنخ شناسایی نشد');
        document.getElementById('spMeta').textContent=ev.customer_calls ? 'تعداد تماس‌های ثبت‌شده: '+ev.customer_calls : (ev.customer?'مشتری ثبت‌شده':(ev.lead?'سرنخ ثبت‌شده':''));
        document.getElementById('spDetails').innerHTML=renderDetails(ev.details);
        var open=document.getElementById('spOpen');open.hidden=!(match&&match.url); open.dataset.url=match&&match.url?match.url:'';
    }
    function poll(){if(!window.__WTTP_EXT)return;fetch('api/live_calls.php',{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&d.enabled)render(d.events||[]);}).catch(function(){});}
    setInterval(poll,4000);poll();
    document.getElementById('spOpen').addEventListener('click',function(){var u=this.dataset.url;if(u) window.open(u,'_blank','noopener');});
    document.getElementById('spCallBack').addEventListener('click',function(){if(current&&current.number)window.wttpCall(current.number,current.customer_id||null,current.lead_id||null);});
    /* تماس خروجی مستقل است و تماس ورودی را منتقل نمی‌کند. */
    window.wttpCall=function(number,customerId,leadId){
        var body='to='+encodeURIComponent(number||'')+'&customer_id='+encodeURIComponent(customerId||0)+'&lead_id='+encodeURIComponent(leadId||0);
        fetch('api/click_to_call.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=utf-8','X-CSRF-Token':window.__CSRF||''},body:body,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){var msg=d&&d.ok?'درخواست تماس خروجی ثبت شد: '+(d.to||number):('خطا در درخواست تماس'+(d&&d.message?': '+d.message:''));if(window.showToast)window.showToast(msg,d&&d.ok?'success':'error');else alert(msg);}).catch(function(){if(window.showToast)window.showToast('خطا در تماس با سرور','error');});
    };
})();
</script>
