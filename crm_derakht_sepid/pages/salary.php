<?php
$pageTitle = t('nav_salary');
$ws_id = $_SESSION['workshop_id'] ?? 0;

require_once __DIR__ . '/../includes/salary_calc.php';

// §fix: افزایش‌های رسیده را در ستون حقوق فعلی هم اعمال کن
salary_sync_current($pdo);

$settings = get_workshop_settings($pdo, $ws_id);

$is_admin = is_strict_admin();
$is_nazer = is_nazer();
$can_manage = $is_admin || $is_nazer || has_permission('manage_salary');
// §v11: ناظرِ حقوق = دیدن حقوق همه بدون امکان مدیریت
$can_view_all = $can_manage || in_array(access_mode('salary'), ['viewer', 'editor'], true);

// بررسی دسترسی: مدیر کارگاه فقط افراد و حقوق‌های کارگاه خودش را می‌بیند
if (!can_see_all_workshops()) {
    $salary_scope = 'ws';
    $s_ws = (int)$ws_id;
} else {
    $salary_scope = (($_GET['scope'] ?? 'all') === 'ws') ? 'ws' : 'all';
    $s_ws = $salary_scope === 'ws' ? (int)$ws_id : 0;
}
$W = function ($alias = '') use (&$s_ws) {
    if ($s_ws <= 0) return '';
    $col = $alias !== '' ? $alias . '.workshop_id' : 'workshop_id';
    return " AND $col = $s_ws";
};

// §v12 §5.1: موتور مشترک محاسبه مانده تا تاریخ مشخص (شامل خود آن روز) — برای لیست (تا امروز) و تسویه (تا تاریخ انتخابی)
function compute_person_balance(PDO $pdo, array $p, string $calc_upto, int $s_ws, Closure $W, array $settings): array {
    $uid = (int)$p['id'];

    // کارکرد کامل فرد در تمامی کارگاه‌ها (شامل مأموریت‌ها) محاسبه می‌شود (بند ۲ و ۳)
    $stmt2 = $pdo->prepare("SELECT * FROM work_hours WHERE user_id = ? AND work_date <= ? ORDER BY work_date, start_time");
    $stmt2->execute([$uid, $calc_upto]);
    $all_hours = $stmt2->fetchAll();

    // Group by month and date
    $monthly_data = [];
    foreach ($all_hours as $h) {
        $month = substr($h['work_date'], 0, 7);
        $monthly_data[$month][$h['work_date']][] = $h;
    }

    // Calculate salary for each month
    $total_base_pay = 0;
    foreach ($monthly_data as $month => $days) {
        $m_start = $month . '-01';
        $m_end = date('Y-m-t', strtotime($m_start));
        // §perf: تعطیلات هر ماه فقط یک‌بار برای همهٔ نفرات خوانده می‌شود
        static $__hol_cache = [];
        if (!isset($__hol_cache[$m_start])) {
            $stmt3 = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
            $stmt3->execute([$m_start, $m_end]);
            $__hol_cache[$m_start] = array_column($stmt3->fetchAll(), 'holiday_date');
        }
        $holidays = $__hol_cache[$m_start];

        if ($p['is_karigar']) {
            // مستند §6.5 + §1: حقوق کارگر = ساعت قابل‌پرداخت × (روزانه تاریخ‌محور / ۸)
            $history = get_salary_history($pdo, $uid);
            $sorted_d = array_keys($days); sort($sorted_d);
            foreach ($sorted_d as $di => $date) {
                $shifts = $days[$date];
                $is_hol = in_array($date, $holidays);
                $prev_d = ($di > 0) ? $sorted_d[$di - 1] : '';
                $prev_s = $prev_d ? ($days[$prev_d] ?? []) : [];
                $calc = calc_worker_day($shifts, $date, $is_hol, $p['daily_salary'], $prev_s, $prev_d);
                $total_base_pay += $calc['payable_hours'] * (salary_at_date($history, $date, $p['daily_salary']) / 8);
            }
        } else {
            // مستند §1: نرخ ساعتی هر روز از تاریخچه حقوق همان روز
            $history = get_salary_history($pdo, $uid);
            $ot_c = (float)($settings['overtime_coefficient'] ?? 1.33);
            $ni_c = (float)($settings['night_coefficient'] ?? 2);
            $fr_c = (float)($settings['friday_coefficient'] ?? 1.33);
            $ho_c = (float)($settings['holiday_coefficient'] ?? 2);
            $month_pay = 0.0;
            $required_for_period = employee_required_hours($pdo, $uid, 0, $m_start, $m_end, $settings);
            $sorted_d = array_keys($days); sort($sorted_d);
            foreach ($sorted_d as $di => $date) {
                $shifts = $days[$date];
                $is_hol = in_array($date, $holidays);
                $prev_d = ($di > 0) ? $sorted_d[$di - 1] : '';
                $prev_s = $prev_d ? ($days[$prev_d] ?? []) : [];
                $calc = calc_employee_day($shifts, $date, $is_hol, $settings, $prev_s, $prev_d);
                $salary_d = salary_at_date($history, $date, (float)($p['monthly_salary'] ?? 0));
                $regular_hourly_d = employee_hourly_rate_for_required_hours($salary_d, $required_for_period);
                $special_hourly_d = $salary_d / 26 / 8;
                $month_pay += ($calc['regular'] * $regular_hourly_d)
                            + ($calc['overtime'] * $special_hourly_d * $ot_c)
                            + ($calc['night'] * $special_hourly_d * $ni_c)
                            + ($calc['friday_hours'] * $special_hourly_d * $fr_c)
                            + ($calc['holiday_hours'] * $special_hourly_d * $ho_c);
            }
            $total_base_pay += round($month_pay);
        }
    }

    // برای کارمند، موتور ماهانه ساعات کاری الزامی را لحاظ می‌کند
    if (!(int)$p['is_karigar'] && !empty($all_hours)) {
        $first_work_date = $all_hours[0]['work_date'];
        $total_base_pay = employee_month_pay($pdo, $uid, 0, $first_work_date, $calc_upto, (float)($p['monthly_salary'] ?? 0), $settings);
    }

    // Bonuses (تا تاریخ مشخص)
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bonuses_penalties WHERE user_id = ? AND status = 'approved' AND type = 'bonus' AND date <= ?");
    $stmt2->execute([$uid, $calc_upto]);
    $bonus = (float)$stmt2->fetchColumn();

    // Penalties
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM bonuses_penalties WHERE user_id = ? AND status = 'approved' AND type = 'penalty' AND date <= ?");
    $stmt2->execute([$uid, $calc_upto]);
    $penalty = (float)$stmt2->fetchColumn();

    // Advances (پرداخت‌شده تا پایان همان روز در تمام کارگاه‌ها)
    $advance = advance_deduction_upto($pdo, $uid, 0, date('Y-m-d', strtotime($calc_upto . ' +1 day')));

    $balance = $total_base_pay + $bonus - $penalty - $advance;

    return [
        'total_salary' => $total_base_pay,
        'bonus' => $bonus,
        'penalty' => $penalty,
        'advance' => $advance,
        'balance' => $balance,
    ];
}

