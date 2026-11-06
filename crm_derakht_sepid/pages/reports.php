<?php
$pageTitle = t('nav_reports');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$type = $_GET['type'] ?? 'salary';

// ═══ §v11: دسترسی تب‌به‌تب گزارش‌ها — هر تب خودش یک دسترسی جداست ═══
$__rep_key_map = access_report_type_keys();
$__rep_allowed = [];
foreach ($__rep_key_map as $__rt => $__rk) {
    if (in_array(access_mode($__rk), ['viewer', 'editor'], true)) $__rep_allowed[] = $__rt;
}
if (empty($__rep_allowed)) {
    showToast(t('به گزارش‌ها دسترسی ندارید'), 'error');
    redirect('index.php?page=dashboard');
}
if (!in_array($type, $__rep_allowed, true)) {
    redirect('index.php?page=reports&type=' . $__rep_allowed[0]);
}

require_once __DIR__ . '/../includes/salary_calc.php';
require_once __DIR__ . '/../includes/subcontractor_link.php';

$settings = get_workshop_settings($pdo, $ws_id);

// Helper: calculate salary for one person in a gregorian range (jalali month bounds) — §1/§7
function calc_person_month($pdo, $uid, $ws_id, $g_start, $g_end, $is_karigar, $daily_salary, $monthly_salary, $settings) {
    $stmt = $pdo->prepare("SELECT * FROM work_hours WHERE user_id = ? AND workshop_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date, start_time");
    $stmt->execute([$uid, $ws_id, $g_start, $g_end]);
    $hours = $stmt->fetchAll();
    if (empty($hours)) return null;

    $stmt2 = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $stmt2->execute([$g_start, $g_end]);
    $holidays = array_column($stmt2->fetchAll(), 'holiday_date');

    $daily = [];
    foreach ($hours as $h) { $daily[$h['work_date']][] = $h; }
    $history = get_salary_history($pdo, $uid);

    if ($is_karigar) {
        $total_days = 0; $total_hours = 0; $extra_days = 0; $total_payable = 0;
        $base_f = 0.0;
        $sorted_d = array_keys($daily); sort($sorted_d);
        foreach ($sorted_d as $di => $date) {
            $shifts = $daily[$date];
            $is_hol = in_array($date, $holidays);
            $prev_d = ($di > 0) ? $sorted_d[$di - 1] : '';
            $prev_s = $prev_d ? ($daily[$prev_d] ?? []) : [];
            $calc = calc_worker_day($shifts, $date, $is_hol, $daily_salary, $prev_s, $prev_d);
            $total_days += $calc['day_count'];
            $total_hours += $calc['payable_hours'];
            $total_payable += $calc['payable_hours'];
            $extra_days += $calc['extra_days'];
            $base_f += $calc['payable_hours'] * (salary_at_date($history, $date, $daily_salary) / 8);
        }
        // مستند §6.5 + §1: حقوق کارگر = ساعت قابل‌پرداخت × (روزانه همان روز / ۸)
        $base_pay = round($base_f);
        return ['regular' => $total_hours, 'overtime' => 0, 'night' => 0, 'friday' => 0, 'holiday' => 0, 'days' => $total_days, 'extra_days' => $extra_days, 'base_pay' => $base_pay];
    } else {
        $reg_h = 0; $ot_h = 0; $night_h = 0; $fri_h = 0; $hol_h = 0;
        $required_info = employee_required_hours($pdo, $uid, $ws_id, $g_start, $g_end, $settings);
        $base_f = 0.0;
        $ot_c = (float)($settings['overtime_coefficient'] ?? 1.33);
        $ni_c = (float)($settings['night_coefficient'] ?? 2);
        $fr_c = (float)($settings['friday_coefficient'] ?? 1.33);
        $ho_c = (float)($settings['holiday_coefficient'] ?? 2);
        $sorted_d = array_keys($daily); sort($sorted_d);
        foreach ($sorted_d as $di => $date) {
            $shifts = $daily[$date];
            $is_hol = in_array($date, $holidays);
            $prev_d = ($di > 0) ? $sorted_d[$di - 1] : '';
            $prev_s = $prev_d ? ($daily[$prev_d] ?? []) : [];
            $calc = calc_employee_day($shifts, $date, $is_hol, $settings, $prev_s, $prev_d);
            $reg_h += $calc['regular'];
            $ot_h += $calc['overtime'];
            $night_h += $calc['night'];
            $fri_h += $calc['friday_hours'];
            $hol_h += $calc['holiday_hours'];
            $salary_d = salary_at_date($history, $date, (float)($monthly_salary ?? 0));
            $regular_hourly_d = employee_hourly_rate_for_required_hours($salary_d, $required_info);
            $special_hourly_d = $salary_d / 26 / 8;
            $base_f += $calc['regular'] * $regular_hourly_d
                     + $calc['overtime'] * $special_hourly_d * $ot_c
                     + $calc['night'] * $special_hourly_d * $ni_c
                     + $calc['friday_hours'] * $special_hourly_d * $fr_c
                     + $calc['holiday_hours'] * $special_hourly_d * $ho_c;
        }
        $base_pay = round($base_f);
        return ['regular' => $reg_h, 'overtime' => $ot_h, 'night' => $night_h, 'friday' => $fri_h, 'holiday' => $hol_h, 'days' => 0, 'extra_days' => 0, 'base_pay' => $base_pay];
    }
}

