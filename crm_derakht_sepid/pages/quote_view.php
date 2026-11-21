<?php
/**
 * «جزئیات استعلام قیمت» — مشخصات، هزینهٔ نت، حاشیهٔ سود، فیکس، تاریخچه
 */
$pageTitle = 'استعلام قیمت';
require_login();
require_once __DIR__ . '/../includes/quotes.php';
ensure_quote_schema($pdo);

$me     = (int)($_SESSION['user_id'] ?? 0);
$canAll = is_strict_admin() || is_finance_officer();
$managed = managed_team_ids($pdo);
$isViewer = function_exists('access_mode') ? (access_mode('quotes') === 'viewer') : false;
$id = (int)($_GET['id'] ?? 0);

// ── خواندن استعلام + معامله/مشتری ──
$st = $pdo->prepare("SELECT q.*, d.title AS deal_title, d.product_name AS deal_product, d.owner_user_id AS deal_owner, d.team_id AS deal_team,
        d.stage_id AS deal_stage, st.name AS stage_name, c.name AS customer_name, c.mobile AS customer_mobile, c.phone AS customer_phone,
        u.full_name AS creator_name, fu.full_name AS fixer_name
    FROM crm_quotes q
    LEFT JOIN crm_deals d ON d.id = q.deal_id
    LEFT JOIN crm_deal_stages st ON st.id = d.stage_id
    LEFT JOIN crm_customers c ON c.id = q.customer_id
    LEFT JOIN users u ON u.id = q.created_by
    LEFT JOIN users fu ON fu.id = q.fixed_by
    WHERE q.id = ? LIMIT 1");
$st->execute([$id]);
$q = $st->fetch(PDO::FETCH_ASSOC);
if (!$q) { include 'includes/header.php'; echo '<div class="alert alert-danger">این استعلام پیدا نشد.</div>'; include 'includes/footer.php'; exit; }
$steps = quote_steps($q);
$idx   = (int)$q['current_step'];
$step  = $steps[$idx] ?? null;
$stype = $step['type'] ?? null;
$curCanAct = quote_user_can_act($pdo, $q, $me);

// ── دسترسی دیدن ──
$canSee = $canAll || (int)$q['created_by'] === $me || (int)($q['deal_owner'] ?? 0) === $me
    || (!empty($managed) && in_array((int)($q['deal_team'] ?? 0), $managed, true)) || $curCanAct;
if (!$canSee) { include 'includes/header.php'; echo '<div class="alert alert-danger">دسترسی ندارید.</div>'; include 'includes/footer.php'; exit; }
$isCreator = ((int)$q['created_by'] === $me);
$editableInfo = ($q['status'] === 'in_flow' && $stype === 'submit' && $curCanAct && !$isViewer);
$lines  = quote_cost_lines($pdo, $id);
$fxNow  = quote_fx_rates($pdo);
$rates  = quote_rates_for($pdo, $q);
$total  = (float)($q['total_cost_toman'] ?? 0);
$margin = $q['margin_pct'] !== null ? (float)$q['margin_pct'] : quote_default_margin($pdo);
$sell   = ($total > 0) ? quote_sell_toman($total, $margin) : 0.0;
$isFixed = ($q['status'] === 'fixed');
$fixedSell = (float)($q['fixed_sell_toman'] ?? 0);

// ── اکشن‌ها ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isViewer) {
    $action = (string)($_POST['action'] ?? '');
    $back = 'index.php?page=quote_view&id=' . $id;

    if ($action === 'save_info' && $editableInfo) {
        $title    = trim((string)($_POST['title'] ?? ''));
        $product  = trim((string)($_POST['product_name'] ?? ''));
        $specs    = trim((string)($_POST['specs'] ?? ''));
        $quantity = (float)str_replace([',', '٬', '،'], '', (string)($_POST['quantity'] ?? '0'));
        $unit     = trim((string)($_POST['unit'] ?? '')) ?: 'عدد';
        $hsCode   = trim((string)($_POST['hs_code'] ?? ''));
        $incoterm = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($_POST['incoterm'] ?? '')), 0, 8));
        if ($title !== '') {
            $st = $pdo->prepare("UPDATE crm_quotes SET title = ?, product_name = ?, specs = ?, quantity = ?, unit = ?, hs_code = ?, incoterm = ? WHERE id = ?");
            $st->execute([$title, $product ?: null, $specs ?: null, $quantity > 0 ? $quantity : null, $unit, $hsCode ?: null, $incoterm ?: null, $id]);
            showToast('مشخصات ذخیره شد.');
        } else showToast('عنوان الزامی است.', 'error');
        redirect($back);
    }

    if ($action === 'send_info' && $editableInfo) {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title !== '') {
            $st = $pdo->prepare("UPDATE crm_quotes SET title = ? WHERE id = ?");
            $st->execute([$title, $id]);
        }
        $r = quote_transition($pdo, $id, $me, 'submit', trim((string)($_POST['note'] ?? '')));
        showToast(!empty($r['ok']) ? 'استعلام برای مرحلهٔ بعد ارسال شد.' : ('خطا: ' . ($r['error'] ?? '')), !empty($r['ok']) ? 'success' : 'error');
        redirect($back);
    }

    if ($action === 'costing_save' && $stype === 'costing' && $curCanAct) {
        $amounts = []; $currencies = [];
        foreach (quote_components() as $code => $_label) {
            $amounts[$code]    = $_POST['amounts'][$code] ?? '';
            $currencies[$code] = $_POST['currencies'][$code] ?? 'USD';
        }
        $r = quote_save_costs($pdo, $id, $amounts, $currencies, true);
        if (!$r['saved']) { showToast('خطا در ذخیرهٔ قیمت نت.', 'error'); redirect($back); }
        if (!empty($r['missing'])) {
            showToast('نرخ تبدیل برای: ' . implode('، ', $r['missing']) . ' تنظیم نشده — مبلغ آن‌ها در جمع منظور نشد.', 'error');
        }
        // ارسال؟
        if (($_POST['mode'] ?? '') === 'send') {
            $r2 = quote_transition($pdo, $id, $me, 'approve', trim((string)($_POST['note'] ?? '')));
            showToast(!empty($r2['ok']) ? 'قیمت نت ثبت و برای مدیر فروش ارسال شد.' : ('خطا: ' . ($r2['error'] ?? '')), !empty($r2['ok']) ? 'success' : 'error');
        } else {
            showToast('قیمت نت ذخیره شد (پیش‌نویس).');
        }
        redirect($back);
    }

    if ($action === 'pricing_save' && $stype === 'pricing' && $curCanAct) {
        $marginIn = (float)str_replace([',', '٬', '،'], '', (string)($_POST['margin_pct'] ?? '25'));
        $marginIn = max(0, min(100, $marginIn));
        $st = $pdo->prepare("UPDATE crm_quotes SET margin_pct = ? WHERE id = ?");
        $st->execute([$marginIn, $id]);
        if (($_POST['mode'] ?? '') === 'send') {
            $r2 = quote_transition($pdo, $id, $me, 'approve', trim((string)($_POST['note'] ?? '')));
            showToast(!empty($r2['ok']) ? 'تأیید نهایی ثبت و برای کارشناس ارسال شد.' : ('خطا: ' . ($r2['error'] ?? '')), !empty($r2['ok']) ? 'success' : 'error');
        } else {
            showToast('حاشیهٔ سود ذخیره شد (پیش‌نویس).');
        }
        redirect($back);
    }

    if ($action === 'fix_now' && $stype === 'finalize' && $curCanAct) {
        $r = quote_transition($pdo, $id, $me, 'fix', trim((string)($_POST['note'] ?? '')));
        showToast(!empty($r['ok']) ? 'قیمت نهایی برای مشتری فیکس شد ✅' : ('خطا: ' . ($r['error'] ?? '')), !empty($r['ok']) ? 'success' : 'error');
        redirect($back);
    }

    if ($action === 'return_step' && $curCanAct) {
        $r = quote_transition($pdo, $id, $me, 'return', trim((string)($_POST['note'] ?? '')));
        showToast(!empty($r['ok']) ? 'به مرحلهٔ قبل برگشت.' : ('خطا: ' . ($r['error'] ?? '')), !empty($r['ok']) ? 'success' : 'error');
        redirect($back);
    }

    if ($action === 'cancel_quote' && ($isCreator || is_strict_admin()) && $q['status'] === 'in_flow') {
        $r = quote_transition($pdo, $id, $me, 'cancel', trim((string)($_POST['note'] ?? '')));
        showToast(!empty($r['ok']) ? 'استعلام لغو شد.' : 'خطا در لغو', !empty($r['ok']) ? 'success' : 'error');
        redirect($back);
    }
}
$lines  = quote_cost_lines($pdo, $id);
$rates  = quote_rates_for($pdo, $q);
$totals = quote_cost_total($lines, $rates);
$total  = (float)($q['total_cost_toman'] ?? $totals['toman']);
$sell   = ($total > 0) ? quote_sell_toman($total, $margin) : 0.0;
$history = quote_history($pdo, $id);

