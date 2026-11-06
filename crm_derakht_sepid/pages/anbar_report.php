<?php
/**
 * فرم گزارش کار انباردار (اسناد §12) — صفحه مستقل A4
 * فقط لوگوی شرکت؛ داده‌های روز از انبار خودکار پر می‌شود
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
$can_sign_keeper = $is_admin || has_permission('manage_inventory');

// ─── انتخاب ماه/روز شمسی ───
$cur_j = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));
$jy = (int)($_GET['jy'] ?? $cur_j[0]);
$jm = (int)($_GET['jm'] ?? $cur_j[1]);
$jd = (int)($_GET['jd'] ?? $cur_j[2]);
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    [$jy, $jm, $jd] = array_map('intval', explode('/', to_jalali($_GET['date'])));
}
$g = jalali_to_gregorian($jy, $jm, $jd);
$report_date_g = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
$report_date_j = to_jalali($report_date_g);
$day_names = localized_weekday_names();
$report_day_name = $day_names[date('w', strtotime($report_date_g))];
$print_mode = isset($_GET['print']);

$company_logo = $_SESSION['workshop_logo'] ?? '';
if (!$company_logo || !file_exists(__DIR__ . '/../' . $company_logo)) {
    $company_logo = 'uploads/logos/logo.png';
}

// ─── ذخیره فیلدهای دستی ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fields' && !$is_javidhse && !$is_yaser) {
    $stmt = $pdo->prepare("INSERT INTO anbar_reports (workshop_id, report_date, keeper_name, project_name, report_number, description, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE keeper_name = VALUES(keeper_name), project_name = VALUES(project_name), report_number = VALUES(report_number), description = VALUES(description), notes = VALUES(notes)");
    $stmt->execute([
        $ws_id, $report_date_g,
        sanitize($_POST['keeper_name'] ?? ''),
        sanitize($_POST['project_name'] ?? ''),
        sanitize($_POST['report_number'] ?? ''),
        sanitize($_POST['description'] ?? ''),
        sanitize($_POST['notes'] ?? ''),
        $current_user_id,
    ]);
    header("Location: index.php?page=anbar_report&date=$report_date_g");
    exit;
}

// ─── ذخیره تایید (دو امضا) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_step'])) {
    $step = (int)$_POST['approve_step'];
    $user_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? '';
    $allowed = false;
    if ($step === 1) $allowed = $can_sign_keeper;   // انباردار
    if ($step === 2) $allowed = $is_admin;          // سرپرست کارگاه

    if ($allowed) {
        $folder = __DIR__ . '/../uploads/reports/';
        if (!is_dir($folder)) mkdir($folder, 0777, true);
        $file = $folder . 'anbar_' . $report_date_g . '_' . $ws_id . '.json';
        $data = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $data['approvals'][$step] = [
            'user_id' => $current_user_id,
            'user_name' => $user_name,
            'note' => trim($_POST['approve_note'] ?? ''),
            'date' => date('Y-m-d H:i:s'),
        ];
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    header("Location: index.php?page=anbar_report&date=$report_date_g");
    exit;
}

// ─── بارگذاری تاییدها ───
$approvals = [1 => null, 2 => null];
$file = __DIR__ . '/../uploads/reports/anbar_' . $report_date_g . '_' . $ws_id . '.json';
if (file_exists($file)) {
    $saved = json_decode(file_get_contents($file), true) ?: [];
    $approvals = $saved['approvals'] ?? $approvals;
}

// ─── فیلدهای دستی + تکرار خودکار نام انباردار/پروژه ───
$st = $pdo->prepare("SELECT * FROM anbar_reports WHERE workshop_id = ? AND report_date = ?");
$st->execute([$ws_id, $report_date_g]);
$form = $st->fetch() ?: [];

$keeper_name = $form['keeper_name'] ?? '';
if ($keeper_name === '') {
    $st = $pdo->prepare("SELECT keeper_name FROM anbar_reports WHERE workshop_id = ? AND keeper_name IS NOT NULL AND keeper_name != '' ORDER BY report_date DESC LIMIT 1");
    $st->execute([$ws_id]);
    $keeper_name = (string)$st->fetchColumn() ?: ($_SESSION['full_name'] ?? '');
}
$project_name = $form['project_name'] ?? '';
if ($project_name === '') {
    $st = $pdo->prepare("SELECT project_name FROM anbar_reports WHERE workshop_id = ? AND project_name IS NOT NULL AND project_name != '' ORDER BY report_date DESC LIMIT 1");
    $st->execute([$ws_id]);
    $project_name = (string)$st->fetchColumn() ?: ($_SESSION['workshop_name'] ?? '');
}
$report_number = $form['report_number'] ?? '';
$description = $form['description'] ?? '';
$notes = $form['notes'] ?? '';

// ═══ داده‌های خودکار روز از انبار (§12.3) ═══
$day_txs = [];
$st = $pdo->prepare("SELECT t.*, i.name AS item_name, i.unit, u.full_name AS creator_name FROM inventory_transactions t JOIN inventory_items i ON t.item_id = i.id LEFT JOIN users u ON t.created_by = u.id WHERE t.workshop_id = ? AND (t.checkout_date = ? OR (t.return_date = ? AND t.return_date IS NOT NULL)) ORDER BY t.id");
$st->execute([$ws_id, $report_date_g, $report_date_g]);
$day_txs = $st->fetchAll();

$sum_in = 0; $sum_out = 0; $sum_to_staff = 0; $sum_returned = 0; $sum_broken = 0; $sum_from_repair = 0;
$rows_in = []; $rows_out = []; $rows_repair = [];
foreach ($day_txs as $t) {
    // خروج امروز: هر تراکنش با تاریخ خروج امروز (حتی اگر بعداً برگشت خورده)
    $is_today_out = ($t['checkout_date'] === $report_date_g) && $t['type'] !== 'restock';
    // برگشت امروز
    $is_today_ret = ($t['return_date'] === $report_date_g && $t['type'] === 'return');

    if ($t['type'] === 'restock' && $t['checkout_date'] === $report_date_g) {
        $sum_in += (float)$t['quantity'];
        $rows_in[] = $t;
    }
    if ($is_today_out) {
        $sum_out += (float)$t['quantity'];
        if (!$t['is_consumable'] && !$t['repair_subcontractor_id']) $sum_to_staff += (float)$t['quantity'];
        $rows_out[] = $t;
        if ($t['repair_subcontractor_id']) {
            $rows_repair[] = ['row' => $t, 'status' => ($t['type'] === 'return' ? t('ارسال به تعمیرکار (برگشته)') : t('ارسال به تعمیرکار')), 'kind' => 'send'];
        }
    }
    if ($is_today_ret) {
        if (($t['return_condition'] ?? '') === 'repaired') {
            $sum_from_repair += (float)$t['quantity'];
            $rows_repair[] = ['row' => $t, 'status' => t('برگشت از تعمیر (سالم)'), 'kind' => 'fixed'];
        } else {
            $sum_returned += (float)$t['quantity'];
            if (($t['return_condition'] ?? '') === 'repair') {
                $sum_broken += (float)$t['quantity'];
                $rows_repair[] = ['row' => $t, 'status' => t('برگشت معیوب — نیازمند تعمیر'), 'kind' => 'broken'];
            }
        }
    }
}

// هشدار کمبود: آماده تحویل ≤ حداقل
$st = $pdo->prepare("SELECT name, quantity, min_quantity, unit FROM inventory_items WHERE workshop_id = ? AND status = 'active' AND min_quantity > 0 AND quantity <= min_quantity ORDER BY name");
$st->execute([$ws_id]);
$low_items = $st->fetchAll();

// ═══ گزارش‌های روزانه واحد انبار (§v6 — مثل فرم HSE؛ قبلاً فقط در فرم اجرا می‌آمدند) ═══
$anbar_acts = [];
$dr_has_status = db_has_column($pdo, 'daily_reports', 'status');
$sql = "SELECT dr.wo_description, dr.report_text, u.full_name FROM daily_reports dr JOIN users u ON u.id = dr.user_id WHERE dr.workshop_id = ? AND dr.report_date = ? AND dr.category = 'anbar'";
if ($dr_has_status) $sql .= " AND dr.status = 'approved'";
$sql .= " ORDER BY dr.id";
$st = $pdo->prepare($sql);
try { $st->execute([$ws_id, $report_date_g]); foreach ($st->fetchAll() as $r) {
    $txt = trim((string)($r['wo_description'] ?: $r['report_text']));
    if ($txt !== '') $anbar_acts[] = ['text' => $txt, 'by' => $r['full_name']];
} } catch (Throwable $e) { /* جدول/ستون category هنوز موجود نیست */ }