// Handle Jalali year/month from dropdowns
$jy_current = (int)($_GET['jalali_year'] ?? 0);
$jm_current = (int)($_GET['jalali_month'] ?? 0);

if ($type === 'yearly_full') {
    // Yearly bilan — uses Jalali year
    $year_j = $_GET['year'] ?? 0;
    if (!$year_j) {
        $jnow = gregorian_to_jalali((int)date('Y'), (int)date('m'), 1);
        $year_j = $jnow[0];
    }
    $month = date('Y-m'); // not used for yearly
    $month_shamsi = '';
    $month_jalali_label = $year_j;
} else {
    // Monthly reports — use Jalali year+month
    if ($jy_current && $jm_current) {
        $month_shamsi = $jy_current . '-' . str_pad($jm_current, 2, '0', STR_PAD_LEFT);
    } else {
        $jnow = gregorian_to_jalali((int)date('Y'), (int)date('m'), 1);
        $jy_current = $jnow[0];
        $jm_current = $jnow[1];
        $month_shamsi = $jy_current . '-' . str_pad($jm_current, 2, '0', STR_PAD_LEFT);
    }
    $g = jalali_to_gregorian($jy_current, $jm_current, 1);
    $month = $g[0] . '-' . str_pad($g[1], 2, '0', STR_PAD_LEFT);
    [$rg_start, $rg_end] = jalali_month_bounds($jy_current, (int)$jm_current);
    $month_jalali_label = jalali_month_name($jm_current) . ' ' . $jy_current;
    $year_j = date('Y');
}

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('rep_title') ?></h2>
</div>

<!-- Report Tabs -->
<div class="tabs mb-4 animate-in">
    <?php if (in_array('salary', $__rep_allowed, true)): ?><a href="index.php?page=reports&type=salary&jalali_year=<?= $jy_current ?>&jalali_month=<?= str_pad($jm_current, 2, '0', STR_PAD_LEFT) ?>" class="tab <?= $type == 'salary' ? 'active' : '' ?>"><?= t('rep_salary') ?></a><?php endif; ?>
    <?php if (in_array('work_hours', $__rep_allowed, true)): ?><a href="index.php?page=reports&type=work_hours&jalali_year=<?= $jy_current ?>&jalali_month=<?= str_pad($jm_current, 2, '0', STR_PAD_LEFT) ?>" class="tab <?= $type == 'work_hours' ? 'active' : '' ?>"><?= t('rep_work_hours') ?></a><?php endif; ?>
    <?php if (in_array('bonuses', $__rep_allowed, true)): ?><a href="index.php?page=reports&type=bonuses&jalali_year=<?= $jy_current ?>&jalali_month=<?= str_pad($jm_current, 2, '0', STR_PAD_LEFT) ?>" class="tab <?= $type == 'bonuses' ? 'active' : '' ?>"><?= t('rep_bonuses') ?></a><?php endif; ?>
    <?php if (in_array('subcontractors', $__rep_allowed, true)): ?><a href="index.php?page=reports&type=subcontractors&jalali_year=<?= $jy_current ?>&jalali_month=<?= str_pad($jm_current, 2, '0', STR_PAD_LEFT) ?>" class="tab <?= $type == 'subcontractors' ? 'active' : '' ?>"><?= t('rep_subcontractors') ?></a><?php endif; ?>
    <?php if (in_array('monthly_full', $__rep_allowed, true)): ?><a href="index.php?page=reports&type=monthly_full&jalali_year=<?= $jy_current ?>&jalali_month=<?= str_pad($jm_current, 2, '0', STR_PAD_LEFT) ?>" class="tab <?= $type == 'monthly_full' ? 'active' : '' ?>"><?= t('rep_monthly_bilan') ?></a><?php endif; ?>
    <?php if (in_array('yearly_full', $__rep_allowed, true)): ?><a href="index.php?page=reports&type=yearly_full&year=<?= $year_j ?>" class="tab <?= $type == 'yearly_full' ? 'active' : '' ?>"><?= t('rep_yearly_bilan') ?></a><?php endif; ?>