$money = function (?float $v, string $ccy): string {
    if ($v === null) return '—';
    return number_format($v, $ccy === 'IRR' ? 0 : 0, '.', '،');
};

include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-3 animate-in" style="flex-wrap:wrap;gap:10px">
    <div>
        <a href="index.php?page=quotes" class="text-muted" style="font-size:11px;text-decoration:none">← استعلام‌ها</a>
        <h2 style="margin:2px 0 0">🧾 استعلام #<?= (int)$id ?> <a class="btn btn-ghost btn-sm" href="index.php?page=quote_print&id=<?= (int)$id ?>" target="_blank" title="پیش‌فاکتور چاپی"><i data-lucide="printer"></i></a></h2>
        <div class="text-muted" style="font-size:11.5px">ثبت‌کننده: <?= htmlspecialchars((string)($q['creator_name'] ?? '')) ?><?php if (!empty($q['deal_title'])): ?> • معامله: <a href="index.php?page=crm_deals" style="text-decoration:none"><?= htmlspecialchars(mb_substr((string)$q['deal_title'], 0, 80)) ?></a><?php if (!empty($q['stage_name'])): ?> <span class="tag" style="background:#ede9fe;color:#5b21b6;padding:1px 8px;border-radius:6px;font-size:10px"><?= htmlspecialchars($q['stage_name']) ?></span><?php endif; ?><?php endif; ?><?php if (!empty($q['customer_name'])): ?> • مشتری: <b><?= htmlspecialchars($q['customer_name']) ?></b><?php endif; ?></div>
    </div>
    <?php if ($isFixed): ?><span class="quote-fixed-badge">✅ فیکس‌شده<?php if (!empty($q['fixed_at'])): ?> — <?= htmlspecialchars(substr((string)$q['fixed_at'], 0, 10)) ?><?php endif; ?></span>
    <?php elseif ($q['status'] === 'cancelled'): ?><span class="quote-cancel-badge">لغو شده</span><?php endif; ?>
