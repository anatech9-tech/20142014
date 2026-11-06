<?php
/**
 * Work Hours Report — Personal daily breakdown + A4 print
 */
require_once __DIR__ . '/../includes/salary_calc.php';

$ws_id = $_SESSION['workshop_id'] ?? 0;
$uid = (int)($_GET['user_id'] ?? 0);
$sel_month = $_GET['month'] ?? '';

$settings = get_workshop_settings($pdo, $ws_id);

if (!$uid) { echo t('کاربر یافت نشد'); exit; }

// User info
$st = $pdo->prepare("SELECT u.*, wp.daily_salary, wp.monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE u.id = ? AND wp.workshop_id = ?");
$st->execute(array($uid, $ws_id));
$user = $st->fetch();
if (!$user) { echo t('کاربر یافت نشد'); exit; }

$is_karigar = (int)$user['is_karigar'];

// Available months — بر اساس ماه شمسی (نامه/کد 'jy-jm' مثل 1405-5)
$st = $pdo->prepare("SELECT DISTINCT work_date FROM work_hours WHERE user_id = ? AND workshop_id = ? ORDER BY work_date DESC");
$st->execute(array($uid, $ws_id));
$all_dates = $st->fetchAll(PDO::FETCH_COLUMN);
$month_map = [];         // key 'jy-jm' => ['label'، 'start'، 'end']
foreach ($all_dates as $wd) {
    $k = jalali_month_key($wd);
    if (!isset($month_map[$k])) $month_map[$k] = jalali_month_from_key($k);
}
uksort($month_map, function ($a, $b) {
    [$ay, $am] = array_map('intval', explode('-', $a));
    [$by, $bm] = array_map('intval', explode('-', $b));
    return ($by * 100 + $bm) <=> ($ay * 100 + $am);
});

if (empty($month_map)) { echo t('wh_no_records'); exit; }

// پیش‌فرض: ماه شمسیِ جاری اگر داده دارد، وگرنه آخرین ماه دارای داده (اسناد §2.3)
$today_key = jalali_month_key(date('Y-m-d'));
if ($sel_month && isset($month_map[$sel_month])) {
    // انتخاب کاربر معتبر است
} elseif (isset($month_map[$today_key])) {
    $sel_month = $today_key;
} else {
    $sel_month = array_key_first($month_map);
}

$sel_info = $month_map[$sel_month];
$m_start = $sel_info['start'];
$m_end   = $sel_info['end'];
$month_label = $sel_info['label'];

// All work hours for this jalali month
$st = $pdo->prepare("SELECT wh.*, u.full_name, u.is_karigar FROM work_hours wh JOIN users u ON wh.user_id = u.id WHERE wh.user_id = ? AND wh.workshop_id = ? AND wh.work_date BETWEEN ? AND ? ORDER BY wh.work_date, wh.start_time");
$st->execute(array($uid, $ws_id, $m_start, $m_end));
$all_hours = $st->fetchAll();

