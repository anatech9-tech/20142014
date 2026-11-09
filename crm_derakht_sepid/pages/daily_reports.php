<?php
require_once __DIR__ . '/../includes/salary_calc.php';   // jalali_month_key / jalali_month_from_key
$pageTitle = t('nav_daily_reports');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$is_admin = is_strict_admin();
$is_nazer = is_nazer();
$can_manage = $is_admin || $is_nazer || has_permission('manage_personnel') || access_mode('daily_reports') === 'editor';
// §v11: ناظرِ گزارش روزانه = دیدن گزارش‌های همه (همه واحدها) بدون امکان تأیید/حذف
$can_view_all = $can_manage || access_mode('daily_reports') === 'viewer';
$current_user_id = $_SESSION['user_id'];

// واحد (فرم گزارش): مدیر می‌تونه بین فرم‌ها جابه‌جا شه؛ بقیه فقط فرم واحد خودشون
$user_team = current_user_team($pdo, $current_user_id);
$team_labels = team_labels();
$team_icons = ['ejra' => '🔧', 'hse' => '🛡️', 'anbar' => '📦'];
$active_cat = $_GET['cat'] ?? '';
if (!in_array($active_cat, ['ejra', 'hse', 'anbar'])) $active_cat = $can_view_all ? 'ejra' : $user_team;
if (!$can_view_all) $active_cat = $user_team;

