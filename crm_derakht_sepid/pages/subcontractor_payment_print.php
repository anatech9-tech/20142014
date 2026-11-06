<?php
/**
 * §11.1: فرم واریزی پیمانکاران — تجمیعی (همه) یا تفکیکی (یک پیمانکار) — A4
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/salary_calc.php';
require_once __DIR__ . '/../includes/subcontractor_link.php';
require_login();

$ws_id = $_SESSION['workshop_id'] ?? 0;
$current_j = gregorian_to_jalali(date('Y'), date('m'), date('d'));

$sel_year = (int)($_GET['year'] ?? $current_j[0]);
$sel_month = (int)($_GET['month'] ?? $current_j[1]);
[$g_start, $g_end] = jalali_month_bounds($sel_year, $sel_month);
$month_label = jalali_month_name($sel_month) . ' ' . $sel_year;
$day_before = date('Y-m-d', strtotime($g_start . ' -1 day'));

// تابع sub_balance_upto در includes/subcontractor_link.php تعریف شده است
// (نسخهٔ قدیمیِ همین تابع که قبلاً اینجا کپی شده بود، حذف شد — هرگز اجرا نمی‌شد)

// پیمانکاران این کارگاه (تعریف‌شده در دوره یا دارای سابقه — فعال)
$st = $pdo->prepare("SELECT * FROM subcontractors WHERE workshop_id = ? AND status = 'active' ORDER BY name");
$st->execute([$ws_id]);
$subs = $st->fetchAll();

$mode = $_GET['mode'] ?? 'all';
$sel_sub_id = (int)($_GET['sub_id'] ?? 0);
$rows = [];
$single = null;     // داده پیمانکارِ تفکیکی
$single_claims = ['face' => [], 'daily' => []];
$single_pays = [];
$single_personnel = null;

if ($mode === 'sub' && $sel_sub_id > 0) {
    foreach ($subs as $s) { if ((int)$s['id'] === $sel_sub_id) { $single = $s; break; } }
    if ($single) {
        $sid = $sel_sub_id;
        // حساب قبلی (مانده تا قبل از این ماه)
        $prev = sub_balance_upto($pdo, $sid, $day_before)['balance'];
        // مطالبات ماه — صورت‌وضعیت/خوراک
        $stx = $pdo->prepare("SELECT * FROM subcontractor_face_items WHERE subcontractor_id = ? AND date BETWEEN ? AND ? ORDER BY date, id");
        $stx->execute([$sid, $g_start, $g_end]);
        $single_claims['face'] = $stx->fetchAll();
        // مطالبات ماه — ثبت‌های روزانه
        $stx = $pdo->prepare("SELECT e.*, (SELECT COALESCE(SUM(i.total_price),0) FROM subcontractor_daily_entry_items i WHERE i.entry_id = e.id) AS total FROM subcontractor_daily_entries e WHERE e.subcontractor_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.shift, e.id");
        $stx->execute([$sid, $g_start, $g_end]);
        $single_claims['daily'] = $stx->fetchAll();
        // اقلام هر ثبت روزانه (خدمت + تعداد + قیمت واحد) برای نمایش در ستون «اقلام»
        if ($single_claims['daily']) {
            $entry_ids = array_map('intval', array_column($single_claims['daily'], 'id'));
            $ph = implode(',', array_fill(0, count($entry_ids), '?'));
            $sti = $pdo->prepare("SELECT i.entry_id, i.quantity, i.unit_price_snapshot, i.total_price, s.service_name, s.unit_name
                                  FROM subcontractor_daily_entry_items i
                                  JOIN subcontractor_services s ON s.id = i.service_id
                                  WHERE i.entry_id IN ($ph) ORDER BY i.id");
            $sti->execute($entry_ids);
            $items_by_entry = [];
            foreach ($sti->fetchAll() as $ii) $items_by_entry[$ii['entry_id']][] = $ii;
            foreach ($single_claims['daily'] as &$__e) $__e['items'] = $items_by_entry[$__e['id']] ?? [];
            unset($__e);
        }
        // پرداخت‌های ماه
        $stx = $pdo->prepare("SELECT * FROM subcontractor_payments WHERE subcontractor_id = ? AND payment_date BETWEEN ? AND ? ORDER BY payment_date, id");
        $stx->execute([$sid, $g_start, $g_end]);
        $single_pays = $stx->fetchAll();
        $m_face = array_sum(array_map(fn($f) => (float)$f['amount'], $single_claims['face']));
        $m_daily = array_sum(array_map(fn($e) => (float)$e['total'], $single_claims['daily']));
        $single_personnel = subcontractor_linked_person_period($pdo, subcontractor_linked_person($pdo, $sid), $g_start, $g_end);
        $m_personnel = (float)$single_personnel['net'];
        $m_paid = array_sum(array_map(fn($p) => (float)$p['amount'], $single_pays));
        $rows = [['prev' => $prev, 'services' => $m_face + $m_daily, 'personnel' => $m_personnel, 'claims' => $m_face + $m_daily + $m_personnel, 'paid' => $m_paid, 'balance' => $prev + $m_face + $m_daily + $m_personnel - $m_paid]];
    }
} else {
    // ── حالت تجمیعی: ردیفی برای هر پیمانکار ──
    foreach ($subs as $s) {
        $sid = (int)$s['id'];
        $prev = sub_balance_upto($pdo, $sid, $day_before)['balance'];
        $stx = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM subcontractor_face_items WHERE subcontractor_id = ? AND date BETWEEN ? AND ?");
        $stx->execute([$sid, $g_start, $g_end]); $m_face = (float)$stx->fetchColumn();
        $stx = $pdo->prepare("SELECT COALESCE(SUM(i.total_price),0) FROM subcontractor_daily_entry_items i JOIN subcontractor_daily_entries e ON i.entry_id = e.id WHERE e.subcontractor_id = ? AND e.entry_date BETWEEN ? AND ?");
        $stx->execute([$sid, $g_start, $g_end]); $m_daily = (float)$stx->fetchColumn();
        $stx = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM subcontractor_payments WHERE subcontractor_id = ? AND payment_date BETWEEN ? AND ?");
        $stx->execute([$sid, $g_start, $g_end]); $m_paid = (float)$stx->fetchColumn();
        $personnel_period = subcontractor_linked_person_period($pdo, subcontractor_linked_person($pdo, $sid), $g_start, $g_end);
        $m_personnel = (float)$personnel_period['net'];
        $rows[] = [
            'id' => $sid, 'name' => $s['name'], 'iban' => $s['iban'] ?: '-',
            'prev' => $prev, 'services' => $m_face + $m_daily, 'personnel' => $m_personnel,
            'linked_name' => $personnel_period['linked'] ? $personnel_period['name'] : '',
            'claims' => $m_face + $m_daily + $m_personnel, 'paid' => $m_paid,
            'balance' => $prev + $m_face + $m_daily + $m_personnel - $m_paid,
        ];
    }
}

$logo_path = $_SESSION['workshop_logo'] ?? '';
if (!$logo_path || !file_exists(__DIR__ . '/../' . $logo_path)) $logo_path = 'uploads/logos/logo.png';
$form_title = ($mode === 'sub' && $single)
    ? (t('فرم واریزی پیمانکار') . ' — «' . $single['name'] . '»')
    : t('فرم واریزی پیمانکاران (تجمیعی)');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $form_title ?> - <?= $month_label ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:<?= $lang_dir ?>; background:#f0f2f5; color:#1a1a2e; min-height:100vh; }
        .page { max-width:1000px; margin:20px auto; background:white; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow:hidden; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:linear-gradient(135deg,#8b5cf6,#6d28d9); color:white; flex-wrap:wrap; gap:8px; }
        .top-bar h1 { font-size:16px; font-weight:700; }
        .top-bar a { color:white; text-decoration:none; background:rgba(255,255,255,0.2); padding:5px 12px; border-radius:8px; font-size:12px; }
        .content { padding:20px; }
        .print-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #333; padding-bottom:12px; margin-bottom:16px; }
        .print-header img { height:60px; }
        .print-title { font-size:18px; font-weight:700; text-align:center; }
        .print-meta { font-size:12px; color:#666; text-align:right; }
        .filter-bar { padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .filter-bar select { padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:inherit; font-size:13px; max-width:220px; }
        .print-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:12px; }
        .print-table th { background:#f1f5f9; padding:8px 4px; border:1px solid #ccc; text-align:center; font-weight:600; }
        .print-table td { padding:6px 4px; border:1px solid #ddd; text-align:center; }
        .print-table .total-row { background:#e2e8f0; font-weight:700; }
        .btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-family:inherit; font-size:12px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
        .btn-print { background:linear-gradient(135deg,#8b5cf6,#6d28d9); color:white; }
        .summary-cards { display:flex; gap:12px; flex-wrap:wrap; margin:14px 0; }
        .summary-cards .sc { flex:1; min-width:150px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; text-align:center; }
        .summary-cards .sc .l { font-size:11px; color:#64748b; margin-bottom:6px; }
        .summary-cards .sc .v { font-size:16px; font-weight:800; }
        h4.sec { font-size:14px; margin:18px 0 6px; color:#4c1d95; border-right:3px solid #8b5cf6; padding-right:8px; }
        @media print {
            * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
            html, body { margin:0; padding:0; background:white; width:100%; height:100%; }
            .page { box-shadow:none; border-radius:0; margin:0; max-width:100%; width:100%; }
            .top-bar, .filter-bar, .action-bar { display:none !important; }
            .print-header { border-bottom:1px solid #000; padding:4px 6px !important; }
            .print-header img { height:40px !important; }
            .print-title { font-size:13px !important; }
            .print-table { font-size:9px !important; }
            .print-table th, .print-table td { padding:3px 2px !important; font-size:9px !important; }
            .summary-cards .sc { padding:6px; }
            .summary-cards .sc .v { font-size:12px; }
            .summary-cards .sc .l { font-size:9px; }
            h4.sec { font-size:11px; margin:10px 0 4px; }
            @page { size:A4; margin:8mm; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <h1>🧾 <?= t('فرم') ?> <?= t('واریزی') ?> <?= t('nav_subcontractors') ?></h1>
        <a href="javascript:window.close()"><?= t('jdp_close') ?></a>
    </div>
    <div class="content">
        <div class="filter-bar">
            <label style="font-size:13px;font-weight:600"><?= t('rep_year') ?>:</label>
            <select id="formYear" onchange="updateUrl()">
                <?php for ($y = $current_j[0]; $y >= $current_j[0] - 2; $y--): ?>
                <option value="<?= $y ?>" <?= $y == $sel_year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <label style="font-size:13px;font-weight:600"><?= t('rep_month') ?>:</label>
            <select id="formMonth" onchange="updateUrl()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m == $sel_month ? 'selected' : '' ?>><?= jalali_month_name($m) ?></option>
                <?php endfor; ?>
            </select>
            <label style="font-size:13px;font-weight:600"><?= t('پیمانکار') ?>:</label>
            <select id="formSub" onchange="updateUrl()">
                <option value="all" <?= $mode === 'all' ? 'selected' : '' ?>><?= t('همه') ?> <?= t('nav_subcontractors') ?> <?= t('(تجمیعی)') ?></option>
                <?php foreach ($subs as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $mode === 'sub' && $sel_sub_id === (int)$s['id'] ? 'selected' : '' ?>><?= $s['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="print-header">
            <div class="print-meta">
                <div style="font-size:14px;font-weight:700"><?= to_jalali(date('Y-m-d')) ?></div>
                <div style="font-size:11px;margin-top:4px"><?= date('H:i') ?></div>
                <div style="font-size:12px;margin-top:4px"><?= $month_label ?></div>
            </div>
            <div class="print-title"><?= $form_title ?></div>
            <div>
                <?php if (file_exists(__DIR__ . '/../' . $logo_path)): ?><img src="<?= $logo_path ?>" alt="logo"><?php endif; ?>
            </div>
        </div>

        <?php if ($mode === 'sub' && $single): ?>
        <!-- ═══ حالت تفکیکی: یک پیمانکار ═══ -->
        <?php $sm = $rows[0]; ?>
        <p style="font-size:13px;margin-bottom:4px"><?= t('پیمانکار: ') ?><strong><?= $single['name'] ?></strong> — <?= t('per_iban') ?>: <strong><?= $single['iban'] ?: '—' ?></strong></p>
        <div class="summary-cards">
            <div class="sc"><div class="l"><?= t('حساب') ?> <?= t('قبلی') ?> (<?= t('pet_balance') ?> <?= t('lea_to') ?> <?= to_jalali($day_before) ?>)</div><div class="v"><?= number_format($sm['prev']) ?></div></div>
            <div class="sc"><div class="l"><?= t('خدمات') ?> <?= t('این') ?> <?= t('rep_month') ?></div><div class="v" style="color:#7c3aed"><?= number_format($sm['services'] ?? 0) ?></div></div>
            <div class="sc"><div class="l"><?= t('کارکرد') ?> <?= t('ساعتیِ') ?> <?= t('متصل') ?></div><div class="v" style="color:#6d28d9"><?= number_format($sm['personnel'] ?? 0) ?></div></div>
            <div class="sc"><div class="l"><?= t('rep_total') ?> <?= t('قابل پرداخت') ?> <?= t('این') ?> <?= t('rep_month') ?></div><div class="v" style="color:#7c3aed"><?= number_format($sm['claims']) ?></div></div>
            <div class="sc"><div class="l"><?= t('پرداختی') ?> <?= t('این') ?> <?= t('rep_month') ?></div><div class="v" style="color:#dc2626"><?= number_format($sm['paid']) ?></div></div>
            <div class="sc"><div class="l"><?= t('pet_balance') ?> <?= t('نهایی') ?></div><div class="v" style="color:#16a34a"><?= number_format($sm['balance']) ?></div></div>
        </div>

        <h4 class="sec">📅 <?= t('مطالبات') ?> <?= t('rep_month') ?> — <?= t('ثبت') ?>‌<?= t('های') ?> <?= t('sal_daily') ?> (<?= count($single_claims['daily']) ?>)</h4>
        <table class="print-table">
            <thead><tr><th style="width:35px"><?= t('ردیف') ?></th><th><?= t('wh_date') ?></th><th><?= t('wh_shift') ?></th><th><?= t('اقلام') ?></th><th><?= t('مبلغ') ?></th></tr></thead>
            <tbody>
                <?php $rn = 0; foreach ($single_claims['daily'] as $e): $rn++; ?>
                <tr>
                    <td><?= $rn ?></td>
                    <td><?= to_jalali($e['entry_date']) ?></td>
                    <td><?= $e['shift'] === 'night' ? '🌙 ' . t('شب') : '☀️ ' . t('روز') ?></td>
                    <td style="text-align:right">
                        <?php if (!empty($e['items'])): ?>
                            <?php foreach ($e['items'] as $ii): ?>
                            <div><?= htmlspecialchars($ii['service_name'], ENT_QUOTES, 'UTF-8') ?>: <strong><?= rtrim(rtrim(number_format((float)$ii['quantity'], 2, '.', ''), '0'), '.') ?></strong> <?= htmlspecialchars($ii['unit_name'], ENT_QUOTES, 'UTF-8') ?> × <?= number_format((float)$ii['unit_price_snapshot']) ?> = <?= number_format((float)$ii['total_price']) ?></div>
                            <?php endforeach; ?>
                            <?php if (!empty($e['description'])): ?><div style="font-size:10px;color:#666"><?= htmlspecialchars($e['description'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                        <?php else: ?>
                            <?= $e['description'] ? htmlspecialchars($e['description'], ENT_QUOTES, 'UTF-8') : '—' ?>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((float)$e['total']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($single_claims['daily'])): ?><tr><td colspan="5" style="color:#999"><?= t('ثبتی نیست') ?></td></tr><?php endif; ?>
            </tbody>
        </table>

        <?php if ($single_personnel && $single_personnel['linked']): ?>
        <h4 class="sec">⏱ <?= t('کارکرد ساعتی') ?> <?= t('nav_personnel') ?> <?= t('متصل') ?> — <?= htmlspecialchars($single_personnel['name']) ?></h4>
        <div class="summary-cards">
            <div class="sc"><div class="l"><?= t('nav_salary') ?> <?= t('کارکرد ساعتی') ?><?= !empty($single_personnel['is_hourly_contractor']) ? ' (' . fmt_hm((float)$single_personnel['worked_hours']) . ' ' . t('ساعت') . ' × ' . number_format((float)$single_personnel['hourly_rate']) . ')' : '' ?></div><div class="v"><?= number_format($single_personnel['base_pay']) ?></div></div>
            <div class="sc"><div class="l"><?= t('nav_bonus_penalty') ?></div><div class="v" style="font-size:13px">+<?= number_format($single_personnel['bonus']) ?> / −<?= number_format($single_personnel['penalty']) ?></div></div>
            <div class="sc"><div class="l"><?= t('sal_advance') ?> / <?= t('پرداخت') ?></div><div class="v" style="font-size:13px">−<?= number_format($single_personnel['advance']) ?></div></div>
            <div class="sc"><div class="l"><?= t('sal_net') ?> <?= t('بخش') ?> <?= t('ساعتی') ?></div><div class="v" style="color:#6d28d9"><?= number_format($single_personnel['net']) ?></div></div>
        </div>
        <table class="print-table">
            <thead><tr><th style="width:35px"><?= t('ردیف') ?></th><th><?= t('wh_date') ?></th><th><?= t('wh_checkout_start') ?></th><th><?= t('wh_checkout_end') ?></th><th><?= t('wh_shift') ?></th><th><?= t('wh_note') ?></th></tr></thead>
            <tbody>
            <?php foreach ($single_personnel['work_rows'] as $wi => $wh): ?>
                <tr><td><?= $wi + 1 ?></td><td><?= to_jalali($wh['work_date']) ?></td><td style="direction:ltr"><?= substr($wh['start_time'],0,5) ?></td><td style="direction:ltr"><?= $wh['end_time'] === '00:00:00' ? t('wh_open') : substr($wh['end_time'],0,5) ?></td><td><?= $wh['is_night'] ? '🌙 ' . t('شب') : '☀️ ' . t('روز') ?></td><td style="text-align:right"><?= htmlspecialchars($wh['note'] ?: '—') ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($single_personnel['work_rows'])): ?><tr><td colspan="6" style="color:#999"><?= t('در') ?> <?= t('این') ?> <?= t('rep_month') ?> <?= t('wh_no_records') ?> <?= t('است') ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h4 class="sec">🧾 <?= t('مطالبات') ?> <?= t('rep_month') ?> — <?= t('سایر') ?> <?= t('اقلام') ?> (<?= count($single_claims['face']) ?>)</h4>
        <table class="print-table">
            <thead><tr><th style="width:35px"><?= t('ردیف') ?></th><th><?= t('wh_date') ?></th><th><?= t('sal_type') ?></th><th><?= t('شرح') ?></th><th><?= t('مبلغ') ?></th></tr></thead>
            <tbody>
                <?php $rn = 0; foreach ($single_claims['face'] as $f): $rn++;
                    $ft = ['food' => t('خوراک'), 'service' => t('خدمات'), 'material' => t('مصالح'), 'other' => t('سایر')][$f['item_type'] ?? 'other'] ?? t('سایر'); ?>
                <tr>
                    <td><?= $rn ?></td>
                    <td><?= to_jalali($f['date']) ?></td>
                    <td><?= $ft ?></td>
                    <td style="text-align:right"><?= $f['description'] ?: '—' ?></td>
                    <td><?= number_format((float)$f['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($single_claims['face'])): ?><tr><td colspan="5" style="color:#999"><?= t('موردی نیست') ?></td></tr><?php endif; ?>
            </tbody>
        </table>

        <h4 class="sec">💸 <?= t('پرداخت') ?>‌<?= t('های') ?> <?= t('rep_month') ?> (<?= count($single_pays) ?>)</h4>
        <table class="print-table">
            <thead><tr><th style="width:35px"><?= t('ردیف') ?></th><th><?= t('wh_date') ?></th><th><?= t('روش') ?></th><th><?= t('پیگیری') ?></th><th><?= t('شرح') ?></th><th><?= t('مبلغ') ?></th></tr></thead>
            <tbody>
                <?php $rn = 0; foreach ($single_pays as $p): $rn++; ?>
                <tr>
                    <td><?= $rn ?></td>
                    <td><?= to_jalali($p['payment_date']) ?></td>
                    <td><?= $p['method'] ?: '—' ?></td>
                    <td style="font-size:10px"><?= $p['tracking_no'] ?: '—' ?></td>
                    <td style="text-align:right"><?= $p['description'] ?: '—' ?></td>
                    <td style="color:#dc2626"><?= number_format((float)$p['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($single_pays)): ?><tr><td colspan="6" style="color:#999"><?= t('پرداختی') ?> <?= t('نیست') ?></td></tr><?php endif; ?>
            </tbody>
        </table>

        <?php else: ?>
        <!-- ═══ حالت تجمیعی: همه پیمانکاران ═══ -->
        <?php if (empty($rows)): ?>
        <p style="text-align:center;color:#999;padding:30px"><?= t('پیمانکاری') ?> <?= t('یافت نشد') ?></p>
        <?php else: ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:35px"><?= t('ردیف') ?></th>
                    <th><?= t('wh_name') ?> <?= t('پیمانکار') ?></th>
                    <th><?= t('خدمات') ?> <?= t('rep_month') ?></th>
                    <th><?= t('کارکرد ساعتی') ?></th>
                    <th><?= t('قابل پرداخت') ?> <?= t('rep_month') ?></th>
                    <th><?= t('پرداختی') ?> <?= t('rep_month') ?></th>
                    <th><?= t('حساب') ?> <?= t('قبلی') ?></th>
                    <th><?= t('pet_balance') ?></th>
                    <th><?= t('per_iban') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $rn = 0; foreach ($rows as $r): $rn++; ?>
                <tr>
                    <td><?= $rn ?></td>
                    <td style="text-align:right;font-weight:600"><?= $r['name'] ?><?= !empty($r['linked_name']) ? '<div style="font-size:9px;color:#6d28d9">🔗 ' . htmlspecialchars($r['linked_name']) . '</div>' : '' ?></td>
                    <td style="color:#7c3aed"><?= number_format($r['services'] ?? 0) ?></td>
                    <td style="color:#6d28d9"><?= number_format($r['personnel'] ?? 0) ?></td>
                    <td style="color:#7c3aed;font-weight:600"><?= number_format($r['claims']) ?></td>
                    <td style="color:#dc2626"><?= number_format($r['paid']) ?></td>
                    <td><?= number_format($r['prev']) ?></td>
                    <td style="font-weight:800;color:<?= $r['balance'] >= 0 ? '#16a34a' : '#dc2626' ?>"><?= number_format($r['balance']) ?></td>
                    <td style="font-size:10px"><?= $r['iban'] ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2"><?= t('rep_total') ?> <?= t('کل') ?></td>
                    <td style="color:#7c3aed"><?= number_format(array_sum(array_column($rows, 'services'))) ?></td>
                    <td style="color:#6d28d9"><?= number_format(array_sum(array_column($rows, 'personnel'))) ?></td>
                    <td style="color:#7c3aed"><?= number_format(array_sum(array_column($rows, 'claims'))) ?></td>
                    <td style="color:#dc2626"><?= number_format(array_sum(array_column($rows, 'paid'))) ?></td>
                    <td><?= number_format(array_sum(array_column($rows, 'prev'))) ?></td>
                    <td style="color:#16a34a"><?= number_format(array_sum(array_column($rows, 'balance'))) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        <?php endif; ?>

        <div class="action-bar" style="margin-top:16px;display:flex;gap:8px;justify-content:center">
            <button onclick="window.print()" class="btn btn-print">🖨 <?= t('btn_print') ?> A4</button>
        </div>
    </div>
</div>
<script>
function updateUrl() {
    var y = document.getElementById('formYear').value;
    var m = document.getElementById('formMonth').value;
    var s = document.getElementById('formSub').value;
    var url = '?page=subcontractor_payment_print&year=' + y + '&month=' + m;
    if (s !== 'all') url += '&mode=sub&sub_id=' + s;
    window.location.href = url;
}
</script>
<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
