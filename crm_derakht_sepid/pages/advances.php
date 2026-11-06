<?php
$pageTitle = t('nav_advances');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$is_admin = is_strict_admin() || is_project_manager() || is_workshop_manager() || has_permission('manage_advances');
// §v11: ناظرِ مساعده = دیدن درخواست‌های همه بدون امکان تأیید/پرداخت
$can_view_all = $is_admin || is_nazer() || in_array(access_mode('advances'), ['viewer', 'editor'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'request') {
        $target_user = (int)($_POST['user_id'] ?? 0);
        if (!$is_admin) $target_user = (int)$_SESSION['user_id']; // غیر ادمین فقط برای خودش
        if ($target_user <= 0) $target_user = (int)$_SESSION['user_id'];
        $amount = (float)$_POST['amount'];
        $reason = sanitize($_POST['reason']);
        if ($is_admin && $target_user !== (int)$_SESSION['user_id']) {
            // ادمین مستقیم ثبت می‌کند → خودکار تأیید می‌شود
            $stmt = $pdo->prepare("INSERT INTO advances (user_id, workshop_id, amount, remaining, reason, payment_type, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, 'advance', 'approved', ?, NOW())");
            $stmt->execute([$target_user, $ws_id, $amount, $amount, $reason, $_SESSION['user_id']]);
            showToast(t('مساعده ثبت و تأیید شد'));
        } else {
            $stmt = $pdo->prepare("INSERT INTO advances (user_id, workshop_id, amount, remaining, reason, payment_type) VALUES (?, ?, ?, ?, ?, 'advance')");
            $stmt->execute([$target_user, $ws_id, $amount, $amount, $reason]);
            showToast(t('درخواست ثبت شد'));
        }
        redirect("index.php?page=advances");
    }

    // ── ویرایش مساعده: ادمین همه / صاحب رکورد فقط در حالت در‌انتظار ──
    if ($action === 'edit_advance') {
        $id = (int)$_POST['id'];
        $st = $pdo->prepare("SELECT * FROM advances WHERE id = ? AND workshop_id = ?");
        $st->execute([$id, $ws_id]);
        $adv = $st->fetch();
        if (!$adv) { showToast(t('رکورد یافت نشد'), 'error'); redirect("index.php?page=advances"); }
        $own_pending = ((int)$adv['user_id'] === (int)$_SESSION['user_id'] && $adv['status'] === 'pending');
        if (!$is_admin && !$own_pending) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=advances"); }
        $new_amount = (float)$_POST['amount'];
        // «مانده از قبل» می‌تواند منفی (طلب نیرو) باشد و هزینه‌کرد ندارد؛
        // پس محدودیت‌های مساعده نباید علامت آن را خراب کند.
        if (($adv['payment_type'] ?? '') === 'debt') {
            $keep_sign = ((float)$adv['amount'] < 0) ? -1 : 1;
            $new_amount = $keep_sign * abs($new_amount);
            $pdo->prepare("UPDATE advances SET amount = ?, remaining = ?, reason = ? WHERE id = ?")
                ->execute([$new_amount, $new_amount, sanitize($_POST['reason']), $id]);
            $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'edit', 'advance', ?, ?)")
                ->execute([$_SESSION['user_id'], $id, 'ویرایش مانده از قبل: ' . number_format($adv['amount']) . ' ← ' . number_format($new_amount)]);
            showToast(t('بروزرسانی شد'));
            redirect("index.php?page=advances");
        }
        if ($new_amount < 0) $new_amount = 0;
        $spent = (float)$adv['amount'] - (float)$adv['remaining'];
        if ($new_amount < $spent) {
            showToast(strtr(t('مبلغ کمتر از هزینه‌های ثبت‌شده ({spent}) نمی‌تواند باشد'), ['{spent}' => number_format($spent)]), 'error');
            redirect("index.php?page=advances");
        }
        $new_remaining = $new_amount - $spent;
        $new_paid_at = $adv['paid_at'];
        if ($adv['status'] === 'paid' && $is_admin && isset($_POST['paid_date'])) {
            $paid_date_g = to_gregorian($_POST['paid_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$paid_date_g)) {
                showToast(t('تاریخ پرداخت نامعتبر است'), 'error');
                redirect("index.php?page=advances");
            }
            $old_time = !empty($adv['paid_at']) ? substr((string)$adv['paid_at'], 11, 8) : date('H:i:s');
            $new_paid_at = $paid_date_g . ' ' . $old_time;
        }
        $pdo->prepare("UPDATE advances SET amount = ?, remaining = ?, reason = ?, paid_at = ? WHERE id = ?")
            ->execute([$new_amount, $new_remaining, sanitize($_POST['reason']), $new_paid_at, $id]);
        // پرداخت‌های جدید تنخواه با advance_id لینک می‌شوند؛ بنابراین تاریخ تنخواه نیز همگام می‌ماند.
        if ($adv['status'] === 'paid' && ($adv['payment_method'] ?? '') === 'petty_cash' && !empty($new_paid_at) && db_has_column($pdo, 'petty_cash', 'advance_id')) {
            $pdo->prepare("UPDATE petty_cash SET date = ? WHERE advance_id = ?")->execute([substr($new_paid_at, 0, 10), $id]);
        }
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'edit', 'advance', ?, ?)")
            ->execute([$_SESSION['user_id'], $id, 'ویرایش: ' . number_format($adv['amount']) . ' ← ' . number_format($new_amount) . (!empty($new_paid_at) ? ' | تاریخ پرداخت: ' . to_jalali(substr($new_paid_at,0,10)) : '')]);
        showToast(t('مساعده بروزرسانی شد'));
        redirect("index.php?page=advances");
    }

    // ── حذف مساعده: ادمین همه / صاحب رکورد فقط در حالت در‌انتظار ──
    if ($action === 'delete_advance') {
        $id = (int)$_POST['id'];
        $st = $pdo->prepare("SELECT * FROM advances WHERE id = ? AND workshop_id = ?");
        $st->execute([$id, $ws_id]);
        $adv = $st->fetch();
        if (!$adv) { showToast(t('رکورد یافت نشد'), 'error'); redirect("index.php?page=advances"); }
        $own_pending = ((int)$adv['user_id'] === (int)$_SESSION['user_id'] && $adv['status'] === 'pending');
        if (!$is_admin && !$own_pending) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=advances"); }
        $pdo->prepare("DELETE FROM advance_expenses WHERE advance_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM advances WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'delete', 'advance', ?, ?)")
            ->execute([$_SESSION['user_id'], $id, 'حذف مساعده ' . number_format($adv['amount'])]);
        showToast(t('مساعده حذف شد'));
        redirect("index.php?page=advances");
    }
    
    // ── ثبت مانده از قبل (طلب یا بدهی نیرو از دوره‌های گذشته) ──
    // موتور حقوق مبلغ payment_type='debt' را از مبلغ قابل پرداخت کم می‌کند؛
    // پس «بدهی نیرو» مثبت ذخیره می‌شود و «طلب نیرو» منفی تا به او اضافه شود.
    if ($action === 'register_debt') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=advances"); exit; }
        $user_id = (int)$_POST['user_id'];
        $amount = abs((float)str_replace(',', '', (string)($_POST['amount'] ?? 0)));
        $direction = ($_POST['balance_dir'] ?? 'debit') === 'credit' ? 'credit' : 'debit';
        $reason = sanitize($_POST['reason'] ?? '');
        if ($user_id <= 0 || $amount <= 0) {
            showToast(t('مبلغ و نیرو را کامل وارد کنید'), 'error');
            redirect("index.php?page=advances");
        }
        // تاریخ مؤثر: مانده از این تاریخ در محاسبات دیده می‌شود (پیش‌فرض امروز)
        $eff_date = to_gregorian(sanitize($_POST['effective_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$eff_date)) $eff_date = date('Y-m-d');
        $eff_at = $eff_date . ' ' . date('H:i:s');
        // طلب نیرو ⇒ منفی (به نفع نیرو) | بدهی نیرو ⇒ مثبت (به ضرر نیرو)
        $signed = $direction === 'credit' ? -$amount : $amount;
        $pdo->prepare("INSERT INTO advances (user_id, workshop_id, amount, remaining, reason, status, payment_type, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, 'approved', 'debt', ?, ?)")
            ->execute([$user_id, $ws_id, $signed, $signed, $reason, $_SESSION['user_id'], $eff_at]);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'create', 'advance', ?, ?)")
            ->execute([$_SESSION['user_id'], (int)$pdo->lastInsertId(),
                ($direction === 'credit' ? 'ثبت طلب نیرو ' : 'ثبت بدهی نیرو ') . number_format($amount) . ' — ' . to_jalali($eff_date)]);
        showToast(t('adv_debt_saved'));
        redirect("index.php?page=advances");
    }
    
    if ($action === 'approve') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=advances"); exit; }
        $stmt = $pdo->prepare("UPDATE advances SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$_SESSION['user_id'], (int)$_POST['id'], $ws_id]);
        showToast(t('تایید شد - در انتظار پرداخت'));
        redirect("index.php?page=advances");
    }
    
    if ($action === 'reject') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=advances"); exit; }
        $stmt = $pdo->prepare("UPDATE advances SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$_SESSION['user_id'], (int)$_POST['id'], $ws_id]);
        showToast(t('رد شد'));
        redirect("index.php?page=advances");
    }
    
    if ($action === 'pay_fund') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=advances"); exit; }
        $id = (int)$_POST['id'];
        $pay_date = to_gregorian($_POST['pay_date'] ?? jalali_now());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$pay_date)) { showToast(t('تاریخ پرداخت نامعتبر است'), 'error'); redirect("index.php?page=advances"); }
        $paid_at = $pay_date . ' ' . date('H:i:s');
        $stmt = $pdo->prepare("UPDATE advances SET status = 'paid', payment_method = 'fund', paid_at = ? WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$paid_at, $id, $ws_id]);
        $stmt2 = $pdo->prepare("SELECT a.amount, u.full_name FROM advances a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
        $stmt2->execute([$id]);
        $info = $stmt2->fetch();
        $stmt2 = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'pay_fund', 'advance', ?, ?)");
        $stmt2->execute([$_SESSION['user_id'], $id, 'پرداخت از صندوق: ' . number_format($info['amount']) . ' به ' . $info['full_name'] . ' در ' . to_jalali($pay_date)]);
        showToast(t('پرداخت از صندوق با تاریخ انتخاب‌شده ثبت شد'));
        redirect("index.php?page=advances");
    }
    
    if ($action === 'pay_petty') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=advances"); exit; }
        $id = (int)$_POST['id'];
        $pay_date = to_gregorian($_POST['pay_date'] ?? jalali_now());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$pay_date)) { showToast(t('تاریخ پرداخت نامعتبر است'), 'error'); redirect("index.php?page=advances"); }
        $paid_at = $pay_date . ' ' . date('H:i:s');
        $stmt = $pdo->prepare("UPDATE advances SET status = 'paid', payment_method = 'petty_cash', paid_at = ? WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$paid_at, $id, $ws_id]);
        $stmt2 = $pdo->prepare("SELECT a.amount, a.reason, a.payment_type, u.full_name FROM advances a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
        $stmt2->execute([$id]);
        $info = $stmt2->fetch();
        $type_label = $info['payment_type'] == 'salary' ? t('nav_salary') : t('sal_advance');
        $desc = $type_label . ' به ' . $info['full_name'] . ($info['reason'] ? ' - ' . $info['reason'] : '');
        if (db_has_column($pdo, 'petty_cash', 'advance_id')) {
            $stmt2 = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by, advance_id) VALUES (?, 'expense', ?, ?, ?, ?, ?)");
            $stmt2->execute([$ws_id, $info['amount'], $desc, $pay_date, $_SESSION['user_id'], $id]);
        } else {
            $stmt2 = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by) VALUES (?, 'expense', ?, ?, ?, ?)");
            $stmt2->execute([$ws_id, $info['amount'], $desc, $pay_date, $_SESSION['user_id']]);
        }
        $stmt2 = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'pay_petty', 'advance', ?, ?)");
        $stmt2->execute([$_SESSION['user_id'], $id, 'پرداخت از تنخواه: ' . number_format($info['amount']) . ' به ' . $info['full_name'] . ' در ' . to_jalali($pay_date)]);
        showToast(t('پرداخت از تنخواه با تاریخ انتخاب‌شده ثبت شد'));
        redirect("index.php?page=advances");
    }
    
    if ($action === 'manual_payment') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=advances"); exit; }
        $user_id = (int)$_POST['user_id'];
        $amount = (float)$_POST['amount'];
        $reason = sanitize($_POST['reason'] ?? '');
        $payment_method = $_POST['payment_method'] ?? 'fund';
        $payment_type = $_POST['payment_type'] ?? 'advance';
        $pay_date = to_gregorian($_POST['pay_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$pay_date)) $pay_date = date('Y-m-d');
        $paid_at = $pay_date . ' ' . date('H:i:s');
        
        // Insert as paid record (با تاریخ پرداخت انتخابی تا کسر حقوق ماه درست محاسبه شود)
        $stmt = $pdo->prepare("INSERT INTO advances (user_id, workshop_id, amount, remaining, reason, status, payment_method, payment_type, approved_by, approved_at, paid_at) VALUES (?, ?, ?, 0, ?, 'paid', ?, ?, ?, NOW(), ?)");
        $stmt->execute([$user_id, $ws_id, $amount, $reason, $payment_method, $payment_type, $_SESSION['user_id'], $paid_at]);
        $manual_advance_id = (int)$pdo->lastInsertId();
        
        // Get name for audit and petty cash
        $stmt2 = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt2->execute([$user_id]);
        $name = $stmt2->fetchColumn();
        
        $type_label = $payment_type == 'salary' ? t('nav_salary') : t('sal_advance');
        
        if ($payment_method == 'petty_cash') {
            $desc = $type_label . ' به ' . $name . ($reason ? ' - ' . $reason : '');
            if (db_has_column($pdo, 'petty_cash', 'advance_id')) {
                $stmt2 = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by, advance_id) VALUES (?, 'expense', ?, ?, ?, ?, ?)");
                $stmt2->execute([$ws_id, $amount, $desc, $pay_date, $_SESSION['user_id'], $manual_advance_id]);
            } else {
                $stmt2 = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by) VALUES (?, 'expense', ?, ?, ?, ?)");
                $stmt2->execute([$ws_id, $amount, $desc, $pay_date, $_SESSION['user_id']]);
            }
        }
        
        $stmt2 = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, ?, 'advance', ?, ?)");
        $stmt2->execute([$_SESSION['user_id'], 'manual_' . $payment_type, $user_id, $type_label . ' دستی: ' . number_format($amount) . ' به ' . $name]);
        
        showToast($type_label . ' ' . t('با موفقیت ثبت شد'));
        redirect("index.php?page=advances");
    }
    
    if ($action === 'add_expense') {
        // امنیت: فقط صاحب مساعده یا ادمین می‌تواند هزینه ثبت کند
        $chk = $pdo->prepare("SELECT user_id FROM advances WHERE id = ? AND workshop_id = ?");
        $chk->execute([(int)$_POST['advance_id'], $ws_id]);
        $adv_owner = (int)$chk->fetchColumn();
        if (!$is_admin && $adv_owner !== (int)$_SESSION['user_id']) {
            showToast(t('دسترسی ندارید'), 'error');
            redirect("index.php?page=advances");
        }
        $receipt = '';
        if (!empty($_FILES['receipt']['name'])) {
            if (!is_dir(RESID_DIR)) mkdir(RESID_DIR, 0777, true);
            $filename = uniqid() . '_' . basename($_FILES['receipt']['name']);
            $receipt = 'uploads/resid/' . $filename;
            move_uploaded_file($_FILES['receipt']['tmp_name'], RESID_DIR . $filename);
            compress_image(RESID_DIR . $filename, 800, 82);
        }
        $amount = (float)$_POST['amount'];
        $stmt = $pdo->prepare("INSERT INTO advance_expenses (advance_id, user_id, amount, description, expense_date, expense_time, receipt_image) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([(int)$_POST['advance_id'], $_SESSION['user_id'], $amount, sanitize($_POST['description']), to_gregorian($_POST['expense_date']), $_POST['expense_time'] ?: null, $receipt]);
        $stmt = $pdo->prepare("UPDATE advances SET remaining = remaining - ? WHERE id = ?");
        $stmt->execute([$amount, (int)$_POST['advance_id']]);
        showToast(t('هزینه ثبت شد'));
        redirect("index.php?page=advances");
    }
}