// آدرس بازگشت با حفظ فیلترها (cat/user/date_from/date_to)
function dr_back_url() {
    $qs = [];
    foreach (['cat', 'user', 'date_from', 'date_to', 'm'] as $k) {
        if (!empty($_GET[$k])) $qs[] = $k . '=' . urlencode((string)$_GET[$k]);
    }
    return 'index.php?page=daily_reports' . ($qs ? '&' . implode('&', $qs) : '');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_report') {
        $rep_cat = $_POST['category'] ?? '';
        if (!in_array($rep_cat, ['ejra', 'hse', 'anbar'])) $rep_cat = $user_team;
        if (!$can_manage) $rep_cat = $user_team;
        $report_text = sanitize($_POST['report_text'] ?? '');
        $wo_desc = sanitize($_POST['wo_description'] ?? '');
        $wo_prog = sanitize($_POST['wo_progress'] ?? '');
        $wo_next = sanitize($_POST['wo_next_day'] ?? '');
        $raw_date = $_POST['report_date'] ?? jalali_now();
        if (strpos($raw_date, '/') !== false) {
            $report_date = to_gregorian($raw_date);
        } else {
            $report_date = $raw_date;
        }

        // If wo_desc is empty, use report_text
        if (empty($wo_desc) && !empty($report_text)) {
            $wo_desc = $report_text;
            $wo_prog = '';
            $wo_next = '';
        }

        $dr_has_shift = db_has_column($pdo, 'daily_reports', 'shift');
        $rep_shift = $dr_has_shift ? detect_report_shift($pdo, $ws_id, $current_user_id, $report_date) : 'day';

        if (!empty($wo_desc)) {
            $display_text = '📋 ' . $wo_desc;
            if ($wo_prog) $display_text .= "\n" . t('پیشرفت:') . ' ' . $wo_prog;
            if ($wo_next) $display_text .= "\n" . t('پیش‌بینی روز بعد:') . ' ' . $wo_next;
            if ($dr_has_shift) {
                $stmt = $pdo->prepare("INSERT INTO daily_reports (user_id, workshop_id, report_date, report_text, wo_description, wo_progress, wo_next_day, category, shift) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$current_user_id, $ws_id, $report_date, $display_text, $wo_desc, $wo_prog, $wo_next, $rep_cat, $rep_shift]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO daily_reports (user_id, workshop_id, report_date, report_text, wo_description, wo_progress, wo_next_day, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$current_user_id, $ws_id, $report_date, $display_text, $wo_desc, $wo_prog, $wo_next, $rep_cat]);
            }
            // §fix: گزارش نیاز به تأیید مدیر ندارد؛ بلافاصله نهایی می‌شود
            $__auto = daily_report_auto_approve($pdo, (int)$pdo->lastInsertId(), (int)$current_user_id);
            showToast(t('گزارش ثبت شد'));
        } elseif (!empty($report_text)) {
            if ($dr_has_shift) {
                $stmt = $pdo->prepare("INSERT INTO daily_reports (user_id, workshop_id, report_date, report_text, category, shift) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$current_user_id, $ws_id, $report_date, $report_text, $rep_cat, $rep_shift]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO daily_reports (user_id, workshop_id, report_date, report_text, category) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$current_user_id, $ws_id, $report_date, $report_text, $rep_cat]);
            }
            $__auto = daily_report_auto_approve($pdo, (int)$pdo->lastInsertId(), (int)$current_user_id);
            showToast(t('گزارش ثبت شد'));
        }
        redirect("index.php?page=daily_reports");
    }

    // حذف گزارش: مالکِ گزارش یا مدیر/ناظر.
    if ($action === 'delete_report') {
        $rid = (int)($_POST['report_id'] ?? 0);
        if ($rid) {
            $st = $pdo->prepare("SELECT user_id, status, source_item_id FROM daily_reports WHERE id = ? AND workshop_id = ?");
            $st->execute([$rid, $ws_id]); $old_report = $st->fetch();
            if ($old_report && ($can_manage || (int)$old_report['user_id'] === (int)$current_user_id)) {
                daily_report_reverse_coins($pdo, $rid, (int)$current_user_id, 'حذف گزارش');
                $stmt = $pdo->prepare("DELETE FROM daily_reports WHERE id = ? AND workshop_id = ?");
                $stmt->execute([$rid, $ws_id]);
                showToast(t('گزارش حذف شد'));
            } else {
                showToast(t('دسترسی غیرمجاز'), 'error');
            }
        }
        redirect(dr_back_url());
    }

    // تأیید / رد گزارش: فقط گزارش تأییدشده در فرم رسمی می‌آید.
    if ($action === 'set_report_status' && $can_manage) {
        $rid = (int)($_POST['report_id'] ?? 0);
        $stt = $_POST['status'] ?? '';
        if ($rid && in_array($stt, ['pending', 'approved', 'rejected'], true)) {
            $st = $pdo->prepare("SELECT * FROM daily_reports WHERE id = ? AND workshop_id = ?");
            $st->execute([$rid, $ws_id]);
            $report_row = $st->fetch();
            if ($report_row) {
                $old_status = $report_row['status'] ?? 'pending';
                if ($stt === 'approved') {
                    if (db_has_column($pdo, 'daily_reports', 'approved_by')) {
                        $pdo->prepare("UPDATE daily_reports SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND workshop_id = ?")->execute([$current_user_id, $rid, $ws_id]);
                    } else {
                        $pdo->prepare("UPDATE daily_reports SET status = 'approved' WHERE id = ? AND workshop_id = ?")->execute([$rid, $ws_id]);
                    }
                    operations_system_chat_message($pdo, $ws_id, $current_user_id, strtr(t('✅ گزارش کار «{name}» تأیید شد'), ['{name}' => $report_row['full_name'] ?? t('نیرو')]));
                    showToast(t('گزارش تأیید شد و در فرم رسمی نمایش داده می‌شود'));
                } elseif ($stt === 'rejected') {
                    $pdo->prepare("UPDATE daily_reports SET status = 'rejected' WHERE id = ? AND workshop_id = ?")->execute([$rid, $ws_id]);
                    showToast(t('گزارش رد شد و از فرم رسمی حذف شد.'));
                } else {
                    $pdo->prepare("UPDATE daily_reports SET status = 'pending' WHERE id = ? AND workshop_id = ?")->execute([$rid, $ws_id]);
                    showToast(t('گزارش به حالت در انتظار بررسی برگشت.'));
                }
            }
        }
        redirect(dr_back_url());
    }

    // ویرایش آیتم گزارش (مالک یا مدیر/ناظر)
    if ($action === 'edit_report') {
        $rid = (int)($_POST['report_id'] ?? 0);
        $st = $pdo->prepare("SELECT user_id FROM daily_reports WHERE id = ? AND workshop_id = ?");
        $st->execute([$rid, $ws_id]);
        $row = $st->fetch();
        if ($row && ($can_manage || (int)$row['user_id'] === (int)$current_user_id)) {
            $new_text = sanitize($_POST['report_text'] ?? '');
            $new_desc = sanitize($_POST['wo_description'] ?? '');
            $new_prog = sanitize($_POST['wo_progress'] ?? '');
            $new_next = sanitize($_POST['wo_next_day'] ?? '');
            if ($new_text === '' && $new_desc === '') {
                showToast(t('متن گزارش خالی است'), 'error');
            } else {
                $pdo->prepare("UPDATE daily_reports SET report_text = ?, wo_description = ?, wo_progress = ?, wo_next_day = ? WHERE id = ?")
                    ->execute([$new_text, $new_desc, $new_prog, $new_next, $rid]);
                showToast(t('آیتم گزارش ویرایش شد'));
            }
        } else {
            showToast(t('دسترسی غیرمجاز'), 'error');
        }
        redirect(dr_back_url());
    }
}

// Get reports
$filter_user = (int)($_GET['user'] ?? 0);
$filter_date_from_raw = $_GET['date_from'] ?? '';
$filter_date_to_raw = $_GET['date_to'] ?? '';
$filter_date_from = '';
$filter_date_to = '';
if ($filter_date_from_raw) {
    $filter_date_from = (strpos($filter_date_from_raw, '/') !== false) ? to_gregorian($filter_date_from_raw) : $filter_date_from_raw;
}
if ($filter_date_to_raw) {
    $filter_date_to = (strpos($filter_date_to_raw, '/') !== false) ? to_gregorian($filter_date_to_raw) : $filter_date_to_raw;
}

// فهرست افراد برای فیلتر: مدیر/ناظر همه؛ اعضای تیم فقط هم‌تیمی‌های خودشان
$personnel = [];
$stmt2 = $pdo->prepare("SELECT u.id, u.full_name, u.work_role FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY u.full_name");
$stmt2->execute([$ws_id]);
foreach ($stmt2->fetchAll() as $__pp) {
    if ($can_view_all || team_from_work_role($__pp['work_role'] ?? '') === $active_cat) $personnel[] = $__pp;
}
$personnel_ids = array_map('intval', array_column($personnel, 'id'));
// عضو تیم فقط می‌تواند روی هم‌تیمی‌ها (یا خودش) فیلتر کند
if (!$can_view_all && $filter_user && $filter_user !== (int)$current_user_id && !in_array($filter_user, $personnel_ids, true)) $filter_user = 0;

// §fix: همهٔ اعضای یک تیم گزارش‌های همان تیم (همان کارگاه) را می‌بینند تا گزارش تکراری ثبت نکنند.
$where = "dr.workshop_id = ? AND dr.category = ?";
$params = [$ws_id, $active_cat];
if ($filter_user) { $where .= " AND dr.user_id = ?"; $params[] = $filter_user; }
if ($filter_date_from) { $where .= " AND dr.report_date >= ?"; $params[] = $filter_date_from; }
if ($filter_date_to) { $where .= " AND dr.report_date <= ?"; $params[] = $filter_date_to; }

// چیپ‌های ماه: تعداد گزارش هر ماه شمسی (با همان فیلترها، بدون فیلتر ماه)
$month_counts = [];
$stc = $pdo->prepare("SELECT dr.report_date AS d, COUNT(*) AS c FROM daily_reports dr WHERE $where GROUP BY dr.report_date");
$stc->execute($params);
foreach ($stc->fetchAll() as $__mc) {
    if (empty($__mc['d']) || strpos((string)$__mc['d'], '0000') === 0) continue;
    $__k = jalali_month_key((string)$__mc['d']);
    $month_counts[$__k] = ($month_counts[$__k] ?? 0) + (int)$__mc['c'];
}
uksort($month_counts, function ($a, $b) {
    [$ay, $am] = array_map('intval', explode('-', $a)); [$by, $bm] = array_map('intval', explode('-', $b));
    return $ay !== $by ? $by - $ay : $bm - $am;
});
$sel_month = (string)($_GET['m'] ?? '');
if (!preg_match('/^\d{4}-\d{1,2}$/', $sel_month) || (int)substr($sel_month, 5) < 1 || (int)substr($sel_month, 5) > 12) $sel_month = '';
if ($sel_month !== '') {
    $__mi = jalali_month_from_key($sel_month);
    $where .= " AND dr.report_date BETWEEN ? AND ?"; $params[] = $__mi['start']; $params[] = $__mi['end'];
}
$stmt = $pdo->prepare("SELECT dr.*, u.full_name FROM daily_reports dr JOIN users u ON dr.user_id = u.id WHERE $where ORDER BY dr.report_date DESC, dr.created_at DESC, u.full_name LIMIT 1500");
$stmt->execute($params);
$reports = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
.dr-months { display:flex; flex-wrap:wrap; gap:8px; }
.dr-chip { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border:1px solid var(--border); border-radius:99px; font-size:12.5px; color:inherit; text-decoration:none; background:var(--surface); }
.dr-chip span { background:rgba(148,163,184,.25); border-radius:99px; padding:0 7px; font-size:11px; font-weight:700; }
.dr-chip:hover { border-color:var(--primary); color:var(--primary); }
.dr-chip.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.dr-chip.active span { background:rgba(255,255,255,.25); }
.dr-month-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px; margin:18px 0 10px; border-radius:10px; background:rgba(99,102,241,.10); border:1px solid rgba(99,102,241,.25); cursor:pointer; user-select:none; }
.dr-month-head h3 { margin:0; font-size:15px; }
.dr-month-head .dr-arrow { display:inline-block; transition:transform .15s; margin-left:6px; }
.dr-month-head.collapsed .dr-arrow { transform:rotate(90deg); }
.dr-month-head small { color:var(--text-secondary); font-size:12px; }
</style>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= $pageTitle ?></h2>
</div>

