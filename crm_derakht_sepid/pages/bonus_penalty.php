<?php
require_once __DIR__ . '/../includes/salary_calc.php';
$pageTitle = t('nav_bonus_penalty');
$ws_id = $_SESSION['workshop_id'] ?? 0;

// Load settings (needed for hourly rate calculation)
$settings = get_workshop_settings($pdo, $ws_id);
$is_admin = is_strict_admin();
$is_pm = is_project_manager();
$is_wm = is_workshop_manager();
$can_manage_all = $is_admin || $is_pm;
$can_create = $can_manage_all || $is_wm || has_permission('manage_bonus_penalty') || has_permission('office_manager');

// §v15: ستون عکس مستندات — در اولین اجرا به‌صورت خودکار اضافه می‌شود (یک‌باره)
try {
    $stc = $pdo->query("SHOW COLUMNS FROM bonuses_penalties LIKE 'evidence_image'");
    if (!$stc->fetch()) {
        $pdo->exec("ALTER TABLE bonuses_penalties ADD COLUMN evidence_image VARCHAR(255) NULL");
    }
} catch (Throwable $e) { }

/** بارگذاری و فشرده‌سازی عکس مستندات — برای صرفه‌جویی در فضای سایت
 *  عکس به حداکثر ۱۰۰۰ پیکسل و کیفیت ۷۲ فشرده می‌شود (اگر GD روی سرور باشد). */
function bp_save_evidence(array $file): ?string {
    if (empty($file['name'])) return null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('ارسال عکس ناموفق بود (خطای آپلود)');
    }
    if ($file['size'] > 8 * 1024 * 1024) throw new RuntimeException('حجم عکس نباید بیشتر از ۸ مگابایت باشد');
    $info = @getimagesize($file['tmp_name']);
    if (!$info) throw new RuntimeException('فایل انتخاب‌شده یک عکس معتبر نیست');
    if (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('فقط عکس‌های JPG / PNG / WebP مجاز است');
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']];
    $dir = __DIR__ . '/../uploads/bonus_evidence/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $fname = 'bp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fname)) throw new RuntimeException('ذخیرهٔ عکس ناموفق بود');
    if (function_exists('imagejpeg')) compress_image($dir . $fname, 1000, 72);
    return 'uploads/bonus_evidence/' . $fname;
}

/** حذف فایل عکس مستندات (فقط از پوشهٔ مجاز) */
function bp_delete_evidence(?string $rel_path): void {
    if ($rel_path && strpos($rel_path, 'uploads/bonus_evidence/') === 0) {
        @unlink(__DIR__ . '/../' . $rel_path);
    }
}

// نرخ ساعتی مؤثر در یک تاریخ برای یک پرسنل (با در نظر گرفتن تاریخچه افزایش حقوق §1)
function bp_hourly_rate(PDO $pdo, $user_id, $ws_id, $date_greg) {
    $u_stmt = $pdo->prepare("SELECT u.is_karigar, wp.daily_salary, wp.monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE u.id = ? AND wp.workshop_id = ? AND wp.status = 'active'");
    $u_stmt->execute([$user_id, $ws_id]);
    $u_info = $u_stmt->fetch();
    if (!$u_info) {
        // در صورت عدم عضویت مستقیم در این کارگاه (مثلاً مأموریت)، از آخرین کارگاه فعال پرسنل استفاده می‌شود
        $u_stmt2 = $pdo->prepare("SELECT u.is_karigar, wp.daily_salary, wp.monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE u.id = ? AND wp.status = 'active' ORDER BY wp.id DESC LIMIT 1");
        $u_stmt2->execute([$user_id]);
        $u_info = $u_stmt2->fetch();
    }
    if (!$u_info) return 0;
    $history = get_salary_history($pdo, $user_id);
    if ((int)$u_info['is_karigar'] === 0) {
        $mi = jalali_month_from_key(jalali_month_key($date_greg));
        $required = employee_required_hours($pdo, $user_id, $ws_id, $mi['start'], $mi['end'], $GLOBALS['settings'] ?? []);
        return employee_hourly_rate_for_required_hours(salary_at_date($history, $date_greg, (float)$u_info['monthly_salary']), $required);
    }
    return hourly_rate_at($history, $date_greg, true, (float)$u_info['daily_salary'], 0);
}