// مرحله بعدی
$next_step = 0;
for ($s = 1; $s <= 2; $s++) { if (empty($approvals[$s])) { $next_step = $s; break; } }
if ($next_step === 1 && !$can_sign_keeper) $next_step = 0;
if ($next_step === 2 && !$is_admin) $next_step = 0;

$prev_d = date('Y-m-d', strtotime($report_date_g . ' -1 day'));
$next_d = date('Y-m-d', strtotime($report_date_g . ' +1 day'));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('فرم') ?> <?= t('nav_daily_reports') ?> <?= t('anbar_keeper') ?> — <?= $report_date_j ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:<?= $lang_dir ?>; background:#eef1f6; color:#1a1a2e; }
        .a4 { max-width:210mm; margin:18px auto; background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,0.09); padding:20px 24px; }
        .doc-header { display:flex; justify-content:space-between; align-items:center; border-bottom:3px double #333; padding-bottom:12px; gap:14px; }
        .doc-header img { height:58px; }
        .doc-title h1 { font-size:19px; font-weight:800; color:#7c2d12; }
        .doc-date { font-size:12.5px; text-align:left; line-height:1.9; }
        .inp { font-family:inherit; border:1px dashed #bbb; border-radius:6px; padding:2px 8px; font-size:12px; background:#fffef7; }
        .info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; font-size:12.5px; padding:12px 2px; border-bottom:1px solid #ddd; }
        .summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:8px; margin-top:14px; }
        .sum-card { border:1px solid #e2e8f0; border-radius:10px; padding:9px 10px; text-align:center; background:#fafaf9; }
        .sum-card .v { font-size:17px; font-weight:800; color:#7c2d12; }
        .sum-card .l { font-size:10.5px; color:#666; margin-top:3px; }
        .sec-title { background:#7c2d12; color:#fff; font-size:13px; font-weight:700; padding:7px 12px; border-radius:8px 8px 0 0; margin-top:18px; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th, td { border:1px solid #d6d3d1; padding:6px 8px; text-align:center; }
        th { background:#f5f5f4; }
        .empty-row { color:#999; }
        .manual-area { width:100%; min-height:70px; border:none; resize:vertical; font-family:inherit; font-size:13px; line-height:1.9; background:transparent; }
        .manual-area:focus { outline:1px dashed #94a3b8; background:#fffef7; }
        .signatures { display:flex; justify-content:space-around; gap:16px; margin-top:26px; }
        .sig-box { flex:1; max-width:300px; border:1px solid #d6d3d1; border-radius:10px; padding:12px; text-align:center; min-height:105px; }
        .sig-line { font-weight:700; font-size:13px; border-bottom:1px solid #eee; padding-bottom:8px; margin-bottom:10px; }
        .sig-check.done { color:#15803d; font-weight:600; font-size:12px; }
        .sig-check.pending { color:#b45309; font-size:12px; }
        .toolbar { max-width:210mm; margin:14px auto; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-family:inherit; font-size:12.5px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
        .btn-save { background:#7c2d12; color:#fff; }
        .btn-print { background:#334155; color:#fff; }
        .btn-approve { background:#1d4ed8; color:#fff; }
        .btn-nav { background:#fff; color:#334155; border:1px solid #cbd5e1; }
        .flash-ok { background:#dcfce7; color:#15803d; padding:8px 14px; border-radius:8px; font-size:12px; font-weight:600; }
        .low-box { border:1px solid #fecaca; background:#fef2f2; border-radius:10px; padding:8px 12px; font-size:11.5px; margin-top:12px; }
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
    <a class="btn btn-nav" href="index.php?page=anbar_report&date=<?= $prev_d ?>">◀ <?= t('wh_prev_day') ?></a>
    <form method="GET" action="index.php" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="page" value="anbar_report">
        <select name="jy" class="inp"><?php for ($y = $cur_j[0] + 1; $y >= $cur_j[0] - 2; $y--): ?><option value="<?= $y ?>" <?= $y == $jy ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
        <select name="jm" class="inp"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m == $jm ? 'selected' : '' ?>><?= jalali_month_name($m) ?></option><?php endfor; ?></select>
        <select name="jd" class="inp"><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>" <?= $d == $jd ? 'selected' : '' ?>><?= $d ?></option><?php endfor; ?></select>
        <button class="btn btn-nav"><?= t('btn_show') ?></button>
    </form>
    <a class="btn btn-nav" href="index.php?page=anbar_report&date=<?= $next_d ?>"><?= t('wh_next_day') ?> ▶</a>
    <a class="btn btn-print" href="index.php?page=anbar_report&date=<?= $report_date_g ?>&print=1" target="_blank">🖨 <?= t('btn_print') ?> A4</a>
    <?php if (!$is_javidhse && !$is_yaser): ?>
    <button form="anbForm" type="submit" class="btn btn-save">💾 <?= t('btn_save') ?> <?= t('فیلدهای') ?> <?= t('دستی') ?></button>
    <?php endif; ?>
    <?php if (!empty($approvals[1]) && !empty($approvals[2])): ?>
    <span class="flash-ok"><?= t('هر') ?> <?= t('دو') ?> <?= t('امضا') ?> <?= t('ثبت') ?> <?= t('شد') ?> ✓</span>
    <?php endif; ?>
</div>

<form method="POST" id="anbForm" class="no-print"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_fields"></form>

<div class="a4">
    <!-- سربرگ: فقط لوگوی شرکت (§12.2) -->
    <div class="doc-header">
        <div>
            <?php if (file_exists(__DIR__ . '/../' . $company_logo)): ?>
            <img src="<?= $company_logo ?>" alt="<?= htmlspecialchars(t('لوگوی شرکت'), ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
        </div>
        <div class="doc-title"><h1><?= t('فرم') ?> <?= t('nav_daily_reports') ?> <?= t('anbar_keeper') ?></h1></div>
        <div class="doc-date">
            <div><strong><?= t('wh_date') ?> <?= t('گزارش') ?>:</strong> <?= $report_date_j ?></div>
            <div style="color:#666"><?= $report_day_name ?></div>
        </div>
    </div>

    <!-- اطلاعات کلی (§12.3) -->
    <div class="info-grid">
        <div><strong><?= t('wh_name') ?> <?= t('anbar_keeper') ?>:</strong>
            <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
            <input class="inp" style="width:130px" name="keeper_name" form="anbForm" value="<?= htmlspecialchars($keeper_name) ?>">
            <?php else: ?><?= htmlspecialchars($keeper_name) ?><?php endif; ?>
        </div>
        <div><strong><?= t('wh_name') ?> <?= t('پروژه') ?> / <?= t('کارگاه') ?>:</strong>
            <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
            <input class="inp" style="width:160px" name="project_name" form="anbForm" value="<?= htmlspecialchars($project_name) ?>">
            <?php else: ?><?= htmlspecialchars($project_name) ?><?php endif; ?>
        </div>
        <div><strong><?= t('hse_report_number') ?>:</strong>
            <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
            <input class="inp" style="width:70px" name="report_number" form="anbForm" value="<?= htmlspecialchars($report_number) ?>">
            <?php else: ?><?= htmlspecialchars($report_number) ?: '—' ?><?php endif; ?>
        </div>
    </div>

    <!-- خلاصه موجودی و عملیات روز -->
    <div class="summary-grid">
        <div class="sum-card"><div class="v"><?= $sum_in ?></div><div class="l"><?= t('btn_checkin') ?> <?= t('کالا') ?> <?= t('به') ?> <?= t('nav_inventory') ?></div></div>
        <div class="sum-card"><div class="v"><?= $sum_out ?></div><div class="l"><?= t('nav_logout') ?> <?= t('کالا') ?> <?= t('lea_from') ?> <?= t('nav_inventory') ?></div></div>
        <div class="sum-card"><div class="v"><?= $sum_to_staff ?></div><div class="l"><?= t('ابزار') ?> <?= t('inv_checkout') ?> <?= t('به') ?> <?= t('nav_personnel') ?></div></div>
        <div class="sum-card"><div class="v"><?= $sum_returned ?></div><div class="l"><?= t('ابزار') ?> <?= t('برگشت') ?> <?= t('lea_from') ?> <?= t('nav_personnel') ?></div></div>
        <div class="sum-card" style="background:#fdf4ff"><div class="v" style="color:#7e22ce"><?= $sum_broken ?></div><div class="l"><?= t('اعلام خرابی / نیاز به') ?> <?= t('تعمیر') ?></div></div>
        <div class="sum-card" style="background:#f0fdf4"><div class="v" style="color:#15803d"><?= $sum_from_repair ?></div><div class="l"><?= t('برگشت') ?> <?= t('lea_from') ?> <?= t('تعمیر') ?></div></div>
        <div class="sum-card" style="background:#fef2f2"><div class="v"><?= count($low_items) ?></div><div class="l"><?= t('warning') ?> <?= t('کمبود') ?> <?= t('inv_stock') ?></div></div>
    </div>

    <?php if (!empty($low_items)): ?>
    <div class="low-box">
        <strong>⚠️ <?= t('کالاهای در آستانه کمبود:') ?></strong>
        <?php foreach ($low_items as $li): ?>
        <span style="display:inline-block;margin:2px 4px;padding:2px 8px;background:#fff;border-radius:6px;border:1px solid #fecaca"><?= $li['name'] ?> (<?= (float)$li['quantity'] ?> <?= $li['unit'] ?>)</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- جدول ورود کالا -->
    <div class="sec-title"><?= t('جدول') ?> <?= t('btn_checkin') ?> <?= t('کالا') ?></div>
    <table>
        <thead><tr><th style="width:36px"><?= t('ردیف') ?></th><th><?= t('wh_name') ?> <?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('واحد') ?></th><th><?= t('ثبت‌کننده') ?></th><th><?= t('wor_description') ?></th></tr></thead>
        <tbody>
            <?php foreach ($rows_in as $ix => $t): ?>
            <tr><td><?= $ix + 1 ?></td><td><?= $t['item_name'] ?></td><td><?= (float)$t['quantity'] ?></td><td><?= $t['unit'] ?></td><td><?= $t['creator_name'] ?: '—' ?></td><td><?= $t['notes'] ?: '—' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($rows_in)): ?><tr><td colspan="6" class="empty-row"><?= t('ورودی') ?> <?= t('ثبت نشده') ?></td></tr><?php endif; ?>
        </tbody>
    </table>

    <!-- جدول خروج / تحویل کالا -->
    <div class="sec-title"><?= t('جدول') ?> <?= t('nav_logout') ?> / <?= t('inv_checkout') ?> <?= t('کالا') ?></div>
    <table>
        <thead><tr><th style="width:36px"><?= t('ردیف') ?></th><th><?= t('wh_name') ?> <?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('واحد') ?></th><th><?= t('inv_checkout') ?><?= t('‌گیرنده') ?></th><th><?= t('علت') ?> <?= t('nav_logout') ?></th><th><?= t('wor_description') ?></th></tr></thead>
        <tbody>
            <?php foreach ($rows_out as $ix => $t):
                $why = $t['is_consumable'] ? t('مصرفی') : ($t['repair_subcontractor_id'] ? t('ارسال به تعمیرکار') : t('تحویل به پرسنل'));
            ?>
            <tr><td><?= $ix + 1 ?></td><td><?= $t['item_name'] ?></td><td><?= (float)$t['quantity'] ?></td><td><?= $t['unit'] ?></td><td><?= $t['person_name'] ?></td><td><?= $why ?></td><td><?= $t['notes'] ?: '—' ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($rows_out)): ?><tr><td colspan="7" class="empty-row"><?= t('خروجی') ?> <?= t('ثبت نشده') ?></td></tr><?php endif; ?>
        </tbody>
    </table>

    <!-- جدول ابزارهای نیازمند تعمیر -->
    <div class="sec-title" style="background:#581c87"><?= t('جدول') ?> <?= t('ابزارهای') ?> <?= t('inv_repair') ?></div>
    <table>
        <thead><tr><th style="width:36px"><?= t('ردیف') ?></th><th><?= t('wh_name') ?> <?= t('ابزار') ?></th><th><?= t('تعداد') ?></th><th><?= t('گزارش‌دهنده خرابی') ?></th><th><?= t('شرح') ?></th><th><?= t('wh_status') ?></th></tr></thead>
        <tbody>
            <?php foreach ($rows_repair as $ix => $rr): ?>
            <tr><td><?= $ix + 1 ?></td><td><?= $rr['row']['item_name'] ?></td><td><?= (float)$rr['row']['quantity'] ?></td><td><?= $rr['row']['person_name'] ?: '—' ?></td><td><?= $rr['row']['notes'] ?: '—' ?></td><td><?= $rr['status'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($rows_repair)): ?><tr><td colspan="6" class="empty-row"><?= t('موردی') ?> <?= t('ثبت نشده') ?></td></tr><?php endif; ?>
        </tbody>
    </table>

    <!-- گزارش‌های روزانه واحد انبار (§v6) -->
    <div class="sec-title" style="background:#92400e"><?= t('nav_daily_reports') ?> <?= t('sal_daily') ?> <?= t('واحد') ?> <?= t('nav_inventory') ?></div>
    <table>
        <thead><tr><th style="width:36px"><?= t('ردیف') ?></th><th><?= t('شرح') ?> <?= t('گزارش') ?></th><th style="width:150px"><?= t('ثبت') ?><?= t('‌شده') ?> <?= t('توسط') ?></th></tr></thead>
        <tbody>
            <?php foreach ($anbar_acts as $ix => $a): ?>
            <tr><td><?= $ix + 1 ?></td><td style="text-align:right"><?= $a['text'] ?></td><td><?= $a['by'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($anbar_acts)): ?><tr><td colspan="3" class="empty-row"><?= t('گزارشی ثبت نشده') ?></td></tr><?php endif; ?>
        </tbody>
    </table>

    <!-- توضیحات -->
    <div class="sec-title" style="background:#44403c"><?= t('wor_description') ?></div>
    <table><tr><td style="text-align:right;vertical-align:top">
        <?php if (!$print_mode && !$is_javidhse && !$is_yaser): ?>
        <textarea class="manual-area" name="description" form="anbForm" placeholder="<?= t('wor_description') ?> <?= t('کلی') ?> <?= t('wh_day') ?>..."><?= htmlspecialchars($description) ?></textarea>
        <textarea class="manual-area" name="notes" form="anbForm" placeholder="<?= t('wor_description') ?> <?= t('تکمیلی') ?> <?= t('anbar_keeper') ?>..." style="border-top:1px dashed #ddd"><?= htmlspecialchars($notes) ?></textarea>
        <?php else: ?>
        <?= nl2br(htmlspecialchars($description)) ?: '' ?><?= $notes ? '<br>' . nl2br(htmlspecialchars($notes)) : '' ?>
        <?php if (!$description && !$notes): ?><span class="empty-row">—</span><?php endif; ?>
        <?php endif; ?>
    </td></tr></table>

    <!-- امضاها (§12.4) -->
    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line"><?= t('تأیید') ?> <?= t('anbar_keeper') ?></div>
            <?php if (!empty($approvals[1])): ?>
                <div class="sig-check done"><?= t('btn_confirm') ?>: <?= htmlspecialchars($approvals[1]['user_name']) ?></div>
            <?php else: ?>
                <div class="sig-check pending"><?= t('منتظر') ?> <?= t('btn_confirm') ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box">
            <div class="sig-line"><?= t('تأیید') ?> <?= t('anbar_sign_supervisor') ?></div>
            <?php if (!empty($approvals[2])): ?>
                <div class="sig-check done"><?= t('btn_confirm') ?>: <?= htmlspecialchars($approvals[2]['user_name']) ?></div>
            <?php else: ?>
                <div class="sig-check pending"><?= t('منتظر') ?> <?= t('btn_confirm') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$print_mode && $next_step > 0):
        $step_labels = [1 => t('تأیید انباردار'), 2 => t('تأیید سرپرست کارگاه')];
    ?>
    <form method="POST" class="no-print" style="display:flex;gap:8px;justify-content:center;margin-top:18px;align-items:center"><?= get_csrf_field() ?>
        <input type="hidden" name="approve_step" value="<?= $next_step ?>">
        <input type="text" name="approve_note" placeholder="<?= t('wor_description') ?> <?= t('امضا') ?> (<?= t('اختیاری') ?>)" class="inp" style="width:200px">
        <button type="submit" class="btn btn-approve">✍ <?= $step_labels[$next_step] ?></button>
    </form>
    <?php endif; ?>
</div>

<?php if ($print_mode): ?><script>window.onload = function(){ window.print(); }</script><?php endif; ?>
<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
