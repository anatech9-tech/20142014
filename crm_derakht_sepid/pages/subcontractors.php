<?php
$pageTitle = t('nav_subcontractors');
$ws_id = $_SESSION['workshop_id'] ?? 0;
require_once __DIR__ . '/../includes/subcontractor_link.php';

// §v12 §4.8/§4.7: مانده حساب پیمانکار تا تاریخ مشخص در includes/subcontractor_link.php تعریف شده است
// (نسخهٔ قدیمیِ همین تابع که قبلاً اینجا کپی شده بود، حذف شد — هرگز اجرا نمی‌شد)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        try {
            $stmt = $pdo->prepare("INSERT INTO subcontractors (workshop_id, name, company, phone, description, total_contract) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ws_id, sanitize($_POST['name']), sanitize($_POST['company'] ?? ''), sanitize($_POST['phone'] ?? ''), sanitize($_POST['description'] ?? ''), (float)($_POST['total_contract'] ?? 0)]);
            $new_sub_id = (int)$pdo->lastInsertId();
            // Update with optional dates if columns exist
            try {
                $stmt2 = $pdo->prepare("UPDATE subcontractors SET start_date=?, end_date=? WHERE id=?");
                $stmt2->execute([to_gregorian($_POST['start_date'] ?? ''), to_gregorian($_POST['end_date'] ?? ''), $new_sub_id]);
            } catch (Exception $e) {}
            // §v12 §4.7/§4.9: شماره شبا + حساب قبلی (مانده اول دوره — علامت‌دار: + طلب پیمانکار از ما، − طلب ما از پیمانکار)
            try {
                $op_amt = abs((float)($_POST['opening_amount'] ?? 0));
                $op_signed = (($_POST['opening_dir'] ?? 'sub') === 'us') ? -$op_amt : $op_amt;
                $stmt2 = $pdo->prepare("UPDATE subcontractors SET iban=?, opening_balance=? WHERE id=?");
                $stmt2->execute([mb_substr(sanitize($_POST['iban'] ?? ''), 0, 34), $op_signed, $new_sub_id]);
            } catch (Exception $e) {}
            // پیوند اختیاری با پرسنل: حقوق ساعتی این شخص در ماندهٔ پیمانکار جمع می‌شود.
            try {
                $linked_user_id = (int)($_POST['linked_user_id'] ?? 0) ?: null;
                $pdo->prepare("UPDATE subcontractors SET linked_user_id = ? WHERE id = ? AND workshop_id = ?")
                    ->execute([$linked_user_id, $new_sub_id, $ws_id]);
            } catch (Exception $e) {}
        } catch (Exception $e) {
            showToast(t('خطا در افزودن پیمانکار: ') . $e->getMessage(), 'error');
            redirect("index.php?page=subcontractors");
        }
        // سرویس‌های اولیه (§9.1)
        try {
            $new_sid = $new_sub_id ?? 0;
            $names = $_POST['svc_name'] ?? [];
            $units = $_POST['svc_unit'] ?? [];
            $prices = $_POST['svc_price'] ?? [];
            foreach ($names as $ix => $nm) {
                $nm = trim((string)$nm);
                if ($nm === '') continue;
                $pdo->prepare("INSERT INTO subcontractor_services (workshop_id, subcontractor_id, service_name, unit_name, unit_price) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$ws_id, $new_sid, sanitize($nm), sanitize($units[$ix] ?? 'عدد'), (float)($prices[$ix] ?? 0)]);
            }
        } catch (Exception $e) {}
        showToast(t('پیمانکار اضافه شد'));
        redirect("index.php?page=subcontractors");
    }
    
    if ($action === 'update_sub') {
        $stmt = $pdo->prepare("UPDATE subcontractors SET name=?, company=?, phone=?, description=?, total_contract=? WHERE id=? AND workshop_id=?");
        $stmt->execute([sanitize($_POST['name']), sanitize($_POST['company'] ?? ''), sanitize($_POST['phone'] ?? ''), sanitize($_POST['description'] ?? ''), (float)($_POST['total_contract'] ?? 0), (int)$_POST['sub_id'], $ws_id]);
        // §v12 §4.7/§4.9: شماره شبا + حساب قبلی
        try {
            $op_amt = abs((float)($_POST['opening_amount'] ?? 0));
            $op_signed = (($_POST['opening_dir'] ?? 'sub') === 'us') ? -$op_amt : $op_amt;
            $pdo->prepare("UPDATE subcontractors SET iban=?, opening_balance=? WHERE id=?")
                ->execute([mb_substr(sanitize($_POST['iban'] ?? ''), 0, 34), $op_signed, (int)$_POST['sub_id']]);
        } catch (Exception $e) {}
        try {
            $linked_user_id = (int)($_POST['linked_user_id'] ?? 0) ?: null;
            $pdo->prepare("UPDATE subcontractors SET linked_user_id = ? WHERE id = ? AND workshop_id = ?")
                ->execute([$linked_user_id, (int)$_POST['sub_id'], $ws_id]);
        } catch (Exception $e) {}
        showToast(t('بروزرسانی شد'));
        redirect("index.php?page=subcontractors" . ((int)($_POST['return_sub'] ?? 0) > 0 ? '&sub=' . (int)$_POST['return_sub'] : ''));
    }

    // §v12 §4.2: حذف پیمانکار — بدون سابقه: حذف کامل / با سابقه: غیرفعال‌سازی (حذف نرم)
    if ($action === 'delete_sub') {
        if (!has_permission('manage_subcontractors')) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=subcontractors"); exit; }
        $sub_id = (int)($_POST['sub_id'] ?? 0);
        if ($sub_id) {
            $nm = $pdo->prepare("SELECT name FROM subcontractors WHERE id = ? AND workshop_id = ?");
            $nm->execute([$sub_id, $ws_id]);
            $sub_name = $nm->fetchColumn() ?: 'پیمانکار';
            $hist = $pdo->prepare("SELECT
                (SELECT COUNT(*) FROM subcontractor_face_items WHERE subcontractor_id = ?)
              + (SELECT COUNT(*) FROM subcontractor_daily_entries WHERE subcontractor_id = ?)
              + (SELECT COUNT(*) FROM subcontractor_payments WHERE subcontractor_id = ?)");
            $hist->execute([$sub_id, $sub_id, $sub_id]);
            $has_history = (int)$hist->fetchColumn() > 0;
            if (!$has_history) {
                $pdo->prepare("DELETE FROM subcontractor_services WHERE subcontractor_id = ?")->execute([$sub_id]);
                $pdo->prepare("DELETE FROM subcontractors WHERE id = ? AND workshop_id = ?")->execute([$sub_id, $ws_id]);
                $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'delete', 'subcontractor', ?, ?)")
                    ->execute([$_SESSION['user_id'], $sub_id, 'حذف کامل پیمانکار «' . $sub_name . '» (بدون سابقه)']);
                showToast(t('پیمانکار «') . $sub_name . '» حذف شد');
            } else {
                $pdo->prepare("UPDATE subcontractors SET status = 'inactive' WHERE id = ? AND workshop_id = ?")->execute([$sub_id, $ws_id]);
                $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'deactivate', 'subcontractor', ?, ?)")
                    ->execute([$_SESSION['user_id'], $sub_id, 'غیرفعال‌سازی پیمانکار «' . $sub_name . '» (دارای سابقه — سوابق حفظ شد)']);
                showToast('«' . $sub_name . '» ' . t('سابقه مالی دارد — غیرفعال شد و سوابقش حفظ می‌ماند'));
            }
        }
        redirect("index.php?page=subcontractors");
    }
    
    // بازمحاسبه total_paid پیمانکار از روی جمع پرداخت‌هایش (قبل از هندلرها تعریف شود)
    if (!function_exists('sub_sync_total_paid')) {
        function sub_sync_total_paid(PDO $pdo, $sub_id) {
            $pdo->prepare("UPDATE subcontractors s SET s.total_paid = COALESCE((SELECT SUM(amount) FROM subcontractor_payments p WHERE p.subcontractor_id = ?), 0) WHERE s.id = ?")
                ->execute([$sub_id, $sub_id]);
        }
    }

    if ($action === 'payment') {
        $pay_source = $_POST['pay_source'] ?? 'fund';
        try { $attachment = operations_upload_file($_FILES['attachment'] ?? [], 'uploads/subcontractor_docs', ['image/png'=>'png','image/jpeg'=>'jpg','application/pdf'=>'pdf'], 12_000_000); } catch (Throwable $e) { showToast($e->getMessage(), 'error'); redirect("index.php?page=subcontractors"); }
        // §v12 §4.6: روش پرداخت + شماره پیگیری + رسید
        $stmt = $pdo->prepare("INSERT INTO subcontractor_payments (subcontractor_id, amount, description, payment_date, created_by, source, method, tracking_no, attachment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([(int)$_POST['sub_id'], (float)$_POST['amount'], sanitize($_POST['description'] ?? ''), to_gregorian($_POST['payment_date']), $_SESSION['user_id'], $pay_source, sanitize($_POST['method'] ?? ''), sanitize($_POST['tracking_no'] ?? ''), $attachment]);
        $stmt = $pdo->prepare("UPDATE subcontractors SET total_paid = total_paid + ? WHERE id = ?");
        $stmt->execute([(float)$_POST['amount'], (int)$_POST['sub_id']]);
        // If paid from petty cash, register expense
        if ($pay_source === 'tanvah') {
            // Get subcontractor info for better description
            $sub_stmt = $pdo->prepare("SELECT name, company FROM subcontractors WHERE id = ?");
            $sub_stmt->execute([(int)$_POST['sub_id']]);
            $sub_info = $sub_stmt->fetch();
            $sub_name = $sub_info ? sanitize($sub_info['name']) : t('نامشخص');
            $sub_company = $sub_info && $sub_info['company'] ? sanitize($sub_info['company']) : '';
            $sub_text = $sub_name . ($sub_company ? ' از ' . $sub_company : '');
            $stmt = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ws_id, 'expense', (float)$_POST['amount'], 'پرداخت پیمانکار (' . $sub_text . '): ' . sanitize($_POST['description'] ?? ''), to_gregorian($_POST['payment_date']), $_SESSION['user_id']]);
        }
        // بازمحاسبه دقیق total_paid از روی جمع پرداخت‌ها
        sub_sync_total_paid($pdo, (int)$_POST['sub_id']);
        showToast(t('پرداخت ثبت شد'));
        redirect("index.php?page=subcontractors");
    }

    // ── ویرایش پرداخت پیمانکار ──
    if ($action === 'edit_payment' && has_permission('manage_subcontractors')) {
        $pid = (int)$_POST['payment_id'];
        $new_amount = (float)$_POST['amount'];
        $new_source = ($_POST['pay_source'] ?? 'fund') === 'tanvah' ? 'tanvah' : 'fund';
        $st = $pdo->prepare("SELECT * FROM subcontractor_payments WHERE id = ?");
        $st->execute([$pid]);
        $old = $st->fetch();
        if ($old) {
            $pdo->prepare("UPDATE subcontractor_payments SET amount = ?, description = ?, payment_date = ?, source = ?, method = ?, tracking_no = ? WHERE id = ?")
                ->execute([$new_amount, sanitize($_POST['description'] ?? ''), to_gregorian($_POST['payment_date']), $new_source, sanitize($_POST['method'] ?? ''), sanitize($_POST['tracking_no'] ?? ''), $pid]);
            sub_sync_total_paid($pdo, (int)$old['subcontractor_id']);
            $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'edit', 'subcontractor_payment', ?, ?)")
                ->execute([$_SESSION['user_id'], $pid, 'ویرایش پرداخت: ' . number_format($old['amount']) . ' ← ' . number_format($new_amount)]);
            showToast(t('پرداخت بروزرسانی شد'));
        } else showToast(t('پرداخت یافت نشد'), 'error');
        redirect("index.php?page=subcontractors&sub=" . (int)$_POST['sub_id']);
    }

    // ── حذف پرداخت پیمانکار ──
    if ($action === 'delete_payment' && has_permission('manage_subcontractors')) {
        $pid = (int)$_POST['payment_id'];
        $st = $pdo->prepare("SELECT subcontractor_id, amount FROM subcontractor_payments WHERE id = ?");
        $st->execute([$pid]);
        $old = $st->fetch();
        if ($old) {
            $pdo->prepare("DELETE FROM subcontractor_payments WHERE id = ?")->execute([$pid]);
            sub_sync_total_paid($pdo, (int)$old['subcontractor_id']);
            $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'delete', 'subcontractor_payment', ?, ?)")
                ->execute([$_SESSION['user_id'], $pid, 'حذف پرداخت ' . number_format($old['amount'])]);
            showToast(t('پرداخت حذف شد'));
        }
        redirect("index.php?page=subcontractors&sub=" . (int)$_POST['sub_id']);
    }
    
    // §v12 §4.3: صورت‌وضعیت قدیمی (face_items) حذف شد — داده‌های قبلی در مانده حساب لحاظ می‌مانند
    // — هندلرهای add_face/edit_face/delete_face حذف شدند؛ رکوردهای قدیمی فقط در جمع مطالبات خوانده می‌شوند.

    // §v12 §4.8: تسویه حساب پیمانکار تا تاریخ مشخص
    if ($action === 'settle_sub' && has_permission('manage_subcontractors')) {
        $sid = (int)($_POST['sub_id'] ?? 0);
        $gdate = to_gregorian(sanitize($_POST['settle_date'] ?? ''));
        if ($sid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$gdate)) {
            showToast(t('تاریخ تسویه نامعتبر است'), 'error'); redirect("index.php?page=subcontractors"); exit;
        }
        if ($gdate > date('Y-m-d')) { showToast(t('تاریخ تسویه نمی‌تواند در آینده باشد'), 'error'); redirect("index.php?page=subcontractors&sub=$sid"); exit; }
        $bal = sub_balance_upto($pdo, $sid, $gdate);
        $due = round($bal['balance']);
        if ($due <= 0) {
            showToast(strtr(t('مانده قابل تسویه تا این تاریخ صفر یا منفی است ({amount} تومان)'), ['{amount}' => number_format($due)]), 'error');
            redirect("index.php?page=subcontractors&sub=$sid"); exit;
        }
        $jdate = to_jalali($gdate);
        $pay_source = (($_POST['pay_source'] ?? 'fund') === 'tanvah') ? 'tanvah' : 'fund';
        $method = sanitize($_POST['method'] ?? 'واریز بانکی');
        $tracking = sanitize($_POST['tracking_no'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO subcontractor_payments (subcontractor_id, amount, description, payment_date, created_by, source, method, tracking_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sid, $due, 'تسویه حساب تا تاریخ ' . $jdate, $gdate, $_SESSION['user_id'], $pay_source, $method, $tracking]);
        $new_pid = (int)$pdo->lastInsertId();
        if ($pay_source === 'tanvah') {
            $sub_nm = $pdo->prepare("SELECT name FROM subcontractors WHERE id = ?");
            $sub_nm->execute([$sid]);
            $stmt = $pdo->prepare("INSERT INTO petty_cash (workshop_id, type, amount, description, date, created_by) VALUES (?, 'expense', ?, ?, ?, ?)");
            $stmt->execute([$ws_id, $due, 'تسویه پیمانکار (' . sanitize($sub_nm->fetchColumn() ?: '') . ') تا ' . $jdate, $gdate, $_SESSION['user_id']]);
        }
        sub_sync_total_paid($pdo, $sid);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'settle', 'subcontractor_payment', ?, ?)")
            ->execute([$_SESSION['user_id'], $new_pid, 'تسویه حساب پیمانکار #' . $sid . ' تا ' . $jdate . ': ' . number_format($due) . ' تومان (' . $method . ')']);
        showToast(strtr(t('✅ تسویه حساب تا {date} با مبلغ {amount} تومان ثبت شد'), ['{date}' => $jdate, '{amount}' => number_format($due)]));
        redirect("index.php?page=subcontractors&sub=$sid");
    }

    // ═══ §9: افزودن سرویس هنگام ایجاد پیمانکار (ردیف‌های techdefault[] از مودال) ═══
    if ($action === 'sync_services' && has_permission('manage_subcontractors')) {
        $sid = (int)$_POST['svc_sub_id'];
        $names = $_POST['svc_name'] ?? [];
        $units = $_POST['svc_unit'] ?? [];
        $prices = $_POST['svc_price'] ?? [];
        $ids = $_POST['svc_id'] ?? [];
        foreach ($names as $ix => $nm) {
            $nm = trim((string)$nm);
            if ($nm === '') continue;
            $svid = (int)($ids[$ix] ?? 0);
            $unit = sanitize($units[$ix] ?? 'عدد');
            $price = (float)($prices[$ix] ?? 0);
            if ($svid > 0) {
                $pdo->prepare("UPDATE subcontractor_services SET service_name = ?, unit_name = ?, unit_price = ? WHERE id = ? AND subcontractor_id = ?")
                    ->execute([sanitize($nm), $unit, $price, $svid, $sid]);
            } else {
                $pdo->prepare("INSERT INTO subcontractor_services (workshop_id, subcontractor_id, service_name, unit_name, unit_price) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$ws_id, $sid, sanitize($nm), $unit, $price]);
            }
        }
        showToast(t('خدمات بروزرسانی شد'));
        redirect("index.php?page=subcontractors&sub=$sid");
    }

    if ($action === 'toggle_service' && has_permission('manage_subcontractors')) {
        $svid = (int)$_POST['service_id'];
        $sid = (int)$_POST['sub_id'];
        $pdo->prepare("UPDATE subcontractor_services SET status = IF(status='active','inactive','active') WHERE id = ? AND subcontractor_id = ?")
            ->execute([$svid, $sid]);
        showToast(t('وضعیت خدمت تغییر کرد'));
        redirect("index.php?page=subcontractors&sub=$sid");
    }

    // حذف خدمت فقط در صورت نداشتن سابقه استفاده (§9.5)
    if ($action === 'delete_service' && has_permission('manage_subcontractors')) {
        $svid = (int)$_POST['service_id'];
        $sid = (int)$_POST['sub_id'];
        $used = $pdo->prepare("SELECT COUNT(*) FROM subcontractor_daily_entry_items WHERE service_id = ?");
        $used->execute([$svid]);
        if ((int)$used->fetchColumn() > 0) {
            showToast(t('این خدمت سابقه استفاده دارد — فقط می‌توانید غیرفعالش کنید'), 'error');
        } else {
            $pdo->prepare("DELETE FROM subcontractor_services WHERE id = ? AND subcontractor_id = ?")->execute([$svid, $sid]);
            showToast(t('خدمت حذف شد'));
        }
        redirect("index.php?page=subcontractors&sub=$sid");
    }

    // ═══ §9: ثبت روزانه خدمات (تعداد + شیفت) با اسنپ‌شات قیمت واحد ═══
    if ($action === 'add_daily_entry' && has_permission('manage_subcontractors')) {
        $sid = (int)$_POST['entry_sub_id'];
        $edate = to_gregorian($_POST['entry_date']);
        $shift = ($_POST['shift'] ?? 'day') === 'night' ? 'night' : 'day';
        $qty = $_POST['qty'] ?? [];
        if (!is_array($qty)) $qty = [];

        // لود قیمت‌های لحظه‌ای برای اسنپ‌شات
        $svc_rows = [];
        $stx = $pdo->prepare("SELECT * FROM subcontractor_services WHERE subcontractor_id = ? AND status = 'active'");
        $stx->execute([$sid]);
        foreach ($stx->fetchAll() as $sv) $svc_rows[$sv['id']] = $sv;

        $items = [];
        foreach ($qty as $svid => $q) {
            // پذیرش اعداد فارسی/عربی و جداکننده‌ی اعشار هنگام ورود دستی
            $q = strtr((string)$q, [
                '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4',
                '۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
                '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                '٫'=>'.', '٬'=>'', ','=>'', '،'=>''
            ]);
            $q = (float)$q;
            $svid = (int)$svid;
            if ($q > 0) {
                // ابتدا از لیست بارگذاری‌شده استفاده می‌کنیم؛ در صورت اختلاف داده،
                // خود خدمت را با شناسه‌ی ارسال‌شده دوباره از دیتابیس می‌خوانیم.
                $service = $svc_rows[$svid] ?? null;
                if (!$service) {
                    $st_one = $pdo->prepare("SELECT * FROM subcontractor_services WHERE id = ? AND subcontractor_id = ? AND status = 'active' LIMIT 1");
                    $st_one->execute([$svid, $sid]);
                    $service = $st_one->fetch() ?: null;
                }
                if (!$service) continue;
                $price = (float)$service['unit_price'];
                $items[] = [$svid, $q, $price, round($q * $price)];
            }
        }
        if (empty($items)) {
            showToast(t('حداقل برای یک خدمت تعداد وارد کنید'), 'error');
            redirect("index.php?page=subcontractors&sub=$sid");
        }
        try { $attachment = operations_upload_file($_FILES['attachment'] ?? [], 'uploads/subcontractor_docs', ['image/png'=>'png','image/jpeg'=>'jpg','application/pdf'=>'pdf'], 12_000_000); } catch (Throwable $e) { showToast($e->getMessage(), 'error'); redirect("index.php?page=subcontractors&sub=$sid"); }
        $pdo->prepare("INSERT INTO subcontractor_daily_entries (workshop_id, subcontractor_id, entry_date, shift, description, attachment, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$ws_id, $sid, $edate, $shift, sanitize($_POST['description'] ?? ''), $attachment, $_SESSION['user_id']]);
        $eid = (int)$pdo->lastInsertId();
        $sti = $pdo->prepare("INSERT INTO subcontractor_daily_entry_items (entry_id, service_id, quantity, unit_price_snapshot, total_price) VALUES (?, ?, ?, ?, ?)");
        foreach ($items as $it) $sti->execute([$eid, $it[0], $it[1], $it[2], $it[3]]);
        showToast(t('ثبت روزانه انجام شد'));
        redirect("index.php?page=subcontractors&sub=$sid");
    }

    // ═══ ویرایش ثبت روزانه: تاریخ/شیفت/تعداد خدمات/توضیح/فایل ═══
    // قیمتِ واحدِ آیتم‌های قبلاً ثبت‌شده (اسنپ‌شات) حفظ می‌شود؛ فقط برای خدمتِ تازه‌اضافه‌شده قیمت فعلی استفاده می‌شود.
    if ($action === 'edit_daily_entry' && has_permission('manage_subcontractors')) {
        $eid = (int)($_POST['entry_id'] ?? 0);
        $sid = (int)($_POST['sub_id'] ?? 0);
        $ste_ = $pdo->prepare("SELECT * FROM subcontractor_daily_entries WHERE id = ? AND workshop_id = ?");
        $ste_->execute([$eid, $ws_id]);
        $entry = $ste_->fetch();
        if (!$entry) { showToast(t('رکورد یافت نشد'), 'error'); redirect("index.php?page=subcontractors&sub=$sid"); }
        $sid = (int)$entry['subcontractor_id'];
        $edate = to_gregorian($_POST['entry_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$edate)) { showToast(t('تاریخ نامعتبر است'), 'error'); redirect("index.php?page=subcontractors&sub=$sid"); }
        $shift = ($_POST['shift'] ?? 'day') === 'night' ? 'night' : 'day';
        $qty = $_POST['qty'] ?? [];
        if (!is_array($qty)) $qty = [];

        // آیتم‌های فعلی
        $cur = [];
        $sti_ = $pdo->prepare("SELECT * FROM subcontractor_daily_entry_items WHERE entry_id = ?");
        $sti_->execute([$eid]);
        foreach ($sti_->fetchAll() as $ci) $cur[(int)$ci['service_id']] = $ci;
        // خدمت‌های پیمانکار (برای قیمت فعلی خدمت‌های جدید)
        $svc_rows = [];
        $stx = $pdo->prepare("SELECT * FROM subcontractor_services WHERE subcontractor_id = ?");
        $stx->execute([$sid]);
        foreach ($stx->fetchAll() as $sv) $svc_rows[(int)$sv['id']] = $sv;

        $new_items = [];   // service_id => [qty, price]
        foreach ($qty as $svid => $q) {
            $q = strtr((string)$q, [
                '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                '٫'=>'.', '٬'=>'', ','=>'', '،'=>''
            ]);
            $q = (float)$q; $svid = (int)$svid;
            if ($q <= 0) continue;
            if (isset($cur[$svid])) $price = (float)$cur[$svid]['unit_price_snapshot'];
            elseif (isset($svc_rows[$svid]) && ($svc_rows[$svid]['status'] ?? 'active') === 'active') $price = (float)$svc_rows[$svid]['unit_price'];
            else continue;
            $new_items[$svid] = [$q, $price];
        }
        if (empty($new_items)) {
            showToast(t('حداقل برای یک خدمت تعداد وارد کنید'), 'error');
            redirect("index.php?page=subcontractors&sub=$sid");
        }
        $attachment = $entry['attachment'];
        if (!empty($_FILES['attachment']['name'])) {
            try {
                $up = operations_upload_file($_FILES['attachment'], 'uploads/subcontractor_docs', ['image/png'=>'png','image/jpeg'=>'jpg','application/pdf'=>'pdf'], 12_000_000);
                if ($up) $attachment = $up;
            } catch (Throwable $e) { showToast($e->getMessage(), 'error'); redirect("index.php?page=subcontractors&sub=$sid"); }
        }
        $pdo->prepare("UPDATE subcontractor_daily_entries SET entry_date = ?, shift = ?, description = ?, attachment = ? WHERE id = ? AND workshop_id = ?")
            ->execute([$edate, $shift, sanitize($_POST['description'] ?? ''), $attachment, $eid, $ws_id]);
        // همگام‌سازی آیتم‌ها: حذف، به‌روزرسانی، افزودن
        foreach ($cur as $svid => $ci) {
            if (!isset($new_items[$svid])) $pdo->prepare("DELETE FROM subcontractor_daily_entry_items WHERE id = ?")->execute([$ci['id']]);
        }
        foreach ($new_items as $svid => [$q, $price]) {
            if (isset($cur[$svid])) {
                $pdo->prepare("UPDATE subcontractor_daily_entry_items SET quantity = ?, total_price = ? WHERE id = ?")->execute([$q, round($q * $price), $cur[$svid]['id']]);
            } else {
                $pdo->prepare("INSERT INTO subcontractor_daily_entry_items (entry_id, service_id, quantity, unit_price_snapshot, total_price) VALUES (?, ?, ?, ?, ?)")->execute([$eid, $svid, $q, $price, round($q * $price)]);
            }
        }
        try { $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'update', 'subcontractor_daily_entry', ?, ?)")->execute([$_SESSION['user_id'], $eid, 'ویرایش ثبت روزانه پیمانکار #' . $sid]); } catch (Throwable $e) {}
        showToast(t('ثبت روزانه ویرایش شد'));
        redirect("index.php?page=subcontractors&sub=$sid");
    }

    if ($action === 'delete_daily_entry' && has_permission('manage_subcontractors')) {
        $eid = (int)$_POST['entry_id'];
        $sid = (int)$_POST['sub_id'];
        $pdo->prepare("DELETE FROM subcontractor_daily_entry_items WHERE entry_id = ?")->execute([$eid]);
        $pdo->prepare("DELETE FROM subcontractor_daily_entries WHERE id = ? AND workshop_id = ?")->execute([$eid, $ws_id]);
        showToast(t('ثبت روزانه حذف شد'));
        redirect("index.php?page=subcontractors&sub=$sid");
    }

}


// §v12 §4.8: پیش‌نمایش مبلغ تسویه پیمانکار تا تاریخ (AJAX)
if (isset($_GET['settle_sub_preview'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $r = ['ok' => false, 'amount' => 0, 'jdate' => '', 'error' => ''];
    if (!has_permission('manage_subcontractors')) {
        $r['error'] = t('دسترسی ندارید');
    } else {
        $sid = (int)$_GET['settle_sub_preview'];
        $gdate = to_gregorian($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$gdate)) {
            $r['error'] = t('تاریخ نامعتبر است');
        } elseif ($gdate > date('Y-m-d')) {
            $r['error'] = t('تاریخ تسویه نمی‌تواند در آینده باشد');
        } else {
            $bal = sub_balance_upto($pdo, $sid, $gdate);
            $r['ok'] = true;
            $r['amount'] = round($bal['balance']);
            $r['jdate'] = to_jalali($gdate);
        }
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// §v11: جمع مطالبات (صورت‌وضعیت + ثبت روزانه) برای نمایش مانده درست روی کارت هر پیمانکار
// §v12 §4.7: حساب قبلی (مانده اول دوره) هم در مانده کارت لحاظ می‌شود
$stmt = $pdo->prepare("SELECT s.*, u.full_name AS linked_person_name, u.is_karigar AS linked_person_type,
    COALESCE((SELECT SUM(f.amount) FROM subcontractor_face_items f WHERE f.subcontractor_id = s.id), 0)
  + COALESCE((SELECT SUM(di.total_price) FROM subcontractor_daily_entry_items di JOIN subcontractor_daily_entries de ON di.entry_id = de.id WHERE de.subcontractor_id = s.id), 0) AS total_claims
    FROM subcontractors s LEFT JOIN users u ON u.id = s.linked_user_id
    WHERE s.workshop_id = ? AND s.status = 'active' ORDER BY s.name");
$stmt->execute([$ws_id]);
$subs = $stmt->fetchAll();

// پرسنل همین کارگاه که می‌توانند به یک پیمانکار وصل شوند (از جمله نوع «پیمانکار» در پرسنل).
$linkable_personnel = [];
try {
    $st_link = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar, wp.daily_salary, wp.hourly_salary, wp.monthly_salary
        FROM users u JOIN workshop_personnel wp ON wp.user_id = u.id
        WHERE wp.workshop_id = ? AND wp.status = 'active' AND u.status = 'active' ORDER BY u.full_name");
    $st_link->execute([$ws_id]);
    $linkable_personnel = $st_link->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/salary_calc.php'; // jalali_month_bounds

// §v12 §4.4/§4.5: فیلتر ماهانه جلالی (فقط سال+ماه — کادر «از/تا تاریخ» حذف شد) برای ثبت‌های روزانه
$face_sub = (int)($_GET['sub'] ?? 0);
$show_all = !empty($_GET['all']);
$jy_now = 0; $jm_now = 0;
$__tj = to_jalali(date('Y-m-d'));
if ($__tj) { $__p = explode('/', $__tj); $jy_now = (int)$__p[0]; $jm_now = (int)$__p[1]; }
$fy = isset($_GET['fy']) ? (int)$_GET['fy'] : $jy_now;
$fm = isset($_GET['fm']) ? (int)$_GET['fm'] : $jm_now;
$g_start = null; $g_end = null;
if ($face_sub && !$show_all && $fy > 0 && $fm >= 1 && $fm <= 12) {
    [$g_start, $g_end] = jalali_month_bounds($fy, $fm);
}

// پیمانکار انتخاب‌شده + داده‌های صورت وضعیت
$sel_sub = null;
$sub_services = [];
$daily_entries = [];
$pay_items = [];
$sum_all = ['face' => 0, 'daily' => 0, 'paid' => 0, 'opening' => 0, 'claims' => 0, 'balance' => 0];
if ($face_sub) {
    try {
        $sts = $pdo->prepare("SELECT * FROM subcontractors WHERE id = ? AND workshop_id = ?");
        $sts->execute([$face_sub, $ws_id]);
        $sel_sub = $sts->fetch() ?: null;
    } catch (Exception $e) { $sel_sub = null; }
    try {
        $sts = $pdo->prepare("SELECT * FROM subcontractor_services WHERE subcontractor_id = ? ORDER BY id");
        $sts->execute([$face_sub]);
        $sub_services = $sts->fetchAll();
    } catch (Exception $e) { $sub_services = []; }

    try {
        $ew = "e.subcontractor_id = ?";
        $ep = [$face_sub];
        if ($g_start) { $ew .= " AND e.entry_date BETWEEN ? AND ?"; $ep[] = $g_start; $ep[] = $g_end; }
        $ste = $pdo->prepare("SELECT e.*, u.full_name AS creator_name FROM subcontractor_daily_entries e LEFT JOIN users u ON u.id = e.created_by WHERE $ew ORDER BY e.entry_date DESC, e.shift, e.id DESC LIMIT 300");
        $ste->execute($ep);
        $daily_entries = $ste->fetchAll();
        if ($daily_entries) {
            $ids = implode(',', array_map('intval', array_column($daily_entries, 'id')));
            $sti = $pdo->query("SELECT i.*, s.service_name, s.unit_name FROM subcontractor_daily_entry_items i JOIN subcontractor_services s ON s.id = i.service_id WHERE i.entry_id IN ($ids)");
            $items_by_entry = [];
            foreach ($sti->fetchAll() as $ii) $items_by_entry[$ii['entry_id']][] = $ii;
            foreach ($daily_entries as &$en) {
                $en['items'] = $items_by_entry[$en['id']] ?? [];
                $en['total'] = array_sum(array_column($en['items'], 'total_price'));
            }
            unset($en);
        }
    } catch (Exception $e) { $daily_entries = []; }

    // پرداخت‌ها (همه دوره‌ها — تاریخچه کامل حساب)
    try {
        $pay_stmt = $pdo->prepare("SELECT * FROM subcontractor_payments WHERE subcontractor_id = ? ORDER BY payment_date DESC, id DESC LIMIT 200");
        $pay_stmt->execute([$face_sub]);
        $pay_items = $pay_stmt->fetchAll();
    } catch (Exception $e) { $pay_items = []; }

    // §v12: جمع کل حساب (همه دوره‌ها) برای خلاصه/کارت مانده — مستقل از فیلتر ماه
    $sum_all = sub_balance_upto($pdo, $face_sub, null);
}
$linked_person = $sel_sub ? subcontractor_linked_person($pdo, (int)$face_sub) : null;
$linked_period = ($linked_person && $g_start && $g_end) ? subcontractor_linked_person_period($pdo, $linked_person, $g_start, $g_end) : null;

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_subcontractors') ?></h2>
    <?php if (has_permission('manage_subcontractors')): ?>
    <button onclick="openModal('addModal')" class="btn btn-primary"><i data-lucide="plus"></i> <?= t('btn_add') ?></button>
    <?php endif; ?>
</div>

<div class="grid-3 animate-in">
    <?php foreach ($subs as $s):
        $__card_summary = sub_balance_upto($pdo, (int)$s['id'], null);
        $__card_bal = (float)$__card_summary['balance'];
    ?>
    <a href="index.php?page=subcontractors&sub=<?= $s['id'] ?>" class="workshop-card" style="background:var(--surface);border-radius:var(--radius);padding:22px;border:1px solid var(--border);text-decoration:none;color:var(--text);display:block;box-shadow:var(--shadow);transition:all 0.3s;">
        <?php if ($s['logo']): ?>
            <img src="<?= $s['logo'] ?>" alt="<?= $s['name'] ?>" style="width:80px;height:80px;border-radius:16px;object-fit:cover;margin-bottom:15px;">
        <?php else: ?>
            <div style="width:80px;height:80px;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-size:32px;font-weight:800;margin:0 auto 15px;">
                <?= mb_substr($s['name'], 0, 1) ?>
            </div>
        <?php endif; ?>
        <h3><?= $s['name'] ?></h3>
        <p class="text-muted"><?= $s['company'] ?: '-' ?> | <?= $s['phone'] ?: '-' ?></p>
        <div class="mt-3">
            <div class="d-flex justify-between"><span><?= t('قرارداد:') ?></span><strong><?= $s['total_contract'] > 0 ? number_format($s['total_contract']) . ' ت' : '-' ?></strong></div>
            <div class="d-flex justify-between"><span><?= t('پرداختی') ?>:</span><strong class="text-success"><?= number_format($s['total_paid']) ?> <?= t('ت') ?></strong></div>
            <div class="d-flex justify-between"><span><?= t('ماندهٔ') ?> <?= t('تجمیعی') ?>:</span><strong class="<?= $__card_bal >= 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($__card_bal) ?> <?= t('ت') ?></strong></div>
            <?php if ((float)$s['total_contract'] > 0): $__contract_pct = ((float)$__card_summary['claims'] / (float)$s['total_contract']) * 100; ?>
            <div style="font-size:10px;margin-top:5px;color:<?= $__contract_pct >= 100 ? '#dc2626' : ($__contract_pct >= 80 ? '#b45309' : '#64748b') ?>">📊 <?= t('مصرف') ?> <?= t('سقف قرارداد') ?>: <?= number_format($__contract_pct,1) ?>٪ <?= $__contract_pct >= 100 ? '— ' . t('سقف قرارداد عبور شده!') : ($__contract_pct >= 80 ? '— ' . t('نزدیک سقف قرارداد') : '') ?></div>
            <?php endif; ?>
            <?php if (!empty($s['opening_balance']) && $s['opening_balance'] != 0): ?>
            <div class="d-flex justify-between text-muted" style="font-size:11px"><span><?= t('حساب') ?> <?= t('قبلی') ?>:</span><strong><?= number_format($s['opening_balance']) ?> <?= t('ت') ?> <?= $s['opening_balance'] < 0 ? '<span class="text-danger">(' . t('طلب ما') . ')</span>' : '' ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($s['iban'])): ?>
            <div class="d-flex justify-between text-muted" style="font-size:11px"><span><?= t('🏦 شبا:') ?></span><strong style="direction:ltr"><?= $s['iban'] ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($s['linked_person_name'])): ?>
            <div class="d-flex justify-between" style="font-size:11px;color:#7c3aed"><span>🔗 <?= t('nav_personnel') ?> <?= t('متصل') ?>:</span><strong><?= htmlspecialchars($s['linked_person_name']) ?></strong></div>
            <div class="d-flex justify-between text-muted" style="font-size:10px"><span><?= t('کارکرد') ?> <?= t('ساعتیِ') ?> <?= t('تجمیعی') ?>:</span><strong><?= number_format((float)$__card_summary['personnel_claim']) ?> <?= t('ت') ?></strong></div>
            <?php endif; ?>
            <?php if ($s['start_date'] || $s['end_date']): ?>
            <div class="mt-2 text-muted" style="font-size:11px"><?= t('wh_start') ?>: <?= $s['start_date'] ? to_jalali($s['start_date']) : '-' ?> | <?= t('پایان:') ?> <?= $s['end_date'] ? to_jalali($s['end_date']) : '-' ?></div>
            <?php endif; ?>
        </div>
        <?php
        $svc_of = [];
        try {
            $stc = $pdo->prepare("SELECT service_name, unit_price, unit_name, status FROM subcontractor_services WHERE subcontractor_id = ? AND status = 'active' ORDER BY id");
            $stc->execute([$s['id']]);
            $svc_of = $stc->fetchAll();
        } catch (Exception $e2) {}
        if (!empty($svc_of)): ?>
        <div class="mt-2" style="display:flex;flex-wrap:wrap;gap:4px">
            <?php foreach ($svc_of as $sv): ?>
            <span style="font-size:10px;padding:3px 8px;background:var(--bg);border-radius:6px;border:1px solid var(--border)"><?= $sv['service_name'] ?>: <?= number_format((float)$sv['unit_price']) ?>/<?= $sv['unit_name'] ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="mt-3">
            <div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                <div style="height:100%;width:<?= ($s['total_contract'] > 0 ? min(100, ($s['total_paid']/$s['total_contract'])*100) : 0) ?>%;background:var(--success);border-radius:3px"></div>
            </div>
        </div>
        <?php if (has_permission('manage_subcontractors')): ?>
        <!-- §v12 §4.1: دکمه‌های تکراری (ویرایش/مطالبه/روزانه/پرداخت) از کارت حذف شدند — همه از داخل صورت وضعیت انجام می‌شوند -->
        <!-- §v12 §4.2: حذف پیمانکار (بدون سابقه: کامل / با سابقه: غیرفعال) -->
        <button onclick="event.preventDefault(); event.stopPropagation(); deleteSub(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($s['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)" class="btn btn-ghost btn-sm btn-block mt-2" style="color:var(--danger)">🗑 <?= t('btn_delete') ?> <?= t('پیمانکار') ?></button>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('پیمانکار') ?> <?= t('جدید') ?></h3><button onclick="closeModal('addModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_name') ?> *</label><input type="text" name="name" class="form-control" required></div>
                    <div class="form-group"><label class="form-label"><?= t('شرکت') ?></label><input type="text" name="company" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('per_phone') ?></label><input type="text" name="phone" class="form-control"></div>
                    <div class="form-group"><label class="form-label"><?= t('مبلغ') ?> <?= t('قرارداد') ?> (<?= t('sal_toman') ?>)</label><input type="number" name="total_contract" class="form-control" min="0"></div>
                </div>
                <div class="form-group"><label class="form-label">🔗 <?= t('اتصال') ?> <?= t('به') ?> <?= t('nav_personnel') ?> (<?= t('اختیاری') ?>)</label>
                    <select name="linked_user_id" class="form-control"><option value=""><?= t('بدون اتصال — فقط') ?> <?= t('sub_services') ?></option><?php foreach ($linkable_personnel as $lp): ?><option value="<?= (int)$lp['id'] ?>"><?= htmlspecialchars($lp['full_name']) ?> — <?= $lp['is_karigar'] == 2 ? t('پیمانکار پرسنلی') : ($lp['is_karigar'] == 1 ? t('sal_worker') : t('sal_employee')) ?><?= $lp['is_karigar'] == 2 && (float)($lp['hourly_salary'] ?? 0) > 0 ? ' | ' . t('ساعتی') . ' ' . number_format($lp['hourly_salary']) : ((float)$lp['daily_salary'] > 0 ? ' | ' . t('روزانه') . ' ' . number_format($lp['daily_salary']) : ((float)$lp['monthly_salary'] > 0 ? ' | ' . t('ماهانه') . ' ' . number_format($lp['monthly_salary']) : '')) ?></option><?php endforeach; ?></select>
                    <div class="text-muted" style="font-size:10px;margin-top:4px"><?= t('با') ?> <?= t('اتصال') ?>، <?= t('کارکرد ساعتی') ?> <?= t('این') ?> <?= t('شخص') ?> <?= t('lea_from') ?> <?= t('nav_personnel') ?> <?= t('btn_calculate') ?> <?= t('و') ?> <?= t('جدا') ?> <?= t('lea_from') ?> <?= t('خدمات') ?>، <?= t('در') ?> <?= t('pet_balance') ?> <?= t('و') ?> <?= t('فرم') ?> <?= t('واریزی') ?> <?= t('پیمانکار') ?> <?= t('rep_total') ?> <?= t('می‌شود') ?>.</div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_start') ?> <?= t('کار') ?></label><input type="text" name="start_date" class="form-control jalali-date" placeholder="1404/01/01"></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_end') ?> <?= t('کار') ?></label><input type="text" name="end_date" class="form-control jalali-date" placeholder="1405/01/01"></div>
                </div>
                <!-- §v12 §4.9 + §4.7 -->
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('per_iban') ?></label><input type="text" name="iban" class="form-control" placeholder="IR123456789012345678901234" style="direction:ltr;text-align:left" maxlength="34"></div>
                    <div class="form-group"><label class="form-label"><?= t('حساب') ?> <?= t('قبلی') ?> / <?= t('pet_balance') ?> <?= t('اول دوره') ?> (<?= t('sal_toman') ?>)</label>
                        <div class="d-flex gap-2">
                            <input type="number" name="opening_amount" class="form-control" min="0" step="any" placeholder="0">
                            <select name="opening_dir" class="form-control" style="max-width:155px">
                                <option value="sub"><?= t('طلب') ?> <?= t('پیمانکار') ?> <?= t('lea_from') ?> <?= t('ما') ?></option>
                                <option value="us"><?= t('طلب') ?> <?= t('ما') ?> <?= t('lea_from') ?> <?= t('پیمانکار') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?></label><textarea name="description" class="form-control" rows="2"></textarea></div>
                <div class="form-group">
                    <label class="form-label"><?= t('sub_services') ?> (§9)</label>
                    <div class="text-muted" style="font-size:10px;margin-bottom:6px"><?= t('برای') ?> <?= t('هر') ?> <?= t('خدمت') ?> <?= t('unit_price') ?> <?= t('تعریف') ?> <?= t('کنید') ?> <?= t('— در') ?> <?= t('ثبت') ?> <?= t('sal_daily') ?> <?= t('فقط') ?> <?= t('تعداد') ?> <?= t('وارد') ?> <?= t('می‌شود') ?></div>
                    <div id="svcRepeater">
                        <div class="d-flex gap-2 mb-2 svc-row">
                            <input type="text" name="svc_name[]" class="form-control" placeholder="<?= t('sal_type') ?> <?= t('خدمت') ?> (<?= t('مثلاً') ?> <?= t('غذا') ?>)" style="flex:2">
                            <input type="text" name="svc_unit[]" class="form-control" placeholder="<?= t('واحد') ?> (<?= t('پرس') ?>)" style="flex:1">
                            <input type="number" name="svc_price[]" class="form-control" placeholder="<?= t('unit_price') ?>" min="0" style="flex:1">
                        </div>
                    </div>
                    <button type="button" onclick="svcAddRow('svcRepeater')" class="btn btn-ghost btn-sm">＋ <?= t('خدمت دیگر') ?></button>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('ایجاد') ?></button></div>
        </form>
    </div>
</div>

<!-- Pay Modal -->
<div id="payModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('ثبت') ?> <?= t('پرداخت') ?></h3><button onclick="closeModal('payModal')" class="modal-close">✕</button></div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?><input type="hidden" name="action" value="payment"><input type="hidden" name="sub_id" id="pay_sub_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('adv_amount') ?> *</label><input type="number" name="amount" class="form-control" required></div>
                    <div class="form-group"><label class="form-label"><?= t('منبع') ?> <?= t('پرداخت') ?> *</label>
                        <select name="pay_source" class="form-control"><option value="fund"><?= t('صندوق') ?> <?= t('شرکت') ?></option><option value="tanvah"><?= t('nav_petty_cash') ?></option></select>
                    </div>
                </div>
                <!-- §v12 §4.6 -->
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="payment_date" class="form-control jalali-date" value="<?= jalali_now() ?>"></div>
                    <div class="form-group"><label class="form-label"><?= t('روش پرداخت') ?></label>
                        <select name="method" class="form-control">
                            <option value="واریز بانکی"><?= t('واریز بانکی') ?></option>
                            <option value="کارت به کارت"><?= t('کارت به کارت') ?></option>
                            <option value="نقدی"><?= t('نقدی') ?></option>
                            <option value="حواله"><?= t('حواله') ?></option>
                            <option value="چک"><?= t('چک') ?></option>
                            <option value="سایر"><?= t('سایر') ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('شماره پیگیری') ?> <span class="text-muted" style="font-size:10px">(<?= t('اختیاری') ?>)</span></label><input type="text" name="tracking_no" class="form-control" style="direction:ltr;text-align:left"></div>
                    <div class="form-group"><label class="form-label"><?= t('توضیح') ?></label><input type="text" name="description" class="form-control"></div>
                </div><div class="form-group"><label class="form-label"><?= t('رسید') ?> / <?= t('فاکتور') ?> <?= t('پرداخت') ?></label><input type="file" name="attachment" class="form-control" accept="image/png,image/jpeg,application/pdf"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('ثبت') ?></button></div>
        </form>
    </div>
</div>

<!-- Edit Subcontractor Modal -->
<div id="editModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_edit') ?> <?= t('پیمانکار') ?></h3><button onclick="closeModal('editModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="update_sub"><input type="hidden" name="sub_id" id="edit_sub_id"><input type="hidden" name="return_sub" value="<?= (int)$face_sub ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_name') ?> *</label><input type="text" name="name" class="form-control" required id="edit_name"></div>
                    <div class="form-group"><label class="form-label"><?= t('شرکت') ?></label><input type="text" name="company" class="form-control" id="edit_company"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('per_phone') ?></label><input type="text" name="phone" class="form-control" id="edit_phone"></div>
                    <div class="form-group"><label class="form-label"><?= t('مبلغ') ?> <?= t('قرارداد') ?> (<?= t('sal_toman') ?>)</label><input type="number" name="total_contract" class="form-control" min="0" id="edit_total_contract"></div>
                </div>
                <!-- §v12 §4.9 + §4.7 -->
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('per_iban') ?></label><input type="text" name="iban" class="form-control" id="edit_iban" style="direction:ltr;text-align:left" maxlength="34" placeholder="IR..."></div>
                    <div class="form-group"><label class="form-label"><?= t('حساب') ?> <?= t('قبلی') ?> / <?= t('pet_balance') ?> <?= t('اول دوره') ?> (<?= t('sal_toman') ?>)</label>
                        <div class="d-flex gap-2">
                            <input type="number" name="opening_amount" id="edit_opening_amount" class="form-control" min="0" step="any" placeholder="0">
                            <select name="opening_dir" id="edit_opening_dir" class="form-control" style="max-width:155px">
                                <option value="sub"><?= t('طلب') ?> <?= t('پیمانکار') ?> <?= t('lea_from') ?> <?= t('ما') ?></option>
                                <option value="us"><?= t('طلب') ?> <?= t('ما') ?> <?= t('lea_from') ?> <?= t('پیمانکار') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">🔗 <?= t('اتصال') ?> <?= t('به') ?> <?= t('nav_personnel') ?></label>
                    <select name="linked_user_id" class="form-control" id="edit_linked_user"><option value=""><?= t('بدون اتصال — فقط') ?> <?= t('sub_services') ?></option><?php foreach ($linkable_personnel as $lp): ?><option value="<?= (int)$lp['id'] ?>"><?= htmlspecialchars($lp['full_name']) ?> — <?= $lp['is_karigar'] == 2 ? t('پیمانکار پرسنلی') : ($lp['is_karigar'] == 1 ? t('sal_worker') : t('sal_employee')) ?></option><?php endforeach; ?></select>
                    <div class="text-muted" style="font-size:10px;margin-top:4px"><?= t('nav_salary') ?> <?= t('ساعتیِ') ?> <?= t('nav_personnel') ?> <?= t('متصل') ?>، <?= t('کنار') ?> <?= t('sub_services') ?> <?= t('btn_calculate') ?> <?= t('و') ?> <?= t('در') ?> <?= t('فرم') ?> <?= t('واریزی') ?> <?= t('تجمیع') ?> <?= t('می‌شود') ?>.</div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?></label><textarea name="description" class="form-control" rows="2" id="edit_description"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- §v12 §4: صورت وضعیت پیمانکار — ساختار جدید (خدمات + ثبت روزانه + پرداخت‌ها)؛ صورت وضعیت قدیمی و پرینت/اکسل حذف شد -->
<?php if ($face_sub > 0 && $sel_sub): ?>
<div class="card mt-4 animate-in">
    <div class="card-header d-flex justify-between align-center" style="flex-wrap:wrap;gap:8px">
        <h3 class="card-title"><?= t('صورت') ?> <?= t('wh_status') ?> — <?= $sel_sub['name'] ?>
            <?php if ($sel_sub['company']): ?><span class="text-muted" style="font-size:12px;font-weight:400">(<?= $sel_sub['company'] ?>)</span><?php endif; ?>
        </h3>
        <?php if (has_permission('manage_subcontractors')): ?>
        <div class="d-flex gap-2" style="flex-wrap:wrap">
            <button onclick="editSubContractor(<?= htmlspecialchars(json_encode($sel_sub, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)" class="btn btn-ghost btn-sm">✏️ <?= t('btn_edit') ?> <?= t('info') ?></button>
            <button onclick="paySub(<?= (int)$face_sub ?>)" class="btn btn-primary btn-sm">💰 <?= t('ثبت') ?> <?= t('پرداخت') ?> <?= t('جدید') ?></button>
            <button onclick="openSubSettle(<?= (int)$face_sub ?>)" class="btn btn-sm" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none">💸 <?= t('تسویه') ?> <?= t('lea_to') ?> <?= t('wh_date') ?></button>
        </div>
        <?php endif; ?>
    </div>
    <!-- §v12 §4.9/§4.7: شبا + حساب قبلی -->
    <div class="d-flex gap-3 mb-3" style="flex-wrap:wrap;font-size:12px;color:var(--text-secondary)">
        <div><?= t('🏦 شبا:') ?> <?php if (!empty($sel_sub['iban'])): ?><strong style="direction:ltr;display:inline-block"><?= $sel_sub['iban'] ?></strong><?php else: ?><span class="text-muted"><?= t('ثبت نشده') ?></span><?php endif; ?></div>
        <div>📂 <?= t('حساب') ?> <?= t('قبلی') ?>: <strong><?= number_format((float)($sel_sub['opening_balance'] ?? 0)) ?> <?= t('ت') ?></strong><?php if (($sel_sub['opening_balance'] ?? 0) < 0): ?> <span class="text-danger" style="font-size:11px">(<?= t('طلب') ?> <?= t('ما') ?> <?= t('lea_from') ?> <?= t('پیمانکار') ?>)</span><?php endif; ?></div>
        <div>📞 <?= $sel_sub['phone'] ?: '—' ?></div>
        <?php if ($linked_person): ?><div style="color:#6d28d9">🔗 <?= t('nav_personnel') ?> <?= t('متصل') ?>: <strong><?= htmlspecialchars($linked_person['full_name']) ?></strong> — <?= t('nav_salary') ?> <?= t('ساعتی') ?> <?= t('lea_from') ?> <?= t('صفحهٔ') ?> <?= t('nav_personnel') ?> <?= t('btn_calculate') ?> <?= t('می‌شود') ?>.</div><?php endif; ?>
    </div>
    <?php if ($linked_period): ?>
    <div class="card mb-3" style="border:1px solid #ddd6fe;background:linear-gradient(135deg,#faf5ff,#fff)">
        <div class="card-header"><h3 class="card-title" style="font-size:14px">⏱ <?= t('کارکرد ساعتی') ?> <?= t('nav_personnel') ?> <?= t('متصل') ?> — <?= htmlspecialchars($linked_period['name']) ?></h3><span class="text-muted" style="font-size:11px"><?= $show_all ? t('همه دوره‌ها') : (jalali_month_name($fm) . ' ' . $fy) ?></span></div>
        <div class="d-flex gap-3" style="flex-wrap:wrap;font-size:12px"><div><?= t('کارکرد ساعتی') ?>: <strong><?= number_format((float)$linked_period['base_pay']) ?> <?= t('ت') ?></strong><?= !empty($linked_period['is_hourly_contractor']) ? ' <span class="text-muted">(' . fmt_hm((float)$linked_period['worked_hours']) . ' ' . t('ساعت') . ' × ' . number_format((float)$linked_period['hourly_rate']) . ')</span>' : '' ?></div><div style="color:#16a34a"><?= t('sal_bonus') ?>: +<?= number_format((float)$linked_period['bonus']) ?> <?= t('ت') ?></div><div style="color:#dc2626"><?= t('sal_penalty') ?>: -<?= number_format((float)$linked_period['penalty']) ?> <?= t('ت') ?></div><div style="color:#0369a1"><?= t('sal_advance') ?>/<?= t('پرداخت') ?>: -<?= number_format((float)$linked_period['advance']) ?> <?= t('ت') ?></div><div style="font-weight:800;color:#6d28d9"><?= t('sal_net') ?> <?= t('بخش') ?> <?= t('ساعتی') ?>: <?= number_format((float)$linked_period['net']) ?> <?= t('ت') ?></div></div>
        <div class="text-muted" style="font-size:10px;margin-top:8px"><?= t('این') ?> <?= t('مبلغ') ?> <?= t('مستقل') ?> <?= t('lea_from') ?> <?= t('ثبت') ?> <?= t('روزانهٔ') ?> <?= t('خدمات') ?> <?= t('است') ?> <?= t('و') ?> <?= t('در') ?> <?= t('ماندهٔ') ?> <?= t('نهایی') ?> <?= t('پیمانکار') ?> <?= t('rep_total') ?> <?= t('می‌شود') ?>.</div>
    </div>
    <?php endif; ?>
    <!-- §9: خدمات پیمانکار -->
    <details class="mb-3" style="border:1px solid var(--border);border-radius:12px;padding:10px 14px;background:var(--surface)">
        <summary style="cursor:pointer;font-weight:700;font-size:13px">🛠 <?= t('sub_services') ?> (<?= count(array_filter($sub_services, fn($x) => $x['status'] === 'active')) ?> <?= t('فعال') ?>) — <?= t('تعریف') ?>/<?= t('btn_edit') ?> <?= t('unit_price') ?></summary>
        <form method="POST" class="mt-3"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="sync_services">
            <input type="hidden" name="svc_sub_id" value="<?= $face_sub ?>">
            <?php foreach ($sub_services as $sv): ?>
            <div class="d-flex gap-2 mb-2" style="align-items:center">
                <input type="hidden" name="svc_id[]" value="<?= $sv['id'] ?>">
                <input type="text" name="svc_name[]" class="form-control" value="<?= htmlspecialchars($sv['service_name']) ?>" style="flex:2" <?= $sv['status'] === 'inactive' ? 'disabled' : '' ?>>
                <input type="text" name="svc_unit[]" class="form-control" value="<?= htmlspecialchars($sv['unit_name']) ?>" style="flex:1" <?= $sv['status'] === 'inactive' ? 'disabled' : '' ?>>
                <input type="number" name="svc_price[]" class="form-control" value="<?= (float)$sv['unit_price'] ?>" min="0" style="flex:1" <?= $sv['status'] === 'inactive' ? 'disabled' : '' ?>>
                <?php if ($sv['status'] === 'inactive'): ?><span class="tag tag-rejected" style="font-size:9px"><?= t('btn_deactivate') ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div id="svcRepeaterDetail"></div>
            <div class="d-flex gap-2 mt-2">
                <button type="button" onclick="svcAddRow('svcRepeaterDetail')" class="btn btn-ghost btn-sm">＋ <?= t('خدمت جدید') ?></button>
                <button type="submit" class="btn btn-primary btn-sm">💾 <?= t('btn_save') ?> <?= t('خدمات') ?></button>
            </div>
        </form>
        <?php if (!empty($sub_services)): ?>
        <div class="mt-3" style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach ($sub_services as $sv): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('btn_delete') ?> <?= t('یا') ?> <?= t('تغییر') ?> <?= t('wh_status') ?> <?= t('این') ?> <?= t('خدمت') ?>؟')"><?= get_csrf_field() ?>
                <input type="hidden" name="sub_id" value="<?= $face_sub ?>">
                <input type="hidden" name="service_id" value="<?= $sv['id'] ?>">
                <span style="display:inline-flex;gap:2px;align-items:center;border:1px solid var(--border);border-radius:8px;padding:2px 6px;font-size:10px">
                    <?= $sv['service_name'] ?>
                    <button name="action" value="toggle_service" class="btn btn-ghost btn-sm" style="padding:1px 5px;font-size:10px" title="<?= t('فعال') ?>/<?= t('btn_deactivate') ?>"><?= $sv['status'] === 'active' ? '🔕' : '🔔' ?></button>
                    <button name="action" value="delete_service" class="btn btn-ghost btn-sm" style="padding:1px 5px;font-size:10px;color:var(--danger)" title="<?= t('btn_delete') ?>">🗑</button>
                </span>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </details>

    <!-- §9: ثبت‌های روزانه خدمات — §v12 §4.4/§4.5: فیلتر سال+ماه جلالی بالای این بخش -->
    <div class="card mb-3" style="border:1px solid var(--border)">
        <div class="card-header d-flex justify-between align-center" style="flex-wrap:wrap;gap:8px">
            <h3 class="card-title" style="font-size:14px">📋 <?= t('sub_daily_entry') ?> (<?= t('تعداد') ?> + <?= t('wh_shift') ?>)</h3>
            <?php if (has_permission('manage_subcontractors')): ?>
            <button type="button"
                    onclick="event.preventDefault();try{if(typeof window.openDailyModal==='function'){window.openDailyModal(<?= (int)$face_sub ?>);}else{var m=document.getElementById('dailyModal');if(m){m.classList.remove('hidden');m.style.display='flex';}}}catch(e){console.error('daily modal error',e);var m=document.getElementById('dailyModal');if(m){m.classList.remove('hidden');m.style.display='flex';}}"
                    class="btn btn-warning btn-sm">＋ <?= t('ثبت') ?> <?= t('sal_daily') ?></button>
            <?php endif; ?>
        </div>
        <form method="GET" class="d-flex gap-2 mb-3" style="align-items:center;flex-wrap:wrap;padding:0 14px">
            <input type="hidden" name="page" value="subcontractors">
            <input type="hidden" name="sub" value="<?= $face_sub ?>">
            <span style="font-size:12px;color:var(--text-secondary)">🗓 <?= t('دوره') ?>:</span>
            <select name="fy" class="form-control" style="width:95px">
                <?php for ($y = $jy_now - 3; $y <= $jy_now + 1; $y++): ?>
                <option value="<?= $y ?>" <?= (!$show_all && $fy === $y) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <select name="fm" class="form-control" style="width:110px">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= (!$show_all && $fm === $m) ? 'selected' : '' ?>><?= jalali_month_name($m) ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm"><?= t('btn_show') ?></button>
            <?php if (!$show_all): ?>
            <a href="index.php?page=subcontractors&sub=<?= $face_sub ?>&all=1" class="btn btn-ghost btn-sm" style="font-size:11px"><?= t('همه دوره‌ها') ?></a>
            <?php else: ?>
            <a href="index.php?page=subcontractors&sub=<?= $face_sub ?>" class="btn btn-ghost btn-sm" style="font-size:11px"><?= t('نمای') ?> <?= t('sal_monthly') ?></a>
            <?php endif; ?>
            <span class="text-muted" style="font-size:11px"><?= $show_all ? '(' . t('نمایش همه دوره‌ها') . ')' : '(' . jalali_month_name($fm) . ' ' . $fy . ')' ?> — <?= count($daily_entries) ?> <?= t('ثبت') ?></span>
        </form>
        <div class="table-container">
            <table>
                <thead><tr><th><?= t('wh_date') ?></th><th><?= t('wh_shift') ?></th><th><?= t('اقلام') ?></th><th><?= t('rep_total') ?></th><th><?= t('ثبت‌کننده') ?></th><th></th></tr></thead>
                <tbody>
                    <?php $total_daily = 0; foreach ($daily_entries as $en): $total_daily += $en['total']; ?>
                    <tr>
                        <td style="white-space:nowrap"><?= to_jalali($en['entry_date']) ?></td>
                        <td><span class="tag" style="font-size:10px;<?= $en['shift'] === 'night' ? 'background:#1e293b;color:#fff' : 'background:#fef3c7;color:#92400e' ?>"><?= $en['shift'] === 'night' ? '🌙 ' . t('شب') : '☀️ ' . t('روز') ?></span></td>
                        <td style="font-size:11px;text-align:right">
                            <?php foreach ($en['items'] as $ii): ?>
                            <div><?= $ii['service_name'] ?>: <strong><?= (float)$ii['quantity'] ?></strong> <?= $ii['unit_name'] ?> × <?= number_format((float)$ii['unit_price_snapshot']) ?> = <?= number_format((float)$ii['total_price']) ?></div>
                            <?php endforeach; ?>
                            <?php if ($en['description']): ?><div class="text-muted" style="font-size:10px"><?= $en['description'] ?></div><?php endif; ?><?php if (!empty($en['attachment'])): ?> <a href="<?= htmlspecialchars($en['attachment']) ?>" target="_blank" class="btn btn-ghost btn-sm" style="font-size:10px">📎 <?= t('فاکتور') ?></a><?php endif; ?>
                        </td>
                        <td style="font-weight:700"><?= number_format((float)$en['total']) ?> <?= t('ت') ?></td>
                        <td class="text-muted" style="font-size:10px"><?= $en['creator_name'] ?: '—' ?></td>
                        <td>
                            <?php if (has_permission('manage_subcontractors')):
                                $__edq = [];
                                foreach ($en['items'] as $__ii) $__edq[(int)$__ii['service_id']] = (float)$__ii['quantity'];
                                $__ed = ['id' => (int)$en['id'], 'sub_id' => (int)$face_sub, 'date' => to_jalali($en['entry_date']), 'shift' => $en['shift'] === 'night' ? 'night' : 'day', 'desc' => (string)($en['description'] ?? ''), 'has_file' => !empty($en['attachment']), 'qty' => (object)$__edq];
                            ?>
                            <button type="button" class="btn btn-ghost btn-sm" style="font-size:11px;color:#0ea5e9" title="<?= htmlspecialchars(t('btn_edit'), ENT_QUOTES, 'UTF-8') ?>" onclick='openEditDaily(<?= js_arg($__ed) ?>)'>✏️</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('این') ?> <?= t('ثبت') ?> <?= t('sal_daily') ?> <?= t('chat_delete') ?>')"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="delete_daily_entry">
                                <input type="hidden" name="entry_id" value="<?= $en['id'] ?>">
                                <input type="hidden" name="sub_id" value="<?= $face_sub ?>">
                                <button class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--danger)">🗑</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($daily_entries)): ?><tr><td colspan="6" class="text-center text-muted"><?= t('ثبتی نیست') ?></td></tr><?php endif; ?>
                    <?php if (!empty($daily_entries)): ?>
                    <tr style="font-weight:bold;background:rgba(245,158,11,0.08)"><td colspan="3"><?= t('rep_total') ?> <?= t('خدمات') ?> <?= t('sal_daily') ?>:</td><td><?= number_format($total_daily) ?> <?= t('ت') ?></td><td colspan="2"></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- §v12 §4.6: پرداخت‌های پیمانکار (همه دوره‌ها) با روش پرداخت و شماره پیگیری -->
    <div class="card mb-3" style="border:1px solid var(--border)">
        <div class="card-header d-flex justify-between align-center" style="flex-wrap:wrap;gap:8px">
            <h3 class="card-title" style="font-size:14px">💰 <?= t('پرداخت‌ها') ?></h3>
            <?php if (has_permission('manage_subcontractors')): ?>
            <button onclick="paySub(<?= (int)$face_sub ?>)" class="btn btn-primary btn-sm">＋ <?= t('ثبت') ?> <?= t('پرداخت') ?></button>
            <?php endif; ?>
        </div>
        <div class="table-container">
            <table>
                <thead><tr><th><?= t('wh_date') ?></th><th><?= t('مبلغ') ?></th><th><?= t('منبع') ?></th><th><?= t('روش') ?></th><th><?= t('پیگیری') ?></th><th><?= t('wor_description') ?></th><th></th></tr></thead>
                <tbody>
                    <?php $total_pay = 0; foreach ($pay_items as $pay): $total_pay += (float)$pay['amount']; ?>
                    <tr>
                        <td style="white-space:nowrap"><?= to_jalali($pay['payment_date']) ?></td>
                        <td style="font-weight:700"><?= number_format((float)$pay['amount']) ?> <?= t('ت') ?></td>
                        <td><span class="tag" style="font-size:10px;<?= ($pay['source'] ?? 'fund') === 'tanvah' ? 'background:#fef3c7;color:#92400e' : 'background:#e0f2fe;color:#0369a1' ?>"><?= ($pay['source'] ?? 'fund') === 'tanvah' ? t('nav_petty_cash') : t('صندوق') ?></span></td>
                        <td style="font-size:11px"><?= $pay['method'] ?: '—' ?></td>
                        <td style="font-size:11px;direction:ltr"><?= $pay['tracking_no'] ?: '—' ?></td>
                        <td style="max-width:200px;font-size:11px"><?= $pay['description'] ?: '—' ?><?php if (!empty($pay['attachment'])): ?> <a href="<?= htmlspecialchars($pay['attachment']) ?>" target="_blank">📎</a><?php endif; ?></td>
                        <td style="white-space:nowrap">
                            <?php if (has_permission('manage_subcontractors')): ?>
                            <button onclick='editSubPayment(<?= htmlspecialchars(json_encode(['id' => (int)$pay['id'], 'amount' => (float)$pay['amount'], 'description' => $pay['description'] ?? '', 'date' => to_jalali($pay['payment_date']), 'source' => $pay['source'] ?? 'fund', 'method' => $pay['method'] ?? '', 'tracking_no' => $pay['tracking_no'] ?? ''], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)' class="btn btn-ghost btn-sm" style="font-size:11px" title="<?= t('btn_edit') ?>">✏️</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('این') ?> <?= t('پرداخت') ?> <?= t('chat_delete') ?>')"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="payment_id" value="<?= (int)$pay['id'] ?>">
                                <input type="hidden" name="sub_id" value="<?= (int)$face_sub ?>">
                                <button class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--danger)" title="<?= t('btn_delete') ?>">🗑️</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pay_items)): ?><tr><td colspan="7" class="text-center text-muted"><?= t('پرداختی') ?> <?= t('ثبت نشده') ?></td></tr><?php endif; ?>
                    <?php if (!empty($pay_items)): ?>
                    <tr style="font-weight:bold;background:rgba(34,197,94,0.08)"><td><?= t('rep_total') ?> <?= t('پرداخت‌ها:') ?></td><td><?= number_format($total_pay) ?> <?= t('ت') ?></td><td colspan="5"></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- §v12 §4.7/§4.8: خلاصه حساب پیمانکار (همه دوره‌ها): مطالبات + حساب قبلی − پرداخت‌ها -->
    <div class="card mb-3" style="background:linear-gradient(135deg,rgba(99,102,241,.07),rgba(34,197,94,.07));border:1px solid var(--border)">
        <div class="d-flex justify-between align-center flex-wrap" style="gap:12px">
            <div><span class="text-muted" style="font-size:12px"><?= t('rep_total') ?> <?= t('مطالبات') ?> (<?= t('خدمات') ?> + <?= t('کارکرد') ?> <?= t('ساعتیِ') ?> <?= t('متصل') ?>):</span><br><strong style="font-size:16px"><?= number_format($sum_all['claims']) ?> <?= t('ت') ?></strong></div>
            <?php if (!empty($sum_all['personnel']['linked'])): ?><div><span class="text-muted" style="font-size:12px">⏱ <?= t('سهم') ?> <?= t('کارکرد ساعتی') ?>:</span><br><strong style="font-size:16px;color:#6d28d9"><?= number_format($sum_all['personnel_claim']) ?> <?= t('ت') ?></strong></div><?php endif; ?>
            <div><span class="text-muted" style="font-size:12px">📂 <?= t('حساب') ?> <?= t('قبلی') ?>:</span><br><strong style="font-size:16px"><?= number_format($sum_all['opening']) ?> <?= t('ت') ?></strong><?php if ($sum_all['opening'] < 0): ?> <span class="text-danger" style="font-size:10px"><?= t('(طلب ما)') ?></span><?php endif; ?></div>
            <div><span class="text-muted" style="font-size:12px"><?= t('rep_total') ?> <?= t('پرداخت‌ها:') ?></span><br><strong style="font-size:16px;color:var(--success)"><?= number_format($sum_all['paid']) ?> <?= t('ت') ?></strong></div>
            <div><span class="text-muted" style="font-size:12px"><?= t('pet_balance') ?> <?= t('حساب') ?> <?= t('پیمانکار') ?>:</span><br><strong style="font-size:20px;color:<?= $sum_all['balance'] >= 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= number_format($sum_all['balance']) ?> <?= t('ت') ?></strong></div>
        </div>
    </div>

<!-- Edit Payment Modal -->
<div id="editPaymentModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:460px">
        <div class="modal-header"><h3 class="modal-title">✏️ <?= t('btn_edit') ?> <?= t('پرداخت') ?> <?= t('پیمانکار') ?></h3><button onclick="closeModal('editPaymentModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit_payment">
            <input type="hidden" name="payment_id" id="edit_pay_id">
            <input type="hidden" name="sub_id" value="<?= (int)$face_sub ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('adv_amount') ?> *</label><input type="number" name="amount" id="edit_pay_amount" class="form-control" required min="0"></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="payment_date" id="edit_pay_date" class="form-control jalali-date"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('منبع') ?></label>
                        <select name="pay_source" id="edit_pay_source" class="form-control">
                            <option value="fund"><?= t('صندوق') ?></option>
                            <option value="tanvah"><?= t('nav_petty_cash') ?></option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label"><?= t('روش پرداخت') ?></label>
                        <select name="method" id="edit_pay_method" class="form-control">
                            <option value="واریز بانکی"><?= t('واریز بانکی') ?></option>
                            <option value="کارت به کارت"><?= t('کارت به کارت') ?></option>
                            <option value="نقدی"><?= t('نقدی') ?></option>
                            <option value="حواله"><?= t('حواله') ?></option>
                            <option value="چک"><?= t('چک') ?></option>
                            <option value="سایر"><?= t('سایر') ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('شماره پیگیری') ?></label><input type="text" name="tracking_no" id="edit_pay_tracking" class="form-control" style="direction:ltr;text-align:left"></div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?></label><textarea name="description" id="edit_pay_desc" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button></div>
        </form>
    </div>
</div>

<!-- §v12 §4.8: مودال تسویه حساب پیمانکار تا تاریخ -->
<div id="subSettleModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:430px">
        <div class="modal-header"><h3 class="modal-title">💸 <?= t('تسویه') ?> <?= t('حساب') ?> <?= t('پیمانکار') ?> <?= t('lea_to') ?> <?= t('wh_date') ?></h3><button onclick="closeModal('subSettleModal')" class="modal-close">✕</button></div>
        <div class="modal-body">
            <form method="post"><?= get_csrf_field() ?>
                <input type="hidden" name="action" value="settle_sub">
                <input type="hidden" name="sub_id" id="subSettleId">
                <p style="font-size:12.5px;color:var(--text-secondary);line-height:1.8"><?= t('pet_balance') ?> <?= t('حساب') ?> (<?= t('مطالبات') ?> + <?= t('حساب') ?> <?= t('قبلی') ?> − <?= t('پرداخت‌ها') ?>) <?= t('lea_to') ?> <?= t('wh_date') ?> <?= t('انتخابی') ?> <?= t('btn_calculate') ?> <?= t('و') ?> <?= t('به') ?>‌<?= t('عنوان') ?> «<?= t('پرداخت') ?>» <?= t('ثبت') ?> <?= t('می‌شود') ?> <?= t('lea_to') ?> <?= t('حساب') ?> <?= t('lea_to') ?> <?= t('آن') ?> <?= t('wh_date') ?> <?= t('صفر') ?> <?= t('شود') ?>. <?= t('خدمات') ?> <?= t('و') ?> <?= t('پرداخت') ?>‌<?= t('های') ?> <?= t('بعد') ?> <?= t('lea_from') ?> <?= t('آن') ?> <?= t('wh_date') ?> <?= t('دست') ?> <?= t('نمی‌خورند') ?>.</p>
                <div class="form-group">
                    <label class="form-label"><?= t('تسویه') ?> <?= t('lea_to') ?> <?= t('wh_date') ?>: <span class="required">*</span></label>
                    <input type="text" name="settle_date" id="subSettleDate" class="form-input jalali-date" placeholder="<?= t('date_placeholder') ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('روش پرداخت') ?>:</label>
                        <select name="method" class="form-control">
                            <option value="واریز بانکی"><?= t('واریز بانکی') ?></option>
                            <option value="کارت به کارت"><?= t('کارت به کارت') ?></option>
                            <option value="نقدی"><?= t('نقدی') ?></option>
                            <option value="حواله"><?= t('حواله') ?></option>
                            <option value="چک"><?= t('چک') ?></option>
                            <option value="سایر"><?= t('سایر') ?></option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label"><?= t('منبع') ?>:</label>
                        <select name="pay_source" class="form-control">
                            <option value="fund"><?= t('صندوق') ?> <?= t('شرکت') ?></option>
                            <option value="tanvah"><?= t('nav_petty_cash') ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('شماره پیگیری') ?> <span class="text-muted" style="font-size:10px">(<?= t('اختیاری') ?>)</span></label><input type="text" name="tracking_no" class="form-control" style="direction:ltr;text-align:left"></div>
                <div id="subSettleBox" style="padding:14px;border-radius:10px;background:var(--bg);text-align:center;margin-bottom:14px;font-size:13px;line-height:1.9">
                    📅 <?= t('wh_date') ?> <?= t('تسویه') ?> <?= t('را') ?> <?= t('انتخاب') ?> <?= t('یا') ?> <?= t('تایپ') ?> <?= t('کنید') ?>…
                </div>
                <button type="submit" id="subSettleSubmit" class="btn btn-primary w-full" disabled>✔ <?= t('تأیید') ?> <?= t('و') ?> <?= t('ثبت') ?> <?= t('تسویه') ?></button>
            </form>
        </div>
    </div>
</div>

<script>
function editSubPayment(p) {
    document.getElementById('edit_pay_id').value = p.id;
    document.getElementById('edit_pay_amount').value = p.amount;
    document.getElementById('edit_pay_date').value = p.date;
    document.getElementById('edit_pay_source').value = p.source;
    if (document.getElementById('edit_pay_method')) document.getElementById('edit_pay_method').value = p.method || '<?= t('واریز بانکی') ?>';
    if (document.getElementById('edit_pay_tracking')) document.getElementById('edit_pay_tracking').value = p.tracking_no || '';
    document.getElementById('edit_pay_desc').value = p.description;
    openModal('editPaymentModal');
}

// §v12 §4.8: <?= t('تسویه') ?> <?= t('پیمانکار') ?> — <?= t('پیش') ?>‌<?= t('نمایش') ?> زنده <?= t('مبلغ') ?> (<?= t('محاسبه') ?> <?= t('سمت') ?> <?= t('سرور') ?>)
function fa2enDigits(s) { return (s || '').replace(/[۰-۹]/g, function(c) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c); }); }
var __subSettleTimer = null;
function openSubSettle(subId) {
    document.getElementById('subSettleId').value = subId;
    document.getElementById('subSettleDate').value = '';
    document.getElementById('subSettleSubmit').disabled = true;
    document.getElementById('subSettleBox').innerHTML = '📅 <?= t('تاریخ تسویه را انتخاب یا تایپ کنید…') ?>';
    openModal('subSettleModal');
    if (__subSettleTimer) clearInterval(__subSettleTimer);
    var lastVal = '';
    __subSettleTimer = setInterval(function() {
        var modal = document.getElementById('subSettleModal');
        if (!modal || modal.classList.contains('hidden')) { clearInterval(__subSettleTimer); __subSettleTimer = null; return; }
        var v = fa2enDigits(document.getElementById('subSettleDate').value.trim());
        if (v !== lastVal) { lastVal = v; subSettlePreview(); }
    }, 350);
}
async function subSettlePreview() {
    var sid = document.getElementById('subSettleId').value;
    var d = fa2enDigits(document.getElementById('subSettleDate').value.trim());
    var box = document.getElementById('subSettleBox');
    var btn = document.getElementById('subSettleSubmit');
    btn.disabled = true;
    if (!/^\d{4}\/\d{2}\/\d{2}$/.test(d)) {
        box.innerHTML = '📅 <?= t('تاریخ تسویه را به‌صورت کامل وارد کنید (مثلاً ۱۴۰۵/۰۵/۳۱)') ?>';
        return;
    }
    box.innerHTML = '⏳ <?= t('در حال محاسبه مانده حساب…') ?>';
    try {
        const res = await fetch('index.php?page=subcontractors&settle_sub_preview=' + sid + '&date=' + encodeURIComponent(d));
        const data = await res.json();
        if (!data.ok) {
            box.innerHTML = '<span class="text-danger">' + (data.error || '<?= t('خطا در محاسبه') ?>') + '</span>';
            return;
        }
        if (data.amount <= 0) {
            box.innerHTML = '💼 <?= t('مانده تا') ?> <strong>' + data.jdate + '</strong>: <strong>' + Number(data.amount).toLocaleString() + '</strong> <?= t('تومان') ?><br><span class="text-danger" style="font-size:12px"><?= t('مانده‌ای برای تسویه وجود ندارد') ?></span>';
            return;
        }
        box.innerHTML = '💼 <?= t('مانده تا') ?> <strong>' + data.jdate + '</strong>:<br><strong style="font-size:20px;color:var(--success)">' + Number(data.amount).toLocaleString() + '</strong> <?= t('تومان') ?>';
        btn.disabled = false;
    } catch (e) {
        box.innerHTML = '<span class="text-danger"><?= t('خطا در ارتباط با سرور') ?></span>';
    }
}
</script>
</div>


<?php endif; ?>

<script>
function paySub(id){document.getElementById('pay_sub_id').value=id;openModal('payModal');}

function editSubContractor(s) {
    document.getElementById('edit_sub_id').value = s.id;
    document.getElementById('edit_name').value = s.name || '';
    document.getElementById('edit_company').value = s.company || '';
    document.getElementById('edit_phone').value = s.phone || '';
    document.getElementById('edit_total_contract').value = s.total_contract || 0;
    document.getElementById('edit_description').value = s.description || '';
    // §v12 §4.7/§4.9: شبا + حساب قبلی
    if (document.getElementById('edit_iban')) document.getElementById('edit_iban').value = s.iban || '';
    var op = parseFloat(s.opening_balance) || 0;
    if (document.getElementById('edit_opening_amount')) document.getElementById('edit_opening_amount').value = Math.abs(op);
    if (document.getElementById('edit_opening_dir')) document.getElementById('edit_opening_dir').value = op < 0 ? 'us' : 'sub';
    if (document.getElementById('edit_linked_user')) document.getElementById('edit_linked_user').value = s.linked_user_id || '';
    openModal('editModal');
}</script>
<!-- ویرایش ثبت روزانه خدمات -->
<div id="editDailyModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title">✏️ <?= t('btn_edit') ?> <?= t('ثبت') ?> <?= t('sal_daily') ?></h3><button onclick="closeModal('editDailyModal')" class="modal-close">✕</button></div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit_daily_entry">
            <input type="hidden" name="entry_id" id="ed_entry_id">
            <input type="hidden" name="sub_id" id="ed_sub_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?> *</label><input type="text" name="entry_date" id="ed_date" class="form-control jalali-date" required></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_shift') ?> *</label>
                        <div class="d-flex gap-3" style="align-items:center;height:100%">
                            <label class="form-check"><input type="radio" name="shift" value="day" id="ed_shift_day"><span>☀️ <?= t('wh_day') ?></span></label>
                            <label class="form-check"><input type="radio" name="shift" value="night" id="ed_shift_night"><span>🌙 <?= t('wh_night') ?></span></label>
                        </div>
                    </div>
                </div>
                <div id="edSvcRows" style="margin-bottom:10px"></div>
                <div class="text-muted" style="font-size:11px;margin-bottom:8px">ℹ️ <?= t('قیمت واحد خدماتِ قبلاً ثبت‌شده تغییر نمی‌کند؛ برای حذف یک خدمت، تعدادش را خالی یا صفر بگذارید.') ?></div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?></label><input type="text" name="description" id="ed_desc" class="form-control"></div>
                <div class="form-group"><label class="form-label"><?= t('فاکتور') ?> / <?= t('فایل') ?> <span id="ed_file_note" class="text-muted" style="font-size:10px"></span></label><input type="file" name="attachment" class="form-control" accept="image/png,image/jpeg,application/pdf"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button><button type="button" onclick="closeModal('editDailyModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>
<script>
// همهٔ خدمات پیمانکار انتخاب‌شده (فعال و غیرفعال) برای فرم ویرایش
window.SUB_ALL_SERVICES = <?= json_encode(array_map(function ($sv) { return ['id' => (int)$sv['id'], 'name' => (string)$sv['service_name'], 'unit' => (string)($sv['unit_name'] ?? ''), 'price' => (float)$sv['unit_price'], 'active' => (($sv['status'] ?? 'active') === 'active')]; }, $sub_services), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
function openEditDaily(d) {
    document.getElementById('ed_entry_id').value = d.id;
    document.getElementById('ed_sub_id').value = d.sub_id;
    document.getElementById('ed_date').value = d.date;
    document.getElementById('ed_desc').value = d.desc || '';
    document.getElementById('ed_shift_' + (d.shift === 'night' ? 'night' : 'day')).checked = true;
    document.getElementById('ed_file_note').textContent = d.has_file ? '(<?= t('فایل فعلی حفظ می‌شود؛ برای تعویض، فایل جدید انتخاب کنید') ?>)' : '';
    var box = document.getElementById('edSvcRows'); box.innerHTML = '';
    (window.SUB_ALL_SERVICES || []).forEach(function (sv) {
        var has = d.qty && Object.prototype.hasOwnProperty.call(d.qty, sv.id);
        if (!sv.active && !has) return;
        var row = document.createElement('div'); row.className = 'form-row';
        var g = document.createElement('div'); g.className = 'form-group';
        var lb = document.createElement('label'); lb.className = 'form-label';
        lb.textContent = sv.name + ' ';
        var sp = document.createElement('span'); sp.className = 'text-muted'; sp.style.fontSize = '10px';
        sp.textContent = '(' + Number(sv.price).toLocaleString() + ' / ' + sv.unit + (sv.active ? '' : ' — <?= t('غیرفعال') ?>') + ')';
        lb.appendChild(sp);
        var inp = document.createElement('input'); inp.type = 'number'; inp.name = 'qty[' + sv.id + ']'; inp.className = 'form-control';
        inp.min = '0'; inp.step = '0.5'; inp.placeholder = '<?= t('تعداد') ?>'; if (has) inp.value = d.qty[sv.id];
        g.appendChild(lb); g.appendChild(inp); row.appendChild(g); box.appendChild(row);
    });
    openModal('editDailyModal');
}
</script>
<!-- §9: مودال ثبت روزانه خدمات -->
<div id="dailyModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title">📋 <?= t('sub_daily_entry') ?></h3><button onclick="closeModal('dailyModal')" class="modal-close">✕</button></div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="add_daily_entry">
            <input type="hidden" name="entry_sub_id" id="daily_sub_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?> *</label><input type="text" name="entry_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_shift') ?> *</label>
                        <div class="d-flex gap-3" style="align-items:center;height:100%">
                            <label class="form-check"><input type="radio" name="shift" value="day" checked><span>☀️ <?= t('wh_day') ?></span></label>
                            <label class="form-check"><input type="radio" name="shift" value="night"><span>🌙 <?= t('wh_night') ?></span></label>
                        </div>
                    </div>
                </div>
                <div id="dailySvcRows" style="margin-bottom:10px">
                    <?php
                    $daily_modal_services = array_values(array_filter($sub_services, function ($svc) {
                        return ($svc['status'] ?? 'active') === 'active';
                    }));
                    foreach ($daily_modal_services as $dsv):
                    ?>
                    <div class="form-row daily-static-service">
                        <div class="form-group">
                            <label class="form-label">
                                <?= htmlspecialchars($dsv['service_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                <span class="text-muted" style="font-size:10px">
                                    (<?= number_format((float)($dsv['unit_price'] ?? 0)) ?> <?= t('ت') ?> / <?= htmlspecialchars($dsv['unit_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>)
                                </span>
                            </label>
                            <input type="number" name="qty[<?= (int)$dsv['id'] ?>]" class="form-control" min="0" step="0.5" placeholder="<?= t('تعداد') ?>" oninput="dailyTotal()">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="dailyEmptyWarn" class="text-muted" style="display:<?= $daily_modal_services ? 'none' : 'block' ?>;font-size:12px;padding:10px;background:var(--bg);border-radius:8px">⚠️ <?= t('برای') ?> <?= t('این') ?> <?= t('پیمانکار') ?> <?= t('هنوز') ?> <?= t('خدمتی') ?> <?= t('تعریف نشده') ?> — <?= t('اول') ?> <?= t('lea_from') ?> <?= t('پنل') ?> «<?= t('sub_services') ?>» <?= t('قیمت') ?> <?= t('واحدها') ?> <?= t('را') ?> <?= t('تعریف') ?> <?= t('کنید') ?>.</div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?></label><input type="text" name="description" class="form-control"></div><div class="form-group"><label class="form-label"><?= t('فاکتور') ?> / <?= t('فایل') ?> <?= t('خدمات') ?></label><input type="file" name="attachment" class="form-control" accept="image/png,image/jpeg,application/pdf"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-warning"><?= t('ثبت') ?> <?= t('sal_daily') ?></button><button type="button" onclick="closeModal('dailyModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>

<script>
// سرویس‌های فعال هر پیمانکار برای مودال روزانه
<?php
// برای کارت‌های دیگر هم سرویس‌ها لازم‌اند (وقتی face_sub انتخاب نشده)
$all_svc_js = [];
try {
    $stj = $pdo->prepare("SELECT s.subcontractor_id, s.id, s.service_name, s.unit_name, s.unit_price FROM subcontractor_services s WHERE s.workshop_id = ? AND s.status = 'active' ORDER BY s.id");
    $stj->execute([$ws_id]);
    foreach ($stj->fetchAll() as $sj) { $all_svc_js[$sj['subcontractor_id']][] = $sj; }
} catch (Exception $e3) {}
// خدمات پیمانکار انتخاب‌شده را مستقیماً از داده‌ی همین صفحه نیز اضافه می‌کنیم
// تا اگر کوئری تجمیعی با مشکل مواجه شد، مودال ثبت روزانه خالی نماند.
if ($face_sub > 0 && !empty($sub_services)) {
    $all_svc_js[(int)$face_sub] = array_values(array_filter($sub_services, function ($svc) {
        return ($svc['status'] ?? 'active') === 'active';
    }));
}
?>
window.SUB_SERVICES = <?= json_encode($all_svc_js ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

window.openDailyModal = function(subId) {
    document.getElementById('daily_sub_id').value = subId;
    var box = document.getElementById('dailySvcRows');
    var warn = document.getElementById('dailyEmptyWarn');
    var list = (window.SUB_SERVICES || {})[String(subId)] || [];
    if (!Array.isArray(list)) list = [list];
    // اگر داده‌ی JS در مرورگر موجود نبود، ردیف‌های PHP داخل مودال را حفظ کن.
    if (list.length) box.innerHTML = '';
    warn.style.display = list.length ? 'none' : 'block';
    list.forEach(function(sv) {
        if (!sv || typeof sv !== 'object') return;
        var row = document.createElement('div');
        row.className = 'form-row';
        row.innerHTML = '<div class="form-group"><label class="form-label">' + sv.service_name + ' <span class="text-muted" style="font-size:10px">(' + Number(sv.unit_price).toLocaleString() + ' <?= t('ت') ?> / ' + sv.unit_name + ')</span></label>' +
            '<input type="number" name="qty[' + sv.id + ']" class="form-control" min="0" step="0.5" placeholder="<?= t('تعداد') ?>" oninput="dailyTotal()"></div>';
        box.appendChild(row);
    });
    window._dlist = list;
    if (typeof openModal === 'function') {
        openModal('dailyModal');
    } else {
        var modal = document.getElementById('dailyModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }
    }
    dailyTotalCalc(list);
};
function dailyTotal() { dailyTotalCalc(window._dlist || []); }
function dailyTotalCalc(list) {
    var prev = document.getElementById('dailyPrev');
    if (prev) prev.remove();
    if (!list.length) return;
    var total = 0;
    document.querySelectorAll('#dailySvcRows input').forEach(function(inp, ix) {
        var q = parseFloat(inp.value) || 0;
        total += q * (parseFloat(list[ix].unit_price) || 0);
    });
    if (total > 0) {
        var d = document.createElement('div');
        d.id = 'dailyPrev';
        d.style.cssText = 'padding:8px 12px;background:rgba(245,158,11,0.12);border-radius:8px;font-size:13px;margin-bottom:8px';
        d.innerHTML = '💰 <?= t('rep_total') ?> <?= t('این') ?> <?= t('ثبت') ?>: <strong>' + total.toLocaleString() + ' <?= t('sal_toman') ?></strong>';
        document.getElementById('dailySvcRows').after(d);
    }
}
// ریپیتر خدمات در مودال‌ها
function svcAddRow(boxId) {
    var d = document.createElement('div');
    d.className = 'd-flex gap-2 mb-2 svc-row';
    d.innerHTML = '<input type="hidden" name="svc_id[]" value="0">' +
        '<input type="text" name="svc_name[]" class="form-control" placeholder="<?= t('sal_type') ?> <?= t('خدمت') ?>" style="flex:2">' +
        '<input type="text" name="svc_unit[]" class="form-control" placeholder="<?= t('واحد') ?> (<?= t('عدد') ?>/<?= t('پرس') ?>/<?= t('کپسول') ?>)" style="flex:1">' +
        '<input type="number" name="svc_price[]" class="form-control" placeholder="<?= t('unit_price') ?>" min="0" style="flex:1">';
    document.getElementById(boxId).appendChild(d);
}
</script>

<!-- §v12 §4.2: فرم حذف پیمانکار -->
<?php if (has_permission('manage_subcontractors')): ?>
<form method="POST" id="delSubForm" style="display:none"><?= get_csrf_field() ?>
    <input type="hidden" name="action" value="delete_sub">
    <input type="hidden" name="sub_id" id="delSubId" value="">
</form>
<script>
function deleteSub(id, name) {
    if (!confirm('<?= t('حذف') ?> <?= t('پیمانکار') ?> «' + name + '»؟\n\n• <?= t('اگر') ?> <?= t('سابقه') ?> <?= t('مالی') ?> <?= t('نداشته باشد') ?>، <?= t('کاملاً') ?> <?= t('حذف') ?> <?= t('می‌شود') ?>.\n• <?= t('اگر') ?> <?= t('سابقه') ?> <?= t('داشته باشد') ?>، <?= t('غیرفعال') ?> <?= t('می‌شود') ?> <?= t('و') ?> <?= t('سوابقش حفظ') ?> <?= t('می‌ماند') ?>.')) return;
    document.getElementById('delSubId').value = id;
    document.getElementById('delSubForm').submit();
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
