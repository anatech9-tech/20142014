<?php
// display_errors عمداً روشن نیست — خطاها نباید روی اسناد چاپی دیده شوند
ini_set('log_errors', 0);

require_once __DIR__ . '/../includes/salary_calc.php';

$ws_id = $_SESSION['workshop_id'] ?? 0;
$uid = (int)($_GET['user_id'] ?? 0);
$selected_month = $_GET['month'] ?? '';

if (!$uid) { echo t('کاربر یافت نشد'); exit; }

// §fix: افزایش‌های رسیده را در ستون حقوق فعلی هم اعمال کن
salary_sync_current($pdo);

$is_self = ($uid === (int)$_SESSION['user_id']);
$is_super = can_see_all_workshops(); // مدیر کل، مدیر پروژه، ناظر کل
$is_manager = has_permission('manage_salary') || is_workshop_manager();

// بررسی عضویت فعال در کارگاه فعال سشن
$chk_ws = $pdo->prepare("SELECT 1 FROM workshop_personnel WHERE user_id = ? AND workshop_id = ? AND status = 'active'");
$chk_ws->execute([$uid, $ws_id]);
$is_my_personnel = (bool)$chk_ws->fetchColumn();

if ($is_self) {
    // بند ۲ کاربر: هر کس بتواند تایم‌شیت خودش را کامل (همراه با تمامی مأموریت‌ها در همه کارگاه‌ها) ببیند
    $detail_scope = 'all';
    $d_ws = 0;
} elseif ($is_super) {
    // دسترسی سراسری مدیر ارشد / مدیر پروژه / ناظر کل
    $detail_scope = (($_GET['scope'] ?? 'all') === 'ws') ? 'ws' : 'all';
    $d_ws = $detail_scope === 'ws' ? (int)$ws_id : 0;
} elseif ($is_manager && $is_my_personnel) {
    // بند ۳ کاربر: مدیر کارگاه تایم‌شیت نیروهای کارگاه خودش را کامل ببیند (کارکرد در همه کارگاه‌ها از گذشته تا آینده)
    $detail_scope = 'all';
    $d_ws = 0;
} else {
    // بند ۴ کاربر: مدیر کارگاه مقصد مأموریت نباید بتواند تایم‌شیت و حقوق نیروی مأمور را ببیند
    echo '<div style="font-family:tahoma,sans-serif;padding:30px;text-align:center;direction:rtl"><h3>' . t('دسترسی غیرمجاز') . '</h3><p>' . t('شما فقط به پرسنل و کارکرد کارگاه اختصاصی خود دسترسی دارید و امکان مشاهده کارکرد نیروهای مأمور یا سایر کارگاه‌ها را ندارید.') . '</p><p><a href="index.php?page=salary">' . t('بازگشت به صفحه حقوق') . '</a></p></div>';
    exit;
}

$settings = get_workshop_settings($pdo, $d_ws ?: $ws_id);

// COALESCE جلوی number_format(null) برای نیروهای منتقل‌شده/پایان‌کارخورده را می‌گیرد.
if ($d_ws > 0) {
    $stmt = $pdo->prepare("SELECT u.*, COALESCE(wp.daily_salary,0) AS daily_salary, COALESCE(wp.monthly_salary,0) AS monthly_salary, wp.status AS wp_status FROM users u LEFT JOIN workshop_personnel wp ON wp.user_id=u.id AND wp.workshop_id=? WHERE u.id=?");
    $stmt->execute([$d_ws, $uid]);
} else {
    $stmt = $pdo->prepare("SELECT u.*, COALESCE((SELECT MAX(daily_salary) FROM workshop_personnel WHERE user_id=u.id AND status='active'), (SELECT MAX(daily_salary) FROM workshop_personnel WHERE user_id=u.id), 0) AS daily_salary, COALESCE((SELECT MAX(monthly_salary) FROM workshop_personnel WHERE user_id=u.id AND status='active'), (SELECT MAX(monthly_salary) FROM workshop_personnel WHERE user_id=u.id), 0) AS monthly_salary, 'aggregate' AS wp_status FROM users u WHERE u.id=?");
    $stmt->execute([$uid]);
}
$user = $stmt->fetch();
if (!$user) { echo t('کاربر یافت نشد'); exit; }
// یک منبع مشترک با صفحهٔ لیست حقوق
$user = array_merge($user, salary_effective_base($pdo, $uid, $d_ws));

$is_karigar = (int)$user['is_karigar'];

// هر user_id در صفحهٔ جزئیات مستقل است. هم‌نامی یا عضویت در چند کارگاه
// به‌تنهایی مجوز ادغام سوابق نیست؛ هیچ حساب دیگری به‌صورت خودکار به این ردیف
// اضافه نمی‌شود. رابطهٔ احتمالی باید با diagnostic و تأیید مدیر احراز شود.
$detail_user_ids = [$uid];
// §v13: پارامتر merged_ids دیگر پذیرفته نمی‌شود. شناسه‌های تکراری در خود
// دیتابیس ادغام شده‌اند و دو نفرِ هم‌نام باید کاملاً جدا بمانند.

$detail_user_placeholders = implode(',', array_fill(0, count($detail_user_ids), '?'));

// Get available months — ماه‌های شمسی (از ۱ تا آخر همان ماه) — §v8 تجمیعی
$stmt = $pdo->prepare("SELECT DISTINCT work_date FROM work_hours WHERE user_id IN ($detail_user_placeholders)" . _ws_filter($d_ws));
$stmt->execute($detail_user_ids);
$jkeys = [];
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $wd) { $jkeys[jalali_month_key($wd)] = true; }
$stmt = $pdo->prepare("SELECT DISTINCT date FROM bonuses_penalties WHERE user_id IN ($detail_user_placeholders)" . _ws_filter($d_ws));
$stmt->execute($detail_user_ids);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $bd) { $jkeys[jalali_month_key($bd)] = true; }
$stmt = $pdo->prepare("SELECT DISTINCT DATE(CASE WHEN status = 'paid' AND paid_at IS NOT NULL THEN paid_at ELSE COALESCE(approved_at, created_at) END) AS d FROM advances WHERE user_id IN ($detail_user_placeholders)" . _ws_filter($d_ws) . " AND status IN ('approved','paid')");
$stmt->execute($detail_user_ids);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ad) { if ($ad) $jkeys[jalali_month_key($ad)] = true; }
$months = array_keys($jkeys);
usort($months, function($a, $b) {
    [$ay, $am] = array_map('intval', explode('-', $a));
    [$by, $bm] = array_map('intval', explode('-', $b));
    if ($ay !== $by) return $by - $ay;
    return $bm - $am;
});