<!-- تب‌های فرم گزارش سه واحد -->
<div class="tabs mb-4 animate-in" style="display:flex;gap:8px;flex-wrap:wrap">
    <?php
    $tab_list = ['ejra', 'hse', 'anbar'];
    $q_extra = '';
    if ($filter_user) $q_extra .= '&user=' . $filter_user;
    if ($sel_month !== '') $q_extra .= '&m=' . urlencode($sel_month);
    if ($filter_date_from_raw) $q_extra .= '&date_from=' . urlencode($filter_date_from_raw);
    if ($filter_date_to_raw) $q_extra .= '&date_to=' . urlencode($filter_date_to_raw);
    foreach ($tab_list as $tb):
        if (!$can_manage && $tb !== $user_team) continue;
    ?>
    <a href="index.php?page=daily_reports&cat=<?= $tb ?><?= $q_extra ?>"
       class="btn <?= $active_cat === $tb ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
       style="<?= $active_cat === $tb ? '' : 'border:1px solid var(--border)' ?>">
        <?= $team_icons[$tb] ?> <?= t('فرم') ?> <?= $team_labels[$tb] ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Filter -->
<div class="card mb-4 animate-in">
    <form method="GET" class="d-flex gap-2 align-center flex-wrap">
        <input type="hidden" name="page" value="daily_reports">
        <input type="hidden" name="cat" value="<?= $active_cat ?>">
        <input type="hidden" name="m" value="<?= htmlspecialchars($sel_month, ENT_QUOTES, 'UTF-8') ?>">
        <?php if (!empty($personnel)): ?>
        <select name="user" class="form-control" style="width:180px" onchange="this.form.submit()">
            <option value=""><?= $can_view_all ? t('همه نیروها') : t('همهٔ هم‌تیمی‌ها') ?></option>
            <?php foreach ($personnel as $p): ?>
            <option value="<?= $p['id'] ?>" <?= ($filter_user == $p['id']) ? 'selected' : '' ?>><?= $p['full_name'] ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="text" name="date_from" class="form-control jalali-date" placeholder="<?= t('lea_from') ?> <?= t('wh_date') ?>" value="<?= $filter_date_from ?>" style="width:120px">
        <input type="text" name="date_to" class="form-control jalali-date" placeholder="<?= t('lea_to') ?> <?= t('wh_date') ?>" value="<?= $filter_date_to ?>" style="width:120px">
        <button type="submit" class="btn btn-primary btn-sm"><?= t('btn_filter') ?></button>
        <a href="index.php?page=daily_reports&cat=<?= $active_cat ?>" class="btn btn-ghost btn-sm"><?= t('پاک‌سازی') ?></a>
        <div class="me-auto"></div>
        <button onclick="printReports()" class="btn btn-ghost btn-sm"><i data-lucide="printer"></i> <?= t('btn_print') ?></button>
    </form>
