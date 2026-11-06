<?php
/**
 * فرم لیست واریزی کارمندان - صفحه مستقل
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
// بازه‌ی دقیق ماه جلالی (§7)
[$g_start, $g_end] = jalali_month_bounds($sel_year, $sel_month);
$month_label = jalali_month_name($sel_month) . ' ' . $sel_year;

$settings = get_workshop_settings($pdo, $ws_id);

// فرم فقط اعضای کارگاه فعلی را می‌آورد؛ اما مبلغ هر عضو، تجمیع کارکردش در همه کارگاه‌هاست.
$st = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar, COALESCE(MAX(wp_all.monthly_salary),0) AS monthly_salary, COALESCE(MAX(wp_all.daily_salary),0) AS daily_salary, u.iban, u.account_name, u.account_number
    FROM users u
    JOIN workshop_personnel wp_current ON wp_current.user_id=u.id AND wp_current.workshop_id=? AND wp_current.status='active'
    LEFT JOIN workshop_personnel wp_all ON wp_all.user_id=u.id AND wp_all.status='active'
    WHERE u.is_karigar=0
    GROUP BY u.id,u.full_name,u.is_karigar,u.iban,u.account_name,u.account_number ORDER BY u.full_name");
$st->execute([$ws_id]);
$all_personnel = $st->fetchAll();

$results = array();
$hidden_zero = 0;
foreach ($all_personnel as $p) {
    // دریافتی ماه با نرخ هر روز از تاریخچه حقوق (§1) — ws=0 یعنی تجمیعی
    $base_pay = employee_month_pay($pdo, $p['id'], 0, $g_start, $g_end, (float)($p['monthly_salary'] ?? 0), $settings);
    // مانده طلب واقعی تا قبل از این ماه (§7)
    $prev = compute_prev_balance($pdo, $p['id'], 0, 0, $g_start, 0, (float)($p['monthly_salary'] ?? 0), $settings);
    $adv = advance_deduction_month($pdo, $p['id'], 0, $g_start, $g_end);
    $bst = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) t FROM bonuses_penalties WHERE user_id = ? AND status = 'approved' AND date BETWEEN ? AND ? GROUP BY type");
    $bst->execute([$p['id'], $g_start, $g_end]);
    $bns = 0; $pnl = 0;
    foreach ($bst->fetchAll() as $br) { if ($br['type'] === 'bonus') $bns = (float)$br['t']; else $pnl = (float)$br['t']; }
    $payable = round($base_pay + $prev + $bns - $pnl - $adv);
    // §v12 §10.1: افراد با حقوق قابل پرداخت صفر/منفی در فرم واریزی نمی‌آیند
    if ($payable <= 0) { $hidden_zero++; continue; }
    $results[] = array('name'=>$p['full_name'], 'monthly'=>$base_pay, 'bonus'=>$bns, 'penalty'=>$pnl, 'advance'=>$adv, 'balance_prev'=>$prev, 'payable'=>$payable, 'iban'=>$p['iban']?:'-', 'account_name'=>$p['account_name']?:'-', 'bank'=>$p['account_number']?:'-');
}
$total_monthly = array_sum(array_column($results, 'monthly'));

// لوگوی کارگاه فعال (با فallback به لوگوی پیش‌فرض)
$logo_path = $_SESSION['workshop_logo'] ?? '';
if (!$logo_path || !file_exists(__DIR__ . '/../' . $logo_path)) {
    $logo_path = 'uploads/logos/logo.png';
}
$print_mode = isset($_GET['print']);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('لیست واریزی') ?> <?= t('scope_employees') ?> - <?= $month_label ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:<?= $lang_dir ?>; background:#f0f2f5; color:#1a1a2e; min-height:100vh; }

        .page { max-width:1000px; margin:20px auto; background:white; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow:hidden; padding:0; }

        .top-bar {
            display:flex; justify-content:space-between; align-items:center;
            padding:12px 16px; background:linear-gradient(135deg,#667eea,#764ba2); color:white;
            flex-wrap:wrap; gap:8px;
        }
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
        /* شبا: خوانا، تک‌خطی و چپ‌چین (ارقام لاتین) */
        .print-table .iban-cell { font-family:'Vazirmatn',Consolas,monospace; font-size:13px; font-weight:700;
            direction:ltr; unicode-bidi:embed; white-space:nowrap; letter-spacing:0.6px; text-align:center; }

        .btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-family:inherit; font-size:12px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
        .btn-print { background:linear-gradient(135deg,#667eea,#764ba2); color:white; }
        .btn-archive { background:linear-gradient(135deg,#059669,#10b981); color:white; }
        .btn-close { background:rgba(255,255,255,0.2); color:white; text-decoration:none; padding:5px 12px; border-radius:8px; font-size:12px; }

        @media print {
            * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
            html, body { margin:0; padding:0; background:white; width:100%; height:100%; }
            .page { box-shadow:none; border-radius:0; margin:0; max-width:100%; width:100%; overflow:hidden; page-break-inside:avoid; }
            .top-bar { background:#333 !important; padding:4px 8px !important; }
            .top-bar h1 { font-size:10px !important; }
            .top-bar a, .filter-bar, .action-bar, .zero-note { display:none !important; }
            .print-header { border-bottom:1px solid #000; padding:4px 6px !important; }
            .print-header img { height:40px !important; }
            .print-title { font-size:13px !important; }
            .print-table { font-size:9px !important; }
            .print-table th, .print-table td { padding:3px 2px !important; font-size:9px !important; }
            .print-table .iban-cell { font-size:11px !important; font-weight:700 !important; white-space:nowrap !important; letter-spacing:0.4px !important; }
            @page { size:A4; margin:8mm; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <h1><?= t('لیست واریزی') ?> <?= t('scope_employees') ?></h1>
        <div style="display:flex;gap:6px;align-items:center">
            <a href="javascript:window.close()" class="btn-close"><?= t('jdp_close') ?></a>
        </div>
    </div>

    <div class="content">
        <!-- Filter -->
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

        <!-- Header -->
        <div class="print-header">
            <div class="print-meta">
                <div style="font-size:14px;font-weight:700"><?= to_jalali(date('Y-m-d')) ?></div>
                <div style="font-size:11px;margin-top:4px"><?= date('H:i') ?></div>
                <div style="font-size:12px;margin-top:4px"><?= $month_label ?></div>
            </div>
            <div class="print-title"><?= t('لیست واریزی') ?> <?= t('نیروهای') ?> <?= t('اداری') ?></div>
            <div>
                <?php if (file_exists(__DIR__ . '/../' . $logo_path)): ?>
                    <img src="<?= $logo_path ?>" alt="logo">
                <?php endif; ?>
            </div>
        </div>

        <?php if ($hidden_zero > 0): ?>
        <p class="zero-note" style="font-size:11px;color:#64748b;margin-bottom:8px">ⓘ <?= $hidden_zero ?> <?= t('per_count') ?> <?= t('با') ?> <?= t('مبلغ') ?> <?= t('قابل پرداخت') ?> <?= t('صفر') ?> <?= t('در') ?> <?= t('این') ?> <?= t('فرم') ?> <?= t('btn_show') ?> <?= t('داده') ?> <?= t('نشدند') ?>.</p>
        <?php endif; ?>
        <?php if (empty($results)): ?>
        <p style="text-align:center;color:#999;padding:30px"><?= t('داده‌ای یافت نشد') ?></p>
        <?php else: ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:35px"><?= t('ردیف') ?></th>
                    <th><?= t('wh_name') ?> <?= t('و') ?> <?= t('wh_name') ?> <?= t('خانوادگی') ?></th>
                    <th><?= t('مبلغ') ?> <?= t('طلب') ?> <?= t('rep_month') ?></th>
                    <th style="font-size:9px"><?= t('sal_bonus') ?>/<?= t('sal_penalty') ?></th>
                    <th style="font-size:9px"><?= t('کسر') ?> <?= t('sal_advance') ?></th>
                    <th><?= t('pet_balance') ?> <?= t('طلب قبلی') ?></th>
                    <th><?= t('قابل پرداخت') ?></th>
                    <th><?= t('per_iban') ?></th>
                    <th><?= t('wh_name') ?> <?= t('صاحب') ?> <?= t('حساب') ?></th>
                    <th><?= t('بانک مقصد') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td style="text-align:right;font-weight:500"><?= $r['name'] ?></td>
                    <td><?= number_format($r['monthly']) ?></td>
                    <td style="font-size:10px"><?= ($r['bonus'] ? '+' . number_format($r['bonus']) : '') . ($r['penalty'] ? ($r['bonus'] ? ' / ' : '') . '−' . number_format($r['penalty']) : (!$r['bonus'] ? '—' : '')) ?></td>
                    <td style="font-size:10px;color:#dc2626"><?= $r['advance'] ? '−' . number_format($r['advance']) : '—' ?></td>
                    <td><?= number_format($r['balance_prev']) ?></td>
                    <td style="font-weight:700;color:#16a34a"><?= number_format($r['payable']) ?></td>
                    <td class="iban-cell"><?= htmlspecialchars($r['iban'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $r['account_name'] ?></td>
                    <td><?= $r['bank'] ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2"><?= t('rep_total') ?> <?= t('کل') ?></td>
                    <td><?= number_format($total_monthly) ?></td>
                    <td style="font-size:10px"><?= number_format(array_sum(array_column($results, 'bonus'))) ?> / −<?= number_format(array_sum(array_column($results, 'penalty'))) ?></td>
                    <td style="color:#dc2626">−<?= number_format(array_sum(array_column($results, 'advance'))) ?></td>
                    <td><?= number_format(array_sum(array_column($results, 'balance_prev'))) ?></td>
                    <td style="font-weight:800;color:#16a34a"><?= number_format(array_sum(array_column($results, 'payable'))) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Action Bar -->
        <div class="action-bar" style="margin-top:16px;display:flex;gap:8px;justify-content:center">
            <button onclick="window.print()" class="btn btn-print"><?= t('btn_print') ?> A4</button>
            <a href="index.php?export=excel&type=employee_payment&year=<?= $sel_year ?>&month=<?= $sel_month ?>" class="btn btn-print" target="_blank"><?= t('btn_download') ?> <?= t('اکسل') ?></a>
        </div>
    </div>
</div>

<script>
function updateUrl() {
    var y = document.getElementById('formYear').value;
    var m = document.getElementById('formMonth').value;
    window.location.href = '?page=employee_payment_print&year=' + y + '&month=' + m;
}
</script>
<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