// Holidays
$st = $pdo->prepare("SELECT holiday_date, title FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$st->execute(array($m_start, $m_end));
$holidays = array();
while ($h = $st->fetch()) { $holidays[$h['holiday_date']] = $h['title']; }

// Group by date
$by_date = array();
foreach ($all_hours as $wh) {
    $by_date[$wh['work_date']][] = $wh;
}

// Calculate each day
$day_names = localized_weekday_names();
$required_hours_info = !$is_karigar ? employee_required_hours($pdo, $uid, $ws_id, $m_start, $m_end, $settings) : null;
$hourly_base = $is_karigar ? 0 : employee_hourly_rate_for_required_hours((float)($user['monthly_salary'] ?? 0), $required_hours_info);
$salary_history = get_salary_history($pdo, $uid);
$overtime_coef = (float)($settings['overtime_coefficient'] ?? 1.33);
$night_coef = (float)($settings['night_coefficient'] ?? 2.0);

$daily_rows = array();
$totals = array('regular'=>0, 'overtime'=>0, 'night'=>0, 'friday_hours'=>0, 'holiday_hours'=>0, 'total_worked'=>0);

$ktotal_days = 0; $ktotal_hours = 0; $kextra_days = 0; $ktotal_payable = 0; $ktotal_pay = 0;

$sorted = array_keys($by_date);
sort($sorted);

foreach ($sorted as $di => $date) {
    $shifts = $by_date[$date];
    $is_hol = isset($holidays[$date]);
    $is_fri = (date('w', strtotime($date)) == 5);
    $dw = $day_names[date('w', strtotime($date))];

    // Previous day for late arrival rule
    $prev_date_str = ($di > 0) ? $sorted[$di - 1] : '';
    $prev_shifts_for_calc = $prev_date_str ? ($by_date[$prev_date_str] ?? array()) : array();

    $row = array(
        'date' => $date,
        'jalali' => to_jalali($date),
        'day_name' => $dw,
        'is_friday' => $is_fri,
        'is_holiday' => $is_hol,
        'shifts' => array(),
    );

    // Gather individual shifts
    foreach ($shifts as $sh) {
        $start_sec = strtotime($sh['start_time']);
        $end_sec = strtotime($sh['end_time']);
        if ($sh['end_time'] === '00:00:00') {
            $dur = (time() - $start_sec) / 3600;
        } else {
            $dur = ($end_sec - $start_sec) / 3600;
            if ($dur <= 0) $dur += 24;
        }
        $row['shifts'][] = array(
            'start' => substr($sh['start_time'],0,5),
            'end' => ($sh['end_time'] === '00:00:00') ? t('wh_open') : substr($sh['end_time'],0,5),
            'duration' => round($dur, 1),
            'is_night' => $sh['is_night'],
        );
    }

    if ($is_karigar) {
        $calc = calc_worker_day($shifts, $date, $is_hol, $user['daily_salary'], $prev_shifts_for_calc, $prev_date_str);
        $row['total_worked'] = $calc['total_worked'];
        $row['paid_hours'] = $calc['paid_hours'];
        $row['overtime_hours'] = $calc['overtime_hours'];
        $row['day_count'] = $calc['day_count'];
        $row['extra_days'] = $calc['extra_days'];
        $ktotal_days += $calc['day_count'];
        $ktotal_hours += $calc['total_worked'];
        $kextra_days += $calc['extra_days'];
        $ktotal_payable += $calc['payable_hours'];
        // مبلغ هر روز با حقوق مؤثر در همان روز (اسناد §1.4)
        $day_rate = salary_at_date($salary_history, $date, $user['daily_salary']);
        $ktotal_pay += $calc['payable_hours'] * ($day_rate / 8);
    } else {
        $calc = calc_employee_day($shifts, $date, $is_hol, $settings, $prev_shifts_for_calc, $prev_date_str);
        $row['regular'] = $calc['regular'];
        $row['overtime'] = $calc['overtime'];
        $row['night'] = $calc['night'];
        $row['friday_hours'] = $calc['friday_hours'];
        $row['holiday_hours'] = $calc['holiday_hours'];
        $row['total_worked'] = $calc['regular'] + $calc['overtime'] + $calc['night'] + $calc['friday_hours'] + $calc['holiday_hours'];
        $totals['regular'] += $calc['regular'];
        $totals['overtime'] += $calc['overtime'];
        $totals['night'] += $calc['night'];
        $totals['friday_hours'] += $calc['friday_hours'];
        $totals['holiday_hours'] += $calc['holiday_hours'];
    }
    $row['total_worked'] = round($row['total_worked'], 1);
    $daily_rows[] = $row;
}

$print = isset($_GET['print']);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('گزارش') ?> <?= t('nav_work_hours') ?> - <?= htmlspecialchars($user['full_name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/glass.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:<?= $lang_dir ?>; background:#f0f2f5; color:#1a1a2e; }
        .page { max-width:900px; margin:20px auto; background:white; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow:hidden; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; padding:16px 24px; background:linear-gradient(135deg,#059669,#10b981); color:white; }
        .top-bar h1 { font-size:18px; font-weight:700; }
        .top-bar a { color:white; text-decoration:none; background:rgba(255,255,255,0.2); padding:6px 14px; border-radius:8px; font-size:13px; }
        .user-box { padding:20px 24px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; }
        .user-name { font-size:22px; font-weight:700; }
        .user-meta { font-size:13px; color:#666; margin-top:4px; }
        .months { padding:12px 24px; border-bottom:1px solid #eee; display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .mbtn { padding:5px 12px; border:1px solid #ddd; border-radius:8px; background:white; cursor:pointer; font-size:12px; font-family:inherit; text-decoration:none; color:#333; }
        .mbtn.active { background:#059669; color:white; border-color:#059669; }
        .summary { padding:16px 24px; border-bottom:1px solid #eee; display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:8px; }
        .sc { padding:10px; border-radius:8px; border:1px solid #f0f0f0; text-align:center; }
        .sc .lbl { font-size:10px; color:#888; }
        .sc .val { font-size:18px; font-weight:700; }
        .sc.g { background:#f0fdf4; } .sc.g .val { color:#16a34a; }
        .sc.y { background:#fefce8; } .sc.y .val { color:#ca8a04; }
        .sc.p { background:#faf5ff; } .sc.p .val { color:#9333ea; }
        .sc.b { background:#eff6ff; } .sc.b .val { color:#2563eb; }
        .days { padding:16px 24px; }
        .days h3 { font-size:15px; font-weight:700; margin-bottom:10px; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th { background:#f1f5f9; padding:8px 6px; text-align:right; font-weight:600; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
        td { padding:7px 6px; border-bottom:1px solid #f1f5f9; }
        .fri { background:#fff7ed; }
        .hol { background:#fef2f2; }
        .print-btn { position:fixed; bottom:24px; left:24px; z-index:100; padding:12px 24px; background:linear-gradient(135deg,#059669,#10b981); color:white; border:none; border-radius:12px; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600; box-shadow:0 4px 15px rgba(5,150,105,0.4); }
        .back-btn { position:fixed; bottom:24px; right:24px; z-index:100; padding:12px 24px; background:#333; color:white; border:none; border-radius:12px; font-size:14px; text-decoration:none; display:inline-block; }
        @media print {
            body { background:white; }
            .page { box-shadow:none; border-radius:0; margin:0; max-width:100%; }
            .top-bar { background:#333 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .months, .print-btn, .back-btn, .no-print { display:none !important; }
            tr { break-inside:avoid; }
            @page { size:A4; margin:15mm; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <h1><?= t('گزارش') ?> <?= t('nav_work_hours') ?></h1>
        <a href="index.php?page=work_hours"><?= t('btn_back') ?></a>
    </div>
    <div class="user-box">
        <div>
            <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="user-meta">
                <?php if ($is_karigar == 1): ?><?= t('کارگر') ?> — <?= number_format($user['daily_salary']) ?> <?= t('ت') ?>/<?= t('روز') ?>
                <?php elseif ($is_karigar == 2): ?><?= t('پیمانکار') ?>
                <?php else: ?><?= t('کارمند') ?> — <?= number_format($user['monthly_salary']) ?> <?= t('ت') ?>/<?= t('ماه') ?>
                <?php endif; ?>
            </div>
        </div>
        <span style="padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:<?= $is_karigar==1?'#fef3c7;color:#92400e':($is_karigar==2?'#e0e7ff;color:#3730a3':'#e0f2fe;color:#0369a1') ?>"><?= $is_karigar==1?t('sal_worker'):($is_karigar==2?t('پیمانکار'):t('sal_employee')) ?></span>
    </div>
    <?php if (!$print): ?>
    <div class="months">
        <span style="font-size:12px;font-weight:600;margin-left:4px"><?= t('rep_month') ?>:</span>
        <?php foreach ($month_map as $mk => $mi): ?>
        <a href="?page=work_report&user_id=<?= $uid ?>&month=<?= $mk ?>" class="mbtn <?= $mk === $sel_month ? 'active' : '' ?>"><?= $mi['label'] ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="summary">
        <?php if ($is_karigar == 1): ?>
            <div class="sc g"><div class="lbl"><?= t('sal_work_days') ?></div><div class="val"><?= $ktotal_days ?></div></div>
            <div class="sc g"><div class="lbl"><?= t('کل') ?> <?= t('wh_hours') ?> <?= t('کار') ?></div><div class="val"><?= fmt_hm($ktotal_hours) ?></div></div>
            <div class="sc y"><div class="lbl"><?= t('wh_day') ?> <?= t('اضافه') ?></div><div class="val"><?= $kextra_days ?></div></div>
            <div class="sc b"><div class="lbl"><?= t('rep_total') ?> <?= t('مبلغ') ?><?= count($salary_history) ? ' (' . t('با افزایش‌های ثبت‌شده') . ')'  : ' (' . t('ساعتی') . ' × ' . number_format(round($user['daily_salary']/8)) . ')'  ?></div><div class="val"><?= number_format(round($ktotal_pay)) ?> <?= t('ت') ?></div></div>
        <?php else: ?>
            <div class="sc g"><div class="lbl"><?= t('sal_regular') ?></div><div class="val"><?= fmt_hm($totals['regular']) ?></div></div>
            <div class="sc y"><div class="lbl"><?= t('sal_overtime') ?></div><div class="val"><?= fmt_hm($totals['overtime']) ?></div></div>
            <div class="sc p"><div class="lbl"><?= t('wh_night') ?></div><div class="val"><?= fmt_hm($totals['night']) ?></div></div>
            <div class="sc b"><div class="lbl"><?= t('wh_friday') ?></div><div class="val"><?= fmt_hm($totals['friday_hours']) ?></div></div>
            <?php if ($totals['holiday_hours'] > 0): ?>
            <div class="sc" style="background:#fef2f2"><div class="lbl"><?= t('wh_holiday') ?></div><div class="val" style="color:#dc2626"><?= fmt_hm($totals['holiday_hours']) ?></div></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Daily Table -->
    <div class="days">
        <h3><?= t('sal_daily_detail') ?> — <?= $month_label ?></h3>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th><?= t('wh_date') ?></th><th><?= t('wh_day') ?></th><th><?= t('wh_checkout_start') ?></th><th><?= t('wh_checkout_end') ?></th><th><?= t('wh_duration') ?></th>
                    <?php if (!$is_karigar): ?>
                        <th><?= t('sal_regular') ?></th><th><?= t('sal_overtime') ?></th><th><?= t('wh_night') ?></th><th><?= t('wh_friday') ?></th><th><?= t('wh_holiday') ?></th>
                    <?php endif; ?>
                    <th><?= t('wh_status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daily_rows as $dr):
                    $rc = $dr['is_friday'] ? 'fri' : ($dr['is_holiday'] ? 'hol' : '');
                ?>
                <tr class="<?= $rc ?>">
                    <td style="white-space:nowrap;font-weight:500"><?= $dr['jalali'] ?></td>
                    <td>
                        <?= $dr['day_name'] ?>
                        <?php if ($dr['is_friday']): ?> <span style="color:#c2410c;font-size:10px"><?= t('wh_friday') ?></span> <?php endif; ?>
                        <?php if ($dr['is_holiday']): ?> <span style="color:#dc2626;font-size:10px"><?= t('wh_holiday') ?></span> <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($dr['shifts'])): ?>
                            <?php foreach ($dr['shifts'] as $sh): ?>
                                <?= $sh['start'] ?><br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($dr['shifts'])): ?>
                            <?php foreach ($dr['shifts'] as $sh): ?>
                                <?= $sh['end'] ?><br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:600">
                        <?php if (!empty($dr['shifts'])): ?>
                            <?php foreach ($dr['shifts'] as $sh): ?>
                                <?= fmt_hm($sh['duration']) ?>
                                <?php if ($sh['is_night']): ?> <span style="color:#9333ea;font-size:9px"><?= t('wh_night') ?></span> <?php endif; ?>
                                <br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <?php if (!$is_karigar): ?>
                        <td><?= $dr['regular'] > 0 ? fmt_hm($dr['regular']) : '-' ?></td>
                        <td><?= $dr['overtime'] > 0 ? fmt_hm($dr['overtime']) : '-' ?></td>
                        <td><?= $dr['night'] > 0 ? fmt_hm($dr['night']) : '-' ?></td>
                        <td><?= $dr['friday_hours'] > 0 ? fmt_hm($dr['friday_hours']) : '-' ?></td>
                        <td><?= $dr['holiday_hours'] > 0 ? fmt_hm($dr['holiday_hours']) : '-' ?></td>
                    <?php endif; ?>
                    <td style="font-weight:600"><?= fmt_hm($dr['total_worked']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($daily_rows)): ?>
                <tr><td colspan="<?= $is_karigar ? 5 : 10 ?>" style="text-align:center;padding:30px;color:#999"><?= t('داده‌ای وجود ندارد') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php if (!$print): ?>
<button class="print-btn" onclick="window.open('?page=work_report&user_id=<?= $uid ?>&month=<?= $sel_month ?>&print=1','_blank','width=900,height=700');setTimeout(function(){window.print();},500)"><?= t('btn_print') ?> A4</button>
<a href="index.php?page=work_hours" class="back-btn"><?= t('btn_back') ?></a>
<?php endif; ?>

<?php if ($print): ?>
<script>window.onload=function(){window.print();}</script>
<?php endif; ?>
<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