</div>

<!-- Filter -->
<div class="card mb-4 animate-in">
    <div class="d-flex align-center gap-3 flex-wrap">
        <form method="GET" class="d-flex gap-2 align-center flex-wrap" id="filterForm">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="type" value="<?= $type ?>">
            <?php if ($type !== 'yearly_full'): ?>
            <label class="form-label" style="margin:0;white-space:nowrap"><?= t('rep_year') ?>:</label>
            <select name="jalali_year" class="form-control" style="width:100px" onchange="this.form.submit()">
                <?php for ($jy = 1404; $jy <= 1408; $jy++): ?>
                <option value="<?= $jy ?>" <?= ($jy == $jy_current) ? 'selected' : '' ?>><?= $jy ?></option>
                <?php endfor; ?>
            </select>
            <label class="form-label" style="margin:0;white-space:nowrap"><?= t('rep_month') ?>:</label>
            <select name="jalali_month" class="form-control" style="width:110px" onchange="this.form.submit()">
                <?php for ($jm = 1; $jm <= 12; $jm++): ?>
                <option value="<?= str_pad($jm, 2, '0', STR_PAD_LEFT) ?>" <?= ($jm == $jm_current) ? 'selected' : '' ?>><?= jalali_month_name($jm) ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><?= t('btn_show') ?></button>
            <?php else: ?>
            <label class="form-label" style="margin:0;white-space:nowrap"><?= t('rep_year') ?>:</label>
            <select name="year" class="form-control" style="width:100px" onchange="this.form.submit()">
                <?php for ($jy = 1404; $jy <= 1408; $jy++): ?>
                <option value="<?= $jy ?>" <?= ($jy == (int)$year_j) ? 'selected' : '' ?>><?= $jy ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><?= t('btn_show') ?></button>
            <?php endif; ?>
        </form>
        <div class="btn-group">
            <button onclick="exportReportCSV()" class="btn btn-success btn-sm"><i data-lucide="file-spreadsheet"></i> <?= t('btn_excel') ?></button>
            <button onclick="window.print()" class="btn btn-ghost btn-sm"><i data-lucide="printer"></i> <?= t('btn_print') ?></button>
        </div>
    </div>
</div>

<?php if ($type == 'salary'): ?>
<?php
// Real-time salary calculation
$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar, wp.daily_salary, wp.monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY u.full_name");
$stmt->execute([$ws_id]);
$all_personnel = $stmt->fetchAll();

$report_data = [];
foreach ($all_personnel as $p) {
    $calc = calc_person_month($pdo, $p['id'], $ws_id, $rg_start, $rg_end, $p['is_karigar'], $p['daily_salary'], $p['monthly_salary'], $settings);
    $reg_h = $calc ? $calc['regular'] : 0;
    $ot_h = $calc ? $calc['overtime'] : 0;
    $night_h = $calc ? $calc['night'] : 0;
    $fri_h = $calc ? $calc['friday'] : 0;
    $hol_h = $calc ? $calc['holiday'] : 0;
    $base_pay = $calc ? $calc['base_pay'] : 0;

    $stmt2 = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) as total FROM bonuses_penalties WHERE user_id = ? AND workshop_id = ? AND date BETWEEN ? AND ? GROUP BY type");
    $stmt2->execute([$p['id'], $ws_id, $rg_start, $rg_end]);
    $bp = ['bonus' => 0, 'penalty' => 0];
    while ($row = $stmt2->fetch()) { $bp[$row['type']] = $row['total']; }

    $advance = advance_deduction_month($pdo, $p['id'], $ws_id, $rg_start, $rg_end);

    $report_data[] = [
        'full_name' => $p['full_name'], 'is_karigar' => $p['is_karigar'],
        'regular_hours' => $reg_h, 'overtime_hours' => $ot_h, 'night_hours' => $night_h,
        'friday_hours' => $fri_h, 'holiday_hours' => $hol_h,
        'base_pay' => $base_pay, 'bonus' => $bp['bonus'], 'penalty' => $bp['penalty'], 'advance' => $advance,
        'net_pay' => $base_pay + $bp['bonus'] - $bp['penalty'] - $advance,
    ];
}