/** سه روش ثبت پاداش/جریمه: ساعتی، مبلغ ثابت یا تعدادی (تعداد × مبلغ واحد). */
function bp_entry_values(PDO $pdo, int $user_id, int $ws_id, string $date_greg, array $input): array {
    $mode = $input['calculation_mode'] ?? 'hourly';
    if (!in_array($mode, ['hourly', 'fixed', 'count'], true)) $mode = 'hourly';
    $hours = 0.0; $rate = 0.0; $qty = 0.0; $unit = 0.0; $amount = 0.0;
    if ($mode === 'hourly') {
        $hours = max(0, (float)normalize_digits($input['hours'] ?? 0));
        $rate = bp_hourly_rate($pdo, $user_id, $ws_id, $date_greg);
        $amount = round($hours * $rate);
    } elseif ($mode === 'fixed') {
        $amount = max(0, round((float)normalize_digits($input['fixed_amount'] ?? 0)));
    } else {
        $qty = max(0, (float)normalize_digits($input['quantity'] ?? 0));
        $unit = max(0, (float)normalize_digits($input['unit_amount'] ?? 0));
        $amount = round($qty * $unit);
    }
    return compact('mode', 'hours', 'rate', 'qty', 'unit', 'amount');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && $can_create) {
        $user_id = (int)$_POST['user_id'];
        $bp_type = ($_POST['type'] ?? 'bonus') === 'penalty' ? 'penalty' : 'bonus';
        $date_greg = to_gregorian($_POST['date']);
        $values = bp_entry_values($pdo, $user_id, $ws_id, $date_greg, $_POST);
        if ($values['amount'] <= 0) { showToast(t('مقدار پاداش/جریمه باید بیشتر از صفر باشد.'), 'error'); redirect("index.php?page=bonus_penalty"); }

        $evidence = null;
        try {
            if (!empty($_FILES['evidence_image']['name'])) $evidence = bp_save_evidence($_FILES['evidence_image']);
        } catch (RuntimeException $e) { showToast(t($e->getMessage()), 'error'); redirect("index.php?page=bonus_penalty"); }
        $status = $can_manage_all ? 'approved' : 'pending';
        $stmt = $pdo->prepare("INSERT INTO bonuses_penalties (user_id, workshop_id, type, amount, hours, hourly_rate, calculation_mode, quantity, unit_amount, reason, date, created_by, status, evidence_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $ws_id, $bp_type, $values['amount'], $values['hours'], $values['rate'], $values['mode'], $values['qty'], $values['unit'], sanitize($_POST['reason']), $date_greg, $_SESSION['user_id'], $status, $evidence]);
        showToast($can_manage_all ? t('ثبت شد') : t('ثبت شد - در انتظار تایید مدیر'));
        redirect("index.php?page=bonus_penalty");
    }

    if ($action === 'edit') {
        $rid = (int)$_POST['id'];
        // ادمین و مدیر پروژه همه را می‌توانند ویرایش کنند؛ غیرادمین فقط رکورد pending خودش
        $chk = $pdo->prepare("SELECT * FROM bonuses_penalties WHERE id = ? AND workshop_id = ?");
        $chk->execute([$rid, $ws_id]);
        $rec = $chk->fetch();
        if ($rec && ($can_manage_all || ($rec['status'] === 'pending' && $rec['created_by'] == $_SESSION['user_id'] && $can_create))) {
            $user_id = (int)$_POST['user_id'];
            $bp_type = ($_POST['type'] ?? 'bonus') === 'penalty' ? 'penalty' : 'bonus';
            $date_greg = to_gregorian($_POST['date']);
            $values = bp_entry_values($pdo, $user_id, $ws_id, $date_greg, $_POST);
            if ($values['amount'] <= 0) { showToast(t('مقدار پاداش/جریمه باید بیشتر از صفر باشد.'), 'error'); redirect("index.php?page=bonus_penalty"); }
            // §v15: عکس مستندات — عکس جدید جایگزین، تیک «حذف» فایل را پاک، وگرنه دست نمی‌خورد
            $evidence = $rec['evidence_image'] ?? null;
            try {
                if (!empty($_FILES['evidence_image']['name'])) {
                    $new = bp_save_evidence($_FILES['evidence_image']);
                    if ($new) { bp_delete_evidence($evidence); $evidence = $new; }
                } elseif (!empty($_POST['remove_evidence'])) {
                    bp_delete_evidence($evidence);
                    $evidence = null;
                }
            } catch (RuntimeException $e) { showToast(t($e->getMessage()), 'error'); redirect("index.php?page=bonus_penalty"); }
            $stmt = $pdo->prepare("UPDATE bonuses_penalties SET user_id = ?, type = ?, hours = ?, amount = ?, hourly_rate = ?, calculation_mode = ?, quantity = ?, unit_amount = ?, reason = ?, date = ?, evidence_image = ? WHERE id = ? AND workshop_id = ?");
            $stmt->execute([$user_id, $bp_type, $values['hours'], $values['amount'], $values['rate'], $values['mode'], $values['qty'], $values['unit'], sanitize($_POST['reason']), $date_greg, $evidence, $rid, $ws_id]);
            showToast(t('ویرایش شد'));
        }
        redirect("index.php?page=bonus_penalty");
    }

    if ($action === 'approve' && $can_manage_all) {
        $stmt = $pdo->prepare("UPDATE bonuses_penalties SET status = 'approved' WHERE id = ? AND workshop_id = ?");
        $stmt->execute([(int)$_POST['id'], $ws_id]);
        showToast(t('تایید شد'));
        redirect("index.php?page=bonus_penalty");
    }

    if ($action === 'reject' && $can_manage_all) {
        $stmt = $pdo->prepare("UPDATE bonuses_penalties SET status = 'rejected' WHERE id = ? AND workshop_id = ?");
        $stmt->execute([(int)$_POST['id'], $ws_id]);
        showToast(t('رد شد'));
        redirect("index.php?page=bonus_penalty");
    }

    if ($action === 'delete') {
        $rid = (int)$_POST['id'];
        $chk = $pdo->prepare("SELECT * FROM bonuses_penalties WHERE id = ? AND workshop_id = ?");
        $chk->execute([$rid, $ws_id]);
        $rec = $chk->fetch();
        if ($rec && ($can_manage_all || ($rec['status'] === 'pending' && $rec['created_by'] == $_SESSION['user_id']))) {
            $ev_del = $rec['evidence_image'] ?? null;
            $stmt = $pdo->prepare("DELETE FROM bonuses_penalties WHERE id = ? AND workshop_id = ?");
            $stmt->execute([(int)$_POST['id'], $ws_id]);
            bp_delete_evidence($ev_del ?: null);
            showToast(t('حذف شد'));
        }
        redirect("index.php?page=bonus_penalty");
    }
}