</div>

<!-- ماه‌ها -->
<?php if (!empty($month_counts)):
    $__mq = 'index.php?page=daily_reports&cat=' . $active_cat . ($filter_user ? '&user=' . $filter_user : '')
          . ($filter_date_from_raw ? '&date_from=' . urlencode($filter_date_from_raw) : '') . ($filter_date_to_raw ? '&date_to=' . urlencode($filter_date_to_raw) : '');
?>
<div class="dr-months mb-3 animate-in">
    <a href="<?= htmlspecialchars($__mq, ENT_QUOTES, 'UTF-8') ?>" class="dr-chip <?= $sel_month === '' ? 'active' : '' ?>"><?= t('همهٔ ماه‌ها') ?> <span><?= array_sum($month_counts) ?></span></a>
    <?php foreach ($month_counts as $__k => $__c): $__mi2 = jalali_month_from_key($__k); ?>
    <a href="<?= htmlspecialchars($__mq . '&m=' . $__k, ENT_QUOTES, 'UTF-8') ?>" class="dr-chip <?= $sel_month === $__k ? 'active' : '' ?>"><?= htmlspecialchars($__mi2['label'], ENT_QUOTES, 'UTF-8') ?> <span><?= $__c ?></span></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Reports List -->
<div id="reportsArea">
<?php if (empty($reports)): ?>
<div class="card animate-in">
    <p class="text-center text-muted" style="padding:40px"><?= t('گزارشی') ?> <?= t('در') ?> <?= t('فرم') ?> «<?= $team_labels[$active_cat] ?>» <?= t('ثبت نشده') ?></p>