$chart_labels = array_map(fn($r) => $r['full_name'], $report_data);
$chart_values = array_map(fn($r) => $r['net_pay'], $report_data);
?>

<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title"><?= t('گزارش') ?> <?= t('nav_salary') ?> - <?= $month_jalali_label ?></h3></div>
    <div class="chart-container"><canvas id="salaryChart"></canvas></div>
</div>

<div class="card mt-4 animate-in">
    <div class="table-container" id="reportTable">
        <table>
            <thead><tr><th><?= t('wh_name') ?></th><th><?= t('sal_type') ?></th><th><?= t('rep_regular_hours') ?></th><th><?= t('rep_overtime_hours') ?></th><th><?= t('rep_night_hours') ?></th><th><?= t('wh_friday') ?></th><th><?= t('wh_holiday') ?></th><th><?= t('sal_base_pay') ?></th><th><?= t('sal_bonus') ?></th><th><?= t('sal_penalty') ?></th><th><?= t('sal_advance') ?></th><th><?= t('sal_net') ?></th></tr></thead>
            <tbody>
                <?php foreach ($report_data as $r): ?>
                <tr>
                    <td><?= $r['full_name'] ?></td>
                    <td><span class="tag <?= $r['is_karigar'] ? 'tag-karigar' : 'tag-active' ?>"><?= $r['is_karigar'] ? t('sal_worker') : t('sal_employee') ?></span></td>
                    <td><?= fmt_hm($r['regular_hours']) ?></td>
                    <td><?= fmt_hm($r['overtime_hours']) ?></td>
                    <td><?= fmt_hm($r['night_hours']) ?></td>
                    <td><?= fmt_hm($r['friday_hours']) ?></td>
                    <td><?= fmt_hm($r['holiday_hours']) ?></td>
                    <td><?= number_format($r['base_pay']) ?></td>
                    <td class="text-success"><?= $r['bonus'] > 0 ? number_format($r['bonus']) : '-' ?></td>
                    <td class="text-danger"><?= $r['penalty'] > 0 ? number_format($r['penalty']) : '-' ?></td>
                    <td><?= $r['advance'] > 0 ? number_format($r['advance']) : '-' ?></td>
                    <td><strong class="<?= $r['net_pay'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($r['net_pay']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('salaryChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{ label: '<?= t('خالص') ?> <?= t('پرداختی') ?>', data: <?= json_encode($chart_values) ?>, backgroundColor: 'rgba(99,102,241,0.3)', borderColor: 'rgba(99,102,241,1)', borderWidth: 2, borderRadius: 8 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });
});
</script>