</div>

<!-- استپر مرحلهٔ جاری -->
<div class="quote-stepper animate-in" dir="rtl">
    <?php foreach ($steps as $si => $s):
        $state = $isFixed ? 'done' : (($si < $idx) ? 'done' : (($si === $idx) ? 'current' : 'todo'));
        $icon = $state === 'done' ? 'check' : ($state === 'current' ? 'loader' : 'lock');
    ?>
        <div class="qstep qstep-<?= $state ?>">
            <span class="qstep-dot"><i data-lucide="<?= $icon ?>"></i></span>
            <span class="qstep-label"><?= htmlspecialchars((string)$s['title']) ?></span>
        </div>
        <?php if ($si < count($steps) - 1): ?><div class="qstep-line <?= ($si < $idx || $isFixed) ? 'done' : '' ?>"></div><?php endif; ?>
    <?php endforeach; ?>
</div>

<div class="quote-layout">
<div class="quote-main">

<!-- ۱) مشخصات استعلام -->
<div class="card animate-in">
    <div class="card-header d-flex justify-between align-center"><h3 class="card-title">📋 مشخصات استعلام (کالا / خدمات)</h3>
        <?php if ($editableInfo): ?><span class="text-muted" style="font-size:11px">پیش از ارسال قابل ویرایش است</span><?php endif; ?>
    </div>
    <form method="post" style="padding:12px 14px"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_info">
        <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="form-group"><label class="form-label">عنوان استعلام *</label><input name="title" class="form-control" value="<?= htmlspecialchars((string)$q['title']) ?>" <?= $editableInfo ? '' : 'readonly' ?> required></div>
            <div class="form-group"><label class="form-label">کالا / شرح</label><input name="product_name" class="form-control" value="<?= htmlspecialchars((string)($q['product_name'] ?? '')) ?>" <?= $editableInfo ? '' : 'readonly' ?> placeholder="نام کالا، کد فنی…"></div>
        </div>
        <div class="grid-2" style="display:grid;grid-template-columns:1fr 120px 120px;gap:10px">
            <div class="form-group"><label class="form-label">مشخصات فنی</label><textarea name="specs" class="form-control" rows="2" <?= $editableInfo ? '' : 'readonly' ?> placeholder="توان، ابعاد، وزن، برند، استاندارد…"><?= htmlspecialchars((string)($q['specs'] ?? '')) ?></textarea></div>
            <div class="form-group"><label class="form-label">مقدار</label><input name="quantity" type="number" min="0" step="any" class="form-control" dir="ltr" value="<?= htmlspecialchars(quote_trim_num($q['quantity'] ?? '')) ?>" <?= $editableInfo ? '' : 'readonly' ?>></div>
            <div class="form-group"><label class="form-label">واحد</label><input name="unit" class="form-control" value="<?= htmlspecialchars((string)($q['unit'] ?? 'عدد')) ?>" <?= $editableInfo ? '' : 'readonly' ?>></div>
        </div>
        <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px">
            <div class="form-group"><label class="form-label">HS Code</label><input name="hs_code" class="form-control" dir="ltr" value="<?= htmlspecialchars((string)($q['hs_code'] ?? '')) ?>" <?= $editableInfo ? '' : 'readonly' ?>></div>
            <div class="form-group"><label class="form-label">اینکوترم</label><input name="incoterm" class="form-control" dir="ltr" placeholder="FOB / CIF / EXW…" value="<?= htmlspecialchars((string)($q['incoterm'] ?? '')) ?>" <?= $editableInfo ? '' : 'readonly' ?>></div>
            <?php if ($editableInfo): ?><div class="form-group" style="display:flex;align-items:flex-end"><button type="submit" class="btn btn-ghost"><i data-lucide="save"></i> ذخیره</button></div><?php endif; ?>
        </div>
    </form>
    <?php if ($editableInfo): ?>
    <form method="post" style="padding:0 14px 12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;border-top:1px dashed var(--border);padding-top:10px"><?= get_csrf_field() ?><input type="hidden" name="action" value="send_info">
        <input name="note" class="form-control" style="max-width:420px" placeholder="یادداشت برای مدیر فروش (اختیاری)">
        <button type="submit" class="btn btn-primary"><i data-lucide="send"></i> ارسال برای تأیید</button>
    </form>
    <?php endif; ?>