</div>
<?php else: ?>
<?php
// گروه‌بندی: ماه شمسی ← روز
$by_month = [];
foreach ($reports as $r) {
    $jd = to_jalali($r['report_date']);
    if (!$jd || strpos($jd, '0000') !== false || strpos($jd, '-') !== false) {
        $jd = jalali_now();
    }
    $mk = (!empty($r['report_date']) && strpos((string)$r['report_date'], '0000') !== 0) ? jalali_month_key((string)$r['report_date']) : jalali_month_key(date('Y-m-d'));
    $by_month[$mk][$jd][] = $r;
}
foreach ($by_month as $mk => $grouped):
    $mk_info = jalali_month_from_key($mk);
    $mk_total = 0; foreach ($grouped as $__dd) $mk_total += count($__dd);
?>
<div class="dr-month-head" data-m="<?= htmlspecialchars($mk, ENT_QUOTES, 'UTF-8') ?>" onclick="toggleDrMonth('<?= htmlspecialchars($mk, ENT_QUOTES, 'UTF-8') ?>')">
    <h3><span class="dr-arrow">▾</span> 📅 <?= htmlspecialchars($mk_info['label'], ENT_QUOTES, 'UTF-8') ?></h3>
    <small><?= $mk_total ?> <?= t('گزارش') ?> • <?= count($grouped) ?> <?= t('روز') ?></small>