$stmt = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY u.full_name");
$stmt->execute([$ws_id]);
$personnel = $stmt->fetchAll();

$filter_user = $_GET['user'] ?? '';
$filter_tab = $_GET['tab'] ?? 'all';

// ═══ فیلتر ماه جلالی (§6) ═══
$all_dates = $pdo->prepare("SELECT date FROM bonuses_penalties WHERE workshop_id = ? ORDER BY date DESC");
$all_dates->execute([$ws_id]);
$months_avail = [];
foreach ($all_dates->fetchAll(PDO::FETCH_COLUMN) as $d) {
    if (!$d || $d === '0000-00-00') continue;
    $k = jalali_month_key($d);
    $months_avail[$k] = true;
}
$cur_key = jalali_month_key(date('Y-m-d'));
$filter_month = $_GET['m'] ?? '';
if ($filter_month === '') {
    $filter_month = isset($months_avail[$cur_key]) ? $cur_key : (count($months_avail) ? array_keys($months_avail)[0] : $cur_key);
}

$where = "bp.workshop_id = ?";
$params = [$ws_id];
if ($filter_month !== 'all' && preg_match('/^(\d{4})-(\d{1,2})$/', $filter_month, $mm)) {
    [$m_start, $m_end] = jalali_month_bounds((int)$mm[1], (int)$mm[2]);
    $where .= " AND bp.date BETWEEN ? AND ?";
    $params[] = $m_start; $params[] = $m_end;
}
if ($filter_user) { $where .= " AND bp.user_id = ?"; $params[] = $filter_user; }
if ($filter_tab == 'pending') { $where .= " AND bp.status = 'pending'"; }
elseif ($filter_tab == 'approved') { $where .= " AND bp.status = 'approved'"; }

