<?php
$pageTitle = t('nav_personnel');
$ws_id = $_SESSION['workshop_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $full_name = sanitize($_POST['full_name']);
        $national_code = sanitize($_POST['national_code'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $is_karigar = (int)($_POST['is_karigar'] ?? 0);
        $contract_type = sanitize($_POST['contract_type'] ?? '');
        $specialty = sanitize($_POST['specialty'] ?? '');
        $contract_start = to_gregorian($_POST['contract_start'] ?? '');
        $contract_end = to_gregorian($_POST['contract_end'] ?? '');
        $insurance_number = sanitize($_POST['insurance_number'] ?? '');
        $insurance_amount = (float)($_POST['insurance_amount'] ?? 0);
        $iban = sanitize($_POST['iban'] ?? '');
        $card_number = sanitize($_POST['card_number'] ?? '');
        $account_number = sanitize($_POST['account_number'] ?? '');
        $account_name = sanitize($_POST['account_name'] ?? '');
        $work_role = sanitize($_POST['work_role'] ?? '');

        $person_type = (int)($_POST['person_type'] ?? $is_karigar);

        // کد ملی شناسهٔ قوی است؛ اگر قبلاً وجود دارد، حساب تازه نساز و از عضویت/انتقال حساب موجود استفاده کن.
        if ($national_code !== '') {
            $same_code = $pdo->prepare("SELECT id, full_name FROM users WHERE TRIM(national_code) = ? LIMIT 1");
            $same_code->execute([$national_code]);
            if ($existing_person = $same_code->fetch()) {
                showToast(strtr(t('این کد ملی قبلاً برای «{name}» ثبت شده است؛ از حساب موجود و گزینهٔ انتقال/عضویت استفاده کنید.'), ['{name}' => $existing_person['full_name']]), 'error');
                redirect("index.php?page=personnel");
                exit;
            }
        }

        if ($person_type == 2) {
            // پیمانکار پرسنلی: نرخ ساعتی دارد و پس از لینک‌شدن در صورت‌وضعیت تجمیع می‌شود.
            $username = 'paymankar_' . bin2hex(random_bytes(4));
            // پیمانکار ورود پیش‌فرض شناخته‌شده ندارد؛ در صورت نیاز مدیر رمز اختصاصی تعیین می‌کند.
            $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $position = sanitize($_POST['position'] ?? '');
            $monthly_salary = 0;
            $hourly_salary = max(0, (float)($_POST['hourly_salary'] ?? 0));
            // برای سازگاری با تاریخچه‌های قدیمی، معادل روزانه هم ذخیره می‌شود؛ محاسبهٔ پیمانکار از hourly_salary استفاده می‌کند.
            $daily_salary = $hourly_salary * 8;
            $description = sanitize($_POST['description'] ?? '');
            $is_karigar = 2;
        } elseif ($person_type == 1) {
            // Worker: auto-generate username, no password needed
            $username = 'karigar_' . bin2hex(random_bytes(4));
            // کارگر در فرم فعلی ورود ندارد؛ رمز تصادفی از رمز پیش‌فرض قابل حدس امن‌تر است.
            $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $position = '';
            $monthly_salary = 0;
            $daily_salary = (float)($_POST['daily_salary'] ?? 0);
            $hourly_salary = 0;
            $description = sanitize($_POST['description'] ?? '');
            $is_karigar = 1;
        } else {
            // Employee: manual username/password
            $username = sanitize($_POST['username']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $position = sanitize($_POST['position'] ?? '');
            $monthly_salary = (float)($_POST['monthly_salary'] ?? 0);
            $daily_salary = 0;
            $hourly_salary = 0;
            $description = '';
            $is_karigar = 0;
        }
        
        $system_role = 'worker';
        if ($person_type == 0 && !empty($_POST['system_role'])) {
            $sr = sanitize($_POST['system_role']);
            if (in_array($sr, ['worker', 'project_manager', 'nazer_kol', 'karfarma', 'nazer'], true)) {
                $system_role = $sr;
            }
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, national_code, phone, role, is_karigar, position, work_role, description, avatar, address, iban, card_number, account_number, account_name, specialty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $password, $full_name, $national_code, $phone, $system_role, $is_karigar, $position, $work_role, $description, '', $address, $iban, $card_number, $account_number, $account_name, $specialty]);
            $user_id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO workshop_personnel (workshop_id, user_id, daily_salary, monthly_salary, hourly_salary, contract_start, contract_end, contract_type, insurance_number, insurance_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ws_id, $user_id, $daily_salary, $monthly_salary, $hourly_salary, $contract_start ?: null, $contract_end ?: null, $contract_type, $insurance_number, $insurance_amount]);
            
            // §v11: تیک‌های پرمیشنِ فرم افزودن حذف شد — دسترسی صفحات از دکمهٔ 🛡️ «دسترسی» در لیست تنظیم می‌شود
            
            // Salary history
            $salary_val = $is_karigar ? $daily_salary : $monthly_salary;
            $stmt = $pdo->prepare("INSERT INTO salary_history (user_id, workshop_id, new_salary, changed_by, reason) VALUES (?, ?, ?, ?, 'ایجاد اولیه')");
            $stmt->execute([$user_id, $ws_id, $salary_val, $_SESSION['user_id']]);
            
            $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'create', 'personnel', ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $user_id, $full_name]);
            
            showToast(t('پرسنل با موفقیت اضافه شد'));
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                showToast(t('نام کاربری تکراری است'), 'error');
            } else {
                showToast(t('خطا: ') . $e->getMessage(), 'error');
            }
        }
        redirect("index.php?page=personnel");
    }
    
    if ($action === 'update') {
        $user_id = (int)$_POST['user_id'];
        $full_name = sanitize($_POST['full_name']);
        $national_code = sanitize($_POST['national_code'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $is_karigar = (int)($_POST['is_karigar'] ?? 0);
        $contract_type = sanitize($_POST['contract_type'] ?? '');
        $specialty = sanitize($_POST['specialty'] ?? '');
        $contract_start = to_gregorian($_POST['contract_start'] ?? '');
        $contract_end = to_gregorian($_POST['contract_end'] ?? '');
        $insurance_number = sanitize($_POST['insurance_number'] ?? '');
        $insurance_amount = (float)($_POST['insurance_amount'] ?? 0);
        $iban = sanitize($_POST['iban'] ?? '');
        $card_number = sanitize($_POST['card_number'] ?? '');
        $account_number = sanitize($_POST['account_number'] ?? '');
        $account_name = sanitize($_POST['account_name'] ?? '');
        $work_role = sanitize($_POST['work_role'] ?? '');

        if ($is_karigar == 2) {
            $position = '';
            $monthly_salary = 0;
            $hourly_salary = max(0, (float)($_POST['hourly_salary'] ?? 0));
            $daily_salary = $hourly_salary * 8; // سازگاری با اطلاعات قدیمی
            $description = sanitize($_POST['description'] ?? '');
        } elseif ($is_karigar == 1) {
            $position = '';
            $monthly_salary = 0;
            $daily_salary = (float)($_POST['daily_salary'] ?? 0);
            $hourly_salary = 0;
            $description = sanitize($_POST['description'] ?? '');
        } else {
            $position = sanitize($_POST['position'] ?? '');
            $monthly_salary = (float)($_POST['monthly_salary'] ?? 0);
            $daily_salary = 0;
            $hourly_salary = 0;
            $description = '';
        }

        // حقوق فعلی قبل از ویرایش — بر اساس «نوع» فعلی (کارمند=ماهانه، کارگر=روزانه، پیمانکار=ساعتی)
        $old_stmt = $pdo->prepare("SELECT daily_salary, monthly_salary, hourly_salary FROM workshop_personnel WHERE user_id = ? AND workshop_id = ?");
        $old_stmt->execute([$user_id, $ws_id]);
        $old_salary_row = $old_stmt->fetch() ?: ['daily_salary' => 0, 'monthly_salary' => 0, 'hourly_salary' => 0];
        if ($is_karigar == 2)      $old_salary = (float)($old_salary_row['hourly_salary'] ?? 0);
        elseif ($is_karigar == 1)  $old_salary = (float)($old_salary_row['daily_salary'] ?? 0);
        else                       $old_salary = (float)($old_salary_row['monthly_salary'] ?? 0);

        // Username: editable from edit-modal — must be unique across users
        $new_username = trim($_POST['username'] ?? '');
        if ($new_username !== '') {
            $new_username = sanitize($new_username);
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $chk->execute([$new_username, $user_id]);
            if ($chk->fetch()) {
                showToast(t('این نام کاربری قبلاً گرفته شده — یک نام دیگر انتخاب کنید'), 'error');
                redirect("index.php?page=personnel");
            }
        }

        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, national_code = ?, phone = ?, is_karigar = ?, position = ?, work_role = ?, description = ?, address = ?, iban = ?, card_number = ?, account_number = ?, account_name = ?, specialty = ? WHERE id = ?");
        $stmt->execute([$full_name, $national_code, $phone, $is_karigar, $position, $work_role, $description, $address, $iban, $card_number, $account_number, $account_name, $specialty, $user_id]);

        if (isset($_POST['system_role']) && (is_strict_admin() || is_project_manager())) {
            $sr = sanitize($_POST['system_role']);
            if (in_array($sr, ['worker', 'project_manager', 'nazer_kol', 'karfarma', 'nazer'], true)) {
                $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$sr, $user_id]);
            }
        }

        if ($new_username !== '') {
            $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->execute([$new_username, $user_id]);
        }
        
        $stmt = $pdo->prepare("UPDATE workshop_personnel SET daily_salary = ?, monthly_salary = ?, hourly_salary = ?, contract_start = ?, contract_end = ?, contract_type = ?, insurance_number = ?, insurance_amount = ? WHERE user_id = ? AND workshop_id = ?");
        $stmt->execute([$daily_salary, $monthly_salary, $hourly_salary, $contract_start ?: null, $contract_end ?: null, $contract_type, $insurance_number, $insurance_amount, $user_id, $ws_id]);
        
        // Update password if provided
        if (!empty($_POST['new_password'])) {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $user_id]);
        }
        
        // Salary history — §fix: ویرایش حقوق باید مبنای محاسبات (salary_history) را هم اصلاح کند
        $new_effective_salary = ($is_karigar == 2) ? $hourly_salary : ($is_karigar ? $daily_salary : $monthly_salary);
        if ($is_karigar == 2) {
            if ((float)$old_salary != (float)$new_effective_salary) {
                $stmt = $pdo->prepare("INSERT INTO salary_history (user_id, workshop_id, old_salary, new_salary, changed_by, reason, start_date) VALUES (?, ?, ?, ?, ?, 'ویرایش نرخ پرسنل', ?)");
                $stmt->execute([$user_id, $ws_id, $old_salary, $new_effective_salary, $_SESSION['user_id'], date('Y-m-d')]);
            }
        } else {
            $hst_now = get_salary_history($pdo, $user_id, true);
            $eff_now = salary_at_date($hst_now, date('Y-m-d'), $old_salary);
            // اگر مقدار عوض شده، یا مقدار فرم با آنچه محاسبات واقعاً استفاده می‌کنند فرق دارد → همگام کن
            if ((float)$old_salary != (float)$new_effective_salary || abs((float)$eff_now - (float)$new_effective_salary) > 0.5) {
                salary_apply_edit($pdo, $user_id, $ws_id, $old_salary, $new_effective_salary, $_SESSION['user_id']);
            }
        }

        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id) VALUES (?, 'update', 'personnel', ?)");
        $stmt->execute([$_SESSION['user_id'], $user_id]);
        
        showToast(t('اطلاعات بروزرسانی شد'));
        redirect("index.php?page=personnel");
    }
    
    // ═══ افزایش حقوق تکی/گروهی (اسناد §1) — مبلغی یا درصدی، با تاریخ اجرای مشخص ═══
    if ($action === 'salary_raise' && is_strict_admin()) {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $mode = ($_POST['raise_mode'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
        $value = (float)($_POST['raise_value'] ?? 0);
        $reason = sanitize($_POST['reason'] ?? '');
        $start_date = to_gregorian($_POST['start_date'] ?? '');
        if (!$start_date) $start_date = date('Y-m-d');

        $target_ids = [];
        $scope = $_POST['scope'] ?? 'single';
        if ($scope === 'single' && $user_id) {
            $target_ids = [$user_id];
        } else {
            $q = "SELECT u.id FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' AND u.is_karigar != 2";
            if ($scope === 'workers') $q .= " AND u.is_karigar = 1";
            elseif ($scope === 'employees') $q .= " AND u.is_karigar = 0";
            $stx = $pdo->prepare($q);
            $stx->execute([$ws_id]);
            $target_ids = array_column($stx->fetchAll(), 'id');
        }

        $done_n = 0;
        foreach ($target_ids as $tid) {
            $stx = $pdo->prepare("SELECT u.is_karigar, wp.daily_salary, wp.monthly_salary FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE u.id = ? AND wp.workshop_id = ?");
            $stx->execute([$tid, $ws_id]);
            $info = $stx->fetch();
            if (!$info) continue;
            $wp_base = ((int)$info['is_karigar'] === 1) ? (float)$info['daily_salary'] : (float)$info['monthly_salary'];
            // بیس = حقوق مؤثر در تاریخ اجرا (از تاریخچه)، نه فقط مقدار خام wp
            $hst = get_salary_history($pdo, $tid, true);
            $base = salary_at_date($hst, $start_date, $wp_base);
            $new = $mode === 'percent' ? (int)round($base * (1 + $value / 100)) : (int)round($base + $value);
            if ($new < 0) $new = 0;

            $stmt = $pdo->prepare("INSERT INTO salary_history (user_id, workshop_id, old_salary, new_salary, changed_by, reason, start_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tid, $ws_id, $base, $new, $_SESSION['user_id'], $reason, $start_date]);

            // اگر تاریخ اجرا رسیده (امروز یا گذشته)، حقوق فعلی پرسنل = «حقوق مؤثر امروز» می‌شود
            // (نه لزوماً همین رکورد؛ ممکن است افزایش دیگری با تاریخ نزدیک‌تر وجود داشته باشد)
            if ($start_date <= date('Y-m-d')) {
                $eff_today = salary_at_date(get_salary_history($pdo, $tid, true), date('Y-m-d'), $new);
                $col = ((int)$info['is_karigar'] === 1) ? 'daily_salary' : 'monthly_salary';
                $pdo->prepare("UPDATE workshop_personnel SET $col = ? WHERE user_id = ? AND workshop_id = ?")->execute([$eff_today, $tid, $ws_id]);
            } else {
                get_salary_history($pdo, $tid, true);
            }
            $done_n++;
        }
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'salary_raise', 'personnel', ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $user_id, "$done_n نفر — " . ($mode === 'percent' ? "$value٪" : number_format($value) . ' تومان') . " از " . to_jalali($start_date)]);
        showToast($start_date > date('Y-m-d') ? strtr(t('افزایش حقوق برای {count} نفر ثبت شد — از «{date}» در محاسبات اعمال می‌شود 📅'), ['{count}' => $done_n, '{date}' => to_jalali($start_date)]) : strtr(t('افزایش حقوق برای {count} نفر ثبت شد'), ['{count}' => $done_n]) . ' ✅');
        redirect("index.php?page=personnel");
    }

    // حذف رکورد تاریخچه + بازمحاسبه حقوق فعلی در صورت نیاز
    if ($action === 'salary_raise_delete' && is_strict_admin()) {
        $hid = (int)$_POST['history_id'];
        $stx = $pdo->prepare("SELECT * FROM salary_history WHERE id = ? AND workshop_id = ?");
        $stx->execute([$hid, $ws_id]);
        $h = $stx->fetch();
        if ($h) {
            $pdo->prepare("DELETE FROM salary_history WHERE id = ?")->execute([$hid]);
            if ($h['start_date'] && $h['start_date'] !== '0000-00-00' && $h['start_date'] <= date('Y-m-d')) {
                // حقوق فعلی = حقوق مؤثر امروز پس از حذف؛ اگر رکوردی نماند، به «حقوق قبل از افزایش» برمی‌گردد
                $kk = (int)$pdo->query("SELECT is_karigar FROM users WHERE id = " . (int)$h['user_id'])->fetchColumn();
                $col = ($kk === 1) ? 'daily_salary' : 'monthly_salary';
                $cur = $pdo->prepare("SELECT $col FROM workshop_personnel WHERE user_id = ? AND workshop_id = ?");
                $cur->execute([$h['user_id'], $ws_id]);
                $cur_val = (float)$cur->fetchColumn();
                $hst_after = get_salary_history($pdo, (int)$h['user_id'], true);
                if (!empty($hst_after)) {
                    $eff = salary_at_date($hst_after, date('Y-m-d'), $cur_val);
                } else {
                    $eff = ($h['old_salary'] !== null && $h['old_salary'] !== '') ? (float)$h['old_salary'] : $cur_val;
                }
                $pdo->prepare("UPDATE workshop_personnel SET $col = ? WHERE user_id = ? AND workshop_id = ?")->execute([$eff, $h['user_id'], $ws_id]);
            }
            showToast(t('رکورد تاریخچه حذف شد'));
        }
        redirect("index.php?page=personnel");
    }

    if ($action === 'transfer') {
        $user_id = (int)$_POST['user_id'];
        $to_workshop = (int)$_POST['to_workshop'];
        $transfer_date = to_gregorian($_POST['transfer_date'] ?? date('Y/m/d'));

        // چک: کارگاه مقصد معتبر و متفاوت باشه
        $ws_chk = $pdo->prepare("SELECT id, name FROM workshops WHERE id = ? AND status = 'active'");
        $ws_chk->execute([$to_workshop]);
        $ws_to = $ws_chk->fetch();
        if (!$ws_to || $to_workshop === (int)$ws_id) {
            showToast(t('کارگاه مقصد معتبر نیست'), 'error');
            redirect("index.php?page=personnel");
        }

        // اگر کاربر مدیر کارگاه است (و ادمین یا مدیر پروژه نیست) → ثبت درخواست برای تایید مدیر پروژه
        if (!is_strict_admin() && !is_project_manager()) {
            $stmt = $pdo->prepare("INSERT INTO pending_transfers (transfer_type, source_workshop_id, target_workshop_id, entity_id, quantity, transfer_date, notes, requested_by, status) VALUES ('personnel', ?, ?, ?, 1, ?, ?, ?, 'pending')");
            $stmt->execute([$ws_id, $to_workshop, $user_id, $transfer_date, sanitize($_POST['notes'] ?? ''), $_SESSION['user_id']]);
            showToast(t('درخواست انتقال پرسنل به کارگاه مقصد ثبت شد و پس از تأیید مدیر پروژه اعمال خواهد شد'));
            redirect("index.php?page=personnel");
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Add to new workshop — با کل فیلدهای حقوق و قرارداد
            $stmt = $pdo->prepare("INSERT INTO workshop_personnel (workshop_id, user_id, daily_salary, monthly_salary, hire_date, contract_start, contract_end, contract_type, insurance_number, insurance_amount, status)
                SELECT ?, ?, daily_salary, monthly_salary, hire_date, contract_start, contract_end, contract_type, insurance_number, insurance_amount, 'active'
                FROM workshop_personnel WHERE user_id = ? AND workshop_id = ?
                ON DUPLICATE KEY UPDATE status = 'active',
                    daily_salary = VALUES(daily_salary), monthly_salary = VALUES(monthly_salary),
                    hire_date = VALUES(hire_date), contract_start = VALUES(contract_start),
                    contract_end = VALUES(contract_end), contract_type = VALUES(contract_type),
                    insurance_number = VALUES(insurance_number), insurance_amount = VALUES(insurance_amount)");
            $stmt->execute([$to_workshop, $user_id, $user_id, $ws_id]);

            // اگر به هر دلیلی رکوردی در مقصد نشد (مثلاً رکورد مبدأ وجود نداشت)، یک رکورد پایه بساز
            $chk = $pdo->prepare("SELECT COUNT(*) FROM workshop_personnel WHERE workshop_id = ? AND user_id = ?");
            $chk->execute([$to_workshop, $user_id]);
            if (!$chk->fetchColumn()) {
                $pdo->prepare("INSERT INTO workshop_personnel (workshop_id, user_id, status) VALUES (?, ?, 'active')")->execute([$to_workshop, $user_id]);
            }

            // حذف قطعی از کارگاه قبلی تا نیرو به هیچ وجه دوکارگاهه نماند
            $pdo->prepare("DELETE FROM workshop_personnel WHERE user_id = ? AND workshop_id != ?")->execute([$user_id, $to_workshop]);

            // Copy permissions و پاک‌سازی از کارگاه مبدأ
            $stmt = $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, workshop_id, permission) SELECT ?, ?, permission FROM user_permissions WHERE user_id = ? AND workshop_id = ?");
            $stmt->execute([$user_id, $to_workshop, $user_id, $ws_id]);
            $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND workshop_id != ?")->execute([$user_id, $to_workshop]);

            $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'transfer', 'personnel', ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $user_id, "انتقال به کارگاه {$ws_to['name']}"]);

            $pdo->commit();
            showToast(strtr(t('انتقال به «{workshop}» با حفظ حقوق انجام شد'), ['{workshop}' => $ws_to['name']]));
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('transfer failed: ' . $e->getMessage());
            showToast(t('خطا در انتقال: ') . $e->getMessage(), 'error');
        }
        redirect("index.php?page=personnel");
    }

    if ($action === 'approve_transfer' && (is_strict_admin() || is_project_manager())) {
        $trans_id = (int)$_POST['transfer_id'];
        $st = $pdo->prepare("SELECT * FROM pending_transfers WHERE id = ? AND transfer_type = 'personnel' AND status = 'pending'");
        $st->execute([$trans_id]);
        $tr = $st->fetch();
        if ($tr) {
            $user_id = $tr['entity_id'];
            $from_ws = $tr['source_workshop_id'];
            $to_ws = $tr['target_workshop_id'];

            $ws_chk = $pdo->prepare("SELECT id, name FROM workshops WHERE id = ?");
            $ws_chk->execute([$to_ws]);
            $ws_to = $ws_chk->fetch();

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO workshop_personnel (workshop_id, user_id, daily_salary, monthly_salary, hire_date, contract_start, contract_end, contract_type, insurance_number, insurance_amount, status)
                    SELECT ?, ?, daily_salary, monthly_salary, hire_date, contract_start, contract_end, contract_type, insurance_number, insurance_amount, 'active'
                    FROM workshop_personnel WHERE user_id = ? AND workshop_id = ?
                    ON DUPLICATE KEY UPDATE status = 'active',
                        daily_salary = VALUES(daily_salary), monthly_salary = VALUES(monthly_salary),
                        hire_date = VALUES(hire_date), contract_start = VALUES(contract_start),
                        contract_end = VALUES(contract_end), contract_type = VALUES(contract_type),
                        insurance_number = VALUES(insurance_number), insurance_amount = VALUES(insurance_amount)");
                $stmt->execute([$to_ws, $user_id, $user_id, $from_ws]);

                $chk = $pdo->prepare("SELECT COUNT(*) FROM workshop_personnel WHERE workshop_id = ? AND user_id = ?");
                $chk->execute([$to_ws, $user_id]);
                if (!$chk->fetchColumn()) {
                    $pdo->prepare("INSERT INTO workshop_personnel (workshop_id, user_id, status) VALUES (?, ?, 'active')")->execute([$to_ws, $user_id]);
                }

                // حذف قطعی از کارگاه مبدأ تا نیرو دوکارگاهه نشود
                $pdo->prepare("DELETE FROM workshop_personnel WHERE user_id = ? AND workshop_id != ?")->execute([$user_id, $to_ws]);

                $stmt = $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, workshop_id, permission) SELECT ?, ?, permission FROM user_permissions WHERE user_id = ? AND workshop_id = ?");
                $stmt->execute([$user_id, $to_ws, $user_id, $from_ws]);
                $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND workshop_id != ?")->execute([$user_id, $to_ws]);

                $pdo->prepare("UPDATE pending_transfers SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
                    ->execute([$_SESSION['user_id'], $trans_id]);

                $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'transfer', 'personnel', ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $user_id, "تأیید انتقال به کارگاه {$ws_to['name']}"]);

                $pdo->commit();
                showToast(strtr(t('انتقال پرسنل به «{workshop}» با موفقیت تأیید و انجام شد'), ['{workshop}' => $ws_to['name']]));
            } catch (Throwable $e) {
                $pdo->rollBack();
                showToast(t('خطا در انتقال: ') . $e->getMessage(), 'error');
            }
        }
        redirect("index.php?page=personnel");
        exit;
    }

    if ($action === 'reject_transfer' && (is_strict_admin() || is_project_manager())) {
        $trans_id = (int)$_POST['transfer_id'];
        $pdo->prepare("UPDATE pending_transfers SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$_SESSION['user_id'], $trans_id]);
        showToast(t('درخواست انتقال پرسنل رد شد'));
        redirect("index.php?page=personnel");
        exit;
    }
    
    if ($action === "update_permissions") {
        // ═══ §v11: ذخیره دسترسی صفحه‌ای (ناظر/ویراستار) ═══
        $user_id = (int)$_POST["user_id"];
        $tu = $pdo->prepare("SELECT username, role, full_name FROM users WHERE id = ?");
        $tu->execute([$user_id]);
        $target = $tu->fetch();
        if (!$target) { showToast(t('کاربر یافت نشد'), 'error'); redirect("index.php?page=personnel"); }
        if (in_array($target['username'], access_special_usernames(), true)) {
            showToast(strtr(t('دسترسی حساب «{name}» ویژه است و طبق روال قبلی ثابت می‌ماند'), ['{name}' => $target['full_name']]), 'error');
            redirect("index.php?page=personnel");
        }
        if (isset($_POST['system_role']) && (is_strict_admin() || is_project_manager())) {
            $sr = sanitize($_POST['system_role']);
            if (in_array($sr, ['worker', 'project_manager', 'nazer_kol', 'karfarma', 'nazer'], true)) {
                $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$sr, $user_id]);
                $target['role'] = $sr;
            }
        }
        $valid  = access_all_valid_keys();
        $posted = (array)($_POST['access'] ?? []);
        $modes  = (array)($_POST['amode'] ?? []);
        $atemps = (array)($_POST['atemp'] ?? []);   // §v12: ناظر موقت تا پایان امروز
        $pdo->prepare("DELETE FROM user_page_access WHERE user_id = ?")->execute([$user_id]);
        $ins_exp = null;
        try { $ins_exp = $pdo->prepare("INSERT INTO user_page_access (user_id, page_key, mode, expires_at) VALUES (?, ?, ?, ?)"); } catch (Throwable $e) {}
        $ins_plain = $pdo->prepare("INSERT INTO user_page_access (user_id, page_key, mode) VALUES (?, ?, ?)");
        $cnt = 0; $tmp_cnt = 0;
        foreach ($posted as $key) {
            $key = (string)$key;
            if (!in_array($key, $valid, true)) continue;
            if (in_array($key, ['reports', 'forms'], true)) continue; // سرگروه‌ها ذخیره نمی‌شوند — زیرگروه‌ها مهم‌اند
            $mode = (($modes[$key] ?? 'editor') === 'viewer') ? 'viewer' : 'editor';
            // §v12: ناظر موقت — expires_at = امروز (فردا به‌طور خودکار نامعتبر می‌شود)
            $exp = ($mode === 'viewer' && !empty($atemps[$key])) ? date('Y-m-d') : null;
            if ($exp !== null && $ins_exp) {
                try { $ins_exp->execute([$user_id, $key, $mode, $exp]); $tmp_cnt++; $cnt++; continue; } catch (Throwable $e) {}
            }
            $ins_plain->execute([$user_id, $key, $mode]);
            $cnt++;
        }
        // سوییچ «مدیر کارگاه» — حفظ/حذف پرچم قدیمیِ دسترسی کامل بر اساس انتخاب مودال
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND workshop_id = ?")->execute([$user_id, $ws_id]);
        if (!empty($_POST['wm_master'])) {
            $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, workshop_id, permission) VALUES (?, ?, 'workshop_manager')")->execute([$user_id, $ws_id]);
        }
        // پل سازگاری: ویراستار ساعت کاری هم یک پرمیشن قدیمی می‌گیرد تا در همهٔ
        // بخش‌های ساعت کاری (نمایش ورودهای ثبت‌شده توسط دیگران، خروج و ویرایش) کامل عمل کند.
        if (in_array('work_hours', $posted, true) && (($modes['work_hours'] ?? 'editor') === 'editor')) {
            $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, workshop_id, permission) VALUES (?, ?, 'manage_work_hours')")->execute([$user_id, $ws_id]);
        }
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'update_access', 'personnel', ?, ?)")
            ->execute([$_SESSION['user_id'], $user_id, "تنظیم دسترسی صفحات «{$target['full_name']}» ({$cnt} مورد" . ($tmp_cnt ? " — {$tmp_cnt} ناظر موقت تا پایان امروز" : "") . ")"]);
        showToast($tmp_cnt ? strtr(t('دسترسی‌های «{name}» بروزرسانی شد ({count} دسترسی — {temp} ناظر موقت تا امروز ⏳)'), ['{name}' => $target['full_name'], '{count}' => $cnt, '{temp}' => $tmp_cnt]) : strtr(t('دسترسی‌های «{name}» بروزرسانی شد ({count} دسترسی)'), ['{name}' => $target['full_name'], '{count}' => $cnt]));
        redirect("index.php?page=personnel");
    }
    
    if ($action === "deactivate") {
        $user_id = (int)$_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$user_id]);
        $stmt = $pdo->prepare("UPDATE workshop_personnel SET status = 'inactive' WHERE user_id = ? AND workshop_id = ?");
        $stmt->execute([$user_id, $ws_id]);
        showToast(t('پرسنل غیرفعال شد'));
        redirect("index.php?page=personnel");
    }


    // حذف کامل و غیرقابل بازگشت: فقط پس از پایان کار و فقط مدیرکل.
    if ($action === 'purge_personnel' && is_strict_admin()) {
        $purge_id = (int)($_POST['user_id'] ?? 0);
        $chk = $pdo->prepare("SELECT u.full_name, wp.status FROM users u JOIN workshop_personnel wp ON wp.user_id=u.id WHERE u.id=? AND wp.workshop_id=?");
        $chk->execute([$purge_id, $ws_id]);
        $target = $chk->fetch();
        if (!$target || $target['status'] !== 'inactive') {
            showToast(t('حذف کامل فقط برای نیرویی مجاز است که ابتدا «پایان کار» برای او ثبت شده باشد.'), 'error');
            redirect("index.php?page=personnel");
        }
        if ($purge_id === (int)$_SESSION['user_id']) {
            showToast(t('حساب کاربر واردشده را نمی‌توان از همین نشست حذف کرد.'), 'error');
            redirect("index.php?page=personnel");
        }
        $pname = $target['full_name'];
        try {
            // بعضی نصب‌های قدیمی کلید خارجی کامل دارند؛ این حذف جامع داده‌های شخص را
            // بدون باقی‌ماندن وابستگیِ شکسته انجام می‌دهد.
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $pdo->beginTransaction();

            // پیمانکارهای متصل به این شخص و همهٔ سوابق خدمات/پرداخت آن‌ها.
            $sub_ids = [];
            try {
                $st = $pdo->prepare("SELECT id FROM subcontractors WHERE linked_user_id = ?");
                $st->execute([$purge_id]);
                $sub_ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $ignore) {}
            foreach ($sub_ids as $sid) {
                try { $pdo->prepare("DELETE FROM subcontractor_daily_entry_items WHERE entry_id IN (SELECT id FROM subcontractor_daily_entries WHERE subcontractor_id=?)")->execute([$sid]); } catch (Throwable $ignore) {}
                try { $pdo->prepare("DELETE FROM subcontractor_daily_entries WHERE subcontractor_id=?")->execute([$sid]); } catch (Throwable $ignore) {}
                try { $pdo->prepare("DELETE FROM subcontractor_services WHERE subcontractor_id=?")->execute([$sid]); } catch (Throwable $ignore) {}
                try { $pdo->prepare("DELETE FROM subcontractor_face_items WHERE subcontractor_id=?")->execute([$sid]); } catch (Throwable $ignore) {}
                try { $pdo->prepare("DELETE FROM subcontractor_payments WHERE subcontractor_id=?")->execute([$sid]); } catch (Throwable $ignore) {}
                try { $pdo->prepare("DELETE FROM subcontractors WHERE id=?")->execute([$sid]); } catch (Throwable $ignore) {}
            }

            // آیتم‌های دستورکاری که شخص ساخته است (و گزارش‌های متصل به آن‌ها).
            $order_ids = [];
            try { $st=$pdo->prepare("SELECT id FROM work_orders WHERE created_by=?"); $st->execute([$purge_id]); $order_ids=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)); } catch(Throwable $ignore) {}
            foreach ($order_ids as $oid) {
                try { $pdo->prepare("DELETE FROM daily_reports WHERE source_item_id IN (SELECT id FROM work_order_items WHERE work_order_id=?)")->execute([$oid]); } catch(Throwable $ignore) {}
                try { $pdo->prepare("DELETE FROM work_order_items WHERE work_order_id=?")->execute([$oid]); } catch(Throwable $ignore) {}
                try { $pdo->prepare("DELETE FROM work_orders WHERE id=?")->execute([$oid]); } catch(Throwable $ignore) {}
            }
            try { $pdo->prepare("UPDATE work_orders SET completed_by=NULL WHERE completed_by=?")->execute([$purge_id]); } catch(Throwable $ignore) {}

            // مالی، حضور، گزارش، مرخصی و WTTP COIN.
            $advance_ids=[];
            try { $st=$pdo->prepare("SELECT id FROM advances WHERE user_id=?"); $st->execute([$purge_id]); $advance_ids=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)); } catch(Throwable $ignore) {}
            foreach ($advance_ids as $aid) { try { $pdo->prepare("DELETE FROM advance_expenses WHERE advance_id=?")->execute([$aid]); } catch(Throwable $ignore) {} }
            foreach ([
                "DELETE FROM advances WHERE user_id = ?",
                "DELETE FROM bonuses_penalties WHERE user_id = ?",
                "DELETE FROM leave_requests WHERE user_id = ?",
                "DELETE FROM daily_reports WHERE user_id = ?",
                "DELETE FROM work_hours WHERE user_id = ?",
                "DELETE FROM missions WHERE user_id = ?",
                "DELETE FROM salary_history WHERE user_id = ?",
                "DELETE FROM salary_calculations WHERE user_id = ?",
                "DELETE FROM karigar_summary WHERE user_id = ?",
                "DELETE FROM arash_coin_transactions WHERE user_id = ?",
                "DELETE FROM arash_coin_redemptions WHERE user_id = ?",
                "DELETE FROM user_page_access WHERE user_id = ?",
                "DELETE FROM user_permissions WHERE user_id = ?",
                "DELETE FROM chat_messages WHERE sender_id = ? OR receiver_id = ?",
                "DELETE FROM notification_recipients WHERE user_id = ?",
                "DELETE FROM archive_files WHERE uploaded_by = ?",
                "DELETE FROM archive_folders WHERE created_by = ?",
                "DELETE FROM workshop_personnel WHERE user_id = ?",
                "DELETE FROM audit_log WHERE user_id = ? OR (entity_type='personnel' AND entity_id = ?)",
            ] as $sql) {
                try {
                    $st = $pdo->prepare($sql);
                    $qmarks = substr_count($sql, '?');
                    $st->execute($qmarks === 2 ? [$purge_id, $purge_id] : [$purge_id]);
                } catch (Throwable $ignore) {}
            }
            // اعلان‌هایی که خودش ساخته بود و ردیف‌های دریافت آن‌ها.
            try {
                $pdo->prepare("DELETE nr FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id WHERE n.created_by=?")->execute([$purge_id]);
                $pdo->prepare("DELETE FROM notifications WHERE created_by=?")->execute([$purge_id]);
            } catch(Throwable $ignore) {}

            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$purge_id]);
            $pdo->commit();
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'purge', 'personnel', ?, ?)")
                ->execute([$_SESSION['user_id'], $purge_id, 'حذف کامل و غیرقابل بازگشت «' . $pname . '»']);
            showToast(t('نیرو و تمام سوابق وابستهٔ او از سایت حذف شد.'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $ignore) {}
            showToast(t('خطا در حذف کامل: ') . $e->getMessage(), 'error');
        }
        redirect("index.php?page=personnel");
    }

    // ═══ §v12 §6: پایان کار — با تاریخ؛ سوابق حفظ می‌شود و از آن تاریخ به بعد در لیست‌های عملیاتی نمی‌آید ═══
    if ($action === 'end_work') {
        $user_id = (int)$_POST['user_id'];
        $raw = trim($_POST['end_date'] ?? '');
        $gdate = $raw !== '' ? ((strpos($raw, '/') !== false) ? to_gregorian($raw) : $raw) : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gdate)) $gdate = date('Y-m-d');
        $note = sanitize($_POST['end_note'] ?? '');
        $nm = $pdo->prepare("SELECT full_name FROM users WHERE id = ?"); $nm->execute([$user_id]); $pname = (string)$nm->fetchColumn();
        $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$user_id]);
        $pdo->prepare("UPDATE workshop_personnel SET status = 'inactive', ended_at = ? WHERE user_id = ? AND workshop_id = ?")->execute([$gdate, $user_id, $ws_id]);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'end_work', 'personnel', ?, ?)")
            ->execute([$_SESSION['user_id'], $user_id, "پایان کار «{$pname}» از " . to_jalali($gdate) . ($note !== '' ? " — {$note}" : '')]);
        // §2.5/§14: هشدار امانت‌ها و کالاهای برنگشتهٔ این نفر قبل از تسویه
        $cust_cnt = 0;
        try {
            $stc = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions t WHERE t.workshop_id = ? AND t.deleted_at IS NULL AND t.return_date IS NULL AND t.type IN ('checkout','consumable') AND t.is_consumable = 0 AND (t.person_id = ? OR REPLACE(REPLACE(TRIM(t.person_name), 'ي', 'ی'), 'ك', 'ک') = ?)");
            $norm_name = str_replace(['ي', 'ك'], ['ی', 'ک'], trim($pname));
            $stc->execute([$ws_id, $user_id, $norm_name]);
            $cust_cnt = (int)$stc->fetchColumn();
        } catch (Throwable $e) { $cust_cnt = 0; }
        showToast(strtr(t('پایان کار «{name}» از {date} ثبت شد — حقوق ماه‌هایی که کارکرد داشته حفظ می‌ماند'), ['{name}' => $pname, '{date}' => to_jalali($gdate)]) . ($cust_cnt > 0 ? strtr(t(' ⚠️ توجه: {count} قلم کالای امانی/تحویلی برنگشته نزد «{name}» است — قبل از تسویه در صفحه انبار (تب گزارش شخص) بررسی و پس بگیرید!'), ['{count}' => $cust_cnt, '{name}' => $pname]) : ''), $cust_cnt > 0 ? 'warning' : 'success');
        redirect("index.php?page=personnel");
    }

    // ═══ §v12 §6: شروع کار مجدد ═══
    if ($action === 'restart_work') {
        $user_id = (int)$_POST['user_id'];
        $raw = trim($_POST['start_date'] ?? '');
        $gdate = $raw !== '' ? ((strpos($raw, '/') !== false) ? to_gregorian($raw) : $raw) : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gdate)) $gdate = date('Y-m-d');
        $nm = $pdo->prepare("SELECT full_name FROM users WHERE id = ?"); $nm->execute([$user_id]); $pname = (string)$nm->fetchColumn();
        $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$user_id]);
        $pdo->prepare("UPDATE workshop_personnel SET status = 'active', ended_at = NULL WHERE user_id = ? AND workshop_id = ?")->execute([$user_id, $ws_id]);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'restart_work', 'personnel', ?, ?)")
            ->execute([$_SESSION['user_id'], $user_id, "شروع کار مجدد «{$pname}» از " . to_jalali($gdate)]);
        showToast(strtr(t('شروع کار مجدد «{name}» از {date} فعال شد 🎉'), ['{name}' => $pname, '{date}' => to_jalali($gdate)]));
        redirect("index.php?page=personnel");
    }
}