</div>

<!-- ۲) هزینهٔ نت -->
<div class="card animate-in">
    <div class="card-header d-flex justify-between align-center"><h3 class="card-title">💱 هزینهٔ نت (قیمت خام)<?php if (!empty($q['fx_snapshot_json'])): ?><span class="text-muted" style="font-size:10.5px;font-weight:400"> — نرخ ثبت‌شده در لحظهٔ قیمت‌گذاری</span><?php endif; ?></h3>
        <span class="text-muted" style="font-size:10px"><?= htmlspecialchars(function_exists('fx_source_line') ? fx_source_line($pdo) : 'منبع نرخ: تنظیمات') ?></span>
    </div>
    <form method="post" id="costingForm"><?= get_csrf_field() ?><input type="hidden" name="action" value="costing_save">
    <div class="overflow-x-auto"><table class="table quote-cost-table">
        <thead><tr><th>#</th><th>جزء قیمت</th><th>مبلغ</th><th>ارز</th><th>معادل تومان</th><th>معادل دلار</th><th>معادل یوان</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $r => $l):
            $code = $l['component_code'];
            $isCosting = ($stype === 'costing' && $curCanAct && $q['status'] === 'in_flow' && !$isViewer);
        ?>
            <tr data-line="<?= htmlspecialchars($code) ?>">
                <td class="text-muted"><?= $r + 1 ?></td>
                <td><?= htmlspecialchars((string)$l['label']) ?></td>
                <td><?php if ($isCosting): ?><input type="text" dir="ltr" inputmode="decimal" name="amounts[<?= htmlspecialchars($code) ?>]" class="form-control q-amount" value="<?= htmlspecialchars($l['amount'] !== null ? quote_trim_num($l['amount']) : '') ?>" placeholder="0" style="width:130px"><?php else: ?><span dir="ltr"><?= htmlspecialchars($l['amount'] !== null ? quote_trim_num($l['amount']) : '—') ?></span><?php endif; ?></td>
                <td><?php if ($isCosting): ?><select name="currencies[<?= htmlspecialchars($code) ?>]" class="form-control q-currency" style="width:90px"><?php foreach (quote_currency_labels() as $ck => $cl): ?><option value="<?= $ck ?>" <?= strtoupper((string)($l['currency'] ?? 'USD')) === $ck ? 'selected' : '' ?>><?= $ck ?></option><?php endforeach; ?></select><?php else: ?><?= htmlspecialchars((string)($l['currency'] ?? '—')) ?><?php endif; ?></td>
                <td class="q-eq-toman" dir="ltr"><?= $l['amount'] ? number_format((float)quote_toman((float)$l['amount'], (string)$l['currency'], $rates) ?? 0, 0, '.', '،') : '—' ?></td>
                <td class="q-eq-usd" dir="ltr"><?= $l['amount'] ? (function() use ($l, $rates) { $t = quote_toman((float)$l['amount'], (string)$l['currency'], $rates); $v = $t !== null ? quote_from_toman($t, 'USD', $rates) : null; return $v === null ? '—' : number_format($v, 0, '.', '،'); })() : '—' ?></td>
                <td class="q-eq-cny" dir="ltr"><?= $l['amount'] ? (function() use ($l, $rates) { $t = quote_toman((float)$l['amount'], (string)$l['currency'], $rates); $v = $t !== null ? quote_from_toman($t, 'CNY', $rates) : null; return $v === null ? '—' : number_format($v, 0, '.', '،'); })() : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="q-total-row"><th colspan="4">جمع هزینهٔ نت</th><th class="q-total-toman" dir="ltr"><?= $total > 0 ? number_format($total, 0, '.', '،') : '—' ?></th><th class="q-total-usd" dir="ltr"><?= $total > 0 ? htmlspecialchars((string)(function() use ($total, $rates) { $v = quote_from_toman($total, 'USD', $rates); return $v === null ? '—' : number_format($v, 0, '.', '،'); })()) : '—' ?></th><th class="q-total-cny" dir="ltr"><?= $total > 0 ? htmlspecialchars((string)(function() use ($total, $rates) { $v = quote_from_toman($total, 'CNY', $rates); return $v === null ? '—' : number_format($v, 0, '.', '،'); })()) : '—' ?></th></tr>
        </tfoot>
    </table></div>
    <div style="padding:8px 14px;font-size:10.5px;color:var(--text-muted)">
        نرخ مرجع: ۱ دلار = <b dir="ltr"><?= number_format((float)$fxNow['USD'], 0, '.', '،') ?></b> تومان • ۱ یوان = <b dir="ltr"><?= number_format((float)$fxNow['CNY'], 0, '.', '،') ?></b> تومان • ۱ ریال = <b dir="ltr"><?= $fxNow['IRR'] ?></b> تومان<br><?= htmlspecialchars(function_exists('fx_source_line') ? fx_source_line($pdo) : 'منبع نرخ: تنظیمات') ?><?php if ((float)$fxNow['USD'] <= 0 || (float)$fxNow['CNY'] <= 0): ?> <span style="color:#b45309">⚠ نرخ USD/CNY تنظیم نشده — «تنظیمات ← تارگت و کمیسیون ← نرخ لحظه‌ای» را فعال کنید.</span><?php endif; ?>
    </div>
    <?php if ($stype === 'costing' && $curCanAct && $q['status'] === 'in_flow' && !$isViewer): ?>
    <div style="padding:10px 14px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;border-top:1px dashed var(--border)">
        <input name="note" class="form-control" style="max-width:360px" placeholder="یادداشت برای مدیر فروش (اختیاری)">
        <button type="submit" name="mode" value="draft" class="btn btn-ghost"><i data-lucide="save"></i> ذخیرهٔ پیش‌نویس</button>
        <button type="submit" name="mode" value="send" class="btn btn-primary"><i data-lucide="send-horizontal"></i> ثبت قیمت نت و ارسال برای مدیر فروش</button>
        <button type="button" class="btn btn-outline-danger" onclick="document.getElementById('returnForm').style.display=document.getElementById('returnForm').style.display==='none'?'flex':'none'"><i data-lucide="undo-2"></i> برگشت به مرحلهٔ قبل</button>
    </div>
    <?php endif; ?>
    </form>