$stmt = $pdo->prepare("SELECT bp.*, u.full_name FROM bonuses_penalties bp JOIN users u ON bp.user_id = u.id WHERE {$where} ORDER BY bp.date DESC, bp.id DESC");
$stmt->execute($params);
$records = $stmt->fetchAll();

// جمع‌های ماه انتخاب‌شده (فقط تاییدشده‌ها در جمع مالی)
$sum_bonus = 0; $sum_penalty = 0; $pending_cnt = 0;
foreach ($records as $r) {
    if ($r['status'] === 'approved') {
        if ($r['type'] === 'bonus') $sum_bonus += $r['amount'];
        else $sum_penalty += $r['amount'];
    }
    if ($r['status'] === 'pending') $pending_cnt++;
}

$m_label = t('همه ماه‌ها');
if ($filter_month !== 'all' && preg_match('/^(\d{4})-(\d{1,2})$/', $filter_month, $mm2)) {
    $m_label = jalali_month_name((int)$mm2[2]) . ' ' . $mm2[1];
}

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_bonus_penalty') ?></h2>
    <?php if ($can_create): ?>
    <button onclick="openModal('addModal')" class="btn btn-primary"><i data-lucide="plus"></i> <?= t('ثبت') ?></button>
    <?php endif; ?>
</div>

<!-- Tabs -->
<div class="tabs mb-4 animate-in">
    <a href="index.php?page=bonus_penalty&tab=all&m=<?= urlencode($filter_month) ?>" class="tab <?= $filter_tab == 'all' ? 'active' : '' ?>"><?= t('همه') ?></a>
    <?php if ($is_admin): ?>
    <a href="index.php?page=bonus_penalty&tab=pending&m=<?= urlencode($filter_month) ?>" class="tab <?= $filter_tab == 'pending' ? 'active' : '' ?>"><?= t('در انتظار') ?> <?= t('تایید') ?> <?php
        $pc = $pdo->prepare("SELECT COUNT(*) FROM bonuses_penalties WHERE workshop_id = ? AND status = 'pending'");
        $pc->execute([$ws_id]); $pc = $pc->fetchColumn();
        if ($pc > 0): ?><span class="tag tag-pending" style="font-size:10px;margin-right:4px"><?= $pc ?></span><?php endif;
    ?></a>
    <a href="index.php?page=bonus_penalty&tab=approved&m=<?= urlencode($filter_month) ?>" class="tab <?= $filter_tab == 'approved' ? 'active' : '' ?>"><?= t('lea_approved') ?></a>
    <?php endif; ?>
</div>

