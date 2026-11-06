<?php
/**
 * گزارش روزانه پروژه - صفحه مستقل
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_login();

$ws_id = $_SESSION['workshop_id'] ?? 0;
$report_date_g = $_GET['date'] ?? date('Y-m-d');
$report_date_j = to_jalali($report_date_g);
$day_names = localized_weekday_names();
$report_day_name = $day_names[date('w', strtotime($report_date_g))];
$print_mode = isset($_GET['print']);

// §v7: شیفت فعال باید قبل از هندلر POST تعریف شه (توی بایگانی خودکار استفاده می‌شه)
$active_shift = ($_GET['shift'] ?? '') === 'night' ? 'night' : 'day';
$is_admin_page = is_strict_admin();
$is_yaser_page = (($_SESSION['username'] ?? '') === 'yaser');

// --- کارگاه و لوگوها ---
$curr_ws = null;
if ($ws_id) {
    try {
        $st_ws = $pdo->prepare("SELECT * FROM workshops WHERE id = ?");
        $st_ws->execute([$ws_id]);
        $curr_ws = $st_ws->fetch();
    } catch (Throwable $e) {}
}
$employer_name = trim($curr_ws['employer_name'] ?? '');
$employer_logo = $curr_ws['employer_logo'] ?? '';
$company_logo = $curr_ws['logo'] ?? '';
$ws_display_name = $curr_ws['name'] ?? t('پروژه اداری تجاری سه‌راه یاسر');

// --- ذخیره تایید ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_step'])) {
    $step = (int)$_POST['approve_step'];
    $note = trim($_POST['approve_note'] ?? '');
    $user_id = $_SESSION['user_id'];
    $user_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? '';

    // §v7: مجوزهای امضا — نام مدیر توی خانه «واحد اجرا» ثبت نمی‌شه
    // مرحله ۱ (واحد اجرا): فقط نیروهای واحد (نه مدیر، نه ریاست کارگاه)
    // مرحله ۲ (سرپرست کارگاه پیمانکار): فقط مدیر
    // مرحله ۳ (ریاست کارگاه): فقط yaser
    $allowed = false;
    if ($step === 1) $allowed = !$is_admin_page && !$is_yaser_page;
    if ($step === 2) $allowed = $is_admin_page;
    if ($step === 3) $allowed = $is_yaser_page;

    $folder = __DIR__ . '/../uploads/reports/';
    if (!is_dir($folder)) mkdir($folder, 0777, true);
    $file = $folder . 'daily_' . $report_date_g . '_' . $ws_id . '.json';

    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }

    if ($allowed) {
        $data['approvals'][$step] = [
            'user_id' => $user_id,
            'user_name' => $user_name,
            'note' => $note,
            'date' => date('Y-m-d H:i:s'),
        ];

        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // --- ذخیره خودکار در بایگانی بعد از ۳ تایید ---
    if (!empty($data['approvals'][1]) && !empty($data['approvals'][2]) && !empty($data['approvals'][3])) {
        $j_parts = explode('/', $report_date_j);
        $j_year = $j_parts[0] ?? '0000';
        $j_month = $j_parts[1] ?? '00';
        $archive_base = __DIR__ . '/../uploads/archive/';
        $archive_dir = $archive_base . 'گزارش کار/' . $j_year . '/' . $j_month;
        if (!is_dir($archive_dir)) mkdir($archive_dir, 0777, true);

        $date_str = str_replace('/', '-', $report_date_j);
        $shift_label = $active_shift === 'night' ? '_شیفت_شب' : '_شیفت_روز';

        // ذخیره JSON با شیفت
        $json_file = $archive_dir . '/گزارش_' . $date_str . $shift_label . '.json';
        $data['shift_type'] = $active_shift;
        file_put_contents($json_file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // ذخیره HTML با شیفت
        $html_file = $archive_dir . '/گزارش_' . $date_str . $shift_label . '.html';
        $html_content = file_get_contents($_SERVER['SCRIPT_FILENAME']);
        $html_content = preg_replace('/^<\?php.*?\?>/s', '', $html_content, 1);
        $html_content = str_replace('$print_mode = isset($_GET[\'print\']);', '$print_mode = true;', $html_content);
        file_put_contents($html_file, $html_content);
    }

    // ریدایرکت بعد از تایید
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// --- بارگذاری تاییدها ---
$approvals = [1 => null, 2 => null, 3 => null];
$file = __DIR__ . '/../uploads/reports/daily_' . $report_date_g . '_' . $ws_id . '.json';
if (file_exists($file)) {
    $saved = json_decode(file_get_contents($file), true) ?: [];
    $approvals = $saved['approvals'] ?? $approvals;
}

// --- دریافت اطلاعات ---
$settings = get_workshop_settings($pdo, $ws_id);

// پرسنل حاضر در روز + ساعت کاری
// شیفت روز: 08:00 تا 21:00 همین روز
// شیفت شب: 21:00 روز قبل تا 07:00 امروز

$prev_date_g = date('Y-m-d', strtotime($report_date_g . ' -1 day'));

// رکوردهای روز جاری
$st = $pdo->prepare("
    SELECT wh.user_id, wh.start_time, wh.end_time, wh.work_date, u.full_name, u.is_karigar, u.position,
           COALESCE(u.work_role, '') as work_role
    FROM work_hours wh
    JOIN users u ON wh.user_id = u.id
    WHERE wh.workshop_id = ? AND wh.work_date = ?
    ORDER BY u.is_karigar, u.full_name
");
$st->execute([$ws_id, $report_date_g]);
$today_hours = $st->fetchAll();

// رکوردهای روز قبل (برای شیفت شبی که از شب قبل شروع شده)
$st->execute([$ws_id, $prev_date_g]);
$yesterday_hours = $st->fetchAll();

// تفکیک شیفت روز: شروع بین 07:00 و 21:00 همین روز
$day_attendance = [];
foreach ($today_hours as $ah) {
    $start_h = (int)date('H', strtotime($ah['start_time']));
    if ($start_h >= 7 && $start_h < 21) {
        $day_attendance[] = $ah;
    }
}

// تفکیک شیفت شب: فقط رکوردهای همین روز که شروع قبل 07:00 یا بعد 21:00 باشه
$night_attendance = [];
foreach ($today_hours as $ah) {
    $start_h = (int)date('H', strtotime($ah['start_time']));
    $is_open = ($ah['end_time'] === '00:00:00');
    if ($start_h >= 21 || $start_h < 7) {
        $night_attendance[] = $ah;
    } elseif ($is_open) {
        $night_attendance[] = $ah; // ورود باز = هنوز سر کاره
    }
}

// آیا شیفت شب هم داریم؟
$has_night_shift = count($night_attendance) > 0;

// شمارش بر اساس نقش - تابع کمکی
function count_roles($list) {
    $counts = [
        'karigar' => 0, 'sarkarigar' => 0,
        'ranande bil' => 0, 'sakht karan' => 0, 'ranande babkat' => 0, 'joskar' => 0, 'paymandkar other' => 0,
        'mohandes ejra' => 0, 'hse' => 0, 'anbar dar' => 0, 'modir projeh' => 0,
        'sarparast' => 0, 'daftar fanni' => 0, 'employee other' => 0,
    ];
    foreach ($list as $a) {
        $is_k = (int)$a['is_karigar'];
        $role = trim(!empty($a['work_role']) ? $a['work_role'] : ($a['position'] ?? ''));
        $role_lower = strtolower($role);

        if ($is_k == 1) {
            if (strpos($role_lower, 'سرکارگر') !== false || strpos($role_lower, 'sarkarigar') !== false) {
                $counts['sarkarigar']++;
            } else {
                $counts['karigar']++;
            }
        } elseif ($is_k == 2) {
            if (strpos($role_lower, 'اسکلت') !== false) $counts['sakht karan']++;
            elseif (strpos($role_lower, 'راننده بیل') !== false) $counts['ranande bil']++;
            elseif (strpos($role_lower, 'راننده بابکت') !== false) $counts['ranande babkat']++;
            elseif (strpos($role_lower, 'جوشکار') !== false) $counts['joskar']++;
            else $counts['paymandkar other']++;
        } else {
            if (strpos($role_lower, 'مهندس اجرا') !== false || strpos($role_lower, 'mohandes ejra') !== false) $counts['mohandes ejra']++;
            elseif (strpos($role_lower, 'hse') !== false) $counts['hse']++;
            elseif (strpos($role_lower, 'انبار') !== false) $counts['anbar dar']++;
            elseif (strpos($role_lower, 'مدیر پروژه') !== false || strpos($role_lower, 'modir') !== false) $counts['modir projeh']++;
            elseif (strpos($role_lower, 'سرپرست') !== false || strpos($role_lower, 'sarparast') !== false) $counts['sarparast']++;
            elseif (strpos($role_lower, 'دفتر فنی') !== false || strpos($role_lower, 'daftar') !== false || strpos($role_lower, 'مسئول دفتر') !== false) $counts['daftar fanni']++;
            else $counts['employee other']++;
        }
    }
    return $counts;
}

$day_counts = count_roles($day_attendance);
$night_counts = count_roles($night_attendance);

// §v9: شمارش گزارش‌های هر شیفت برای این تاریخ — چون گزارش‌های قدیمی بیشتر شیفت شب ثبت شدن
$_dr_shift  = db_has_column($pdo, 'daily_reports', 'shift');
$_dr_status = db_has_column($pdo, 'daily_reports', 'status');
$rep_counts = ['day' => 0, 'night' => 0];
$_rc_sql = "SELECT " . ($_dr_shift ? "COALESCE(NULLIF(dr.shift,''),'day')" : "'day'") . " AS s, COUNT(*) AS c FROM daily_reports dr WHERE dr.workshop_id = ? AND dr.report_date = ? AND dr.category = 'ejra'";
if ($_dr_status) $_rc_sql .= " AND dr.status = 'approved'";
$_rc_sql .= " GROUP BY s";
$_st = $pdo->prepare($_rc_sql);
$_st->execute([$ws_id, $report_date_g]);
foreach ($_st->fetchAll() as $_r) { if (isset($rep_counts[$_r['s']])) $rep_counts[$_r['s']] = (int)$_r['c']; }

// انتخاب شیفت فعال برای نمایش — §v9: پیش‌فرض شیفتی که گزارش دارد (حل مشکل «گزارش روزهای قبل نیست»)
$req_shift = $_GET['shift'] ?? '';
if (in_array($req_shift, ['day', 'night'])) {
    $active_shift = $req_shift;
} elseif ($rep_counts['day'] > 0) {
    $active_shift = 'day';
} elseif ($rep_counts['night'] > 0) {
    $active_shift = 'night';
} else {
    $active_shift = (count($day_attendance) > 0) ? 'day' : ((count($night_attendance) > 0) ? 'night' : 'day');
}
// حفظ شیفت در لینک‌های ناوبری
$qp_extra = '&shift=' . urlencode($active_shift);
$attendance = ($active_shift === 'night') ? $night_attendance : $day_attendance;
$counts = ($active_shift === 'night') ? $night_counts : $day_counts;

// مهندسین اجرا — §v7: نام مدیر و نیروهای واحد HSE/انبار توی فرم اجرا نمیاد
$st = $pdo->prepare("
    SELECT DISTINCT u.full_name FROM work_hours wh
    JOIN users u ON wh.user_id = u.id
    WHERE wh.workshop_id = ? AND wh.work_date = ?
    AND u.is_karigar = 0 AND u.role != 'admin'
    AND (u.work_role LIKE '%مهندس اجرا%' OR u.work_role LIKE '%mohandes ejra%' OR u.position LIKE '%مهندس اجرا%' OR u.position LIKE '%mohandes ejra%')
");
$st->execute([$ws_id, $report_date_g]);
$ejra_engineers = array_column($st->fetchAll(), 'full_name');

// تکمیل‌کنندگان دستور کارِ واحد اجرا (نه مدیر، نه نیروهای HSE/انبار)
$st = $pdo->prepare("
    SELECT DISTINCT u.full_name FROM work_order_items woi
    JOIN work_orders wo ON woi.work_order_id = wo.id
    JOIN users u ON woi.completed_by = u.id
    WHERE wo.workshop_id = ? AND woi.is_completed = 1
    AND DATE(woi.completed_at) = ?
    AND (wo.target_team IS NULL OR wo.target_team = '' OR wo.target_team = 'ejra')
    AND u.role != 'admin'
    AND LOWER(COALESCE(u.work_role,'')) NOT LIKE '%hse%' AND COALESCE(u.work_role,'') NOT LIKE '%انبار%'
    AND LOWER(COALESCE(u.position,'')) NOT LIKE '%hse%' AND COALESCE(u.position,'') NOT LIKE '%انبار%'
");
$st->execute([$ws_id, $report_date_g]);
$ejra_engineers = array_merge($ejra_engineers, array_column($st->fetchAll(), 'full_name'));

$st = $pdo->prepare("
    SELECT DISTINCT u.full_name FROM daily_reports dr
    JOIN users u ON dr.user_id = u.id
    WHERE dr.workshop_id = ? AND dr.report_date = ?
    AND dr.status = 'approved'
    AND u.is_karigar = 0 AND u.role != 'admin'
    AND (u.work_role LIKE '%مهندس اجرا%' OR u.work_role LIKE '%mohandes ejra%' OR u.position LIKE '%مهندس اجرا%' OR u.position LIKE '%mohandes ejra%')
");
$st->execute([$ws_id, $report_date_g]);
$ejra_engineers = array_merge($ejra_engineers, array_column($st->fetchAll(), 'full_name'));

$ejra_engineers = array_unique($ejra_engineers);

// گزارشات روزانه (ساختاریافته) — فقط شیفت فعال و فقط آیتم‌های ردنشده
// §v7: این فرم فقط مخصوص واحد اجراست — واحد HSE و انبار فرم جدای خودشون رو دارن
$report_cat = 'ejra';

$dr_has_shift  = db_has_column($pdo, 'daily_reports', 'shift');
$dr_has_status = db_has_column($pdo, 'daily_reports', 'status');

$act_sql  = "SELECT dr.wo_description, dr.wo_progress, dr.wo_next_day, dr.report_text, u.full_name FROM daily_reports dr JOIN users u ON dr.user_id = u.id WHERE dr.workshop_id = ? AND dr.report_date = ?";
$act_args = [$ws_id, $report_date_g];
if ($dr_has_shift)  { $act_sql .= " AND dr.shift = ?";               $act_args[] = $active_shift; }
if ($dr_has_status) { $act_sql .= " AND dr.status = 'approved'"; }
if ($report_cat)    { $act_sql .= " AND dr.category = ?";            $act_args[] = $report_cat; }
$act_sql .= " ORDER BY dr.id";

$st = $pdo->prepare($act_sql);
$st->execute($act_args);
$activities = [];
while ($dr = $st->fetch()) {
    if (!empty($dr['wo_description'])) {
        $descs = array_filter(array_map('trim', explode("\n", $dr['wo_description'])));
        $progs = array_filter(array_map('trim', explode("\n", $dr['wo_progress'] ?? '')));
        $nexts = array_filter(array_map('trim', explode("\n", $dr['wo_next_day'] ?? '')));
        $max = max(count($descs), count($progs), count($nexts));
        for ($i = 0; $i < $max; $i++) {
            $activities[] = [
                'description' => $descs[$i] ?? '',
                'progress' => $progs[$i] ?? '',
                'next_day' => $nexts[$i] ?? '',
            ];
        }
    } elseif (!empty($dr['report_text'])) {
        foreach (explode("\n", trim($dr['report_text'])) as $line) {
            $line = trim($line);
            if (!empty($line)) $activities[] = ['description' => $line, 'progress' => '', 'next_day' => ''];
        }
    }
}

// شیفت اتوماتیک
$st = $pdo->prepare("SELECT start_time, end_time FROM work_hours WHERE workshop_id = ? AND work_date = ? LIMIT 5");
$st->execute([$ws_id, $report_date_g]);
$shift_times = $st->fetchAll();
$auto_shift = 'روز';
foreach ($shift_times as $s) {
    $start_hour = (int)date('H', strtotime($s['start_time']));
    $end_hour = (int)date('H', strtotime($s['end_time']));
    if ($start_hour >= 18 || $start_hour < 6 || $end_hour >= 22) {
        $auto_shift = 'شب';
        break;
    }
}

$yaser_user = null;
$st = $pdo->prepare("SELECT id, full_name FROM users WHERE username = 'yaser' LIMIT 1");
$st->execute();
$yaser_user = $st->fetch();

$current_user_id = $_SESSION['user_id'];
$is_yaser = (($_SESSION['username'] ?? '') === 'yaser');
$step_labels = [t('واحد اجرا (مهندسین)'), t('سرپرست کارگاه'), t('ریاست کارگاه')];

// اطلاعات سال/ماه/روز برای منوی کشویی
$j_parts_now = explode('/', $report_date_j);
$sel_jy = (int)($j_parts_now[0] ?? date('Y'));
$sel_jm = (int)($j_parts_now[1] ?? date('m'));
$sel_jd = (int)($j_parts_now[2] ?? date('d'));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('فرم گزارش') ?> <?= t('sal_daily') ?> <?= t('اجرا') ?> - <?= $report_date_j ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family:'Vazirmatn',Tahoma,sans-serif;
            direction:<?= $lang_dir ?>; background:#f0f2f5; color:#1a1a2e;
            min-height:100vh;
        }

        .page {
            max-width:1000px; margin:20px auto; background:white;
            border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,0.08);
            overflow:hidden; width:100%; padding:0;
        }

        .top-bar {
            display:flex; justify-content:space-between; align-items:center;
            padding:12px 16px; background:linear-gradient(135deg,#667eea,#764ba2); color:white;
            flex-wrap:wrap; gap:8px;
        }
        .top-bar h1 { font-size:clamp(14px,2.5vw,18px); font-weight:700; }
        .top-bar a { color:white; text-decoration:none; background:rgba(255,255,255,0.2); padding:5px 12px; border-radius:8px; font-size:12px; }

        /* منوی انتخاب تاریخ */
        .date-nav {
            display:flex; gap:6px; align-items:center; padding:10px 14px;
            background:#f8fafc; border-bottom:1px solid #eee; flex-wrap:wrap;
        }
        .date-nav select, .date-nav button {
            font-family:inherit; font-size:12px; padding:5px 8px;
            border:1px solid #ddd; border-radius:6px; background:white;
        }
        .date-nav button {
            background:linear-gradient(135deg,#667eea,#764ba2); color:white;
            border:none; cursor:pointer; font-weight:600; padding:5px 14px;
        }
        .date-nav button:hover { opacity:0.9; }

        /* هدر گزارش */
        .report-header {
            display:flex; justify-content:space-between; align-items:flex-start;
            border-bottom:3px solid #333; padding:10px 14px; gap:8px;
        }
        .report-col { flex:1; min-width:0; }
        .report-title { font-size:clamp(13px,2vw,16px); font-weight:700; color:#333; }

        /* جداول */
        .section { padding:8px 12px; }
        .section-title {
            font-size:clamp(10px,1.8vw,13px); font-weight:700;
            border-bottom:2px solid #333; padding-bottom:3px; margin-bottom:6px;
        }
        .grid-2 {
            display:grid; grid-template-columns:1fr 1fr; gap:8px;
        }

        table.data { width:100%; border-collapse:collapse; font-size:clamp(8px,1.5vw,10px); }
        table.data th {
            background:#f1f5f9; padding:4px 2px; border:1px solid #ccc;
            text-align:center; font-weight:600; font-size:clamp(7px,1.3vw,9px);
            overflow:hidden; white-space:nowrap;
        }
        table.data td {
            padding:3px 2px; border:1px solid #ddd; text-align:center;
            overflow:hidden; word-wrap:break-word; font-size:clamp(7px,1.3vw,9px);
        }
        table.data td.right { text-align:right; }

        .inp {
            width:100%; padding:2px 3px; border:1px solid #ddd; border-radius:3px;
            font-size:clamp(7px,1.3vw,9px); font-family:inherit; text-align:center;
            max-width:100%; overflow:hidden;
        }
        .inp:focus { outline:none; border-color:#667eea; }
        .inp.txt-r { text-align:right; }

        /* امضاها */
        .signatures {
            display:flex; justify-content:space-between; padding:12px 14px;
            border-top:2px solid #333; gap:8px; flex-wrap:wrap;
        }
        .sig-box { flex:1; min-width:100px; text-align:center; }
        .sig-line { border-top:2px solid #333; margin-top:30px; padding-top:4px; font-size:9px; font-weight:600; }
        .sig-check { margin-top:4px; font-size:9px; }
        .sig-check.done { color:#16a34a; font-weight:600; }
        .sig-check.pending { color:#999; }

        .action-bar {
            display:flex; gap:6px; padding:10px 14px; background:white;
            border-top:1px solid #eee; flex-wrap:wrap; align-items:center;
        }
        .btn {
            padding:7px 14px; border:none; border-radius:8px; cursor:pointer;
            font-family:inherit; font-size:11px; font-weight:600; text-decoration:none;
            display:inline-flex; align-items:center; gap:4px;
        }
        .btn-print { background:linear-gradient(135deg,#667eea,#764ba2); color:white; }
        .btn-approve { background:linear-gradient(135deg,#059669,#10b981); color:white; }

        /* ========== ریسپانسیو ========== */
        @media (max-width:768px) {
            .page { margin:10px auto; border-radius:10px; }
            .report-header { flex-direction:column; gap:6px; padding:8px 10px; }
            .report-col { text-align:center !important; }
            .grid-2 { grid-template-columns:1fr; gap:6px; }
            .section { padding:6px 8px; }
            .signatures { flex-direction:column; gap:12px; }
            .sig-box { min-width:auto; }
        }

        @media (max-width:480px) {
            .top-bar { padding:8px 10px; }
            .top-bar h1 { font-size:14px; }
            .section { padding:4px 6px; }
            table.data { font-size:7px; }
            table.data th, table.data td { padding:2px 1px; font-size:7px; }
            .inp { font-size:7px; padding:1px 2px; }
        }

        /* ========== پرینت A4 ========== */
        @media print {
            * {
                -webkit-print-color-adjust:exact !important;
                print-color-adjust:exact !important;
            }
            html, body { margin:0; padding:0; background:white; direction:<?= $lang_dir ?> !important; }
            body { padding:0; }
            .page { box-shadow:none; border-radius:0; margin:0 auto; max-width:100%; width:100%; overflow:hidden; direction:<?= $lang_dir ?> !important; }
            .top-bar, .date-nav, .action-bar, .no-print { display:none !important; }

            /* هدر پرینت */
            .report-header { border-bottom:1px solid #000; padding:6px 8px !important; }
            .report-col { flex:1; min-width:0; }
            .report-header img { height:40px !important; margin-bottom:2px !important; }
            .report-title { font-size:13px !important; }
            .report-header div { font-size:10px !important; line-height:1.5 !important; }

            .section { padding:4px 8px !important; }
            .section-title { font-size:11px !important; padding-bottom:2px !important; margin-bottom:4px !important; }

            .grid-2 { display:grid !important; grid-template-columns:1fr 1fr !important; gap:8px !important; }

            table.data { font-size:9px !important; table-layout:auto !important; width:100% !important; border-collapse:collapse; }
            table.data th, table.data td { padding:3px 2px !important; font-size:9px !important; border:1px solid #ccc !important; }
            .inp { font-size:9px !important; padding:1px 2px !important; width:100% !important; border:none !important; background:transparent !important; }

            .signatures { padding:6px 8px !important; gap:8px !important; }
            .sig-line { margin-top:20px !important; font-size:9px !important; }
            .sig-check { font-size:8px !important; }

            @page { size:A4 portrait; margin:8mm; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar no-print">
        <h1><?= t('فرم گزارش') ?> <?= t('sal_daily') ?> <?= t('اجرا') ?> <?= ($has_night_shift || $active_shift === 'night') ? ($active_shift === 'night' ? '🌙 ' . t('شیفت شب') : '☀️ ' . t('شیفت روز')) : '' ?></h1>
        <a href="javascript:window.close()"><?= t('jdp_close') ?></a>
    </div>

    <!-- منوی انتخاب تاریخ -->
    <div class="date-nav no-print">
        <a href="?page=report_print&date=<?= date('Y-m-d', strtotime($report_date_g . ' -1 day')) ?><?= $qp_extra ?>" style="text-decoration:none;color:var(--text);padding:5px 8px;border:1px solid #ddd;border-radius:6px;font-size:12px">◀ <?= t('wh_prev_day') ?></a>
        <strong style="font-size:12px"><?= t('انتخاب') ?> <?= t('wh_date') ?>:</strong>
        <select id="selYear">
            <?php for ($y = 1403; $y <= 1408; $y++): ?>
            <option value="<?= $y ?>" <?= $y == $sel_jy ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <select id="selMonth">
            <?php
            $month_names = [];
            for ($mi = 1; $mi <= 12; $mi++) $month_names[str_pad((string)$mi, 2, '0', STR_PAD_LEFT)] = jalali_month_name($mi);
            foreach ($month_names as $num => $name):
            ?>
            <option value="<?= $num ?>" <?= (int)$num == $sel_jm ? 'selected' : '' ?>><?= $name ?></option>
            <?php endforeach; ?>
        </select>
        <select id="selDay">
            <?php for ($d = 1; $d <= 31; $d++): ?>
            <option value="<?= $d ?>" <?= $d == $sel_jd ? 'selected' : '' ?>><?= $d ?></option>
            <?php endfor; ?>
        </select>
        <button onclick="goToDate()"><?= t('برو') ?></button>
        <a href="?page=report_print&date=<?= date('Y-m-d') ?><?= $qp_extra ?>" style="text-decoration:none;color:var(--primary);padding:5px 8px;border:1px solid var(--primary);border-radius:6px;font-size:12px;font-weight:600"><?= t('wh_today') ?></a>
        <a href="?page=report_print&date=<?= date('Y-m-d', strtotime($report_date_g . ' +1 day')) ?><?= $qp_extra ?>" style="text-decoration:none;color:var(--text);padding:5px 8px;border:1px solid #ddd;border-radius:6px;font-size:12px"><?= t('wh_next_day') ?> ▶</a>
    </div>

    <!-- تب‌های شیفت — §v9: هر دو تب همیشه (وقتی حضور یا گزارش هست) + شمارش گزارش‌های هر شیفت -->
    <?php
    $other_shift = $active_shift === 'day' ? 'night' : 'day';
    $show_tabs = (count($day_attendance) + count($night_attendance) > 0) || ($rep_counts['day'] + $rep_counts['night'] > 0);
    if ($show_tabs):
    ?>
    <div class="date-nav no-print" style="background:#f0f4ff;border-bottom:2px solid var(--primary)">
        <strong style="font-size:12px"><?= t('wh_shift') ?>:</strong>
        <a href="?page=report_print&date=<?= $report_date_g ?>&shift=day" style="text-decoration:none;padding:6px 16px;border-radius:6px;font-size:12px;font-weight:600;background:<?= $active_shift === 'day' ? 'var(--primary)' : 'var(--surface)' ?>;color:<?= $active_shift === 'day' ? 'white' : 'var(--text)' ?>;border:1px solid <?= $active_shift === 'day' ? 'var(--primary)' : 'var(--border)' ?>">☀️ <?= t('شیفت روز') ?> (<?= count($day_attendance) ?> <?= t('نفر') ?><?= $rep_counts['day'] ? '، ' . $rep_counts['day'] . ' ' . t('گزارش') : '' ?>) — 7 <?= t('صبح') ?> <?= t('الی') ?> 9 <?= t('wh_night') ?></a>
        <a href="?page=report_print&date=<?= $report_date_g ?>&shift=night" style="text-decoration:none;padding:6px 16px;border-radius:6px;font-size:12px;font-weight:600;background:<?= $active_shift === 'night' ? '#1e293b' : 'var(--surface)' ?>;color:<?= $active_shift === 'night' ? 'white' : 'var(--text)' ?>;border:1px solid <?= $active_shift === 'night' ? '#1e293b' : 'var(--border)' ?>">🌙 <?= t('شیفت شب') ?> (<?= count($night_attendance) ?> <?= t('نفر') ?><?= $rep_counts['night'] ? '، ' . $rep_counts['night'] . ' ' . t('گزارش') : '' ?>) — 9 <?= t('wh_night') ?> <?= t('الی') ?> 7 <?= t('صبح') ?></a>
    </div>
    <?php if ($rep_counts[$active_shift] === 0 && $rep_counts[$other_shift] > 0): ?>
    <div class="no-print" style="padding:8px 14px;background:#fef3c7;border-bottom:2px solid #f59e0b;font-size:12.5px;color:#92400e">
        ⚠️ <?= t('گزارش‌های') ?> <?= t('این') ?> <?= t('wh_day') ?> <?= t('در') ?> <b><?= $other_shift === 'night' ? '🌙 ' . t('شیفت شب') : '☀️ ' . t('شیفت روز') ?></b> <?= t('ثبت شده‌اند') ?> (<?= $rep_counts[$other_shift] ?> <?= t('گزارش') ?>) —
        <a href="?page=report_print&date=<?= $report_date_g ?>&shift=<?= $other_shift ?>" style="font-weight:700;color:#b45309"><?= t('نمایش') ?> <?= $other_shift === 'night' ? t('شیفت شب') : t('شیفت روز') ?> ←</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- هدر گزارش -->
    <div class="report-header">
        <!-- سمت راست: لوگو کارفرما -->
        <div class="report-col" style="text-align:right">
            <?php if (!empty($employer_logo) && file_exists(__DIR__ . '/../' . $employer_logo)): ?>
                <img src="<?= htmlspecialchars($employer_logo) ?>" alt="<?= htmlspecialchars($employer_name ?: t('کارفرما')) ?>" style="height:50px;margin-bottom:4px">
            <?php elseif (!empty($employer_name)): ?>
                <div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:4px"><?= htmlspecialchars($employer_name) ?></div>
            <?php endif; ?>
            <?php if (!empty($employer_name)): ?>
                <div style="font-size:11px"><?= t('کارفرما') ?>: <?= htmlspecialchars($employer_name) ?></div>
            <?php endif; ?>
            <div style="font-size:11px"><?= t('hse_report_number') ?>: __________</div>
        </div>

        <!-- وسط: عنوان -->
        <div class="report-col" style="text-align:center">
            <div class="report-title"><?= t('فرم گزارش') ?> <?= t('sal_daily') ?> <?= t('اجرا') ?> <?= ($has_night_shift || $active_shift === 'night') ? '<span style="font-size:11px;padding:2px 8px;border-radius:4px;background:' . ($active_shift === 'night' ? '#1e293b' : '#fef3c7') . ';color:' . ($active_shift === 'night' ? 'white' : '#92400e') . ';font-weight:600;vertical-align:middle;margin-right:6px">' . ($active_shift === 'night' ? t('شیفت شب') : t('شیفت روز')) . '</span>' : '' ?></div>
            <div style="font-size:11px;margin-top:6px;display:flex;justify-content:center;gap:10px;flex-wrap:wrap">
                <span>
                    <strong><?= t('wh_status') ?> <?= t('جوی:') ?></strong>
                    <select class="inp" style="width:auto;display:inline-block" id="weatherSel">
                        <option><?= t('آفتابی') ?></option><option><?= t('ابری') ?></option><option><?= t('بارانی') ?></option><option><?= t('برفی') ?></option><option><?= t('طوفانی') ?></option><option><?= t('مه‌آلود') ?></option>
                    </select>
                </span>
                <span>
                    <strong><?= t('wh_status') ?> <?= t('کارگاه') ?>:</strong>
                    <select class="inp" style="width:auto;display:inline-block">
                        <option><?= t('فعال') ?></option><option><?= t('نیمه') ?> <?= t('فعال') ?></option><option><?= t('غیر') ?> <?= t('فعال') ?></option>
                    </select>
                </span>
            </div>
            <div style="font-size:11px;margin-top:4px"><strong><?= t('wh_name') ?> <?= t('پروژه') ?>:</strong> <?= htmlspecialchars($ws_display_name) ?></div>
            <div style="font-size:11px;margin-top:2px;display:flex;gap:10px;justify-content:center">
                <span><strong><?= t('شماره قرارداد:') ?></strong> <input class="inp" style="width:80px"></span>
                <span><strong><?= t('wh_date') ?> <?= t('قرارداد:') ?></strong> <input class="inp" style="width:80px"></span>
            </div>
        </div>

        <!-- سمت چپ: لوگو شرکت + تاریخ -->
        <div class="report-col" style="text-align:left">
            <?php if (!empty($company_logo) && file_exists(__DIR__ . '/../' . $company_logo)): ?>
                <img src="<?= htmlspecialchars($company_logo) ?>" alt="<?= t('لوگو') ?>" style="height:50px;margin-bottom:4px">
            <?php endif; ?>
            <div style="font-size:13px;font-weight:600"><?= $report_date_j ?></div>
            <div style="font-size:11px;color:#666"><?= t('wh_day') ?> <?= $report_day_name ?></div>
            <div style="font-size:10px;color:#999;margin-top:2px"><?= t('تعداد') ?> <?= t('نیرو') ?>: <?= count($attendance) ?> <?= t('per_count') ?></div>
        </div>
    </div>

    <!-- جداول دو ستونه -->
    <div class="section">
        <div class="grid-2">
            <!-- نیروی انسانی -->
            <div>
                <div class="section-title"><?= t('نیروی انسانی') ?></div>
                <table class="data" style="table-layout:auto">
                    <thead><tr><th style="width:50%"><?= t('عنوان') ?></th><th style="width:20%"><?= t('تعداد') ?></th><th style="width:30%"><?= t('wh_hours') ?> <?= t('کارکرد') ?></th></tr></thead>
                    <tbody>
                        <?php
                        $total_eng = $counts['mohandes ejra'] + $counts['hse'] + $counts['anbar dar'] + $counts['modir projeh'] + $counts['sarparast'] + $counts['daftar fanni'] + $counts['employee other'];
                        $total_peymankar = $counts['sakht karan'] + $counts['ranande bil'] + $counts['ranande babkat'] + $counts['joskar'] + $counts['paymandkar other'];
                        $hr_items = [
                            ['karigar', t('sal_worker')],
                            ['sarkarigar', t('سرکارگر') ],
                            ['sakht karan', t('اسکلت کار') ],
                            ['joskar', t('جوشکار') ],
                            ['ranande bil', t('راننده بیل') ],
                            ['ranande babkat', t('راننده بابکت') ],
                            ['paymandkar other', t('سایر پیمانکار') ],
                        ];
                        ?>
                        <?php foreach ($hr_items as $item): ?>
                        <?php if ($counts[$item[0]] > 0 || in_array($item[0], ['karigar', 'sarkarigar'])): ?>
                        <tr>
                            <td class="right"><?= $item[1] ?></td>
                            <td><input class="inp" value="<?= $counts[$item[0]] ?>" readonly style="width:40px"></td>
                            <td><input class="inp" type="text"></td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <tr>
                            <td class="right" style="font-weight:600"><?= t('مهندسین') ?></td>
                            <td><input class="inp" value="<?= $total_eng ?>" readonly style="width:40px"></td>
                            <td><input class="inp" type="text"></td>
                        </tr>
                        <tr>
                            <td class="right"><?= t('rep_total') ?> <?= t('nav_subcontractors') ?></td>
                            <td><input class="inp" value="<?= $total_peymankar ?>" readonly style="width:40px"></td>
                            <td><input class="inp" type="text"></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ماشین آلات -->
            <div>
                <div class="section-title"><?= t('ماشین آلات و تجهیزات') ?></div>
                <table class="data">
                    <thead><tr><th><?= t('شرح') ?></th><th><?= t('موجود') ?></th><th><?= t('فعال') ?></th><th><?= t('wh_hours') ?> <?= t('کار') ?></th><th><?= t('wor_description') ?></th></tr></thead>
                    <tbody>
                        <?php foreach (['مینی بیل','برش بتن برقی','برش بتن گازوئیلی','برش بتن بنزینی','بابکت'] as $m): ?>
                        <tr>
                            <td class="right" style="font-size:9px"><?= t($m) ?></td>
                            <td><input class="inp"></td>
                            <td><input class="inp"></td>
                            <td><input class="inp"></td>
                            <td><input class="inp"></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php for ($i=0; $i<3; $i++): ?>
                        <tr>
                            <td><input class="inp txt-r"></td>
                            <td><input class="inp"></td>
                            <td><input class="inp"></td>
                            <td><input class="inp"></td>
                            <td><input class="inp"></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- توضیحات -->
    <div class="section">
        <div class="grid-2">
            <div>
                <div class="section-title"><?= t('wor_description') ?> <?= t('کارفرما') ?> (<?= t('موانع و مشکلات') ?> <?= t('پروژه') ?>)</div>
                <textarea class="inp txt-r" rows="2" style="width:100%;resize:none"></textarea>
            </div>
            <div>
                <div class="section-title"><?= t('موانع و مشکلات') ?></div>
                <textarea class="inp txt-r" rows="2" style="width:100%;resize:none"></textarea>
            </div>
        </div>
    </div>

    <!-- فعالیت‌ها -->
    <div class="section">
        <div class="section-title"><?= t('شرح') ?> <?= t('فعالیت') ?>‌<?= t('های') ?> <?= t('انجام') ?> <?= t('شده') ?> <?= t('در') ?> <?= t('wh_date') ?> <?= $report_date_j ?></div>
        <table class="data">
            <thead><tr><th style="width:5%"><?= t('ردیف') ?></th><th style="width:50%"><?= t('شرح') ?> <?= t('فعالیت') ?></th><th style="width:15%"><?= t('درصد پیشرفت') ?></th><th style="width:30%"><?= t('پیش‌بینی') ?> <?= t('فعالیت') ?> <?= t('wh_next_day') ?></th></tr></thead>
            <tbody>
                <?php foreach ($activities as $i => $act): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="right" style="font-size:10px"><?= htmlspecialchars($act['description']) ?></td>
                    <td><input class="inp" value="<?= htmlspecialchars($act['progress']) ?>"></td>
                    <td><input class="inp" value="<?= htmlspecialchars($act['next_day']) ?>"></td>
                </tr>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < max(3, 8 - count($activities)); $i++): ?>
                <tr>
                    <td><?= count($activities) + $i + 1 ?></td>
                    <td><input class="inp txt-r"></td>
                    <td><input class="inp"></td>
                    <td><input class="inp"></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <!-- امضاها -->
    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line"><?= t('واحد اجرا') ?></div>
            <?php if (!empty($ejra_engineers)): ?>
                <div class="sig-check done" style="font-size:10px;line-height:1.5">
                    <?= t('btn_confirm') ?>:<br>
                    <?php foreach ($ejra_engineers as $eng): ?>
                    <?= htmlspecialchars($eng) ?><br>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!empty($approvals[1])): ?>
                <div class="sig-check done"><?= t('btn_confirm') ?>: <?= $approvals[1]['user_name'] ?></div>
            <?php else: ?>
                <div class="sig-check pending"><?= t('منتظر') ?> <?= t('btn_confirm') ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box">
            <div class="sig-line"><?= t('hse_sign_supervisor') ?></div>
            <?php if (!empty($approvals[2])): ?>
                <div class="sig-check done"><?= t('btn_confirm') ?>: <?= $approvals[2]['user_name'] ?></div>
            <?php else: ?>
                <div class="sig-check pending"><?= t('منتظر') ?> <?= t('btn_confirm') ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box">
            <div class="sig-line"><?= t('ریاست') ?> <?= t('کارگاه') ?></div>
            <?php if (!empty($approvals[3])): ?>
                <div class="sig-check done"><?= t('btn_confirm') ?>: <?= $approvals[3]['user_name'] ?></div>
            <?php else: ?>
                <div class="sig-check pending"><?= t('منتظر') ?> <?= t('btn_confirm') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$print_mode): ?>
    <div class="action-bar no-print">
        <a href="?page=report_print&date=<?= $report_date_g ?>&print=1<?= $qp_extra ?>" class="btn btn-print" target="_blank"><?= t('btn_print') ?> A4</a>

        <?php
        // مرحله 1 (اجرا): مهندسین اجرا که گزارش دادن = تایید خودکار
        if (empty($approvals[1]) && !empty($ejra_engineers)) {
            $folder = __DIR__ . '/../uploads/reports/';
            $file_approve = $folder . 'daily_' . $report_date_g . '_' . $ws_id . '.json';
            $adata = file_exists($file_approve) ? json_decode(file_get_contents($file_approve), true) ?: [] : [];
            $adata['approvals'][1] = [
                'user_id' => 0,
                'user_name' => implode(', ', $ejra_engineers),
                'note' => 'تایید خودکار - مهندسین اجرا',
                'date' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($file_approve, json_encode($adata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $approvals[1] = $adata['approvals'][1];
        }

        // پیدا کردن مرحله بعدی
        $next_step = 0;
        for ($s = 1; $s <= 3; $s++) {
            if (empty($approvals[$s])) { $next_step = $s; break; }
        }

        // §v7: محدودیت‌های امضا — نام مدیر توی خانه‌های واحد ثبت نمی‌شه
        $step_block_msg = '';
        if ($next_step == 1 && ($is_admin_page || $is_yaser_page)) {
            $step_block_msg = t('امضای «واحد اجرا» فقط با نام مهندسین/نیروهای واحد اجرا انجام می‌شود (با ثبت گزارش توسط آن‌ها به‌صورت خودکار تایید می‌شود)');
            $next_step = 0;
        } elseif ($next_step == 2 && !$is_admin_page) {
            $step_block_msg = t('مرحله «سرپرست کارگاه پیمانکار» فقط توسط مدیر قابل تایید است');
            $next_step = 0;
        } elseif ($next_step == 3 && !$is_yaser_page) {
            $step_block_msg = t('مرحله «ریاست کارگاه» فقط توسط yaser قابل تایید است');
            $next_step = 0;
        }

        if ($next_step > 0):
        ?>
        <form method="POST" style="display:inline;display:flex;gap:6px;align-items:center"><?= get_csrf_field() ?>
            <input type="hidden" name="approve_step" value="<?= $next_step ?>">
            <input type="text" name="approve_note" placeholder="<?= t('wor_description') ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:11px;width:160px">
            <button type="submit" class="btn btn-approve"><?= t('btn_confirm') ?>: <?= $step_labels[$next_step - 1] ?></button>
        </form>
        <?php endif; ?>

        <?php if ($next_step === 0 && empty($approvals[3]) && $step_block_msg !== ''): ?>
        <div style="padding:6px 12px;background:#fefce8;color:#a16207;border-radius:6px;font-size:11px"><?= $step_block_msg ?></div>
        <?php endif; ?>

        <?php if (!empty($approvals[3])): ?>
        <div style="padding:6px 12px;background:#f0fdf4;color:#16a34a;border-radius:6px;font-weight:600;font-size:12px"><?= t('همه') ?> <?= t('مراحل') ?> <?= t('btn_confirm') ?> <?= t('شد') ?> ✓</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!$print_mode): ?>
<script>
function goToDate() {
    var y = document.getElementById('selYear').value;
    var m = document.getElementById('selMonth').value;
    var d = document.getElementById('selDay').value;
    // تبدیل شمسی <?= t('به') ?> میلادی
    var jy = parseInt(y), jm = parseInt(m), jd = parseInt(d);
    var gy, gm, gd;
    var j_days_in_month = [31,31,31,31,31,31,30,30,30,30,30,29];
    var g_days_in_month = [31,28,31,30,31,30,31,31,30,31,30,31];

    var jy2 = jy - 979, jm2 = jm - 1, jd2 = jd - 1;
    var j_day_no = 365*jy2 + Math.floor(jy2/33)*8 + Math.floor((jy2%33+3)/4);
    for (var i = 0; i < jm2; i++) j_day_no += j_days_in_month[i];
    j_day_no += jd2;

    var g_day_no = j_day_no + 79;
    var gy2 = 1600 + 400*Math.floor(g_day_no/146097);
    g_day_no %= 146097;
    if (g_day_no >= 36525) { g_day_no--; gy2 += 100*Math.floor(g_day_no/36524); g_day_no %= 36524; if (g_day_no >= 365) g_day_no++; }
    gy2 += 4*Math.floor(g_day_no/1461); g_day_no %= 1461;
    if (g_day_no >= 366) { g_day_no--; gy2 += Math.floor(g_day_no/365); g_day_no %= 365; }
    var leap = (gy2%4==0 && gy2%100!=0) || (gy2%400==0);
    for (var i = 0; i < 12; i++) {
        var dim = g_days_in_month[i] + (i==1 && leap ? 1 : 0);
        if (g_day_no < dim) break;
        g_day_no -= dim;
    }
    gm2 = i + 1;
    gd2 = g_day_no + 1;

    var gDate = gy2 + '-' + String(gm2).padStart(2,'0') + '-' + String(gd2).padStart(2,'0');
    window.location.href = '?page=report_print&date=' + gDate + '<?= $qp_extra ?>';
}
</script>
<?php endif; ?>

<?php if ($print_mode): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>

<?php if ($is_yaser): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input.inp, textarea.inp, select.inp').forEach(function(el) {
        el.setAttribute('readonly', 'readonly');
        el.style.pointerEvents = 'none';
    });
});
</script>
<?php endif; ?>
<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