<?php elseif ($type == 'work_hours'): ?>
<?php
$stmt = $pdo->prepare("SELECT u.full_name, u.is_karigar, SUM(TIME_TO_SEC(TIMEDIFF(wh.end_time, wh.start_time))/3600) as total_hours, COUNT(DISTINCT wh.work_date) as days, MAX(COALESCE(wh.mission_id,0)) as has_mission, GROUP_CONCAT(DISTINCT mws.name SEPARATOR '، ') as mission_sources FROM work_hours wh JOIN users u ON wh.user_id = u.id LEFT JOIN workshops mws ON mws.id = wh.mission_source_ws WHERE wh.workshop_id = ? AND wh.work_date BETWEEN ? AND ? GROUP BY wh.user_id ORDER BY total_hours DESC");
$stmt->execute([$ws_id, $rg_start, $rg_end]);
$hours_data = $stmt->fetchAll();
?>
<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title"><?= t('ساعات') ?> <?= t('کاری -') ?> <?= $month_jalali_label ?></h3></div>
    <div class="table-container" id="reportTable"><table>
        <thead><tr><th><?= t('wh_name') ?></th><th><?= t('sal_type') ?></th><th><?= t('rep_days') ?></th><th><?= t('کل') ?> <?= t('ساعات') ?></th><th><?= t('میانگین') ?> <?= t('sal_daily') ?></th></tr></thead>
        <tbody>
            <?php foreach ($hours_data as $h): ?>
            <tr>
                <td><?= $h['full_name'] ?>
                    <?php if ($h['has_mission']): ?><span class="tag" style="font-size:9px;background:#dbeafe;color:#1d4ed8" title="<?= t('wh_hours') ?>‌<?= t('های') ?> <?= t('نیروی کمکی') ?>/<?= t('مأموریت') ?> <?= t('در') ?> <?= t('این') ?> <?= t('کارگاه') ?> <?= t('ثبت') ?> <?= t('شده') ?> (<?= t('lea_from') ?> <?= t('کارگاه') ?> <?= t('اصلی') ?>: <?= $h['mission_sources'] ?>)">🚀 <?= t('مأموریت') ?></span><?php endif; ?>
                </td>
                <td><span class="tag <?= $h['is_karigar'] ? 'tag-karigar' : 'tag-active' ?>"><?= $h['is_karigar'] ? t('sal_worker') : t('sal_employee') ?></span></td>
                <td><?= $h['days'] ?></td>
                <td><?= number_format($h['total_hours'], 1) ?>h</td>
                <td><?= $h['days'] > 0 ? number_format($h['total_hours']/$h['days'], 1) : 0 ?>h</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php elseif ($type == 'bonuses'): ?>
<?php
$stmt = $pdo->prepare("SELECT u.full_name, bp.type, SUM(bp.amount) as total, SUM(bp.hours) as total_hours FROM bonuses_penalties bp JOIN users u ON bp.user_id = u.id WHERE bp.workshop_id = ? AND bp.date BETWEEN ? AND ? GROUP BY bp.user_id, bp.type ORDER BY u.full_name");
$stmt->execute([$ws_id, $rg_start, $rg_end]);
$bp_data = $stmt->fetchAll();
?>
<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title"><?= t('rep_bonuses') ?> - <?= $month_jalali_label ?></h3></div>
    <div class="table-container" id="reportTable"><table>
        <thead><tr><th><?= t('wh_name') ?></th><th><?= t('sal_type') ?></th><th><?= t('wh_hours') ?></th><th><?= t('مبلغ') ?></th></tr></thead>
        <tbody>
            <?php foreach ($bp_data as $b): ?>
            <tr><td><?= $b['full_name'] ?></td><td><span class="tag <?= $b['type'] == 'bonus' ? 'tag-active' : 'tag-rejected' ?>"><?= $b['type'] == 'bonus' ? t('sal_bonus') : t('sal_penalty') ?></span></td><td><?= fmt_hm((float)($b['total_hours'] ?? 0)) ?></td><td><?= number_format($b['total']) ?> <?= t('ت') ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php elseif ($type == 'monthly_full'): ?>
<?php
// BILAN MONTHLY - real-time calculation
$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar, wp.daily_salary, wp.monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY u.full_name");
$stmt->execute([$ws_id]);
$all_personnel = $stmt->fetchAll();

$monthly_data = [];
foreach ($all_personnel as $p) {
    $calc = calc_person_month($pdo, $p['id'], $ws_id, $rg_start, $rg_end, $p['is_karigar'], $p['daily_salary'], $p['monthly_salary'], $settings);
    $d = ['name' => $p['full_name'], 'is_karigar' => $p['is_karigar']];
    $d['days'] = $calc ? $calc['days'] : 0;
    $d['hours'] = $calc ? ($calc['regular'] + $calc['overtime'] + $calc['night']) : 0;
    $d['overtime_hours'] = $calc ? $calc['overtime'] : 0;
    $d['night_hours'] = $calc ? $calc['night'] : 0;
    $d['base_pay'] = $calc ? $calc['base_pay'] : 0;

    $stmt2 = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) as total FROM bonuses_penalties WHERE user_id = ? AND workshop_id = ? AND date BETWEEN ? AND ? GROUP BY type");
    $stmt2->execute([$p['id'], $ws_id, $rg_start, $rg_end]);
    $bp = ['bonus' => 0, 'penalty' => 0];
    while ($row = $stmt2->fetch()) { $bp[$row['type']] = $row['total']; }
    $d['bonus'] = $bp['bonus'];
    $d['penalty'] = $bp['penalty'];

    $d['advance'] = advance_deduction_month($pdo, $p['id'], $ws_id, $rg_start, $rg_end);

    $d['net'] = $d['base_pay'] + $d['bonus'] - $d['penalty'] - $d['advance'];
    $monthly_data[] = $d;
}