// Get personnel for dropdowns
$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY u.full_name");
$stmt->execute([$ws_id]);
$personnel = $stmt->fetchAll();

// Get advances
if ($can_view_all) {
    $stmt = $pdo->prepare("SELECT a.*, u.full_name FROM advances a JOIN users u ON a.user_id = u.id WHERE a.workshop_id = ? ORDER BY a.created_at DESC");
    $stmt->execute([$ws_id]);
} else {
    $stmt = $pdo->prepare("SELECT a.*, u.full_name FROM advances a JOIN users u ON a.user_id = u.id WHERE a.workshop_id = ? AND a.user_id = ? ORDER BY a.created_at DESC");
    $stmt->execute([$ws_id, $_SESSION['user_id']]);
}
$advances = $stmt->fetchAll();

$statusLabels = [
    'pending' => [t('adv_pending'), 'tag-pending'],
    'approved' => [t('adv_approved'), 'tag-pending'],
    'paid' => [t('adv_paid'), 'tag-active'],
    'rejected' => [t('adv_rejected'), 'tag-rejected'],
];

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('adv_title') ?></h2>
    <div class="btn-group">
        <button onclick="openModal('requestModal')" class="btn btn-primary"><i data-lucide="plus"></i> <?= t('adv_request') ?></button>
        <?php if ($is_admin): ?>
        <button onclick="openModal('debtModal')" class="btn btn-primary"><i data-lucide="scale"></i> <?= t('adv_register_debt') ?></button>
        <?php endif; ?>
        <?php if ($is_admin): ?>
        <button onclick="openManualPayment('advance')" class="btn btn-success"><i data-lucide="banknote"></i> <?= t('adv_pay_advance') ?></button>
        <button onclick="openManualPayment('salary')" class="btn btn-warning"><i data-lucide="wallet"></i> <?= t('adv_pay_salary') ?></button>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<div class="tabs mb-4 animate-in">
    <button class="tab active" onclick="switchTab(this, 'all')">...</button>
    <button class="tab" onclick="switchTab(this, 'advance')"><?= t('adv_request') ?></button>
    <button class="tab" onclick="switchTab(this, 'salary')"><?= t('nav_salary') ?></button>
    <button class="tab" onclick="switchTab(this, 'pending')"><?= t('adv_pending') ?></button>
    <button class="tab" onclick="switchTab(this, 'paid')"><?= t('adv_paid') ?></button>