</div>
<?php if ($curCanAct && $q['status'] === 'in_flow' && $idx > 0 && !$isViewer): ?>
<form method="post" id="returnForm" style="display:none;padding:10px 14px;margin:0 0 6px;align-items:center;gap:10px;flex-wrap:wrap" class="card"><?= get_csrf_field() ?><input type="hidden" name="action" value="return_step">
    <span style="font-size:12px;color:var(--text-secondary)">↩ برگشت به مرحلهٔ قبل + یادداشت:</span>
    <input name="note" class="form-control" style="max-width:380px" placeholder="دلیل برگشت (مثلاً: مشخصات کالا ناقص است)…">
    <button type="submit" class="btn btn-outline-danger btn-sm">ثبت برگشت</button>
</form>
<?php endif; ?>

<!-- ۳) تأیید نهایی + حاشیهٔ سود (مدیر فروش) -->
<?php
$pricingIndex = null;
foreach ($steps as $si => $s) { if ($s['type'] === 'pricing') { $pricingIndex = $si; break; } }
$finalizeIndex = null;
foreach ($steps as $si => $s) { if ($s['type'] === 'finalize') { $finalizeIndex = $si; break; } }
$showPricing = ($pricingIndex !== null) && ($isFixed || $idx >= $pricingIndex);
$pricingEditable = $showPricing && !$isFixed && $q['status'] === 'in_flow' && $stype === 'pricing' && $curCanAct && !$isViewer;
?>
<?php if ($showPricing): ?>
<div class="card animate-in" id="pricingCard">
    <div class="card-header d-flex justify-between align-center"><h3 class="card-title">٪ تأیید نهایی — حاشیهٔ سود روی قیمت نت</h3>
        <?php if (!$pricingEditable && !$isFixed): ?><span class="text-muted" style="font-size:11px">فقط مدیر فروش این مرحله را می‌بیند/تأیید می‌کند</span><?php endif; ?>
    </div>
    <form method="post" style="padding:12px 14px"><?= get_csrf_field() ?><input type="hidden" name="action" value="pricing_save">
        <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0"><label class="form-label">جمع هزینهٔ نت</label><div style="font-size:20px;font-weight:800" dir="ltr"><?= number_format($total, 0, '.', '،') ?> <small style="font-size:11px">تومان</small></div></div>
            <div class="form-group" style="margin:0;max-width:150px"><label class="form-label">حاشیهٔ سود (٪)</label>
                <input type="number" name="margin_pct" id="marginInput" class="form-control" min="0" max="100" step="0.5" dir="ltr" value="<?= htmlspecialchars(quote_trim_num(sprintf('%.2f', $margin))) ?>" <?= $pricingEditable ? '' : 'readonly' ?>>
            </div>
            <div class="form-group" style="margin:0"><label class="form-label">سود این معامله</label><div style="font-size:16px;font-weight:800;color:#0f766e" dir="ltr" id="profitToman"><?= number_format($total * $margin / 100, 0, '.', '،') ?> <small>تومان</small></div></div>
        </div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:10px">
            <div class="price-chip"><span>قیمت نهایی (تومان)</span><b dir="ltr" id="sellToman"><?= number_format($sell, 0, '.', '،') ?></b></div>
            <div class="price-chip"><span>قیمت نهایی (دلار)</span><b dir="ltr" id="sellUsd"><?= ($v = quote_from_toman($sell, 'USD', $rates)) === null ? '—' : number_format($v, 0, '.', '،') ?></b></div>
            <div class="price-chip"><span>قیمت نهایی (یوان)</span><b dir="ltr" id="sellCny"><?= ($v = quote_from_toman($sell, 'CNY', $rates)) === null ? '—' : number_format($v, 0, '.', '،') ?></b></div>
            <div class="price-chip price-chip-rial"><span>معادل ریالی</span><b dir="ltr" id="sellIrr"><?= ($v = quote_from_toman($sell, 'IRR', $rates)) === null ? '—' : number_format($v, 0, '.', '،') ?></b></div>
        </div>
        <?php if ($pricingEditable): ?>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px;border-top:1px dashed var(--border);padding-top:10px">
            <input name="note" class="form-control" style="max-width:360px" placeholder="یادداشت برای کارشناس (اختیاری)">
            <button type="submit" name="mode" value="draft" class="btn btn-ghost"><i data-lucide="save"></i> ذخیرهٔ پیش‌نویس</button>
            <button type="submit" name="mode" value="send" class="btn btn-success"><i data-lucide="badge-check"></i> تأیید نهایی و ارسال برای کارشناس</button>
        </div>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<!-- ۴) اعلام به مشتری + فیکس -->
