<?php
$pageTitle = t('nav_leaves');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$is_admin = is_strict_admin() || is_project_manager() || is_workshop_manager() || has_permission('manage_leaves');

// Check if hourly columns exist
$has_hourly = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM leave_requests LIKE 'leave_type'");
    $has_hourly = $chk->fetch() !== false;
} catch (Exception $e) {}

// ورودی تاریخ/ساعت قبل از ذخیره باید واقعاً معتبر باشد؛ مقدار خالی نباید به 0000-00-00 تبدیل شود.
function leave_parse_date($raw) {
    $date = to_gregorian((string)$raw);
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)$date)) return '';
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y) ? $date : '';
}
function leave_time_minutes($raw) {
    $raw = trim((string)$raw);
    if (preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $raw)) {
        $parts = explode(':', $raw);
        $h = (int)$parts[0];
        $m = (int)$parts[1];
        return $h * 60 + $m;
    }
    return null;
}

// §fix: تاریخ مرخصی نباید بیش از ~۲ ماه با امروز فاصله داشته باشد.
// رایج‌ترین خطا تایپ سال قبلی است (مثلاً ۱۴۰۴ به‌جای ۱۴۰۵)؛ چنین رکوردی
// در ماه درست شمرده نمی‌شود و در حقوق «غیبت بدون مرخصی» جعلی می‌سازد.
function leave_date_too_far($gdate, $max_days = 62) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$gdate)) return true;
    $diff = abs(strtotime($gdate) - strtotime(date('Y-m-d')));
    return $diff > $max_days * 86400;
}
// §fix: مرخصی ساعتی فقط «یک تاریخ» دارد (پایان = شروع). قبلاً تاریخ پایانِ خالیِ مرخصی ساعتی
// هم به‌عنوان «خیلی دور» رد می‌شد و پیام «بیش از دو ماه فاصله» می‌داد.
function leave_range_too_far($leave_type, $start_date, $end_date, $max_days = 62) {
    if (leave_date_too_far($start_date, $max_days)) return true;
    if ($leave_type === 'hourly') return false;
    return leave_date_too_far($end_date, $max_days);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request') {
        $leave_type = ($_POST['leave_type'] ?? 'daily') === 'hourly' && $has_hourly ? 'hourly' : 'daily';
        $start_date = leave_parse_date($_POST['start_date'] ?? '');
        $end_date = leave_parse_date($_POST['end_date'] ?? '');

        if (!$start_date || ($leave_type === 'daily' && (!$end_date || $end_date < $start_date))) {
            showToast(t('تاریخ مرخصی نامعتبر است یا بازهٔ آن برعکس وارد شده است.'), 'error');
            redirect("index.php?page=leaves");
            exit;
        }

        if (leave_range_too_far($leave_type, $start_date, $end_date)) {
            showToast(t('تاریخ مرخصی بیش از دو ماه با امروز فاصله دارد — احتمالاً سال را اشتباه وارد کرده‌اید (مثلاً ۱۴۰۴ به‌جای ۱۴۰۵). تاریخ را اصلاح کنید.'), 'error');
            redirect("index.php?page=leaves");
            exit;
        }

        if ($leave_type === 'hourly') {
            $raw_st = $_POST['start_time'] ?? '';
            $raw_et = $_POST['end_time'] ?? '';
            $start_mins = leave_time_minutes($raw_st);
            $end_mins = leave_time_minutes($raw_et);
            $end_date = $start_date;
            if ($start_mins === null || $end_mins === null || $start_mins === $end_mins) {
                showToast(t('ساعت شروع و پایان مرخصی ساعتی را درست وارد کنید.'), 'error');
                redirect("index.php?page=leaves");
                exit;
            }
            $hours = round(($end_mins - $start_mins) / 60, 1);
            if ($hours < 0) $hours += 24;
            if ($hours <= 0 || $hours > 24) {
                showToast(t('مدت مرخصی ساعتی باید بیشتر از صفر و حداکثر ۲۴ ساعت باشد.'), 'error');
                redirect("index.php?page=leaves");
                exit;
            }
            $start_time = sprintf('%02d:%02d:00', intdiv($start_mins, 60), $start_mins % 60);
            $end_time = sprintf('%02d:%02d:00', intdiv($end_mins, 60), $end_mins % 60);
            $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, workshop_id, start_date, end_date, reason, leave_type, start_time, end_time, hours) VALUES (?, ?, ?, ?, ?, 'hourly', ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $ws_id, $start_date, $end_date, sanitize($_POST['reason'] ?? ''), $start_time, $end_time, $hours]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, workshop_id, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $ws_id, $start_date, $end_date, sanitize($_POST['reason'] ?? '')]);
        }
        showToast(t('درخواست ثبت شد'));
        redirect("index.php?page=leaves");
    }

    if ($action === 'direct_register') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=leaves"); exit; }
        $user_id = (int)$_POST['user_id'];
        $leave_type = ($_POST['leave_type'] ?? 'daily') === 'hourly' && $has_hourly ? 'hourly' : 'daily';
        $start_date = leave_parse_date($_POST['start_date'] ?? '');
        $end_date = leave_parse_date($_POST['end_date'] ?? '');

        if ($user_id <= 0 || !$start_date || ($leave_type === 'daily' && (!$end_date || $end_date < $start_date))) {
            showToast(t('اطلاعات مرخصی یا تاریخ آن نامعتبر است.'), 'error');
            redirect("index.php?page=leaves");
            exit;
        }

        if (leave_range_too_far($leave_type, $start_date, $end_date)) {
            showToast(t('تاریخ مرخصی بیش از دو ماه با امروز فاصله دارد — احتمالاً سال را اشتباه وارد کرده‌اید (مثلاً ۱۴۰۴ به‌جای ۱۴۰۵). تاریخ را اصلاح کنید.'), 'error');
            redirect("index.php?page=leaves");
            exit;
        }

        if ($leave_type === 'hourly') {
            $raw_st = $_POST['start_time'] ?? '';
            $raw_et = $_POST['end_time'] ?? '';
            $start_mins = leave_time_minutes($raw_st);
            $end_mins = leave_time_minutes($raw_et);
            $end_date = $start_date;
            if ($start_mins === null || $end_mins === null || $start_mins === $end_mins) {
                showToast(t('ساعت شروع و پایان مرخصی ساعتی را درست وارد کنید.'), 'error');
                redirect("index.php?page=leaves");
                exit;
            }
            $hours = round(($end_mins - $start_mins) / 60, 1);
            if ($hours < 0) $hours += 24;
            if ($hours <= 0 || $hours > 24) {
                showToast(t('مدت مرخصی ساعتی باید بیشتر از صفر و حداکثر ۲۴ ساعت باشد.'), 'error');
                redirect("index.php?page=leaves");
                exit;
            }
            $start_time = sprintf('%02d:%02d:00', intdiv($start_mins, 60), $start_mins % 60);
            $end_time = sprintf('%02d:%02d:00', intdiv($end_mins, 60), $end_mins % 60);
            $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, workshop_id, start_date, end_date, reason, status, approved_by, approved_at, leave_type, start_time, end_time, hours) VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW(), 'hourly', ?, ?, ?)");
            $stmt->execute([$user_id, $ws_id, $start_date, $end_date, sanitize($_POST['reason'] ?? ''), $_SESSION['user_id'], $start_time, $end_time, $hours]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, workshop_id, start_date, end_date, reason, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW())");
            $stmt->execute([$user_id, $ws_id, $start_date, $end_date, sanitize($_POST['reason'] ?? ''), $_SESSION['user_id']]);
        }
        $stmt2 = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt2->execute([$user_id]);
        showToast(t('مرخصی ') . $stmt2->fetchColumn() . ' ' . t('ثبت شد'));
        redirect("index.php?page=leaves");
    }

    if ($action === 'approve') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=leaves"); exit; }
        $stmt = $pdo->prepare("UPDATE leave_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$_SESSION['user_id'], (int)$_POST['id'], $ws_id]);
        showToast(t('تایید شد'));
        redirect("index.php?page=leaves");
    }

    if ($action === 'reject') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=leaves"); exit; }
        $stmt = $pdo->prepare("UPDATE leave_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$_SESSION['user_id'], sanitize($_POST['rejection_reason'] ?? ''), (int)$_POST['id'], $ws_id]);
        showToast(t('رد شد'));
        redirect("index.php?page=leaves");
    }

    // §fix: ویرایش رکورد مرخصی (حتی بعد از تأیید) توسط مدیر — برای اصلاح غلط‌های
    // تایپی مثل سال اشتباه که در محاسبهٔ حقوق «غیبت بدون مرخصی» جعلی می‌سازند.
    if ($action === 'edit') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=leaves"); exit; }
        $id = (int)$_POST['id'];
        if (is_strict_admin() || is_project_manager()) {
            $stmt = $pdo->prepare("SELECT lr.*, u.full_name FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("SELECT lr.*, u.full_name FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.id = ? AND lr.workshop_id = ?");
            $stmt->execute([$id, $ws_id]);
        }
        $old = $stmt->fetch();
        if (!$old) { showToast(t('رکورد یافت نشد'), 'error'); redirect("index.php?page=leaves"); exit; }

        $user_id = (int)$_POST['user_id'];
        $leave_type = ($_POST['leave_type'] ?? 'daily') === 'hourly' && $has_hourly ? 'hourly' : 'daily';
        $start_date = leave_parse_date($_POST['start_date'] ?? '');
        $end_date = leave_parse_date($_POST['end_date'] ?? '');
        $new_status = in_array($_POST['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $_POST['status'] : ($old['status'] ?: 'pending');

        if ($user_id <= 0 || !$start_date || ($leave_type === 'daily' && (!$end_date || $end_date < $start_date))) {
            showToast(t('اطلاعات مرخصی یا تاریخ آن نامعتبر است.'), 'error');
            redirect("index.php?page=leaves");
            exit;
        }

        $start_time = null; $end_time = null; $hours = null;
        if ($leave_type === 'hourly') {
            $raw_st = $_POST['start_time'] ?? '';
            $raw_et = $_POST['end_time'] ?? '';
            $start_mins = leave_time_minutes($raw_st);
            $end_mins = leave_time_minutes($raw_et);
            $end_date = $start_date;
            if ($start_mins === null || $end_mins === null || $start_mins === $end_mins) {
                showToast(t('ساعت شروع و پایان مرخصی ساعتی را درست وارد کنید.'), 'error');
                redirect("index.php?page=leaves");
                exit;
            }
            $hours = round(($end_mins - $start_mins) / 60, 1);
            if ($hours < 0) $hours += 24;
            if ($hours <= 0 || $hours > 24) {
                showToast(t('مدت مرخصی ساعتی باید بیشتر از صفر و حداکثر ۲۴ ساعت باشد.'), 'error');
                redirect("index.php?page=leaves");
                exit;
            }
            $start_time = sprintf('%02d:%02d:00', intdiv($start_mins, 60), $start_mins % 60);
            $end_time = sprintf('%02d:%02d:00', intdiv($end_mins, 60), $end_mins % 60);
        }

        try {
            if (is_strict_admin() || is_project_manager()) {
                $stmt = $pdo->prepare("UPDATE leave_requests SET user_id = ?, start_date = ?, end_date = ?, reason = ?, leave_type = ?, start_time = ?, end_time = ?, hours = ?, status = ? WHERE id = ?");
                $stmt->execute([$user_id, $start_date, $end_date, sanitize($_POST['reason'] ?? ''), $leave_type, $start_time, $end_time, $hours, $new_status, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE leave_requests SET user_id = ?, start_date = ?, end_date = ?, reason = ?, leave_type = ?, start_time = ?, end_time = ?, hours = ?, status = ? WHERE id = ? AND workshop_id = ?");
                $stmt->execute([$user_id, $start_date, $end_date, sanitize($_POST['reason'] ?? ''), $leave_type, $start_time, $end_time, $hours, $new_status, $id, $ws_id]);
            }
            // اگر رکورد از حالت غیرتأیید به تأیید تغییر کرد، تأییدکننده ثبت شود
            if ($new_status === 'approved' && ($old['status'] ?? '') !== 'approved') {
                $pdo->prepare("UPDATE leave_requests SET approved_by = ?, approved_at = NOW(), rejection_reason = NULL WHERE id = ?")->execute([$_SESSION['user_id'], $id]);
            }
            operations_audit($pdo, (int)$_SESSION['user_id'], 'edit', 'leave_request', $id,
                'ویرایش مرخصی ' . $old['full_name'] . ' → ' . to_jalali($start_date) . ($leave_type === 'hourly' ? ' (' . substr($start_time, 0, 5) . ' تا ' . substr($end_time, 0, 5) . ')' : ' تا ' . to_jalali($end_date)) . ' — ' . $new_status,
                'قبلی: ' . to_jalali((string)$old['start_date']) . ' تا ' . to_jalali((string)$old['end_date']) . ' — ' . $old['status']);
            showToast(t('مرخصی بروزرسانی شد'));
        } catch (Exception $e) {
            showToast(t('خطا در ذخیره') . ': ' . $e->getMessage(), 'error');
        }
        redirect("index.php?page=leaves");
    }

    // §fix: حذف کامل رکورد مرخصی توسط مدیر — با رد پا در لاگ تغییرات
    if ($action === 'delete') {
        if (!$is_admin) { showToast(t('دسترسی ندارید'), 'error'); redirect("index.php?page=leaves"); exit; }
        $id = (int)$_POST['id'];
        if (is_strict_admin() || is_project_manager()) {
            $stmt = $pdo->prepare("SELECT lr.*, u.full_name FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("SELECT lr.*, u.full_name FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.id = ? AND lr.workshop_id = ?");
            $stmt->execute([$id, $ws_id]);
        }
        $old = $stmt->fetch();
        if ($old) {
            if (is_strict_admin() || is_project_manager()) {
                $pdo->prepare("DELETE FROM leave_requests WHERE id = ?")->execute([$id]);
            } else {
                $pdo->prepare("DELETE FROM leave_requests WHERE id = ? AND workshop_id = ?")->execute([$id, $ws_id]);
            }
            operations_audit($pdo, (int)$_SESSION['user_id'], 'delete', 'leave_request', $id,
                'حذف مرخصی ' . $old['full_name'] . ' — ' . to_jalali((string)$old['start_date']) . ' (' . $old['status'] . ')', '');
            showToast(t('رکورد حذف شد'));
        } else {
            showToast(t('رکورد یافت نشد'), 'error');
        }
        redirect("index.php?page=leaves");
    }
}

$is_admin = is_strict_admin() || is_project_manager() || is_workshop_manager() || has_permission('manage_leaves');
// §v11: ناظرِ مرخصی = دیدن مرخصی همه بدون امکان تأیید/رد
$can_view_all = $is_admin || is_nazer() || in_array(access_mode('leaves'), ['viewer', 'editor'], true);
if ($can_view_all) {
    $stmt = $pdo->prepare("SELECT lr.*, u.full_name FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.workshop_id = ? ORDER BY lr.created_at DESC");
    $stmt->execute([$ws_id]);
} else {
    $stmt = $pdo->prepare("SELECT lr.*, u.full_name FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.workshop_id = ? AND lr.user_id = ? ORDER BY lr.created_at DESC");
    $stmt->execute([$ws_id, $_SESSION['user_id']]);
}
$leaves = $stmt->fetchAll();

$personnel = [];
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY u.full_name");
    $stmt->execute([$ws_id]);
    $personnel = $stmt->fetchAll();
}


// ═══════════════════════════════════════════════════════════════════════
// خلاصهٔ مرخصی ماهانهٔ پرسنل: هر ماه شمسی چقدر مرخصی روزانه/ساعتی گرفته و چقدر از سهمیه مانده
// قواعد دقیقاً مثل موتور حقوق (approved_leave_summary): فقط مرخصی «تأییدشده»؛ جمعه و تعطیل رسمی
// شمرده نمی‌شوند؛ مرخصی‌های هم‌پوشان یک‌بار حساب می‌شوند. سهمیه از «تنظیمات» خوانده می‌شود.
// ═══════════════════════════════════════════════════════════════════════
$ls_settings = get_workshop_settings($pdo, $ws_id);
$ls_day_hours = (float)($ls_settings['employee_daily_required_hours'] ?? 8); if ($ls_day_hours <= 0) $ls_day_hours = 8;
$ls_quota_days = max(0.0, (float)($ls_settings['employee_monthly_leave_quota_days'] ?? 2));
$ls_quota_hours = $ls_quota_days * $ls_day_hours;

// ماه انتخاب‌شده (پیش‌فرض: ماه جاری شمسی)
$ls_cur_key = jalali_month_key(date('Y-m-d'));
$ls_key = (string)($_GET['lm'] ?? $ls_cur_key);
if (!preg_match('/^\d{4}-\d{1,2}$/', $ls_key)) $ls_key = $ls_cur_key;
[$ls_jy, $ls_jm] = array_map('intval', explode('-', $ls_key));
if ($ls_jm < 1 || $ls_jm > 12 || $ls_jy < 1300 || $ls_jy > 1600) { [$ls_jy, $ls_jm] = array_map('intval', explode('-', $ls_cur_key)); }
$ls_shift = function ($jy, $jm, $delta) { $n = $jy * 12 + ($jm - 1) + $delta; return [intdiv($n, 12), $n % 12 + 1]; };

// ۶ ماه منتهی به ماه انتخاب‌شده (برای پنجرهٔ تاریخچهٔ هر نفر)
$ls_months = [];
for ($i = 0; $i < 6; $i++) {
    [$yy, $mm] = $ls_shift($ls_jy, $ls_jm, -$i);
    [$ms, $me] = jalali_month_bounds($yy, $mm);
    $ls_months[] = ['jy' => $yy, 'jm' => $mm, 'label' => jalali_month_name($mm) . ' ' . $yy, 'start' => $ms, 'end' => $me];
}
$ls_win_from = end($ls_months)['start'];
$ls_win_to = $ls_months[0]['end'];
$ls_holidays = _holidays_between($pdo, $ls_win_from, $ls_win_to);

// پرسنل قابل نمایش: مدیر/ناظر همه؛ بقیه فقط خودشان
$ls_people = [];
try {
    if ($can_view_all) {
        $__st = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar FROM users u JOIN workshop_personnel wp ON wp.user_id = u.id WHERE wp.workshop_id = ? AND wp.status = 'active' AND u.is_karigar != 2 AND u.username != 'yaser' ORDER BY u.full_name");
        $__st->execute([$ws_id]);
    } else {
        $__st = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar FROM users u WHERE u.id = ?");
        $__st->execute([$_SESSION['user_id']]);
    }
    $ls_people = $__st->fetchAll();
} catch (Throwable $e) {}

// همهٔ مرخصی‌های تأییدشده/در انتظار پنجره، یک‌جا
$ls_rows = [];
try {
    $__st = $pdo->prepare("SELECT * FROM leave_requests WHERE workshop_id = ? AND status IN ('approved','pending') AND end_date >= ? AND start_date <= ?");
    $__st->execute([$ws_id, $ls_win_from, $ls_win_to]);
    foreach ($__st->fetchAll() as $__r) $ls_rows[(int)$__r['user_id']][] = $__r;
} catch (Throwable $e) {}

function leave_month_usage(array $rows, array $holidays, $ms, $me, $status) {
    $covered = []; $hourly = 0.0;
    foreach ($rows as $l) {
        if (($l['status'] ?? '') !== $status) continue;
        if (($l['leave_type'] ?? 'daily') === 'hourly') {
            $d = (string)$l['start_date'];
            if ($d >= $ms && $d <= $me && date('w', strtotime($d)) != 5 && !in_array($d, $holidays, true)) $hourly += max(0, (float)($l['hours'] ?? 0));
            continue;
        }
        $from = max($ms, (string)$l['start_date']); $to = min($me, (string)$l['end_date']);
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
            $d = date('Y-m-d', $ts);
            if (date('w', $ts) == 5 || in_array($d, $holidays, true)) continue;
            $covered[$d] = true;
        }
    }
    return [count($covered), $hourly];
}
// «۱ روز و ۲:۳۰ ساعت» — ساعتِ مازاد بر روز کاری به روز تبدیل می‌شود
function leave_fmt_amount($hours, $day_hours) {
    $hours = max(0.0, (float)$hours);
    if ($hours < 0.0084) return '۰';
    $days = (int)floor(($hours + 1e-9) / $day_hours);
    $rem = $hours - $days * $day_hours;
    $parts = [];
    if ($days > 0) $parts[] = $days . ' ' . t('روز');
    if ($rem >= 0.0084) $parts[] = fmt_hm($rem) . ' ' . t('ساعت');
    return implode(' ' . t('و') . ' ', $parts);
}

$ls_summary = [];   // برای ماه انتخاب‌شده
$ls_hist = [];      // برای پنجرهٔ ماه‌ها
foreach ($ls_people as $__p) {
    $uid_ = (int)$__p['id'];
    $rows_ = $ls_rows[$uid_] ?? [];
    foreach ($ls_months as $mi => $m) {
        [$dd, $hh] = leave_month_usage($rows_, $ls_holidays, $m['start'], $m['end'], 'approved');
        [$pd, $ph] = leave_month_usage($rows_, $ls_holidays, $m['start'], $m['end'], 'pending');
        $used = $dd * $ls_day_hours + $hh;
        $left = max(0.0, $ls_quota_hours - $used);
        $over = max(0.0, $used - $ls_quota_hours);
        $rec = [
            'label' => $m['label'], 'days' => $dd, 'hours' => $hh, 'used' => $used, 'left' => $left, 'over' => $over,
            'pending' => $pd * $ls_day_hours + $ph,
            'pct' => $ls_quota_hours > 0 ? min(100, round($used / $ls_quota_hours * 100)) : ($used > 0 ? 100 : 0),
        ];
        if ($mi === 0) $ls_summary[$uid_] = $rec;
        if ($used <= 0 && $rec['pending'] <= 0) continue;   // ماه بدون مرخصی نمایش داده نمی‌شود
        $ls_hist[$uid_]['name'] = $__p['full_name'];
        $ls_hist[$uid_]['rows'][] = [
            'label' => $rec['label'],
            'days' => $dd > 0 ? (string)$dd : '—',
            'hours' => $hh > 0 ? fmt_hm($hh) : '—',
            'used' => $used > 0 ? leave_fmt_amount($used, $ls_day_hours) : '—',
            'left' => leave_fmt_amount($left, $ls_day_hours),
            'over' => $over > 0 ? leave_fmt_amount($over, $ls_day_hours) : '',
            'pending' => $rec['pending'] > 0 ? leave_fmt_amount($rec['pending'], $ls_day_hours) : '',
            'pct' => $rec['pct'],
        ];
    }
}
[$__py, $__pm] = $ls_shift($ls_jy, $ls_jm, -1);
[$__ny, $__nm] = $ls_shift($ls_jy, $ls_jm, 1);
$ls_prev_url = 'index.php?page=leaves&lm=' . $__py . '-' . $__pm;
$ls_next_url = 'index.php?page=leaves&lm=' . $__ny . '-' . $__nm;
$ls_is_current = ($ls_jy . '-' . $ls_jm) === $ls_cur_key;

include 'includes/header.php';
?>

<style>
.leave-type-tabs { display:flex; gap:4px; margin-bottom:16px; }
.leave-tab { padding:8px 20px; border:1px solid var(--border); border-radius:8px; background:var(--surface); cursor:pointer; font-size:13px; font-family:inherit; transition:all 0.2s; }
.leave-tab.active { background:var(--primary); color:white; border-color:var(--primary); }
.hourly-fields { display:none; }
.hourly-fields.show { display:block; }
/* خلاصهٔ مرخصی ماهانه */
.ls-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding:14px 16px; border-bottom:1px solid var(--border); }
.ls-title { font-size:15px; font-weight:700; margin:0; }
.ls-sub { font-size:11.5px; color:var(--text-secondary); margin-top:3px; }
.ls-nav { display:flex; align-items:center; gap:6px; }
.ls-nav a, .ls-nav .ls-cur { padding:6px 12px; border:1px solid var(--border); border-radius:8px; background:var(--surface); color:inherit; text-decoration:none; font-size:12.5px; }
.ls-nav .ls-cur { font-weight:700; background:var(--primary); color:#fff; border-color:var(--primary); min-width:110px; text-align:center; }
.ls-nav a:hover { border-color:var(--primary); color:var(--primary); }
.ls-table td, .ls-table th { text-align:center; white-space:nowrap; }
.ls-table td:first-child, .ls-table th:first-child { text-align:right; }
.ls-num { font-weight:700; }
.ls-muted { color:var(--text-secondary); font-size:11px; }
.ls-bar { width:110px; height:8px; border-radius:99px; background:rgba(148,163,184,.25); overflow:hidden; display:inline-block; vertical-align:middle; }
.ls-bar > i { display:block; height:100%; border-radius:99px; background:#22c55e; }
.ls-bar.warn > i { background:#f59e0b; }
.ls-bar.full > i { background:#ef4444; }
.ls-left { font-weight:700; color:#16a34a; }
.ls-left.zero { color:#dc2626; }
.ls-over { display:block; font-size:11px; color:#dc2626; font-weight:600; }
.ls-pending { display:block; font-size:11px; color:#b45309; }
.lv-month-head td { background:rgba(99,102,241,.08); cursor:pointer; padding:9px 14px; border-top:2px solid var(--border); user-select:none; }
.lv-month-head:hover td { background:rgba(99,102,241,.14); }
.lv-arrow { display:inline-block; transition:transform .15s; margin-left:4px; }
.lv-month-head.collapsed .lv-arrow { transform:rotate(90deg); }
.ls-note { padding:10px 16px; font-size:11.5px; color:var(--text-secondary); border-top:1px solid var(--border); line-height:1.9; }
</style>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_leaves') ?></h2>
    <div class="btn-group">
        <button onclick="openModal('requestModal')" class="btn btn-primary"><i data-lucide="plus"></i> <?= t('درخواست') ?> <?= t('nav_leaves') ?></button>
        <?php if ($is_admin): ?>
        <button onclick="openModal('directModal')" class="btn btn-success"><i data-lucide="calendar-plus"></i> <?= t('ثبت') ?> <?= t('nav_leaves') ?></button>
        <?php endif; ?>
    </div>
</div>

<!-- خلاصهٔ مرخصی ماهانه -->
<div class="card animate-in" style="margin-bottom:16px">
    <div class="ls-head">
        <div>
            <h3 class="ls-title">📊 <?= t('خلاصهٔ مرخصی ماهانه') ?><?= $can_view_all ? '' : ' — ' . htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h3>
            <div class="ls-sub"><?= t('سهمیهٔ هر ماه') ?>: <strong><?= leave_fmt_amount($ls_quota_hours, $ls_day_hours) ?></strong> (<?= rtrim(rtrim(number_format($ls_quota_days, 1), '0'), '.') ?> <?= t('روز') ?> × <?= rtrim(rtrim(number_format($ls_day_hours, 1), '0'), '.') ?> <?= t('ساعت') ?>) — <?= t('مرخصی روزانه و ساعتی از یک سهمیه کم می‌شوند') ?></div>
        </div>
        <div class="ls-nav">
            <a href="<?= htmlspecialchars($ls_prev_url, ENT_QUOTES, 'UTF-8') ?>" title="<?= t('ماه قبل') ?>">‹ <?= t('ماه قبل') ?></a>
            <span class="ls-cur"><?= htmlspecialchars($ls_months[0]['label'], ENT_QUOTES, 'UTF-8') ?></span>
            <a href="<?= htmlspecialchars($ls_next_url, ENT_QUOTES, 'UTF-8') ?>" title="<?= t('ماه بعد') ?>"><?= t('ماه بعد') ?> ›</a>
            <?php if (!$ls_is_current): ?><a href="index.php?page=leaves"><?= t('ماه جاری') ?></a><?php endif; ?>
        </div>
    </div>
    <div class="table-container">
        <table class="ls-table">
            <thead><tr>
                <th><?= t('پرسنل') ?></th>
                <th><?= t('مرخصی روزانه') ?></th>
                <th><?= t('مرخصی ساعتی') ?></th>
                <th><?= t('جمع مصرف‌شده') ?></th>
                <th><?= t('باقی‌مانده از سهمیه') ?></th>
                <th><?= t('میزان مصرف') ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php $ls_shown = 0;
            foreach ($ls_people as $__p): $r_ = $ls_summary[(int)$__p['id']] ?? null; if (!$r_ || ($r_['used'] <= 0 && $r_['pending'] <= 0)) continue; $ls_shown++; ?>
            <tr>
                <td><strong><?= htmlspecialchars($__p['full_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><span class="ls-num"><?= $r_['days'] > 0 ? $r_['days'] : '—' ?></span><?= $r_['days'] > 0 ? ' <span class="ls-muted">' . t('روز') . '</span>' : '' ?></td>
                <td><span class="ls-num"><?= $r_['hours'] > 0 ? fmt_hm($r_['hours']) : '—' ?></span><?= $r_['hours'] > 0 ? ' <span class="ls-muted">' . t('ساعت') . '</span>' : '' ?></td>
                <td>
                    <span class="ls-num"><?= $r_['used'] > 0 ? leave_fmt_amount($r_['used'], $ls_day_hours) : '—' ?></span>
                    <?php if ($r_['pending'] > 0): ?><span class="ls-pending">+ <?= leave_fmt_amount($r_['pending'], $ls_day_hours) ?> <?= t('در انتظار تأیید') ?></span><?php endif; ?>
                </td>
                <td>
                    <span class="ls-left <?= $r_['left'] <= 0 ? 'zero' : '' ?>"><?= leave_fmt_amount($r_['left'], $ls_day_hours) ?></span>
                    <?php if ($r_['over'] > 0): ?><span class="ls-over"><?= t('اضافه بر سهمیه') ?>: <?= leave_fmt_amount($r_['over'], $ls_day_hours) ?></span><?php endif; ?>
                </td>
                <td><span class="ls-bar <?= $r_['pct'] >= 100 ? 'full' : ($r_['pct'] >= 70 ? 'warn' : '') ?>" title="<?= (int)$r_['pct'] ?>٪"><i style="width:<?= (int)$r_['pct'] ?>%"></i></span> <span class="ls-muted"><?= (int)$r_['pct'] ?>٪</span></td>
                <td><button type="button" class="btn btn-ghost btn-sm" onclick="openLeaveHistory(<?= (int)$__p['id'] ?>)">📅 <?= t('ماه‌های اخیر') ?></button></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($ls_shown === 0): ?>
            <tr><td colspan="7" class="text-center text-muted" style="padding:24px"><?= t('در این ماه مرخصی‌ای ثبت نشده است') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="ls-note">ℹ️ <?= t('فقط مرخصی‌های «تأییدشده» حساب می‌شوند؛ جمعه‌ها و تعطیلات رسمی از مرخصی کم نمی‌شوند. مرخصی‌های در انتظار تأیید جدا نمایش داده می‌شوند.') ?></div>
</div>

<!-- پنجرهٔ تاریخچهٔ ماهانهٔ یک نفر -->
<div id="leaveHistoryModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:720px">
        <div class="modal-header"><h3 class="modal-title">📅 <?= t('مرخصی ماه‌های اخیر') ?> — <span id="lhName"></span></h3><button onclick="closeModal('leaveHistoryModal')" class="modal-close">✕</button></div>
        <div class="modal-body" style="overflow-x:auto">
            <table class="ls-table" style="width:100%">
                <thead><tr><th><?= t('ماه') ?></th><th><?= t('روزانه') ?></th><th><?= t('ساعتی') ?></th><th><?= t('جمع مصرف‌شده') ?></th><th><?= t('باقی‌مانده') ?></th></tr></thead>
                <tbody id="lhBody"></tbody>
            </table>
        </div>
    </div>
</div>
<script>
var LEAVE_HIST = <?= json_encode($ls_hist, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function toggleLvMonth(k) {
    var head = document.querySelector('.lv-month-head[data-m="' + k + '"]'); if (!head) return;
    var c = head.classList.toggle('collapsed');
    document.querySelectorAll('.lv-row[data-m="' + k + '"]').forEach(function (r) { r.style.display = c ? 'none' : ''; });
}
function openLeaveHistory(uid) {
    var d = LEAVE_HIST[uid]; if (!d) return;
    document.getElementById('lhName').textContent = d.name;
    var tb = document.getElementById('lhBody'); tb.innerHTML = '';
    d.rows.forEach(function (r) {
        var tr = document.createElement('tr');
        function td(txt, cls, extra) { var c = document.createElement('td'); c.textContent = txt; if (cls) c.className = cls; if (extra) { var s = document.createElement('span'); s.className = extra[0]; s.textContent = extra[1]; c.appendChild(s); } return c; }
        tr.appendChild(td(r.label));
        tr.appendChild(td(r.days));
        tr.appendChild(td(r.hours));
        tr.appendChild(td(r.used, 'ls-num', r.pending ? ['ls-pending', '+ ' + r.pending + ' <?= t('در انتظار تأیید') ?>'] : null));
        tr.appendChild(td(r.left, 'ls-left' + (r.left === '۰' ? ' zero' : ''), r.over ? ['ls-over', '<?= t('اضافه بر سهمیه') ?>: ' + r.over] : null));
        tb.appendChild(tr);
    });
    openModal('leaveHistoryModal');
}
</script>

<h3 style="margin:6px 0 10px;font-size:15px">🗒️ <?= t('فهرست درخواست‌های مرخصی') ?></h3>
<div class="card animate-in">
    <div class="table-container">
        <table>
            <thead><tr><th><?= t('wh_name') ?></th><th><?= t('sal_type') ?></th><th><?= t('lea_from') ?> <?= t('wh_date') ?></th><th><?= t('lea_to') ?> <?= t('wh_date') ?></th><th><?= t('ساعات') ?></th><th><?= t('adv_reason') ?></th><th><?= t('wh_status') ?></th><th><?= t('wh_actions') ?></th></tr></thead>
            <tbody>
                <?php
                // مرخصی‌ها به تفکیک ماه شمسیِ تاریخ شروع (جدیدترین ماه بالا)
                $lv_groups = [];
                foreach ($leaves as $__l) { $lv_groups[jalali_month_key(substr((string)$__l['start_date'], 0, 10))][] = $__l; }
                uksort($lv_groups, function ($a, $b) {
                    [$ay, $am] = array_map('intval', explode('-', $a)); [$by, $bm] = array_map('intval', explode('-', $b));
                    return $ay !== $by ? $by - $ay : $bm - $am;
                });
                foreach ($lv_groups as $lv_key => $lv_items):
                    usort($lv_items, function ($a, $b) { return strcmp((string)$b['start_date'], (string)$a['start_date']) ?: ((int)$b['id'] - (int)$a['id']); });
                    $lv_info = jalali_month_from_key($lv_key);
                    $lv_cnt = count($lv_items);
                    $lv_pend = 0; foreach ($lv_items as $__x) { if ($__x['status'] === 'pending') $lv_pend++; }
                ?>
                <tr class="lv-month-head" data-m="<?= htmlspecialchars($lv_key, ENT_QUOTES, 'UTF-8') ?>" onclick="toggleLvMonth('<?= htmlspecialchars($lv_key, ENT_QUOTES, 'UTF-8') ?>')">
                    <td colspan="8"><span class="lv-arrow">▾</span> <strong><?= htmlspecialchars($lv_info['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="ls-muted">— <?= $lv_cnt ?> <?= t('مرخصی') ?><?= $lv_pend ? ' • ' . $lv_pend . ' ' . t('در انتظار تأیید') : '' ?></span></td>
                </tr>
                <?php foreach ($lv_items as $l):
                    $lt = $l['leave_type'] ?? 'daily';
                    // §fix: داده‌ای که با دکمهٔ ویرایش به مودال پاس داده می‌شود (تاریخ‌ها شمسی)
                    $lj = [
                        'id' => (int)$l['id'],
                        'user_id' => (int)$l['user_id'],
                        'full_name' => (string)$l['full_name'],
                        'type' => $lt,
                        'sj' => to_jalali((string)$l['start_date']),
                        'ej' => to_jalali((string)$l['end_date']),
                        'st' => !empty($l['start_time']) ? substr($l['start_time'], 0, 5) : '',
                        'et' => !empty($l['end_time']) ? substr($l['end_time'], 0, 5) : '',
                        'reason' => (string)($l['reason'] ?? ''),
                        'status' => (string)$l['status'],
                    ];
                ?>
                <tr class="lv-row" data-m="<?= htmlspecialchars($lv_key, ENT_QUOTES, 'UTF-8') ?>">
                    <td><strong><?= $l['full_name'] ?></strong></td>
                    <td>
                        <?php if ($lt === 'hourly'): ?>
                            <span class="tag" style="background:#fef3c7;color:#92400e"><?= t('ساعتی') ?></span>
                        <?php else: ?>
                            <span class="tag tag-active"><?= t('sal_daily') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= to_jalali($l['start_date']) ?></td>
                    <td><?= ($lt === 'hourly' && $l['start_date'] === $l['end_date']) ? '-' : to_jalali($l['end_date']) ?></td>
                    <td>
                        <?php if ($lt === 'hourly' && $l['start_time'] && $l['end_time']): ?>
                            <?= substr($l['start_time'], 0, 5) ?> - <?= substr($l['end_time'], 0, 5) ?>
                            (<?= $l['hours'] ?> <?= t('ساعت') ?>)
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= $l['reason'] ?: '-' ?></td>
                    <td><span class="tag tag-<?= $l['status'] ?>"><?= $l['status'] == 'pending' ? t('adv_pending') : ($l['status'] == 'approved' ? t('تایید شد') : t('btn_reject')) ?></span></td>
                    <td>
                        <?php if ($is_admin && $l['status'] == 'pending'): ?>
                        <form method="POST" action="index.php?page=leaves" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="btn btn-success btn-sm"><?= t('btn_confirm') ?></button></form>
                        <button type="button" onclick="rejectLeave(<?= $l['id'] ?>)" class="btn btn-danger btn-sm"><?= t('btn_reject') ?></button>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                        <button type="button" data-leave="<?= htmlspecialchars(json_encode($lj, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="editLeaveFromBtn(this)" class="btn btn-ghost btn-sm" title="<?= htmlspecialchars(t('btn_edit'), ENT_QUOTES, 'UTF-8') ?>">✏️</button>
                        <button type="button" onclick="deleteLeave(<?= $l['id'] ?>)" class="btn btn-ghost btn-sm" title="<?= htmlspecialchars(t('btn_delete'), ENT_QUOTES, 'UTF-8') ?>" style="color:var(--danger)">🗑️</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                <?php if (empty($leaves)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:40px"><?= t('nav_leaves') ?> <?= t('ثبت نشده') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Request Modal -->
<div id="requestModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('درخواست') ?> <?= t('nav_leaves') ?></h3><button onclick="closeModal('requestModal')" class="modal-close">✕</button></div>
        <form method="POST" action="index.php?page=leaves"><?= get_csrf_field() ?><input type="hidden" name="action" value="request">
            <div class="modal-body">
                <?php if ($has_hourly): ?>
                <div class="leave-type-tabs">
                    <button type="button" class="leave-tab active" onclick="setLeaveType('request', 'daily')"><?= t('sal_daily') ?></button>
                    <button type="button" class="leave-tab" onclick="setLeaveType('request', 'hourly')"><?= t('ساعتی') ?></button>
                </div>
                <input type="hidden" name="leave_type" id="request_leave_type" value="daily">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_date') ?> *</label><input type="text" name="start_date" class="form-control jalali-date" required placeholder="1405/07/01" id="request_start_date"></div>
                    <div class="form-group" id="request_end_date_group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_date') ?> *</label><input type="text" name="end_date" class="form-control jalali-date" id="request_end_date" placeholder="1405/07/02"></div>
                </div>

                <?php if ($has_hourly): ?>
                <div class="hourly-fields" id="request_hourly_fields">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_hours') ?> *</label><input type="time" name="start_time" class="form-control"></div>
                        <div class="form-group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_hours') ?> *</label><input type="time" name="end_time" class="form-control"></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group"><label class="form-label"><?= t('adv_reason') ?></label><textarea name="reason" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_submit') ?></button></div>
        </form>
    </div>
</div>

<!-- Direct Register Modal -->
<div id="directModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('ثبت') ?> <?= t('nav_leaves') ?></h3><button onclick="closeModal('directModal')" class="modal-close">✕</button></div>
        <form method="POST" action="index.php?page=leaves"><?= get_csrf_field() ?><input type="hidden" name="action" value="direct_register">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('wh_name') ?> <?= t('نیرو') ?> *</label>
                    <select name="user_id" class="form-control" required>
                        <option value=""><?= t('انتخاب کنید') ?></option>
                        <?php foreach ($personnel as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= $p['full_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($has_hourly): ?>
                <div class="leave-type-tabs">
                    <button type="button" class="leave-tab active" onclick="setLeaveType('direct', 'daily')"><?= t('sal_daily') ?></button>
                    <button type="button" class="leave-tab" onclick="setLeaveType('direct', 'hourly')"><?= t('ساعتی') ?></button>
                </div>
                <input type="hidden" name="leave_type" id="direct_leave_type" value="daily">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_date') ?> *</label><input type="text" name="start_date" class="form-control jalali-date" required placeholder="1405/07/01" id="direct_start_date"></div>
                    <div class="form-group" id="direct_end_date_group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_date') ?> *</label><input type="text" name="end_date" class="form-control jalali-date" id="direct_end_date" placeholder="1405/07/02"></div>
                </div>

                <?php if ($has_hourly): ?>
                <div class="hourly-fields" id="direct_hourly_fields">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_hours') ?> *</label><input type="time" name="start_time" class="form-control"></div>
                        <div class="form-group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_hours') ?> *</label><input type="time" name="end_time" class="form-control"></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group"><label class="form-label"><?= t('adv_reason') ?></label><textarea name="reason" class="form-control" rows="2" placeholder="<?= t('اختیاری') ?>"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success"><?= t('ثبت') ?> <?= t('و') ?> <?= t('btn_confirm') ?></button></div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_reject') ?> <?= t('nav_leaves') ?></h3><button onclick="closeModal('rejectModal')" class="modal-close">✕</button></div>
        <form method="POST" action="index.php?page=leaves"><?= get_csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" id="reject_id">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('adv_reason') ?> <?= t('btn_reject') ?></label><textarea name="rejection_reason" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-danger"><?= t('btn_reject') ?></button></div>
        </form>
    </div>
</div>

<!-- §fix: Edit Modal — ویرایش رکورد مرخصی (حتی تأییدشده) توسط مدیر -->
<?php if ($is_admin): ?>
<div id="editLeaveModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_edit') ?> <?= t('nav_leaves') ?></h3><button type="button" onclick="closeModal('editLeaveModal')" class="modal-close">✕</button></div>
        <form method="POST" action="index.php?page=leaves" onsubmit="return validateEditLeaveForm(this)">
            <?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_leave_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= t('wh_name') ?> <?= t('نیرو') ?> *</label>
                    <select name="user_id" id="edit_leave_user" class="form-control" required>
                        <option value=""><?= t('انتخاب کنید') ?></option>
                        <?php foreach ($personnel as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= $p['full_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($has_hourly): ?>
                <div class="leave-type-tabs">
                    <button type="button" class="leave-tab active" onclick="setLeaveType('edit_leave', 'daily')"><?= t('sal_daily') ?></button>
                    <button type="button" class="leave-tab" onclick="setLeaveType('edit_leave', 'hourly')"><?= t('ساعتی') ?></button>
                </div>
                <input type="hidden" name="leave_type" id="edit_leave_leave_type" value="daily">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_date') ?> *</label><input type="text" name="start_date" id="edit_leave_start_date" class="form-control jalali-date" required placeholder="1405/07/01" oninput="if(document.getElementById('edit_leave_leave_type') && document.getElementById('edit_leave_leave_type').value==='hourly') document.getElementById('edit_leave_end_date').value=this.value;"></div>
                    <div class="form-group" id="edit_leave_end_date_group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_date') ?> *</label><input type="text" name="end_date" id="edit_leave_end_date" class="form-control jalali-date" placeholder="1405/07/02"></div>
                </div>

                <?php if ($has_hourly): ?>
                <div class="hourly-fields" id="edit_leave_hourly_fields">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_hours') ?> *</label><input type="time" name="start_time" id="edit_leave_start_time" class="form-control"></div>
                        <div class="form-group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_hours') ?> *</label><input type="time" name="end_time" id="edit_leave_end_time" class="form-control"></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label"><?= t('wh_status') ?></label>
                    <select name="status" id="edit_leave_status" class="form-control">
                        <option value="approved"><?= t('تایید شد') ?></option>
                        <option value="pending"><?= t('adv_pending') ?></option>
                        <option value="rejected"><?= t('btn_reject') ?></option>
                    </select>
                </div>

                <div class="form-group"><label class="form-label"><?= t('adv_reason') ?></label><textarea name="reason" id="edit_leave_reason" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function rejectLeave(id){document.getElementById('reject_id').value=id;openModal('rejectModal');}

<?php if ($is_admin): ?>
function editLeaveFromBtn(btn) {
    try {
        var raw = btn.getAttribute('data-leave');
        if (!raw) return;
        var l = JSON.parse(raw);
        editLeave(l);
    } catch(e) {
        console.error('editLeaveFromBtn error:', e);
    }
}

// §fix: باز کردن مودال ویرایش با داده‌های رکورد (تاریخ‌ها شمسی‌اند — همون فرمتی که فرم قبول می‌کند)
function editLeave(l) {
    if (!l) return;
    var idInput = document.getElementById('edit_leave_id');
    if (idInput) idInput.value = l.id || '';
    var sel = document.getElementById('edit_leave_user');
    if (sel) {
        var found = false;
        for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].value == l.user_id) { found = true; break; } }
        if (!found && l.user_id) {
            // نیرویی که دیگر در لیست پرسنل فعال نیست — دستی اضافه شود تا مقدار گم نشود
            var o = document.createElement('option');
            o.value = l.user_id; o.textContent = l.full_name || ('#' + l.user_id);
            sel.appendChild(o);
        }
        sel.value = l.user_id;
    }
    setLeaveType('edit_leave', (l.type === 'hourly') ? 'hourly' : 'daily');
    var sd = document.getElementById('edit_leave_start_date');
    if (sd) sd.value = l.sj || '';
    var ed = document.getElementById('edit_leave_end_date');
    if (ed) ed.value = (l.type === 'hourly') ? (l.sj || '') : (l.ej || '');
    var st = document.getElementById('edit_leave_start_time'), et = document.getElementById('edit_leave_end_time');
    if (st) st.value = l.st || '';
    if (et) et.value = l.et || '';
    var rz = document.getElementById('edit_leave_reason');
    if (rz) rz.value = l.reason || '';
    var stat = document.getElementById('edit_leave_status');
    if (stat) stat.value = l.status || 'pending';
    openModal('editLeaveModal');
}

function validateEditLeaveForm(form) {
    var typeInput = document.getElementById('edit_leave_leave_type');
    var type = typeInput ? typeInput.value : 'daily';
    if (type === 'hourly') {
        var st = document.getElementById('edit_leave_start_time');
        var et = document.getElementById('edit_leave_end_time');
        if (!st || !st.value || !et || !et.value) {
            alert('لطفاً ساعت شروع و پایان مرخصی ساعتی را مشخص کنید.');
            return false;
        }
        if (st.value === et.value) {
            alert('ساعت شروع و پایان نمی‌تواند برابر باشد.');
            return false;
        }
    }
    return true;
}

// §fix: حذف کامل رکورد — با فرم POST و توکن CSRF (مثل حذف کارگاه)
async function deleteLeave(id) {
    if (await confirmAction('<?= t('این') ?> <?= t('رکورد') ?> <?= t('مرخصی') ?> <?= t('برای') ?> <?= t('همیشه') ?> <?= t('حذف شود؟') ?>')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php?page=leaves';
        form.innerHTML = `<?= get_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}
<?php endif; ?>

function setLeaveType(prefix, type) {
    var typeInput = document.getElementById(prefix + '_leave_type');
    if (typeInput) typeInput.value = type;

    var modal = document.getElementById(prefix + 'Modal') || 
                (prefix === 'edit_leave' ? document.getElementById('editLeaveModal') : null) || 
                document.getElementById('edit_leaveModal');
    var tabs = modal ? modal.querySelectorAll('.leave-tab') : document.querySelectorAll('#' + prefix + 'Modal .leave-tab');
    if (tabs && tabs.length > 0) {
        tabs.forEach(function(t) { t.classList.remove('active'); });
    }

    var endDate = document.getElementById(prefix + '_end_date');
    var endDateGroup = document.getElementById(prefix + '_end_date_group');
    var hourlyFields = document.getElementById(prefix + '_hourly_fields');

    if (type === 'hourly') {
        if (tabs && tabs.length > 1) tabs[1].classList.add('active');
        if (hourlyFields) hourlyFields.classList.add('show');
        if (endDateGroup) endDateGroup.style.display = 'none';
        if (endDate) {
            endDate.removeAttribute('required');
            var sd = document.getElementById(prefix + '_start_date');
            if (sd && sd.value) endDate.value = sd.value;
        }
    } else {
        if (tabs && tabs.length > 0) tabs[0].classList.add('active');
        if (hourlyFields) hourlyFields.classList.remove('show');
        if (endDateGroup) endDateGroup.style.display = '';
        if (endDate) endDate.setAttribute('required', 'required');
    }
}
</script>
<?php include 'includes/footer.php'; ?>
