<?php
/**
 * فرم گزارش روزانه واحد HSE (اسناد §10) — صفحه مستقل A4
 * کد مدرک: MHD06R02
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_login();

$ws_id = $_SESSION['workshop_id'] ?? 0;
$current_user_id = $_SESSION['user_id'] ?? 0;
$is_admin = is_strict_admin();
$is_javidhse = (($_SESSION['username'] ?? '') === 'javidhse');
$is_yaser = (($_SESSION['username'] ?? '') === 'yaser');

// ─── انتخاب ماه/روز شمسی ───
$cur_j = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));
$jy = (int)($_GET['jy'] ?? $cur_j[0]);
$jm = (int)($_GET['jm'] ?? $cur_j[1]);
$jd = (int)($_GET['jd'] ?? (($_GET['date'] ?? '') ? (int)explode('/', to_jalali($_GET['date']))[2] : $cur_j[2]));
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $jj = to_jalali($_GET['date']);
    [$jy, $jm, $jd] = array_map('intval', explode('/', $jj));
}
$g = jalali_to_gregorian($jy, $jm, $jd);
$report_date_g = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
$report_date_j = to_jalali($report_date_g);
$day_names = localized_weekday_names();
$report_day_name = $day_names[date('w', strtotime($report_date_g))];
$print_mode = isset($_GET['print']);

// §v7: فرم HSE حالا شیفت روز/شب داره (مثل فرم اجرا)
$hse_has_shift = db_has_column($pdo, 'hse_reports', 'shift');
$dr_has_shift  = db_has_column($pdo, 'daily_reports', 'shift');

// §v9: شمارش گزارش‌های HSE هر شیفت — پیش‌فرض نمایش = شیفتی که گزارش دارد
$rep_counts_hse = ['day' => 0, 'night' => 0];
{
    $_rc_sql = "SELECT " . ($dr_has_shift ? "COALESCE(NULLIF(dr.shift,''),'day')" : "'day'") . " AS s, COUNT(*) AS c FROM daily_reports dr WHERE dr.workshop_id = ? AND dr.report_date = ? AND dr.category = 'hse'";
    if (db_has_column($pdo, 'daily_reports', 'status')) $_rc_sql .= " AND dr.status = 'approved'";
    $_rc_sql .= " GROUP BY s";
    $_st = $pdo->prepare($_rc_sql);
    $_st->execute([$ws_id, $report_date_g]);
    foreach ($_st->fetchAll() as $_r) { if (isset($rep_counts_hse[$_r['s']])) $rep_counts_hse[$_r['s']] = (int)$_r['c']; }
}

$_req_shift = $_GET['shift'] ?? '';
if (in_array($_req_shift, ['day', 'night'], true)) {
    $active_shift = ($_req_shift === 'night' && $hse_has_shift) ? 'night' : 'day';
} elseif ($hse_has_shift && $rep_counts_hse['night'] > 0 && $rep_counts_hse['day'] === 0) {
    $active_shift = 'night'; // §v9: گزارش‌های قدیمی بیشتر شیفت شبان — خودکار همون شیفت باز شه
} else {
    $active_shift = 'day';
}
$shift_label_fa = $active_shift === 'night' ? t('شیفت شب') : t('شیفت روز');
// فایل امضاها: شیفت روز همون نام قدیمی (سازگاری)، شیفت شب پسوند _night
$hse_appr_name = 'hse_' . $report_date_g . '_' . $ws_id . ($active_shift === 'night' ? '_night' : '') . '.json';

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

// ─── ذخیره فیلدهای دستی (§10.5) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fields' && !$is_javidhse && !$is_yaser) {
    if ($hse_has_shift) {
        $stmt = $pdo->prepare("INSERT INTO hse_reports (workshop_id, report_date, shift, workshop_name, weather, report_number, work_fronts, unsafe_items, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE workshop_name = VALUES(workshop_name), weather = VALUES(weather), report_number = VALUES(report_number), work_fronts = VALUES(work_fronts), unsafe_items = VALUES(unsafe_items)");
        $stmt->execute([
            $ws_id, $report_date_g, $active_shift,
            sanitize($_POST['workshop_name'] ?? ''),
            sanitize($_POST['weather'] ?? ''),
            sanitize($_POST['report_number'] ?? ''),
            sanitize($_POST['work_fronts'] ?? ''),
            sanitize($_POST['unsafe_items'] ?? ''),
            $current_user_id,
        ]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO hse_reports (workshop_id, report_date, workshop_name, weather, report_number, work_fronts, unsafe_items, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE workshop_name = VALUES(workshop_name), weather = VALUES(weather), report_number = VALUES(report_number), work_fronts = VALUES(work_fronts), unsafe_items = VALUES(unsafe_items)");
        $stmt->execute([
            $ws_id, $report_date_g,
            sanitize($_POST['workshop_name'] ?? ''),
            sanitize($_POST['weather'] ?? ''),
            sanitize($_POST['report_number'] ?? ''),
            sanitize($_POST['work_fronts'] ?? ''),
            sanitize($_POST['unsafe_items'] ?? ''),
            $current_user_id,
        ]);
    }
    header("Location: index.php?page=hse_report&date=$report_date_g&shift=$active_shift");
    exit;
}

// ─── ذخیره تایید (همان الگوی گزارش روزانه) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_step'])) {
    $step = (int)$_POST['approve_step'];
    $user_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? '';

    // محدودیت‌های مرحله‌ای — §v7: نام مدیر توی خانه «واحد HSE» ثبت نمی‌شه
    $allowed = false;
    if ($step === 1) $allowed = !$is_javidhse && !$is_yaser && !$is_admin;                    // واحد HSE — فقط نیروهای واحد
    if ($step === 2) $allowed = $is_admin;                                                    // سرپرست کارگاه پیمانکار — فقط مدیر
    if ($step === 3) $allowed = $is_javidhse;                                                 // سرپرست HSE — فقط javidhse

    if ($allowed) {
        $folder = __DIR__ . '/../uploads/reports/';
        if (!is_dir($folder)) mkdir($folder, 0777, true);
        $file = $folder . $hse_appr_name;
        $data = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $data['approvals'][$step] = [
            'user_id' => $current_user_id,
            'user_name' => $user_name,
            'note' => trim($_POST['approve_note'] ?? ''),
            'date' => date('Y-m-d H:i:s'),
        ];
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    header("Location: index.php?page=hse_report&date=$report_date_g&shift=$active_shift");
    exit;
}

// ─── بارگذاری تاییدها ───
$approvals = [1 => null, 2 => null, 3 => null];
$file = __DIR__ . '/../uploads/reports/' . $hse_appr_name;
if (file_exists($file)) {
    $saved = json_decode(file_get_contents($file), true) ?: [];
    $approvals = $saved['approvals'] ?? $approvals;
}

// ─── فیلدهای دستی ذخیره‌شده + قانون تکرار خودکار کارگاه ───
if ($hse_has_shift) {
    $st = $pdo->prepare("SELECT * FROM hse_reports WHERE workshop_id = ? AND report_date = ? AND shift = ?");
    $st->execute([$ws_id, $report_date_g, $active_shift]);
} else {
    $st = $pdo->prepare("SELECT * FROM hse_reports WHERE workshop_id = ? AND report_date = ?");
    $st->execute([$ws_id, $report_date_g]);
}
$form = $st->fetch() ?: [];

// کارگاه: اگر برای این روز نیست، آخرین مقدار ثبت‌شده همین شیفت (§10.2)
$workshop_name = $form['workshop_name'] ?? '';
if ($workshop_name === '' || $workshop_name === null) {
    if ($hse_has_shift) {
        $st = $pdo->prepare("SELECT workshop_name FROM hse_reports WHERE workshop_id = ? AND shift = ? AND workshop_name IS NOT NULL AND workshop_name != '' ORDER BY report_date DESC LIMIT 1");
        $st->execute([$ws_id, $active_shift]);
    } else {
        $st = $pdo->prepare("SELECT workshop_name FROM hse_reports WHERE workshop_id = ? AND workshop_name IS NOT NULL AND workshop_name != '' ORDER BY report_date DESC LIMIT 1");
        $st->execute([$ws_id]);
    }
    $workshop_name = (string)$st->fetchColumn();
}
// وضعیت جوی: پیش‌فرض آخرین انتخاب همین شیفت
$weather = $form['weather'] ?? '';
if ($weather === '') {
    if ($hse_has_shift) {
        $st = $pdo->prepare("SELECT weather FROM hse_reports WHERE workshop_id = ? AND shift = ? AND weather IS NOT NULL AND weather != '' ORDER BY report_date DESC LIMIT 1");
        $st->execute([$ws_id, $active_shift]);
    } else {
        $st = $pdo->prepare("SELECT weather FROM hse_reports WHERE workshop_id = ? AND weather IS NOT NULL AND weather != '' ORDER BY report_date DESC LIMIT 1");
        $st->execute([$ws_id]);
    }
    $weather = (string)$st->fetchColumn() ?: 'آفتابی';
}
$report_number = $form['report_number'] ?? '';
$work_fronts = $form['work_fronts'] ?? '';
$unsafe_items = $form['unsafe_items'] ?? '';

// ─── تعداد نفرات حاضر (خودکار) — بر اساس شیفت: روز ۷ صبح تا ۹ شب / شب ۹ شب تا ۷ صبح ───
$st = $pdo->prepare("SELECT user_id, start_time FROM work_hours WHERE workshop_id = ? AND work_date = ?");
$st->execute([$ws_id, $report_date_g]);
$staff_ids = [];
foreach ($st->fetchAll() as $wh) {
    $h = (int)date('H', strtotime($wh['start_time']));
    $is_day_person = ($h >= 7 && $h < 21);
    if (($active_shift === 'day') === $is_day_person) $staff_ids[(int)$wh['user_id']] = true;
}
$staff_count = count($staff_ids);

// ─── فعالیت‌های انجام‌گرفته (خودکار از دستور کار/گزارش کار HSE — فقط همین شیفت) ───
$hse_acts = [];
$hse_reporters = [];
$dr_has_status = db_has_column($pdo, 'daily_reports', 'status');
$sql = "SELECT dr.wo_description, dr.report_text, u.full_name, u.role AS urole FROM daily_reports dr JOIN users u ON dr.user_id = u.id WHERE dr.workshop_id = ? AND dr.report_date = ? AND dr.category = 'hse'";
$args = [$ws_id, $report_date_g];
if ($dr_has_status) $sql .= " AND dr.status = 'approved'";
if ($dr_has_shift)  { $sql .= " AND (dr.shift IS NULL OR dr.shift = '' OR dr.shift = ?)"; $args[] = $active_shift; }
$sql .= " ORDER BY dr.id";
$st = $pdo->prepare($sql);
$st->execute($args);
foreach ($st->fetchAll() as $r) {
    $txt = trim((string)($r['wo_description'] ?: $r['report_text']));
    if ($txt !== '') $hse_acts[] = ['text' => $txt, 'by' => $r['full_name']];
    // §v7: نام مدیر توی لیست امضاکنندگان واحد HSE نمیاد
    if (($r['urole'] ?? '') !== 'admin') $hse_reporters[] = $r['full_name'];
}
$hse_reporters = array_unique($hse_reporters);

// ─── تایید خودکار مرحله ۱ وقتی گزارش HSE ثبت شده (مثل مهندسین اجرا در گزارش روزانه) ───
if (empty($approvals[1]) && !empty($hse_reporters)) {
    $adata = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $adata['approvals'][1] = [
        'user_id' => 0,
        'user_name' => implode('، ', $hse_reporters),
        'note' => 'تایید خودکار — ثبت‌کنندگان گزارش HSE',
        'date' => date('Y-m-d H:i:s'),
    ];
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
    file_put_contents($file, json_encode($adata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $approvals[1] = $adata['approvals'][1];
}

// مرحله بعدی قابل تایید برای کاربر فعلی
$next_step = 0;
for ($s = 1; $s <= 3; $s++) { if (empty($approvals[$s])) { $next_step = $s; break; } }
$step_block_msg = '';
if ($next_step === 1 && ($is_javidhse || $is_yaser || $is_admin)) {
    $step_block_msg = $is_admin
        ? t('امضای «واحد HSE» فقط با نام نیروهای واحد HSE انجام می‌شود (با ثبت گزارش توسط آن‌ها به‌صورت خودکار تایید می‌شود)')
        : t('امضای «واحد HSE» فقط توسط نیروهای داخلی واحد انجام می‌شود');
    $next_step = 0;
}
if ($next_step === 2 && !$is_admin) $next_step = 0;
if ($next_step === 3 && !$is_javidhse) $next_step = 0;

// روزهای قبل/بعد
$prev_d = date('Y-m-d', strtotime($report_date_g . ' -1 day'));
$next_d = date('Y-m-d', strtotime($report_date_g . ' +1 day'));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('فرم گزارش') ?> <?= t('sal_daily') ?> <?= t('hse_sign_keeper') ?> (<?= $shift_label_fa ?>) — <?= $report_date_j ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:<?= $lang_dir ?>; background:#eef1f6; color:#1a1a2e; }
        .a4 { max-width:210mm; margin:18px auto; background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,0.09); padding:20px 24px; }
        .doc-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px double #333; padding-bottom:12px; gap:14px; }
        .doc-header img { height:56px; }
        .doc-title { text-align:center; flex:1; }
        .doc-title h1 { font-size:19px; font-weight:800; color:#14532d; }
        .doc-sub { font-size:12px; margin-top:8px; }
        .doc-left { font-size:12px; line-height:2; text-align:left; min-width:170px; }
        .doc-left b { color:#14532d; }
        .inp { font-family:inherit; border:1px dashed #bbb; border-radius:6px; padding:2px 8px; font-size:12px; background:#fffef7; }
        .info-strip { display:flex; gap:18px; font-size:12px; padding:10px 4px; border-bottom:1px solid #ddd; flex-wrap:wrap; }
        .sec-title { background:#14532d; color:#fff; font-size:13px; font-weight:700; padding:7px 12px; border-radius:8px 8px 0 0; margin-top:16px; }
        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th, td { border:1px solid #c8ccd4; padding:7px 10px; }
        th { background:#f1f5f1; }
        .body-table td { min-height:90px; vertical-align:top; line-height:1.9; }
        .act-list { margin:0; padding:0; list-style:none; }
        .act-list li { padding:5px 2px; border-bottom:1px dotted #ddd; }
        .act-list li:last-child { border-bottom:none; }
        .by { color:#666; font-size:11px; }
        .manual-area { width:100%; min-height:90px; border:none; resize:vertical; font-family:inherit; font-size:13px; line-height:1.9; background:transparent; }
        .manual-area:focus { outline:1px dashed #94a3b8; background:#fffef7; }
        .signatures { display:flex; justify-content:space-between; gap:16px; margin-top:26px; }
        .sig-box { flex:1; border:1px solid #c8ccd4; border-radius:10px; padding:12px; text-align:center; min-height:110px; }
        .sig-line { font-weight:700; font-size:13px; border-bottom:1px solid #eee; padding-bottom:8px; margin-bottom:10px; }
        .sig-check.done { color:#15803d; font-weight:600; font-size:12px; line-height:1.8; }
        .sig-check.pending { color:#b45309; font-size:12px; }
        .toolbar { max-width:210mm; margin:14px auto; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-family:inherit; font-size:12.5px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
        .btn-save { background:#14532d; color:#fff; }
        .btn-print { background:#334155; color:#fff; }
        .btn-approve { background:#1d4ed8; color:#fff; }
        .btn-nav { background:#fff; color:#334155; border:1px solid #cbd5e1; }
        .flash-ok { background:#dcfce7; color:#15803d; padding:8px 14px; border-radius:8px; font-size:12px; font-weight:600; }
        @media print {
            * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
            body { background:#fff; }
            .a4 { box-shadow:none; margin:0; max-width:100%; border-radius:0; }
            .toolbar, .no-print { display:none !important; }
            input.inp, select.inp, textarea.manual-area { border:none; background:transparent; }
            @page { size:A4; margin:10mm; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <a class="btn btn-nav" href="index.php?page=forms">→ <?= t('nav_forms') ?></a>
    <a class="btn btn-nav" href="index.php?page=hse_report&date=<?= $prev_d ?>&shift=<?= $active_shift ?>">◀ <?= t('wh_prev_day') ?></a>
    <form method="GET" action="index.php" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="page" value="hse_report">
        <input type="hidden" name="shift" value="<?= $active_shift ?>">
        <select name="jy" class="inp"><?php for ($y = $cur_j[0] + 1; $y >= $cur_j[0] - 2; $y--): ?><option value="<?= $y ?>" <?= $y == $jy ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
        <select name="jm" class="inp"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m == $jm ? 'selected' : '' ?>><?= jalali_month_name($m) ?></option><?php endfor; ?></select>
        <select name="jd" class="inp"><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>" <?= $d == $jd ? 'selected' : '' ?>><?= $d ?></option><?php endfor; ?></select>
        <button class="btn btn-nav"><?= t('btn_show') ?></button>
    </form>
    <a class="btn btn-nav" href="index.php?page=hse_report&date=<?= $next_d ?>&shift=<?= $active_shift ?>"><?= t('wh_next_day') ?> ▶</a>
    <a class="btn btn-print" href="index.php?page=hse_report&date=<?= $report_date_g ?>&shift=<?= $active_shift ?>&print=1" target="_blank">🖨 <?= t('btn_print') ?> A4</a>
    <?php if (!$is_javidhse && !$is_yaser): ?>
    <button form="hseForm" type="submit" class="btn btn-save">💾 <?= t('btn_save') ?> <?= t('فیلدهای') ?> <?= t('دستی') ?></button>
    <?php endif; ?>
    <?php if (!empty($approvals[1]) && !empty($approvals[2]) && !empty($approvals[3])): ?>
    <span class="flash-ok"><?= t('هر') ?> ۳ <?= t('امضا') ?> <?= t('ثبت') ?> <?= t('شد') ?> ✓</span>
    <?php endif; ?>
</div>

<?php if ($hse_has_shift): ?>
<div class="toolbar no-print" style="margin-top:0;padding-top:0">
    <strong style="font-size:13px"><?= t('wh_shift') ?>:</strong>
    <a class="btn <?= $active_shift === 'day' ? 'btn-save' : 'btn-nav' ?>" href="index.php?page=hse_report&date=<?= $report_date_g ?>&shift=day">☀️ <?= t('شیفت روز') ?><?= $rep_counts_hse['day'] ? ' (' . $rep_counts_hse['day'] . ' ' . t('گزارش') . ')' : '' ?></a>
    <a class="btn <?= $active_shift === 'night' ? 'btn-save' : 'btn-nav' ?>" <?= $active_shift === 'night' ? 'style="background:#1e293b"' : '' ?> href="index.php?page=hse_report&date=<?= $report_date_g ?>&shift=night">🌙 <?= t('شیفت شب') ?><?= $rep_counts_hse['night'] ? ' (' . $rep_counts_hse['night'] . ' ' . t('گزارش') . ')' : '' ?></a>
</div>
<?php
$other_shift = $active_shift === 'day' ? 'night' : 'day';
if ($rep_counts_hse[$active_shift] === 0 && $rep_counts_hse[$other_shift] > 0): ?>
<div class="no-print" style="max-width:210mm;margin:0 auto 10px;padding:8px 14px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;font-size:12.5px;color:#92400e">
    ⚠️ <?= t('گزارش‌های') ?> HSE <?= t('این') ?> <?= t('wh_day') ?> <?= t('در') ?> <b><?= $other_shift === 'night' ? '🌙 ' . t('شیفت شب') : '☀️ ' . t('شیفت روز') ?></b> <?= t('ثبت شده‌اند') ?> (<?= $rep_counts_hse[$other_shift] ?> <?= t('گزارش') ?>) —
    <a href="index.php?page=hse_report&date=<?= $report_date_g ?>&shift=<?= $other_shift ?>" style="font-weight:700;color:#b45309"><?= t('btn_show') ?> ←</a>
</div>
<?php endif; ?>
<?php endif; ?>

<form method="POST" id="hseForm" class="no-print"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_fields"></form>

<div class="a4">
    <!-- سربرگ (§10.2) -->
    <div class="doc-header">
        <div style="text-align:right">
            <?php if (!empty($employer_logo) && file_exists(__DIR__ . '/../' . $employer_logo)): ?>
                <img src="<?= htmlspecialchars($employer_logo) ?>" alt="<?= htmlspecialchars($employer_name ?: t('کارفرما')) ?>" style="height:48px;margin-bottom:4px">
            <?php elseif (!empty($employer_name)): ?>
                <div style="font-size:18px;font-weight:800;color:#14532d;margin-bottom:4px"><?= htmlspecialchars($employer_name) ?></div>
            <?php endif; ?>
            <?php if (!empty($employer_name)): ?>
                <div style="font-size:11px"><?= t('کارفرما') ?>: <?= htmlspecialchars($employer_name) ?></div>
            <?php endif; ?>
            <div style="font-size:11px;margin-top:5px"><?= t('وبسایت') ?> <?= t('hse_sign_keeper') ?></div>
        </div>

        <div class="doc-title">
            <h1><?= t('فرم گزارش') ?> <?= t('sal_daily') ?> <?= t('hse_sign_keeper') ?>
                <span style="font-size:11px;padding:2px 10px;border-radius:6px;background:<?= $active_shift === 'night' ? '#1e293b' : '#fef3c7' ?>;color:<?= $active_shift === 'night' ? '#fff' : '#92400e' ?>;vertical-align:middle"><?= $active_shift === 'night' ? '🌙 ' . t('شیفت شب') : '☀️ ' . t('شیفت روز') ?></span>
            </h1>
            <div class="doc-sub">
                <strong><?= t('wh_status') ?> <?= t('جوی:') ?></strong>
                <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
                <select class="inp" name="weather" form="hseForm">
                    <?php foreach (['آفتابی','ابری','بارانی','برفی','طوفانی','مه‌آلود'] as $w): ?>
                    <option <?= $weather === $w ? 'selected' : '' ?>><?= t($w) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?><?= t($weather) ?><?php endif; ?>
            </div>
        </div>

        <div class="doc-left">
            <div><?= t('کد') ?> <?= t('مدرک') ?>: <b>MHD06R02</b></div>
            <div><?= t('wh_date') ?>: <b><?= $report_date_j ?></b> (<?= $report_day_name ?>)</div>
            <div><?= t('wh_shift') ?>: <b><?= $shift_label_fa ?></b></div>
            <div><?= t('hse_report_number') ?>:
                <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
                <input class="inp" style="width:70px" name="report_number" form="hseForm" value="<?= htmlspecialchars($report_number) ?>">
                <?php else: ?><?= htmlspecialchars($report_number) ?: '—' ?><?php endif; ?>
            </div>
            <div><?= t('تعداد') ?> <?= t('نفرات:') ?> <b><?= $staff_count ?></b> <?= t('per_count') ?></div>
        </div>
    </div>

    <div class="info-strip">
        <span><strong><?= t('کارگاه') ?>:</strong>
        <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
            <input class="inp" style="width:180px" name="workshop_name" form="hseForm" value="<?= htmlspecialchars($workshop_name) ?>" placeholder="<?= t('نام کارگاه') ?>">
        <?php else: ?><?= htmlspecialchars($workshop_name) ?: '—' ?><?php endif; ?>
        </span>
    </div>

    <!-- جدول ۱: جبهه‌های کاری فعال -->
    <div class="sec-title"><?= t('جبهه‌های کاری فعال') ?></div>
    <table class="body-table"><tr><td>
        <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
        <textarea class="manual-area" name="work_fronts" form="hseForm" placeholder="<?= t('جبهه‌های کاری فعال امروز را بنویسید...') ?>"><?= htmlspecialchars($work_fronts) ?></textarea>
        <?php else: ?>
        <?= nl2br(htmlspecialchars($work_fronts)) ?: '<span style="color:#999">—</span>' ?>
        <?php endif; ?>
    </td></tr></table>

    <!-- جدول ۲: اعمال و شرایط ناایمن -->
    <div class="sec-title" style="background:#7f1d1d"><?= t('اهم') ?> <?= t('اعمال') ?> <?= t('و') ?> <?= t('شرایط ناایمن') ?> <?= t('مشاهده') ?> <?= t('گردیده') ?></div>
    <table class="body-table"><tr><td>
        <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
        <textarea class="manual-area" name="unsafe_items" form="hseForm" placeholder="<?= t('موارد') ?> <?= t('ناایمن') ?> <?= t('مشاهده') ?>‌<?= t('شده') ?>..."><?= htmlspecialchars($unsafe_items) ?></textarea>
        <?php else: ?>
        <?= nl2br(htmlspecialchars($unsafe_items)) ?: '<span style="color:#999">—</span>' ?>
        <?php endif; ?>
    </td></tr></table>

    <!-- جدول ۳: فعالیت‌های انجام‌گرفته (خودکار) -->
    <div class="sec-title" style="background:#1e3a8a"><?= t('فعالیت') ?>‌<?= t('های') ?> <?= t('انجام') ?> <?= t('گرفته') ?></div>
    <table class="body-table"><tr><td>
        <?php if (empty($hse_acts)): ?>
            <span style="color:#999"><?= t('گزارشی') ?> <?= t('lea_from') ?> <?= t('hse_sign_keeper') ?> <?= t('برای') ?> <?= t('این') ?> <?= t('wh_day') ?> <?= t('ثبت نشده') ?> <?= t('است') ?></span>
        <?php else: ?>
        <ul class="act-list">
            <?php foreach ($hse_acts as $a): ?>
            <li>• <?= nl2br(htmlspecialchars($a['text'])) ?> <span class="by">(<?= t('ثبت') ?><?= t('‌شده') ?> <?= t('توسط:') ?> <?= htmlspecialchars($a['by']) ?>)</span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </td></tr></table>

    <!-- امضاها (§10.4) -->
    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line"><?= t('hse_sign_keeper') ?></div>
            <?php if (!empty($approvals[1])): ?>
                <div class="sig-check done"><?= t('btn_confirm') ?>: <?= htmlspecialchars($approvals[1]['user_name']) ?></div>
            <?php else: ?>
                <div class="sig-check pending"><?= t('منتظر') ?> <?= t('btn_confirm') ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box">
            <div class="sig-line"><?= t('تأیید') ?> <?= t('hse_sign_supervisor') ?></div>
            <?php if (!empty($approvals[2])): ?>
                <div class="sig-check done"><?= t('btn_confirm') ?>: <?= htmlspecialchars($approvals[2]['user_name']) ?></div>
            <?php else: ?>
                <div class="sig-check pending"><?= t('منتظر') ?> <?= t('btn_confirm') ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box">
            <div class="sig-line"><?= t('hse_sign_hse_sup') ?></div>
            <?php if (!empty($approvals[3])): ?>
                <div class="sig-check done"><?= t('btn_confirm') ?>: <?= htmlspecialchars($approvals[3]['user_name']) ?></div>
            <?php else: ?>
                <div class="sig-check pending"><?= t('منتظر') ?> <?= t('btn_confirm') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$print_mode && $next_step > 0):
        $step_labels = [1 => t('hse_sign_keeper'), 2 => t('تأیید سرپرست کارگاه پیمانکار'), 3 => t('hse_sign_hse_sup')];
    ?>
    <form method="POST" class="no-print" style="display:flex;gap:8px;justify-content:center;margin-top:18px;align-items:center"><?= get_csrf_field() ?>
        <input type="hidden" name="approve_step" value="<?= $next_step ?>">
        <input type="text" name="approve_note" placeholder="<?= t('wor_description') ?> <?= t('امضا') ?> (<?= t('اختیاری') ?>)" class="inp" style="width:200px">
        <button type="submit" class="btn btn-approve">✍ <?= t('btn_confirm') ?>: <?= $step_labels[$next_step] ?></button>
    </form>
    <?php endif; ?>
    <?php if (!$print_mode && $next_step === 0 && (empty($approvals[1]) || empty($approvals[2]) || empty($approvals[3]))): ?>
        <?php if ($step_block_msg !== '' && empty($approvals[1])): ?>
        <div class="no-print" style="text-align:center;margin-top:14px;color:#b45309;font-size:12px"><?= $step_block_msg ?></div>
        <?php elseif (empty($approvals[2]) && !$is_admin && !empty($approvals[1])): ?>
        <div class="no-print" style="text-align:center;margin-top:14px;color:#b45309;font-size:12px"><?= t('مرحله') ?> «<?= t('تأیید') ?> <?= t('hse_sign_supervisor') ?>» <?= t('فقط') ?> <?= t('توسط') ?> <?= t('مدیر') ?> <?= t('قابل') ?> <?= t('انجام') ?> <?= t('است') ?></div>
        <?php elseif (!empty($approvals[2]) && empty($approvals[3]) && !$is_javidhse): ?>
        <div class="no-print" style="text-align:center;margin-top:14px;color:#b45309;font-size:12px"><?= t('مرحله') ?> «<?= t('hse_sign_hse_sup') ?>» <?= t('فقط') ?> <?= t('توسط') ?> <?= t('اکانت') ?> javidhse <?= t('قابل') ?> <?= t('btn_confirm') ?> <?= t('است') ?></div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($print_mode): ?><script>window.onload = function(){ window.print(); }</script><?php endif; ?>
<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
