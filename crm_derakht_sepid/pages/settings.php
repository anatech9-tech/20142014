<?php
$pageTitle = t('nav_settings');
require_once __DIR__ . '/../includes/letterhead.php';

// Legacy web SQL restore is unsafe: never read or execute an uploaded dump here.
// These early paths must run before the settings page's automatic schema changes.
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && ($_POST['action'] ?? null)==='restore') {
    http_response_code(403); echo 'بازیابی از طریق وب غیرفعال است؛ بازیابی فقط در محیط ایزوله و توسط مسئول پایگاه داده انجام شود.'; return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && in_array($_POST['action'] ?? null,['backup','download_backup'],true)) {
    $token=$_POST['csrf_token'] ?? null;
    if (!is_string($token) || !function_exists('verify_csrf') || !verify_csrf($token)) { http_response_code(419); echo 'درخواست معتبر نیست.'; return; }
    try {
        if (!is_strict_admin() || is_nazer_kol() || (!access_is_special_account() && access_mode('settings')==='viewer')) throw new DomainException('Forbidden');
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([(int)($_SESSION['user_id'] ?? 0)]); $actor=$st->fetch(PDO::FETCH_ASSOC);
        if (!$actor || $actor['status']!=='active' || $actor['role']!=='admin') throw new DomainException('Forbidden');
    } catch (Throwable $e) { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
    require_once __DIR__.'/../includes/backup_web.php';
    if ($_POST['action']==='download_backup') {
        $filename=$_POST['filename'] ?? null; if (!is_string($filename)) { http_response_code(404); echo 'فایل بکاپ در دسترس نیست.'; return; }
        backup_web_send(BACKUP_DIR,$filename); return;
    }
    try {
        if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR,0700,true) && !is_dir(BACKUP_DIR)) throw new RuntimeException('Backup directory unavailable');
        $filename='backup_'.date('Y-m-d_His').'_'.bin2hex(random_bytes(4)).'.sql';
        $tmp=BACKUP_DIR.'.manual_'.bin2hex(random_bytes(8)).'.tmp'; $file=BACKUP_DIR.$filename;
        try { operations_stream_database($pdo,$tmp); if (!rename($tmp,$file)) throw new RuntimeException('Backup publication failed'); }
        finally { if (is_file($tmp)) @unlink($tmp); }
        backup_web_send(BACKUP_DIR,$filename);
    } catch (Throwable $e) { error_log('Manual backup failed'); http_response_code(503); echo 'تهیه بکاپ ممکن نیست.'; }
    return;
}

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
        $keys = ['regular_hours','employee_daily_required_hours','employee_monthly_leave_quota_days','overtime_coefficient','night_coefficient','night_start','morning_start','morning_end','overtime_start','night_end','late_extended','late_threshold','late_extended_threshold','karigar_work_day','karigar_extra_day_hours','karigar_night_min','karigar_friday_start','karigar_friday_end','friday_coefficient','holiday_coefficient','monthly_salary_days','backup_daily_retention','backup_weekly_retention','backup_monthly_retention','payroll_excluded_usernames'];
        
        $ws_target = (int)($_POST['target_workshop_id'] ?? $ws_id);
        if ($ws_target <= 0) $ws_target = $ws_id;

        // اگر مدیر واحد است (و ادمین یا مدیر پروژه نیست) → ثبت درخواست برای تأیید
        if (!$can_direct_save) {
            $proposed = [];
            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    $proposed[$k] = $_POST[$k];
                }
            }
            $stmt = $pdo->prepare("INSERT INTO settings_requests (workshop_id, requested_by, settings_data, status) VALUES (?, ?, ?, 'pending')");
            $stmt->execute([$ws_target, $_SESSION['user_id'], json_encode($proposed, JSON_UNESCAPED_UNICODE)]);
            showToast(t('درخواست تغییر تنظیمات واحد ثبت شد و پس از تأیید مدیر پروژه اعمال خواهد شد'));
            redirect("index.php?page=settings");
            exit;
        }

        // ادمین یا مدیر پروژه: ذخیره مستقیم روی همان واحد
        $stmt = $pdo->prepare("INSERT INTO workshop_settings (workshop_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $stmt->execute([$ws_target, $k, $_POST[$k]]);
            }
        }
        showToast(t('تنظیمات اختصاصی واحد با موفقیت ذخیره شد'));
        redirect("index.php?page=settings");
        exit;
    }

    // ─── تارگت و کمیسیون (سند نهایی سازمان — این مقادیر در محاسبهٔ زندهٔ کمیسیون صفحات «حقوق»/«پرسنل» خوانده می‌شوند) ───
    if ($action === 'save_commission_settings') {
        if (!is_strict_admin()) {
            showToast(t('دسترسی ندارید'), 'error');
            redirect('index.php?page=settings');
            exit;
        }
        require_once __DIR__ . '/../includes/org_commission.php';
        ensure_org_commission_schema($pdo);
        $ins = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $margin = (float)($_POST['commission_margin_pct'] ?? 25);
        $ins->execute(['commission_margin_pct', (string)max(0, min(100, $margin))]);
        foreach (['commission_target_sales_toman', 'commission_target_team_toman', 'commission_target_bd_toman'] as $k) {
            $v = (float)str_replace(',', '', (string)($_POST[$k] ?? 0));
            $ins->execute([$k, (string)max(0, $v)]);
        }
        // نرخ ارز به تومان (۰ = نامشخص؛ محاسبه با آن ارز صفر می‌شود و هشدار نمایش داده می‌شود)
        foreach (['USD', 'EUR', 'CNY', 'AED', 'IRR'] as $cur) {
            $v = (float)str_replace(',', '', (string)($_POST['fx_to_toman_' . $cur] ?? 0));
            $ins->execute(['fx_to_toman_' . $cur, (string)max(0, $v)]);
        }
        $rates = (array)($_POST['tier_rate'] ?? []);
        $stU = $pdo->prepare("UPDATE commission_tier_rules SET rate_pct = ? WHERE id = ?");
        foreach ($rates as $tid => $rate) {
            $r = (float)str_replace(',', '', (string)$rate);
            $stU->execute([max(0, min(100, $r)), (int)$tid]);
        }
        $liveNow = isset($_POST['fx_live_enabled']) ? '1' : '0';
        $ins->execute(['fx_live_enabled', $liveNow]);
        $toast = t('تنظیمات تارگت و کمیسیون ذخیره شد');
        if ($liveNow === '1') {
            require_once __DIR__ . '/../includes/fx.php';
            $rr = fx_live_refresh($pdo);
            $toast .= !empty($rr['ok']) ? (' — نرخ ارز به‌روز شد' . (($rr['as_of'] ?? '') !== '' ? ' (نرخ ' . $rr['as_of'] . ')' : '')) : (' — اما گرفتن نرخ لحظه‌ای ناموفق بود: ' . ($rr['error'] ?? 'خطای نامشخص'));
        }
        showToast($toast);
        redirect('index.php?page=settings');
        exit;
    }

    // ─── نرخ ارز لحظه‌ای «نرخ‌سنج» — دکمهٔ «به‌روزرسانی همین حالا» ───
    if ($action === 'fx_refresh_now') {
        if (!is_strict_admin()) {
            showToast(t('دسترسی ندارید'), 'error');
            redirect('index.php?page=settings');
            exit;
        }
        require_once __DIR__ . '/../includes/fx.php';
        $rr = fx_live_refresh($pdo);
        showToast(!empty($rr['ok']) ? ('✅ نرخ ارز به‌روز شد' . (($rr['as_of'] ?? '') !== '' ? ' — نرخ ' . $rr['as_of'] : '')) : ('❌ خطا در گرفتن نرخ: ' . ($rr['error'] ?? 'خطای نامشخص')), !empty($rr['ok']) ? 'success' : 'error');
        redirect('index.php?page=settings');
        exit;
    }

    // ─── پروفایل شرکت / سربرگ چاپ (لوگو/مهر/امضا + اطلاعات ثبتی) — فراواحد‌ها، به همه چاپ‌ها اعمال می‌شود ───
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
            showToast(t('درخواست تغییر تنظیمات واحد تأیید و اعمال شد'));
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
        <strong><?= t('واحد جاری:') ?> <?= htmlspecialchars($_SESSION['workshop_name'] ?? '') ?></strong>
        <div class="text-muted" style="font-size:11.5px"><?= t('هر تغییری در این صفحه، فقط و فقط برای همین واحد ذخیره و اعمال می‌شود و واحد‌های دیگر تغییر نخواهند کرد.') ?></div>
    </div>