// §fix: افزایش‌های تاریخ‌دارِ رسیده را در ستون حقوق فعلی هم اعمال کن (فرم ویرایش/لیست همیشه عدد واقعی را نشان دهد)
salary_sync_current($pdo);

// Get personnel with sorting
$search = $_GET['search'] ?? '';
// انتخاب مرتب‌سازی بعد از رفرش/برگشت حفظ می‌شه (سِشن)
$allowed_sorts = ['full_name', 'is_karigar', 'national_code', 'monthly_salary', 'daily_salary', 'contract_start', 'contract_type'];
if (isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sorts)) {
    $_SESSION['personnel_sort'] = $_GET['sort'];
}
if (isset($_GET['dir']) && in_array($_GET['dir'], ['ASC', 'DESC'])) {
    $_SESSION['personnel_sort_dir'] = $_GET['dir'];
}
$sort = $_SESSION['personnel_sort'] ?? 'full_name';
$sort_dir = $_SESSION['personnel_sort_dir'] ?? 'ASC';
// salary sorts go to workshop_personnel table
$sort_col = ($sort === 'monthly_salary' || $sort === 'daily_salary' || $sort === 'contract_start' || $sort === 'contract_type') ? "wp.$sort" : "u.$sort";

// §v12 §6: پایان‌کارشده‌ها هم در لیست می‌مانند (کمرنگ) تا دکمه «شروع کار مجدد» در دسترس باشد
$where = "wp.workshop_id = ? AND wp.status IN ('active','inactive') AND u.username != 'yaser'";
$params = [$ws_id];