</div>
<div class="dr-month-body" data-m="<?= htmlspecialchars($mk, ENT_QUOTES, 'UTF-8') ?>">
<?php
foreach ($grouped as $date_label => $day_reports):
?>
<div class="card mb-3 animate-in">
    <div class="card-header">
        <h3 class="card-title" style="font-size:14px"><?= $date_label ?></h3>
        <span class="text-muted" style="font-size:12px"><?= count($day_reports) ?> <?= t('گزارش') ?></span>
    </div>
    <?php foreach ($day_reports as $r):
        $r_status = $r['status'] ?? 'pending';
        $r_shift = $r['shift'] ?? 'day';
        $is_rejected = ($r_status === 'rejected');
        $rejected_title = htmlspecialchars(t('این آیتم رد شده و در گزارش چاپی نمی‌آید'), ENT_QUOTES, 'UTF-8');
    ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border);<?= $is_rejected ? 'opacity:.55;' : '' ?>" <?= $is_rejected ? 'title="' . $rejected_title . '"' : '' ?>>
        <div class="d-flex justify-between align-center">
            <div class="d-flex align-center gap-2" style="flex-wrap:wrap">
                <div class="person-avatar" style="width:28px;height:28px;font-size:11px"><?= mb_substr($r['full_name'], 0, 1) ?></div>
                <strong style="font-size:13px"><?= $r['full_name'] ?></strong>
                <span style="background:<?= $r_shift === 'night' ? '#1e293b;color:#e2e8f0' : '#fef3c7;color:#92400e' ?>;padding:2px 8px;border-radius:10px;font-size:10px"><?= $r_shift === 'night' ? '🌙 ' . t('شیفت شب') : '☀️ ' . t('شیفت روز') ?></span>
                <?php if ($r_status === 'approved'): ?>
                    <span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:10px"><?= t('lea_approved') ?></span>
                <?php elseif ($is_rejected): ?>
                    <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:10px"><?= t('adv_rejected') ?> (<?= t('در') ?> <?= t('btn_print') ?> <?= t('نمی‌آید') ?>)</span>
                <?php else: ?>
                    <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:10px"><?= t('در انتظار') ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex align-center gap-2" style="flex-wrap:wrap">
                <span class="text-muted" style="font-size:11px"><?= substr($r['created_at'], 11, 5) ?></span>
                <?php if ($can_manage || $r['user_id'] == $current_user_id): ?>
                <button type="button" class="btn btn-ghost btn-sm" style="font-size:11px;padding:2px 8px;color:#0ea5e9"
                    onclick='openReportEdit(<?= $r['id'] ?>, <?= js_arg($r['report_text'] ?? '') ?>, <?= js_arg($r['wo_description'] ?? '') ?>, <?= js_arg($r['wo_progress'] ?? '') ?>, <?= js_arg($r['wo_next_day'] ?? '') ?>)'>✏️ <?= t('btn_edit') ?></button>
                <?php endif; ?>
                <?php if ($can_manage): ?>
                    <?php if ($is_rejected): ?>
                    <form method="POST" style="display:inline"><?= get_csrf_field() ?>
                        <input type="hidden" name="action" value="set_report_status">
                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="status" value="pending">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:#16a34a;font-size:11px;padding:2px 6px" title="<?= t('برگرداندن') ?> <?= t('برای') ?> <?= t('بررسی') ?> <?= t('مجدد') ?>">↩ <?= t('برگرداندن') ?></button>
                    </form>
                    <?php else: ?>
                    <?php if ($r_status !== 'approved'): ?>
                    <form method="POST" style="display:inline"><?= get_csrf_field() ?>
                        <input type="hidden" name="action" value="set_report_status">
                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="status" value="approved">
                        <button type="submit" class="btn btn-success btn-sm" style="font-size:11px;padding:2px 7px" title="<?= t('تأیید') ?> <?= t('گزارش') ?>">✓ <?= t('تأیید') ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('این') ?> <?= t('آیتم') ?> <?= t('btn_reject') ?> <?= t('شود') ?> <?= t('و') ?> <?= t('lea_from') ?> <?= t('گزارش') ?> <?= t('رسمی') ?> <?= t('btn_delete') ?> <?= t('گردد') ?>؟')"><?= get_csrf_field() ?>
                        <input type="hidden" name="action" value="set_report_status">
                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="status" value="rejected">
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:#f59e0b;font-size:11px;padding:2px 6px" title="<?= t('btn_reject') ?> <?= t('آیتم') ?> (<?= t('btn_delete') ?> <?= t('lea_from') ?> <?= t('گزارش') ?> <?= t('چاپی') ?>)">🚫 <?= t('btn_reject') ?></button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($can_manage || $r['user_id'] == $current_user_id): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('chat_delete') ?>')"><?= get_csrf_field() ?>
                    <input type="hidden" name="action" value="delete_report">
                    <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:11px;padding:2px 6px">✕</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($r['wo_description'])): ?>
        <table class="wo-items-table" style="margin-top:8px">
            <thead><tr><th><?= t('شرح') ?> <?= t('فعالیت') ?></th><th style="width:70px"><?= t('پیشرفت') ?></th><th><?= t('پیش‌بینی') ?> <?= t('wh_next_day') ?></th></tr></thead>
            <tbody>
            <?php
            $descs = array_filter(array_map('trim', explode("\n", $r['wo_description'])));
            $progs = array_filter(array_map('trim', explode("\n", $r['wo_progress'] ?? '')));
            $nexts = array_filter(array_map('trim', explode("\n", $r['wo_next_day'] ?? '')));
            $max = max(count($descs), count($progs), count($nexts));
            for ($i = 0; $i < $max; $i++):
            ?>
            <tr>
                <td style="text-align:right;font-size:12px;font-weight:500"><?= htmlspecialchars($descs[$i] ?? '') ?></td>
                <td style="text-align:center;font-size:11px;font-weight:600;color:#16a34a"><?= htmlspecialchars($progs[$i] ?? '-') ?></td>
                <td style="text-align:right;font-size:11px"><?= htmlspecialchars($nexts[$i] ?? '-') ?></td>
            </tr>
            <?php endfor; ?>
            </tbody>
        </table>
        <?php elseif (!empty($r['report_text'])): ?>
        <p style="margin:8px 0 0;font-size:13px;line-height:1.7;white-space:pre-wrap"><?= nl2br(htmlspecialchars($r['report_text'])) ?></p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
