<?php
/**
 * تنظیم گردش‌کار «استعلام قیمت» — سازندهٔ بصری نود-اتصال (شبیه n8n)
 * فقط مدیر کل. تغییرات فقط روی استعلام‌های جدید اعمال می‌شود.
 */
$pageTitle = 'گردش‌کار استعلام قیمت';
require_login();
require_once __DIR__ . '/../includes/quotes.php';
ensure_quote_schema($pdo);

if (!is_strict_admin()) {
    include 'includes/header.php';
    echo '<div class="alert alert-danger">این صفحه فقط برای مدیر کل است.</div>';
    include 'includes/footer.php';
    exit;
}

// ── اکشن‌ها ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_quote_flow') {
        $decoded = json_decode((string)($_POST['steps_json'] ?? ''), true);
        $valid = quote_workflow_validate(is_array($decoded) ? $decoded : null);
        if ($valid) {
            quote_workflow_save($pdo, $valid);
            operations_audit($pdo, (int)($_SESSION['user_id'] ?? 0), 'quote_flow_save', 'settings', 0, 'quote_workflow_json');
            showToast('گردش‌کار استعلام ذخیره شد. از این پس استعلام‌های جدید با این ترتیب ساخته می‌شوند.');
        } else {
            showToast('❌ گردش‌کار نامعتبر است (باید با «ثبت استعلام» شروع شود، حداقل ۲ مرحله و یک زنجیرهٔ پیوسته باشد).', 'error');
        }
        redirect('index.php?page=quote_flow');
    }
    if ($action === 'reset_quote_flow') {
        try { $pdo->prepare("DELETE FROM settings WHERE setting_key = 'quote_workflow_json'")->execute(); } catch (Throwable $e) {}
        showToast('گردش‌کار به پیش‌فرض کارخانه بازگشت.');
        redirect('index.php?page=quote_flow');
    }
    if ($action === 'save_quote_margin') {
        $m = (float)str_replace([',', '٬', '،'], '', (string)($_POST['quote_default_margin_pct'] ?? '25'));
        $m = max(0, min(100, $m));
        try {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('quote_default_margin_pct', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string)$m]);
            showToast('حاشیهٔ سود پیش‌فرض روی ' . rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.') . '٪ تنظیم شد.');
        } catch (Throwable $e) { showToast('خطا در ذخیره', 'error'); }
        redirect('index.php?page=quote_flow');
    }
}

$wf = quote_workflow_load($pdo);
$defaultMargin = quote_default_margin($pdo);
$fx = quote_fx_rates($pdo);