<?php
$showFinalize = $isFixed || ($finalizeIndex !== null && $idx >= $finalizeIndex);
$finalizeEditable = $showFinalize && !$isFixed && $q['status'] === 'in_flow' && $stype === 'finalize' && $curCanAct && !$isViewer;
$finalToman = $isFixed ? $fixedSell : $sell;
?>
<?php if ($showFinalize): ?>
<div class="card animate-in quote-final-card">
    <div class="card-header"><h3 class="card-title">📣 اعلام به مشتری<?php if ($isFixed): ?> — ✅ فیکس شد<?php endif; ?></h3></div>
    <div style="padding:14px">
        <?php if ($isFixed): ?>
        <div class="alert alert-success" style="margin:0 0 10px"><b>قیمت نهایی فیکس شد</b><?php if (!empty($q['fixed_at'])): ?> در <?= htmlspecialchars(substr((string)$q['fixed_at'], 0, 16)) ?><?php endif; ?><?php if (!empty($q['fixer_name'])): ?> توسط <?= htmlspecialchars((string)$q['fixer_name']) ?><?php endif; ?>. مبنای محاسبهٔ پورسانت: <b dir="ltr"><?= number_format($fixedSell, 0, '.', '،') ?></b> تومان (مبلغ معامله در «پایپ‌لاین واردات» همین عدد شد).</div>
        <?php endif; ?>
        <div style="display:flex;gap:14px;flex-wrap:wrap">
            <div class="price-chip price-chip-lg"><span>قیمت نهایی (تومان)</span><b dir="ltr"><?= number_format($finalToman, 0, '.', '،') ?></b></div>
            <div class="price-chip price-chip-lg"><span>قیمت نهایی (دلار)</span><b dir="ltr"><?= ($v = quote_from_toman($finalToman, 'USD', $rates)) === null ? '—' : number_format($v, 0, '.', '،') ?></b></div>
            <div class="price-chip price-chip-lg"><span>قیمت نهایی (یوان)</span><b dir="ltr"><?= ($v = quote_from_toman($finalToman, 'CNY', $rates)) === null ? '—' : number_format($v, 0, '.', '،') ?></b></div>
        </div>
        <p class="text-muted" style="font-size:11px;margin:10px 2px 0">کارشناسی که این قیمت را به مشتری اعلام می‌کند، باید <b>همان عدد را فیکس کند</b>؛ پورسانت او از سود همین فیکس محاسبه می‌شود (موتور کمیسیون — پله‌های سند نهایی).</p>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
            <?php if ($finalizeEditable): ?>
            <form method="post" onsubmit="return confirm('قیمت برای مشتری فیکس و مبلغ معامله در پایپ‌لاین به‌روز شود؟')"><?= get_csrf_field() ?><input type="hidden" name="action" value="fix_now">
                <input type="hidden" name="note" value="فیکس از صفحهٔ استعلام">
                <button type="submit" class="btn btn-success btn-lg"><i data-lucide="badge-check"></i> اعلام به مشتری و فیکس قیمت ✅</button>
            </form>
            <?php endif; ?>
            <a class="btn btn-outline-primary" href="index.php?page=quote_print&id=<?= (int)$id ?>" target="_blank"><i data-lucide="printer"></i> چاپ پیش‌فاکتور</a>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /quote-main -->