<!-- جمع‌های ماه -->
<div class="grid-3 mb-4 animate-in">
    <div class="card stat-card"><div class="stat-icon" style="background:var(--success-bg);color:var(--success)"><i data-lucide="gift"></i></div>
        <div><div class="stat-label"><?= t('rep_total') ?> <?= t('sal_bonus') ?> — <?= $m_label ?></div><div class="stat-value" style="color:var(--success)"><?= number_format($sum_bonus) ?> <?= t('ت') ?></div></div></div>
    <div class="card stat-card"><div class="stat-icon" style="background:var(--danger-bg);color:var(--danger)"><i data-lucide="gavel"></i></div>
        <div><div class="stat-label"><?= t('rep_total') ?> <?= t('sal_penalty') ?> — <?= $m_label ?></div><div class="stat-value" style="color:var(--danger)"><?= number_format($sum_penalty) ?> <?= t('ت') ?></div></div></div>
    <div class="card stat-card"><div class="stat-icon" style="background:var(--warning-bg);color:var(--warning)"><i data-lucide="hourglass"></i></div>
        <div><div class="stat-label"><?= t('در انتظار') ?> <?= t('btn_confirm') ?></div><div class="stat-value"><?= $pending_cnt ?> <?= t('مورد') ?></div></div></div>
</div>