if ($search) {
    $where .= " AND (u.full_name LIKE ? OR u.national_code LIKE ? OR u.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare("SELECT u.*, wp.daily_salary, wp.hourly_salary, wp.hire_date, wp.contract_start, wp.contract_end, wp.contract_type, wp.insurance_number, wp.insurance_amount, wp.monthly_salary, wp.status AS wp_status, wp.ended_at FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE {$where} ORDER BY $sort_col $sort_dir");
$stmt->execute($params);
$personnel = $stmt->fetchAll();

// Other workshops for transfer
$stmt = $pdo->prepare("SELECT id, name FROM workshops WHERE id != ? AND status = 'active'");
$stmt->execute([$ws_id]);
$other_workshops = $stmt->fetchAll();

// §1: شمارش دسته‌ها برای مودال گروهی
$gc_all = 0; $gc_workers = 0; $gc_employees = 0;
foreach ($personnel as $__p) {
    if ($__p['is_karigar'] == 2) continue;
    $gc_all++;
    if ($__p['is_karigar'] == 1) $gc_workers++; else $gc_employees++;
}

// §1: تاریخچه حقوق هر نفر برای مودال تاریخچه
$hist_map = [];
try {
    $sth = $pdo->prepare("SELECT sh.*, u.full_name AS changer FROM salary_history sh LEFT JOIN users u ON u.id = sh.changed_by WHERE sh.workshop_id = ? ORDER BY sh.user_id, (sh.start_date IS NULL) DESC, sh.start_date DESC, sh.id DESC");
    $sth->execute([$ws_id]);
    foreach ($sth->fetchAll() as $h) { $hist_map[$h['user_id']][] = $h; }
} catch (Throwable $e) {}

// §v11: نقشهٔ دسترسی صفحه‌ای همه کاربران (برای پیش‌پرکردن مودال دسترسی)
$__uam = user_access_map_for_admin($pdo);

// درخواست‌های انتقال پرسنل
$pending_personnel_transfers = [];
try {
    if (is_strict_admin() || is_project_manager()) {
        $st = $pdo->query("SELECT pt.*, u.full_name AS person_name, ws_from.name AS from_ws_name, ws_to.name AS to_ws_name, req_u.full_name AS requester_name
            FROM pending_transfers pt
            JOIN users u ON u.id = pt.entity_id
            JOIN workshops ws_from ON ws_from.id = pt.source_workshop_id
            JOIN workshops ws_to ON ws_to.id = pt.target_workshop_id
            LEFT JOIN users req_u ON req_u.id = pt.requested_by
            WHERE pt.transfer_type = 'personnel' AND pt.status = 'pending'
            ORDER BY pt.id DESC");
        $pending_personnel_transfers = $st->fetchAll();
    } else {
        $st = $pdo->prepare("SELECT pt.*, u.full_name AS person_name, ws_to.name AS to_ws_name
            FROM pending_transfers pt
            JOIN users u ON u.id = pt.entity_id
            JOIN workshops ws_to ON ws_to.id = pt.target_workshop_id
            WHERE pt.transfer_type = 'personnel' AND pt.source_workshop_id = ? AND pt.status = 'pending'
            ORDER BY pt.id DESC");
        $st->execute([$ws_id]);
        $pending_personnel_transfers = $st->fetchAll();
    }
} catch (Throwable $e) {}

include 'includes/header.php';

echo '<script>var PERSONNEL_DATA = {};';
foreach ($personnel as $pp) {
    echo 'PERSONNEL_DATA[' . (int)$pp['id'] . '] = ' . json_encode($pp, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) . ';';
}
echo '</script>';


// رندر یک ردیف دسترسی در مودال (چک‌باکس + رادیوی ویراستار/ناظر + تیک «موقت تا پایان امروز» — §v12)
function access_row_html($key, $label, $indent = false) {
    $k = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    $temporary_title = htmlspecialchars(t('ناظر موقت: بعد از پایان امروز خودش غیرفعال می‌شود'), ENT_QUOTES, 'UTF-8');
    return '<div class="acc-row" data-key="' . $k . '" style="display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);' . ($indent ? 'margin-right:20px;border-style:dashed;' : '') . '">'
        . '<label class="form-check" style="flex:1;display:flex;align-items:center;gap:6px;margin:0;cursor:pointer;min-width:0"><input type="checkbox" class="acc-chk" name="access[]" value="' . $k . '" id="acc_' . $k . '"><span style="font-size:12.5px">' . $label . '</span></label>'
        . '<span class="acc-radios" id="radios_' . $k . '" style="display:none;gap:8px;font-size:11px;white-space:nowrap;background:rgba(99,102,241,.08);padding:4px 9px;border-radius:8px">'
        . '<label style="cursor:pointer;display:inline-flex;align-items:center;gap:3px"><input type="radio" name="amode[' . $k . ']" value="editor" checked disabled> ✏️ ' . t('ویراستار') . '</label>'
        . '<label style="cursor:pointer;display:inline-flex;align-items:center;gap:3px"><input type="radio" name="amode[' . $k . ']" value="viewer" disabled> 👁️ ' . t('ناظر') . '</label>'
        . '<label class="acc-temp-wrap" style="cursor:pointer;display:none;align-items:center;gap:3px;color:#b45309" title="' . $temporary_title . '"><input type="checkbox" class="acc-temp" name="atemp[' . $k . ']" value="1" disabled> ⏳ ' . t('تا امروز') . '</label>'
        . '</span></div>';
}
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('per_title') ?> (<?= count($personnel) ?> <?= t('per_count') ?>)</h2>
    <div class="btn-group">
        <form method="GET" class="d-flex gap-2">
            <input type="hidden" name="page" value="personnel">
            <input type="text" name="search" class="form-control" placeholder="<?= t('btn_search') ?>..." value="<?= sanitize($search) ?>" style="width:200px">
            <button type="submit" class="btn btn-ghost"><i data-lucide="search"></i></button>
        </form>
        <?php if (has_permission('manage_personnel')): ?>
        <button type="button" onclick="openAddModal()" class="btn btn-primary">
            <i data-lucide="user-plus"></i> <?= t('btn_add') ?>
        </button>
        <?php endif; ?>
        <?php if (is_strict_admin()): ?>
        <button type="button"  onclick="openGroupRaise()" class="btn btn-warning"><i data-lucide="trending-up"></i> <?= t('raise_group_btn') ?></button>
        <?php endif; ?>
    </div>
</div>

<?php if ((is_strict_admin() || is_project_manager()) && !empty($pending_personnel_transfers)): ?>
<!-- کارت درخواست‌های تأیید انتقال پرسنل -->
<div class="card mb-4 animate-in" style="border-right:4px solid #3b82f6">
    <div class="card-header"><h3 class="card-title">🔔 <?= t('درخواست‌های انتقال پرسنل در انتظار تأیید مدیر پروژه') ?> (<?= count($pending_personnel_transfers) ?>)</h3></div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th><?= t('نام نیرو') ?></th>
                    <th><?= t('کارگاه مبدأ') ?></th>
                    <th><?= t('کارگاه مقصد') ?></th>
                    <th><?= t('تاریخ انتقال') ?></th>
                    <th><?= t('درخواست‌دهنده') ?></th>
                    <th><?= t('wh_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_personnel_transfers as $pt): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($pt['person_name']) ?></strong></td>
                    <td><?= htmlspecialchars($pt['from_ws_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($pt['to_ws_name'] ?? '') ?></td>
                    <td><?= to_jalali($pt['transfer_date']) ?></td>
                    <td><?= htmlspecialchars($pt['requester_name'] ?? 'مدیر کارگاه') ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="approve_transfer">
                                <input type="hidden" name="transfer_id" value="<?= $pt['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm"><i data-lucide="check"></i> <?= t('تأیید انتقال') ?></button>
                            </form>
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="reject_transfer">
                                <input type="hidden" name="transfer_id" value="<?= $pt['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="x"></i> <?= t('رد') ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif (!is_strict_admin() && !is_project_manager() && !empty($pending_personnel_transfers)): ?>
<div class="card mb-4 animate-in" style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:12px 16px;border-radius:10px">
    ⏳ <strong><?= t('توجه:') ?></strong> <?= count($pending_personnel_transfers) ?> <?= t('درخواست انتقال پرسنل به کارگاه دیگر در انتظار تأیید مدیر پروژه دارید.') ?>
</div>
<?php endif; ?>

<!-- Desktop Table -->
<div class="table-container animate-in" id="personnelTable">
    <table>
        <thead>
            <tr>
                <?php
                $sortIcon = function($col) use ($sort, $sort_dir) {
                    if ($sort === $col) return $sort_dir === 'ASC' ? ' ▲' : ' ▼';
                    return ' ⇅';
                };
                $base = 'index.php?page=personnel' . ($search ? '&search=' . urlencode($search) : '');
                ?>
                <th><a href="<?= $base ?>&sort=full_name&dir=<?= ($sort==='full_name' && $sort_dir==='ASC') ? 'DESC' : 'ASC' ?>" style="text-decoration:none;color:inherit"><?= t('per_full_name') ?><?= $sortIcon('full_name') ?></a></th>
                <th><a href="<?= $base ?>&sort=is_karigar&dir=<?= ($sort==='is_karigar' && $sort_dir==='ASC') ? 'DESC' : 'ASC' ?>" style="text-decoration:none;color:inherit"><?= t('sal_type') ?><?= $sortIcon('is_karigar') ?></a></th>
                <th><a href="<?= $base ?>&sort=national_code&dir=<?= ($sort==='national_code' && $sort_dir==='ASC') ? 'DESC' : 'ASC' ?>" style="text-decoration:none;color:inherit"><?= t('per_national_code') ?><?= $sortIcon('national_code') ?></a></th>
                <th><?= t('per_phone') ?></th>
                <?php if (is_strict_admin()): ?><th><a href="<?= $base ?>&sort=monthly_salary&dir=<?= ($sort==='monthly_salary' && $sort_dir==='ASC') ? 'DESC' : 'ASC' ?>" style="text-decoration:none;color:inherit"><?= t('nav_salary') ?><?= $sortIcon('monthly_salary') ?></a></th><?php endif; ?>
                <th><a href="<?= $base ?>&sort=contract_start&dir=<?= ($sort==='contract_start' && $sort_dir==='ASC') ? 'DESC' : 'ASC' ?>" style="text-decoration:none;color:inherit"><?= t('per_contract_start') ?><?= $sortIcon('contract_start') ?></a></th>
                <th><a href="<?= $base ?>&sort=contract_type&dir=<?= ($sort==='contract_type' && $sort_dir==='ASC') ? 'DESC' : 'ASC' ?>" style="text-decoration:none;color:inherit"><?= t('per_contract_type') ?><?= $sortIcon('contract_type') ?></a></th>
                <th><?= t('wh_actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($personnel as $p): ?>
            <?php $__ended = (($p['wp_status'] ?? 'active') === 'inactive'); ?>
            <tr<?= $__ended ? ' style="opacity:.55;background:var(--bg)"' : '' ?>>
                <td>
                    <div class="d-flex align-center gap-2">
                        <div class="person-avatar"><?= mb_substr($p['full_name'], 0, 1) ?></div>
                        <div>
                            <strong><?= $p['full_name'] ?></strong>
                            <div class="text-muted" style="font-size:11px"><?= $p['specialty'] ?: $p['position'] ?><?= $p['username'] ? ' · <span dir="ltr" style="color:var(--primary);font-weight:600">' . htmlspecialchars($p['username']) . '</span>' : '' ?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <?php
                    $type_label = t('per_employee_type');
                    $type_class = 'tag-active';
                    if ($p['is_karigar'] == 1) { $type_label = t('per_worker_type'); $type_class = 'tag-karigar'; }
                    elseif ($p['is_karigar'] == 2) { $type_label = t('پیمانکار'); $type_class = ''; }
                    ?>
                    <span class="tag <?= $type_class ?>" <?= $p['is_karigar'] == 2 ? 'style="background:#e0e7ff;color:#3730a3"' : '' ?>><?= $type_label ?></span>
                    <?php if (($p['role'] ?? '') === 'project_manager'): ?>
                        <span class="tag" style="background:#fef3c7;color:#92400e" title="<?= t('مدیر پروژه') ?>">🏗️ <?= t('مدیر پروژه') ?></span>
                    <?php elseif (($p['role'] ?? '') === 'nazer_kol'): ?>
                        <span class="tag" style="background:#e0f2fe;color:#0369a1" title="<?= t('ناظر کل') ?>">👁️ <?= t('ناظر کل') ?></span>
                    <?php elseif (($p['role'] ?? '') === 'karfarma'): ?>
                        <span class="tag" style="background:#f3e8ff;color:#7e22ce" title="<?= t('کارفرما') ?>">🏢 <?= t('کارفرما') ?></span>
                    <?php elseif (($p['role'] ?? '') === 'nazer'): ?>
                        <span class="tag" style="background:#ecfdf5;color:#065f46" title="<?= t('ناظر کارگاه') ?>">🔍 <?= t('ناظر') ?></span>
                    <?php endif; ?>
                    <?php if ($__ended): ?><span class="tag" style="background:#fee2e2;color:#991b1b" title="<?= t('wh_date') ?> <?= t('wh_end') ?> <?= t('کار') ?>">📅 <?= t('wh_end') ?>‌<?= t('کار') ?> <?= !empty($p['ended_at']) ? to_jalali($p['ended_at']) : '' ?></span><?php endif; ?>
                </td>
                <td><?= $p['national_code'] ?: '-' ?></td>
                <td><?= $p['phone'] ?: '-' ?></td>
                <?php if (is_strict_admin()): ?><td>
                    <?php if ($p['is_karigar'] == 2): ?>
                        <?= number_format((float)($p['hourly_salary'] ?? 0)) ?> <?= t('ت') ?> (<?= t('ساعتی') ?>)
                    <?php else: ?>
                        <?= $p['is_karigar'] ? number_format($p['daily_salary']) . ' ' . t('ت') . ' (' . t('روزانه') . ')' : number_format($p['monthly_salary']) . ' ' . t('ت') . ' (' . t('ماهانه') . ')' ?>
                    <?php endif; ?>
                </td><?php endif; ?>
                <td><?= $p['contract_start'] ? to_jalali($p['contract_start']) : '-' ?></td>
                <td><?= $p['contract_type'] ?: '-' ?></td>
                <td>
                    <div class="table-actions">
                        <?php if ($__ended): ?>
                        <?php if (has_permission('manage_personnel')): ?>
                        <a href="index.php?page=personnel_profile&user_id=<?= (int)$p['id'] ?>" class="btn btn-ghost btn-sm" title="<?= t('پرونده') ?> <?= t('کامل') ?>"><i data-lucide="folder-open"></i></a>
                        <button type="button"  onclick='openRestartModalById(<?= (int)$p["id"] ?>)' class="btn btn-success btn-sm" title="<?= t('wh_start') ?> <?= t('کار') ?> <?= t('مجدد') ?>"><i data-lucide="user-check"></i> <?= t('wh_start') ?> <?= t('مجدد') ?></button>
                        <?php if (is_strict_admin()): ?><button onclick='openPurgeModalById(<?= (int)$p["id"] ?>)' class="btn btn-danger btn-sm" title="<?= t('btn_delete') ?> <?= t('کامل') ?> <?= t('و') ?> <?= t('غیرقابل') ?> <?= t('btn_back') ?>"><i data-lucide="trash-2"></i> <?= t('btn_delete') ?> <?= t('کامل') ?></button><?php endif; ?>
                        <?php endif; ?>
                        <a href="index.php?page=salary" class="btn btn-ghost btn-sm" title="<?= t('sal_details') ?> <?= t('nav_salary') ?>"><i data-lucide="wallet"></i></a>
                        <?php elseif (has_permission('manage_personnel')): ?>
                        <a href="index.php?page=personnel_profile&user_id=<?= (int)$p['id'] ?>" class="btn btn-ghost btn-sm" title="<?= t('پرونده') ?> <?= t('کامل') ?>"><i data-lucide="folder-open"></i></a>
                        <button type="button" onclick='editPersonById(<?= (int)$p["id"] ?>)' class="btn btn-ghost btn-sm" title="<?= t('btn_edit') ?>"><i data-lucide="edit"></i></button>
                        <?php if (is_strict_admin() && $p['is_karigar'] != 2): ?>
                        <button type="button"  onclick='openRaiseModalById(<?= (int)$p["id"] ?>)' class="btn btn-warning btn-sm" title="<?= t('raise_single_title') ?>"><i data-lucide="trending-up"></i></button>
                        <button type="button"  onclick="openModal('histModal<?= $p['id'] ?>')" class="btn btn-ghost btn-sm" title="<?= t('inv_history') ?> <?= t('nav_salary') ?>"><i data-lucide="history"></i></button>
                        <?php endif; ?>
                        <button type="button" onclick="transferPerson(<?= $p['id'] ?>)" class="btn btn-info btn-sm" title="<?= t('btn_transfer') ?>"><i data-lucide="arrow-left-right"></i></button>
                        <?php if (!$p['is_karigar']): ?>
                        <button type="button" onclick="openPermissions(<?= $p["id"] ?>, this)" class="btn btn-info btn-sm" title="<?= t('دسترسی') ?>"
                            data-uname="<?= htmlspecialchars($p['full_name'], ENT_QUOTES) ?>"
                            data-role="<?= htmlspecialchars($p['role'] ?? 'worker', ENT_QUOTES) ?>"
                            data-special="<?= (in_array($p['username'] ?? '', access_special_usernames(), true) || ($p['role'] ?? '') === 'nazer') ? 1 : 0 ?>"
                            data-access="<?= js_arg($__uam[$p['id']] ?? []) ?>"><i data-lucide="shield-check"></i></button>
                        <?php endif; ?>
                        <button type="button"  onclick='openEndWorkModalById(<?= (int)$p["id"] ?>)' class="btn btn-danger btn-sm" title="<?= t('wh_end') ?> <?= t('کار') ?> — <?= t('lea_from') ?> <?= t('آن') ?> <?= t('wh_date') ?> <?= t('به') ?> <?= t('بعد') ?> <?= t('در') ?> <?= t('لیست') ?>‌<?= t('های') ?> <?= t('عملیاتی') ?> <?= t('نمی‌آید') ?>"><i data-lucide="user-x"></i> <?= t('wh_end') ?> <?= t('کار') ?></button>
                        <?php endif; ?>
                        <a href="index.php?page=salary" class="btn btn-ghost btn-sm" title="<?= t('sal_details') ?> <?= t('nav_salary') ?>"><i data-lucide="wallet"></i></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($personnel)): ?>
            <tr><td colspan="<?= is_strict_admin() ? 8 : 7 ?>" class="text-center text-muted" style="padding:40px"><?= t('per_no_records') ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:750px">
        <div class="modal-header">
            <h3 class="modal-title"><?= t('per_add') ?></h3>
            <button onclick="closeModal('addModal')" class="modal-close">✕</button>
        </div>
        <form method="POST" id="addForm" onsubmit="return fixAddWorkRole()"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <!-- Type Selection -->
                <div class="form-row mb-3">
                    <label class="form-check"><input type="radio" name="person_type" value="0" id="add_type_employee" checked onchange="togglePersonType('add', 0)"><span><strong><?= t('per_employee_type') ?></strong> (<?= t('حقوق') ?> <?= t('ماهانه') ?>، <?= t('btn_checkin') ?> <?= t('به') ?> <?= t('پنل') ?>)</span></label>
                    <label class="form-check"><input type="radio" name="person_type" value="1" id="add_type_worker" onchange="togglePersonType('add', 1)"><span><strong><?= t('sal_worker') ?></strong> (<?= t('حقوق') ?> <?= t('روزانه') ?>، <?= t('بدون') ?> <?= t('btn_checkin') ?> <?= t('به') ?> <?= t('پنل') ?>)</span></label>
                    <label class="form-check"><input type="radio" name="person_type" value="2" id="add_type_peymankar" onchange="togglePersonType('add', 2)"><span><strong><?= t('پیمانکار') ?></strong> (<?= t('فقط ثبت حضور') ?>)</span></label>
                </div>

                <!-- Common Fields -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_full_name') ?> *</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_national_code') ?></label>
                        <input type="text" name="national_code" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_phone') ?></label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_specialty') ?></label>
                        <input type="text" name="specialty" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('برق‌کار، آهنگر...') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_address') ?></label>
                        <input type="text" name="address" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_contract_type') ?></label>
                        <select name="contract_type" class="form-control">
                            <option value=""><?= t('انتخاب کنید') ?></option>
                            <option value="<?= t('رسمی') ?>"><?= t('رسمی') ?></option>
                            <option value="<?= t('پیمانی') ?>"><?= t('پیمانی') ?></option>
                            <option value="<?= t('قراردادی') ?>"><?= t('قراردادی') ?></option>
                            <option value="<?= t('روزمزد') ?>"><?= t('روزمزد') ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_contract_start') ?></label>
                        <input type="text" name="contract_start" class="form-control" placeholder="1404/01/01">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_contract_end') ?></label>
                        <input type="text" name="contract_end" class="form-control" placeholder="1405/01/01">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_insurance_number') ?></label>
                        <input type="text" name="insurance_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_insurance_amount') ?></label>
                        <input type="number" name="insurance_amount" class="form-control" min="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_iban') ?></label>
                        <input type="text" name="iban" class="form-control" placeholder="IR...">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_card_number') ?></label>
                        <input type="text" name="card_number" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_account_number') ?></label>
                        <input type="text" name="account_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_bank_name') ?></label>
                        <input type="text" name="account_name" class="form-control" placeholder="<?= t('نام و نام خانوادگی صاحب حساب') ?>">
                    </div>
                </div>

                <!-- Employee-Only Fields -->
                <div id="add_employee_fields">
                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <p style="font-weight:bold; color: var(--primary); margin-bottom:12px;"><?= t('info') ?> <?= t('per_employee_type') ?></p>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('per_username') ?> *</label>
                            <input type="text" name="username" id="add_username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('per_password') ?> *</label>
                            <input type="password" name="password" id="add_password" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('per_position') ?></label>
                            <input type="text" name="position" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('مدیر، سرپرست،') ?> <?= t('حسابدار') ?>...">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('نقش در گزارش') ?></label>
                            <select name="work_role" id="add_work_role" class="form-control">
                                <option value=""><?= t('انتخاب کنید') ?></option>
                                <option value="<?= t('مهندس اجرا') ?>"><?= t('مهندس اجرا') ?></option>
                                <option value="HSE">HSE</option>
                                <option value="<?= t('anbar_keeper') ?>"><?= t('anbar_keeper') ?></option>
                                <option value="<?= t('مدیر پروژه') ?>"><?= t('مدیر پروژه') ?></option>
                                <option value="<?= t('anbar_sign_supervisor') ?>"><?= t('anbar_sign_supervisor') ?></option>
                                <option value="<?= t('دفتر فنی') ?>"><?= t('دفتر فنی') ?></option>
                                <option value="<?= t('سایر') ?>"><?= t('سایر') ?></option>
                            </select>
                        </div>
                        <?php if (is_strict_admin() || is_project_manager()): ?>
                        <div class="form-group">
                            <label class="form-label"><?= t('نقش کاربری سیستمی') ?></label>
                            <select name="system_role" class="form-control">
                                <option value="worker">👤 <?= t('کارمند / پرسنل عادی') ?></option>
                                <option value="project_manager">🏗️ <?= t('مدیر پروژه (دسترسی کامل به کل سایت)') ?></option>
                                <option value="nazer_kol">👁️ <?= t('ناظر کل (مشاهده تمام بخش‌ها و کارگاه‌ها)') ?></option>
                                <option value="karfarma">🏢 <?= t('کارفرما (فرم‌ها و گزارش روزانه و HSE)') ?></option>
                                <option value="nazer">🔍 <?= t('ناظر کارگاه') ?></option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('per_monthly_salary') ?> (<?= t('sal_toman') ?>)</label>
                            <input type="number" name="monthly_salary" class="form-control" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <p class="text-muted" style="font-size:12px;background:rgba(59,130,246,.07);border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin:0">
                        🔐 <?= t('btn_permissions') ?> <?= t('صفحات') ?> (<?= t('ناظر') ?>/<?= t('ویراستار') ?>) <?= t('را') ?> <?= t('بعد') ?> <?= t('lea_from') ?> <?= t('btn_add') ?> <?= t('شخص') ?>، <?= t('با') ?> <?= t('دکمهٔ') ?> 🛡️ <b>«<?= t('btn_permissions') ?>»</b> <?= t('در') ?> <?= t('لیست') ?> <?= t('nav_personnel') ?> <?= t('تنظیم') ?> <?= t('کنید') ?>.
                        </p>
                    </div>
                </div>

                <!-- Worker-Only Fields -->
                <div id="add_worker_fields" style="display:none;">
                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <p style="font-weight:bold; color: var(--primary); margin-bottom:12px;"><?= t('info') ?> <?= t('sal_worker') ?></p>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('sal_type') ?> <?= t('sal_worker') ?></label>
                            <select name="work_role" id="add_work_role_worker" class="form-control">
                                <option value="<?= t('sal_worker') ?>"><?= t('sal_worker') ?></option>
                                <option value="<?= t('سرکارگر') ?>"><?= t('سرکارگر') ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('per_daily_salary') ?> (<?= t('sal_toman') ?>)</label>
                            <input type="number" name="daily_salary" class="form-control" min="0">
                        </div>
                    </div>
                </div>

                <!-- Peymankar-Only Fields -->
                <div id="add_peymankar_fields" style="display:none;">
                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <p style="font-weight:bold; color: var(--primary); margin-bottom:12px;"><?= t('info') ?> <?= t('پیمانکار') ?></p>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= t('nav_salary') ?> <?= t('ساعتی حضور') ?> (<?= t('sal_toman') ?>)</label><input type="number" name="hourly_salary" class="form-control" min="0" step="1000" placeholder="<?= t('مثلاً') ?>: 150000"><div class="text-muted" style="font-size:10px;margin-top:4px"><?= t('پس') ?> <?= t('lea_from') ?> <?= t('اتصال') ?> <?= t('به') ?> <?= t('پیمانکار') ?> <?= t('در') ?> <?= t('صفحه') ?> <?= t('پیمانکاران') ?>، <?= t('این') ?> <?= t('مبلغ') ?> <?= t('با') ?> <?= t('خدمات') ?> <?= t('او') ?> <?= t('تجمیع') ?> <?= t('و') ?> <?= t('در') ?> <?= t('فرم') ?> <?= t('واریزی') ?> <?= t('نشان') ?> <?= t('داده') ?> <?= t('می‌شود') ?>.</div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('per_position') ?> / <?= t('شغل') ?></label>
                            <select name="work_role" id="add_work_role_peymankar" class="form-control">
                                <option value=""><?= t('انتخاب کنید') ?></option>
                                <option value="<?= t('راننده بیل') ?>"><?= t('راننده بیل') ?></option>
                                <option value="<?= t('اسکلت') ?> <?= t('کار') ?>"><?= t('اسکلت') ?> <?= t('کار') ?></option>
                                <option value="<?= t('راننده بابکت') ?>"><?= t('راننده بابکت') ?></option>
                                <option value="<?= t('جوشکار') ?>"><?= t('جوشکار') ?></option>
                                <option value="<?= t('سایر') ?> <?= t('پیمانکار') ?>"><?= t('سایر') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('ایجاد') ?></button>
                <button type="button" onclick="closeModal('addModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:750px">
        <div class="modal-header">
            <h3 class="modal-title"><?= t('per_edit') ?></h3>
            <button onclick="closeModal('editModal')" class="modal-close">✕</button>
        </div>
        <form method="POST" onsubmit="return fixEditWorkRole()"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="hidden" name="is_karigar" id="edit_is_karigar_hidden">
            <div class="modal-body">
                <!-- Type Display -->
                <div class="form-row mb-3">
                    <label class="form-check"><input type="radio" name="is_karigar" value="0" id="edit_type_employee" onchange="togglePersonType('edit', 0)"><span><strong><?= t('per_employee_type') ?></strong></span></label>
                    <label class="form-check"><input type="radio" name="is_karigar" value="1" id="edit_type_worker" onchange="togglePersonType('edit', 1)"><span><strong><?= t('sal_worker') ?></strong></span></label>
                    <label class="form-check"><input type="radio" name="is_karigar" value="2" id="edit_type_peymankar" onchange="togglePersonType('edit', 2)"><span><strong><?= t('پیمانکار') ?></strong></span></label>
                </div>

                <!-- Common Fields -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_full_name') ?> *</label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_username') ?> (<?= t('btn_checkin') ?>)</label>
                        <input type="text" name="username" id="edit_username" class="form-control" dir="ltr" autocomplete="off">
                        <small class="text-muted" style="font-size:10px"><?= t('برای') ?> <?= t('scope_workers') ?>/<?= t('nav_subcontractors') ?> <?= t('به') ?><?= t('‌صورت') ?> <?= t('خودکار') ?> <?= t('ساخته') ?> <?= t('می‌شود') ?> — <?= t('قابل') ?> <?= t('تغییر') ?> <?= t('است') ?></small>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_national_code') ?></label>
                        <input type="text" name="national_code" id="edit_national_code" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_phone') ?></label>
                        <input type="text" name="phone" id="edit_phone" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_specialty') ?></label>
                        <input type="text" name="specialty" id="edit_specialty" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_address') ?></label>
                        <input type="text" name="address" id="edit_address" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_contract_type') ?></label>
                        <select name="contract_type" id="edit_contract_type" class="form-control">
                            <option value=""><?= t('انتخاب کنید') ?></option>
                            <option value="<?= t('رسمی') ?>"><?= t('رسمی') ?></option>
                            <option value="<?= t('پیمانی') ?>"><?= t('پیمانی') ?></option>
                            <option value="<?= t('قراردادی') ?>"><?= t('قراردادی') ?></option>
                            <option value="<?= t('روزمزد') ?>"><?= t('روزمزد') ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_contract_start') ?></label>
                        <input type="text" name="contract_start" id="edit_contract_start" class="form-control" placeholder="1404/01/01">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_contract_end') ?></label>
                        <input type="text" name="contract_end" id="edit_contract_end" class="form-control" placeholder="1405/01/01">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_insurance_number') ?></label>
                        <input type="text" name="insurance_number" id="edit_insurance_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_insurance_amount') ?></label>
                        <input type="number" name="insurance_amount" id="edit_insurance_amount" class="form-control" min="0">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_iban') ?></label>
                        <input type="text" name="iban" id="edit_iban" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_card_number') ?></label>
                        <input type="text" name="card_number" id="edit_card_number" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('per_account_number') ?></label>
                        <input type="text" name="account_number" id="edit_account_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('per_bank_name') ?></label>
                        <input type="text" name="account_name" id="edit_account_name" class="form-control">
                    </div>
                </div>

                <!-- Employee-Only Fields -->
                <div id="edit_employee_fields">
                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <p style="font-weight:bold; color: var(--primary); margin-bottom:12px;"><?= t('info') ?> <?= t('per_employee_type') ?></p>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('per_position') ?></label>
                            <input type="text" name="position" id="edit_position" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('مدیر، سرپرست،') ?> <?= t('حسابدار') ?>...">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('نقش در گزارش') ?></label>
                            <select name="work_role" id="edit_work_role" class="form-control">
                                <option value=""><?= t('انتخاب کنید') ?></option>
                                <option value="<?= t('مهندس اجرا') ?>"><?= t('مهندس اجرا') ?></option>
                                <option value="HSE">HSE</option>
                                <option value="<?= t('anbar_keeper') ?>"><?= t('anbar_keeper') ?></option>
                                <option value="<?= t('مدیر پروژه') ?>"><?= t('مدیر پروژه') ?></option>
                                <option value="<?= t('anbar_sign_supervisor') ?>"><?= t('anbar_sign_supervisor') ?></option>
                                <option value="<?= t('دفتر فنی') ?>"><?= t('دفتر فنی') ?></option>
                                <option value="<?= t('سایر') ?>"><?= t('سایر') ?></option>
                            </select>
                        </div>
                        <?php if (is_strict_admin() || is_project_manager()): ?>
                        <div class="form-group">
                            <label class="form-label"><?= t('نقش کاربری سیستمی') ?></label>
                            <select name="system_role" id="edit_system_role" class="form-control">
                                <option value="worker">👤 <?= t('کارمند / پرسنل عادی') ?></option>
                                <option value="project_manager">🏗️ <?= t('مدیر پروژه (دسترسی کامل به کل سایت)') ?></option>
                                <option value="nazer_kol">👁️ <?= t('ناظر کل (مشاهده تمام بخش‌ها و کارگاه‌ها)') ?></option>
                                <option value="karfarma">🏢 <?= t('کارفرما (فرم‌ها و گزارش روزانه و HSE)') ?></option>
                                <option value="nazer">🔍 <?= t('ناظر کارگاه') ?></option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('per_monthly_salary') ?> (<?= t('sal_toman') ?>)</label>
                            <input type="number" name="monthly_salary" id="edit_monthly_salary" class="form-control" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('per_password') ?> <?= t('جدید') ?> (<?= t('خالی') ?> = <?= t('عدم') ?> <?= t('تغییر') ?>)</label>
                            <input type="password" name="new_password" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Worker-Only Fields -->
                <div id="edit_worker_fields" style="display:none;">
                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <p style="font-weight:bold; color: var(--primary); margin-bottom:12px;"><?= t('info') ?> <?= t('sal_worker') ?></p>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('sal_type') ?> <?= t('sal_worker') ?></label>
                            <select name="work_role" id="edit_work_role_worker" class="form-control">
                                <option value="<?= t('sal_worker') ?>"><?= t('sal_worker') ?></option>
                                <option value="<?= t('سرکارگر') ?>"><?= t('سرکارگر') ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= t('per_daily_salary') ?> (<?= t('sal_toman') ?>)</label>
                            <input type="number" name="daily_salary" id="edit_daily_salary" class="form-control" min="0">
                        </div>
                    </div>
                </div>

                <!-- Peymankar-Only Fields -->
                <div id="edit_peymankar_fields" style="display:none;">
                    <hr style="border-color: var(--border); margin: 16px 0;">
                    <p style="font-weight:bold; color: var(--primary); margin-bottom:12px;"><?= t('info') ?> <?= t('پیمانکار') ?></p>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= t('nav_salary') ?> <?= t('ساعتی حضور') ?> (<?= t('sal_toman') ?>)</label><input type="number" name="hourly_salary" id="edit_hourly_salary" class="form-control" min="0" step="1000"><div class="text-muted" style="font-size:10px;margin-top:4px"><?= t('این') ?> <?= t('نرخ') ?> <?= t('پس') ?> <?= t('lea_from') ?> <?= t('اتصال') ?> <?= t('به') ?> <?= t('صفحه') ?> <?= t('پیمانکاران') ?>، <?= t('کنار') ?> <?= t('خدمات') ?> <?= t('در') ?> <?= t('فرم') ?> <?= t('واریزی') ?> <?= t('تجمیع') ?> <?= t('می‌شود') ?>.</div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><?= t('per_position') ?> / <?= t('شغل') ?></label>
                            <select name="work_role" id="edit_work_role_peymankar" class="form-control">
                                <option value=""><?= t('انتخاب کنید') ?></option>
                                <option value="<?= t('راننده بیل') ?>"><?= t('راننده بیل') ?></option>
                                <option value="<?= t('اسکلت') ?> <?= t('کار') ?>"><?= t('اسکلت') ?> <?= t('کار') ?></option>
                                <option value="<?= t('راننده بابکت') ?>"><?= t('راننده بابکت') ?></option>
                                <option value="<?= t('جوشکار') ?>"><?= t('جوشکار') ?></option>
                                <option value="<?= t('سایر') ?> <?= t('پیمانکار') ?>"><?= t('سایر') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button>
                <button type="button" onclick="closeModal('editModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Modal -->
<div id="transferModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header">
            <h3 class="modal-title"><?= t('per_transfer') ?></h3>
            <button onclick="closeModal('transferModal')" class="modal-close">✕</button>
        </div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="transfer">
            <input type="hidden" name="user_id" id="transfer_user_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('per_to_workshop') ?></label>
                    <select name="to_workshop" class="form-control" required>
                        <option value=""><?= t('انتخاب کنید') ?></option>
                        <?php foreach ($other_workshops as $ow): ?>
                        <option value="<?= $ow['id'] ?>"><?= $ow['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('per_transfer_date') ?></label>
                    <input type="text" name="transfer_date" class="form-control" value="<?= jalali_now() ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('btn_transfer') ?></button>
                <button type="button" onclick="closeModal('transferModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Permissions Modal — §v11 سیستم دسترسی صفحه‌ای (ناظر/ویراستار) -->
<div id="permModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:900px;max-height:90vh;overflow-y:auto">
        <div class="modal-header"><h3 class="modal-title">🔐 <?= t('btn_permissions') ?>‌<?= t('های') ?> <span id="perm_user_name"><?= t('کاربر') ?></span></h3><button onclick="closeModal('permModal')" class="modal-close">✕</button></div>
        <form method="POST" id="permForm"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="user_id" id="perm_user_id">
            <div class="modal-body">
                <div id="permSpecialNotice" style="display:none;background:#fef3c7;border:1px solid #f59e0b;color:#92400e;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:12px">
                    ⚠️ <?= t('این') ?> <?= t('حساب') ?> <?= t('ویژه') ?> <?= t('است') ?> (<?= t('اکانتهای') ?> yaser / javidhse) — <?= t('btn_permissions') ?> <?= t('آن') ?> <?= t('طبق') ?> <?= t('روال') ?> <?= t('قبلیِ') ?> <?= t('خودش') ?> <?= t('ثابت') ?> <?= t('می‌ماند') ?> <?= t('و') ?> <?= t('این') ?> <?= t('فرم') ?> <?= t('روی') ?> <?= t('آن') ?> <?= t('btn_save') ?> <?= t('نمی‌شود') ?>.
                </div>
                <?php if (is_strict_admin() || is_project_manager()): ?>
                <div class="form-group mb-3" style="background:var(--bg);padding:10px 14px;border-radius:10px;border:1px solid var(--border)">
                    <label class="form-label font-bold mb-2">🎭 <?= t('نقش اصلی کاربر در سیستم') ?>:</label>
                    <select name="system_role" id="perm_system_role" class="form-control" style="font-weight:600">
                        <option value="worker">👤 <?= t('کاربر / پرسنل عادی کارگاه') ?></option>
                        <option value="project_manager">🏗️ <?= t('مدیر پروژه (دسترسی کامل به کل سایت و همه کارگاه‌ها)') ?></option>
                        <option value="nazer_kol">👁️ <?= t('ناظر کل (دسترسی مشاهده به تمام بخش‌ها و کارگاه‌ها)') ?></option>
                        <option value="karfarma">🏢 <?= t('کارفرما (فقط فرم‌ها، گزارش روزانه و HSE)') ?></option>
                        <option value="nazer">🔍 <?= t('ناظر کارگاه (فقط مشاهده کارگاه جاری)') ?></option>
                    </select>
                </div>
                <?php endif; ?>
                <label class="form-check" style="display:flex;align-items:center;gap:8px;background:rgba(245,158,11,.08);padding:10px 12px;border-radius:10px;border:1px solid var(--border);margin-bottom:14px;cursor:pointer">
                    <input type="checkbox" name="wm_master" id="perm_wm_master" value="1">
                    <span><b>🏭 <?= t('مدیر کارگاه') ?></b> — <?= t('btn_permissions') ?> <?= t('کامل') ?> <?= t('به') ?> <?= t('همهٔ') ?> <?= t('صفحات') ?> <?= t('این') ?> <?= t('کارگاه') ?> (<?= t('ماند') ?> <?= t('و') ?> <?= t('قدرت') ?> <?= t('قبلی') ?>)</span>
                </label>
                <p class="text-muted" style="font-size:11.5px;line-height:1.9;margin:0 0 12px">
                    ✅ <?= t('تیک') ?> <?= t('هر') ?> <?= t('صفحه') ?> = <?= t('btn_permissions') ?> <?= t('به') ?> <?= t('آن') ?> — <?= t('بعد') ?> <?= t('lea_from') ?> <?= t('تیک') ?>، <b>✏️ <?= t('ویراستار') ?></b> (<?= t('مدیریت کامل') ?>) <?= t('یا') ?> <b>👁️ <?= t('ناظر') ?></b> (<?= t('فقط مشاهده، بدون هیچ تغییری') ?>) <?= t('را') ?> <?= t('انتخاب کنید') ?>.
                    <br>📌 <?= t('صفحات') ?> <?= t('sal_base_pay') ?> <?= t('برای') ?> <?= t('همه') ?> <?= t('با') ?> <?= t('اطلاعاتِ') ?> <?= t('خودشان') ?> <?= t('فعال') ?>‌<?= t('اند') ?>؛ <?= t('تیک') ?>‌<?= t('شان') ?> <?= t('یعنی') ?> <?= t('دیدن') ?>/<?= t('مدیریت') ?> <?= t('اطلاعاتِ') ?> <?= t('بقیه') ?> <?= t('هم') ?>. <?= t('بدون') ?> <?= t('هیچ') ?> <?= t('تیکی') ?> <?= t('هر') ?> <?= t('per_count') ?>: <?= t('داشبورد') ?>، <?= t('پروفایل') ?>، <?= t('چت') ?> <?= t('و') ?> <?= t('صفحات') ?> <?= t('پایهٔ') ?> <?= t('خودش') ?> <?= t('را') ?> <?= t('دارد') ?>.
                    <br>⏳ <?= t('تیک') ?> «<?= t('lea_to') ?> <?= t('wh_today') ?>» <?= t('کنار') ?> <?= t('ناظر') ?> = <b><?= t('ناظر') ?> <?= t('موقت') ?></b>: <?= t('btn_permissions') ?> <?= t('مشاهده') ?> <?= t('بعد') ?> <?= t('lea_from') ?> <?= t('wh_end') ?> <?= t('wh_today') ?> <?= t('خودش') ?> <?= t('btn_deactivate') ?> <?= t('می‌شود') ?>.
                </p>
                <!-- §v12: نقش‌های آماده -->
                <div style="margin-bottom:14px;padding:10px 12px;background:rgba(236,72,153,.06);border:1px dashed var(--border);border-radius:10px">
                    <div style="font-size:12px;font-weight:700;margin-bottom:8px">⚡ <?= t('نقش‌های آماده') ?> <span style="font-weight:400;color:var(--text-secondary)">(<?= t('یک') ?> <?= t('کلیک') ?> = <?= t('پر') ?> <?= t('شدن') ?> <?= t('خودکار') ?> <?= t('تیک') ?>‌<?= t('ها') ?> — <?= t('بعدش') ?> <?= t('دستی') ?> <?= t('هم') ?> <?= t('می‌توانی') ?> <?= t('عوضشان کنی') ?>)</span></div>
                    <div class="d-flex gap-2" style="flex-wrap:wrap">
                        <button type="button" class="btn btn-ghost btn-sm" style="border:1px solid var(--border)" onclick="accPreset('office')">🧑‍💼 <?= t('مسئول دفتر') ?></button>
                        <button type="button" class="btn btn-ghost btn-sm" style="border:1px solid var(--border)" onclick="accPreset('supervisor')">👷 <?= t('anbar_sign_supervisor') ?></button>
                        <button type="button" class="btn btn-ghost btn-sm" style="border:1px solid var(--border)" onclick="accPreset('warehouse')">📦 <?= t('anbar_keeper') ?></button>
                        <button type="button" class="btn btn-ghost btn-sm" style="border:1px solid var(--border)" onclick="accPreset('accountant')">💰 <?= t('حسابدار') ?></button>
                        <button type="button" class="btn btn-ghost btn-sm" style="border:1px solid var(--border);color:var(--danger)" onclick="accPreset('clear')">🧹 <?= t('پاک‌سازی تیک‌ها') ?></button>
                    </div>
                </div>
                <?php $__reg = page_access_registry(); ?>
                <h4 style="margin:4px 0 8px;color:var(--primary)">🧍 <?= t('صفحات') ?> <?= t('sal_base_pay') ?></h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:8px">
                    <?php foreach ($__reg['personal']['items'] as $k => $l) echo access_row_html($k, $l); ?>
                </div>
                <h4 style="margin:16px 0 8px;color:var(--primary)">🗂️ <?= t('صفحات مدیریتی') ?></h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:8px">
                    <?php foreach ($__reg['manage']['items'] as $k => $l): if (in_array($k, ['reports','forms'], true)) continue; echo access_row_html($k, $l); endforeach; ?>
                </div>
                <h4 style="margin:16px 0 8px;color:var(--primary)">📊 <?= t('گزارش‌ها') ?> <button type="button" class="btn btn-ghost btn-sm" style="font-size:11px" onclick="accToggleGroup('rep_', this)"><?= t('انتخاب/لغو همه') ?></button></h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:8px">
                    <?php foreach ($__reg['children']['reports'] as $k => $l) echo access_row_html($k, $l, true); ?>
                </div>
                <h4 style="margin:16px 0 8px;color:var(--primary)">🧾 <?= t('nav_forms') ?> <button type="button" class="btn btn-ghost btn-sm" style="font-size:11px" onclick="accToggleGroup('form_', this)"><?= t('انتخاب/لغو همه') ?></button></h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:8px">
                    <?php foreach ($__reg['children']['forms'] as $k => $l) echo access_row_html($k, $l, true); ?>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary" id="permSaveBtn"><?= t('btn_save') ?></button><button type="button" onclick="closeModal('permModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    try {
        var form = document.getElementById('addForm');
        if (form) form.reset();
        togglePersonType('add', 0);
        openModal('addModal');
    } catch(e){ console.error('openAddModal error', e); alert('خطا در باز کردن فرم افزودن: '+e.message); }
}
// FIXED: تابع کمکی برای فراخوانی با ID به جای آبجکت کامل (جلوگیری از شکست onclick)
function editPersonById(id){
    try {
        if (typeof PERSONNEL_DATA !== 'undefined' && PERSONNEL_DATA[id]) {
            editPerson(PERSONNEL_DATA[id]);
        } else {
            console.error('PERSONNEL_DATA not found for id', id);
            showToast('داده پرسنل یافت نشد', 'error');
        }
    } catch(e){ console.error(e); }
}
function openEndWorkModalById(id){
    try {
        var name = (typeof PERSONNEL_DATA !== 'undefined' && PERSONNEL_DATA[id]) ? PERSONNEL_DATA[id].full_name : '';
        openEndWorkModal(id, name);
    } catch(e){ openEndWorkModal(id, ''); }
}
function openRestartModalById(id){
    try {
        var name = (typeof PERSONNEL_DATA !== 'undefined' && PERSONNEL_DATA[id]) ? PERSONNEL_DATA[id].full_name : '';
        openRestartModal(id, name);
    } catch(e){ openRestartModal(id, ''); }
}
function openPurgeModalById(id){
    try {
        var name = (typeof PERSONNEL_DATA !== 'undefined' && PERSONNEL_DATA[id]) ? PERSONNEL_DATA[id].full_name : '';
        openPurgePersonnelModal(id, name);
    } catch(e){ openPurgePersonnelModal(id, ''); }
}
function openRaiseModalById(id){
    try {
        if (typeof PERSONNEL_DATA !== 'undefined' && PERSONNEL_DATA[id]) {
            var p = PERSONNEL_DATA[id];
            openRaiseModal(p.id, p.full_name, p.is_karigar == 1 ? p.daily_salary : p.monthly_salary, p.is_karigar == 1 ? 1 : 0);
        }
    } catch(e){ console.error(e); }
}


function togglePersonType(prefix, isWorker) {
    var empFields = document.getElementById(prefix + '_employee_fields');
    var workFields = document.getElementById(prefix + '_worker_fields');
    var peyFields = document.getElementById(prefix + '_peymankar_fields');
    if (peyFields) peyFields.style.display = 'none';
    if (workFields) workFields.style.display = 'none';
    if (empFields) empFields.style.display = 'none';

    if (isWorker == 2) {
        if (peyFields) peyFields.style.display = 'block';
    } else if (isWorker == 1) {
        if (workFields) workFields.style.display = 'block';
    } else {
        if (empFields) empFields.style.display = 'block';
    }
    var u = document.getElementById(prefix + '_username');
    var p = document.getElementById(prefix + '_password');
    if (isWorker == 0) {
        if (u) u.setAttribute('required', 'required');
        if (p) p.setAttribute('required', 'required');
    } else {
        if (u) u.removeAttribute('required');
        if (p) p.removeAttribute('required');
    }
}

function editPerson(p) {
    try {
        var el;
        el = document.getElementById('edit_user_id'); if(el) el.value = p.id;
        el = document.getElementById('edit_full_name'); if(el) el.value = p.full_name || '';
        el = document.getElementById('edit_username'); if(el) el.value = p.username || '';
        el = document.getElementById('edit_national_code'); if(el) el.value = p.national_code || '';
        el = document.getElementById('edit_phone'); if(el) el.value = p.phone || '';
        el = document.getElementById('edit_specialty'); if(el) el.value = p.specialty || '';
        el = document.getElementById('edit_address'); if(el) el.value = p.address || '';
        el = document.getElementById('edit_contract_type'); if(el) el.value = p.contract_type || '';
        el = document.getElementById('edit_contract_start'); if(el) { try { el.value = (p.contract_start && p.contract_start.indexOf('0000') === -1) ? toJalali(p.contract_start) : ''; } catch(ex) { el.value = ''; } }
        el = document.getElementById('edit_contract_end'); if(el) { try { el.value = (p.contract_end && p.contract_end.indexOf('0000') === -1) ? toJalali(p.contract_end) : ''; } catch(ex) { el.value = ''; } }
        el = document.getElementById('edit_insurance_number'); if(el) el.value = p.insurance_number || '';
        el = document.getElementById('edit_insurance_amount'); if(el) el.value = p.insurance_amount || 0;
        el = document.getElementById('edit_iban'); if(el) el.value = p.iban || '';
        el = document.getElementById('edit_card_number'); if(el) el.value = p.card_number || '';
        el = document.getElementById('edit_account_number'); if(el) el.value = p.account_number || '';
        el = document.getElementById('edit_account_name'); if(el) el.value = p.account_name || '';
        el = document.getElementById('edit_position'); if(el) el.value = p.position || '';
        el = document.getElementById('edit_monthly_salary'); if(el) el.value = p.monthly_salary || 0;
        el = document.getElementById('edit_daily_salary'); if(el) el.value = p.daily_salary || 0;
        el = document.getElementById('edit_hourly_salary'); if(el) el.value = p.hourly_salary || 0;
        el = document.getElementById('edit_description'); if(el) el.value = p.description || '';

        // <?= t('تنظیم') ?> نقش
        var wr = p.work_role || '';
        el = document.getElementById('edit_work_role'); if(el) el.value = wr;
        el = document.getElementById('edit_work_role_worker'); if(el) el.value = wr || '<?= t('کارگر') ?>';
        el = document.getElementById('edit_work_role_peymankar'); if(el) el.value = wr;
        el = document.getElementById('edit_system_role'); if(el) el.value = p.role || 'worker';

        // Set personnel type.
        var k = parseInt(p.is_karigar) || 0;
        if (k == 2) {
            el = document.getElementById('edit_type_peymankar'); if(el) el.checked = true;
            togglePersonType('edit', 2);
        } else if (k == 1) {
            el = document.getElementById('edit_type_worker'); if(el) el.checked = true;
            togglePersonType('edit', 1);
        } else {
            el = document.getElementById('edit_type_employee'); if(el) el.checked = true;
            togglePersonType('edit', 0);
        }
        openModal('editModal');
    } catch(e) {
        console.error('editPerson error:', e);
        alert('<?= t('خطا') ?> <?= t('در') ?> <?= t('باز کردن') ?> <?= t('فرم') ?> <?= t('ویرایش') ?>: ' + e.message);
    }
}

function transferPerson(userId) {
    document.getElementById('transfer_user_id').value = userId;
    openModal('transferModal');
}

// End-work and restart-work modals.
function openEndWorkModal(uid, name) {
    document.getElementById('ew_user_id').value = uid;
    document.getElementById('ew_name').textContent = name;
    openModal('endWorkModal');
}
function openRestartModal(uid, name) {
    document.getElementById('rw_user_id').value = uid;
    document.getElementById('rw_name').textContent = name;
    openModal('restartWorkModal');
}
function openPurgePersonnelModal(uid, name) {
    document.getElementById('purge_user_id').value = uid;
    document.getElementById('purge_name').textContent = name;
    document.getElementById('purge_confirm_text').value = '';
    document.getElementById('purge_submit').disabled = true;
    openModal('purgePersonnelModal');
}
function verifyPurgeName() {
    var expected = document.getElementById('purge_name').textContent.trim();
    document.getElementById('purge_submit').disabled = document.getElementById('purge_confirm_text').value.trim() !== expected;
}

// Page-access modal management.
function accSyncRow(cb) {
    var key = cb.value;
    var radios = document.getElementById('radios_' + key);
    if (!radios) return;
    radios.style.display = cb.checked ? 'inline-flex' : 'none';
    radios.querySelectorAll('input[type="radio"]').forEach(function (r) { r.disabled = !cb.checked; });
    // Temporary access is available only when viewer mode is selected.
    var isViewer = cb.checked && radios.querySelector('input[type="radio"][value="viewer"]').checked;
    var tempWrap = radios.querySelector('.acc-temp-wrap');
    var tempChk = radios.querySelector('.acc-temp');
    if (tempWrap) tempWrap.style.display = isViewer ? 'inline-flex' : 'none';
    if (tempChk) tempChk.disabled = !isViewer;
}
function accToggleGroup(prefix, btn) {
    var boxes = document.querySelectorAll('#permForm .acc-chk');
    var targetState = false;
    boxes.forEach(function (cb) { if (cb.value.indexOf(prefix) === 0 && !cb.checked) targetState = true; });
    boxes.forEach(function (cb) {
        if (cb.value.indexOf(prefix) === 0) { cb.checked = targetState; accSyncRow(cb); }
    });
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('acc-chk')) accSyncRow(e.target);
    // Refresh the temporary-access option after an access-mode change.
    if (e.target && e.target.name && e.target.name.indexOf('amode[') === 0) {
        var key = e.target.name.slice(6, -1);
        var cb = document.getElementById('acc_' + key);
        if (cb) accSyncRow(cb);
    }
});

// §v12: <?= t('نقش‌های آماده') ?> — <?= t('پیش') ?>‌<?= t('پر کردن') ?> <?= t('خودکار') ?> <?= t('تیک') ?>‌<?= t('ها') ?> (<?= t('بعد') ?> <?= t('از') ?> <?= t('انتخاب') ?> <?= t('می‌شود') ?> <?= t('دستی') ?> تغییرشان داد)
function accResetAll() {
    document.querySelectorAll('#permForm .acc-chk').forEach(function (cb) { cb.checked = false; accSyncRow(cb); });
    document.querySelectorAll('#permForm .acc-temp').forEach(function (t) { t.checked = false; });
}
function accApply(key, mode) {
    var cb = document.getElementById('acc_' + key);
    if (!cb) return;
    cb.checked = true;
    var radio = document.querySelector('#radios_' + key + ' input[value="' + mode + '"]');
    if (radio) radio.checked = true;
    accSyncRow(cb);
}
function accPreset(name) {
    accResetAll();
    var repKeys = [];
    document.querySelectorAll('#permForm .acc-chk').forEach(function (cb) { if (cb.value.indexOf('rep_') === 0) repKeys.push(cb.value); });
    var P = {
        office: { personnel:'e', work_hours:'e', salary:'e', advances:'e', leaves:'e', daily_reports:'e', work_orders:'e', petty_cash:'e', contracts:'e', archive:'v', audit:'v', form_emp_pay:'e', __reps:'v' },
        supervisor: { work_hours:'e', daily_reports:'e', work_orders:'e', leaves:'v', salary:'v', rep_work_hours:'v' },
        warehouse: { inventory:'e', form_anbar:'e', rep_monthly:'v' },
        accountant: { salary:'e', advances:'e', petty_cash:'e', contracts:'e', archive:'v', audit:'v', form_emp_pay:'e', __reps:'v' }
    };
    var p = P[name] || {};
    if (name === 'clear') return;
    Object.keys(p).forEach(function (k) {
        if (k === '__reps') { repKeys.forEach(function (rk) { accApply(rk, p[k]); }); return; }
        accApply(k, p[k]);
    });
}

function openPermissions(userId, btn) {
    document.getElementById('perm_user_id').value = userId;
    document.getElementById('perm_user_name').textContent = btn ? (btn.getAttribute('data-uname') || '<?= t('کاربر') ?>') : '<?= t('کاربر') ?>';
    // ریست <?= t('همه') ?> <?= t('چک') ?>‌باکس‌<?= t('ها') ?> <?= t('و') ?> رادیوها
    document.querySelectorAll('#permForm .acc-chk').forEach(function (cb) { cb.checked = false; accSyncRow(cb); });
    document.querySelectorAll('#permForm .acc-radios input[type="radio"]').forEach(function (r) { if (r.value === 'editor') r.checked = true; });
    document.getElementById('perm_wm_master').checked = false;

    var special = btn && btn.getAttribute('data-special') === '1';
    document.getElementById('permSpecialNotice').style.display = special ? 'block' : 'none';
    document.getElementById('permSaveBtn').disabled = special;
    document.getElementById('permSaveBtn').style.opacity = special ? '.5' : '1';

    var roleSel = document.getElementById('perm_system_role');
    if (roleSel) {
        var rVal = (btn && btn.getAttribute('data-role')) ? btn.getAttribute('data-role') : 'worker';
        roleSel.value = rVal;
    }

    if (btn) {
        var map = {};
        try { map = JSON.parse(btn.getAttribute('data-access') || '{}'); } catch (e) {}
        var tempKeys = map['@temp'] || [];
        Object.keys(map).forEach(function (key) {
            if (key === '@wm') { document.getElementById('perm_wm_master').checked = true; return; }
            if (key === '@temp') return;
            var cb = document.getElementById('acc_' + key);
            if (!cb) return;
            cb.checked = true;
            var radio = document.querySelector('#radios_' + key + ' input[value="' + (map[key] === 'viewer' ? 'viewer' : 'editor') + '"]');
            if (radio) radio.checked = true;
            accSyncRow(cb);
            // Restore previously saved temporary viewer access.
            var tempChk = document.querySelector('#radios_' + key + ' .acc-temp');
            if (tempChk && tempKeys.indexOf(key) !== -1) tempChk.checked = true;
        });
    }
    openModal('permModal');
}

// حل <?= t('مشکل') ?> work_role - <?= t('حذف') ?> select<?= t('های') ?> <?= t('غیرفعال') ?> <?= t('قبل') ?> <?= t('از') ?> سابمیت
function fixWorkRoleSelects(formPrefix) {
    var typeRadios = document.querySelectorAll('#' + formPrefix + 'Modal input[name="' + (formPrefix === 'add' ? 'person_type' : 'is_karigar') + '"]');
    var val = 0;
    typeRadios.forEach(function(r) { if (r.checked) val = parseInt(r.value); });

    // <?= t('حذف') ?> <?= t('همه') ?> select<?= t('های') ?> work_role
    var form = document.getElementById(formPrefix + 'Form') || document.querySelector('#' + formPrefix + 'Modal form');
    if (!form) return true;
    form.querySelectorAll('select[name="work_role"]').forEach(function(sel) {
        sel.removeAttribute('name');
    });

    // <?= t('فعال') ?> <?= t('کردن') ?> <?= t('فقط') ?> select مرتبط
    if (val === 1) {
        var el = document.getElementById(formPrefix + '_work_role_worker');
        if (el) el.setAttribute('name', 'work_role');
    } else if (val === 2) {
        var el = document.getElementById(formPrefix + '_work_role_peymankar');
        if (el) el.setAttribute('name', 'work_role');
    } else {
        var el = document.getElementById(formPrefix + '_work_role');
        if (el) el.setAttribute('name', 'work_role');
    }
    return true;
}

function fixEditWorkRole() { return fixWorkRoleSelects('edit'); }
function fixAddWorkRole() { return fixWorkRoleSelects('add'); }
</script>

<!-- ═══ مودال افزایش حقوق تکی (§1) ═══ -->
<div id="raiseModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title">📈 <?= t('raise_single_title') ?> — <span id="raiseName"></span></h3><button onclick="closeModal('raiseModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="salary_raise">
            <input type="hidden" name="scope" value="single">
            <input type="hidden" name="user_id" id="raiseUserId">
            <div class="modal-body">
                <div style="padding:10px 14px;background:var(--bg);border-radius:10px;margin-bottom:12px;font-size:13px">
                    <?= t('nav_salary') ?> <?= t('فعلی') ?>: <strong id="raiseCurrent"></strong> <span class="text-muted" id="raiseKind" style="font-size:11px"></span>
                </div>
                <div class="form-group"><label class="form-label"><?= t('sal_type') ?> <?= t('افزایش *') ?></label>
                    <div class="d-flex gap-3" style="align-items:center">
                        <label style="display:flex;gap:4px;align-items:center;cursor:pointer"><input type="radio" name="raise_mode" value="amount" checked onchange="raiseModeChanged()"> <?= t('مبلغی') ?> (<?= t('sal_toman') ?>)</label>
                        <label style="display:flex;gap:4px;align-items:center;cursor:pointer"><input type="radio" name="raise_mode" value="percent" onchange="raiseModeChanged()"> <?= t('raise_mode_percent') ?> <?= t('(٪)') ?></label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label" id="raiseValueLabel"><?= t('مبلغ') ?> <?= t('افزایش') ?> (<?= t('sal_toman') ?>) *</label>
                        <input type="number" name="raise_value" id="raiseValue" class="form-control" required min="0" step="1000" oninput="raisePreviewUpdate()">
                    </div>
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('اجرا') ?> *</label>
                        <input type="text" name="start_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required>
                    </div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('raise_reason') ?></label><input type="text" name="reason" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('افزایش') ?> <?= t('سالانه') ?>"></div>
                <div id="raisePreview" style="display:none;padding:10px 14px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:10px;font-size:13px"></div>
                <p class="text-muted" style="font-size:11px;margin-top:8px">💡 <?= t('اگر') ?> <?= t('wh_date') ?> <?= t('اجرا') ?> <?= t('در') ?> <?= t('آینده') ?> <?= t('باشد') ?>، <?= t('nav_salary') ?> <?= t('جدید') ?> <?= t('فقط') ?> <?= t('برای') ?> <?= t('روزهای') ?> «<?= t('lea_from') ?> <?= t('آن') ?> <?= t('wh_date') ?> <?= t('به') ?> <?= t('بعد') ?>» <?= t('در') ?> <?= t('btn_calculate') ?> <?= t('nav_salary') ?> <?= t('اعمال') ?> <?= t('می‌شود') ?> <?= t('و') ?> <?= t('محاسبات') ?> <?= t('rep_month') ?>‌<?= t('های') ?> <?= t('گذشته') ?> <?= t('تغییر') ?> <?= t('نمی‌کند') ?>.</p>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('raise_apply') ?></button><button type="button" onclick="closeModal('raiseModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>

<!-- ═══ مودال افزایش گروهی (§1) ═══ -->
<div id="groupRaiseModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title">📈 <?= t('raise_group_btn') ?></h3><button onclick="closeModal('groupRaiseModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="salary_raise">
            <input type="hidden" name="scope" id="groupScope" value="all">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('دسته') ?><?= t('‌بندی *') ?></label>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm" id="gsAll" onclick="setGroupScope('all')"><?= t('همه') ?></button>
                        <button type="button" class="btn btn-ghost btn-sm" id="gsWorkers" onclick="setGroupScope('workers')"><?= t('scope_workers') ?></button>
                        <button type="button" class="btn btn-ghost btn-sm" id="gsEmployees" onclick="setGroupScope('employees')"><?= t('scope_employees') ?></button>
                    </div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('sal_type') ?> <?= t('افزایش *') ?></label>
                    <div class="d-flex gap-3" style="align-items:center">
                        <label style="display:flex;gap:4px;align-items:center;cursor:pointer"><input type="radio" name="raise_mode" value="percent" checked onchange="groupModeChanged()"> <?= t('raise_mode_percent') ?> <?= t('(٪)') ?></label>
                        <label style="display:flex;gap:4px;align-items:center;cursor:pointer"><input type="radio" name="raise_mode" value="amount" onchange="groupModeChanged()"> <?= t('مبلغی') ?> (<?= t('sal_toman') ?>)</label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label" id="groupValueLabel"><?= t('درصد') ?> <?= t('افزایش *') ?></label>
                        <input type="number" name="raise_value" id="groupValue" class="form-control" required min="0" step="0.5" oninput="groupPreviewUpdate()">
                    </div>
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('اجرا') ?> *</label>
                        <input type="text" name="start_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required>
                    </div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('raise_reason') ?></label><input type="text" name="reason" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('افزایش') ?> <?= t('سالانه') ?> ۱۴۰۵"></div>
                <div id="groupPreview" style="display:none;padding:10px 14px;background:rgba(234,179,8,0.1);border:1px solid rgba(234,179,8,0.35);border-radius:10px;font-size:13px"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-warning" onclick="return confirm('<?= t('افزایش') ?> <?= t('گروهی') ?> <?= t('برای') ?> <?= t('همه') ?> <?= t('افراد') ?> <?= t('این') ?> <?= t('دسته') ?> <?= t('ثبت') ?> <?= t('شود') ?>؟')"><?= t('raise_apply') ?> <?= t('گروهی') ?></button><button type="button" onclick="closeModal('groupRaiseModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>

<!-- ═══ مودال‌های تاریخچه حقوق (§1) ═══ -->
<?php foreach ($personnel as $p):
    if ($p['is_karigar'] == 2) continue;
    $rows = $hist_map[$p['id']] ?? [];
    $unit = $p['is_karigar'] == 1 ? t('sal_daily') : t('sal_monthly');
?>
<div id="histModal<?= $p['id'] ?>" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:640px">
        <div class="modal-header"><h3 class="modal-title">🕓 <?= t('inv_history') ?> <?= t('nav_salary') ?> — <?= $p['full_name'] ?> <span class="text-muted" style="font-size:12px">(<?= $unit ?>)</span></h3><button onclick="closeModal('histModal<?= $p['id'] ?>')" class="modal-close">✕</button></div>
        <div class="modal-body" style="max-height:60vh;overflow-y:auto">
            <?php if (empty($rows)): ?>
            <p class="text-muted text-center" style="padding:20px"><?= t('هنوز') ?> <?= t('افزایشی') ?> <?= t('ثبت نشده') ?></p>
            <?php else: ?>
            <table style="font-size:12px">
                <thead><tr><th><?= t('lea_from') ?></th><th><?= t('به') ?></th><th><?= t('wh_date') ?> <?= t('اجرا') ?></th><th><?= t('adv_reason') ?></th><th><?= t('ثبت‌کننده') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $h): ?>
                    <tr>
                        <td><?= $h['old_salary'] !== null && $h['old_salary'] !== '' ? number_format((float)$h['old_salary']) : '—' ?></td>
                        <td style="font-weight:700;color:var(--success)"><?= number_format((float)$h['new_salary']) ?></td>
                        <td><?= ($h['start_date'] && $h['start_date'] !== '0000-00-00') ? to_jalali($h['start_date']) : '<span class="text-muted">' . (($h['reason'] ?? '') === 'ایجاد اولیه' ? t('اولیه') : t('ویرایش مستقیم')) . '</span>' ?></td>
                        <td><?= $h['reason'] ?: '—' ?></td>
                        <td class="text-muted"><?= $h['changer'] ?: '—' ?></td>
                        <td>
                            <?php if (is_strict_admin() && $h['start_date'] && $h['start_date'] !== '0000-00-00'): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('این') ?> <?= t('رکورد') ?> <?= t('chat_delete') ?>')"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="salary_raise_delete">
                                <input type="hidden" name="history_id" value="<?= $h['id'] ?>">
                                <button class="btn btn-ghost btn-sm" title="<?= t('btn_delete') ?>"><i data-lucide="trash-2"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="button" onclick="closeModal('histModal<?= $p['id'] ?>')" class="btn btn-ghost"><?= t('jdp_close') ?></button></div>
    </div>
</div>
<?php endforeach; ?>

<script>
// ═══ <?= t('افزایش حقوق') ?> (§1) ═══
var raiseBase = 0;
function openRaiseModal(uid, name, base, isWorker) {
    raiseBase = parseFloat(base) || 0;
    document.getElementById('raiseUserId').value = uid;
    document.getElementById('raiseName').textContent = name;
    document.getElementById('raiseCurrent').textContent = raiseBase.toLocaleString() + ' <?= t('sal_toman') ?>';
    document.getElementById('raiseKind').textContent = isWorker ? '(<?= t('sal_daily') ?>)' : '(<?= t('sal_monthly') ?>)';
    document.getElementById('raiseValue').value = '';
    document.getElementById('raisePreview').style.display = 'none';
    openModal('raiseModal');
}
function raiseModeChanged() {
    var pct = document.querySelector('#raiseModal input[name="raise_mode"]:checked').value === 'percent';
    document.getElementById('raiseValueLabel').textContent = pct ? '<?= t('درصد') ?> <?= t('افزایش') ?> <?= t('(٪)') ?> *' : '<?= t('مبلغ') ?> <?= t('افزایش') ?> (<?= t('sal_toman') ?>) *';
    raisePreviewUpdate();
}
function raisePreviewUpdate() {
    var mode = document.querySelector('#raiseModal input[name="raise_mode"]:checked').value;
    var v = parseFloat(document.getElementById('raiseValue').value) || 0;
    var box = document.getElementById('raisePreview');
    if (v <= 0) { box.style.display = 'none'; return; }
    var nw = mode === 'percent' ? Math.round(raiseBase * (1 + v / 100)) : Math.round(raiseBase + v);
    box.style.display = 'block';
    box.innerHTML = '✅ <?= t('nav_salary') ?> <?= t('جدید') ?>: <strong>' + nw.toLocaleString() + ' <?= t('sal_toman') ?></strong> (' +
        (mode === 'percent' ? v + '٪ <?= t('افزایش') ?>' : '+' + v.toLocaleString() + ' <?= t('sal_toman') ?>') + ')';
}
function openGroupRaise() {
    document.getElementById('groupValue').value = '';
    document.getElementById('groupPreview').style.display = 'none';
    openModal('groupRaiseModal');
}
var groupCounts = {all: <?= (int)$gc_all ?>, workers: <?= (int)$gc_workers ?>, employees: <?= (int)$gc_employees ?>};
function setGroupScope(sc) {
    document.getElementById('groupScope').value = sc;
    ['All', 'Workers', 'Employees'].forEach(function(k) {
        document.getElementById('gs' + k).className = 'btn btn-ghost btn-sm';
    });
    document.getElementById('gs' + sc.charAt(0).toUpperCase() + sc.slice(1)).className = 'btn btn-primary btn-sm';
    groupPreviewUpdate();
}
function groupModeChanged() {
    var pct = document.querySelector('#groupRaiseModal input[name="raise_mode"]:checked').value === 'percent';
    document.getElementById('groupValueLabel').textContent = pct ? '<?= t('درصد') ?> <?= t('افزایش') ?> <?= t('(٪)') ?> *' : '<?= t('مبلغ') ?> <?= t('افزایش') ?> <?= t('برای') ?> <?= t('هر') ?> <?= t('per_count') ?> (<?= t('sal_toman') ?>) *';
    groupPreviewUpdate();
}
function groupPreviewUpdate() {
    var sc = document.getElementById('groupScope').value;
    var mode = document.querySelector('#groupRaiseModal input[name="raise_mode"]:checked').value;
    var v = parseFloat(document.getElementById('groupValue').value) || 0;
    var box = document.getElementById('groupPreview');
    if (v <= 0) { box.style.display = 'none'; return; }
    box.style.display = 'block';
    box.innerHTML = '⚠️ <?= t('این') ?> <?= t('افزایش') ?> <?= t('روی') ?> <strong>' + (groupCounts[sc] || 0) + ' <?= t('per_count') ?></strong> <?= t('اعمال') ?> <?= t('می‌شود') ?> — ' +
        (mode === 'percent' ? v + '٪ <?= t('روی') ?> <?= t('nav_salary') ?> <?= t('هر') ?> <?= t('per_count') ?>' : '+' + v.toLocaleString() + ' <?= t('sal_toman') ?> <?= t('روی') ?> <?= t('nav_salary') ?> <?= t('هر') ?> <?= t('per_count') ?>');
}
</script>

<!-- حذف کامل نیرو پس از پایان کار -->
<div id="purgePersonnelModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:520px;border:2px solid var(--danger)">
        <div class="modal-header"><h3 class="modal-title" style="color:var(--danger)">🗑️ <?= t('btn_delete') ?> <?= t('کامل') ?> <?= t('و') ?> <?= t('غیرقابل') ?> <?= t('btn_back') ?></h3><button onclick="closeModal('purgePersonnelModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="purge_personnel"><input type="hidden" name="user_id" id="purge_user_id">
            <div class="modal-body">
                <p style="line-height:1.9"><?= t('تمام') ?> <?= t('info') ?> <strong id="purge_name"></strong> <?= t('شامل') ?> <?= t('حضور') ?>، <?= t('گزارش') ?>‌<?= t('ها') ?>، <?= t('nav_leaves') ?>، <?= t('sal_advance') ?>، <?= t('sal_bonus') ?>/<?= t('sal_penalty') ?>، <?= t('چت') ?> <?= t('و') ?> <?= t('اتصال') ?> <?= t('پیمانکار') ?> <?= t('btn_delete') ?> <?= t('می‌شود') ?>. <?= t('این') ?> <?= t('عمل') ?> <?= t('قابل') ?> <?= t('btn_back') ?> <?= t('نیست') ?>.</p>
                <div class="form-group"><label class="form-label"><?= t('برای') ?> <?= t('تأیید') ?>، <?= t('per_full_name') ?> <?= t('نیرو') ?> <?= t('را') ?> <?= t('دقیقاً') ?> <?= t('وارد کنید') ?>:</label><input type="text" id="purge_confirm_text" class="form-control" oninput="verifyPurgeName()" autocomplete="off"></div>
            </div>
            <div class="modal-footer"><button type="button" onclick="closeModal('purgePersonnelModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button><button type="submit" id="purge_submit" class="btn btn-danger" disabled><?= t('btn_delete') ?> <?= t('کامل') ?></button></div>
        </form>
    </div>
</div>

<!-- ═══ §v12 §6: مودال پایان کار ═══ -->
<div id="endWorkModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title">📅 <?= t('wh_end') ?> <?= t('کار') ?> — <span id="ew_name"></span></h3><button onclick="closeModal('endWorkModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="end_work">
            <input type="hidden" name="user_id" id="ew_user_id">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('wh_end') ?> <?= t('کار') ?> *</label><input type="text" name="end_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required></div>
                <div class="form-group"><label class="form-label"><?= t('توضیح (اختیاری)') ?></label><input type="text" name="end_note" class="form-control" placeholder="<?= t('مثلاً') ?> <?= t('per_contract_end') ?>"></div>
                <p class="text-muted" style="font-size:12px;line-height:1.9;margin:0">
                    <?= t('lea_from') ?> <?= t('آن') ?> <?= t('wh_date') ?> <?= t('به') ?> <?= t('بعد') ?> <?= t('این') ?> <?= t('per_count') ?> <?= t('در') ?> <?= t('لیست') ?>‌<?= t('های') ?> <?= t('عملیاتی') ?> (<?= t('ساعت') ?> <?= t('کاری') ?>، <?= t('انبار،') ?> <?= t('nav_forms') ?>) <?= t('نمی‌آید') ?>؛ <?= t('اما') ?>
                    <b><?= t('سوابقش حفظ') ?> <?= t('می‌شود') ?></b> <?= t('و') ?> <?= t('nav_salary') ?> <?= t('rep_month') ?>‌<?= t('هایی') ?> <?= t('که') ?> <?= t('کارکرد') ?> <?= t('داشته') ?> <?= t('در') ?> <?= t('صفحه') ?> <?= t('nav_salary') ?> <?= t('می‌ماند') ?>.
                    💡 <?= t('قبل') ?> <?= t('lea_from') ?> <?= t('تسویه') ?>، <?= t('کالاهای') ?> <?= t('امانی') ?>/<?= t('تحویلی') ?> <?= t('برنگشتهٔ او') ?> <?= t('را') ?> <?= t('در') ?> <?= t('صفحه') ?> <?= t('nav_inventory') ?> <?= t('بررسی') ?> <?= t('کنید') ?>.
                </p>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-danger"><?= t('ثبت') ?> <?= t('wh_end') ?> <?= t('کار') ?></button><button type="button" onclick="closeModal('endWorkModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>

<!-- ═══ §v12 §6: مودال شروع کار مجدد ═══ -->
<div id="restartWorkModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title">🎉 <?= t('wh_start') ?> <?= t('کار') ?> <?= t('مجدد') ?> — <span id="rw_name"></span></h3><button onclick="closeModal('restartWorkModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="restart_work">
            <input type="hidden" name="user_id" id="rw_user_id">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('wh_start') ?> <?= t('مجدد') ?> *</label><input type="text" name="start_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required></div>
                <p class="text-muted" style="font-size:12px;margin:0"><?= t('lea_from') ?> <?= t('آن') ?> <?= t('wh_date') ?> <?= t('به') ?> <?= t('بعد') ?> <?= t('این') ?> <?= t('per_count') ?> <?= t('دوباره') ?> <?= t('در سایت') ?> <?= t('فعال') ?> <?= t('می‌شود') ?>.</p>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success"><?= t('ثبت') ?> <?= t('wh_start') ?> <?= t('مجدد') ?></button><button type="button" onclick="closeModal('restartWorkModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