if (empty($months)) { echo t('هیچ داده‌ای (ساعت کاری/پاداش/مساعده) ثبت نشده'); exit; }

if (!$selected_month || !in_array($selected_month, $months)) { $selected_month = $months[0]; }

// بازه میلادی همین ماه شمسی
$jmi = jalali_month_from_key($selected_month);
$m_start = $jmi['start'];
$m_end = $jmi['end'];
$month_label = $jmi['label'];
// ساعت کاری الزامی ماه = روزهای تقویمیِ کاری − مرخصیِ تأییدشده؛ غیبت بدون مرخصی کم نمی‌شود.
$required_hours_info = !$is_karigar ? employee_required_hours($pdo, $uid, $d_ws, $m_start, $m_end, $settings) : null;

// Get all work hours for this jalali month (§v8: همراه نام کارگاه)
$stmt = $pdo->prepare("SELECT wh.*, w.name AS ws_name FROM work_hours wh LEFT JOIN workshops w ON w.id = wh.workshop_id WHERE wh.user_id IN ($detail_user_placeholders)" . _ws_filter($d_ws, 'wh.workshop_id') . " AND wh.work_date BETWEEN ? AND ? ORDER BY wh.work_date, wh.start_time");
$stmt->execute(array_merge($detail_user_ids, [$m_start, $m_end]));
$hours = $stmt->fetchAll();

$daily = [];
foreach ($hours as $h) { $daily[$h['work_date']][] = $h; }

// Get holidays
$stmt = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$stmt->execute([$m_start, $m_end]);
$holidays = array_column($stmt->fetchAll(), 'holiday_date');

$hourly_base = $is_karigar ? 0 : employee_hourly_rate_for_required_hours((float)($user['monthly_salary'] ?? 0), $required_hours_info);
$overtime_coef = (float)($settings['overtime_coefficient'] ?? 1.33);
$night_coef = (float)($settings['night_coefficient'] ?? 2.0);
$friday_coef = (float)($settings['friday_coefficient'] ?? 1.33);
$holiday_coef = (float)($settings['holiday_coefficient'] ?? 2.0);

$totals = [
    'regular' => 0, 'overtime' => 0, 'night' => 0,
    'friday_hours' => 0, 'holiday_hours' => 0,
    'regular_pay' => 0, 'overtime_pay' => 0, 'night_pay' => 0,
    'friday_pay' => 0, 'holiday_pay' => 0,
    'total_days' => 0, 'extra_days' => 0, 'payable_hours' => 0,
];

$daily_detail = [];
$sorted_dates = array_keys($daily);
sort($sorted_dates);

// §1: نرخ هر روز فقط از تاریخچهٔ همین user_id خوانده می‌شود؛ حساب هم‌نام دیگری
// بدون رابطهٔ تأییدشده وارد این جزئیات نمی‌شود.
$history = [];
foreach ($detail_user_ids as $history_uid) {
    foreach (get_salary_history($pdo, $history_uid) as $history_row) {
        $history_row['_source_uid'] = (int)$history_uid;
        $history[] = $history_row;
    }
}
usort($history, function ($a, $b) use ($uid) {
    $date_cmp = strcmp((string)$a['start_date'], (string)$b['start_date']);
    if ($date_cmp !== 0) return $date_cmp;
    if ((int)$a['_source_uid'] === (int)$uid && (int)$b['_source_uid'] !== (int)$uid) return 1;
    if ((int)$b['_source_uid'] === (int)$uid && (int)$a['_source_uid'] !== (int)$uid) return -1;
    return 0;
});
$base_pay_f = 0.0;
$buck = ['regular'=>0.0,'overtime'=>0.0,'night'=>0.0,'friday'=>0.0,'holiday'=>0.0];