</div>

<?php if ($can_direct_save && !empty($pending_set_requests)): ?>
<!-- کارت درخواست‌های تأیید تنظیمات واحد -->
<div class="card mb-4 animate-in" style="border-right:4px solid #f59e0b">
    <div class="card-header"><h3 class="card-title">🔔 <?= t('درخواست‌های تغییر تنظیمات واحد در انتظار تأیید') ?> (<?= count($pending_set_requests) ?>)</h3></div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th><?= t('واحد') ?></th>
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
            <p class="text-muted mb-3" style="font-size:12px">مرجع سازمان فروش: ساعت کار عادی ۸ تا ۱۷ با ضریب ۱.۰۰. همهٔ مقادیر این‌جا در دیتابیس ذخیره می‌شوند و در کل سیستم (حقوق، گزارش‌ها، مرخصی) اعمال می‌شوند.</p>
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
                <div class="form-group"><label class="form-label"><?= t('روزهای مبنای تبدیل حقوق ماهانه به روزانه/ساعتی') ?></label><input type="number" name="monthly_salary_days" class="form-control" min="1" max="31" step="0.5" value="<?= (float)($settings['monthly_salary_days'] ?? 26) ?>"><small class="text-muted" style="font-size:10px">حقوق روزانه = ماهانه ÷ این عدد (پیش‌فرض ۲۶)</small></div>
                <div class="form-group"></div>
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
        <p class="text-muted mb-3" style="font-size:12px">سازگاری با داده‌های قدیمی (کارگاه/پیمانکار): هر روز ۸ ساعت کار (با ۱ ساعت استراحت). هر ۸ ساعت اضافه‌کاری = ۱ روز کاری. شب‌کاری زیر ۴ ساعت = ۴ ساعت حساب می‌شود. این تنظیمات روی نیروهای قدیمی (is_karigar) اعمال می‌شود؛ سازمان فروش فعلی از «ضرایب حقوق» بالا استفاده می‌کند.</p>
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
            <i data-lucide="save"></i> <?= $can_direct_save ? t('set_save') . ' ' . t('این واحد') : t('ارسال درخواست تغییر تنظیمات جهت تأیید مدیر پروژه') ?>
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