<div class="card mb-4 animate-in">
    <form method="GET" action="index.php" class="d-flex gap-2" style="flex-wrap:wrap">
        <input type="hidden" name="page" value="bonus_penalty">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($filter_tab) ?>">
        <select name="m" class="form-control" style="width:170px">
            <option value="all" <?= $filter_month === 'all' ? 'selected' : '' ?>><?= t('همه') ?> <?= t('rep_month') ?>‌<?= t('ها') ?></option>
            <?php foreach (array_keys($months_avail) as $mk):
                [$jy, $jm] = explode('-', $mk); ?>
            <option value="<?= $mk ?>" <?= $filter_month === $mk ? 'selected' : '' ?>><?= jalali_month_name((int)$jm) . ' ' . $jy ?></option>
            <?php endforeach; ?>
        </select>
        <select name="user" class="form-control" style="width:200px">
            <option value=""><?= t('scope_both') ?></option>
            <?php foreach ($personnel as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $filter_user == $p['id'] ? 'selected' : '' ?>><?= $p['full_name'] ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-ghost"><?= t('btn_filter') ?></button>
    </form>
</div>

<div class="card animate-in">
    <div class="table-container" id="reportTable">
        <table>
            <thead><tr><th><?= t('wh_name') ?></th><th><?= t('sal_type') ?></th><th><?= t('روش') ?> <?= t('btn_calculate') ?></th><th><?= t('sal_details') ?></th><th><?= t('مبلغ') ?></th><th><?= t('adv_reason') ?></th><th><?= t('مستندات') ?></th><th><?= t('wh_date') ?></th><th><?= t('wh_status') ?></th><th><?= t('wh_actions') ?></th></tr></thead>
            <tbody>
                <?php foreach ($records as $r):
                    $can_edit = $can_manage_all || ($r['status'] === 'pending' && $r['created_by'] == $_SESSION['user_id'] && $can_create);
                    $can_delete = $can_manage_all || ($r['status'] === 'pending' && $r['created_by'] == $_SESSION['user_id']);
                ?>
                <tr>
                    <td><strong><?= $r['full_name'] ?></strong></td>
                    <td><span class="tag <?= $r['type'] == 'bonus' ? 'tag-active' : 'tag-rejected' ?>"><?= $r['type'] == 'bonus' ? t('sal_bonus') : t('sal_penalty') ?></span></td>
                    <?php $mode = $r['calculation_mode'] ?? 'hourly'; ?>
                    <td><span class="tag" style="font-size:10px"><?= $mode === 'fixed' ? t('💰 مبلغ ثابت') : ($mode === 'count' ? t('🔢 تعدادی') : t('⏱ ساعتی')) ?></span></td>
                    <td style="font-size:11px"><?= $mode === 'fixed' ? number_format($r['amount']) . ' ' . t('تومان') : ($mode === 'count' ? number_format((float)($r['quantity'] ?? 0)) . ' × ' . number_format((float)($r['unit_amount'] ?? 0)) : fmt_hm((float)($r['hours'] ?? 0)) . ' × ' . number_format((float)($r['hourly_rate'] ?? 0))) ?></td>
                    <td><?= number_format($r['amount']) ?> <?= t('ت') ?></td>
                    <td><?= $r['reason'] ?></td>
                    <td style="text-align:center">
                        <?php if (!empty($r['evidence_image'])): ?>
                        <img src="<?= htmlspecialchars($r['evidence_image'], ENT_QUOTES, 'UTF-8') ?>" onclick="openBpLightbox('<?= htmlspecialchars($r['evidence_image'], ENT_QUOTES, 'UTF-8') ?>')" style="width:48px;height:36px;object-fit:cover;border-radius:6px;cursor:pointer;border:1px solid #e2e8f0" title="<?= t('مشاهده عکس') ?>">
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td><?= to_jalali($r['date']) ?></td>
                    <td>
                        <?php if ($r['status'] == 'pending'): ?>
                            <span class="tag tag-pending"><?= t('در انتظار') ?> <?= t('btn_confirm') ?></span>
                        <?php elseif ($r['status'] == 'rejected'): ?>
                            <span class="tag tag-rejected"><?= t('adv_rejected') ?></span>
                        <?php else: ?>
                            <span class="tag tag-active"><?= t('lea_approved') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="table-actions">
                            <?php if ($can_edit): ?>
                            <button onclick="openModal('editModal<?= $r['id'] ?>')" class="btn btn-ghost btn-sm" title="<?= t('btn_edit') ?>"><i data-lucide="pencil"></i></button>
                            <?php endif; ?>
                            <?php if ($can_manage_all && $r['status'] == 'pending'): ?>
                            <form method="POST" action="index.php?page=bonus_penalty" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-success btn-sm"><?= t('btn_confirm') ?></button></form>
                            <form method="POST" action="index.php?page=bonus_penalty" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-danger btn-sm"><?= t('btn_reject') ?></button></form>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <form method="POST" action="index.php?page=bonus_penalty" style="display:inline" onsubmit="return confirm('<?= t('chat_delete') ?>')"><?= get_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-ghost btn-sm" title="<?= t('btn_delete') ?>"><i data-lucide="trash-2"></i></button></form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:40px"><?= t('داده‌ای نیست') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال‌های ویرایش -->
<?php foreach ($records as $r):
    $can_edit = $can_manage_all || ($r['status'] === 'pending' && $r['created_by'] == $_SESSION['user_id'] && $can_create);
    if (!$can_edit) continue;
?>
<div id="editModal<?= $r['id'] ?>" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_edit') ?> — <?= $r['full_name'] ?></h3><button onclick="closeModal('editModal<?= $r['id'] ?>')" class="modal-close">✕</button></div>
        <form method="POST" action="index.php?page=bonus_penalty" enctype="multipart/form-data" onsubmit="return bpBeforeSubmit(this)"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('nav_personnel') ?> *</label>
                    <select name="user_id" class="form-control" required>
                        <?php foreach ($personnel as $p): ?><option value="<?= $p['id'] ?>" <?= $r['user_id'] == $p['id'] ? 'selected' : '' ?>><?= $p['full_name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('sal_type') ?> *</label>
                        <select name="type" class="form-control" required>
                            <option value="bonus" <?= $r['type'] === 'bonus' ? 'selected' : '' ?>><?= t('sal_bonus') ?></option>
                            <option value="penalty" <?= $r['type'] === 'penalty' ? 'selected' : '' ?>><?= t('sal_penalty') ?></option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label"><?= t('روش') ?> *</label><select name="calculation_mode" id="bp_edit_mode_<?= $r['id'] ?>" class="form-control" onchange="bpToggleMode('edit_<?= $r['id'] ?>')"><option value="hourly" <?= ($r['calculation_mode'] ?? 'hourly') === 'hourly' ? 'selected' : '' ?>><?= t('ساعتی') ?></option><option value="fixed" <?= ($r['calculation_mode'] ?? '') === 'fixed' ? 'selected' : '' ?>><?= t('مبلغ ثابت') ?></option><option value="count" <?= ($r['calculation_mode'] ?? '') === 'count' ? 'selected' : '' ?>><?= t('عددی') ?></option></select></div>
                </div>
                <div id="bp_edit_<?= $r['id'] ?>_hourly" class="form-group"><label class="form-label"><?= t('تعداد') ?> <?= t('wh_hours') ?></label><input type="number" name="hours" class="form-control" min="0" step="0.5" value="<?= $r['hours'] ?>"></div>
                <div id="bp_edit_<?= $r['id'] ?>_fixed" class="form-group" style="display:none"><label class="form-label"><?= t('raise_mode_amount') ?></label><input type="number" name="fixed_amount" class="form-control" min="0" value="<?= $r['amount'] ?>"></div>
                <div id="bp_edit_<?= $r['id'] ?>_count" class="form-row" style="display:none"><div class="form-group"><label class="form-label"><?= t('تعداد') ?></label><input type="number" name="quantity" class="form-control" min="0" step="0.5" value="<?= $r['quantity'] ?? 0 ?>"></div><div class="form-group"><label class="form-label"><?= t('مبلغ') ?> <?= t('هر') ?> <?= t('واحد') ?> (<?= t('sal_toman') ?>)</label><input type="number" name="unit_amount" class="form-control" min="0" value="<?= $r['unit_amount'] ?? 0 ?>"></div></div>
                <div class="form-group"><label class="form-label"><?= t('adv_reason') ?> *</label><textarea name="reason" class="form-control" required rows="2"><?= htmlspecialchars($r['reason']) ?></textarea></div>
                <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="date" class="form-control jalali-date" value="<?= to_jalali($r['date']) ?>"></div>
                <?php if (!empty($r['evidence_image'])): ?>
                <div class="form-group" style="display:flex;align-items:center;gap:12px">
                    <img src="<?= htmlspecialchars($r['evidence_image'], ENT_QUOTES, 'UTF-8') ?>" onclick="openBpLightbox('<?= htmlspecialchars($r['evidence_image'], ENT_QUOTES, 'UTF-8') ?>')" style="width:72px;height:54px;object-fit:cover;border-radius:8px;cursor:pointer;border:1px solid #e2e8f0" title="<?= t('مشاهده عکس') ?>">
                    <label style="font-size:12px;display:flex;gap:6px;align-items:center;cursor:pointer"><input type="checkbox" name="remove_evidence" value="1"> <?= t('حذف عکس') ?> <?= t('فعلی') ?></label>
                </div>
                <?php endif; ?>
                <div class="form-group"><label class="form-label">📷 <?= t('عکس') ?> <?= t('مستندات') ?> (<?= t('اختیاری') ?>)</label>
                    <input type="file" name="evidence_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <p class="text-muted" style="font-size:11px"><?= t('عکس به‌صورت خودکار فشرده می‌شود تا فضای سایت اشغال نشود') ?></p>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_submit') ?></button><button type="button" onclick="closeModal('editModal<?= $r['id'] ?>')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<div id="addModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('ثبت') ?> <?= t('جدید') ?></h3><button onclick="closeModal('addModal')" class="modal-close">✕</button></div>
        <form method="POST" action="index.php?page=bonus_penalty" enctype="multipart/form-data" onsubmit="return bpBeforeSubmit(this)"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('nav_personnel') ?> *</label>
                    <select name="user_id" class="form-control" required>
                        <?php foreach ($personnel as $p): ?><option value="<?= $p['id'] ?>"><?= $p['full_name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('sal_type') ?> *</label>
                        <select name="type" class="form-control" required><option value="bonus"><?= t('sal_bonus') ?></option><option value="penalty"><?= t('sal_penalty') ?></option></select>
                    </div>
                    <div class="form-group"><label class="form-label"><?= t('روش') ?> *</label><select name="calculation_mode" id="bp_add_mode" class="form-control" onchange="bpToggleMode('add')"><option value="hourly"><?= t('ساعتی') ?></option><option value="fixed"><?= t('مبلغ ثابت') ?></option><option value="count"><?= t('عددی') ?></option></select></div>
                </div>
                <div id="bp_add_hourly" class="form-group"><label class="form-label"><?= t('تعداد') ?> <?= t('wh_hours') ?></label><input type="number" name="hours" class="form-control" min="0" step="0.5" placeholder="<?= t('مثلاً') ?>: 3"></div>
                <div id="bp_add_fixed" class="form-group" style="display:none"><label class="form-label"><?= t('raise_mode_amount') ?></label><input type="number" name="fixed_amount" class="form-control" min="0" placeholder="<?= t('مثلاً') ?>: 500000"></div>
                <div id="bp_add_count" class="form-row" style="display:none"><div class="form-group"><label class="form-label"><?= t('تعداد') ?></label><input type="number" name="quantity" class="form-control" min="0" step="0.5" placeholder="<?= t('مثلاً') ?>: 3"></div><div class="form-group"><label class="form-label"><?= t('مبلغ') ?> <?= t('هر') ?> <?= t('واحد') ?> (<?= t('sal_toman') ?>)</label><input type="number" name="unit_amount" class="form-control" min="0" placeholder="<?= t('مثلاً') ?>: 100000"></div></div>
                <div class="form-group"><label class="form-label"><?= t('adv_reason') ?> *</label><textarea name="reason" class="form-control" required rows="2"></textarea></div>
                <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="date" class="form-control jalali-date" value="<?= jalali_now() ?>"></div>
                <div class="form-group"><label class="form-label">📷 <?= t('عکس') ?> <?= t('مستندات') ?> (<?= t('اختیاری') ?>)</label>
                    <input type="file" name="evidence_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <p class="text-muted" style="font-size:11px"><?= t('عکس به‌صورت خودکار فشرده می‌شود تا فضای سایت اشغال نشود') ?></p>
                </div>
                <?php if (!$can_manage_all): ?>
                <p class="text-muted" style="font-size:11px"><?= t('پس') ?> <?= t('lea_from') ?> <?= t('ثبت') ?>، <?= t('نیاز') ?> <?= t('به') ?> <?= t('btn_confirm') ?> <?= t('مدیر') ?> <?= t('دارد') ?></p>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_submit') ?></button><button type="button" onclick="closeModal('addModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>

<div id="bpLightbox" class="hidden" onclick="this.classList.add('hidden')" style="position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:20px">
    <img id="bpLightboxImg" src="" style="max-width:95%;max-height:95%;border-radius:8px;object-fit:contain" alt="">
    <button onclick="document.getElementById('bpLightbox').classList.add('hidden')" style="position:absolute;top:16px;left:16px;background:rgba(255,255,255,0.2);border:none;color:white;font-size:24px;width:40px;height:40px;border-radius:50%;cursor:pointer">✕</button>
</div>

<script>
function bpBeforeSubmit(form) {
    var fileInput = form.querySelector('input[type="file"][name="evidence_image"]');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        form.removeAttribute('enctype');
    }
    return true;
}
function openBpLightbox(src) {
    document.getElementById('bpLightboxImg').src = src;
    var lb = document.getElementById('bpLightbox');
    lb.classList.remove('hidden');
    lb.style.display = 'flex';
}
function bpToggleMode(prefix) {
    var select = document.getElementById('bp_' + prefix + '_mode');
    if (!select) return;
    ['hourly','fixed','count'].forEach(function(mode) {
        var el = document.getElementById('bp_' + prefix + '_' + mode);
        if (el) el.style.display = select.value === mode ? '' : 'none';
    });
}
(function(){
    bpToggleMode('add');
    <?php foreach ($records as $r): if ($can_manage_all || ($r['status'] === 'pending' && $r['created_by'] == $_SESSION['user_id'] && $can_create)): ?>bpToggleMode('edit_<?= $r['id'] ?>');<?php endif; endforeach; ?>
})();
</script>

<?php include 'includes/footer.php'; ?>