<!-- ستون کناری: تاریخچه + معامله -->
<aside class="quote-side">
    <div class="card animate-in">
        <div class="card-header"><h3 class="card-title">🕘 تاریخچه</h3></div>
        <div style="padding:10px 14px;max-height:60vh;overflow:auto">
            <?php if (!$history): ?><p class="text-muted" style="font-size:12px">هنوز رویدادی ثبت نشده.</p><?php endif; ?>
            <?php foreach (array_reverse($history) as $h):
                $actMap = ['create' => '🆕 ایجاد', 'submit' => '📤 ارسال', 'approve' => '✅ تأیید', 'return' => '↩️ برگشت', 'fix' => '📣 فیکس', 'cancel' => '✖️ لغو', 'notify' => '🔔 اعلان'];
                $lbl = $actMap[$h['action']] ?? $h['action'];
                $jt = (function_exists('to_jalali') ? to_jalali(substr((string)$h['created_at'], 0, 10)) : substr((string)$h['created_at'], 0, 10)) . ' ' . substr((string)$h['created_at'], 11, 5);
            ?>
                <div style="border-right:2px solid var(--border);padding:2px 12px 10px;position:relative">
                    <div style="position:absolute;right:-5px;top:5px;width:8px;height:8px;border-radius:50%;background:var(--primary,#308e89)"></div>
                    <div style="font-size:12px;font-weight:700"><?= $lbl ?> <span class="text-muted" style="font-weight:400">— <?= htmlspecialchars((string)($h['actor_name'] ?? 'سیستم')) ?></span></div>
                    <div class="text-muted" style="font-size:10px" dir="ltr"><?= htmlspecialchars($jt) ?></div>
                    <?php if (!empty($h['note'])): ?><div style="font-size:11px;margin-top:2px"><?= nl2br(htmlspecialchars((string)$h['note'])) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($q['status'] === 'in_flow' && ($isCreator || is_strict_admin()) && !$isViewer): ?>
    <div class="card animate-in" style="margin-top:12px">
        <div class="card-header"><h3 class="card-title" style="color:#b91c1c">لغو استعلام</h3></div>
        <form method="post" style="padding:10px 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('این استعلام لغو شود؟')"><?= get_csrf_field() ?><input type="hidden" name="action" value="cancel_quote">
            <input name="note" class="form-control" placeholder="دلیل لغو (اختیاری)" style="flex:1;min-width:140px">
            <button type="submit" class="btn btn-outline-danger btn-sm">لغو</button>
        </form>
    </div>
    <?php endif; ?>