<?php if (is_strict_admin()): ?>
<!-- کارت تارگت و کمیسیون (سند نهایی سازمان) -->
<?php
require_once __DIR__ . '/../includes/org_commission.php';
ensure_org_commission_schema($pdo);
$__comm = commission_targets($pdo);
$__tiers = [];
try { $__tiers = $pdo->query("SELECT * FROM commission_tier_rules ORDER BY FIELD(role_code,'sales_expert','sales_manager','business_development'), min_pct")->fetchAll(); } catch (Throwable $e) {}
$__roleNames = ['sales_expert' => 'کارشناس فروش', 'sales_manager' => 'مدیر فروش', 'business_development' => 'توسعه بازار'];
require_once __DIR__ . '/../includes/fx.php';
$__fx = fx_live_status($pdo);
$__fxCachedJalali = '';
if ($__fx['cached_at'] > 0) { $__fxCachedJalali = (function_exists('to_jalali') ? to_jalali(date('Y-m-d', $__fx['cached_at'])) : date('Y-m-d', $__fx['cached_at'])) . ' ' . date('H:i', $__fx['cached_at']); }
?>
<form method="POST" class="card mt-4 animate-in"><?= get_csrf_field() ?>
    <input type="hidden" name="action" value="save_commission_settings">
    <div class="card-header"><h3 class="card-title">🎯 تارگت و کمیسیون (طبق سند نهایی سازمان)</h3></div>
    <p class="text-muted mb-3" style="font-size:12px">این مقادیر از سند «ساختار سازمانی و نظام جبران خدمات» (<code>docs/COMPANY_ORG_POLICY.md</code>) آمده‌اند؛ در «حقوق» و «پرسنل»، کمیسیونِ تارگتی به‌صورت زنده (پله‌ای و نسبت به تحقق ماهانه) با همین جدول محاسبه می‌شود. واحد مبلغ‌ها: تومان.</p>
    <div class="grid-2">
        <div class="form-group"><label class="form-label">حاشیهٔ سود ناخالص مفروض (٪)</label><input type="number" step="0.5" min="0" max="100" name="commission_margin_pct" class="form-control" value="<?= (float)$__comm['margin_pct'] ?>"></div>
        <div class="form-group"><label class="form-label">تارگت کارشناس فروش</label><input type="number" name="commission_target_sales_toman" class="form-control" min="0" step="1000000" value="<?= (int)$__comm['sales_toman'] ?>"></div>
        <div class="form-group"><label class="form-label">تارگت تیم فروش (مدیر فروش)</label><input type="number" name="commission_target_team_toman" class="form-control" min="0" step="1000000" value="<?= (int)$__comm['team_toman'] ?>"></div>
        <div class="form-group"><label class="form-label">تارگت توسعه بازار</label><input type="number" name="commission_target_bd_toman" class="form-control" min="0" step="1000000" value="<?= (int)$__comm['bd_toman'] ?>"></div>
    </div>
    <div style="background:rgba(16,185,129,.09);border:1px dashed #10b981;border-radius:10px;padding:10px 12px;margin:2px 0 12px;font-size:11px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:800;font-size:12px;color:#047857">
            <input type="checkbox" name="fx_live_enabled" value="1" <?= !empty($__fx['enabled']) ? 'checked' : '' ?>> 📡 نرخ لحظه‌ای ارز فعال باشد — منبع: «نرخ‌سنج» (هر ۳۰ دقیقه کش می‌شود)
        </label>
        <div class="text-muted" style="font-size:10.5px;margin-top:6px;line-height:1.9">منبع: <a href="https://nerkh.jahankhahan.shop/developers" target="_blank" rel="noopener" style="color:#047857">نرخ‌سنج</a> — نرخ بازار آزاد تهران به تومان (دلار/یورو/یوان/درهم/پوند + تتر). در حالت فعال، موتور استعلام و موتور کمیسیون نرخ زنده را به کار می‌برند؛ فیلدهای دستی بالا دست‌نخورده می‌مانند و فقط در حالت خاموش (یا اگر ارزی در کش نباشد) اعمال می‌شوند.<br>📌 نرخ مؤثر فعلی: <b><?= htmlspecialchars(function_exists('fx_live_summary') ? fx_live_summary($pdo) : '—') ?></b></div>
        <div style="margin-top:6px;font-size:10.5px;line-height:1.9">
            <?php if (!empty($__fx['enabled'])): ?>
                <?php if (($__fx['error'] ?? '') !== ''): ?>
                    <span style="color:#b45309;font-weight:700">⚠️ <?= htmlspecialchars((string)$__fx['error']) ?></span>
                <?php elseif ($__fxCachedJalali !== ''): ?>
                    <span style="color:#047857;font-weight:700">✅ آخرین به‌روزرسانی: <?= htmlspecialchars($__fxCachedJalali) ?><?php if (!empty($__fx['as_of'])): ?> — نرخ <?= htmlspecialchars((string)$__fx['as_of']) ?><?php endif; ?></span>
                <?php else: ?>
                    <span style="color:#0369a1;font-weight:700">⏳ کش خالی است — نرخ با باز شدن همین صفحه گرفته می‌شود (یا دکمهٔ پایین).</span>
                <?php endif; ?>
            <?php else: ?>
                <span style="color:#64748b;font-weight:700">🔕 حالت دستی فعال است — نرخ‌ها دقیقاً همان مقادیر این فرم هستند.</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="background:rgba(37,99,235,.06);border:1px dashed var(--border);border-radius:10px;padding:10px 12px;margin-bottom:12px;font-size:11px;color:var(--text-secondary)">
        <b>📄 مرجع سند — حقوق ثابت پیشنهادی (بودجه‌ریزی، نه قرارداد):</b> کارشناس فروش ۲۳ تا ۲۸ م | مدیر فروش ۳۵ تا ۴۵ م | توسعهٔ بازار ۳۰ تا ۳۵ م | هماهنگ‌کنندهٔ سفارشات ۲۸ تا ۳۵ م تومان/ماه (به‌علاوهٔ کمیسیون و مزایای قانونی). مبالغ واقعیِ هر نفر را در «پرسنل» ثبت کنید؛ این‌جا صرفاً برای یادآوری سند نهایی نمایش داده می‌شود و قابل ویرایش نیست.
    </div>
    <p class="text-muted" style="font-size:11.5px;margin:2px 0 8px">💱 نرخ هر ارز به «تومان» (۰ = نامشخص — فروش با آن ارز در محاسبهٔ کمیسیون صفر می‌شود و هشدار می‌گیرید):</p>
    <div class="grid-2">
        <?php foreach (['USD' => 'دلار', 'EUR' => 'یورو', 'CNY' => 'یوآن چین', 'AED' => 'درهم', 'IRR' => 'ریال (۱ ریال = ۰٫۱ تومان)'] as $cur => $lbl): ?>
        <div class="form-group"><label class="form-label"><?= $lbl ?> (<?= $cur ?>)</label><input type="number" name="fx_to_toman_<?= $cur ?>" class="form-control" min="0" step="0.1" value="<?= number_format((float)commission_setting($pdo, 'fx_to_toman_' . $cur, $cur === 'IRR' ? '0.1' : '0'), 1, '.', '') ?>"></div>
        <?php endforeach; ?>
    </div>
    <div class="table-container">
        <table>
            <thead><tr><th>نقش</th><th>از (٪ تحقق)</th><th>تا (٪ تحقق)</th><th>کمیسیون (٪)</th><th>توضیح</th></tr></thead>
            <tbody>
            <?php foreach ($__tiers as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($__roleNames[$t['role_code']] ?? $t['role_code']) ?></td>
                    <td><?= (int)$t['min_pct'] ?>٪</td>
                    <td><?= $t['max_pct'] === null ? '∞' : (int)$t['max_pct'] . '٪' ?></td>
                    <td><input type="number" step="0.5" min="0" max="100" name="tier_rate[<?= (int)$t['id'] ?>]" class="form-control" style="width:90px" value="<?= number_format((float)$t['rate_pct'], 2, '.', '') ?>"></td>
                    <td class="text-muted" style="font-size:11px"><?= htmlspecialchars($t['note'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button type="submit" class="btn btn-primary"><i data-lucide="save"></i> ذخیرهٔ تارگت و پله‌ها</button></div>
</form>
<form method="post" style="margin-top:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><?= get_csrf_field() ?><input type="hidden" name="action" value="fx_refresh_now">
    <span style="font-size:11px;color:var(--text-secondary)">بدون صبر کش ۳۰ دقیقه‌ای، نرخ را همین حالا از نرخ‌سنج بگیر:</span>
    <button type="submit" class="btn btn-success btn-sm"><i data-lucide="refresh-cw"></i> به‌روزرسانی همین حالا</button>
</form>
<!-- کارت میان‌بر «استعلام قیمت» -->
<a href="index.php?page=quote_flow" class="card mt-4 animate-in" style="display:block;text-decoration:none;border-right:4px solid var(--primary)">
    <div class="card-header d-flex justify-between align-center" style="margin-bottom:6px"><h3 class="card-title">🧾 استعلام قیمت — گردش‌کار و حاشیهٔ سود</h3><i data-lucide="arrow-left"></i></div>
    <p class="text-muted" style="font-size:11.5px;padding:0 4px 12px;margin:0">تنظیم بصری مراحل «استعلام → تأیید مدیر → اعلام قیمت نت → تأیید نهایی و سود → فیکس» با گره و اتصال (شبیه n8n) + حاشیهٔ سود پیش‌فرض. استعلام‌ها از هر معامله در «پایپ‌لاین واردات» (پیش‌فاکتور) ساخته می‌شوند.</p>
</a>
<?php endif; ?>


<?php include 'includes/footer.php'; ?>