$total_base = array_sum(array_map(fn($d) => $d['base_pay'], $monthly_data));
$total_bonus = array_sum(array_map(fn($d) => $d['bonus'], $monthly_data));
$total_penalty = array_sum(array_map(fn($d) => $d['penalty'], $monthly_data));
$total_advance = array_sum(array_map(fn($d) => $d['advance'], $monthly_data));
$total_net = array_sum(array_map(fn($d) => $d['net'], $monthly_data));
?>

<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title"><?= t('rep_monthly_bilan') ?> - <?= $month_jalali_label ?></h3></div>
    <div class="table-container" id="reportTable"><table>
        <thead><tr><th><?= t('wh_name') ?></th><th><?= t('sal_type') ?></th><th><?= t('rep_days') ?>/<?= t('ساعات') ?></th><th><?= t('nav_salary') ?> <?= t('sal_base_pay') ?></th><th><?= t('sal_bonus') ?></th><th><?= t('sal_penalty') ?></th><th><?= t('sal_advance') ?></th><th><?= t('sal_net') ?></th></tr></thead>
        <tbody>
            <?php foreach ($monthly_data as $d): ?>
            <tr>
                <td><strong><?= $d['name'] ?></strong></td>
                <td><span class="tag <?= $d['is_karigar'] ? 'tag-karigar' : 'tag-active' ?>"><?= $d['is_karigar'] ? t('sal_worker') : t('sal_employee') ?></span></td>
                <td><?= $d['is_karigar'] ? number_format($d['days'], 1) . ' ' . t('روز') : fmt_hm($d['hours']) ?></td>
                <td><?= number_format($d['base_pay']) ?></td>
                <td class="text-success"><?= $d['bonus'] > 0 ? number_format($d['bonus']) : '-' ?></td>
                <td class="text-danger"><?= $d['penalty'] > 0 ? number_format($d['penalty']) : '-' ?></td>
                <td><?= $d['advance'] > 0 ? number_format($d['advance']) : '-' ?></td>
                <td><strong class="<?= $d['net'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($d['net']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:bold;background:var(--bg)">
                <td colspan="3"><?= t('rep_total') ?> <?= t('کل') ?></td>
                <td><?= number_format($total_base) ?></td>
                <td class="text-success"><?= number_format($total_bonus) ?></td>
                <td class="text-danger"><?= number_format($total_penalty) ?></td>
                <td><?= number_format($total_advance) ?></td>
                <td class="<?= $total_net >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($total_net) ?></td>
            </tr>
        </tfoot>
    </table></div>
</div>

<?php elseif ($type == 'yearly_full'): ?>
<?php
// BILAN YEARLY - real-time calculation
$jy = (int)$year_j;
$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar, wp.daily_salary, wp.monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY full_name");
$stmt->execute([$ws_id]);
$all_p = $stmt->fetchAll();

$yearly_months = [];
for ($jm = 1; $jm <= 12; $jm++) {
    [$ys, $ye] = jalali_month_bounds($jy, $jm);
    $yearly_months[$jm] = ['label' => jalali_month_name($jm), 'start' => $ys, 'end' => $ye];
}

$yearly_data = [];
foreach ($all_p as $p) {
    $uid = $p['id'];
    $d = ['name' => $p['full_name'], 'is_karigar' => $p['is_karigar'], 'months' => [], 'total' => 0];

    foreach ($yearly_months as $jm => $minfo) {
        $calc = calc_person_month($pdo, $uid, $ws_id, $minfo['start'], $minfo['end'], $p['is_karigar'], $p['daily_salary'], $p['monthly_salary'], $settings);
        $pay = $calc ? $calc['base_pay'] : 0;

        $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bonuses_penalties WHERE user_id = ? AND workshop_id = ? AND type = 'bonus' AND date BETWEEN ? AND ?");
        $stmt2->execute([$uid, $ws_id, $minfo['start'], $minfo['end']]);
        $bonus = (float)$stmt2->fetchColumn();

        $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bonuses_penalties WHERE user_id = ? AND workshop_id = ? AND type = 'penalty' AND date BETWEEN ? AND ?");
        $stmt2->execute([$uid, $ws_id, $minfo['start'], $minfo['end']]);
        $penalty = (float)$stmt2->fetchColumn();

        $advance = advance_deduction_month($pdo, $uid, $ws_id, $minfo['start'], $minfo['end']);

        $month_net = $pay + $bonus - $penalty - $advance;
        $d['months'][$jm] = $month_net;
        $d['total'] += $month_net;
    }
    $yearly_data[] = $d;
}