foreach ($sorted_dates as $idx => $date) {
    $shifts = $daily[$date];
    $is_hol = in_array($date, $holidays);
    $is_fri = (date('w', strtotime($date)) == 5);
    $jalali_date = to_jalali($date);

    // Get previous day's shifts for late arrival rule
    $prev_date_str = '';
    $prev_shifts_for_calc = [];
    if ($idx > 0) {
        $prev_date_str = $sorted_dates[$idx - 1];
        $prev_shifts_for_calc = $daily[$prev_date_str] ?? [];
    }

    $day_names = localized_weekday_names();
    $day_info = [
        'date' => $date,
        'jalali' => $jalali_date,
        'day_name' => $day_names[date('w', strtotime($date))],
        'is_friday' => $is_fri,
        'is_holiday' => $is_hol,
        'workshop' => $shifts[0]['ws_name'] ?? '',
        'mission' => !empty($shifts[0]['mission_id']) || !empty($shifts[0]['mission_source_ws']),
        // §v14: ساعت‌ها شیف‌به‌شیفت — روزهای چندشیفتی در چاپ هر شیف در خط
        // مجزا نشان داده می‌شود تا ساعت‌ها روی هم نیفتند.
        'shift_labels' => array_map(function ($sh) {
            $s = substr((string)($sh['start_time'] ?? ''), 0, 5);
            $e = substr((string)($sh['end_time'] ?? ''), 0, 5);
            return [
                'start' => $s !== '' ? $s : '—',
                'end'   => $e === '' ? '—' : ($e === '00:00' ? t('باز') : $e),
                'workshop' => $sh['ws_name'] ?? '',
                'is_mission' => !empty($sh['mission_id']) || !empty($sh['mission_source_ws']),
            ];
        }, $shifts),
        'regular' => 0, 'overtime' => 0, 'night' => 0,
        'friday_hours' => 0, 'holiday_hours' => 0,
        'total_worked' => 0,
    ];

    if ($is_karigar) {
        $calc = calc_worker_day($shifts, $date, $is_hol, $user['daily_salary'], $prev_shifts_for_calc, $prev_date_str);
        $day_info['total_worked'] = $calc['total_worked'];
        $day_info['paid_hours'] = $calc['paid_hours'];
        $day_info['overtime_hours'] = $calc['overtime_hours'];
        $day_info['day_count'] = $calc['day_count'];
        $day_info['extra_days'] = $calc['extra_days'];
        $totals['total_days'] += $calc['day_count'];
        $totals['extra_days'] += $calc['extra_days'];
        $totals['payable_hours'] += $calc['payable_hours'];
        $base_pay_f += $calc['payable_hours'] * (salary_at_date($history, $date, $user['daily_salary']) / 8);
    } else {
        $calc = calc_employee_day($shifts, $date, $is_hol, $settings, $prev_shifts_for_calc, $prev_date_str);
        $day_info['regular'] = $calc['regular'];
        $day_info['overtime'] = $calc['overtime'];
        $day_info['night'] = $calc['night'];
        $day_info['friday_hours'] = $calc['friday_hours'];
        $day_info['holiday_hours'] = $calc['holiday_hours'];
        $day_info['total_worked'] = $calc['regular'] + $calc['overtime'] + $calc['night'] + $calc['friday_hours'] + $calc['holiday_hours'];

        $totals['regular'] += $calc['regular'];
        $totals['overtime'] += $calc['overtime'];
        $totals['night'] += $calc['night'];
        $totals['friday_hours'] += $calc['friday_hours'];
        $totals['holiday_hours'] += $calc['holiday_hours'];

        $salary_d = salary_at_date($history, $date, (float)($user['monthly_salary'] ?? 0));
        $regular_hourly_d = employee_hourly_rate_for_required_hours($salary_d, $required_hours_info);
        $special_hourly_d = $salary_d / 26 / 8;
        $buck['regular'] += $calc['regular'] * $regular_hourly_d;
        $buck['overtime'] += $calc['overtime'] * $special_hourly_d * $overtime_coef;
        $buck['night'] += $calc['night'] * $special_hourly_d * $night_coef;
        $buck['friday'] += $calc['friday_hours'] * $special_hourly_d * $friday_coef;
        $buck['holiday'] += $calc['holiday_hours'] * $special_hourly_d * $holiday_coef;
    }

    $daily_detail[] = $day_info;
}

// Calculate pay — نرخ هر روز در حلقه اعمال شد؛ اینجا فقط رند نهایی (§1)
if (!$is_karigar) {
    $totals['regular_pay'] = round($buck['regular']);
    $totals['overtime_pay'] = round($buck['overtime']);
    $totals['night_pay'] = round($buck['night']);
    $totals['friday_pay'] = round($buck['friday']);
    $totals['holiday_pay'] = round($buck['holiday']);
    $totals['base_pay'] = $totals['regular_pay'] + $totals['overtime_pay'] + $totals['night_pay'] + $totals['friday_pay'] + $totals['holiday_pay'];
} else {
    // مستند §6.5 + §1: حقوق کارگر = ساعت قابل‌پرداخت × (روزانه همان روز / ۸)
    $totals['base_pay'] = round($base_pay_f);
}
// نرخ ساعتی نمایشی = نرخ مؤثر در پایان ماه
$hourly_base = $is_karigar
    ? salary_at_date($history, $m_end, $user['daily_salary']) / 8
    : employee_hourly_rate_for_required_hours(salary_at_date($history, $m_end, (float)($user['monthly_salary'] ?? 0)), $required_hours_info);

// Bonuses/Penalties for this month
$bonuses_penalties = array();
$bonus_total = 0;
$penalty_total = 0;
try {
    $stmt = $pdo->prepare("SELECT type, amount, hours, hourly_rate, reason, date FROM bonuses_penalties WHERE user_id IN ($detail_user_placeholders)" . _ws_filter($d_ws) . " AND status = 'approved' AND date BETWEEN ? AND ? ORDER BY date");
    $stmt->execute(array_merge($detail_user_ids, [$m_start, $m_end]));
    $bonuses_penalties = $stmt->fetchAll();
    foreach ($bonuses_penalties as $bp) {
        if ($bp['type'] === 'bonus') $bonus_total += $bp['amount'];
        else $penalty_total += $bp['amount'];
    }
} catch (PDOException $e) { }

// Advances for this month
$advances = array();
$advance_total = 0;
try {
    $stmt = $pdo->prepare("SELECT amount, reason, payment_type, created_at, CASE WHEN status = 'paid' AND paid_at IS NOT NULL THEN paid_at ELSE approved_at END AS effective_at, status FROM advances WHERE user_id IN ($detail_user_placeholders)" . _ws_filter($d_ws) . " AND payment_type IN ('advance','salary','debt') AND ((status = 'paid' AND paid_at IS NOT NULL AND DATE(paid_at) BETWEEN ? AND ?) OR (status = 'approved' AND approved_at IS NOT NULL AND DATE(approved_at) BETWEEN ? AND ?)) ORDER BY effective_at");
    $stmt->execute(array_merge($detail_user_ids, [$m_start, $m_end, $m_start, $m_end]));
    $advances = $stmt->fetchAll();
    foreach ($advances as $a) { $advance_total += $a['amount']; }
} catch (PDOException $e) { }

$net_pay = $totals['base_pay'] + $bonus_total - $penalty_total - $advance_total;