// §v12 §5.1: ثبت تسویه حساب نیرو تا تاریخ مشخص — مانده تا آن روز محاسبه و به‌عنوان «پرداخت حقوق» ثبت می‌شود
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle_person') {
    if (!$can_manage) { showToast(t('دسترسی ندارید'), 'error'); redirect('index.php?page=salary'); exit; }
    $uid = (int)($_POST['user_id'] ?? 0);
    // مدیر کارگاه فقط مجاز به تسویه پرسنل فعال کارگاه خود است
    if (!can_see_all_workshops()) {
        $chk_ws = $pdo->prepare("SELECT 1 FROM workshop_personnel WHERE user_id = ? AND workshop_id = ? AND status = 'active'");
        $chk_ws->execute([$uid, $ws_id]);
        if (!$chk_ws->fetchColumn()) {
            showToast(t('شما فقط مجاز به تسویه پرسنل کارگاه اختصاصی خود هستید'), 'error'); redirect('index.php?page=salary'); exit;
        }
    }
    $gdate = to_gregorian(sanitize($_POST['settle_date'] ?? ''));
    $method = (in_array(($_POST['payment_method'] ?? 'fund'), ['fund', 'petty_cash'], true)) ? $_POST['payment_method'] : 'fund';
    if ($uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$gdate)) {
        showToast(t('تاریخ تسویه نامعتبر است'), 'error'); redirect('index.php?page=salary'); exit;
    }
    if ($gdate > date('Y-m-d')) { showToast(t('تاریخ تسویه نمی‌تواند در آینده باشد'), 'error'); redirect('index.php?page=salary'); exit; }
    // اطلاعات نفر مطابق نمای فعلی (تجمیعی / این کارگاه) — مبلغ همیشه سمت سرور محاسبه می‌شود
    $stmt2 = $pdo->prepare("SELECT u.full_name, u.is_karigar, MAX(wp.daily_salary) AS daily_salary, MAX(wp.monthly_salary) AS monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE u.id = ?" . $W('wp') . " GROUP BY u.id, u.full_name, u.is_karigar");
    $stmt2->execute([$uid]);
    $per = $stmt2->fetch();
    if (!$per) { showToast(t('پرسنل یافت نشد'), 'error'); redirect('index.php?page=salary'); exit; }
    $per = array_merge($per, salary_effective_base($pdo, $uid, $s_ws));
    $b = compute_person_balance($pdo, $per + ['id' => $uid], $gdate, $s_ws, $W, $settings);
    $due = round($b['balance']);
    if ($due <= 0) {
        showToast(strtr(t('مانده قابل تسویه تا این تاریخ صفر یا منفی است ({amount} تومان)'), ['{amount}' => number_format($due)]), 'error'); redirect('index.php?page=salary'); exit;
    }
    $jdate = to_jalali($gdate);
    $reason = t('تسویه حساب تا تاریخ') . ' ' . $jdate;
    $paid_at = $gdate . ' ' . date('H:i:s');
    $stmt = $pdo->prepare("INSERT INTO advances (user_id, workshop_id, amount, remaining, reason, status, payment_method, payment_type, approved_by, approved_at, paid_at) VALUES (?, ?, ?, 0, ?, 'paid', ?, 'salary', ?, NOW(), ?)");
    $stmt->execute([$uid, $ws_id, $due, $reason, $method, $_SESSION['user_id'], $paid_at]);
    if ($method === 'petty_cash') {
        $desc = t('تسویه حقوق') . ' ' . $per['full_name'] . ' ' . t('تا') . ' ' . $jdate;
        $stmt2 = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by) VALUES (?, 'expense', ?, ?, ?, ?)");
        $stmt2->execute([$ws_id, $due, $desc, $gdate, $_SESSION['user_id']]);
    }
    $stmt2 = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'personnel_settle', 'advance', ?, ?)");
    $stmt2->execute([$_SESSION['user_id'], $uid, 'تسویه حساب ' . $per['full_name'] . ' تا ' . $jdate . ': ' . number_format($due) . ' تومان (' . ($method === 'petty_cash' ? t('nav_petty_cash') : 'صندوق') . ')']);
    showToast(strtr(t('✅ تسویه حساب {name} تا {date} با مبلغ {amount} تومان ثبت شد'), ['{name}' => $per['full_name'], '{date}' => $jdate, '{amount}' => number_format($due)]));
    redirect('index.php?page=salary');
    exit;
}

// Get personnel with sorting — انتخاب کاربر بعد از رفرش/برگشت حفظ می‌شه (سِشن)
$allowed_sorts = ['full_name', 'is_karigar', 'monthly_salary', 'daily_salary'];
if (isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sorts)) {
    $_SESSION['salary_sort'] = $_GET['sort'];
}
if (isset($_GET['dir']) && in_array($_GET['dir'], ['ASC', 'DESC'])) {
    $_SESSION['salary_sort_dir'] = $_GET['dir'];
}
$sort = $_SESSION['salary_sort'] ?? 'full_name';
$sort_dir = $_SESSION['salary_sort_dir'] ?? 'ASC';
if ($s_ws > 0) {
    $sort_col = ($sort === 'monthly_salary' || $sort === 'daily_salary') ? "wp.$sort" : "u.$sort";
} else {
    // حالت تجمیعی: با MAX گروه‌بندی می‌کنیم، مرتب‌سازی روی alias
    $sort_col = ($sort === 'monthly_salary' || $sort === 'daily_salary') ? $sort : "u.$sort";
}

// مستند §4: پیمانکار، حقوق صفر، و لیست مستثنیات تو صفحه حقوق نمیان
$excluded_usernames = array_filter(array_map('trim', explode(',', strtolower($settings['payroll_excluded_usernames'] ?? 'yaser'))));
$excluded_usernames[] = 'yaser';
$excluded_usernames = array_unique($excluded_usernames);
$ex_ph = implode(',', array_fill(0, count($excluded_usernames), '?'));

if ($can_view_all) {
    if ($s_ws > 0) {
        // فقط نیروهای فعال همین کارگاه (نیروهای مأمور از سایر کارگاه‌ها یا منتقل‌شده‌ها نمایش داده نمی‌شوند)
        $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar, wp.daily_salary, wp.monthly_salary, 1 AS has_active_membership
            FROM users u
            JOIN workshop_personnel wp ON u.id = wp.user_id
            WHERE wp.workshop_id = ? AND wp.status = 'active' AND u.status = 'active' AND u.is_karigar != 2 AND (wp.daily_salary > 0 OR wp.monthly_salary > 0) AND LOWER(u.username) NOT IN ($ex_ph)
            ORDER BY $sort_col $sort_dir");
        $stmt->execute(array_merge([$s_ws], array_values($excluded_usernames)));
    } else {
        // §v8 تجمیعی: هر نفر یک‌بار با بالاترین نرخ غیرصفرش بین همه کارگاه‌هایی که فعاله
        $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar, MAX(wp.daily_salary) AS daily_salary, MAX(wp.monthly_salary) AS monthly_salary, 1 AS has_active_membership
            FROM users u
            JOIN workshop_personnel wp ON u.id = wp.user_id
            WHERE wp.status = 'active' AND u.status = 'active' AND u.is_karigar != 2 AND (wp.daily_salary > 0 OR wp.monthly_salary > 0) AND LOWER(u.username) NOT IN ($ex_ph)
            GROUP BY u.id, u.full_name, u.is_karigar
            ORDER BY $sort_col $sort_dir");
        $stmt->execute(array_values($excluded_usernames));
    }
} else {
    // نمای کاربر برای خودش — همیشه تجمیعی (حقوقش مجموع کارش در همه کارگاه‌هاست)
    $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar, MAX(wp.daily_salary) AS daily_salary, MAX(wp.monthly_salary) AS monthly_salary, 1 AS has_active_membership
        FROM users u
        JOIN workshop_personnel wp ON u.id = wp.user_id
        WHERE wp.status = 'active' AND u.id = ? AND u.is_karigar != 2 AND (wp.daily_salary > 0 OR wp.monthly_salary > 0)
        GROUP BY u.id, u.full_name, u.is_karigar");
    $stmt->execute([$_SESSION['user_id']]);
}
$personnel_raw = $stmt->fetchAll();
// هم‌راستا با صفحهٔ جزئیات: حقوق پایه فقط از عضویت‌های فعال (نه ردیف‌های قدیمیِ منتقل‌شده)
foreach ($personnel_raw as $__i => $__pr) {
    $__eb = salary_effective_base($pdo, (int)$__pr['id'], $s_ws);
    $personnel_raw[$__i]['daily_salary'] = $__eb['daily_salary'];
    $personnel_raw[$__i]['monthly_salary'] = $__eb['monthly_salary'];
}

// هر user_id فقط یک ردیف اصلی در صفحه حقوق دارد. عضویت در چند کارگاه نباید
// باعث تکرار ردیف شود؛ جزئیات کارگاه‌ها در صفحه جزئیات حقوق نمایش داده می‌شود.
$personnel_by_id = [];
foreach ($personnel_raw as $person_row) {
    $personnel_by_id[(int)$person_row['id']] = $person_row;
}
$personnel = array_values($personnel_by_id);