</aside>
</div><!-- /quote-layout -->

<script>
/* محاسبهٔ زندهٔ معادل‌های ارزی و جمع (از نرخ‌های ثبت/امروز) */
(function () {
    var FX = <?= json_encode($rates, JSON_UNESCAPED_UNICODE) ?>;
    function parseNum(s) { s = String(s == null ? '' : s).replace(/[,،٬\s]/g, ''); var n = parseFloat(s); return isNaN(n) ? 0 : n; }
    function fmt(n) { if (!isFinite(n)) return '—'; return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '،'); }
    function toman(amount, cur) { var r = FX[cur]; if (!r || r <= 0) return null; return amount * r; }
    function fromToman(t, cur) { var r = FX[cur]; if (!r || r <= 0) return null; return t / r; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('tr[data-line]'));
    function recalc() {
        var sum = 0, missing = {};
        rows.forEach(function (row) {
            var amountEl = row.querySelector('.q-amount');
            var curEl = row.querySelector('.q-currency');
            var amount = amountEl ? parseNum(amountEl.value) : parseNum((row.querySelector('span') || {}).textContent || '0');
            var cur = curEl ? curEl.value : null;
            if (!curEl) {
                var cells = row.querySelectorAll('td');
                // حالت خواندنی: ارز از ستون ۴ خوانده می‌شود
                cur = (cells[3] ? cells[3].textContent : 'USD').trim().toUpperCase().split(' ')[0];
            }
            var t = amount > 0 ? toman(amount, cur) : 0;
            if (amount > 0 && t === null) missing[cur] = true;
            if (t) sum += t;
            var cTom = row.querySelector('.q-eq-toman'), cUsd = row.querySelector('.q-eq-usd'), cCny = row.querySelector('.q-eq-cny');
            if (cTom) cTom.textContent = (t !== null && amount > 0) ? fmt(t) : '—';
            if (cUsd) { var u = (t !== null) ? fromToman(t, 'USD') : null; cUsd.textContent = (u !== null && amount > 0) ? fmt(u) : '—'; }
            if (cCny) { var c = (t !== null) ? fromToman(t, 'CNY') : null; cCny.textContent = (c !== null && amount > 0) ? fmt(c) : '—'; }
        });
        var totalEl = document.querySelector('.q-total-toman');
        var totalUsd = document.querySelector('.q-total-usd');
        var totalCny = document.querySelector('.q-total-cny');
        if (totalEl) totalEl.textContent = sum > 0 ? fmt(sum) : '—';
        if (totalUsd) { var u = fromToman(sum, 'USD'); totalUsd.textContent = u !== null ? fmt(u) : '—'; }
        if (totalCny) { var c = fromToman(sum, 'CNY'); totalCny.textContent = c !== null ? fmt(c) : '—'; }
        var marginEl = document.getElementById('marginInput');
        var m = marginEl ? parseNum(marginEl.value) : <?= (float)$margin ?>;
        document.getElementById('profitToman') && (document.getElementById('profitToman').innerHTML = fmt(sum * m / 100) + ' <small>تومان</small>');
        var sell = sum * (1 + m / 100);
        var setSell = function (id, val, unit) { var el = document.getElementById(id); if (el) el.textContent = val !== null ? fmt(val) : '—'; };
        setSell('sellToman', sell); setSell('sellUsd', fromToman(sell, 'USD')); setSell('sellCny', fromToman(sell, 'CNY')); setSell('sellIrr', fromToman(sell, 'IRR'));
    }
    document.addEventListener('input', function (e) { if (e.target.closest('#costingForm') || e.target.id === 'marginInput') recalc(); });
    recalc();
})();
</script>
<?php include 'includes/footer.php'; ?>