// ── مطالبات ماه‌های قبل: جمع خالص همه ماه‌های قبل از این ماه ──
$prev_balance = 0;
try {
    // کارکرد قبل از شروع این ماه (روزبه‌روز، معادل جمع ماه‌های قبلی)
    $st = $pdo->prepare("SELECT * FROM work_hours WHERE user_id IN ($detail_user_placeholders)" . _ws_filter($d_ws) . " AND work_date < ? ORDER BY work_date, start_time");
    $st->execute(array_merge($detail_user_ids, [$m_start]));
    $prev_hours = $st->fetchAll();
    $prev_daily = array();
    foreach ($prev_hours as $h) { $prev_daily[$h['work_date']][] = $h; }

    // تعطیلات کل تاریخچه
    $st = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date < ?");
    $st->execute([$m_start]);
    $prev_holidays = array_column($st->fetchAll(), 'holiday_date');

    $prev_dates = array_keys($prev_daily);
    sort($prev_dates);
    $prev_base = 0;
    if ($is_karigar) {
        $pd_payable = 0;
        foreach ($prev_dates as $di => $pd) {
            $p_shifts = $prev_daily[$pd];
            $p_hol = in_array($pd, $prev_holidays);
            $p_prev = ($di > 0) ? $prev_dates[$di - 1] : '';
            $p_prev_shifts = $p_prev ? ($prev_daily[$p_prev] ?? []) : [];
            $pc = calc_worker_day($p_shifts, $pd, $p_hol, $user['daily_salary'], $p_prev_shifts, $p_prev);
            $pd_payable += $pc['payable_hours'] * (salary_at_date($history, $pd, $user['daily_salary']) / 8);
        }
        $prev_base = round($pd_payable);
    } else {
        // موتور مشترک ماهانه با مخرج «ساعات کاری الزامی»؛ از اختلاف فرم/جزئیات جلوگیری می‌کند.
        $prev_base = !empty($prev_dates)
            ? employee_month_pay($pdo, $uid, $d_ws, $prev_dates[0], date('Y-m-d', strtotime($m_start . ' -1 day')), (float)($user['monthly_salary'] ?? 0), $settings)
            : 0;
    }

    // پاداش و جریمه قبل از این ماه
    $st = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) t FROM bonuses_penalties WHERE user_id IN ($detail_user_placeholders)" . _ws_filter($d_ws) . " AND status = 'approved' AND date < ? GROUP BY type");
    $st->execute(array_merge($detail_user_ids, [$m_start]));
    $prev_bonus = 0; $prev_pen = 0;
    foreach ($st->fetchAll() as $r) {
        if ($r['type'] === 'bonus') $prev_bonus = (float)$r['t'];
        else $prev_pen = (float)$r['t'];
    }

    // مساعده/بدهی قبل از این ماه — شامل حساب قدیمیِ همین نیروی تکرارشده
    $du_ph = implode(',', array_fill(0, count($detail_user_ids), '?'));
    $du_sql = "SELECT COALESCE(SUM(amount),0) FROM advances WHERE user_id IN ($du_ph)" . _ws_filter($d_ws) . " AND payment_type IN ('advance','salary','debt') AND ((status = 'paid' AND paid_at IS NOT NULL AND DATE(paid_at) < ?) OR (status = 'approved' AND approved_at IS NOT NULL AND DATE(approved_at) < ?))";
    $du_stmt = $pdo->prepare($du_sql);
    $du_stmt->execute(array_merge($detail_user_ids, [$m_start, $m_start]));
    $prev_adv = (float)$du_stmt->fetchColumn();

    $prev_balance = $prev_base + $prev_bonus - $prev_pen - $prev_adv;
} catch (PDOException $e) { $prev_balance = 0; }
$balance_upto = $prev_balance + $net_pay;

$bp_by_date = array();
foreach ($bonuses_penalties as $bp) {
    $d = $bp['date'];
    if (!isset($bp_by_date[$d])) $bp_by_date[$d] = array();
    $bp_by_date[$d][] = $bp;
}
$adv_by_date = array();
foreach ($advances as $a) {
    $d = substr((string)($a['effective_at'] ?? $a['created_at']), 0, 10);
    if (!isset($adv_by_date[$d])) $adv_by_date[$d] = array();
    $adv_by_date[$d][] = $a;
}

// ساختار جدید خلاصه: درآمد جاری و ماندهٔ قبلی جدا، سپس کسورات/پاداش و مبلغ نهایی.
$current_month_gross = (float)$totals['base_pay'];
$total_gross_claim = (float)$prev_balance + $current_month_gross;
$final_payable = (float)$balance_upto;

// §fix: حقوقِ نمایش‌داده‌شده در هدر باید همان حقوقی باشد که محاسبات استفاده می‌کنند
// (حقوق مؤثر از تاریخچه در پایان همین ماه یا امروز — هرکدام زودتر)، نه ستون خام پرسنل.
$__hdr_date = min($m_end, date('Y-m-d'));
$hdr_daily = salary_at_date($history, $__hdr_date, (float)($user['daily_salary'] ?? 0));
$hdr_monthly = salary_at_date($history, $__hdr_date, (float)($user['monthly_salary'] ?? 0));