$month_totals = [];
for ($jm = 1; $jm <= 12; $jm++) {
    $month_totals[$jm] = 0;
    foreach ($yearly_data as $d) { $month_totals[$jm] += $d['months'][$jm] ?? 0; }
}
$grand_total = array_sum($month_totals);
?>

<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title"><?= t('rep_yearly_bilan') ?> - <?= $jy ?></h3></div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr>
                <th><?= t('wh_name') ?></th>
                <?php for ($jm = 1; $jm <= 12; $jm++): ?>
                <th style="font-size:11px"><?= $yearly_months[$jm]['label'] ?></th>
                <?php endfor; ?>
                <th><?= t('rep_total') ?> <?= t('کل') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($yearly_data as $d): ?>
            <tr>
                <td><strong><?= $d['name'] ?></strong></td>
                <?php for ($jm = 1; $jm <= 12; $jm++): ?>
                <td><?= ($d['months'][$jm] ?? 0) > 0 ? number_format($d['months'][$jm]) : '-' ?></td>
                <?php endfor; ?>
                <td><strong class="<?= $d['total'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($d['total']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:bold;background:var(--bg)">
                <td><?= t('rep_total') ?> <?= t('sal_monthly') ?></td>
                <?php for ($jm = 1; $jm <= 12; $jm++): ?>
                <td><?= $month_totals[$jm] > 0 ? number_format($month_totals[$jm]) : '-' ?></td>
                <?php endfor; ?>
                <td class="<?= $grand_total >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($grand_total) ?></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>

<?php elseif ($type == 'subcontractors'): ?>
<?php
// مانده پیمانکار = خدمات + کارکرد ساعتیِ پرسنل متصل + حساب قبلی − پرداخت‌ها.
$stmt = $pdo->prepare("SELECT s.*, u.full_name AS linked_person_name FROM subcontractors s LEFT JOIN users u ON u.id=s.linked_user_id WHERE s.workshop_id = ? AND s.status = 'active'");
$stmt->execute([$ws_id]);
$sub_data = $stmt->fetchAll();
?>
<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title"><?= t('nav_subcontractors') ?></h3></div>
    <div class="table-container" id="reportTable"><table>
        <thead><tr><th><?= t('wh_name') ?></th><th><?= t('قرارداد') ?></th><th><?= t('خدمات') ?></th><th><?= t('کارکرد ساعتی') ?></th><th><?= t('حساب') ?> <?= t('قبلی') ?></th><th><?= t('پرداختی') ?></th><th><?= t('pet_balance') ?></th></tr></thead>
        <tbody>
            <?php foreach ($sub_data as $s): $__sub_sum = sub_balance_upto($pdo, (int)$s['id'], null); $__bal = (float)$__sub_sum['balance']; ?>
            <tr>
                <td><?= htmlspecialchars($s['name']) ?><?= !empty($s['linked_person_name']) ? '<div class="text-muted" style="font-size:10px">🔗 ' . htmlspecialchars($s['linked_person_name']) . '</div>' : '' ?></td>
                <td><?= number_format($s['total_contract']) ?></td>
                <td><?= number_format((float)$__sub_sum['face'] + (float)$__sub_sum['daily']) ?></td>
                <td style="color:#6d28d9"><?= number_format((float)$__sub_sum['personnel_claim']) ?></td>
                <td><?= $__sub_sum['opening'] != 0 ? number_format($__sub_sum['opening']) : '—' ?></td>
                <td><?= number_format($__sub_sum['paid']) ?></td>
                <td class="<?= $__bal >= 0 ? 'text-danger' : 'text-success' ?>"><strong><?= number_format($__bal) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<script>
function exportReportCSV() {
    // §v13: خروجی xlsx واقعی (قبلاً Tab-separated با پسوند csv بود و همه‌چیز در یک ستون می‌افتاد)
    exportTableToXlsx('reportTable', 'report_<?= $type ?>_<?= $month ?>');
}
</script>

<?php include 'includes/footer.php'; ?>
