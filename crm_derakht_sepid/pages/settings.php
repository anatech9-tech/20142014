<?php
$pageTitle = t('nav_settings');
require_once __DIR__ . '/../includes/letterhead.php';

// Auto-migration: add hours and hourly_rate to bonuses_penalties
try {
    $chk = $pdo->query("SHOW COLUMNS FROM bonuses_penalties LIKE 'hours'");
    if ($chk->fetch() === false) {
        $pdo->exec("ALTER TABLE bonuses_penalties ADD COLUMN hours DECIMAL(5,2) DEFAULT 0 AFTER amount");
        $pdo->exec("ALTER TABLE bonuses_penalties ADD COLUMN hourly_rate DECIMAL(15,2) DEFAULT 0 AFTER hours");
    }
} catch (Exception $e) {}

$ws_id = (int)($_SESSION['workshop_id'] ?? 1);
$can_direct_save = is_strict_admin() || is_project_manager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        $keys = ['regular_hours','employee_daily_required_hours','employee_monthly_leave_quota_days','overtime_coefficient','night_coefficient','night_start','morning_start','morning_end','overtime_start','night_end','late_extended','late_threshold','late_extended_threshold','karigar_work_day','karigar_extra_day_hours','karigar_night_min','karigar_friday_start','karigar_friday_end','friday_coefficient','holiday_coefficient','backup_daily_retention','backup_weekly_retention','backup_monthly_retention','payroll_excluded_usernames'];
        
        $ws_target = (int)($_POST['target_workshop_id'] ?? $ws_id);
        if ($ws_target <= 0) $ws_target = $ws_id;

        // اگر مدیر کارگاه است (و ادمین یا مدیر پروژه نیست) → ثبت درخواست برای تأیید
        if (!$can_direct_save) {
            $proposed = [];
            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    $proposed[$k] = $_POST[$k];
                }
            }
            $stmt = $pdo->prepare("INSERT INTO settings_requests (workshop_id, requested_by, settings_data, status) VALUES (?, ?, ?, 'pending')");
            $stmt->execute([$ws_target, $_SESSION['user_id'], json_encode($proposed, JSON_UNESCAPED_UNICODE)]);
            showToast(t('درخواست تغییر تنظیمات کارگاه ثبت شد و پس از تأیید مدیر پروژه اعمال خواهد شد'));
            redirect("index.php?page=settings");
            exit;
        }

        // ادمین یا مدیر پروژه: ذخیره مستقیم روی همان کارگاه
        $stmt = $pdo->prepare("INSERT INTO workshop_settings (workshop_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $stmt->execute([$ws_target, $k, $_POST[$k]]);
            }
        }
        showToast(t('تنظیمات اختصاصی کارگاه با موفقیت ذخیره شد'));
        redirect("index.php?page=settings");
        exit;
    }

    // ─── پروفایل شرکت / سربرگ چاپ (لوگو/مهر/امضا + اطلاعات ثبتی) — فراکارگاه‌ها، به همه چاپ‌ها اعمال می‌شود ───
    if ($action === 'save_letterhead') {
        if (!is_strict_admin() && !is_project_manager()) {
            showToast(t('دسترسی ندارید'), 'error');
            redirect('index.php?page=settings');
        }
        $allowed = ['letterhead_company_name','letterhead_economic_code','letterhead_national_id','letterhead_reg_no','letterhead_phone','letterhead_address','letterhead_website'];
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($allowed as $k) {
            if (isset($_POST[$k])) $stmt->execute([$k, trim(sanitize($_POST[$k]))]);
        }
        $getv = function ($key) use ($pdo) { try { $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?"); $st->execute([$key]); $v = $st->fetchColumn(); return $v === false ? '' : (string)$v; } catch (Throwable $e) { return ''; } };
        $lh_dir = __DIR__ . '/../uploads/letterhead/';
        if (!is_dir($lh_dir)) @mkdir($lh_dir, 0755, true);
        $image_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        foreach (['letterhead_logo', 'letterhead_stamp', 'letterhead_signature'] as $field) {
            $old = $getv($field);
            if (($_POST['remove_' . $field] ?? '') === '1') {
                if ($old && is_file(__DIR__ . '/../' . $old)) @unlink(__DIR__ . '/../' . $old);
                $stmt->execute([$field, '']);
                $old = '';
            }
            $file = $_FILES[$field] ?? null;
            if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) continue;
                if (($file['size'] ?? 0) > 8 * 1024 * 1024) { showToast(t('حجم فایل از ۸ مگابایت بیشتر است'), 'error'); continue; }
                $mime = @mime_content_type($file['tmp_name']) ?: '';
                if (!isset($image_mimes[$mime])) { showToast(t('فقط تصویر JPG/PNG/WEBP مجاز است'), 'error'); continue; }
                $name = 'lh_' . $field . '_' . date('YmdHis') . '.' . $image_mimes[$mime];
                if (move_uploaded_file($file['tmp_name'], $lh_dir . $name)) {
                    compress_image($lh_dir . $name, 900, 85);
                    if ($old && is_file(__DIR__ . '/../' . $old)) @unlink(__DIR__ . '/../' . $old);
                    $stmt->execute([$field, 'uploads/letterhead/' . $name]);
                }
            }
        }
        showToast(t('پروفایل شرکت / سربرگ ذخیره شد'));
        redirect('index.php?page=settings');
        exit;
    }

    if ($action === 'approve_settings_request' && $can_direct_save) {
        $req_id = (int)$_POST['request_id'];
        $st = $pdo->prepare("SELECT * FROM settings_requests WHERE id = ? AND status = 'pending'");
        $st->execute([$req_id]);
        $req = $st->fetch();
        if ($req) {
            $data = json_decode($req['settings_data'], true) ?: [];
            $ins = $pdo->prepare("INSERT INTO workshop_settings (workshop_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($data as $k => $v) {
                $ins->execute([$req['workshop_id'], $k, $v]);
            }
            $pdo->prepare("UPDATE settings_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$_SESSION['user_id'], $req_id]);
            showToast(t('درخواست تغییر تنظیمات کارگاه تأیید و اعمال شد'));
        }
        redirect("index.php?page=settings");
        exit;
    }

    if ($action === 'reject_settings_request' && $can_direct_save) {
        $req_id = (int)$_POST['request_id'];
        $pdo->prepare("UPDATE settings_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$_SESSION['user_id'], $req_id]);
        showToast(t('درخواست تغییر تنظیمات رد شد'));
        redirect("index.php?page=settings");
        exit;
    }
    
    if ($action === 'save_menu_order') {
        if (!is_strict_admin()) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=settings"); exit; }
        $known = array_keys(nav_menu_labels());
        $order = [];
        foreach (array_filter(array_map('trim', explode(',', $_POST['menu_order'] ?? ''))) as $k) {
            if (in_array($k, $known) && !in_array($k, $order)) $order[] = $k;
        }
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('menu_order', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([implode(',', $order)]);
        showToast(t('ترتیب منو ذخیره شد'));
        redirect("index.php?page=settings");
    }

    if ($action === 'save_workshop_employers') {
        if (!is_strict_admin() && !is_project_manager()) {
            showToast(t('دسترسی ندارید'), 'error');
            redirect("index.php?page=settings");
            exit;
        }
        $emp_names = $_POST['ws_employer_name'] ?? [];
        $del_logos = $_POST['ws_delete_logo'] ?? [];

        foreach ($emp_names as $wid => $ename) {
            $wid = (int)$wid;
            $ename = sanitize($ename);
            $extra_sql = '';
            $params = [$ename];

            if (!empty($del_logos[$wid])) {
                $extra_sql .= ", employer_logo = NULL";
            } elseif (!empty($_FILES['ws_employer_logo']['name'][$wid])) {
                if (!is_dir(LOGO_DIR)) mkdir(LOGO_DIR, 0777, true);
                $filename = 'emp_' . uniqid() . '_' . basename($_FILES['ws_employer_logo']['name'][$wid]);
                $path = 'uploads/logos/' . $filename;
                if (move_uploaded_file($_FILES['ws_employer_logo']['tmp_name'][$wid], LOGO_DIR . $filename)) {
                    compress_image(LOGO_DIR . $filename, 800, 85);
                    $extra_sql .= ", employer_logo = ?";
                    $params[] = $path;
                }
            }

            $params[] = $wid;
            $stmt = $pdo->prepare("UPDATE workshops SET employer_name = ? {$extra_sql} WHERE id = ?");
            $stmt->execute($params);
        }
        showToast(t('تنظیمات کارفرمای کارگاه‌ها با موفقیت ذخیره شد'));
        redirect("index.php?page=settings");
        exit;
    }

    if ($action === 'backup') {
        // §fix: بکاپ دستی قبلاً بدون CREATE TABLE و با addslashes (فرار نادرست
        // کوتیشن) ساخته می‌شد؛ نتیجه روی دیتابیس خالی اصلاً ریستور نمی‌شد.
        // حالا از همان موتور بکاپ خودکار (SHOW CREATE TABLE + quote امن) استفاده می‌شود.
        $sql = operations_dump_database($pdo);
        
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
        $filename = 'backup_' . date('Y-m-d_His') . '.sql';
        $file = BACKUP_DIR . $filename;
        file_put_contents($file, $sql);
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    
    if ($action === 'restore') {
        if (!isset($_FILES['restore_file']) || $_FILES['restore_file']['error'] !== UPLOAD_ERR_OK) {
            showToast(t('فایل بکاپ انتخاب نشده'), 'error');
            redirect("index.php?page=settings");
        }
        $sql = file_get_contents($_FILES['restore_file']['tmp_name']);
        if (empty($sql)) {
            showToast(t('فایل خالی است'), 'error');
            redirect("index.php?page=settings");
        }
        // §fix: اگر جدول‌ها از قبل موجود باشند CREATE TABLE خطا می‌دهد و ریکاوری
        // شکست می‌خورد؛ ابتدا جدول‌های داخل فایل بکاپ حذف می‌شوند تا بازسازی کامل باشد.
        try {
            if (preg_match_all('/CREATE TABLE `([a-z0-9_]+)`/i', $sql, $tm) && !empty($tm[1])) {
                $drops = "SET FOREIGN_KEY_CHECKS=0;\n";
                foreach (array_unique($tm[1]) as $tb) $drops .= "DROP TABLE IF EXISTS `$tb`;\n";
                $drops .= "SET FOREIGN_KEY_CHECKS=1;\n";
                $sql = $drops . $sql;
            }
            $pdo->exec($sql);
            showToast(t('ریکاوری با موفقیت انجام شد'));
        } catch (PDOException $e) {
            showToast(t('خطا در ریکاوری: ') . $e->getMessage(), 'error');
        }
        redirect("index.php?page=settings");
    }
    
    if ($action === 'download_backup') {
        $filename = $_POST['filename'] ?? '';
        $file = BACKUP_DIR . basename($filename);
        if (file_exists($file)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }
    }
}

$settings = get_workshop_settings($pdo, $ws_id);

$pending_set_requests = [];
try {
    if ($can_direct_save) {
        $st = $pdo->query("SELECT sr.*, w.name AS ws_name, u.full_name AS requester_name FROM settings_requests sr JOIN workshops w ON w.id = sr.workshop_id JOIN users u ON u.id = sr.requested_by WHERE sr.status = 'pending' ORDER BY sr.id DESC");
        $pending_set_requests = $st->fetchAll();
    } else {
        $st = $pdo->prepare("SELECT sr.*, w.name AS ws_name FROM settings_requests sr JOIN workshops w ON w.id = sr.workshop_id WHERE sr.workshop_id = ? AND sr.status = 'pending' ORDER BY sr.id DESC");
        $st->execute([$ws_id]);
        $pending_set_requests = $st->fetchAll();
    }
} catch (Throwable $e) {}

$backups = glob(BACKUP_DIR . '*.sql');

include 'includes/header.php';
?>
<?php $lh_company = letterhead_company(); $lh_logo = letterhead_image('letterhead_logo'); $lh_stamp = letterhead_image('letterhead_stamp'); $lh_sign = letterhead_image('letterhead_signature'); ?>
<?php if (is_strict_admin() || is_project_manager()): ?>
<div class="card mb-4 animate-in" style="border:2px solid #8b5cf6">
    <div class="card-header"><h3 class="card-title">🏢 <?= t('پروفایل شرکت / سربرگ چاپ') ?></h3></div>
    <p class="text-muted" style="font-size:12px;line-height:1.9">این اطلاعات و تصاویر، بالای اسناد چاپ‌شوندهٔ سیستم (لیست واریزی، صندوق، انبارگردانی، گزارش انبار و …) به‌عنوان سربرگ شرکت چاپ می‌شوند.</p>
    <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_letterhead">
        <div class="grid-2">
            <div class="form-group"><label class="form-label">نام شرکت (روی سربرگ)</label><input type="text" name="letterhead_company_name" class="form-control" value="<?= htmlspecialchars((string)$lh_company['letterhead_company_name'], ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars((string)$lh_company['app_name'], ENT_QUOTES) ?>"></div>
            <div class="form-group"><label class="form-label">وب‌سایت</label><input type="text" name="letterhead_website" class="form-control" value="<?= htmlspecialchars((string)$lh_company['letterhead_website'], ENT_QUOTES) ?>" placeholder="www.example.ir"></div>
            <div class="form-group"><label class="form-label">کد اقتصادی</label><input type="text" name="letterhead_economic_code" class="form-control" value="<?= htmlspecialchars((string)$lh_company['letterhead_economic_code'], ENT_QUOTES) ?>"></div>
            <div class="form-group"><label class="form-label">شناسهٔ ملی</label><input type="text" name="letterhead_national_id" class="form-control" value="<?= htmlspecialchars((string)$lh_company['letterhead_national_id'], ENT_QUOTES) ?>"></div>
            <div class="form-group"><label class="form-label">شمارهٔ ثبت</label><input type="text" name="letterhead_reg_no" class="form-control" value="<?= htmlspecialchars((string)$lh_company['letterhead_reg_no'], ENT_QUOTES) ?>"></div>
            <div class="form-group"><label class="form-label">تلفن شرکت</label><input type="text" name="letterhead_phone" class="form-control" value="<?= htmlspecialchars((string)$lh_company['letterhead_phone'], ENT_QUOTES) ?>"></div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">آدرس شرکت</label><input type="text" name="letterhead_address" class="form-control" value="<?= htmlspecialchars((string)$lh_company['letterhead_address'], ENT_QUOTES) ?>"></div>
        </div>
        <div class="grid-2">
        <?php foreach ([['letterhead_logo','لوگوی شرکت',$lh_logo],['letterhead_stamp','مهر شرکت',$lh_stamp],['letterhead_signature','امضای مجاز / مدیرعامل',$lh_sign]] as $__lh_item): ?>
            <div class="form-group">
                <label class="form-label"><?= $__lh_item[1] ?></label>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <?php if ($__lh_item[2]): ?><span style="display:inline-flex;align-items:center;gap:8px;border:1px solid var(--border);border-radius:8px;padding:4px 10px"><img src="<?= htmlspecialchars($__lh_item[2]) ?>" style="max-height:48px" alt=""><label style="font-size:11px;display:flex;gap:4px;align-items:center"><input type="checkbox" name="remove_<?= $__lh_item[0] ?>" value="1"> حذف</label></span><?php endif; ?>
                    <input type="file" name="<?= $__lh_item[0] ?>" accept="image/jpeg,image/png,image/webp" class="form-control" style="max-width:220px">
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <button class="btn btn-primary mt-2">💾 ذخیرهٔ پروفایل شرکت</button>
    </form>
</div>
<?php endif; ?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('set_title') ?></h2>
</div>

<div class="mb-3" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(59,130,246,.08);border-radius:10px;border:1px solid var(--border)">
    <span style="font-size:18px">🏢</span>
    <div>
        <strong><?= t('کارگاه جاری:') ?> <?= htmlspecialchars($_SESSION['workshop_name'] ?? '') ?></strong>
        <div class="text-muted" style="font-size:11.5px"><?= t('هر تغییری در این صفحه، فقط و فقط برای همین کارگاه ذخیره و اعمال می‌شود و کارگاه‌های دیگر تغییر نخواهند کرد.') ?></div>
    </div>
</div>

<?php if ($can_direct_save && !empty($pending_set_requests)): ?>
<!-- کارت درخواست‌های تأیید تنظیمات کارگاه -->
<div class="card mb-4 animate-in" style="border-right:4px solid #f59e0b">
    <div class="card-header"><h3 class="card-title">🔔 <?= t('درخواست‌های تغییر تنظیمات کارگاه در انتظار تأیید') ?> (<?= count($pending_set_requests) ?>)</h3></div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th><?= t('کارگاه') ?></th>
                    <th><?= t('درخواست‌دهنده') ?></th>
                    <th><?= t('تاریخ درخواست') ?></th>
                    <th><?= t('خلاصه تغییرات پیشنهادی') ?></th>
                    <th><?= t('wh_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_set_requests as $pr):
                    $pdata = json_decode($pr['settings_data'], true) ?: [];
                    $summary = [];
                    foreach ($pdata as $pk => $pv) {
                        $curr_val = $settings[$pk] ?? '';
                        if ((string)$pv !== (string)$curr_val) {
                            $summary[] = $pk . ': ' . htmlspecialchars($pv);
                        }
                    }
                    if (empty($summary)) {
                        $summary[] = count($pdata) . ' مورد تنظیمات';
                    }
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($pr['ws_name']) ?></strong></td>
                    <td><?= htmlspecialchars($pr['requester_name']) ?></td>
                    <td><?= to_jalali($pr['created_at']) ?></td>
                    <td style="font-size:11px;max-width:300px;word-break:break-all">
                        <?= implode(' | ', array_slice($summary, 0, 5)) ?><?= count($summary) > 5 ? ' ...' : '' ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="approve_settings_request">
                                <input type="hidden" name="request_id" value="<?= $pr['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm"><i data-lucide="check"></i> <?= t('تأیید و اعمال') ?></button>
                            </form>
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="reject_settings_request">
                                <input type="hidden" name="request_id" value="<?= $pr['id'] ?>">
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
<?php elseif (!$can_direct_save && !empty($pending_set_requests)): ?>
<div class="card mb-4 animate-in" style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:12px 16px;border-radius:10px">
    ⏳ <strong><?= t('توجه:') ?></strong> <?= t('شما یک درخواست تغییر تنظیمات در انتظار تأیید دارید. این درخواست به محض بررسی توسط مدیر پروژه یا مدیر کل اعمال خواهد شد.') ?>
</div>
<?php endif; ?>

<form method="POST"><?= get_csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    
    <div class="grid-2 animate-in">
        <div class="card">
            <div class="card-header"><h3 class="card-title">⏱️ <?= t('set_salary_rules') ?></h3></div>
            <p class="text-muted mb-3" style="font-size:12px"><?= t('ساعات کار عادی: ۸ تا ۱۷ — ضریب ۱.۰۰') ?></p>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('ساعات کار روزانه (پایه)') ?></label><input type="number" name="regular_hours" class="form-control" value="<?= $settings['regular_hours'] ?? 9 ?>"></div>
                <div class="form-group"><label class="form-label"><?= t('ضریب اضافه‌کاری (۱۷ تا ۲۱)') ?></label><input type="number" name="overtime_coefficient" class="form-control" value="<?= $settings['overtime_coefficient'] ?? 1.33 ?>" step="0.01"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('ضریب شب‌کاری (۲۱ تا ۷:۳۰)') ?></label><input type="number" name="night_coefficient" class="form-control" value="<?= $settings['night_coefficient'] ?? 2 ?>"></div>
                <div class="form-group"><label class="form-label"><?= t('ضریب') ?> <?= t('wh_friday') ?></label><input type="number" name="friday_coefficient" class="form-control" value="<?= $settings['friday_coefficient'] ?? 1.33 ?>" step="0.01"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('ضریب تعطیلات رسمی') ?></label><input type="number" name="holiday_coefficient" class="form-control" value="<?= $settings['holiday_coefficient'] ?? 2 ?>" step="0.01"></div>
                <div class="form-group"><label class="form-label"><?= t('ساعات کاری الزامی روزانه کارکنان') ?></label><input type="number" name="employee_daily_required_hours" class="form-control" value="<?= $settings['employee_daily_required_hours'] ?? 8 ?>" min="1" max="24" step="0.5"><small class="text-muted" style="font-size:10px"><?= t('ساعات الزامی ماهانه = روزهای ماه − جمعه‌ها − تعطیلات رسمی − مرخصی‌های تأییدشده × این مقدار') ?></small></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('سهمیه مرخصی ماهانه کارکنان (روز)') ?></label><input type="number" name="employee_monthly_leave_quota_days" class="form-control" value="<?= $settings['employee_monthly_leave_quota_days'] ?? 2 ?>" min="0" max="31" step="0.5"><small class="text-muted" style="font-size:10px"><?= t('سهمیه استفاده‌نشده از ساعت الزامی کم می‌شود (تبدیل به اضافه‌کار). غیبتِ بدون ثبت مرخصی، سهمیه را مصرف می‌کند و این تخفیف را از بین می‌برد.') ?></small></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('ساعت شروع کار روزانه') ?></label><input type="text" name="morning_start" class="form-control" value="<?= $settings['morning_start'] ?? '08:00' ?>"></div>
                <div class="form-group"><label class="form-label"><?= t('ساعت پایان کار روزانه') ?></label><input type="text" name="morning_end" class="form-control" value="<?= $settings['morning_end'] ?? '17:00' ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('زمان شروع اضافه‌کاری') ?></label><input type="text" name="overtime_start" class="form-control" value="<?= $settings['overtime_start'] ?? '17:00' ?>"></div>
                <div class="form-group"><label class="form-label"><?= t('زمان شروع شب‌کاری') ?></label><input type="text" name="night_start" class="form-control" value="<?= $settings['night_start'] ?? '21:00' ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('زمان پایان شب‌کاری') ?></label><input type="text" name="night_end" class="form-control" value="<?= $settings['night_end'] ?? '07:30' ?>"></div>
                <div class="form-group"></div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h3 class="card-title">⏰ <?= t('قواعد شب‌کاری (تا صبح)') ?></h3></div>
            <p class="text-muted mb-3" style="font-size:12px"><?= t('اگر نیرو تا ۳ صبح کار کرده و رفته، فردا تا ساعت مشخصی می‌تواند ورود بزند و حقوق از ۸ صبح حساب می‌شود.') ?></p>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('آستانه شب‌کاری طولانی') ?></label><input type="text" name="late_extended" class="form-control" value="<?= $settings['late_extended'] ?? '03:00' ?>"></div>
                <div class="form-group"></div>
            </div>
            <p class="text-muted mb-3" style="font-size:12px;border-top:1px solid var(--border);padding-top:10px;margin-top:5px"><strong><?= t('قبل از ۳ صبح:') ?></strong> <?= t('اگر نیرو قبل از ساعت بالا کارش را تمام کرده باشد') ?></p>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('آخرین ساعت ورود (قبل از ۳ صبح)') ?></label><input type="text" name="late_threshold" class="form-control" value="<?= $settings['late_threshold'] ?? '11:00' ?>"></div>
                <div class="form-group"></div>
            </div>
            <p class="text-muted mb-3" style="font-size:12px;border-top:1px solid var(--border);padding-top:10px;margin-top:5px"><strong><?= t('بعد از ۳ صبح:') ?></strong> <?= t('اگر نیرو بعد از ساعت بالا کارش را تمام کرده باشد') ?></p>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('آخرین ساعت ورود (بعد از ۳ صبح)') ?></label><input type="text" name="late_extended_threshold" class="form-control" value="<?= $settings['late_extended_threshold'] ?? '13:00' ?>"></div>
                <div class="form-group"></div>
            </div>
        </div>
    </div>
    
    <div class="card mt-4 animate-in">
        <div class="card-header"><h3 class="card-title">👷 <?= t('set_worker_rules') ?></h3></div>
        <p class="text-muted mb-3" style="font-size:12px"><?= t('قواعد کارگران: هر روز ۸ ساعت کار (با ۱ ساعت استراحت). هر ۸ ساعت اضافه‌کاری = ۱ روز کاری. شب‌کاری زیر ۴ ساعت = ۴ ساعت حساب می‌شود.') ?></p>
        <div class="grid-2">
            <div class="form-group"><label class="form-label"><?= t('ساعات کار عادی (بدون اضافه‌کاری)') ?></label><input type="number" name="karigar_work_day" class="form-control" value="<?= $settings['karigar_work_day'] ?? 8 ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('ساعات اضافه‌کاری = ۱ روز کاری') ?></label><input type="number" name="karigar_extra_day_hours" class="form-control" value="<?= $settings['karigar_extra_day_hours'] ?? 8 ?>"></div>
        </div>
        <div class="grid-2">
            <div class="form-group"><label class="form-label"><?= t('حداقل ساعات شب‌کاری') ?></label><input type="number" name="karigar_night_min" class="form-control" value="<?= $settings['karigar_night_min'] ?? 4 ?>"></div>
            <div class="form-group"></div>
        </div>
        <div class="grid-2">
            <div class="form-group"><label class="form-label"><?= t('ساعت شروع کار جمعه/تعطیل') ?></label><input type="text" name="karigar_friday_start" class="form-control" value="<?= $settings['karigar_friday_start'] ?? '08:00' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('ساعت پایان کار جمعه/تعطیل') ?></label><input type="text" name="karigar_friday_end" class="form-control" value="<?= $settings['karigar_friday_end'] ?? '13:00' ?>"></div>
        </div>
    </div>
    
    <div class="card mt-4 animate-in">
        <div class="card-header"><h3 class="card-title">💳 <?= t('تنظیمات صفحه حقوق') ?></h3></div>
        <p class="text-muted mb-3" style="font-size:12px"><?= t('پیمانکاران و نیروهای بدون حقوق ثبت‌شده خودکار حذف می‌شوند. این‌ها هم از صفحه حقوق حذف می‌شوند:') ?></p>
        <div class="form-group">
            <label class="form-label"><?= t('نام‌های کاربری مستثنی از صفحه حقوق (با کاما جدا کنید)') ?></label>
            <input type="text" name="payroll_excluded_usernames" class="form-control" dir="ltr" value="<?= htmlspecialchars($settings['payroll_excluded_usernames'] ?? 'yaser') ?>" placeholder="yaser,nazer,arash">
            <small class="text-muted" style="font-size:11px"><?= t('مثلاً: yaser یا نام کاربری مدیر/ناظر — این افراد دیگر در لیست حقوق نمایش داده نمی‌شوند') ?></small>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary btn-lg">
            <i data-lucide="save"></i> <?= $can_direct_save ? t('set_save') . ' ' . t('این کارگاه') : t('ارسال درخواست تغییر تنظیمات جهت تأیید مدیر پروژه') ?>
        </button>
    </div>
</form>

<?php if (is_strict_admin()): ?>
<!-- کارت ترتیب منو -->
<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title">🔀 <?= t('ترتیب آیتم‌های منو') ?></h3></div>
    <p class="text-muted mb-3" style="font-size:12px"><?= t('با') ?> <?= t('دکمه‌') ?><?= t('های') ?> <?= t('بالا/پایین') ?> <?= t('ترتیب') ?> <?= t('btn_show') ?> <?= t('آیتم') ?>‌<?= t('ها') ?> <?= t('در') ?> <?= t('منوی کناری') ?> <?= t('رو') ?> <?= t('تغییر') ?> <?= t('بده') ?> <?= t('و') ?> <?= t('btn_save') ?> <?= t('کن') ?>.</p>
    <form method="POST" id="menuOrderForm"><?= get_csrf_field() ?>
        <input type="hidden" name="action" value="save_menu_order">
        <input type="hidden" name="menu_order" id="menuOrderValue">
        <ul id="menuOrderList" style="list-style:none;padding:0;margin:0 0 12px">
            <?php
            $mo_labels = nav_menu_labels();
            $mo_raw = $settings['menu_order'] ?? '';
            $mo_keys = [];
            foreach (array_filter(array_map('trim', explode(',', $mo_raw))) as $k) {
                if (isset($mo_labels[$k]) && !in_array($k, $mo_keys)) $mo_keys[] = $k;
            }
            foreach (array_keys($mo_labels) as $k) {
                if (!in_array($k, $mo_keys)) $mo_keys[] = $k;
            }
            foreach ($mo_keys as $k):
            ?>
            <li data-key="<?= $k ?>" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;background:var(--surface)">
                <span style="flex:1;font-size:13px"><?= htmlspecialchars($mo_labels[$k]) ?></span>
                <button type="button" class="btn btn-ghost btn-sm" onclick="moveMenuItem(this,-1)" title="<?= t('بالا') ?>" style="padding:2px 8px">▲</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="moveMenuItem(this,1)" title="<?= t('پایین') ?>" style="padding:2px 8px">▼</button>
            </li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn btn-primary" onclick="saveMenuOrder()"><i data-lucide="save"></i> <?= t('btn_save') ?> <?= t('ترتیب منو') ?></button>
    </form>
</div>

<script>
function moveMenuItem(btn, dir) {
    var li = btn.closest('li');
    var list = document.getElementById('menuOrderList');
    if (dir < 0 && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
    else if (dir > 0 && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
}
function saveMenuOrder() {
    var keys = [];
    document.querySelectorAll('#menuOrderList li').forEach(function(li) { keys.push(li.dataset.key); });
    document.getElementById('menuOrderValue').value = keys.join(',');
    document.getElementById('menuOrderForm').submit();
}
</script>
<?php endif; ?>

<?php
$ws_list_for_emp = $pdo->query("SELECT id, name, employer_name, employer_logo FROM workshops WHERE status = 'active' ORDER BY id")->fetchAll();
?>
<!-- تنظیمات لوگو و نام کارفرمای کارگاه‌ها -->
<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title">🏢 <?= t('تنظیمات کارفرما و لوگو برای کارگاه‌ها (سربرگ فرم‌های گزارش)') ?></h3></div>
    <p class="text-muted mb-3" style="font-size:12px"><?= t('می‌توانید برای هر کارگاه لوگو و نام کارفرما تعیین کنید تا در سربرگ گزارش روزانه و HSE نمایش داده شود. در صورت خالی بودن، فیلد خالی می‌ماند؛ در صورت بارگذاری لوگو، لوگو نمایش داده می‌شود و در صورت درج نام، نام کارفرما درج خواهد شد.') ?></p>
    <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
        <input type="hidden" name="action" value="save_workshop_employers">
        <div style="overflow-x:auto">
            <table class="table" style="font-size:13px">
                <thead>
                    <tr>
                        <th style="width:25%"><?= t('نام کارگاه') ?></th>
                        <th style="width:35%"><?= t('نام کارفرما') ?></th>
                        <th style="width:40%"><?= t('لوگوی کارفرما') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ws_list_for_emp as $w): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($w['name']) ?></strong></td>
                        <td>
                            <input type="text" name="ws_employer_name[<?= $w['id'] ?>]" class="form-control" value="<?= htmlspecialchars($w['employer_name'] ?? '') ?>" placeholder="<?= t('مثلاً: گروه جاوید...') ?>">
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <?php if (!empty($w['employer_logo']) && file_exists(__DIR__ . '/../' . $w['employer_logo'])): ?>
                                    <img src="<?= htmlspecialchars($w['employer_logo']) ?>" style="height:34px;border-radius:4px;border:1px solid var(--border)">
                                    <label class="text-danger" style="font-size:11px;cursor:pointer">
                                        <input type="checkbox" name="ws_delete_logo[<?= $w['id'] ?>]" value="1"> <?= t('حذف') ?>
                                    </label>
                                <?php endif; ?>
                                <input type="file" name="ws_employer_logo[<?= $w['id'] ?>]" class="form-control" accept="image/*" style="max-width:200px;font-size:11px">
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> <?= t('ذخیره کارفرمای کارگاه‌ها') ?></button>
        </div>
    </form>
</div>

<form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="save_settings"><div class="card mt-4 animate-in"><div class="card-header"><h3 class="card-title">🗄️ <?= t('بکاپ خودکار') ?></h3></div><p class="text-muted" style="font-size:12px"><?= t('بکاپ در اولین بازدید روز ایجاد می‌شود؛ برای زمان‌بندی دقیق‌تر می‌توان PWA را با همین منطق با Cron اجرا کرد.') ?></p><div class="grid-2"><div class="form-group"><label class="form-label"><?= t('نگهداری بکاپ روزانه') ?></label><input type="number" name="backup_daily_retention" class="form-control" value="<?= $settings['backup_daily_retention'] ?? 7 ?>" min="1"></div><div class="form-group"><label class="form-label"><?= t('نگهداری بکاپ هفتگی') ?></label><input type="number" name="backup_weekly_retention" class="form-control" value="<?= $settings['backup_weekly_retention'] ?? 4 ?>" min="1"></div><div class="form-group"><label class="form-label"><?= t('نگهداری بکاپ ماهانه') ?></label><input type="number" name="backup_monthly_retention" class="form-control" value="<?= $settings['backup_monthly_retention'] ?? 12 ?>" min="1"></div></div><div class="mt-3"><button class="btn btn-primary"><?= t('set_save') ?> <?= t('btn_backup') ?></button></div></div></form>
<!-- Backup Section -->
<div class="card mt-4 animate-in">
    <div class="card-header"><h3 class="card-title"><?= t('set_backup') ?></h3></div>
    <div class="d-flex gap-3 flex-wrap">
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="backup">
            <button type="submit" class="btn btn-success"><i data-lucide="download"></i> <?= t('گرفتن') ?> <?= t('btn_backup') ?> + <?= t('btn_download') ?></button>
        </form>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?><input type="hidden" name="action" value="restore">
            <div class="d-flex gap-2 align-center">
                <input type="file" name="restore_file" class="form-control" accept=".sql" required style="width:200px">
                <button type="submit" class="btn btn-danger" onclick="return confirm('<?= t('آیا') ?> <?= t('lea_from') ?> <?= t('btn_restore') ?> <?= t('مطمئنید؟') ?> <?= t('تمام') ?> <?= t('info') ?> <?= t('فعلی') ?> <?= t('جایگزین') ?> <?= t('می‌شود') ?>!')"><i data-lucide="upload"></i> <?= t('btn_restore') ?></button>
            </div>
        </form>
    </div>
    <?php if (!empty($backups)): ?>
    <div class="mt-3">
        <h4 style="font-size:14px"><?= t('btn_backup') ?>‌<?= t('های') ?> <?= t('قبلی') ?>:</h4>
        <?php foreach (array_slice($backups, 0, 10) as $b): ?>
        <div class="d-flex justify-between align-center mt-2" style="padding:8px;background:var(--bg);border-radius:6px">
            <div class="d-flex align-center gap-2">
                <span style="font-size:12px"><?= basename($b) ?></span>
                <span style="font-size:11px" class="text-muted"><?= round(filesize($b)/1024) ?> KB</span>
            </div>
            <div class="d-flex gap-1">
                <form method="POST" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="download_backup"><input type="hidden" name="filename" value="<?= basename($b) ?>"><button class="btn btn-ghost btn-sm" style="font-size:11px"><?= t('btn_download') ?></button></form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