</div>

<div class="card animate-in">
    <div class="table-container">
        <table>
            <thead><tr><th><?= t('wh_name') ?></th><th><?= t('sal_type') ?></th><th><?= t('مبلغ') ?></th><th><?= t('pet_balance') ?></th><th><?= t('adv_reason') ?></th><th><?= t('wh_status') ?></th><th><?= t('wh_date') ?></th><th><?= t('wh_actions') ?></th></tr></thead>
            <tbody>
                <?php foreach ($advances as $a):
                    $label = $statusLabels[$a['status']] ?? [$a['status'], 'tag-pending'];
                    $ptype = $a['payment_type'] ?? 'advance';
                    $ptype_label = $ptype == 'salary' ? t('nav_salary') : t('sal_advance');
                    $ptype_class = $ptype == 'salary' ? 'tag-warning' : 'tag-info';
                    if ($a['status'] == 'paid') $ptype_class = 'tag-active';
                    // مانده از قبل: مثبت = بدهی نیرو (کسر می‌شود) | منفی = طلب نیرو (اضافه می‌شود)
                    $is_debt_row = ($ptype === 'debt');
                    if ($is_debt_row) {
                        $ptype_label = t('adv_debt');
                        $ptype_class = ((float)$a['amount'] < 0) ? 'tag-active' : 'tag-danger';
                    }
                    $amt_style = '';
                    if ($is_debt_row) $amt_style = ((float)$a['amount'] < 0) ? 'color:#16a34a;font-weight:700' : 'color:#dc2626;font-weight:700';
                ?>
                <tr data-type="<?= $ptype ?>" data-status="<?= $a['status'] ?>">
                    <td><strong><?= $a['full_name'] ?></strong></td>
                    <td><span class="tag <?= $ptype_class ?>"><?= $ptype_label ?></span></td>
                    <td style="<?= $amt_style ?>">
                        <?php if ($is_debt_row): ?>
                            <?= ((float)$a['amount'] < 0 ? '+' : '−') . number_format(abs((float)$a['amount'])) ?> <?= t('ت') ?>
                            <div class="text-muted" style="font-size:9px;font-weight:400"><?= (float)$a['amount'] < 0 ? t('adv_debt_credit') : t('adv_debt_debit') ?></div>
                        <?php else: ?>
                            <?= number_format($a['amount']) ?> <?= t('ت') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $is_debt_row ? '—' : number_format($a['remaining']) . ' ' . t('ت') ?></td>
                    <td><?= $a['reason'] ?: '-' ?></td>
                    <td><span class="tag <?= $label[1] ?>"><?= $label[0] ?></span></td>
                    <td><?= to_jalali($a['status'] === 'paid' && !empty($a['paid_at']) ? $a['paid_at'] : ($a['approved_at'] ?: $a['created_at'])) ?><?= $a['status'] === 'paid' ? '<div class="text-muted" style="font-size:9px">' . t('تاریخ پرداخت') . '</div>' : '' ?></td>
                    <td>
                        <div class="table-actions">
                            <?php if ($is_admin && $a['status'] == 'pending'): ?>
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button class="btn btn-success btn-sm"><?= t('btn_confirm') ?></button>
                            </form>
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button class="btn btn-danger btn-sm"><?= t('btn_reject') ?></button>
                            </form>
                            <?php endif; ?>
                            
                            <?php if ($is_admin && $a['status'] == 'approved'): ?>
                            <button onclick="showPayModal(<?= $a['id'] ?>, <?= $a['amount'] ?>, '<?= $a['full_name'] ?>')" class="btn btn-success btn-sm"><?= t('پرداخت') ?></button>
                            <?php endif; ?>
                            
                            <?php if ($a['status'] == 'paid'): ?>
                            <span class="tag tag-active" style="font-size:10px"><?= t('پرداخت') ?> <?= t('شد') ?> <?= $a['payment_method'] == 'petty_cash' ? '(' . t('تنخواه') . ')' : '(' . t('صندوق') . ')' ?></span>
                            <?php endif; ?>
                            
                            <?php if ($a['status'] == 'approved' && ($a['user_id'] == $_SESSION['user_id'] || $is_admin)): ?>
                            <button onclick="addExpense(<?= $a['id'] ?>)" class="btn btn-info btn-sm"><?= t('ثبت') ?> <?= t('pet_expense') ?></button>
                            <?php endif; ?>

                            <?php if ($is_admin || ($a['user_id'] == $_SESSION['user_id'] && $a['status'] == 'pending')): ?>
                            <button onclick='editAdvance(<?= js_arg(["id" => $a["id"], "amount" => (float)$a["amount"], "remaining" => (float)$a["remaining"], "reason" => $a["reason"] ?? "", "name" => $a["full_name"], "status" => $a["status"], "paid_date" => !empty($a["paid_at"]) ? to_jalali(substr($a["paid_at"], 0, 10)) : ""]) ?>)' class="btn btn-ghost btn-sm" title="<?= t('btn_edit') ?>">✏️</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('این') ?> <?= t('sal_advance') ?> <?= t('chat_delete') ?>')"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="delete_advance">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button class="btn btn-ghost btn-sm" style="color:var(--danger)" title="<?= t('btn_delete') ?>">🗑️</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($advances)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:40px"><?= t('رکوردی') ?> <?= t('ثبت نشده') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Request Modal -->