</div><!-- /dr-month-body -->
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- مودال ویرایش آیتم گزارش -->
<div id="reportEditModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:520px">
        <div class="modal-header">
            <h3 class="modal-title">✏️ <?= t('btn_edit') ?> <?= t('آیتم') ?> <?= t('گزارش') ?></h3>
            <button onclick="closeModal('reportEditModal')" class="modal-close">✕</button>
        </div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit_report">
            <input type="hidden" name="report_id" id="edit_report_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('شرح') ?> <?= t('فعالیت') ?></label>
                    <input type="text" name="wo_description" id="edit_wo_description" class="form-control">
                </div>
                <div class="form-group" style="display:flex;gap:10px">
                    <div style="flex:0 0 120px">
                        <label class="form-label"><?= t('پیشرفت') ?></label>
                        <input type="text" name="wo_progress" id="edit_wo_progress" class="form-control" placeholder="%">
                    </div>
                    <div style="flex:1">
                        <label class="form-label"><?= t('پیش‌بینی') ?> <?= t('wh_next_day') ?></label>
                        <input type="text" name="wo_next_day" id="edit_wo_next_day" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('متن') ?> <?= t('نمایشی') ?> <?= t('گزارش') ?> (<?= t('آنچه در پرینت دیده می‌شود') ?>)</label>
                    <textarea name="report_text" id="edit_report_text" class="form-control" rows="4"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('reportEditModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= t('btn_save') ?> <?= t('btn_edit') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDrMonth(k) {
    var h = document.querySelector('.dr-month-head[data-m="' + k + '"]'), b = document.querySelector('.dr-month-body[data-m="' + k + '"]');
    if (!h || !b) return;
    var c = h.classList.toggle('collapsed'); b.style.display = c ? 'none' : '';
}
function openReportEdit(id, text, desc, prog, next) {
    document.getElementById('edit_report_id').value = id;
    document.getElementById('edit_report_text').value = text || '';
    document.getElementById('edit_wo_description').value = desc || '';
    document.getElementById('edit_wo_progress').value = prog || '';
    document.getElementById('edit_wo_next_day').value = next || '';
    openModal('reportEditModal');
}
// همگام‌سازی <?= t('خودکار') ?> <?= t('متن') ?> <?= t('نمایشی') ?> <?= t('با') ?> <?= t('فیلدهای') ?> ساختاری
(function() {
    var d = document.getElementById('edit_wo_description');
    var p = document.getElementById('edit_wo_progress');
    var n = document.getElementById('edit_wo_next_day');
    var t = document.getElementById('edit_report_text');
    function sync() {
        if (!d.value.trim()) return;
        t.value = '📋 ' + d.value.trim()
            + (p.value.trim() ? '\n<?= t('پیشرفت') ?>: ' + p.value.trim() : '')
            + (n.value.trim() ? '\n<?= t('پیش‌بینی روز بعد') ?>: ' + n.value.trim() : '');
    }
    [d, p, n].forEach(function(el) { el.addEventListener('input', sync); });
})();
</script>

<?php include 'includes/footer.php'; ?>