// §v13: ادغام بر اساس نام حذف شد.
// شناسه‌های تکراری در دیتابیس ادغام شده‌اند (merge_duplicates.php)، و دو نفر
// متفاوت می‌توانند هم‌نام باشند؛ پس هر user_id یک شخص مستقل است.
foreach ($personnel as $i => $person_row) {
    $personnel[$i]['_merged_ids'] = [(int)$person_row['id']];
}

// نام کارگاه‌های هر نفر را برای نمایش خلاصه کنار همان ردیف آماده می‌کنیم.
$workshop_labels = [];
if (!empty($personnel)) {
    $person_ids = [];
    foreach ($personnel as $person_row) {
        foreach (($person_row['_merged_ids'] ?? [(int)$person_row['id']]) as $person_id) {
            $person_ids[] = (int)$person_id;
        }
    }
    $person_ids = array_values(array_unique($person_ids));
    $person_ph = implode(',', array_fill(0, count($person_ids), '?'));
    $ws_stmt = $pdo->prepare("
        SELECT wp.user_id, w.name
        FROM workshop_personnel wp
        LEFT JOIN workshops w ON w.id = wp.workshop_id
        WHERE wp.user_id IN ($person_ph)
        GROUP BY wp.user_id, w.id, w.name
        ORDER BY w.name
    ");
    $ws_stmt->execute($person_ids);
    foreach ($ws_stmt->fetchAll(PDO::FETCH_ASSOC) as $ws_row) {
        $workshop_labels[(int)$ws_row['user_id']][] = $ws_row['name'];
    }
}

// Calculate balance for each person (real-time from work_hours) — §v12: با موتور مشترک تا «امروز»
$balances = [];
$__today = date('Y-m-d');
foreach ($personnel as $p) {
    $merged_balance = ['total_salary' => 0, 'bonus' => 0, 'penalty' => 0, 'advance' => 0, 'balance' => 0];
    foreach (($p['_merged_ids'] ?? [(int)$p['id']]) as $merged_id) {
        $source_person = $p;
        $source_person['id'] = $merged_id;
        $one_balance = compute_person_balance($pdo, $source_person, $__today, $s_ws, $W, $settings);
        foreach ($merged_balance as $balance_key => $balance_value) {
            $merged_balance[$balance_key] += (float)($one_balance[$balance_key] ?? 0);
        }
    }
    $balances[$p['id']] = $merged_balance;
}

// §v12 §5.1: پیش‌نمایش مبلغ تسویه تا تاریخ (AJAX) — محاسبه مطابق نمای فعلی
if (isset($_GET['settle_preview'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $r = ['ok' => false, 'amount' => 0, 'jdate' => '', 'name' => '', 'error' => ''];
    if (!$can_manage) {
        $r['error'] = t('دسترسی ندارید');
    } else {
        $uid = (int)$_GET['settle_preview'];
        $gdate = to_gregorian($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$gdate)) {
            $r['error'] = t('تاریخ نامعتبر است');
        } elseif ($gdate > date('Y-m-d')) {
            $r['error'] = t('تاریخ تسویه نمی‌تواند در آینده باشد');
        } else {
            $stmt2 = $pdo->prepare("SELECT u.full_name, u.is_karigar, MAX(wp.daily_salary) AS daily_salary, MAX(wp.monthly_salary) AS monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE u.id = ?" . $W('wp') . " GROUP BY u.id, u.full_name, u.is_karigar");
            $stmt2->execute([$uid]);
            $per = $stmt2->fetch();
            if (!$per) {
                $r['error'] = t('پرسنل یافت نشد');
            } else {
                $per = array_merge($per, salary_effective_base($pdo, $uid, $s_ws));
                $b = compute_person_balance($pdo, $per + ['id' => $uid], $gdate, $s_ws, $W, $settings);
                $r['ok'] = true;
                $r['amount'] = round($b['balance']);
                $r['jdate'] = to_jalali($gdate);
                $r['name'] = $per['full_name'];
            }
        }
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// Detail endpoint (AJAX) — NEW calculation engine
if (isset($_GET['detail_user'])) {
    error_reporting(0);
    ini_set('display_errors', 0);

    header('Content-Type: application/json; charset=utf-8');
    $uid = (int)$_GET['detail_user'];

    $result = ['user' => null, 'months' => [], 'error' => null, 'debug_ws' => $ws_id, 'debug_uid' => $uid];

    try {
    // §v8 تجمیعی: اطلاعات نفر از بالاترین نرخ بین همه کارگاه‌های فعالش
    $stmt2 = $pdo->prepare("SELECT u.full_name, u.is_karigar, MAX(wp.daily_salary) AS daily_salary, MAX(wp.monthly_salary) AS monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE u.id = ?" . $W('wp') . " GROUP BY u.id, u.full_name, u.is_karigar");
    $stmt2->execute([$uid]);
    $result['user'] = $stmt2->fetch();
    if ($result['user']) { $result['user'] = array_merge($result['user'], salary_effective_base($pdo, $uid, $s_ws)); }

    // §v13: هر user_id یک شخص مستقل است (ادغام بر اساس نام حذف شد).
    $detail_ids = [$uid];
    $UIDS = implode(',', array_fill(0, count($detail_ids), '?'));

    // ── ماه‌های شمسی (از روز ۱ تا آخر همان ماه شمسی) — §v8 تجمیعی ──
    $stmt2 = $pdo->prepare("SELECT DISTINCT work_date FROM work_hours WHERE user_id IN ($UIDS)" . $W());
    $stmt2->execute($detail_ids);
    $jkeys = [];
    foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $wd) { $jkeys[jalali_month_key($wd)] = true; }
    // ماه‌های دارای پاداش/جریمه یا مساعده (حتی بدون ساعت کاری)
    $stmt2 = $pdo->prepare("SELECT DISTINCT date FROM bonuses_penalties WHERE user_id IN ($UIDS)" . $W());
    $stmt2->execute($detail_ids);
    foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $bd) { $jkeys[jalali_month_key($bd)] = true; }
    $stmt2 = $pdo->prepare("SELECT DISTINCT DATE(CASE WHEN status = 'paid' AND paid_at IS NOT NULL THEN paid_at ELSE COALESCE(approved_at, created_at) END) AS d FROM advances WHERE user_id IN ($UIDS)" . $W() . " AND status IN ('approved','paid')");
    $stmt2->execute($detail_ids);
    foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $ad) { $jkeys[jalali_month_key($ad)] = true; }
    $months = array_keys($jkeys);
    usort($months, function($a, $b) {
        [$ay, $am] = array_map('intval', explode('-', $a));
        [$by, $bm] = array_map('intval', explode('-', $b));
        if ($ay !== $by) return $by - $ay;
        return $bm - $am;
    });
    $months = array_slice($months, 0, 24);
    $month_rows = [];
    $result['debug_months'] = $months;
    $result['debug_month_count'] = count($months);
    $result['debug_user_data'] = $result['user'];

    foreach ($months as $m) {
        $jmi = jalali_month_from_key($m);
        $row = ['month' => $m, 'label' => $jmi['label']];
        $m_start = $jmi['start'];
        $m_end = $jmi['end'];

        // Get all work hours for this jalali month (§v8: همراه نام کارگاه هر روز)
        $stmt3 = $pdo->prepare("SELECT wh.*, w.name AS ws_name FROM work_hours wh LEFT JOIN workshops w ON w.id = wh.workshop_id WHERE wh.user_id IN ($UIDS)" . $W('wh') . " AND wh.work_date BETWEEN ? AND ? ORDER BY wh.work_date, wh.start_time");
        $stmt3->execute(array_merge($detail_ids, [$m_start, $m_end]));
        $hours = $stmt3->fetchAll();

        // Group by date
        $daily = [];
        foreach ($hours as $h) { $daily[$h['work_date']][] = $h; }

        // Get holidays
        $stmt3 = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $stmt3->execute([$m_start, $m_end]);
        $holidays = array_column($stmt3->fetchAll(), 'holiday_date');
        $required_info = !$result['user']['is_karigar'] ? employee_required_hours($pdo, $uid, $s_ws, $m_start, $m_end, $settings) : null;

        $row['work_days'] = count($daily);
        $row['regular_hours'] = 0;
        $row['overtime_hours'] = 0;
        $row['night_hours'] = 0;
        $row['total_days'] = 0;
        $row['extra_days'] = 0;
        $row['base_pay'] = 0;
        $row['daily_detail'] = [];

        if ($result['user']['is_karigar']) {
            // ── Worker calculation — مستند §6: ساعت قابل‌پرداخت × (روزانه/۸) ──
            $total_days = 0;
            $total_hours = 0;
            $extra_days = 0;
            $total_payable = 0;
            $sorted_daily = array_keys($daily); sort($sorted_daily);

            $history = get_salary_history($pdo, $uid);
            $base_pay_f = 0.0;
            foreach ($sorted_daily as $di => $date) {
                $shifts = $daily[$date];
                $is_hol = in_array($date, $holidays);
                $prev_d = ($di > 0) ? $sorted_daily[$di - 1] : '';
                $prev_s = $prev_d ? ($daily[$prev_d] ?? []) : [];
                $calc = calc_worker_day($shifts, $date, $is_hol, $result['user']['daily_salary'], $prev_s, $prev_d);
                $total_days += $calc['day_count'];
                $total_hours += $calc['payable_hours'];
                $total_payable += $calc['payable_hours'];
                $extra_days += $calc['extra_days'];
                $base_pay_f += $calc['payable_hours'] * (salary_at_date($history, $date, $result['user']['daily_salary']) / 8);

                $row['daily_detail'][] = [
                    'date' => $date,
                    'jalali' => to_jalali($date),
                    'is_friday' => (date('w', strtotime($date)) == 5),
                    'is_holiday' => $is_hol,
                    'workshop' => $shifts[0]['ws_name'] ?? '',
                    'mission' => !empty($shifts[0]['mission_id']), // §3: روز مأموریت
                    'start_time' => workday_clock_labels($shifts)['start_label'],
                    'end_time' => workday_clock_labels($shifts)['end_label'],
                    'total_worked' => $calc['total_worked'],
                    'paid_hours' => $calc['paid_hours'],
                    'overtime_hours' => $calc['overtime_hours'],
                    'day_count' => $calc['day_count'],
                    'payable_hours' => $calc['payable_hours'],
                ];
            }

            $row['total_days'] = round($total_days, 2);
            $row['total_hours'] = round($total_hours, 1);
            $row['extra_days'] = round($extra_days, 2);
            $row['payable_hours'] = round($total_payable, 2);
            $row['hourly_rate'] = round(salary_at_date($history, $m_end, $result['user']['daily_salary']) / 8);
            $row['base_pay'] = round($base_pay_f);
            $row['has_salary_history'] = count($history) > 0;

        } else {
            // ── Employee calculation ──
            $reg_h = 0; $ot_h = 0; $night_h = 0; $fri_h = 0; $hol_h = 0;
            $friday_coef = (float)($settings['friday_coefficient'] ?? 1.33);
            $holiday_coef = (float)($settings['holiday_coefficient'] ?? 2.0);
            $overtime_coef = (float)($settings['overtime_coefficient'] ?? 1.33);
            $night_coef = (float)($settings['night_coefficient'] ?? 2.0);
            $monthly_salary = (float)($result['user']['monthly_salary'] ?? 0);
            $history = get_salary_history($pdo, $uid);
            $reg_pay_f = 0.0; $ot_pay_f = 0.0; $night_pay_f = 0.0; $fri_pay_f = 0.0; $hol_pay_f = 0.0;

            $sorted_dates = array_keys($daily);
            sort($sorted_dates);

            foreach ($sorted_dates as $idx => $date) {
                $shifts = $daily[$date];
                $is_hol = in_array($date, $holidays);
                $prev_d = ($idx > 0) ? $sorted_dates[$idx - 1] : '';
                $prev_s = $prev_d ? ($daily[$prev_d] ?? []) : [];
                $calc = calc_employee_day($shifts, $date, $is_hol, $settings, $prev_s, $prev_d);

                $reg_h += $calc['regular'];
                $ot_h += $calc['overtime'];
                $night_h += $calc['night'];
                $fri_h += $calc['friday_hours'];
                $hol_h += $calc['holiday_hours'];

                // فقط ساعت عادی نرخ پویا دارد؛ همهٔ کارهای ویژه نرخ ثابت ۲۶×۸ را نگه می‌دارند.
                $salary_d = salary_at_date($history, $date, $monthly_salary);
                $regular_hourly_d = employee_hourly_rate_for_required_hours($salary_d, $required_info);
                $special_hourly_d = $salary_d / 26 / 8;
                $reg_pay_f += $calc['regular'] * $regular_hourly_d;
                $ot_pay_f += $calc['overtime'] * $special_hourly_d * $overtime_coef;
                $night_pay_f += $calc['night'] * $special_hourly_d * $night_coef;
                $fri_pay_f += $calc['friday_hours'] * $special_hourly_d * $friday_coef;
                $hol_pay_f += $calc['holiday_hours'] * $special_hourly_d * $holiday_coef;

                $row['daily_detail'][] = [
                    'date' => $date,
                    'jalali' => to_jalali($date),
                    'is_friday' => (date('w', strtotime($date)) == 5),
                    'is_holiday' => $is_hol,
                    'workshop' => $shifts[0]['ws_name'] ?? '',
                    'mission' => !empty($shifts[0]['mission_id']), // §3: روز مأموریت
                    'start_time' => workday_clock_labels($shifts)['start_label'],
                    'end_time' => workday_clock_labels($shifts)['end_label'],
                    'regular' => $calc['regular'],
                    'overtime' => $calc['overtime'],
                    'night' => $calc['night'],
                    'friday_hours' => $calc['friday_hours'],
                    'holiday_hours' => $calc['holiday_hours'],
                ];
            }

            $regular_hours = (float)($settings['regular_hours'] ?? 9);

            // Count working days in this jalali month (از ۱ تا آخر ماه شمسی)
            $m_start_date = $m_start;
            $m_end_date = $m_end;
            $working_days = 0;
            for ($ts = strtotime($m_start_date); $ts <= strtotime($m_end_date); $ts = strtotime('+1 day', $ts)) {
                $check_date = date('Y-m-d', $ts);
                $dw = date('w', $ts);
                if ($dw != 5 && !in_array($check_date, $holidays)) {
                    $working_days++;
                }
            }
            if ($working_days == 0) $working_days = 22; // fallback

            // Hourly base rate
            $hourly_base = employee_hourly_rate_for_required_hours($monthly_salary, $required_info);

            // Calculate pay — per-day rates accumulated (§1), rounded once per bucket
            $reg_pay = round($reg_pay_f);
            $ot_pay = round($ot_pay_f);
            $night_pay = round($night_pay_f);
            $fri_pay = round($fri_pay_f);
            $hol_pay = round($hol_pay_f);

            $row['regular_hours'] = round($reg_h, 1);
            $row['overtime_hours'] = round($ot_h, 1);
            $row['night_hours'] = round($night_h, 1);
            $row['friday_hours'] = round($fri_h, 1);
            $row['holiday_hours'] = round($hol_h, 1);
            $row['required_hours'] = (float)$required_info['required_hours'];
            $row['required_work_days'] = (int)$required_info['calendar_work_days'];
            $row['approved_leave_days'] = (int)$required_info['leave_days'];
            $row['approved_leave_hours'] = (float)$required_info['leave_hours'];
            $row['hourly_rate'] = round(employee_hourly_rate_for_required_hours(salary_at_date($history, $m_end, $monthly_salary), $required_info));
            $row['has_salary_history'] = count($history) > 0;
            $row['regular_pay'] = $reg_pay;
            $row['overtime_pay'] = $ot_pay;
            $row['night_pay'] = $night_pay;
            $row['friday_pay'] = $fri_pay;
            $row['holiday_pay'] = $hol_pay;
            $row['base_pay'] = $reg_pay + $ot_pay + $night_pay + $fri_pay + $hol_pay;
        }

        // Bonuses/Penalties (§v8 تجمیعی)
        $stmt3 = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) as total, COALESCE(SUM(hours),0) as total_hours FROM bonuses_penalties WHERE user_id IN ($UIDS)" . $W() . " AND status = 'approved' AND date BETWEEN ? AND ? GROUP BY type");
        $stmt3->execute(array_merge($detail_ids, [$m_start, $m_end]));
        $bp = ['bonus' => 0, 'penalty' => 0, 'bonus_hours' => 0, 'penalty_hours' => 0];
        while ($row3 = $stmt3->fetch()) {
            $bp[$row3['type']] = $row3['total'];
            $bp[$row3['type'] . '_hours'] = $row3['total_hours'];
        }

        // Advances + Debts (§v8 تجمیعی)
        $stmt3 = $pdo->prepare("SELECT payment_type AS type, amount, reason, DATE_FORMAT(CASE WHEN status = 'paid' AND paid_at IS NOT NULL THEN paid_at ELSE approved_at END,'%Y-%m-%d') as pay_date FROM advances WHERE user_id IN ($UIDS)" . $W() . " AND payment_type IN ('advance','salary','debt') AND ((status = 'paid' AND paid_at IS NOT NULL AND DATE(paid_at) BETWEEN ? AND ?) OR (status = 'approved' AND approved_at IS NOT NULL AND DATE(approved_at) BETWEEN ? AND ?))");
        $stmt3->execute(array_merge($detail_ids, [$m_start, $m_end, $m_start, $m_end]));
        $adv_items = $stmt3->fetchAll();
        $adv_total = 0;
        foreach ($adv_items as $ai) {
            if ($ai['type'] === 'debt') $adv_total += $ai['amount'];
            else $adv_total += $ai['amount'];
        }

        $row['bonus'] = $bp['bonus'];
        $row['penalty'] = $bp['penalty'];
        $row['bonus_hours'] = $bp['bonus_hours'];
        $row['penalty_hours'] = $bp['penalty_hours'];
        $row['advance'] = $adv_total;
        $row['advance_items'] = $adv_items;
        $row['net'] = $row['base_pay'] + $bp['bonus'] - $bp['penalty'] - $adv_total;

        $month_rows[$m] = $row;
    }

    // ── زنجیره مطالبات: مانده هر ماه به ماه بعد منتقل می‌شه ──
    uksort($month_rows, function($a, $b) {
        [$ay, $am] = array_map('intval', explode('-', $a));
        [$by, $bm] = array_map('intval', explode('-', $b));
        if ($ay !== $by) return $ay - $by;
        return $am - $bm;
    });
    $running = 0;
    foreach ($month_rows as $k => $r) {
        $month_rows[$k]['prev_balance'] = $running;          // مطالبات/بدهی قبلی
        $month_rows[$k]['gross_claim'] = $running + (float)$r['base_pay']; // قبل از پاداش/جریمه/پرداخت
        $running += $r['net'];
        $month_rows[$k]['balance_upto'] = $running;          // مبلغ نهایی قابل پرداخت تا پایان این ماه
    }
    // جدیدترین ماه اول نمایش داده بشه
    foreach (array_reverse($month_rows, true) as $r) {
        $result['months'][] = $r;
    }

    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('sal_title') ?></h2>
</div>

<!-- §v12 §5.2: انتخاب «کارکرد این کارگاه / تجمیعی» به داخل پنجره جزئیات حقوق منتقل شد -->
<?php if (!can_see_all_workshops()): ?>
<p class="text-muted animate-in" style="font-size:11.5px;margin:0 0 10px">🏢 <?= t('نمایش پرسنل و مانده حقوق مربوط به همین کارگاه') ?></p>
<?php elseif ($salary_scope === 'all'): ?>
<p class="text-muted animate-in" style="font-size:11.5px;margin:0 0 10px">🌐 <?= t('nav_salary') ?> <?= t('هر') ?> <?= t('per_count') ?> <?= t('rep_total') ?> <?= t('کارکردش') ?> <?= t('در') ?> <?= t('همه') ?> <?= t('کارگاه') ?>‌<?= t('هاست') ?> <?= t('— در') ?> «<?= t('sal_details') ?>» <?= t('می‌توانید') ?> <?= t('بین') ?> <?= t('نمای') ?> <?= t('تجمیعی') ?> <?= t('و') ?> <?= t('تک') ?>‌<?= t('کارگاه') ?> <?= t('جابه‌جا شوید') ?>. <a href="index.php?page=salary&scope=ws" class="btn btn-ghost btn-sm" style="margin-right:10px">🏢 <?= t('فیلتر فقط این کارگاه') ?></a></p>
<?php else: ?>
<p class="text-muted animate-in" style="font-size:11.5px;margin:0 0 10px">🏢 <?= t('فیلتر فعال: فقط این کارگاه') ?>. <a href="index.php?page=salary&scope=all" class="btn btn-ghost btn-sm" style="margin-right:10px">🌐 <?= t('نمایش تجمیعی همه کارگاه‌ها') ?></a></p>
<?php endif; ?>

<div class="card animate-in">
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <?php
                    $sortIcon = function($col) use ($sort, $sort_dir) {
                        if ($sort === $col) return $sort_dir === 'ASC' ? ' ▲' : ' ▼';
                        return ' ⇅';
                    };
                    $base = 'index.php?page=salary';
                    ?>
                    <th><a href="<?= $base ?>&sort=full_name&dir=<?= ($sort==='full_name' && $sort_dir==='ASC') ? 'DESC' : 'ASC' ?>" style="text-decoration:none;color:inherit"><?= t('wh_name') ?><?= $sortIcon('full_name') ?></a></th>
                    <th><a href="<?= $base ?>&sort=is_karigar&dir=<?= ($sort==='is_karigar' && $sort_dir==='ASC') ? 'DESC' : 'ASC' ?>" style="text-decoration:none;color:inherit"><?= t('sal_type') ?><?= $sortIcon('is_karigar') ?></a></th>
                    <th><?= t('sal_balance') ?></th>
                    <th><?= t('wh_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($personnel as $p):
                    $b = $balances[$p['id']];
                ?>
                <tr>
                    <td>
                        <div class="d-flex align-center gap-2">
                            <div class="person-avatar"><?= mb_substr($p['full_name'], 0, 1) ?></div>
                            <div>
                                <strong><?= htmlspecialchars($p['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (!empty($workshop_labels[(int)$p['id']])): ?>
                                    <div style="font-size:11px;color:var(--text-secondary);margin-top:3px">
                                        <?= htmlspecialchars(implode('، ', $workshop_labels[(int)$p['id']]), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><span class="tag <?= $p['is_karigar'] ? 'tag-karigar' : 'tag-active' ?>"><?= $p['is_karigar'] ? t('sal_worker') : t('sal_employee') ?></span></td>
                    <td class="<?= $b['balance'] < 0 ? 'text-danger' : 'text-success' ?>">
                        <strong style="font-size:15px"><?= number_format($b['balance']) ?> <?= t('sal_toman') ?></strong>
                    </td>
                    <td>
                        <div class="d-flex gap-2" style="flex-wrap:wrap">
                        <a href="index.php?page=salary_detail&user_id=<?= $p['id'] ?>&scope=<?= $salary_scope ?>" target="_blank" class="btn btn-ghost btn-sm"><?= t('sal_details') ?></a>
                        <?php if ($can_manage): ?>
                        <button type="button" onclick="openSettleModal(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['full_name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)" class="btn btn-sm" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none" title="<?= t('تسویه') ?> <?= t('حساب') ?> <?= t('lea_to') ?> <?= t('wh_date') ?> <?= t('مشخص') ?>">💸 <?= t('تسویه') ?></button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($personnel)): ?>
                <tr><td colspan="4" class="text-center text-muted" style="padding:40px"><?= t('per_no_records') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- §v12 §5.1: مودال تسویه حساب تا تاریخ -->
<?php if ($can_manage): ?>
<div id="settleModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:430px">
        <div class="modal-header">
            <h3 class="modal-title">💸 <?= t('تسویه') ?> <?= t('حساب') ?> <?= t('lea_to') ?> <?= t('wh_date') ?></h3>
            <button onclick="closeModal('settleModal')" class="modal-close">✕</button>
        </div>
        <div class="modal-body">
            <form method="post"><?= get_csrf_field() ?>
                <input type="hidden" name="action" value="settle_person">
                <input type="hidden" name="user_id" id="settleUid">
                <p style="font-size:12.5px;color:var(--text-secondary);line-height:1.8"><?= t('pet_balance') ?> <?= t('حساب') ?> <strong id="settleName" style="color:var(--text)"></strong> <?= t('lea_to') ?> <?= t('wh_date') ?> <?= t('انتخابی') ?> <?= t('btn_calculate') ?> <?= t('و') ?> <?= t('به') ?>‌<?= t('عنوان') ?> «<?= t('adv_pay_salary') ?>» <?= t('ثبت') ?> <?= t('می‌شود') ?> <?= t('lea_to') ?> <?= t('حساب') ?> <?= t('او') ?> <?= t('lea_to') ?> <?= t('آن') ?> <?= t('wh_date') ?> <?= t('صفر') ?> <?= t('شود') ?>. <?= t('سوابق') ?> <?= t('قبلی') ?> <?= t('btn_delete') ?> <?= t('نمی‌') ?><?= t('شوند') ?>.</p>
                <div class="form-group">
                    <label class="form-label"><?= t('تسویه') ?> <?= t('lea_to') ?> <?= t('wh_date') ?>: <span class="required">*</span></label>
                    <input type="text" name="settle_date" id="settleDate" class="form-input jalali-date" placeholder="<?= t('date_placeholder') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('روش پرداخت') ?>:</label>
                    <select name="payment_method" class="form-select">
                        <option value="fund">🏛️ <?= t('صندوق') ?> / <?= t('حساب') ?> <?= t('اصلی') ?></option>
                        <option value="petty_cash">💰 <?= t('nav_petty_cash') ?></option>
                    </select>
                </div>
                <div id="settleAmountBox" style="padding:14px;border-radius:10px;background:var(--bg);text-align:center;margin-bottom:14px;font-size:13px;line-height:1.9">
                    📅 <?= t('wh_date') ?> <?= t('تسویه') ?> <?= t('را') ?> <?= t('انتخاب') ?> <?= t('یا') ?> <?= t('تایپ') ?> <?= t('کنید') ?>…
                </div>
                <button type="submit" id="settleSubmit" class="btn btn-primary w-full" disabled>✔ <?= t('تأیید') ?> <?= t('و') ?> <?= t('ثبت') ?> <?= t('تسویه') ?></button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Detail Modal -->
<div id="detailModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:800px">
        <div class="modal-header">
            <h3 class="modal-title" id="detailTitle"><?= t('sal_details') ?> <?= t('حساب') ?></h3>
            <button onclick="closeModal('detailModal')" class="modal-close">✕</button>
        </div>
        <div class="modal-body" id="detailBody" style="max-height:70vh;overflow-y:auto">
            <div class="text-center" style="padding:30px"><div class="spinner" style="margin:0 auto"></div></div>
        </div>
    </div>
</div>

<script>
const T = {
    details: <?= json_encode(t('sal_details'), JSON_UNESCAPED_UNICODE) ?>,
    base: <?= json_encode(t('sal_base_pay'), JSON_UNESCAPED_UNICODE) ?>,
    bonus: <?= json_encode(t('sal_bonus'), JSON_UNESCAPED_UNICODE) ?>,
    penalty: <?= json_encode(t('sal_penalty'), JSON_UNESCAPED_UNICODE) ?>,
    advance: <?= json_encode(t('sal_advance'), JSON_UNESCAPED_UNICODE) ?>,
    net: <?= json_encode(t('sal_net'), JSON_UNESCAPED_UNICODE) ?>,
    totalDebt: <?= json_encode(t('sal_total_debt'), JSON_UNESCAPED_UNICODE) ?>,
    toman: <?= json_encode(t('sal_toman'), JSON_UNESCAPED_UNICODE) ?>,
    regular: <?= json_encode(t('sal_regular'), JSON_UNESCAPED_UNICODE) ?>,
    overtime: <?= json_encode(t('sal_overtime'), JSON_UNESCAPED_UNICODE) ?>,
    night: <?= json_encode(t('sal_night'), JSON_UNESCAPED_UNICODE) ?>,
    dailyDetail: <?= json_encode(t('sal_daily_detail'), JSON_UNESCAPED_UNICODE) ?>,
    workDays: <?= json_encode(t('sal_work_days'), JSON_UNESCAPED_UNICODE) ?>,
    total: <?= json_encode(t('rep_total'), JSON_UNESCAPED_UNICODE) ?>,
    holiday: <?= json_encode(t('wh_holiday'), JSON_UNESCAPED_UNICODE) ?>,
    friday: <?= json_encode(t('wh_friday'), JSON_UNESCAPED_UNICODE) ?>,
    employee: <?= json_encode(t('sal_employee'), JSON_UNESCAPED_UNICODE) ?>,
    worker: <?= json_encode(t('sal_worker'), JSON_UNESCAPED_UNICODE) ?>,
    daily: <?= json_encode(t('sal_daily'), JSON_UNESCAPED_UNICODE) ?>,
    monthly: <?= json_encode(t('sal_monthly'), JSON_UNESCAPED_UNICODE) ?>,
};
// نمایش ساعت به صورت H:MM
function fmtHM(x) {
    x = parseFloat(x) || 0;
    var n = x < 0, m = Math.round(Math.abs(x) * 60), h = Math.floor(m / 60), mm = m % 60;
    return (n ? '-' : '') + h + ':' + (mm < 10 ? '0' : '') + mm;
}
// §v12 §5.2: اسکوپ جزئیات داخل خود مودال — پیش‌فرض تجمیعی
var canSeeAll = <?= can_see_all_workshops() ? 'true' : 'false' ?>;
window.__salDetScope = canSeeAll ? (window.__salDetScope || '<?= $salary_scope ?>') : 'ws';
function salScopeBar(userId, active) {
    if (!canSeeAll) return '';
    return '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">'
        + '<span style="font-size:12px;color:var(--text-secondary)"><?= t('نمای تفکیکی دقیق:') ?></span>'
        + '<a target="_blank" href="index.php?page=salary_detail&user_id=' + userId + '&scope=all" class="btn btn-ghost btn-sm">🌐 <?= t('تجمیعی') ?> <?= t('همه') ?> <?= t('nav_workshops') ?></a>'
        + '<a target="_blank" href="index.php?page=salary_detail&user_id=' + userId + '&scope=ws" class="btn btn-ghost btn-sm">🏢 <?= t('فقط این کارگاه') ?></a>'
        + '</div>';
}

async function showDetail(userId, name, scope) {
    if (name) window.__salDetName = name;
    if (scope) window.__salDetScope = scope;
    var curScope = window.__salDetScope;
    document.getElementById('detailTitle').textContent = T.details + ' - ' + (window.__salDetName || '');
    document.getElementById('detailBody').innerHTML = '<div class="text-center" style="padding:30px"><div class="spinner" style="margin:0 auto"></div></div>';
    openModal('detailModal');

    try {
        const res = await fetch('index.php?page=salary&detail_user=' + userId + '&scope=' + curScope);
        const data = await res.json();
        let html = '';

        if (!data.user) {
            html = '<p class="text-muted text-center"><?= t('info') ?> <?= t('یافت نشد') ?></p>';
        } else {
            html = salScopeBar(userId, curScope);
            const isKarigar = data.user.is_karigar == 1;
            html += '<div style="padding:14px;background:var(--bg);border-radius:12px;margin-bottom:16px">';
            html += '<strong style="font-size:16px">' + data.user.full_name + '</strong>';
            html += '<div class="text-muted" style="font-size:12px;margin-top:4px">';
            html += isKarigar
                ? T.worker + ' — ' + T.daily + ': ' + Number(data.user.daily_salary).toLocaleString() + ' ' + T.toman + ' · <?= t('ساعتی') ?>: ' + Number(Math.round(data.user.daily_salary / 8)).toLocaleString() + ' ' + T.toman
                : T.employee + ' — ' + T.monthly + ': ' + Number(data.user.monthly_salary).toLocaleString() + ' ' + T.toman + ' · <?= t('نرخ ساعتی') ?> <?= t('هر') ?> <?= t('rep_month') ?> <?= t('lea_from') ?> «<?= t('ساعات') ?> <?= t('کاری') ?> <?= t('الزامی') ?> <?= t('همان') ?> <?= t('rep_month') ?>» <?= t('btn_calculate') ?> <?= t('می‌شود') ?>.';
            html += '</div></div>';

            if (data.months.length === 0) {
                html += '<p class="text-muted text-center" style="padding:20px">' + T.dailyDetail + '</p>';
            } else {
                let totalNet = 0;
                data.months.forEach(function(m) {
                    totalNet += m.net;
                    html += '<div style="border:1px solid var(--border);border-radius:12px;margin-bottom:12px;overflow:hidden">';
                    html += '<div style="padding:12px 14px;background:var(--bg);display:flex;justify-content:space-between;align-items:center">';
                    html += '<strong>' + m.label + '</strong>';
                    html += '<div>' + T.workDays + ': <strong>' + m.work_days + '</strong></div>';
                    html += '</div>';

                    // Hours breakdown
                    html += '<div style="padding:12px 14px;font-size:13px">';
                    if (isKarigar) {
                        html += '<div style="display:flex;gap:16px;flex-wrap:wrap">';
                        html += '<div>💰 ' + T.total + ': <strong>' + m.total_days + '</strong></div>';
                        html += '<div>⏰ ' + T.regular + ': <strong>' + fmtHM(m.total_hours) + '</strong></div>';
                        html += '<div>➕ ' + T.overtime + ': <strong>' + m.extra_days + '</strong></div>';
                        html += '</div>';
                    } else {
                        html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;font-size:12px">';
                        if (typeof m.required_hours !== 'undefined') html += '<div style="padding:8px;background:rgba(99,102,241,0.08);border-radius:8px"><div style="color:var(--text-secondary);font-size:11px"><?= t('ساعات') ?> <?= t('کاری') ?> <?= t('الزامی') ?> <?= t('rep_month') ?></div><strong>' + fmtHM(m.required_hours) + '</strong><div style="font-size:10px;color:var(--text-secondary)">' + (m.required_work_days || 0) + ' <?= t('sal_work_days') ?> − ' + (m.approved_leave_days || 0) + ' <?= t('wh_day') ?> <?= t('nav_leaves') ?> <?= t('تأییدشده') ?></div></div>';
                        if (m.regular_hours > 0) html += '<div style="padding:8px;background:rgba(34,197,94,0.08);border-radius:8px"><div style="color:var(--text-secondary);font-size:11px"><?= t('ضریب') ?> ۱ — <?= t('sal_regular') ?></div><strong>' + fmtHM(m.regular_hours) + '</strong>' + (m.regular_pay ? ' = ' + Number(m.regular_pay).toLocaleString() + ' <?= t('ت') ?>' : '') + '</div>';
                        if (m.overtime_hours > 0) html += '<div style="padding:8px;background:rgba(234,179,8,0.08);border-radius:8px"><div style="color:var(--text-secondary);font-size:11px"><?= t('ضریب') ?> ۱.۳۳ — <?= t('sal_overtime') ?></div><strong>' + fmtHM(m.overtime_hours) + '</strong>' + (m.overtime_pay ? ' = ' + Number(m.overtime_pay).toLocaleString() + ' <?= t('ت') ?>' : '') + '</div>';
                        if (m.night_hours > 0) html += '<div style="padding:8px;background:rgba(168,85,247,0.08);border-radius:8px"><div style="color:var(--text-secondary);font-size:11px"><?= t('ضریب') ?> ۲ — <?= t('wh_night') ?></div><strong>' + fmtHM(m.night_hours) + '</strong>' + (m.night_pay ? ' = ' + Number(m.night_pay).toLocaleString() + ' <?= t('ت') ?>' : '') + '</div>';
                        if (m.friday_hours > 0) html += '<div style="padding:8px;background:rgba(59,130,246,0.08);border-radius:8px"><div style="color:var(--text-secondary);font-size:11px"><?= t('ضریب') ?> ۱.۳۳ — <?= t('wh_friday') ?></div><strong>' + fmtHM(m.friday_hours) + '</strong>' + (m.friday_pay ? ' = ' + Number(m.friday_pay).toLocaleString() + ' <?= t('ت') ?>' : '') + '</div>';
                        if (m.holiday_hours > 0) html += '<div style="padding:8px;background:rgba(239,68,68,0.08);border-radius:8px"><div style="color:var(--text-secondary);font-size:11px"><?= t('ضریب') ?> ۲ — <?= t('wh_holiday') ?></div><strong>' + fmtHM(m.holiday_hours) + '</strong>' + (m.holiday_pay ? ' = ' + Number(m.holiday_pay).toLocaleString() + ' <?= t('ت') ?>' : '') + '</div>';
                        html += '</div>';
                        if (m.hourly_rate) html += '<div style="margin-top:6px;font-size:11px;color:var(--text-secondary)"><?= t('دستمزد') ?> <?= t('هر') ?> <?= t('wh_hours') ?>: ' + Number(m.hourly_rate).toLocaleString() + ' <?= t('sal_toman') ?></div>';
                    }
                    html += '</div>';

                    // Daily detail (collapsible)
                    html += '<details style="border-top:1px solid var(--border)">';
                    html += '<summary style="padding:10px 14px;font-size:12px;cursor:pointer;color:var(--text-secondary)">' + T.dailyDetail + ' ▾</summary>';
                    html += '<div style="padding:0 14px 10px">';
                    html += '<table style="width:100%;font-size:11px;border-collapse:collapse">';
                    if (isKarigar) {
                        html += '<thead><tr style="border-bottom:1px solid var(--border)"><th style="padding:6px;text-align:right"><?= t('wh_date') ?></th><th><?= t('کارگاه') ?></th><th><?= t('btn_checkin') ?></th><th><?= t('nav_logout') ?></th><th><?= t('wh_status') ?></th><th><?= t('کل کار') ?></th><th><?= t('wh_hours') ?> <?= t('پرداخت') ?></th><th><?= t('sal_overtime') ?></th></tr></thead><tbody>';
                        m.daily_detail.forEach(function(d) {
                            let tags = '';
                            if (d.is_friday) tags += '<span class="tag tag-friday" style="font-size:9px">' + T.friday + '</span> ';
                            if (d.is_holiday) tags += '<span class="tag tag-holiday" style="font-size:9px">' + T.holiday + '</span> ';
                            html += '<tr style="border-bottom:1px solid var(--border)"><td style="padding:5px">' + d.jalali + '</td><td style="font-size:10px">' + (d.workshop ? '<span style="background:rgba(99,102,241,.12);padding:1px 6px;border-radius:5px">' + (d.mission ? '🚀 ' : '🏢 ') + d.workshop + (d.mission ? ' <?= t('(مأموریت)') ?>' : '') + '</span>' : '-') + '</td><td dir="ltr">' + (d.start_time || '-') + '</td><td dir="ltr">' + (d.end_time || '-') + '</td><td>' + (tags || '-') + '</td><td>' + fmtHM(d.total_worked) + '</td><td>' + fmtHM(d.paid_hours) + '</td><td>' + fmtHM(d.overtime_hours) + '</td></tr>';
                        });
                    } else {
                        html += '<thead><tr style="border-bottom:1px solid var(--border)"><th style="padding:6px;text-align:right"><?= t('wh_date') ?></th><th><?= t('کارگاه') ?></th><th><?= t('btn_checkin') ?></th><th><?= t('nav_logout') ?></th><th><?= t('wh_status') ?></th><th><?= t('sal_regular') ?></th><th><?= t('sal_overtime') ?></th><th><?= t('wh_night') ?></th><th><?= t('wh_friday') ?></th><th><?= t('wh_holiday') ?></th></tr></thead><tbody>';
                        m.daily_detail.forEach(function(d) {
                            let tags = '';
                            if (d.is_friday) tags += '<span class="tag tag-friday" style="font-size:9px">' + T.friday + '</span> ';
                            if (d.is_holiday) tags += '<span class="tag tag-holiday" style="font-size:9px">' + T.holiday + '</span> ';
                            html += '<tr style="border-bottom:1px solid var(--border)"><td style="padding:5px">' + d.jalali + '</td><td style="font-size:10px">' + (d.workshop ? '<span style="background:rgba(99,102,241,.12);padding:1px 6px;border-radius:5px">' + (d.mission ? '🚀 ' : '🏢 ') + d.workshop + (d.mission ? ' <?= t('(مأموریت)') ?>' : '') + '</span>' : '-') + '</td><td dir="ltr">' + (d.start_time || '-') + '</td><td dir="ltr">' + (d.end_time || '-') + '</td><td>' + (tags || '-') + '</td><td>' + fmtHM(d.regular) + '</td><td>' + fmtHM(d.overtime) + '</td><td>' + fmtHM(d.night) + '</td><td>' + fmtHM(d.friday_hours || 0) + '</td><td>' + fmtHM(d.holiday_hours || 0) + '</td></tr>';
                        });
                    }
                    html += '</tbody></table></div></details>';

                    // Summary row: same ledger order as the printable/detail page.
                    html += '<div style="padding:10px 14px;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;border-top:1px solid var(--border)">';
                    html += '<div style="color:var(--text-secondary)">📅 <?= t('مطالبات') ?> <?= t('rep_month') ?>‌<?= t('های') ?> <?= t('قبل') ?>: <strong>' + Number(m.prev_balance || 0).toLocaleString() + '</strong></div>';
                    html += '<div>' + '<?= t('طلب') ?> <?= t('ناخالص') ?> <?= t('rep_month') ?> <?= t('جاری') ?>' + ': <strong>' + Number(m.base_pay).toLocaleString() + '</strong></div>';
                    html += '<div style="font-weight:700;color:#7c3aed"><?= t('rep_total') ?> <?= t('sal_total_debt') ?> <?= t('ناخالص') ?>: <strong>' + Number(typeof m.gross_claim !== 'undefined' ? m.gross_claim : ((m.prev_balance || 0) + m.base_pay)).toLocaleString() + '</strong></div>';
                    html += '<div class="text-danger">' + T.penalty + ': <strong>-' + Number(m.penalty).toLocaleString() + '</strong>' + (m.penalty_hours ? ' (' + fmtHM(m.penalty_hours) + ')' : '') + '</div>';
                    html += '<div>' + T.advance + ': <strong>-' + Number(m.advance).toLocaleString() + '</strong></div>';
                    html += '<div class="text-success">' + T.bonus + ': <strong>+' + Number(m.bonus).toLocaleString() + '</strong>' + (m.bonus_hours ? ' (' + fmtHM(m.bonus_hours) + ')' : '') + '</div>';
                    html += '<div style="font-weight:800;color:' + (m.balance_upto >= 0 ? 'var(--success)' : 'var(--danger)') + '">💳 <?= t('مبلغ نهایی قابل پرداخت') ?>: ' + Number(m.balance_upto).toLocaleString() + '</div>';
                    html += '</div>';

                    // Advance items detail
                    if (m.advance_items && m.advance_items.length > 0) {
                        html += '<div style="padding:8px 14px 10px;font-size:11px;border-top:1px solid var(--border)">';
                        m.advance_items.forEach(function(ai) {
                            let typeLabel = ai.type === 'debt'
                                ? (Number(ai.amount) < 0 ? '📌 <?= t('adv_debt_credit') ?>' : '📌 <?= t('adv_debt_debit') ?>')
                                : '💵 ' + T.advance;
                            html += '<div style="padding:3px 0;color:var(--text-secondary)">' + typeLabel + ': ' + (ai.pay_date || '-') + ' — ' + (ai.reason || '<?= t('بدون توضیح') ?>') + ' = ' + Number(ai.amount).toLocaleString() + ' <?= t('ت') ?></div>';
                        });
                        html += '</div>';
                    }

                    html += '</div>';
                });

                html += '<div style="margin-top:14px;padding:14px;background:' + (totalNet >= 0 ? 'rgba(34,197,94,0.1)' : 'rgba(239,68,68,0.1)') + ';border-radius:12px;text-align:center">';
                html += '<div class="text-muted" style="font-size:11px">' + T.totalDebt + '</div>';
                html += '<strong style="font-size:20px;color:' + (totalNet >= 0 ? 'var(--success)' : 'var(--danger)') + '">' + Number(totalNet).toLocaleString() + ' ' + T.toman + '</strong>';
                html += '</div>';
            }
        }
        document.getElementById('detailBody').innerHTML = html;
    } catch (e) {
        document.getElementById('detailBody').innerHTML = '<p class="text-danger"><?= t('error') ?> <?= t('در') ?> <?= t('دریافت') ?> <?= t('info') ?>: ' + e.message + '</p>';
    }
}

// §v12 §5.1: مودال <?= t('تسویه حساب') ?> — <?= t('با') ?> <?= t('پیش') ?>‌<?= t('نمایش') ?> زنده <?= t('مبلغ') ?> (<?= t('محاسبه') ?> <?= t('سمت') ?> <?= t('سرور') ?>)
function fa2enDigits(s) { return (s || '').replace(/[۰-۹]/g, function(c) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c); }); }
var __settleTimer = null;
function openSettleModal(uid, name) {
    document.getElementById('settleUid').value = uid;
    document.getElementById('settleName').textContent = name;
    document.getElementById('settleDate').value = '';
    document.getElementById('settleSubmit').disabled = true;
    document.getElementById('settleAmountBox').innerHTML = '📅 <?= t('wh_date') ?> <?= t('تسویه') ?> <?= t('را') ?> <?= t('انتخاب') ?> <?= t('یا') ?> <?= t('تایپ') ?> <?= t('کنید') ?>…';
    openModal('settleModal');
    if (__settleTimer) clearInterval(__settleTimer);
    var lastVal = '';
    __settleTimer = setInterval(function() {
        var modal = document.getElementById('settleModal');
        if (!modal || modal.classList.contains('hidden')) { clearInterval(__settleTimer); __settleTimer = null; return; }
        var v = fa2enDigits(document.getElementById('settleDate').value.trim());
        if (v !== lastVal) { lastVal = v; updateSettlePreview(); }
    }, 350);
}
async function updateSettlePreview() {
    var uid = document.getElementById('settleUid').value;
    var d = fa2enDigits(document.getElementById('settleDate').value.trim());
    var box = document.getElementById('settleAmountBox');
    var btn = document.getElementById('settleSubmit');
    btn.disabled = true;
    if (!/^\d{4}\/\d{2}\/\d{2}$/.test(d)) {
        box.innerHTML = '📅 <?= t('wh_date') ?> <?= t('تسویه') ?> <?= t('را') ?> <?= t('به') ?><?= t('‌صورت') ?> <?= t('کامل') ?> <?= t('وارد کنید') ?> (<?= t('مثلاً') ?> <?= t('date_placeholder') ?>)';
        return;
    }
    box.innerHTML = '⏳ <?= t('در حال') ?> <?= t('btn_calculate') ?> <?= t('pet_balance') ?> <?= t('حساب') ?>…';
    try {
        const res = await fetch('index.php?page=salary&settle_preview=' + uid + '&date=' + encodeURIComponent(d));
        const data = await res.json();
        if (!data.ok) {
            box.innerHTML = '<span class="text-danger">' + (data.error || '<?= t('error') ?> <?= t('در') ?> <?= t('btn_calculate') ?>') + '</span>';
            return;
        }
        if (data.amount <= 0) {
            box.innerHTML = '💼 <?= t('pet_balance') ?> <?= t('lea_to') ?> <strong>' + data.jdate + '</strong>: <strong>' + Number(data.amount).toLocaleString() + '</strong> <?= t('sal_toman') ?><br><span class="text-danger" style="font-size:12px"><?= t('pet_balance') ?><?= t('‌ای') ?> <?= t('برای تسویه وجود ندارد') ?></span>';
            return;
        }
        box.innerHTML = '💼 <?= t('pet_balance') ?> <?= t('lea_to') ?> <strong>' + data.jdate + '</strong>:<br><strong style="font-size:20px;color:var(--success)">' + Number(data.amount).toLocaleString() + '</strong> <?= t('sal_toman') ?>';
        btn.disabled = false;
    } catch (e) {
        box.innerHTML = '<span class="text-danger"><?= t('error') ?> <?= t('در') ?> <?= t('ارتباط') ?> <?= t('با') ?> <?= t('سرور') ?></span>';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