<div id="requestModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('adv_request') ?></h3><button onclick="closeModal('requestModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <div class="modal-body">
                <?php if ($is_admin): ?>
                <div class="form-group">
                    <label class="form-label"><?= t('wh_name') ?> <?= t('نیرو') ?> *</label>
                    <select name="user_id" class="form-control" required>
                        <?php foreach ($personnel as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $_SESSION['user_id'] ? 'selected' : '' ?>><?= $p['full_name'] ?> <?= $p['is_karigar'] ? t('(کارگر)') : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?= t('ثبت برای دیگران، خودکار تأیید می‌شود') ?></small>
                </div>
                <?php endif; ?>
                <div class="form-group"><label class="form-label"><?= t('adv_amount') ?> *</label><input type="number" name="amount" class="form-control" required min="0"></div>
                <div class="form-group"><label class="form-label"><?= t('adv_reason') ?></label><textarea name="reason" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_submit') ?></button></div>
        </form>
    </div>
</div>

<!-- Edit Advance Modal -->
<div id="editAdvanceModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title">✏️ <?= t('btn_edit') ?> <?= t('sal_advance') ?></h3><button onclick="closeModal('editAdvanceModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit_advance">
            <input type="hidden" name="id" id="edit_adv_id">
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:12px"><?= t('btn_edit') ?> <?= t('برای:') ?> <strong id="edit_adv_name"></strong><br><?= t('pet_expense') ?>‌<?= t('های') ?> <?= t('ثبت') ?><?= t('‌شده') ?> <?= t('حفظ') ?> <?= t('می‌') ?><?= t('شوند') ?> <?= t('و') ?> <?= t('pet_balance') ?> <?= t('خودکار') ?> <?= t('wh_open') ?><?= t('btn_calculate') ?> <?= t('می‌شود') ?>.</p>
                <div class="form-group"><label class="form-label"><?= t('adv_amount') ?> *</label><input type="number" name="amount" id="edit_adv_amount" class="form-control" required min="0"></div>
                <div class="form-group"><label class="form-label"><?= t('adv_reason') ?></label><textarea name="reason" id="edit_adv_reason" class="form-control" rows="2"></textarea></div>
                <div class="form-group" id="editPaidDateWrap" style="display:none"><label class="form-label"><?= t('wh_date') ?> <?= t('پرداخت') ?></label><input type="text" name="paid_date" id="edit_adv_paid_date" class="form-control jalali-date" placeholder="<?= t('date_placeholder') ?>"><div class="text-muted" style="font-size:10px;margin-top:3px"><?= t('تغییر') ?> <?= t('این') ?> <?= t('تاریخ،') ?> <?= t('ماهِ') ?> <?= t('کسر') ?> <?= t('در') ?> <?= t('nav_salary') ?> <?= t('و') ?> <?= t('پرداخت') ?> <?= t('تنخواهِ') ?> <?= t('لینک‌') ?><?= t('شده') ?> <?= t('را') ?> <?= t('هم') ?> <?= t('اصلاح') ?> <?= t('می‌کند') ?>.</div></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button></div>
        </form>
    </div>
</div>

<!-- Debt Registration Modal -->
<div id="debtModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:500px">
        <div class="modal-header"><h3 class="modal-title">📌 <?= t('btn_register_debt') ?></h3><button onclick="closeModal('debtModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="register_debt">
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:12px"><?= t('adv_debt_desc') ?></p>
                <div class="form-group">
                    <label class="form-label"><?= t('wh_name') ?> <?= t('نیرو') ?> *</label>
                    <select name="user_id" class="form-control" required>
                        <option value=""><?= t('انتخاب کنید') ?></option>
                        <?php foreach ($personnel as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= $p['full_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('adv_debt_dir_label') ?> *</label>
                    <div class="d-flex gap-2" style="flex-wrap:wrap">
                        <label class="form-check" style="flex:1;min-width:190px;border:1px solid var(--border);border-radius:8px;padding:8px 10px;cursor:pointer">
                            <input type="radio" name="balance_dir" value="credit" checked onchange="debtDirHint()">
                            <span style="color:#16a34a;font-weight:600">＋ <?= t('adv_debt_credit') ?></span>
                        </label>
                        <label class="form-check" style="flex:1;min-width:190px;border:1px solid var(--border);border-radius:8px;padding:8px 10px;cursor:pointer">
                            <input type="radio" name="balance_dir" value="debit" onchange="debtDirHint()">
                            <span style="color:#dc2626;font-weight:600">− <?= t('adv_debt_debit') ?></span>
                        </label>
                    </div>
                    <div id="debtDirHint" class="text-muted" style="font-size:11px;margin-top:6px"><?= t('adv_debt_credit_hint') ?></div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('مبلغ') ?> (<?= t('sal_toman') ?>) *</label><input type="number" name="amount" class="form-control" required min="1" step="1" placeholder="<?= t('مثال') ?>: 10000000"></div>
                <div class="form-group"><label class="form-label"><?= t('adv_debt_date') ?> *</label><input type="text" name="effective_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required></div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?> (<?= t('adv_reason') ?>) *</label><textarea name="reason" class="form-control" rows="2" required placeholder="<?= t('مثال') ?>: <?= t('مانده') ?> <?= t('حساب') ?> <?= t('rep_month') ?>‌<?= t('های') ?> <?= t('قبل') ?>"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_register_debt') ?></button></div>
        </form>
    </div>
</div>

<!-- Manual Payment Modal -->
<div id="manualPaymentModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:520px">
        <div class="modal-header"><h3 class="modal-title" id="manualPaymentTitle"><?= t('پرداخت') ?></h3><button onclick="closeModal('manualPaymentModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="manual_payment">
            <input type="hidden" name="payment_type" id="manual_payment_type">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('wh_name') ?> <?= t('نیرو') ?> *</label>
                    <select name="user_id" class="form-control" required>
                        <option value=""><?= t('انتخاب کنید') ?></option>
                        <?php foreach ($personnel as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= $p['full_name'] ?> <?= $p['is_karigar'] ? t('(کارگر)') : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('adv_amount') ?> *</label>
                        <input type="number" name="amount" class="form-control" required min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('wh_date') ?> <?= t('پرداخت') ?></label>
                        <input type="text" name="pay_date" class="form-control jalali-date" value="<?= jalali_now() ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('روش پرداخت') ?> *</label>
                    <div class="d-flex gap-3">
                        <label class="form-check" style="flex:1;padding:12px;background:var(--bg);border-radius:10px;border:2px solid var(--border);cursor:pointer;text-align:center">
                            <input type="radio" name="payment_method" value="fund" checked style="display:none">
                            <i data-lucide="landmark" style="width:24px;height:24px;color:var(--success);margin-bottom:4px;display:block;margin:0 auto 4px"></i>
                            <strong><?= t('صندوق') ?></strong>
                        </label>
                        <label class="form-check" style="flex:1;padding:12px;background:var(--bg);border-radius:10px;border:2px solid var(--border);cursor:pointer;text-align:center">
                            <input type="radio" name="payment_method" value="petty_cash" style="display:none">
                            <i data-lucide="wallet" style="width:24px;height:24px;color:var(--warning);margin-bottom:4px;display:block;margin:0 auto 4px"></i>
                            <strong><?= t('nav_petty_cash') ?></strong>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('wor_description') ?></label>
                    <textarea name="reason" class="form-control" rows="2" placeholder="<?= t('اختیاری') ?>"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('ثبت') ?> <?= t('پرداخت') ?></button>
                <button type="button" onclick="closeModal('manualPaymentModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Pay Modal (for approved advances) -->
<div id="payModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:430px">
        <div class="modal-header"><h3 class="modal-title"><?= t('پرداخت') ?> <?= t('nav_advances') ?></h3><button onclick="closeModal('payModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="id" id="pay_id">
            <div class="modal-body" style="text-align:center">
                <p style="margin-bottom:8px"><?= t('پرداخت') ?> <?= t('به:') ?> <strong id="pay_name"></strong></p>
                <p style="margin-bottom:16px;font-size:22px;font-weight:800;color:var(--primary)"><span id="pay_amount"></span> <?= t('sal_toman') ?></p>
                <div class="form-group" style="text-align:right"><label class="form-label"><?= t('wh_date') ?> <?= t('پرداخت') ?> *</label><input type="text" name="pay_date" id="approvedPayDate" class="form-control jalali-date" value="<?= jalali_now() ?>" required></div>
                <p class="text-muted" style="margin-bottom:12px;font-size:12px"><?= t('روش پرداخت') ?> <?= t('را') ?> <?= t('انتخاب') ?> <?= t('کنید') ?>؛ <?= t('پرداخت') ?> <?= t('دقیقاً') ?> <?= t('با') ?> <?= t('همین') ?> <?= t('wh_date') ?> <?= t('در') ?> <?= t('nav_salary') ?> <?= t('ثبت') ?> <?= t('می‌شود') ?>.</p>
            </div>
            <div class="modal-footer" style="justify-content:center">
                <button class="btn btn-success" name="action" value="pay_fund" style="padding:12px 20px"><i data-lucide="landmark" style="width:20px;height:20px"></i> <?= t('صندوق') ?></button>
                <button class="btn btn-warning" name="action" value="pay_petty" style="padding:12px 20px"><i data-lucide="wallet" style="width:20px;height:20px"></i> <?= t('nav_petty_cash') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Expense Modal -->
<div id="expenseModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('ثبت') ?> <?= t('pet_expense') ?></h3><button onclick="closeModal('expenseModal')" class="modal-close">✕</button></div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="add_expense">
            <input type="hidden" name="advance_id" id="expense_advance_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('adv_amount') ?> *</label><input type="number" name="amount" class="form-control" required></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="expense_date" class="form-control" value="<?= jalali_now() ?>"></div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('wh_hours') ?></label><input type="time" name="expense_time" class="form-control"></div>
                <div class="form-group"><label class="form-label"><?= t('توضیح *') ?></label><input type="text" name="description" class="form-control" required></div>
                <div class="form-group"><label class="form-label"><?= t('عکس') ?> <?= t('رسید') ?></label><input type="file" name="receipt" class="form-control" accept="image/*"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('ثبت') ?></button></div>
        </form>
    </div>
</div>

<script>
function debtDirHint() {
    var el = document.getElementById('debtDirHint');
    if (!el) return;
    var sel = document.querySelector('input[name="balance_dir"]:checked');
    var isCredit = !sel || sel.value === 'credit';
    el.textContent = isCredit
        ? <?= json_encode(t('adv_debt_credit_hint'), JSON_UNESCAPED_UNICODE) ?>
        : <?= json_encode(t('adv_debt_debit_hint'), JSON_UNESCAPED_UNICODE) ?>;
    el.style.color = isCredit ? '#16a34a' : '#dc2626';
}

function openManualPayment(type) {
    document.getElementById('manual_payment_type').value = type;
    document.getElementById('manualPaymentTitle').textContent = type == 'salary' ? '<?= t('adv_pay_salary') ?>' : '<?= t('adv_pay_advance') ?>';
    lucide.createIcons();
    openModal('manualPaymentModal');
}

function showPayModal(id, amount, name) {
    document.getElementById('pay_id').value = id;
    document.getElementById('pay_name').textContent = name;
    document.getElementById('pay_amount').textContent = Number(amount).toLocaleString();
    document.getElementById('approvedPayDate').value = '<?= jalali_now() ?>';
    openModal('payModal');
}

function addExpense(id) {
    document.getElementById('expense_advance_id').value = id;
    openModal('expenseModal');
}

function editAdvance(a) {
    document.getElementById('edit_adv_id').value = a.id;
    document.getElementById('edit_adv_amount').value = a.amount;
    document.getElementById('edit_adv_reason').value = a.reason;
    document.getElementById('edit_adv_name').textContent = a.name + ' (<?= t('pet_balance') ?> <?= t('فعلی') ?>: ' + Number(a.remaining).toLocaleString() + ' <?= t('ت') ?>)';
    var paidWrap = document.getElementById('editPaidDateWrap');
    paidWrap.style.display = a.status === 'paid' ? '' : 'none';
    document.getElementById('edit_adv_paid_date').value = a.paid_date || '';
    openModal('editAdvanceModal');
}

function switchTab(btn, filter) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    
    document.querySelectorAll('tbody tr[data-type]').forEach(tr => {
        var type = tr.dataset.type;
        var status = tr.dataset.status;
        var show = false;
        
        if (filter === 'all') show = true;
        else if (filter === 'advance') show = type === 'advance';
        else if (filter === 'salary') show = type === 'salary';
        else if (filter === 'pending') show = status === 'pending' || status === 'approved';
        else if (filter === 'paid') show = status === 'paid';
        
        tr.style.display = show ? '' : 'none';
    });
}

// Payment method radio styling
document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('input[name="payment_method"]').forEach(r => {
            r.closest('label').style.borderColor = 'var(--border)';
            r.closest('label').style.background = 'var(--bg)';
        });
        this.closest('label').style.borderColor = 'var(--primary)';
        this.closest('label').style.background = 'rgba(99,102,241,0.08)';
    });
});
// Init
var firstRadio = document.querySelector('input[name="payment_method"]:checked');
if (firstRadio) { firstRadio.closest('label').style.borderColor = 'var(--primary)'; firstRadio.closest('label').style.background = 'rgba(99,102,241,0.08)'; }
</script>

<?php include 'includes/footer.php'; ?>
