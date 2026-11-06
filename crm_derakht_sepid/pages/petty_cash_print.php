<?php
/**
 * §11.2: فرم تنخواه ماهانه — کلیه واریزی‌ها و هزینه‌های یک ماه شمسی — A4
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/salary_calc.php';
require_login();

$ws_id = $_SESSION['workshop_id'] ?? 0;
$current_j = gregorian_to_jalali(date('Y'), date('m'), date('d'));

$sel_year = (int)($_GET['year'] ?? $current_j[0]);
$sel_month = (int)($_GET['month'] ?? $current_j[1]);
[$g_start, $g_end] = jalali_month_bounds($sel_year, $sel_month);
$month_label = jalali_month_name($sel_month) . ' ' . $sel_year;
$day_before = date('Y-m-d', strtotime($g_start . ' -1 day'));

// کلیه گردش‌های ماه (همراه ثبت‌کننده)
$st = $pdo->prepare("SELECT pc.*, u.full_name AS creator_name FROM petty_cash pc LEFT JOIN users u ON u.id = pc.created_by WHERE pc.workshop_id = ? AND pc.date BETWEEN ? AND ? ORDER BY pc.date, pc.id");
$st->execute([$ws_id, $g_start, $g_end]);
$rows = $st->fetchAll();

// مانده تا قبل از این ماه
$st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM petty_cash WHERE workshop_id = ? AND date <= ?");
$st->execute([$ws_id, $day_before]);
$prev_balance = (float)$st->fetchColumn();

$income_total = 0; $expense_total = 0;
foreach ($rows as $r) {
    if ($r['type'] === 'income') $income_total += (float)$r['amount'];
    else $expense_total += (float)$r['amount'];
}
$final_balance = $prev_balance + $income_total - $expense_total;

$logo_path = $_SESSION['workshop_logo'] ?? '';
if (!$logo_path || !file_exists(__DIR__ . '/../' . $logo_path)) $logo_path = 'uploads/logos/logo.png';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('فرم') ?> <?= t('nav_petty_cash') ?> <?= t('sal_monthly') ?> - <?= $month_label ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:<?= $lang_dir ?>; background:#f0f2f5; color:#1a1a2e; min-height:100vh; }
        .page { max-width:1000px; margin:20px auto; background:white; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow:hidden; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:linear-gradient(135deg,#eab308,#a16207); color:white; flex-wrap:wrap; gap:8px; }
        .top-bar h1 { font-size:16px; font-weight:700; }
        .top-bar a { color:white; text-decoration:none; background:rgba(255,255,255,0.2); padding:5px 12px; border-radius:8px; font-size:12px; }
        .content { padding:20px; }
        .print-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #333; padding-bottom:12px; margin-bottom:16px; }
        .print-header img { height:60px; }
        .print-title { font-size:18px; font-weight:700; text-align:center; }
        .print-meta { font-size:12px; color:#666; text-align:right; }
        .filter-bar { padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .filter-bar select { padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:inherit; font-size:13px; }
        .print-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:12px; }
        .print-table th { background:#f1f5f9; padding:8px 4px; border:1px solid #ccc; text-align:center; font-weight:600; }
        .print-table td { padding:6px 4px; border:1px solid #ddd; text-align:center; }
        .print-table .total-row { background:#e2e8f0; font-weight:700; }
        .btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-family:inherit; font-size:12px; font-weight:600; }
        .btn-print { background:linear-gradient(135deg,#eab308,#a16207); color:white; }
        .summary-cards { display:flex; gap:12px; flex-wrap:wrap; margin:14px 0; }
        .summary-cards .sc { flex:1; min-width:140px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; text-align:center; }
        .summary-cards .sc .l { font-size:11px; color:#64748b; margin-bottom:6px; }
        .summary-cards .sc .v { font-size:16px; font-weight:800; }
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
            @page { size:A4; margin:8mm; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <h1><?= t('فرم') ?> <?= t('nav_petty_cash') ?> <?= t('sal_monthly') ?></h1>
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
        </div>

        <div class="print-header">
            <div class="print-meta">
                <div style="font-size:14px;font-weight:700"><?= to_jalali(date('Y-m-d')) ?></div>
                <div style="font-size:11px;margin-top:4px"><?= date('H:i') ?></div>
                <div style="font-size:12px;margin-top:4px"><?= $month_label ?></div>
            </div>
            <div class="print-title"><?= t('فرم') ?> <?= t('nav_petty_cash') ?> <?= t('sal_monthly') ?></div>
            <div>
                <?php if (file_exists(__DIR__ . '/../' . $logo_path)): ?><img src="<?= $logo_path ?>" alt="logo"><?php endif; ?>
            </div>
        </div>

        <div class="summary-cards">
            <div class="sc"><div class="l"><?= t('pet_balance') ?> <?= t('اول دوره') ?> (<?= t('lea_to') ?> <?= to_jalali($day_before) ?>)</div><div class="v"><?= number_format($prev_balance) ?></div></div>
            <div class="sc"><div class="l"><?= t('rep_total') ?> <?= t('واریزی') ?>‌<?= t('های') ?> <?= t('rep_month') ?></div><div class="v" style="color:#16a34a">+<?= number_format($income_total) ?></div></div>
            <div class="sc"><div class="l"><?= t('rep_total') ?> <?= t('pet_expense') ?>‌<?= t('های') ?> <?= t('rep_month') ?></div><div class="v" style="color:#dc2626">−<?= number_format($expense_total) ?></div></div>
            <div class="sc"><div class="l"><?= t('pet_balance') ?> <?= t('wh_end') ?> <?= t('دوره') ?></div><div class="v" style="color:<?= $final_balance >= 0 ? '#16a34a' : '#dc2626' ?>"><?= number_format($final_balance) ?></div></div>
        </div>

        <?php if (empty($rows)): ?>
        <p style="text-align:center;color:#999;padding:30px"><?= t('گردشی در') ?> <?= t('این') ?> <?= t('rep_month') ?> <?= t('ثبت نشده') ?></p>
        <?php else: ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:35px"><?= t('ردیف') ?></th>
                    <th><?= t('wh_date') ?></th>
                    <th><?= t('دریافت') ?><?= t('‌کننده /') ?> <?= t('ثبت‌کننده') ?></th>
                    <th><?= t('sal_type') ?></th>
                    <th><?= t('شرح') ?></th>
                    <th><?= t('adv_amount') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $rn = 0; foreach ($rows as $r): $rn++; ?>
                <tr>
                    <td><?= $rn ?></td>
                    <td style="white-space:nowrap"><?= to_jalali($r['date']) ?></td>
                    <td><?= $r['creator_name'] ?: '—' ?></td>
                    <td><?= $r['type'] === 'income' ? '<span style="color:#16a34a;font-weight:600">' . t('واریزی') . '</span>' : '<span style="color:#dc2626;font-weight:600">' . t('هزینه') . '</span>' ?></td>
                    <td style="text-align:right"><?= $r['description'] ?></td>
                    <td style="font-weight:600;color:<?= $r['type'] === 'income' ? '#16a34a' : '#dc2626' ?>"><?= $r['type'] === 'income' ? '+' : '−' ?><?= number_format((float)$r['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5"><?= t('rep_total') ?> <?= t('واریزی') ?></td>
                    <td style="color:#16a34a">+<?= number_format($income_total) ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="5"><?= t('rep_total') ?> <?= t('pet_expense') ?></td>
                    <td style="color:#dc2626">−<?= number_format($expense_total) ?></td>
                </tr>
                <tr class="total-row" style="background:#d1fae5">
                    <td colspan="5"><?= t('rep_total') ?> <?= t('کل') ?> (<?= t('sal_net') ?> <?= t('rep_month') ?>)</td>
                    <td style="font-weight:800"><?= ($income_total - $expense_total) >= 0 ? '+' : '−' ?><?= number_format(abs($income_total - $expense_total)) ?></td>
                </tr>
            </tbody>
        </table>
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
    window.location.href = '?page=petty_cash_print&year=' + y + '&month=' + m;
}
</script>
<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