$print_mode = isset($_GET['print']);
// §v13: جهت کاغذ انتخابی کاربر — پیش‌فرض عمودی (portrait)
$print_orient = (($_GET['orient'] ?? 'portrait') === 'landscape') ? 'landscape' : 'portrait';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('sal_details') ?> <?= t('حساب') ?> - <?= htmlspecialchars($user['full_name']) ?> - <?= $month_label ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/glass.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:<?= $lang_dir ?>; background:#f0f2f5; color:#1a1a2e; }
        .page-container { max-width:900px; width:calc(100% - 24px); margin:20px auto; background:white; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow:hidden; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; padding:16px 24px; background:linear-gradient(135deg,#667eea,#764ba2); color:white; }
        .top-bar h1 { font-size:18px; font-weight:700; }
        .top-bar a { color:white; text-decoration:none; background:rgba(255,255,255,0.2); padding:6px 14px; border-radius:8px; font-size:13px; }
        .top-bar a:hover { background:rgba(255,255,255,0.3); }
        .user-info { padding:20px 24px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .user-name { font-size:22px; font-weight:700; }
        .user-meta { font-size:13px; color:#666; margin-top:4px; }
        .user-type { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
        .type-employee { background:#e0f2fe; color:#0369a1; }
        .type-worker { background:#fef3c7; color:#92400e; }
        .month-selector { padding:16px 24px; border-bottom:1px solid #eee; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .month-btn { padding:6px 14px; border:1px solid #ddd; border-radius:8px; background:white; cursor:pointer; font-size:12px; font-family:inherit; text-decoration:none; color:#333; }
        .month-btn:hover { border-color:#667eea; color:#667eea; }
        .month-btn.active { background:#667eea; color:white; border-color:#667eea; }
        .summary { padding:20px 24px; border-bottom:1px solid #eee; }
        .summary h3 { font-size:15px; font-weight:700; margin-bottom:14px; color:#333; }
        .summary-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px; }
        .summary-card { padding:12px; border-radius:10px; border:1px solid #f0f0f0; }
        .summary-card .label { font-size:11px; color:#888; margin-bottom:4px; }
        .summary-card .value { font-size:18px; font-weight:700; }
        .summary-card .sub { font-size:11px; color:#999; margin-top:2px; }
        .sc-green { background:#f0fdf4; } .sc-green .value { color:#16a34a; }
        .sc-yellow { background:#fefce8; } .sc-yellow .value { color:#ca8a04; }
        .sc-purple { background:#faf5ff; } .sc-purple .value { color:#9333ea; }
        .sc-blue { background:#eff6ff; } .sc-blue .value { color:#2563eb; }
        .sc-red { background:#fef2f2; } .sc-red .value { color:#dc2626; }
        .sc-gray { background:#f8fafc; } .sc-gray .value { color:#475569; }
        .pay-summary { padding:16px 24px; border-bottom:1px solid #eee; background:#f8fafc; }
        .pay-row { display:flex; justify-content:space-between; padding:6px 0; font-size:14px; }
        .pay-row.total { border-top:2px solid #333; margin-top:6px; padding-top:10px; font-weight:700; font-size:16px; }
        .daily-section { padding:20px 24px; }
        .daily-section h3 { font-size:15px; font-weight:700; margin-bottom:12px; color:#333; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th { background:#f1f5f9; padding:10px 8px; text-align:right; font-weight:600; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
        td { padding:8px; border-bottom:1px solid #f1f5f9; }
        tr:hover { background:#f8fafc; }
        .friday-row { background:#fff7ed !important; }
        .holiday-row { background:#fef2f2 !important; }
        .bp-tag { display:inline-block; padding:2px 8px; border-radius:12px; font-size:10px; font-weight:500; }
        .bp-bonus { background:#dcfce7; color:#166534; }
        .bp-penalty { background:#fee2e2; color:#991b1b; }
        .bp-advance { background:#e0f2fe; color:#075985; }
        .print-btn-group { position:fixed; bottom:24px; left:24px; z-index:100; display:flex; align-items:center; gap:8px;
              background:rgba(255,255,255,.95); padding:8px 12px; border-radius:14px; box-shadow:0 4px 15px rgba(0,0,0,.15); }
        .pbg-label { font-size:12px; font-weight:600; color:#555; }
        .pb-alt { background:linear-gradient(135deg,#0ea5e9,#2563eb) !important; }
        .print-btn { z-index:100; padding:12px 24px; background:linear-gradient(135deg,#667eea,#764ba2); color:white; border:none; border-radius:12px; cursor:pointer; font-family:inherit; font-size:14px; font-weight:600; box-shadow:0 4px 15px rgba(102,126,234,0.4); }
        .back-btn { position:fixed; bottom:24px; right:24px; z-index:100; padding:12px 24px; background:#333; color:white; border:none; border-radius:12px; font-size:14px; text-decoration:none; display:inline-block; }
        @media screen and (max-width:820px) {
            body { padding:0; }
            .page-container { width:100%; margin:0; border-radius:0; }
            .daily-section { padding:14px 10px; }
            table { font-size:11px; }
            .print-btn-group { bottom:12px; padding:6px 8px; gap:6px; } .print-btn { padding:8px 12px; font-size:12px; } .back-btn { bottom:12px; padding:10px 16px; font-size:13px; }
        }
        @media print {
            body { background:white; }
            .page-container { box-shadow:none; border-radius:0; margin:0; max-width:100%; }
            .top-bar { background:#333 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .month-selector, .print-btn, .print-btn-group, .back-btn { display:none !important; }
            tr { break-inside:avoid; }
            /* جهت کاغذ را کاربر انتخاب می‌کند (دکمه‌های چاپ) */
            @page { size:A4 <?= $print_orient ?>; margin:<?= $print_orient === 'landscape' ? '8mm' : '6mm' ?>; }
            /* اسکرول افقی در چاپ باعث «بریده شدن» ستون‌های سمت چپ می‌شود */
            .daily-section > div[style*="overflow"] { overflow:visible !important; }
            .daily-section { padding:10px 12px; }
            table { width:100% !important; table-layout:fixed; font-size:<?= $print_orient === 'landscape' ? '8.5px' : '7px' ?>; }
            th, td { padding:<?= $print_orient === 'landscape' ? '3px 2px' : '2px 1px' ?> !important; word-wrap:break-word; overflow-wrap:anywhere; }
            th { white-space:normal !important; }
            /* ستون ملاحظات باید کشسان باشد تا محتوایش گم نشود */
            th:last-child, td:last-child { white-space:normal !important; width:22%; }
            .bp-tag { display:inline-block; font-size:<?= $print_orient === 'landscape' ? '7.5px' : '6.5px' ?>; padding:1px 3px; margin:1px 0;
                      white-space:normal; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            /* §v13: تاریخ و ساعت‌ها هرگز نباید وسطشان بشکند (مثلاً «۱۴۰۵/۰۶/۰۱»
               که رقم آخرش می‌افتاد خط بعد). فقط ستون کارگاه اجازهٔ شکستن دارد. */
            td.col-date, td.col-time, th.col-date, th.col-time {
                white-space:nowrap !important; word-break:keep-all !important;
                overflow-wrap:normal !important; hyphens:none !important;
            }
            td.col-date { font-variant-numeric:tabular-nums; letter-spacing:-.2px; }
            th.col-date, td.col-date { width:<?= $print_orient === 'landscape' ? '8%' : '9%' ?>; }
            th.col-time, td.col-time { width:6%; }
            td.col-ws { white-space:normal !important; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="top-bar">
        <h1><?= t('sal_details') ?> <?= t('حساب') ?> <?= t('nav_salary') ?></h1>
        <a href="index.php?page=salary"><?= t('btn_back') ?></a>
    </div>

    <div class="user-info">
        <div>
            <div class="user-name"><?= htmlspecialchars($user['full_name']) ?>
                <span class="bp-tag" style="background:#eef2ff;color:#4f46e5;font-size:11px;vertical-align:middle"><?= $detail_scope === 'ws' ? '🏢 ' . strtr(t('فقط کارگاه «{workshop}»'), ['{workshop}' => htmlspecialchars($_SESSION['workshop_name'] ?? '')]) : '🌐 ' . t('حقوق تجمیعی همه کارگاه‌ها') ?></span>
            </div>
            <div class="user-meta">
                <?php if ($is_karigar): ?>
                    <?= t('کارگر') ?> — <?= t('حقوق روزانه') ?>: <?= number_format($hdr_daily) ?> <?= t('تومان') ?>
                    · <?= t('حقوق') ?> <?= t('ساعتی') ?> (<?= t('روزانه') ?>/۸): <?= number_format(round($hdr_daily / 8)) ?> <?= t('تومان') ?>
                <?php else: ?>
                    <?= t('کارمند') ?> — <?= t('حقوق ماهانه') ?>: <?= number_format($hdr_monthly) ?> <?= t('تومان') ?>
                    · <?= t('حقوق روزانه') ?> (/۲۶): <?= number_format(round($hdr_monthly / 26)) ?> <?= t('تومان') ?>
                    · <?= t('نرخ') ?> <?= t('عادی') ?> <?= t('این') ?> <?= t('ماه:') ?> <?= number_format(round($hourly_base)) ?> <?= t('تومان') ?> (<?= t('بر مبنای') ?> <?= t('ساعات') ?> <?= t('کاری') ?> <?= t('الزامی') ?>)
                    · <?= t('نرخ') ?> <?= t('ثابت') ?> <?= t('ویژه') ?>: <?= number_format(round($hdr_monthly / 26 / 8)) ?> <?= t('تومان') ?> (<?= t('جمعه') ?>/<?= t('تعطیل') ?>/<?= t('شب') ?>/<?= t('اضافه‌کار') ?>)
                <?php endif; ?>
            </div>
        </div>
        <span class="user-type <?= $is_karigar ? 'type-worker' : 'type-employee' ?>"><?= $is_karigar ? t('sal_worker') : t('sal_employee') ?></span>
    </div>

    <?php if (!$print_mode): ?>
    <?php if (can_see_all_workshops()): ?>
    <div class="month-selector" style="border-bottom:0;padding-bottom:4px">
        <span style="font-size:13px;font-weight:600;margin-left:8px"><?= t('نمای') ?> <?= t('کارکرد') ?>:</span>
        <a href="?page=salary_detail&user_id=<?= $uid ?>&month=<?= urlencode($selected_month) ?>&scope=all" class="month-btn <?= $detail_scope === 'all' ? 'active' : '' ?>">🌐 <?= t('تجمیعی') ?> <?= t('همه') ?> <?= t('nav_workshops') ?></a>
        <a href="?page=salary_detail&user_id=<?= $uid ?>&month=<?= urlencode($selected_month) ?>&scope=ws" class="month-btn <?= $detail_scope === 'ws' ? 'active' : '' ?>">🏢 <?= t('فقط این کارگاه') ?></a>
    </div>
    <?php endif; ?>
    <div class="month-selector">
        <span style="font-size:13px;font-weight:600;margin-left:8px"><?= t('rep_month') ?>:</span>
        <?php foreach ($months as $m):
            $ml = jalali_month_from_key($m)['label'];
        ?>
        <a href="?page=salary_detail&user_id=<?= $uid ?>&month=<?= $m ?>&scope=<?= $detail_scope ?>" class="month-btn <?= $m === $selected_month ? 'active' : '' ?>"><?= $ml ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="summary">
        <h3><?= t('خلاصه') ?> <?= t('عملکرد') ?> — <?= $month_label ?></h3>
        <?php if ($is_karigar): ?>
        <div class="summary-grid">
            <div class="summary-card sc-gray"><div class="label"><?= t('sal_work_days') ?></div><div class="value"><?= $totals['total_days'] ?></div></div>
            <div class="summary-card sc-green"><div class="label"><?= t('wh_hours') ?> <?= t('کل کار') ?> <?= t('(واقعی)') ?></div><div class="value"><?= fmt_hm(array_sum(array_column($daily_detail, 'total_worked'))) ?></div></div>
            <div class="summary-card sc-purple"><div class="label"><?= t('wh_hours') ?> <?= t('قابل') ?>‌<?= t('پرداخت') ?> (×۱.۸ <?= t('wh_friday') ?> / <?= t('حداقل') ?> ۴ <?= t('wh_night') ?>)</div><div class="value"><?= fmt_hm($totals['payable_hours']) ?></div></div>
            <div class="summary-card sc-yellow"><div class="label"><?= t('wh_day') ?> <?= t('اضافه') ?></div><div class="value"><?= $totals['extra_days'] ?></div><div class="sub"><?= t('هر') ?> ۸ <?= t('wh_hours') ?> <?= t('sal_overtime') ?> <?= t('= ۱') ?> <?= t('wh_day') ?></div></div>
            <div class="summary-card sc-blue"><div class="label"><?= t('rep_total') ?> <?= t('مبلغ') ?> <?= t('sal_base_pay') ?></div><div class="value"><?= number_format($totals['base_pay']) ?> <?= t('ت') ?></div></div>
        </div>
        <?php else: ?>
        <div class="summary-grid">
            <?php
              $__req_h = (float)$required_hours_info['required_hours'];
              $__worked_h = (float)$totals['regular'] + (float)$totals['overtime'] + (float)$totals['night'] + (float)$totals['friday_hours'] + (float)$totals['holiday_hours'];
              $__diff = $__worked_h - $__req_h;
            ?>
            <div class="summary-card <?= $__diff < -0.01 ? 'sc-red' : ($__diff > 0.01 ? 'sc-green' : 'sc-gray') ?>">
              <div class="label"><?= $__diff < -0.01 ? t('کسری کارکرد') : ($__diff > 0.01 ? t('مازاد کارکرد') : t('وضعیت کارکرد')) ?></div>
              <div class="value"><?= abs($__diff) < 0.01 ? t('تسویه') : fmt_hm(abs($__diff)) ?></div>
              <div class="sub"><?= (int)$required_hours_info['calendar_work_days'] ?> <?= t('روز') ?> <?= t('کاری') ?><?= (int)$required_hours_info['leave_days'] > 0 ? ' − ' . (int)$required_hours_info['leave_days'] . ' ' . t('روز مرخصی') : '' ?><?= $required_hours_info['leave_hours'] > 0 ? ' − ' . fmt_hm((float)$required_hours_info['leave_hours']) . ' ' . t('مرخصی ساعتی') : '' ?><?= !empty($required_hours_info['unused_quota_hours']) ? ' − ' . fmt_hm((float)$required_hours_info['unused_quota_hours']) . ' ' . t('سهمیه استفاده‌نشده') : '' ?><?= !empty($required_hours_info['absent_hours']) ? ' · <b style="color:#dc2626">' . fmt_hm((float)$required_hours_info['absent_hours']) . ' ' . t('غیبت بدون مرخصی') . '</b>' . (array_key_exists('absent_dates', $required_hours_info) ? ' <span style="direction:rtl">(' . t('تاریخ') . ': ' . implode('، ', array_map(fn($d) => to_jalali($d), $required_hours_info['absent_dates'])) . ')</span>' : '') : '' ?></div>
            </div>
            <div class="summary-card sc-green"><div class="label"><?= t('rep_regular_hours') ?> (<?= t('ضریب') ?> ۱)</div><div class="value"><?= fmt_hm($totals['regular']) ?></div><div class="sub"><?= number_format($totals['regular_pay']) ?> <?= t('sal_toman') ?></div></div>
            <div class="summary-card sc-yellow"><div class="label"><?= t('ساعات') ?> <?= t('sal_overtime') ?> (<?= t('ضریب') ?> <?= $overtime_coef ?>)</div><div class="value"><?= fmt_hm($totals['overtime']) ?></div><div class="sub"><?= number_format($totals['overtime_pay']) ?> <?= t('sal_toman') ?></div></div>
            <div class="summary-card sc-purple"><div class="label"><?= t('ساعات') ?> <?= t('rep_night_hours') ?> (<?= t('ضریب') ?> <?= $night_coef ?>)</div><div class="value"><?= fmt_hm($totals['night']) ?></div><div class="sub"><?= number_format($totals['night_pay']) ?> <?= t('sal_toman') ?></div></div>
            <?php if ($totals['friday_hours'] > 0): ?>
            <div class="summary-card sc-blue"><div class="label"><?= t('ساعات') ?> <?= t('wh_friday') ?> (<?= t('ضریب') ?> <?= $friday_coef ?>)</div><div class="value"><?= fmt_hm($totals['friday_hours']) ?></div><div class="sub"><?= number_format($totals['friday_pay']) ?> <?= t('sal_toman') ?></div></div>
            <?php endif; ?>
            <?php if ($totals['holiday_hours'] > 0): ?>
            <div class="summary-card sc-red"><div class="label"><?= t('ساعات') ?> <?= t('wh_holiday') ?> (<?= t('ضریب') ?> <?= $holiday_coef ?>)</div><div class="value"><?= fmt_hm($totals['holiday_hours']) ?></div><div class="sub"><?= number_format($totals['holiday_pay']) ?> <?= t('sal_toman') ?></div></div>
            <?php endif; ?>
            <div class="summary-card sc-gray"><div class="label"><?= t('دستمزد') ?> <?= t('هر') ?> <?= t('wh_hours') ?></div><div class="value"><?= number_format(round($hourly_base)) ?> <?= t('ت') ?></div></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="pay-summary">
        <div class="pay-row" style="color:#64748b"><span>📅 <?= t('باقی') ?>‌<?= t('ماندهٔ') ?> <?= t('مطالبات') ?> <?= t('rep_month') ?>‌<?= t('های') ?> <?= t('قبل') ?></span><span><?= number_format($prev_balance) ?> <?= t('sal_toman') ?></span></div>
        <div class="pay-row"><span><?= t('rep_total') ?> <?= t('sal_total_debt') ?> <?= t('rep_month') ?> <?= t('جاری') ?> (<?= t('کارکرد') ?> <?= t('sal_regular') ?> + <?= t('sal_overtime') ?> <?= t('و') ?> <?= t('ضرایب') ?>)</span><span><?= number_format($current_month_gross) ?> <?= t('sal_toman') ?></span></div>
        <div class="pay-row" style="font-weight:700;color:#7c3aed"><span><?= t('rep_total') ?> <?= t('sal_total_debt') ?> <?= t('ناخالص') ?> <?= t('lea_to') ?> <?= t('این') ?> <?= t('rep_month') ?></span><span><?= number_format($total_gross_claim) ?> <?= t('sal_toman') ?></span></div>
        <div class="pay-row" style="color:#dc2626"><span><?= t('sal_penalty') ?></span><span>-<?= number_format($penalty_total) ?> <?= t('sal_toman') ?></span></div>
        <div class="pay-row" style="color:#0369a1"><span><?= t('sal_advance') ?> / <?= t('adv_debt') ?> / <?= t('پرداخت') ?> <?= t('ثبت') ?><?= t('‌شده') ?></span><span>-<?= number_format($advance_total) ?> <?= t('sal_toman') ?></span></div>
        <div class="pay-row" style="color:#16a34a"><span><?= t('sal_bonus') ?></span><span>+<?= number_format($bonus_total) ?> <?= t('sal_toman') ?></span></div>
        <div class="pay-row total" style="color:<?= $final_payable >= 0 ? '#16a34a' : '#dc2626' ?>">
            <span>💳 <?= t('مبلغ نهایی قابل پرداخت') ?></span><span><?= number_format($final_payable) ?> <?= t('sal_toman') ?></span>
        </div>
    </div>

    <div class="daily-section">
        <h3><?= t('sal_daily_detail') ?></h3>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th class="col-date"><?= t('wh_date') ?></th><th><?= t('wh_day') ?></th><th><?= t('کارگاه') ?></th><th class="col-time"><?= t('wh_checkout_start') ?></th><th class="col-time"><?= t('wh_checkout_end') ?></th>
                    <?php if ($is_karigar): ?>
                        <th><?= t('کل کار') ?></th><th><?= t('wh_hours') ?> <?= t('پرداخت') ?></th><th><?= t('sal_overtime') ?></th><th><?= t('sal_work_days') ?></th>
                    <?php else: ?>
                        <th><?= t('sal_regular') ?></th><th><?= t('sal_overtime') ?></th><th><?= t('wh_night') ?></th><th><?= t('wh_friday') ?></th><th><?= t('wh_holiday') ?></th><th><?= t('کل') ?></th>
                    <?php endif; ?>
                    <th><?= t('ملاحظات') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                // §v14: سال دو رقمی (۱۴۰۵/۰۶/۰۱ → ۰۵/۰۶/۰۱) — نمایش فشرده برای چاپ
                $short_jalali = function ($j) {
                    $j = (string)$j;
                    // «۱۴۰۵/۰۶/۰۱» → «۰۵/۰۶/۰۱» (جایگاه اول اسلش در شاخص ۴ است)
                    return (strlen($j) >= 8 && $j[4] === '/') ? substr($j, 2) : $j;
                };
                ?>
                <?php foreach ($daily_detail as $d):
                    $row_class = '';
                    if ($d['is_friday']) $row_class = 'friday-row';
                    elseif ($d['is_holiday']) $row_class = 'holiday-row';
                    $day_bps = $bp_by_date[$d['date']] ?? array();
                    $day_ads = $adv_by_date[$d['date']] ?? array();
                    // §v14: روز چندشیفتی = چند خط؛ خط اول شیف اول (روز/صبح) و
                    // خط بعدی شیف بعد (شب) — فقط ستون ساعت ورود/خروج پر می‌شود.
                    foreach ($d['shift_labels'] as $si => $sl):
                        $is_first = ($si === 0);
                ?>
                <tr class="<?= $row_class ?>">
                    <td class="col-date" style="white-space:nowrap;font-weight:500"><?= $short_jalali($d['jalali']) ?></td>
                    <td>
                        <?php if ($is_first): ?>
                        <?= $d['day_name'] ?>
                        <?php if ($d['is_friday']): ?> <span class="bp-tag" style="background:#fff7ed;color:#c2410c"><?= t('wh_friday') ?></span> <?php endif; ?>
                        <?php if ($d['is_holiday']): ?> <span class="bp-tag" style="background:#fef2f2;color:#dc2626"><?= t('wh_holiday') ?></span> <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="col-ws" style="white-space:nowrap;font-size:11px">
                        <?php if (!empty($sl['workshop'])): ?>
                        <span class="bp-tag" style="background:<?= $sl['is_mission'] ? '#fef3c7;color:#b45309' : '#eef2ff;color:#4f46e5' ?>"><?= $sl['is_mission'] ? '🚀 ' : '🏢 ' ?><?= htmlspecialchars($sl['workshop']) ?><?= $sl['is_mission'] ? ' (' . t('مأموریت') . ')' : '' ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="col-time" style="white-space:nowrap;direction:ltr;text-align:center"><?= htmlspecialchars($sl['start']) ?></td>
                    <td class="col-time" style="white-space:nowrap;direction:ltr;text-align:center"><?= htmlspecialchars($sl['end']) ?></td>
                    <?php if ($is_karigar): ?>
                        <td><?= $is_first ? fmt_hm($d['total_worked']) : '' ?></td>
                        <td><?= $is_first ? fmt_hm($d['paid_hours'] ?? 0) : '' ?></td>
                        <td><?= $is_first ? fmt_hm($d['overtime_hours'] ?? 0) : '' ?></td>
                        <td><?= $is_first ? ($d['day_count'] ?? 0) : '' ?></td>
                    <?php else: ?>
                        <td><?= $is_first ? ($d['regular'] > 0 ? fmt_hm($d['regular']) : '-') : '' ?></td>
                        <td><?= $is_first ? ($d['overtime'] > 0 ? fmt_hm($d['overtime']) : '-') : '' ?></td>
                        <td><?= $is_first ? ($d['night'] > 0 ? fmt_hm($d['night']) : '-') : '' ?></td>
                        <td><?= $is_first ? ($d['friday_hours'] > 0 ? fmt_hm($d['friday_hours']) : '-') : '' ?></td>
                        <td><?= $is_first ? ($d['holiday_hours'] > 0 ? fmt_hm($d['holiday_hours']) : '-') : '' ?></td>
                        <td style="font-weight:600"><?= $is_first ? fmt_hm($d['total_worked']) : '' ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($is_first): ?>
                        <?php foreach ($day_bps as $bp): ?>
                            <span class="bp-tag <?= $bp['type'] === 'bonus' ? 'bp-bonus' : 'bp-penalty' ?>">
                                <?= $bp['type'] === 'bonus' ? '+' : '-' ?><?= number_format($bp['amount']) ?><?= t('ت') ?>
                                <?php if (!empty($bp['hours']) && $bp['hours'] > 0): ?>(<?= fmt_hm((float)$bp['hours']) ?>)<?php endif; ?>
                                <?php if ($bp['reason']): ?> (<?= htmlspecialchars($bp['reason']) ?>)<?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                        <?php foreach ($day_ads as $ad): ?>
                            <span class="bp-tag bp-advance">-<?= number_format($ad['amount']) ?><?= t('ت') ?> <?= ($ad['payment_type'] ?? '') === 'debt' ? t('adv_debt') : t('sal_advance') ?></span>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                <?php if (empty($daily_detail)): ?>
                <tr><td colspan="<?= $is_karigar ? 10 : 12 ?>" style="text-align:center;padding:30px;color:#999"><?= t('wh_no_records') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php if (!$print_mode): ?>
<div class="print-btn-group">
    <span class="pbg-label"><?= t('btn_print') ?> A4</span>
    <button class="print-btn" onclick="doPrint('portrait')" title="<?= t('عمودی') ?>">📄 <?= t('عمودی') ?></button>
    <button class="print-btn pb-alt" onclick="doPrint('landscape')" title="<?= t('افقی') ?>">📃 <?= t('افقی') ?></button>
</div>
<script>
function doPrint(orient) {
    // پنجرهٔ چاپ خودش بعد از لود، window.print() را صدا می‌زند
    var u = '?page=salary_detail&user_id=<?= $uid ?>&month=<?= $selected_month ?>&scope=<?= $detail_scope ?>&print=1&orient=' + orient;
    window.open(u, '_blank', 'width=1000,height=700');
}
</script>
<a href="index.php?page=salary" class="back-btn"><?= t('btn_back') ?></a>
<?php endif; ?>

<?php if ($print_mode): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>

<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