// سمت‌های سازمانی برای دراپ‌دان «سمت»
$roles = [];
try { $roles = $pdo->query("SELECT code, name FROM org_roles WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
// کاربران فعال برای دراپ‌دان «کاربر مشخص»
$users = [];
try { $users = $pdo->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name LIMIT 800")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {
    try { $users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name LIMIT 800")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e2) {}
}
$usersById = [];
foreach ($users as $u) $usersById[(int)$u['id']] = $u['full_name'];
$rolesByCode = [];
foreach ($roles as $r) $rolesByCode[$r['code']] = $r['name'];

$stepsForJs = $wf['steps'] ?? [];
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/js/vendor/drawflow.min.css">
<style>
/* Drawflow مختصات را چپ‌به‌راست محاسبه می‌کند؛ محتوای فارسی داخل گره راست‌چین است. */
#flowCanvas { direction:ltr; }
#flowCanvas .drawflow-node { width:190px; padding:12px; border:1px solid #cbd5e1; border-radius:16px; background:linear-gradient(145deg,#fff,#f0fdfa); color:#0f172a; box-shadow:0 8px 24px rgba(15,76,80,.13); }
#flowCanvas .drawflow-node.selected { border-color:#0d9488; box-shadow:0 0 0 3px rgba(13,148,136,.2),0 10px 24px rgba(15,76,80,.16); }
#flowCanvas .drawflow_content_node { direction:rtl; }
#flowCanvas .drawflow-node .inputs, #flowCanvas .drawflow-node .outputs { position:absolute; top:50%; width:0; height:0; }
#flowCanvas .drawflow-node .inputs { left:0; }
#flowCanvas .drawflow-node .outputs { right:0; }
#flowCanvas .drawflow-node .input, #flowCanvas .drawflow-node .output { position:absolute; top:0; left:0; right:auto; width:16px; height:16px; margin:0; transform:translate(-50%,-50%); border:3px solid #0d9488; background:white; z-index:4; }
#flowCanvas .connection .main-path { stroke:#0d9488; stroke-width:3px; }
#flowCanvas .qfn-icon { background:linear-gradient(135deg,#0f766e,#14b8a6); }
</style>

<div class="d-flex justify-between align-center mb-3 animate-in" style="flex-wrap:wrap;gap:10px">
    <div>
        <h2 style="margin:0">🔀 گردش‌کار «استعلام قیمت»</h2>
        <p class="text-muted" style="font-size:11.5px;margin:4px 0 0">گره‌ها را بکشید، به هم وصل کنید و با کلیک روی هر گره مشخصاتش را ویرایش کنید. <b>فقط استعلام‌های جدید</b> با این ترتیب ساخته می‌شوند؛ استعلام‌های در جریان مسیر قبلی خود را طی می‌کنند.</p>
    </div>
    <a href="index.php?page=settings" class="btn btn-ghost btn-sm"><i data-lucide="arrow-right"></i> بازگشت به تنظیمات</a>
</div>

<div class="alert alert-info" style="font-size:11px;margin-bottom:10px">
    💡 ترتیب اجرا از گرهٔ <b>«ثبت استعلام»</b> شروع و از یک خروجی به ورودی گرهٔ بعد وصل می‌شود. گره‌های <b>«اعلان»</b> فقط پیام می‌فرستند و بدون توقف عبور می‌شوند. <b>همهٔ گره‌ها باید در یک زنجیرهٔ پیوسته باشند.</b> برای اتصال مجدد، از خروجی سمت راست یک گره به ورودی سمت چپ گرهٔ بعد بکشید؛ برای حذف اتصال، گره را انتخاب کنید. مدیر فروش هر تیم از <a href="index.php?page=crm_teams">تیم‌های فروش</a> تعیین می‌شود. نرخ ارز در «تنظیمات ← تارگت و کمیسیون» تنظیم می‌شود.
</div>

<div class="flow-shell animate-in">
    <!-- پالت گره‌ها -->
    <div class="flow-palette">
        <div class="flow-palette-title">افزودن مرحله (کلیک یا کشیدن به بوم)</div>
        <div class="flow-palette-item" draggable="true" data-node-type="submit"><span class="flow-pi-icon" style="color:#0284c7"><i data-lucide="send"></i></span>ثبت استعلام</div>
        <div class="flow-palette-item" draggable="true" data-node-type="approval"><span class="flow-pi-icon" style="color:#d97706"><i data-lucide="shield-check"></i></span>تأیید (مدیر)</div>
        <div class="flow-palette-item" draggable="true" data-node-type="costing"><span class="flow-pi-icon" style="color:#7c3aed"><i data-lucide="calculator"></i></span>اعلام قیمت نت</div>
        <div class="flow-palette-item" draggable="true" data-node-type="pricing"><span class="flow-pi-icon" style="color:#059669"><i data-lucide="percent"></i></span>تأیید نهایی + سود</div>
        <div class="flow-palette-item" draggable="true" data-node-type="finalize"><span class="flow-pi-icon" style="color:#16a34a"><i data-lucide="badge-check"></i></span>اعلام به مشتری و فیکس</div>
        <div class="flow-palette-item" draggable="true" data-node-type="notify"><span class="flow-pi-icon" style="color:#475569"><i data-lucide="bell"></i></span>اعلان (بدون اقدام)</div>
        <div style="border-top:1px solid var(--border);margin-top:auto;padding-top:10px">
            <div class="flow-palette-title">وضعیت زنجیره</div>
            <div id="chainStatus" style="font-size:11px;line-height:1.8;color:var(--text-secondary)">…</div>
        </div>
    </div>

    <!-- بوم Drawflow -->
    <div class="flow-canvas-wrap">
        <div class="flow-canvas-toolbar">
            <button class="btn btn-ghost btn-sm" id="zoomOut" title="کوچک‌نمایی"><i data-lucide="zoom-out"></i></button>
            <button class="btn btn-ghost btn-sm" id="zoomFit" title="اندازهٔ اصلی"><i data-lucide="maximize"></i></button>
            <button class="btn btn-ghost btn-sm" id="zoomIn" title="بزرگ‌نمایی"><i data-lucide="zoom-in"></i></button>
            <button class="btn btn-ghost btn-sm" id="autoLayout" title="مرتب‌سازی خودکار زنجیره (چپ→راست)"><i data-lucide="align-horizontal-distribute-center"></i></button>
        </div>
        <div id="flowCanvas" class="drawflow"></div>
    </div>

    <!-- ویرایشگر گره -->
    <div class="flow-editor" id="flowEditor">
        <div class="flow-editor-title">تنظیمات گرهٔ انتخاب‌شده</div>
        <div class="flow-empty" id="editorEmpty">روی هر گره کلیک کنید تا مشخصاتش (عنوان، مسئول، متن اعلان) را اینجا ویرایش کنید.</div>
        <div id="editorForm" style="display:none;flex-direction:column;gap:10px">
            <div><label class="form-label">نوع مرحله</label><div id="edType" style="font-weight:800;font-size:13px"></div></div>
            <div class="form-group"><label class="form-label" for="edTitle">عنوان مرحله</label><input id="edTitle" class="form-control" maxlength="120" placeholder="مثلاً تأیید مدیر فروش"></div>
            <div class="form-group"><label class="form-label" for="edKind">مسئول این مرحله</label>
                <select id="edKind" class="form-control">
                    <option value="owner">ثبت‌کنندهٔ استعلام (کارشناس همان پرونده)</option>
                    <option value="role">سمت سازمانی (همهٔ دارنده‌های آن سمت)</option>
                    <option value="user">کاربر مشخص</option>
                </select></div>
            <div class="form-group" id="roleWrap" style="display:none"><label class="form-label" for="edRole">سمت سازمانی</label>
                <select id="edRole" class="form-control"><?php foreach ($roles as $r): ?><option value="<?= htmlspecialchars($r['code']) ?>"><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" id="userWrap" style="display:none"><label class="form-label" for="edUser">کاربر</label>
                <select id="edUser" class="form-control"><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" id="notifyWrap" style="display:none"><label class="form-label" for="edNotify">متن اعلان (اختیاری)</label><textarea id="edNotify" class="form-control" rows="3" placeholder="مثلاً: استعلام جدید برای بررسی ارسال شد…"></textarea></div>
            <button type="button" class="btn btn-ghost" id="edDisconnect">قطع اتصال‌های این گره</button>
            <button class="btn btn-outline-danger" id="edDelete"><i data-lucide="trash-2"></i> حذف این گره</button>
        </div>
    </div>
</div>

<div style="display:flex;gap:10px;margin-top:12px;align-items:center;flex-wrap:wrap">
    <button class="btn btn-primary btn-lg" id="saveFlowBtn"><i data-lucide="save"></i> ذخیرهٔ گردش‌کار استعلام</button>
    <form method="post" style="display:inline" onsubmit="return confirm('به تنظیمات پیش‌فرض کارخانه برگردد؟')"><?= get_csrf_field() ?><input type="hidden" name="action" value="reset_quote_flow"><button type="submit" class="btn btn-ghost"><i data-lucide="rotate-ccw"></i> بازنشانی به پیش‌فرض</button></form>
    <span class="text-muted" style="font-size:10.5px">گره «ثبت استعلام» همیشه نقطهٔ آغاز است؛ آخرین گره را «اعلام به مشتری و فیکس» بگذارید.</span>
</div>
<form method="post" id="saveFlowForm" style="display:none"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_quote_flow"><input type="hidden" name="steps_json" id="stepsJson"></form>

<!-- حاشیهٔ سود پیش‌فرض + وضعیت نرخ ارز -->
<div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px">
    <div class="card">
        <div class="card-header"><h3 class="card-title">٪ حاشیهٔ سود پیش‌فرض استعلام</h3></div>
        <form method="post" style="padding:12px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_quote_margin">
            <input type="number" name="quote_default_margin_pct" class="form-control" min="0" max="100" step="0.5" dir="ltr" style="width:130px" value="<?= htmlspecialchars(quote_trim_num(sprintf('%.2f', $defaultMargin))) ?>">
            <span style="font-size:12px;color:var(--text-secondary)">٪ — مدیر فروش هنگام تأیید نهایی هر استعلام می‌تواند آن را کم/زیاد کند</span>
            <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="save"></i> ذخیره</button>
        </form>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">💱 نرخ مرجع امروز (تومان)</h3></div>
        <div style="padding:12px 14px;font-size:12px;line-height:2.1">
            <div>۱ دلار = <b dir="ltr"><?= number_format((float)$fx['USD'], 0, '.', '،') ?></b> تومان &nbsp;•&nbsp; ۱ یوان = <b dir="ltr"><?= number_format((float)$fx['CNY'], 0, '.', '،') ?></b> تومان</div>
            <div class="text-muted" style="font-size:10px"><?= htmlspecialchars(function_exists('fx_source_line') ? fx_source_line($pdo) : '') ?></div>
            <div class="text-muted" style="font-size:10.5px">نرخ‌ها هر ۳۰ دقیقه خودکار از «نرخ‌سنج» گرفته می‌شوند (خاموش/روشن و به‌روزرسانی فوری: «تنظیمات ← تارگت و کمیسیون ← نرخ لحظه‌ای»). هر سه ارز در همهٔ استعلام‌ها نمایش داده می‌شوند.</div>
        </div>
    </div>
</div>

<script src="assets/js/vendor/drawflow.min.js"></script>
<script>
(function () {
    'use strict';
    var STEPS = <?= json_encode(array_values($stepsForJs), JSON_UNESCAPED_UNICODE) ?>;
    var ROLES = <?= json_encode($rolesByCode, JSON_UNESCAPED_UNICODE) ?>;
    var USERS = <?= json_encode($usersById, JSON_UNESCAPED_UNICODE) ?>;
    var TYPE_META = {
        submit:   { label: 'ثبت استعلام',              icon: 'send',        color: 'qfn-type-submit'   },
        approval: { label: 'تأیید',                    icon: 'shield-check',color: 'qfn-type-approval' },
        costing:  { label: 'اعلام قیمت نت',            icon: 'calculator',  color: 'qfn-type-costing'  },
        pricing:  { label: 'تأیید نهایی + حاشیهٔ سود',  icon: 'percent',     color: 'qfn-type-pricing'  },
        finalize: { label: 'اعلام به مشتری و فیکس',    icon: 'badge-check', color: 'qfn-type-finalize' },
        notify:   { label: 'اعلان',                    icon: 'bell',        color: 'qfn-type-notify'   }
    };
    var canvas = document.getElementById('flowCanvas');
    var editor = null;
    var selectedId = null;

    function assigneeLabel(a) {
        a = a || { kind: 'owner' };
        if (a.kind === 'role') return 'مسئول: ' + (ROLES[a.role] || ('سمت «' + (a.role || '?') + '»'));
        if (a.kind === 'user') return 'مسئول: ' + (USERS[a.user_id] || ('کاربر #' + (a.user_id || '?')));
        return 'مسئول: ثبت‌کنندهٔ استعلام';
    }
    function stepToData(s, id) {
        return {
            stepId: id || ('step_' + Math.random().toString(36).slice(2, 9)),
            id: id || ('step_' + Math.random().toString(36).slice(2, 9)),
            stepType: s.type || 'approval',
            title: s.title || (TYPE_META[s.type] ? TYPE_META[s.type].label : 'مرحله'),
            assigneeKind: (s.assignee && s.assignee.kind) || 'owner',
            role: (s.assignee && s.assignee.role) || 'sales_manager',
            userId: (s.assignee && parseInt(s.assignee.user_id, 10)) || 0,
            notifyText: s.notify_text || ''
        };
    }
    function dataToAssignee(d) {
        if (d.assigneeKind === 'role') return { kind: 'role', role: d.role || 'sales_manager' };
        if (d.assigneeKind === 'user') return { kind: 'user', user_id: parseInt(d.userId, 10) || 0 };
        return { kind: 'owner' };
    }
    function nodeHtml(d) {
        var meta = TYPE_META[d.stepType] || TYPE_META.approval;
        var safeTitle = String(d.title || meta.label).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
        var safeAssignee = String(assigneeLabel(dataToAssignee(d))).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
        return '<div class="qfn-head"><div class="qfn-icon ' + meta.color + '"><i data-lucide="' + meta.icon + '"></i></div>'
            + '<div><div class="qfn-title">' + safeTitle + '</div><div class="qfn-meta">' + meta.label + '</div></div></div>'
            + '<div class="qfn-meta">' + safeAssignee + '</div>';
    }
    function refreshNode(id) {
        var nodeEl = canvas.querySelector('.drawflow-node[id="node-' + id + '"]');
        if (!nodeEl) return;
        var d = nodeData[id];
        var content = nodeEl.querySelector('.drawflow_content_node');
        if (!content) return;
        content.innerHTML = nodeHtml(d);
        if (window.lucide) lucide.createIcons();
        editor.updateConnectionNodes('node-' + id);
        bindPorts(nodeEl, d);
    }
    var nodeData = {};
    function addStepNode(s, x, y) {
        var d = stepToData(s, s.id || null);
        var meta = TYPE_META[d.stepType];
        var inputs = d.stepType === 'submit' ? 0 : 1;
        var outputs = d.stepType === 'finalize' ? 0 : 1;
        var nid = editor.addNode('step', inputs, outputs, x, y, 'qfn-' + d.stepType, d, nodeHtml(d));
        nodeData[nid] = d;
        bindPorts(canvas.querySelector('.drawflow-node[id="node-' + nid + '"]'), d);
        return nid;
    }
    function bindPorts(nodeEl, d) { /* ارتباط‌ها با پورت‌های پیش‌فرض Drawflow کار می‌کنند */ }
    function computeChain() {
        var data = (editor.export().drawflow || {}).Home;
        if (!data) return { ok: false, msg: 'بوم خالی است' };
        var nodes = [];
        for (var k in data.data) nodes.push(data.data[k]);
        if (nodes.length < 2) return { ok: false, msg: 'حداقل ۲ مرحله لازم است.' };
        var starts = nodes.filter(function (n) { return !n.inputs || Object.keys(n.inputs).length === 0; });
        if (starts.length !== 1) return { ok: false, msg: 'دقیقاً یک گرهٔ آغاز لازم است («ثبت استعلام»).' };
        if (starts[0].data.stepType !== 'submit') return { ok: false, msg: 'نقطهٔ آغاز باید «ثبت استعلام» باشد.' };
        var order = []; var seen = {}; var cur = starts[0]; var guard = 0;
        while (cur && guard++ < 50) {
            if (seen[cur.id]) return { ok: false, msg: 'اتصال حلقوی دیده شد.' };
            seen[cur.id] = true; order.push(cur);
            var outs = [];
            if (cur.outputs) { for (var p in cur.outputs) { var cs = cur.outputs[p].connections || []; for (var i = 0; i < cs.length; i++) outs.push(cs[i]); } }
            if (outs.length > 1) return { ok: false, msg: 'هر مرحله فقط یک خروجی داشته باشد.' };
            var nextId = outs.length ? outs[0].node : null;
            cur = nextId ? data.data[nextId] : null;
        }
        if (order.length !== nodes.length) return { ok: false, msg: 'همهٔ گره‌ها باید در یک زنجیرهٔ پیوسته باشند.' };
        return { ok: true, order: order, msg: '✅ زنجیرهٔ معتبر — ' + order.length + ' مرحله' };
    }
    function updateChainStatus() {
        var el = document.getElementById('chainStatus');
        var r = computeChain();
        el.textContent = r.msg;
        el.style.color = (r.ok ? '#15803d' : '#b45309');
        return r;
    }
    function renderEditor() {
        var empty = document.getElementById('editorEmpty');
        var form = document.getElementById('editorForm');
        if (!selectedId || !nodeData[selectedId]) { empty.style.display = 'block'; form.style.display = 'none'; return; }
        empty.style.display = 'none'; form.style.display = 'flex';
        var d = nodeData[selectedId];
        document.getElementById('edType').textContent = (TYPE_META[d.stepType] || {}).label || d.stepType;
        document.getElementById('edTitle').value = d.title || '';
        document.getElementById('edKind').value = d.assigneeKind;
        document.getElementById('edRole').value = d.role || 'sales_manager';
        document.getElementById('edUser').value = String(d.userId || 0);
        document.getElementById('edNotify').value = d.notifyText || '';
        document.getElementById('roleWrap').style.display = d.assigneeKind === 'role' ? 'block' : 'none';
        document.getElementById('userWrap').style.display = d.assigneeKind === 'user' ? 'block' : 'none';
        document.getElementById('notifyWrap').style.display = d.stepType === 'notify' ? 'block' : 'none';
    }
    function removeNode(id) {
        try { editor.removeNodeId('node-' + id); } catch (e) {}
        delete nodeData[id];
        if (selectedId === id) selectedId = null;
        renderEditor(); updateChainStatus();
    }

    editor = new Drawflow(canvas);
    if (editor.reroute !== undefined) editor.reroute = true;
    editor.start();

    // ساخت اولیه از گردش ذخیره‌شده
    var x = 30, y = 140, gap = 236;
    var prevNid = null;
    STEPS.forEach(function (s, i) {
        var nid = addStepNode(s, x + i * gap, y + (i % 2 ? 46 : 0));
        if (prevNid !== null) { try { editor.addConnection(prevNid, nid, 'output_1', 'input_1'); } catch (e) {} }
        prevNid = nid;
    });

    // رویدادها
    editor.on('nodeSelected', function (id) {
        selectedId = parseInt(id, 10);
        if (!nodeData[selectedId]) {
            var n = null;
            try { n = editor.getNodeFromId(selectedId); } catch (e) {}
            if (n && n.data) nodeData[selectedId] = n.data;
        }
        renderEditor();
    });
    editor.on('nodeUnselected', function () { selectedId = null; renderEditor(); });
    editor.on('nodeRemoved', function (id) { delete nodeData[parseInt(id, 10)]; if (selectedId === parseInt(id, 10)) selectedId = null; renderEditor(); updateChainStatus(); });
    ['connectionCreated', 'connectionRemoved', 'nodeCreated', 'nodeMoved'].forEach(function (ev) { editor.on(ev, updateChainStatus); });

    // پالت: کلیک و کشیدن
    var palette = document.querySelector('.flow-palette');
    palette.addEventListener('click', function (ev) {
        var item = ev.target.closest('[data-node-type]');
        if (item) addPaletteNode(item.getAttribute('data-node-type'));
    });
    palette.addEventListener('dragstart', function (ev) {
        var item = ev.target.closest('[data-node-type]');
        if (item) ev.dataTransfer.setData('text/plain', item.getAttribute('data-node-type'));
    });
    function addPaletteNode(type, x, y) {
        if (typeof TYPE_META[type] === 'undefined') return;
        var count = Object.keys(nodeData).length;
        var nid = addStepNode({ type: type }, x || (60 + (count % 5) * 60), y || (400 + Math.floor(count / 5) * 80));
        selectedId = nid; renderEditor(); updateChainStatus();
        if (window.lucide) lucide.createIcons();
    }
    canvas.addEventListener('dragover', function (ev) { ev.preventDefault(); });
    canvas.addEventListener('drop', function (ev) {
        ev.preventDefault();
        var type = ev.dataTransfer.getData('text/plain');
        var rect = canvas.getBoundingClientRect();
        var zoom = editor.zoom || 1;
        var x = (ev.clientX - rect.left) / zoom;
        var y = (ev.clientY - rect.top) / zoom;
        addPaletteNode(type, x, y);
    });

    // ویرایشگر
    document.getElementById('edTitle').addEventListener('input', function (ev) {
        if (!selectedId || !nodeData[selectedId]) return;
        nodeData[selectedId].title = ev.target.value;
        refreshNode(selectedId);
    });
    document.getElementById('edKind').addEventListener('change', function (ev) {
        if (!selectedId || !nodeData[selectedId]) return;
        nodeData[selectedId].assigneeKind = ev.target.value; renderEditor(); refreshNode(selectedId); updateChainStatus();
    });
    document.getElementById('edRole').addEventListener('change', function (ev) { if (selectedId && nodeData[selectedId]) { nodeData[selectedId].role = ev.target.value; refreshNode(selectedId); } });
    document.getElementById('edUser').addEventListener('change', function (ev) { if (selectedId && nodeData[selectedId]) { nodeData[selectedId].userId = parseInt(ev.target.value, 10) || 0; refreshNode(selectedId); } });
    document.getElementById('edNotify').addEventListener('input', function (ev) { if (selectedId && nodeData[selectedId]) nodeData[selectedId].notifyText = ev.target.value; });
    document.getElementById('edDisconnect').addEventListener('click', function () {
        if (!selectedId) return;
        var n = editor.getNodeFromId(selectedId);
        Object.keys(n.outputs || {}).forEach(function (port) {
            (n.outputs[port].connections || []).slice().forEach(function (c) { editor.removeSingleConnection(String(selectedId), String(c.node), port, c.output); });
        });
        Object.keys(n.inputs || {}).forEach(function (port) {
            (n.inputs[port].connections || []).slice().forEach(function (c) { editor.removeSingleConnection(String(c.node), String(selectedId), c.input, port); });
        });
        updateChainStatus();
    });
    document.getElementById('edDelete').addEventListener('click', function () { if (selectedId) removeNode(selectedId); });

    // زوم
    document.getElementById('zoomIn').addEventListener('click', function () { editor.zoom_in(); });
    document.getElementById('zoomOut').addEventListener('click', function () { editor.zoom_out(); });
    document.getElementById('zoomFit').addEventListener('click', function () { editor.zoom_reset(); });
    document.getElementById('autoLayout').addEventListener('click', function () {
        var r = computeChain();
        if (!r.ok) { alert('برای چیدمان خودکار، زنجیره باید معتبر باشد:\n' + r.msg); return; }
        var y = 140;
        r.order.forEach(function (n, i) {
            var px = 30 + i * 236, py = y + (i % 2 ? 46 : 0);
            try {
                editor.drawflow.drawflow.Home.data[n.id].pos_x = px;
                editor.drawflow.drawflow.Home.data[n.id].pos_y = py;
            } catch (e) {}
            var el = canvas.querySelector('.drawflow-node[id="node-' + n.id + '"]');
            if (el) { el.style.left = px + 'px'; el.style.top = py + 'px'; }
            try { editor.updateConnectionNodes('node-' + n.id); } catch (e) {}
        });
        updateChainStatus();
    });

    // ذخیره
    document.getElementById('saveFlowBtn').addEventListener('click', function () {
        var r = computeChain();
        if (!r.ok) { alert('❌ ذخیره نشد:\n' + r.msg); return; }
        var steps = r.order.map(function (n) {
            var d = n.data;
            var step = { id: d.stepId || ('step_' + n.id), type: d.stepType, title: d.title || TYPE_META[d.stepType].label, assignee: dataToAssignee(d) };
            if (d.stepType === 'notify' && d.notifyText) step.notify_text = d.notifyText;
            return step;
        });
        document.getElementById('stepsJson').value = JSON.stringify({ steps: steps });
        document.getElementById('saveFlowForm').submit();
    });

    updateChainStatus();
    if (window.lucide) lucide.createIcons();
})();
</script>
<?php include 'includes/footer.php'; ?>
