<?php
$pageTitle = t('nav_work_hours');
$ws_id = $_SESSION['workshop_id'] ?? 0;
// هم پرمیشن قدیمی و هم دسترسی صفحه‌ای جدید صریحاً پشتیبانی می‌شوند؛ بنابراین
// ویراستاری که ورود را ندیده، می‌تواند همان رکورد را ببیند/ویرایش/خروج بزند.
$work_hours_mode = access_mode('work_hours');
$can_manage = is_strict_admin() || is_workshop_manager()
    || in_array('manage_work_hours', $_SESSION['permissions'] ?? [], true)
    || in_array('office_manager', $_SESSION['permissions'] ?? [], true)
    || $work_hours_mode === 'editor'
    || has_permission('manage_work_hours') || has_permission('office_manager');

// هشدارهای حضور زیرمجموعهٔ ساعت کاری است و دیگر صفحهٔ جداگانه‌ای در منو ندارد.
if (($_GET['tab'] ?? '') === 'alerts') {
    include __DIR__ . '/attendance_alerts.php';
    exit;
}

// ═══ §v17: دسترسی موقت شیفت — کاربر عادی با مجوز فعال، فقط در همین صفحه و فقط برای همان تاریخ/شیفت مثل مدیر عمل می‌کند ═══
// بقیهٔ سایت برایش مثل همیشه است. بدون مجوز فعال، فقط ساعت خودش را می‌بیند.
$temp_grant = null; $tg_date = ''; $tg_shift = 'day';
if (!$can_manage && !is_strict_admin() && is_logged_in()) {
    $temp_grant = temp_grant_active($pdo, (int)$_SESSION['user_id'], 0);
    if ($temp_grant) {
        $ws_id = (int)$temp_grant['workshop_id'];
        $tg_date = (string)$temp_grant['grant_date'];
        $tg_shift = (string)($temp_grant['shift_type'] ?? 'day');
        $can_manage = true;
        $_GET['date'] = to_jalali($tg_date); // فقط همان روز
    }
}
$tg_deny = function (string $msg) {
    showToast($msg, 'error');
    redirect('index.php?page=work_hours');
};
if ($temp_grant && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tg_action = $_POST['action'] ?? '';
    if (!in_array($tg_action, ['checkin', 'checkin_single', 'checkout', 'edit', 'bulk_checkout', 'bulk_edit', 'protest'], true)) {
        $tg_deny('این عملیات با دسترسی موقت مجاز نیست');
    }
    // مأموریت با دسترسی موقت ممکن نیست
    unset($_POST['mission_user'], $_POST['is_mission']); $_POST['mission_ws'] = 0;
    // تاریخ ثبت فقط همان روز
    if (in_array($tg_action, ['checkin', 'checkin_single'], true)) {
        if (to_gregorian($_POST['work_date'] ?? '') !== $tg_date) $tg_deny('با این دسترسی فقط برای تاریخ همان شیفت می‌توانید ثبت کنید');
        $tg_st = trim((string)($_POST['start_time'] ?? '')) ?: date('H:i');
        if (!temp_grant_row_in_shift(['start_time' => $tg_st . ':00', 'end_time' => '00:00:00', 'is_night' => 0], $tg_shift)) {
            $tg_deny('ساعت ورود خارج از ' . ($tg_shift === 'night' ? 'شیفت شب' : 'شیفت روز') . ' است');
        }
        // فقط نیروهای همین کارگاه
        $tg_ids = $tg_action === 'checkin' ? (array)($_POST['selected_users'] ?? []) : [(int)($_POST['user_id'] ?? 0)];
        $tg_chk = $pdo->prepare("SELECT 1 FROM workshop_personnel WHERE user_id=? AND workshop_id=? AND status='active'");
        foreach ($tg_ids as $tg_u) { $tg_chk->execute([(int)$tg_u, $ws_id]); if (!$tg_chk->fetchColumn()) $tg_deny('نیروی انتخاب‌شده عضو این کارگاه نیست'); }
    }
    // ویرایش/خروج: فقط رکوردهای همان کارگاه + همان روز + همان شیفت
    if (in_array($tg_action, ['checkout', 'edit', 'bulk_checkout', 'bulk_edit'], true)) {
        $tg_rec = in_array($tg_action, ['checkout', 'edit'], true) ? [(int)($_POST['id'] ?? 0)] : array_map('intval', (array)($_POST['ids'] ?? []));
        $tg_q = $pdo->prepare("SELECT start_time, end_time, is_night FROM work_hours WHERE id=? AND workshop_id=? AND work_date=?");
        foreach ($tg_rec as $tg_i) {
            $tg_q->execute([$tg_i, $ws_id, $tg_date]);
            $tg_r = $tg_q->fetch();
            if (!$tg_r || !temp_grant_row_in_shift($tg_r, $tg_shift)) $tg_deny('این رکورد خارج از محدودهٔ شیفت شماست');
        }
        if (in_array($tg_action, ['edit', 'bulk_edit'], true)) {
            $tg_new = ['start_time' => (string)($_POST['start_time'] ?? ''), 'end_time' => (string)($_POST['end_time'] ?? '') ?: '00:00:00', 'is_night' => 0];
            if (!preg_match('/^\d{2}:\d{2}/', $tg_new['start_time']) || !temp_grant_row_in_shift($tg_new, $tg_shift)) $tg_deny('ساعت جدید خارج از محدودهٔ شیفت است');
        }
    }
}

// §v11: تیک «ناظر/ویراستار» روی ساعت کاری = دیدن ساعت همه (ویراستار علاوه بر آن مدیریت هم دارد)
$can_see_others = $can_manage || can_see_others_hours() || in_array($work_hours_mode, ['viewer', 'editor'], true);
$is_own_view = !$can_see_others;

// ═══ مأموریت بین کارگاه‌ها (§3): نام کارگاه‌ها + هلپر همگام‌سازی وضعیت مأموریت با رکورد ساعت ═══
$ws_name_map = [];
try {
    foreach ($pdo->query("SELECT id, name FROM workshops WHERE status = 'active'")->fetchAll() as $w) {
        $ws_name_map[(int)$w['id']] = $w['name'];
    }
} catch (Throwable $e) {}
// با خروج/حذفِ رکورد ساعتِ متصل به مأموریت، خود مأموریت هم بسته/لغو شود
if (!function_exists('wh_sync_mission')) {
    function wh_sync_mission(PDO $pdo, int $wh_id, string $mode, ?string $end_time = null): void {
        $st = $pdo->prepare("SELECT mission_id, work_date FROM work_hours WHERE id = ?");
        $st->execute([$wh_id]);
        $r = $st->fetch();
        if (!$r || empty($r['mission_id'])) return;
        if ($mode === 'close') {
            $pdo->prepare("UPDATE missions SET status = 'completed', end_date = COALESCE(end_date, ?), end_time = COALESCE(end_time, ?), completed_by = ? WHERE id = ? AND status = 'active'")
                ->execute([$r['work_date'], $end_time ?: date('H:i'), $_SESSION['user_id'], $r['mission_id']]);
        } elseif ($mode === 'cancel') {
            $pdo->prepare("UPDATE missions SET status = 'cancelled', completed_by = ? WHERE id = ? AND status = 'active'")
                ->execute([$_SESSION['user_id'], $r['mission_id']]);
        }
    }
}

// §1: بررسی دسترسی کاربر به مدیریت ساعت کاری یک کارگاه خاص
if (!function_exists('can_user_manage_ws_work_hours')) {
    function can_user_manage_ws_work_hours(PDO $pdo, int $user_id, int $target_ws): bool {
        if ($user_id <= 0 || $target_ws <= 0) return false;
        if (is_strict_admin() || is_project_manager()) return true;

        // ۱. بررسی دسترسی صریح مدیریت ساعت در کارگاه هدف
        $st = $pdo->prepare("SELECT 1 FROM user_permissions WHERE user_id = ? AND workshop_id = ? AND permission IN ('manage_work_hours', 'office_manager', 'workshop_manager')");
        $st->execute([$user_id, $target_ws]);
        if ($st->fetchColumn()) return true;

        // ۲. بررسی عضویت و نقش فعال در کارگاه هدف
        $st_wp = $pdo->prepare("SELECT 1 FROM workshop_personnel WHERE user_id = ? AND workshop_id = ? AND status = 'active'");
        $st_wp->execute([$user_id, $target_ws]);
        if ($st_wp->fetchColumn()) {
            $st_u = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $st_u->execute([$user_id]);
            $u_role = $st_u->fetchColumn();
            if (in_array($u_role, ['manager', 'workshop_manager', 'مدیر کارگاه'], true)) return true;
        }

        return false;
    }
}

// §1: بررسی مجوز تغییر یا حذف ساعت نیروی مأمور از کارگاه دیگر
if (!function_exists('can_user_edit_wh_record')) {
    function can_user_edit_wh_record(PDO $pdo, int $wh_id): bool {
        if (is_strict_admin() || is_project_manager()) return true;
        $st = $pdo->prepare("SELECT wh.workshop_id, wh.mission_id, wh.mission_source_ws, m.source_workshop_id 
                             FROM work_hours wh 
                             LEFT JOIN missions m ON m.id = wh.mission_id 
                             WHERE wh.id = ?");
        $st->execute([$wh_id]);
        $row = $st->fetch();
        if (!$row) return false;

        $source_ws = (int)($row['mission_source_ws'] ?: ($row['source_workshop_id'] ?? 0));
        // اگر این رکورد مأموریت از کارگاه دیگر نیست، دسترسی عادی کاربر به کارگاه مقصد معتبر است
        if ($source_ws <= 0 || $source_ws === (int)$row['workshop_id']) {
            return true;
        }

        // اگر رکورد مأموریت از کارگاه دیگر است: فقط کاربران دارای دسترسی در کارگاه مبدأ مجاز به ویرایش هستند
        $uid = (int)($_SESSION['user_id'] ?? 0);
        return can_user_manage_ws_work_hours($pdo, $uid, $source_ws);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // حفظ فیلترهای فعلی (نوع نیرو / شیفت) بعد از ریدایرکت
    $fqs = '';
    if (!empty($_GET['type'])) $fqs .= '&type=' . urlencode($_GET['type']);
    if (!empty($_GET['shift'])) $fqs .= '&shift=' . urlencode($_GET['shift']);

    // ── §v16: دسترسی موقت ثبت ساعت (کیوسک شیفت) — فقط ادمین ──
    if (in_array($action, ['grant_create', 'grant_revoke'], true) && is_strict_admin()) {
        temp_grants_ensure_table($pdo);
        if ($action === 'grant_create') {
            $gu = (int)($_POST['grant_user'] ?? 0);
            $gd = to_gregorian($_POST['grant_date'] ?? '');
            $gs = (($_POST['grant_shift'] ?? 'day') === 'night') ? 'night' : 'day';
            // دریافت‌کننده حتماً باید نیروی فعال همین کارگاه باشد.
            $validUser = $pdo->prepare("SELECT 1 FROM users u JOIN workshop_personnel wp ON wp.user_id=u.id WHERE u.id=? AND u.status='active' AND wp.workshop_id=? AND wp.status='active'");
            $validUser->execute([$gu, $ws_id]);
            if ($gu > 0 && $gd && $validUser->fetchColumn()) {
                $pdo->prepare("INSERT INTO temp_entry_grants (user_id, workshop_id, grant_date, shift_type, created_by) VALUES (?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE revoked_at=NULL, created_by=VALUES(created_by), created_at=CURRENT_TIMESTAMP")
                     ->execute([$gu, $ws_id, $gd, $gs, $_SESSION['user_id']]);
                showToast('دسترسی موقت «' . ($gs === 'night' ? 'شیفت شب' : 'شیفت روز') . '» داده شد؛ دسترسی فقط به همان تاریخ و همان شیفت محدود است.');
            } else {
                showToast('یک نفر فعال و تاریخ معتبر انتخاب کنید', 'error');
            }
        } else {
            $pdo->prepare("UPDATE temp_entry_grants SET revoked_at=NOW() WHERE id=? AND workshop_id=?")->execute([(int)$_POST['grant_id'], $ws_id]);
            showToast('دسترسی لغو شد');
        }
        $back = $action === 'grant_create' ? ($gd ? to_jalali($gd) : '') : (string)($_POST['back_date'] ?? '');
        redirect("index.php?page=work_hours" . ($back !== '' ? '&date=' . urlencode($back) : '') . $fqs);
    }

    // ── Check-in: admin picks a start time, all active personnel get an open entry ──
    if ($action === 'checkin' && $can_manage) {
        $start_time = $_POST['start_time'] ?: date('H:i');
        $work_date = to_gregorian($_POST['work_date'] ?? jalali_now());
        $note = sanitize($_POST['note'] ?? '');
        $selected_users = $_POST['selected_users'] ?? [];
        // §3.2: تیک مأموریت به ازای هر نفر + کارگاه مقصد مشترک
        $mission_users = array_map('intval', $_POST['mission_user'] ?? []);
        $mission_ws = (int)($_POST['mission_ws'] ?? 0);
        if ($mission_ws <= 0 || $mission_ws === (int)$ws_id) $mission_users = [];
        $target_name = $ws_name_map[$mission_ws] ?? '';
        $source_name = $ws_name_map[$ws_id] ?? '';

        // Get previous day for night adjustment
        $prev_date_g = date('Y-m-d', strtotime($work_date . ' -1 day'));

        $inserted = 0; $missioned = 0;
        foreach ($selected_users as $pid) {
            $pid = (int)$pid;
            // ── مأموریت: رکورد در کارگاه مقصد ثبت می‌شود، نفر در کارگاه اصلی می‌ماند ──
            if (in_array($pid, $mission_users, true)) {
                $chk = $pdo->prepare("SELECT id FROM work_hours WHERE user_id = ? AND workshop_id = ? AND work_date = ? AND end_time = '00:00:00'");
                $chk->execute([$pid, $mission_ws, $work_date]);
                if ($chk->fetch()) continue;
                $notes = trim(('مأموریت از کارگاه «' . $source_name . '»') . ($note !== '' ? ' — ' . $note : ''));
                $pdo->prepare("INSERT INTO missions (user_id, source_workshop_id, target_workshop_id, start_date, start_time, status, description, created_by) VALUES (?, ?, ?, ?, ?, 'active', ?, ?)")
                    ->execute([$pid, $ws_id, $mission_ws, $work_date, $start_time, $notes, $_SESSION['user_id']]);
                $mid = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare("INSERT INTO work_hours (user_id, workshop_id, work_date, start_time, end_time, is_night, note, created_by, mission_id, mission_source_ws) VALUES (?, ?, ?, ?, '00:00:00', 0, ?, ?, ?, ?)");
                $stmt->execute([$pid, $mission_ws, $work_date, $start_time, $notes, $_SESSION['user_id'], $mid, $ws_id]);
                arash_coin_sync_work_hour_rewards($pdo, (int)$pdo->lastInsertId(), (int)$_SESSION['user_id']);
                $missioned++;
                continue;
            }
            // Skip if already has open entry
            $chk = $pdo->prepare("SELECT id FROM work_hours WHERE user_id = ? AND workshop_id = ? AND work_date = ? AND end_time = '00:00:00'");
            $chk->execute([$pid, $ws_id, $work_date]);
            if ($chk->fetch()) continue;

            $stmt = $pdo->prepare("INSERT INTO work_hours (user_id, workshop_id, work_date, start_time, end_time, is_night, note, created_by) VALUES (?, ?, ?, ?, '00:00:00', 0, ?, ?)");
            $stmt->execute([$pid, $ws_id, $work_date, $start_time, $note, $_SESSION['user_id']]);
            arash_coin_sync_work_hour_rewards($pdo, (int)$pdo->lastInsertId(), (int)$_SESSION['user_id']);
            $inserted++;
        }
        $msg = strtr(t('{count} نفر ورود ثبت شد'), ['{count}' => $inserted]);
        if ($missioned) $msg .= strtr(t(' + 🚀 {count} نفر مأموریت به کارگاه «{workshop}»'), ['{count}' => $missioned, '{workshop}' => $target_name]);
        showToast($msg);
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['work_date'] ?? jalali_now()) . $fqs);
    }


    // ── ثبت چندروزهٔ ساعت کاری یک نفر: هر روز با ساعت ورود/خروج مستقل ──
    if ($action === 'multi_day_entry' && $can_manage) {
        $batch_user_id = (int)($_POST['user_id'] ?? 0);
        $work_dates = $_POST['work_dates'] ?? [];
        $chosen_indexes = array_flip(array_map('intval', $_POST['use_dates'] ?? []));
        $start_times = $_POST['start_times'] ?? [];
        $end_times = $_POST['end_times'] ?? [];
        $batch_note = sanitize($_POST['note'] ?? 'ثبت چندروزه ساعت کاری');
        $inserted = 0; $updated = 0; $skipped = 0;

        if ($batch_user_id <= 0 || empty($chosen_indexes)) {
            showToast(t('یک نیرو و حداقل یک روز را انتخاب کنید.'), 'error');
            redirect("index.php?page=work_hours&date=" . urlencode($_GET['date'] ?? jalali_now()) . $fqs);
        }

        try {
            $find = $pdo->prepare("SELECT id, mission_id FROM work_hours WHERE user_id = ? AND workshop_id = ? AND work_date = ? ORDER BY id");
            $insert = $pdo->prepare("INSERT INTO work_hours (user_id, workshop_id, work_date, start_time, end_time, is_night, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $update = $pdo->prepare("UPDATE work_hours SET start_time = ?, end_time = ?, is_night = ?, note = ? WHERE id = ? AND workshop_id = ?");
            foreach ($chosen_indexes as $idx => $_selected) {
                $jdate = $work_dates[$idx] ?? '';
                $gdate = to_gregorian($jdate);
                $start_time = trim((string)($start_times[$idx] ?? ''));
                $end_time = trim((string)($end_times[$idx] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$gdate)
                    || !preg_match('/^\d{2}:\d{2}$/', $start_time)
                    || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
                    $skipped++;
                    continue;
                }
                $is_night = (strtotime($start_time) >= strtotime('21:00') || strtotime($end_time) >= strtotime('21:00') || strtotime($end_time) < strtotime($start_time)) ? 1 : 0;
                $find->execute([$batch_user_id, $ws_id, $gdate]);
                $existing = $find->fetchAll();
                if (empty($existing)) {
                    $insert->execute([$batch_user_id, $ws_id, $gdate, $start_time, $end_time, $is_night, $batch_note, $_SESSION['user_id']]);
                    arash_coin_sync_work_hour_rewards($pdo, (int)$pdo->lastInsertId(), (int)$_SESSION['user_id']);
                    $inserted++;
                } elseif (count($existing) === 1 && empty($existing[0]['mission_id'])) {
                    // برای جلوگیری از رکورد تکراری، تنها رکورد عادی همان روز اصلاح می‌شود.
                    $update->execute([$start_time, $end_time, $is_night, $batch_note, $existing[0]['id'], $ws_id]);
                    arash_coin_sync_work_hour_rewards($pdo, (int)$existing[0]['id'], (int)$_SESSION['user_id']);
                    $updated++;
                } else {
                    // روزی که چند شیفت یا مأموریت دارد نباید بی‌صدا بازنویسی شود.
                    $skipped++;
                }
            }
            $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'multi_day_work_hours', 'work_hours', ?, ?)")
                ->execute([$_SESSION['user_id'], $batch_user_id, "ثبت چندروزه: {$inserted} جدید، {$updated} ویرایش، {$skipped} ردشده"]);
            $message = strtr(t('ثبت چندروزه انجام شد: {inserted} روز جدید، {updated} روز ویرایش، {skipped} روز رد شد (رکورد چندشیفت/مأموریت یا ساعت نامعتبر)'), ['{inserted}' => $inserted, '{updated}' => $updated, '{skipped}' => $skipped]);
            showToast($message, $skipped ? 'warning' : 'success');
        } catch (Throwable $e) {
            showToast(t('خطا در ثبت چندروزه: ') . $e->getMessage(), 'error');
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── Single Check-in ──
    if ($action === 'checkin_single' && $can_manage) {
        $user_id_check = (int)$_POST['user_id'];
        $start_time = $_POST['start_time'] ?: date('H:i');
        $work_date = to_gregorian($_POST['work_date'] ?? jalali_now());
        $note = sanitize($_POST['note'] ?? '');
        // §3.1: تیک مأموریت — رکورد برای کارگاه مقصد ساخته می‌شود، نفر در کارگاه اصلی می‌ماند
        $is_mission = !empty($_POST['is_mission']);
        $mission_ws = (int)($_POST['mission_ws'] ?? 0);
        if ($mission_ws <= 0 || $mission_ws === (int)$ws_id) $is_mission = false;
        $target_ws = $is_mission ? $mission_ws : $ws_id;
        $target_name = $ws_name_map[$mission_ws] ?? '';
        $source_name = $ws_name_map[$ws_id] ?? '';

        // Check for open entry
        $chk = $pdo->prepare("SELECT id FROM work_hours WHERE user_id = ? AND workshop_id = ? AND work_date = ? AND end_time = '00:00:00'");
        $chk->execute([$user_id_check, $target_ws, $work_date]);
        if ($chk->fetch()) {
            showToast(t('این نیرو قبلاً ورود باز دارد'), 'error');
        } else {
            if ($is_mission) {
                $notes = trim(('مأموریت از کارگاه «' . $source_name . '»') . ($note !== '' ? ' — ' . $note : ''));
                $pdo->prepare("INSERT INTO missions (user_id, source_workshop_id, target_workshop_id, start_date, start_time, status, description, created_by) VALUES (?, ?, ?, ?, ?, 'active', ?, ?)")
                    ->execute([$user_id_check, $ws_id, $mission_ws, $work_date, $start_time, $notes, $_SESSION['user_id']]);
                $mid = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare("INSERT INTO work_hours (user_id, workshop_id, work_date, start_time, end_time, is_night, note, created_by, mission_id, mission_source_ws) VALUES (?, ?, ?, ?, '00:00:00', 0, ?, ?, ?, ?)");
                $stmt->execute([$user_id_check, $mission_ws, $work_date, $start_time, $notes, $_SESSION['user_id'], $mid, $ws_id]);
                arash_coin_sync_work_hour_rewards($pdo, (int)$pdo->lastInsertId(), (int)$_SESSION['user_id']);
                showToast(strtr(t('🚀 مأموریت به کارگاه «{workshop}» ثبت شد — ساعت در کارگاه مقصد حساب می‌شود'), ['{workshop}' => $target_name]));
            } else {
                $stmt = $pdo->prepare("INSERT INTO work_hours (user_id, workshop_id, work_date, start_time, end_time, is_night, note, created_by) VALUES (?, ?, ?, ?, '00:00:00', 0, ?, ?)");
                $stmt->execute([$user_id_check, $ws_id, $work_date, $start_time, $note, $_SESSION['user_id']]);
                arash_coin_sync_work_hour_rewards($pdo, (int)$pdo->lastInsertId(), (int)$_SESSION['user_id']);
                showToast(t('ورود ثبت شد'));
            }
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['work_date'] ?? jalali_now()) . $fqs);
    }

    // ── Checkout: close an open entry (end_time = 00:00 → now) ──
    if ($action === 'checkout' && $can_manage) {
        $id = (int)$_POST['id'];
        if (!can_user_edit_wh_record($pdo, $id)) {
            showToast(t('تغییر ساعت نیروی مأمور فقط توسط مدیر پروژه یا کارگاه مبدأ امکان‌پذیر است'), 'error');
            redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
            exit;
        }
        $end_time = $_POST['end_time'] ?: date('H:i');

        $stmt = $pdo->prepare("UPDATE work_hours SET end_time = ? WHERE id = ? AND workshop_id = ? AND end_time = '00:00:00'");
        $stmt->execute([$end_time, $id, $ws_id]);

        // Recalculate night shift
        $row = $pdo->prepare("SELECT start_time, end_time FROM work_hours WHERE id = ?");
        $row->execute([$id]);
        $wh = $row->fetch();
        if ($wh) {
            $is_night = (strtotime($wh['start_time']) >= strtotime('21:00:00') || strtotime($wh['end_time']) >= strtotime('21:00:00') || strtotime($wh['end_time']) < strtotime($wh['start_time'])) ? 1 : 0;
            $pdo->prepare("UPDATE work_hours SET is_night = ? WHERE id = ?")->execute([$is_night, $id]);
        }
        wh_sync_mission($pdo, $id, 'close', $end_time); // §3.5: با ثبت خروج، مأموریت هم پایان می‌یابد
        arash_coin_sync_work_hour_rewards($pdo, $id, (int)$_SESSION['user_id']);
        showToast(t('خروج ثبت شد'));
        redirect("index.php?page=work_hours&date=" . urlencode($_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── Edit entry (after checkout) ──
    if ($action === 'edit' && $can_manage) {
        $id = (int)$_POST['id'];
        if (!can_user_edit_wh_record($pdo, $id)) {
            showToast(t('تغییر ساعت نیروی مأمور فقط توسط مدیر پروژه یا کارگاه مبدأ امکان‌پذیر است'), 'error');
            redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
            exit;
        }
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];

        $is_night = (strtotime($start_time) >= strtotime('21:00:00') || strtotime($end_time) >= strtotime('21:00:00') || strtotime($end_time) < strtotime($start_time)) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE work_hours SET start_time = ?, end_time = ?, is_night = ? WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$start_time, $end_time, $is_night, $id, $ws_id]);
        arash_coin_sync_work_hour_rewards($pdo, $id, (int)$_SESSION['user_id']);
        showToast(t('ساعت ویرایش شد'));
        redirect("index.php?page=work_hours&date=" . urlencode($_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── Delete ──
    if ($action === 'delete' && $can_manage) {
        $id = (int)$_POST['id'];
        if (!can_user_edit_wh_record($pdo, $id)) {
            showToast(t('حذف ساعت نیروی مأمور فقط توسط مدیر پروژه یا کارگاه مبدأ امکان‌پذیر است'), 'error');
            redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
            exit;
        }
        wh_sync_mission($pdo, $id, 'cancel'); // §3: حذف رکورد مأموریت = لغو مأموریت
        arash_coin_remove_work_hour_rewards($pdo, $id);
        $stmt = $pdo->prepare("DELETE FROM work_hours WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$id, $ws_id]);
        showToast(t('حذف شد'));
        redirect("index.php?page=work_hours&date=" . urlencode($_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── Bulk checkout: close several open entries at once ──
    if ($action === 'bulk_checkout' && $can_manage) {
        $ids = $_POST['ids'] ?? [];
        $end_time = $_POST['end_time'] ?: date('H:i');
        $done = 0; $blocked = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if (!can_user_edit_wh_record($pdo, $id)) { $blocked++; continue; }
            $stmt = $pdo->prepare("UPDATE work_hours SET end_time = ? WHERE id = ? AND workshop_id = ? AND end_time = '00:00:00'");
            $stmt->execute([$end_time, $id, $ws_id]);
            if ($stmt->rowCount() > 0) {
                $row = $pdo->prepare("SELECT start_time, end_time FROM work_hours WHERE id = ?");
                $row->execute([$id]);
                $wh = $row->fetch();
                if ($wh) {
                    $is_night = (strtotime($wh['start_time']) >= strtotime('21:00:00') || strtotime($wh['end_time']) >= strtotime('21:00:00') || strtotime($wh['end_time']) < strtotime($wh['start_time'])) ? 1 : 0;
                    $pdo->prepare("UPDATE work_hours SET is_night = ? WHERE id = ?")->execute([$is_night, $id]);
                }
                wh_sync_mission($pdo, $id, 'close', $end_time); // §3.5
                arash_coin_sync_work_hour_rewards($pdo, $id, (int)$_SESSION['user_id']);
                $done++;
            }
        }
        if ($blocked > 0 && $done === 0) {
            showToast(t('تغییر ساعت نیروهای مأمور فقط توسط مدیر پروژه یا کارگاه مبدأ امکان‌پذیر است'), 'error');
        } elseif ($blocked > 0) {
            showToast(strtr(t('خروج {count} نفر ثبت شد ({blocked} نیروی مأمور رد شدند)'), ['{count}' => $done, '{blocked}' => $blocked]));
        } else {
            showToast(strtr(t('خروج {count} نفر ثبت شد'), ['{count}' => $done]));
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── Bulk edit: apply one start/end to several entries ──
    if ($action === 'bulk_edit' && $can_manage) {
        $ids = $_POST['ids'] ?? [];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $is_night = (strtotime($start_time) >= strtotime('21:00:00') || strtotime($end_time) >= strtotime('21:00:00') || strtotime($end_time) < strtotime($start_time)) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE work_hours SET start_time = ?, end_time = ?, is_night = ? WHERE id = ? AND workshop_id = ?");
        $done = 0; $blocked = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if (!can_user_edit_wh_record($pdo, $id)) { $blocked++; continue; }
            $stmt->execute([$start_time, $end_time, $is_night, (int)$id, $ws_id]);
            if ($stmt->rowCount() > 0) { arash_coin_sync_work_hour_rewards($pdo, (int)$id, (int)$_SESSION['user_id']); $done++; }
        }
        if ($blocked > 0 && $done === 0) {
            showToast(t('تغییر ساعت نیروهای مأمور فقط توسط مدیر پروژه یا کارگاه مبدأ امکان‌پذیر است'), 'error');
        } elseif ($blocked > 0) {
            showToast(strtr(t('ساعت {count} رکورد ویرایش شد ({blocked} نیروی مأمور رد شدند)'), ['{count}' => $done, '{blocked}' => $blocked]));
        } else {
            showToast(strtr(t('ساعت {count} رکورد ویرایش شد'), ['{count}' => $done]));
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── Bulk delete ──
    if ($action === 'bulk_delete' && $can_manage) {
        $ids = $_POST['ids'] ?? [];
        $stmt = $pdo->prepare("DELETE FROM work_hours WHERE id = ? AND workshop_id = ?");
        $done = 0; $blocked = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if (!can_user_edit_wh_record($pdo, $id)) { $blocked++; continue; }
            wh_sync_mission($pdo, (int)$id, 'cancel'); // §3
            arash_coin_remove_work_hour_rewards($pdo, (int)$id);
            $stmt->execute([(int)$id, $ws_id]);
            if ($stmt->rowCount() > 0) $done++;
        }
        if ($blocked > 0 && $done === 0) {
            showToast(t('حذف ساعت نیروهای مأمور فقط توسط مدیر پروژه یا کارگاه مبدأ امکان‌پذیر است'), 'error');
        } elseif ($blocked > 0) {
            showToast(strtr(t('{count} رکورد حذف شد ({blocked} نیروی مأمور رد شدند)'), ['{count}' => $done, '{blocked}' => $blocked]));
        } else {
            showToast(strtr(t('رکورد حذف شد'), ['{count}' => $done]));
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── §3.5: ثبت پایان مأموریت (تاریخ + ساعت + توضیح اختیاری) ──
    if ($action === 'end_mission' && $can_manage) {
        $mid = (int)$_POST['mission_id'];
        $end_date = to_gregorian($_POST['end_date'] ?? jalali_now());
        $end_time = $_POST['end_time'] ?: date('H:i');
        $desc = sanitize($_POST['mission_desc'] ?? '');

        $stm = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
        $stm->execute([$mid]);
        $mission = $stm->fetch();
        if ($mission && $mission['status'] === 'active') {
            $src_ws = (int)$mission['source_workshop_id'];
            if (!is_strict_admin() && !is_project_manager() && !can_user_manage_ws_work_hours($pdo, (int)$_SESSION['user_id'], $src_ws)) {
                showToast(t('ثبت پایان مأموریت فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است'), 'error');
                redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
                exit;
            }
            $pdo->prepare("UPDATE missions SET status = 'completed', end_date = ?, end_time = ?, completed_by = ?, description = TRIM(BOTH ' — ' FROM CONCAT_WS(' — ', NULLIF(description, ''), NULLIF(?, ''))) WHERE id = ?")
                ->execute([$end_date, $end_time, $_SESSION['user_id'], $desc, $mid]);
            // رکورد(های) ساعتِ بازِ متصل به این مأموریت هم با همین ساعت بسته شود
            $stw = $pdo->prepare("SELECT id FROM work_hours WHERE mission_id = ? AND end_time = '00:00:00'");
            $stw->execute([$mid]);
            $closed = 0;
            foreach ($stw->fetchAll(PDO::FETCH_COLUMN) as $wh_id_m) {
                $pdo->prepare("UPDATE work_hours SET end_time = ? WHERE id = ?")->execute([$end_time, $wh_id_m]);
                $row = $pdo->prepare("SELECT start_time, end_time FROM work_hours WHERE id = ?");
                $row->execute([$wh_id_m]);
                $wh = $row->fetch();
                if ($wh) {
                    $is_night = (strtotime($wh['start_time']) >= strtotime('21:00:00') || strtotime($wh['end_time']) >= strtotime('21:00:00') || strtotime($wh['end_time']) < strtotime($wh['start_time'])) ? 1 : 0;
                    $pdo->prepare("UPDATE work_hours SET is_night = ? WHERE id = ?")->execute([$is_night, $wh_id_m]);
                }
                arash_coin_sync_work_hour_rewards($pdo, (int)$wh_id_m, (int)$_SESSION['user_id']);
                $closed++;
            }
            showToast($closed ? strtr(t('🚀 پایان مأموریت ثبت شد و {count} رکورد ساعت بسته شد'), ['{count}' => $closed]) : t('🚀 پایان مأموریت ثبت شد'));
        } else {
            showToast(t('مأموریت یافت نشد یا قبلاً پایان یافته'), 'error');
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── ویرایش ساعت مأموریت از کارگاه مبدأ ──
    if ($action === 'edit_mission' && $can_manage) {
        $mid = (int)$_POST['mission_id'];
        $start_time = sanitize($_POST['start_time'] ?? '');
        $end_time = sanitize($_POST['end_time'] ?? '');
        $desc = sanitize($_POST['mission_desc'] ?? '');

        $stm = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
        $stm->execute([$mid]);
        $mission = $stm->fetch();
        if ($mission) {
            $src_ws = (int)$mission['source_workshop_id'];
            if (!is_strict_admin() && !is_project_manager() && !can_user_manage_ws_work_hours($pdo, (int)$_SESSION['user_id'], $src_ws)) {
                showToast(t('ویرایش ساعت مأموریت فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است'), 'error');
                redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
                exit;
            }
            $is_completed = ($end_time !== '' && $end_time !== '00:00:00' && $end_time !== '00:00');
            $new_status = $is_completed ? 'completed' : 'active';
            $end_date = $is_completed ? ($mission['end_date'] ?: $mission['start_date']) : null;
            $end_time_val = $is_completed ? $end_time : '00:00:00';

            $pdo->prepare("UPDATE missions SET start_time = ?, end_time = ?, status = ?, end_date = ?, description = ? WHERE id = ?")
                ->execute([$start_time, $end_time_val, $new_status, $end_date, $desc, $mid]);

            $is_night = (strtotime($start_time) >= strtotime('21:00:00') || ($is_completed && (strtotime($end_time) >= strtotime('21:00:00') || strtotime($end_time) < strtotime($start_time)))) ? 1 : 0;
            $pdo->prepare("UPDATE work_hours SET start_time = ?, end_time = ?, is_night = ?, note = ? WHERE mission_id = ?")
                ->execute([$start_time, $end_time_val, $is_night, $desc, $mid]);

            $stw = $pdo->prepare("SELECT id FROM work_hours WHERE mission_id = ?");
            $stw->execute([$mid]);
            foreach ($stw->fetchAll(PDO::FETCH_COLUMN) as $wh_id_m) {
                arash_coin_sync_work_hour_rewards($pdo, (int)$wh_id_m, (int)$_SESSION['user_id']);
            }
            showToast(t('ساعت مأموریت با موفقیت ویرایش شد'));
        } else {
            showToast(t('مأموریت یافت نشد'), 'error');
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── حذف مأموریت ──
    if ($action === 'delete_mission' && $can_manage) {
        $mid = (int)$_POST['mission_id'];
        $stm = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
        $stm->execute([$mid]);
        $mission = $stm->fetch();
        if ($mission) {
            $src_ws = (int)$mission['source_workshop_id'];
            if (!is_strict_admin() && !is_project_manager() && !can_user_manage_ws_work_hours($pdo, (int)$_SESSION['user_id'], $src_ws)) {
                showToast(t('حذف مأموریت فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است'), 'error');
                redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
                exit;
            }
            $stw = $pdo->prepare("SELECT id FROM work_hours WHERE mission_id = ?");
            $stw->execute([$mid]);
            foreach ($stw->fetchAll(PDO::FETCH_COLUMN) as $wh_id_m) {
                arash_coin_remove_work_hour_rewards($pdo, (int)$wh_id_m);
            }
            $pdo->prepare("DELETE FROM work_hours WHERE mission_id = ?")->execute([$mid]);
            $pdo->prepare("DELETE FROM missions WHERE id = ?")->execute([$mid]);
            showToast(t('مأموریت و ساعات کاری مربوطه حذف شد'));
        } else {
            showToast(t('مأموریت یافت نشد'), 'error');
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── خروج دسته‌جمعی مأموریت‌ها ──
    if ($action === 'bulk_end_mission' && $can_manage) {
        $mids = array_map('intval', (array)($_POST['mission_ids'] ?? []));
        $end_time = trim((string)($_POST['end_time'] ?? '')) ?: date('H:i');
        $end_date = to_gregorian($_POST['date'] ?? jalali_now());
        $done = 0; $blocked = 0;
        foreach ($mids as $mid) {
            if ($mid <= 0) continue;
            $stm = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
            $stm->execute([$mid]);
            $mission = $stm->fetch();
            if ($mission && $mission['status'] === 'active') {
                $src_ws = (int)$mission['source_workshop_id'];
                if (!is_strict_admin() && !is_project_manager() && !can_user_manage_ws_work_hours($pdo, (int)$_SESSION['user_id'], $src_ws)) {
                    $blocked++;
                    continue;
                }
                $pdo->prepare("UPDATE missions SET status = 'completed', end_date = ?, end_time = ?, completed_by = ? WHERE id = ?")
                    ->execute([$end_date, $end_time, $_SESSION['user_id'], $mid]);

                $stw = $pdo->prepare("SELECT id, start_time FROM work_hours WHERE mission_id = ? AND (end_time = '00:00:00' OR end_time IS NULL)");
                $stw->execute([$mid]);
                foreach ($stw->fetchAll() as $wh_row) {
                    $is_night = (strtotime($wh_row['start_time']) >= strtotime('21:00:00') || strtotime($end_time) >= strtotime('21:00:00') || strtotime($end_time) < strtotime($wh_row['start_time'])) ? 1 : 0;
                    $pdo->prepare("UPDATE work_hours SET end_time = ?, is_night = ? WHERE id = ?")->execute([$end_time, $is_night, $wh_row['id']]);
                    arash_coin_sync_work_hour_rewards($pdo, (int)$wh_row['id'], (int)$_SESSION['user_id']);
                }
                $done++;
            }
        }
        if ($blocked > 0 && $done === 0) {
            showToast(t('ثبت پایان مأموریت‌ها فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است'), 'error');
        } elseif ($blocked > 0) {
            showToast(strtr(t('خروج دسته‌جمعی {count} مأموریت ثبت شد ({blocked} مأموریت رد شد)'), ['{count}' => $done, '{blocked}' => $blocked]));
        } else {
            showToast(strtr(t('خروج دسته‌جمعی {count} مأموریت ثبت شد'), ['{count}' => $done]));
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── ویرایش دسته‌جمعی ساعت مأموریت‌ها ──
    if ($action === 'bulk_edit_mission' && $can_manage) {
        $mids = array_map('intval', (array)($_POST['mission_ids'] ?? []));
        $start_time = trim((string)($_POST['bulk_start_time'] ?? ''));
        $end_time = trim((string)($_POST['bulk_end_time'] ?? ''));
        $done = 0; $blocked = 0;
        foreach ($mids as $mid) {
            if ($mid <= 0) continue;
            $stm = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
            $stm->execute([$mid]);
            $mission = $stm->fetch();
            if ($mission) {
                $src_ws = (int)$mission['source_workshop_id'];
                if (!is_strict_admin() && !is_project_manager() && !can_user_manage_ws_work_hours($pdo, (int)$_SESSION['user_id'], $src_ws)) {
                    $blocked++;
                    continue;
                }
                $up_st = ($start_time !== '') ? $start_time : $mission['start_time'];
                $up_et = ($end_time !== '') ? $end_time : $mission['end_time'];
                $is_comp = ($up_et !== '' && $up_et !== '00:00:00' && $up_et !== '00:00');
                $st_val = $is_comp ? 'completed' : 'active';
                $end_date_val = $is_comp ? ($mission['end_date'] ?: $mission['start_date']) : null;

                $pdo->prepare("UPDATE missions SET start_time = ?, end_time = ?, status = ?, end_date = ? WHERE id = ?")
                    ->execute([$up_st, $up_et, $st_val, $end_date_val, $mid]);

                $is_night = (strtotime($up_st) >= strtotime('21:00:00') || ($is_comp && (strtotime($up_et) >= strtotime('21:00:00') || strtotime($up_et) < strtotime($up_st)))) ? 1 : 0;
                $pdo->prepare("UPDATE work_hours SET start_time = ?, end_time = ?, is_night = ? WHERE mission_id = ?")
                    ->execute([$up_st, $up_et, $is_night, $mid]);

                $stw = $pdo->prepare("SELECT id FROM work_hours WHERE mission_id = ?");
                $stw->execute([$mid]);
                foreach ($stw->fetchAll(PDO::FETCH_COLUMN) as $wh_id_m) {
                    arash_coin_sync_work_hour_rewards($pdo, (int)$wh_id_m, (int)$_SESSION['user_id']);
                }
                $done++;
            }
        }
        if ($blocked > 0 && $done === 0) {
            showToast(t('ویرایش ساعت مأموریت‌ها فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است'), 'error');
        } elseif ($blocked > 0) {
            showToast(strtr(t('ساعت {count} مأموریت با موفقیت ویرایش شد ({blocked} مأموریت رد شد)'), ['{count}' => $done, '{blocked}' => $blocked]));
        } else {
            showToast(strtr(t('ساعت {count} مأموریت با موفقیت ویرایش شد'), ['{count}' => $done]));
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── حذف دسته‌جمعی مأموریت‌ها ──
    if ($action === 'bulk_delete_mission' && $can_manage) {
        $mids = array_map('intval', (array)($_POST['mission_ids'] ?? []));
        $done = 0; $blocked = 0;
        foreach ($mids as $mid) {
            if ($mid <= 0) continue;
            $stm = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
            $stm->execute([$mid]);
            $mission = $stm->fetch();
            if ($mission) {
                $src_ws = (int)$mission['source_workshop_id'];
                if (!is_strict_admin() && !is_project_manager() && !can_user_manage_ws_work_hours($pdo, (int)$_SESSION['user_id'], $src_ws)) {
                    $blocked++;
                    continue;
                }
                $stw = $pdo->prepare("SELECT id FROM work_hours WHERE mission_id = ?");
                $stw->execute([$mid]);
                foreach ($stw->fetchAll(PDO::FETCH_COLUMN) as $wh_id_m) {
                    arash_coin_remove_work_hour_rewards($pdo, (int)$wh_id_m);
                }
                $pdo->prepare("DELETE FROM work_hours WHERE mission_id = ?")->execute([$mid]);
                $pdo->prepare("DELETE FROM missions WHERE id = ?")->execute([$mid]);
                $done++;
            }
        }
        if ($blocked > 0 && $done === 0) {
            showToast(t('حذف مأموریت‌ها فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است'), 'error');
        } elseif ($blocked > 0) {
            showToast(strtr(t('{count} مأموریت با موفقیت حذف شدند ({blocked} مأموریت رد شد)'), ['{count}' => $done, '{blocked}' => $blocked]));
        } else {
            showToast(strtr(t('{count} مأموریت با موفقیت حذف شدند'), ['{count}' => $done]));
        }
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? jalali_now()) . $fqs);
    }

    // ── Protest: worker submits dispute ──
    if ($action === 'protest') {
        $id = (int)$_POST['id'];
        $new_start = $_POST['protest_start'];
        $new_end = $_POST['protest_end'];
        $note = sanitize($_POST['protest_note'] ?? '');
        $text = t('ورود') . ': ' . $new_start . ' — ' . t('خروج') . ': ' . $new_end . ($note ? ' — ' . $note : '');
        $stmt = $pdo->prepare("UPDATE work_hours SET protest_text = ?, protest_status = 'pending', protest_by = ? WHERE id = ?");
        $stmt->execute([$text, $_SESSION['user_id'], $id]);
        showToast(t('اعتراض ثبت شد - در انتظار بررسی'));
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? '') . $fqs);
    }

    // ── Approve protest ──
    if ($action === 'approve_protest' && $can_manage) {
        $id = (int)$_POST['id'];
        // Parse new times from protest_text "ورود: HH:MM — خروج: HH:MM"
        $new_start = $_POST['new_start_time'];
        $new_end = $_POST['new_end_time'];
        $is_night = (strtotime($new_start) >= strtotime('21:00:00') || strtotime($new_end) >= strtotime('21:00:00') || strtotime($new_end) < strtotime($new_start)) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE work_hours SET start_time = ?, end_time = ?, is_night = ?, protest_status = 'approved' WHERE id = ?");
        $stmt->execute([$new_start, $new_end, $is_night, $id]);
        arash_coin_sync_work_hour_rewards($pdo, $id, (int)$_SESSION['user_id']);
        showToast(t('اعتراض تایید و ساعت اصلاح شد'));
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? '') . $fqs);
    }

    // ── Reject protest ──
    if ($action === 'reject_protest' && $can_manage) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE work_hours SET protest_status = 'rejected' WHERE id = ?");
        $stmt->execute([$id]);
        showToast(t('اعتراض رد شد'));
        redirect("index.php?page=work_hours&date=" . urlencode($_POST['date'] ?? $_GET['date'] ?? '') . $fqs);
    }
}

$selected_date = $_GET['date'] ?? jalali_now();
$greg_date = to_gregorian($selected_date);

// Personnel
$stmt = $pdo->prepare("SELECT u.id, u.full_name, u.is_karigar FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' AND u.username != 'yaser' ORDER BY u.full_name");
$stmt->execute([$ws_id]);
$personnel = $stmt->fetchAll();

// Work hours for selected date (همراه نام کارگاه مبدأ مأموریت)
if ($is_own_view && !$can_see_others) {
    $stmt = $pdo->prepare("SELECT wh.*, u.full_name, u.is_karigar, mws.name AS mission_source_name FROM work_hours wh JOIN users u ON wh.user_id = u.id LEFT JOIN workshops mws ON mws.id = wh.mission_source_ws WHERE wh.workshop_id = ? AND wh.work_date = ? AND wh.user_id = ? ORDER BY wh.start_time");
    $stmt->execute([$ws_id, $greg_date, $_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("SELECT wh.*, u.full_name, u.is_karigar, mws.name AS mission_source_name FROM work_hours wh JOIN users u ON wh.user_id = u.id LEFT JOIN workshops mws ON mws.id = wh.mission_source_ws WHERE wh.workshop_id = ? AND wh.work_date = ? ORDER BY u.full_name, wh.start_time");
    $stmt->execute([$ws_id, $greg_date]);
}
$work_hours = $stmt->fetchAll();
if ($temp_grant) {
    // فقط ردیف‌های همین شیفت
    $work_hours = array_values(array_filter($work_hours, fn($r) => temp_grant_row_in_shift($r, $tg_shift)));
}

// مأموریت‌های مربوط به همین تاریخ برای این کارگاه (مبدأ)
// بند ۲: در کارگاه مقصد، نیروها فقط در جدول ساعت کاری بالا نمایش داده می‌شوند و نباید در مأموریت‌های پایین تکرار شوند.
// در کارگاه مبدأ، نیروهای اعزام‌شده در مأموریت‌های پایین نمایش داده می‌شوند و در جدول ساعت کاری بالا حضور ندارند.
// بند ۳: نیروهایی که دسترسی مدیریت ساعت کاری را ندارند، فقط مأموریت خودشان را می‌بینند.
$can_see_all_missions = $can_manage || can_see_others_hours() || in_array($work_hours_mode, ['viewer', 'editor'], true);
$day_missions = [];
try {
    if ($can_see_all_missions) {
        $stmt = $pdo->prepare("SELECT m.*, u.full_name, MIN(wh.id) AS wh_id,
            COALESCE(NULLIF(MIN(wh.start_time), ''), m.start_time) AS effective_start_time,
            COALESCE(NULLIF(MAX(wh.end_time), '00:00:00'), NULLIF(m.end_time, '00:00:00'), '00:00:00') AS effective_end_time,
            MAX(wh.note) AS wh_note
            FROM missions m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN work_hours wh ON wh.mission_id = m.id
            WHERE m.source_workshop_id = ?
              AND m.start_date = ?
              AND m.status IN ('active', 'completed')
            GROUP BY m.id
            ORDER BY m.id DESC");
        $stmt->execute([$ws_id, $greg_date]);
        $day_missions = $stmt->fetchAll();
    } else {
        // کاربران عادی بدون دسترسی مدیریت: فقط مأموریت خودشان
        $stmt = $pdo->prepare("SELECT m.*, u.full_name, MIN(wh.id) AS wh_id,
            COALESCE(NULLIF(MIN(wh.start_time), ''), m.start_time) AS effective_start_time,
            COALESCE(NULLIF(MAX(wh.end_time), '00:00:00'), NULLIF(m.end_time, '00:00:00'), '00:00:00') AS effective_end_time,
            MAX(wh.note) AS wh_note
            FROM missions m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN work_hours wh ON wh.mission_id = m.id
            WHERE m.source_workshop_id = ?
              AND m.user_id = ?
              AND m.start_date = ?
              AND m.status IN ('active', 'completed')
            GROUP BY m.id
            ORDER BY m.id DESC");
        $stmt->execute([$ws_id, (int)($_SESSION['user_id'] ?? 0), $greg_date]);
        $day_missions = $stmt->fetchAll();
    }
} catch (Throwable $e) { $day_missions = []; }
// کارگاه‌های مقصد مأموریت (غیر از کارگاه فعلی)
$mission_targets = [];
foreach ($ws_name_map as $wid => $wname) { if ($wid !== (int)$ws_id) $mission_targets[$wid] = $wname; }

// Pending protests for admin
$pending_protests = [];
if ($temp_grant) { $day_missions = []; $mission_targets = []; }
if ($can_manage && !$temp_grant) {
    $stmt = $pdo->prepare("SELECT wh.*, u.full_name FROM work_hours wh JOIN users u ON wh.user_id = u.id WHERE wh.workshop_id = ? AND wh.protest_status = 'pending' ORDER BY wh.work_date DESC");
    $stmt->execute([$ws_id]);
    $pending_protests = $stmt->fetchAll();
}

$stmt = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date = ?");
$stmt->execute([$greg_date]);
$is_holiday = $stmt->fetch();
$day_of_week = date('w', strtotime($greg_date));
$is_friday = ($day_of_week == 5);

include 'includes/header.php';

// Filter type for tabs
$filter_type = $_GET['type'] ?? 'all';
?>

<style>
.type-tabs { display:flex; gap:4px; margin-bottom:12px; }
.type-tab { padding:6px 16px; border:1px solid var(--border); border-radius:8px; background:var(--surface); cursor:pointer; font-size:12px; font-family:inherit; text-decoration:none; color:var(--text); }
.type-tab.active { background:var(--primary); color:white; border-color:var(--primary); }
</style>

<?php if (is_strict_admin()):
    // ═══ §v16: مدیریت دسترسی موقت ثبت ساعت ═══
    temp_grants_ensure_table($pdo);
    $gp = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN workshop_personnel wp ON wp.user_id = u.id AND wp.workshop_id = ? AND wp.status = 'active' WHERE u.status = 'active' ORDER BY u.full_name");
    $gp->execute([$ws_id]);
    $grant_personnel = $gp->fetchAll();
    $gg = $pdo->prepare("SELECT g.*, u.full_name FROM temp_entry_grants g JOIN users u ON u.id = g.user_id WHERE g.workshop_id = ? AND g.grant_date = ? ORDER BY g.id DESC");
    $gg->execute([$ws_id, $greg_date]);
    $recent_grants = $gg->fetchAll();
?>
<div class="card mb-4 animate-in" style="border:1px solid #e7d9ae;background:#fdfaf1">
    <div style="font-weight:700;font-size:14px;margin-bottom:10px">🔑 دسترسی موقت ثبت ساعت <span class="text-muted" style="font-weight:400;font-size:12px">(کیوسک شیفت — برای وقتی که خودتان کارگاه نیستید)</span></div>
    <form method="POST" class="d-flex gap-2" style="flex-wrap:wrap;align-items:center">
        <?= get_csrf_field() ?>
        <input type="hidden" name="action" value="grant_create">
        <select name="grant_user" class="form-control" style="width:230px" required>
            <option value="">— انتخاب نفر —</option>
            <?php foreach ($grant_personnel as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['full_name']) ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="grant_date" class="form-control jalali-date" style="width:130px" value="<?= $selected_date ?>" required>
        <select name="grant_shift" class="form-control" style="width:130px" required>
            <option value="day">☀️ شیفت روز</option><option value="night">🌙 شیفت شب</option>
        </select>
        <button class="btn btn-primary btn-sm" type="submit">اعطای دسترسی</button>
        <span class="text-muted" style="font-size:11px">روز: ۰۶ تا ۲۲ همان تاریخ — شب: ۱۸ همان تاریخ تا ۱۰ صبح فردا. کاربر در پنل خودش همه‌چیز را مثل قبل می‌بیند؛ فقط در «ساعت کاری» برای همان تاریخ و شیفت، ساعت همه را می‌بیند و ورود/خروج/ویرایش می‌زند.</span>
    </form>
    <div class="text-muted" style="font-size:12px;margin-top:10px">دسترسی‌های ثبت‌شده برای <b><?= $selected_date ?></b> (با جابه‌جایی روز در پایین صفحه، دسترسی‌های همان روز نمایش داده می‌شود)</div>
    <?php if (!$recent_grants): ?><div class="text-muted" style="font-size:12px;margin-top:6px">برای این روز دسترسی‌ای داده نشده است.</div><?php endif; ?>
    <?php if ($recent_grants): ?>
    <table style="margin-top:12px;font-size:12px;width:100%;border-collapse:collapse">
        <thead><tr style="text-align:right;color:var(--text-muted)"><th style="padding:6px 4px">نفر</th><th style="padding:6px 4px">شیفت (تاریخ)</th><th style="padding:6px 4px">وضعیت</th><th style="padding:6px 4px">زمان اعطا</th><th style="padding:6px 4px"></th></tr></thead>
        <tbody>
        <?php foreach ($recent_grants as $rg): $stg = temp_grant_status($rg); ?>
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:6px 4px"><?= htmlspecialchars($rg['full_name']) ?></td>
            <td style="padding:6px 4px"><?= to_jalali($rg['grant_date']) ?> — <?= (($rg['shift_type'] ?? 'day') === 'night') ? '🌙 شب' : '☀️ روز' ?></td>
            <td style="padding:6px 4px">
                <?php if ($stg === 'active'): ?><span class="tag tag-active">فعال</span>
                <?php elseif ($stg === 'scheduled'): ?><span class="tag">شروع نشده</span>
                <?php elseif ($stg === 'revoked'): ?><span class="tag tag-rejected">لغوشده</span>
                <?php else: ?><span class="tag">پایان‌یافته</span><?php endif; ?>
            </td>
            <td style="padding:6px 4px" class="text-muted"><?= date('m/d H:i', strtotime((string)$rg['created_at'])) ?></td>
            <td style="padding:6px 4px">
                <?php if ($stg !== 'revoked'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('دسترسی این نفر لغو شود؟')"><?= get_csrf_field() ?><input type="hidden" name="action" value="grant_revoke"><input type="hidden" name="back_date" value="<?= $selected_date ?>"><input type="hidden" name="grant_id" value="<?= $rg['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">لغو دسترسی</button></form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('wh_title') ?> - <?= $selected_date ?></h2>
    <?php if ($can_manage): ?>
    <div class="btn-group">
        <button onclick="openModal('reportModal')" class="btn btn-info"><i data-lucide="file-text"></i> <?= t('گزارش نیروها') ?></button>
        <button onclick="openModal('checkinGroupModal')" class="btn btn-primary"><i data-lucide="users"></i> <?= t('btn_group_checkin') ?></button>
        <button onclick="openModal('checkinSingleModal')" class="btn btn-success"><i data-lucide="user-plus"></i> <?= t('btn_single_checkin') ?></button>
        <?php if (!$temp_grant): ?><button onclick="openBatchEntryModal()" class="btn btn-warning"><i data-lucide="calendar-range"></i> <?= t('ثبت') ?> <?= t('چندروزه') ?></button><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<div class="tabs mb-4 animate-in work-hours-tabs"><a href="index.php?page=work_hours&date=<?= $selected_date ?>" class="tab active">⏱ <?= t('nav_work_hours') ?></a><?php if ($can_manage && !$temp_grant): ?><a href="index.php?page=work_hours&tab=alerts&date=<?= $selected_date ?>" class="tab">🚨 <?= t('page_attendance_alerts') ?></a><?php endif; ?></div>

<!-- Type filter tabs -->
<div class="type-tabs animate-in">
    <a href="index.php?page=work_hours&date=<?= $selected_date ?>&type=all" class="type-tab <?= $filter_type === 'all' ? 'active' : '' ?>"><?= t('همه نیروها') ?></a>
    <a href="index.php?page=work_hours&date=<?= $selected_date ?>&type=employee" class="type-tab <?= $filter_type === 'employee' ? 'active' : '' ?>"><?= t('scope_employees') ?></a>
    <a href="index.php?page=work_hours&date=<?= $selected_date ?>&type=worker" class="type-tab <?= $filter_type === 'worker' ? 'active' : '' ?>"><?= t('scope_workers') ?></a>
    <a href="index.php?page=work_hours&date=<?= $selected_date ?>&type=peymankar" class="type-tab <?= $filter_type === 'peymankar' ? 'active' : '' ?>"><?= t('nav_subcontractors') ?></a>
</div>

<!-- Shift filter tabs -->
<?php
// شمارش شیفت روز و شب (بر اساس یوزر یکتا)
$day_users = []; $night_users = [];
foreach ($work_hours as $wh) {
    $sh = (int)date('H', strtotime($wh['start_time']));
    $is_open = ($wh['end_time'] === '00:00:00');
    $uid = $wh['user_id'];
    // شیفت روز: شروع 07:00-21:00
    if ($sh >= 7 && $sh < 21) $day_users[$uid] = true;
    // شیفت شب: شروع بعد 21:00 یا قبل 07:00 + ورودهای باز
    if ($sh >= 21 || $sh < 7 || $is_open) $night_users[$uid] = true;
}
$day_count = count($day_users);
$night_count = count($night_users);
$shift_filter = $_GET['shift'] ?? 'all';
?>
<div class="type-tabs animate-in" style="margin-bottom:12px">
    <a href="index.php?page=work_hours&date=<?= $selected_date ?>&type=<?= $filter_type ?>&shift=all" class="type-tab <?= $shift_filter === 'all' ? 'active' : '' ?>" style="<?= $shift_filter === 'all' ? 'border-color:var(--primary);background:rgba(99,102,241,0.1)' : '' ?>"><?= t('همه') ?> <?= t('wh_shift') ?>‌<?= t('ها') ?></a>
    <a href="index.php?page=work_hours&date=<?= $selected_date ?>&type=<?= $filter_type ?>&shift=day" class="type-tab <?= $shift_filter === 'day' ? 'active' : '' ?>" style="<?= $shift_filter === 'day' ? 'border-color:#f59e0b;background:#fef3c7;color:#92400e' : '' ?>">☀️ <?= t('شیفت روز') ?> (<?= $day_count ?>) — 7 <?= t('صبح') ?> <?= t('الی') ?> 9 <?= t('wh_night') ?></a>
    <a href="index.php?page=work_hours&date=<?= $selected_date ?>&type=<?= $filter_type ?>&shift=night" class="type-tab <?= $shift_filter === 'night' ? 'active' : '' ?>" style="<?= $shift_filter === 'night' ? 'border-color:#1e293b;background:#1e293b;color:white' : '' ?>">🌙 <?= t('شیفت شب') ?> (<?= $night_count ?>) — 9 <?= t('wh_night') ?> <?= t('الی') ?> 7 <?= t('صبح') ?></a>
</div>

<!-- Pending Protests -->
<?php if ($can_manage && !empty($pending_protests)): ?>
<div class="card mb-4 animate-in" style="border-right:4px solid var(--warning)">
    <div class="card-header"><h3 class="card-title">⚠️ <?= t('wh_pending_protests') ?> (<?= count($pending_protests) ?>)</h3></div>
    <?php foreach ($pending_protests as $pr): ?>
    <div style="padding:12px;border-bottom:1px solid var(--border)">
        <div class="d-flex justify-between align-center">
            <div>
                <strong><?= $pr['full_name'] ?></strong>
                <span class="text-muted" style="font-size:12px;margin-right:8px"><?= to_jalali($pr['work_date']) ?></span>
                <span class="text-muted" style="font-size:12px"> <?= t('wh_hours') ?> <?= t('ثبت') ?> <?= t('شده') ?>: <?= substr($pr['start_time'],0,5) ?> - <?= substr($pr['end_time'],0,5) ?></span>
            </div>
        </div>
        <p class="mt-1" style="font-size:13px">💬 <?= $pr['protest_text'] ?></p>
        <form method="POST" class="d-flex gap-2 mt-2" style="align-items:center;flex-wrap:wrap"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="approve_protest">
            <input type="hidden" name="id" value="<?= $pr['id'] ?>">
            <input type="hidden" name="date" value="<?= $selected_date ?>">
            <label class="form-label" style="margin:0;font-size:12px"><?= t('lea_from') ?>:</label>
            <input type="time" name="new_start_time" value="<?= substr($pr['start_time'],0,5) ?>" class="form-control" style="width:100px" required>
            <label class="form-label" style="margin:0;font-size:12px"><?= t('lea_to') ?>:</label>
            <input type="time" name="new_end_time" value="<?= substr($pr['end_time'],0,5) ?>" class="form-control" style="width:100px" required>
            <button type="submit" class="btn btn-success btn-sm"><?= t('btn_confirm') ?></button>
        </form>
        <form method="POST" style="display:inline" class="mt-1"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="reject_protest">
            <input type="hidden" name="id" value="<?= $pr['id'] ?>">
            <input type="hidden" name="date" value="<?= $selected_date ?>">
            <button type="submit" class="btn btn-danger btn-sm"><?= t('btn_reject') ?></button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Date Nav -->
<?php if ($temp_grant): ?>
<div class="card mb-4 animate-in" style="border:1px solid #e7d9ae;background:#fdfaf1;font-size:13px">
    🔑 دسترسی موقت فعال: شما فقط برای <b><?= $selected_date ?></b> — <b><?= temp_grant_shift_label($temp_grant) ?></b> می‌توانید ساعت کاری همه را ببینید و ورود/خروج/ویرایش کنید.
</div>
<?php else: ?>
<div class="card mb-4 animate-in">
    <div class="d-flex align-center gap-3 flex-wrap">
        <a href="index.php?page=work_hours&date=<?= to_jalali(date('Y-m-d', strtotime($greg_date.' -1 day'))) ?>&type=<?= $filter_type ?>&shift=<?= $shift_filter ?>" class="btn btn-ghost btn-sm">◀ <?= t('wh_prev_day') ?></a>
        <form method="GET" class="d-flex gap-2">
            <input type="hidden" name="page" value="work_hours">
            <input type="hidden" name="type" value="<?= $filter_type ?>">
            <input type="hidden" name="shift" value="<?= $shift_filter ?>">
            <input type="text" name="date" class="form-control jalali-date" value="<?= $selected_date ?>" style="width:130px" placeholder="1404/04/27">
            <button type="submit" class="btn btn-ghost btn-sm"><i data-lucide="search"></i></button>
        </form>
        <a href="index.php?page=work_hours&date=<?= to_jalali(date('Y-m-d', strtotime($greg_date.' +1 day'))) ?>&type=<?= $filter_type ?>&shift=<?= $shift_filter ?>" class="btn btn-ghost btn-sm"><?= t('wh_next_day') ?> ▶</a>
        <a href="index.php?page=work_hours&date=<?= jalali_now() ?>&type=<?= $filter_type ?>&shift=<?= $shift_filter ?>" class="btn btn-ghost btn-sm"><?= t('wh_today') ?></a>
        <?php if ($is_holiday): ?>
            <span class="tag tag-holiday">🔴 <?= t('wh_holiday') ?>: <?= $is_holiday['title'] ?></span>
        <?php elseif ($is_friday): ?>
            <span class="tag tag-friday">🟡 <?= t('wh_friday') ?></span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($can_manage): ?>
<!-- نوار عملیات دسته‌جمعی -->
<div class="card mb-3 animate-in" style="padding:10px 14px">
    <div class="d-flex align-center gap-2 flex-wrap" style="font-size:13px">
        <label class="form-check" style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" id="whSelectAll" onchange="toggleAllWH(this)">
            <span><?= t('wh_select_all') ?></span>
        </label>
        <span class="text-muted" id="whSelCount" style="font-size:11px"></span>
        <div class="me-auto"></div>
        <button onclick="openBulkModal('checkout')" class="btn btn-success btn-sm" disabled id="btnBulkCheckout"><i data-lucide="log-out"></i> <?= t('nav_logout') ?> <?= t('دسته') ?><?= t('‌جمعی') ?></button>
        <button onclick="openBulkModal('edit')" class="btn btn-primary btn-sm" disabled id="btnBulkEdit"><i data-lucide="edit"></i> <?= t('btn_edit') ?> <?= t('دسته') ?><?= t('‌جمعی') ?></button>
        <?php if (!$temp_grant): ?><button onclick="bulkDelete()" class="btn btn-danger btn-sm" disabled id="btnBulkDelete"><i data-lucide="trash-2"></i> <?= t('btn_delete') ?> <?= t('دسته') ?><?= t('‌جمعی') ?></button><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Work Hours List (card-based for mobile) -->
<div class="card animate-in">
    <div class="wh-list">
        <?php
        // Filter by type
        $filtered_hours = $work_hours;
        if ($filter_type === 'employee') {
            $filtered_hours = array_filter($work_hours, function($w) { return $w['is_karigar'] == 0; });
        } elseif ($filter_type === 'worker') {
            $filtered_hours = array_filter($work_hours, function($w) { return $w['is_karigar'] == 1; });
        } elseif ($filter_type === 'peymankar') {
            $filtered_hours = array_filter($work_hours, function($w) { return $w['is_karigar'] == 2; });
        }

        // Filter by shift
        if ($shift_filter === 'day') {
            $filtered_hours = array_filter($filtered_hours, function($w) {
                $sh = (int)date('H', strtotime($w['start_time']));
                return $sh >= 7 && $sh < 21;
            });
        } elseif ($shift_filter === 'night') {
            $filtered_hours = array_filter($filtered_hours, function($w) {
                $sh = (int)date('H', strtotime($w['start_time']));
                $is_open = ($w['end_time'] === '00:00:00');
                if ($sh >= 21 || $sh < 7) return true;
                if ($is_open) return true; // ورود باز = توی شیفت شب
                return false;
            });
        }

        foreach ($filtered_hours as $wh):
            $start_sec = strtotime($wh['start_time']);
            $end_sec = strtotime($wh['end_time']);
            $is_open = ($wh['end_time'] === '00:00:00');
            if ($is_open) {
                $duration = (time() - $start_sec) / 3600;
                if ($duration < 0) $duration += 24;
            } else {
                $duration = ($end_sec - $start_sec) / 3600;
                if ($duration <= 0) $duration += 24;
            }
            // تعیین شیفت بر اساس ساعت شروع
            $sh_start = (int)date('H', strtotime($wh['start_time']));
            $is_day_shift = ($sh_start >= 7 && $sh_start < 21);
            $shift_label = $is_day_shift ? '☀️ ' . t('روز') : '🌙 ' . t('شب');
            $shift_style = $is_day_shift ? 'background:#fef3c7;color:#92400e' : 'background:#1e293b;color:#e2e8f0';
            $shift_title = $is_day_shift ? t('شیفت صبح (7 صبح - 9 شب)') : t('شیفت شب (9 شب - 7 صبح)');
            // §1: کنترل مجوز تغییر ساعت نیروی مأمور از کارگاه دیگر
            $is_visiting_mission = (!empty($wh['mission_id']) || !empty($wh['mission_source_ws']));
            $wh_source_ws = (int)($wh['mission_source_ws'] ?? 0);
            $can_edit_this_wh = $can_manage && (!$is_visiting_mission || $wh_source_ws <= 0 || $wh_source_ws === (int)$ws_id || is_strict_admin() || is_project_manager() || can_user_edit_wh_record($pdo, (int)$wh['id']));
        ?>
        <div class="wh-item" style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);flex-wrap:wrap">
            <?php if ($can_manage): ?>
            <input type="checkbox" class="wh-check" value="<?= $wh['id'] ?>" data-open="<?= $is_open ? 1 : 0 ?>" data-can-edit="<?= $can_edit_this_wh ? 1 : 0 ?>" onchange="updateBulkUI()" style="flex-shrink:0" <?= !$can_edit_this_wh ? 'disabled title="' . t('تغییر ساعت نیروی مأمور فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است') . '"' : '' ?>>
            <?php endif; ?>
            <div style="flex:1;min-width:120px">
                <strong><?= $wh['full_name'] ?></strong>
                <?php if ($wh['is_karigar'] == 1): ?><span class="tag tag-karigar" style="margin-right:6px;font-size:10px"><?= t('sal_worker') ?></span>
                <?php elseif ($wh['is_karigar'] == 2): ?><span class="tag" style="margin-right:6px;font-size:10px;background:#e0e7ff;color:#3730a3"><?= t('پیمانکار') ?></span>
                <?php endif; ?>
                <?php if (!empty($wh['mission_id'])): ?><span class="tag" style="margin-right:6px;font-size:10px;background:#dbeafe;color:#1d4ed8" title="<?= t('wh_hours') ?> <?= t('این') ?> <?= t('نیرو') ?> <?= t('در') ?> <?= t('همین') ?> <?= t('کارگاه') ?> (<?= t('مقصد') ?> <?= t('مأموریت') ?>) <?= t('حساب') ?> <?= t('می‌شود') ?>">🚀 <?= t('نیروی کمکی') ?> <?= t('lea_from') ?> <?= t('کارگاه') ?> «<?= $wh['mission_source_name'] ?: t('اصلی') ?>»</span><?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                <span style="font-size:13px;font-weight:600"><?= substr($wh['start_time'],0,5) ?></span>
                <span class="text-muted">—</span>
                <?php if ($is_open): ?>
                    <span style="color:var(--success);font-weight:600;animation:pulse 1.5s infinite"><?= t('wh_open') ?></span>
                <?php else: ?>
                    <span style="font-size:13px;font-weight:600"><?= substr($wh['end_time'],0,5) ?></span>
                <?php endif; ?>
                <span class="tag" style="margin:0 4px;<?= $shift_style ?>"><?= $shift_label ?></span>
                <span style="font-size:12px;color:var(--text-secondary)"><?= number_format($duration,1) ?> <?= t('wh_hours') ?></span>
            </div>
            <?php if ($wh['protest_status'] == 'pending'): ?>
                <span class="tag tag-pending">⏳ <?= t('در انتظار') ?> <?= t('بررسی') ?></span>
            <?php elseif ($wh['protest_status'] == 'approved'): ?>
                <span class="tag tag-active">✓ <?= t('lea_approved') ?></span>
            <?php elseif ($wh['protest_status'] == 'rejected'): ?>
                <span class="tag tag-rejected">✗ <?= t('adv_rejected') ?></span>
            <?php endif; ?>
            <div style="display:flex;gap:4px;flex-shrink:0;align-items:center">
                <?php if ($can_manage && $is_open): ?>
                    <?php if ($can_edit_this_wh): ?>
                    <button onclick="openCheckoutModal(<?= $wh['id'] ?>, '<?= substr($wh['start_time'],0,5) ?>')" class="btn btn-success btn-sm"><i data-lucide="log-out"></i> <?= t('btn_checkout') ?></button>
                    <?php else: ?>
                    <span class="tag" style="background:#f1f5f9;color:#64748b;font-size:11px" title="<?= t('تغییر ساعت نیروی مأمور فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است') ?>"><i data-lucide="lock" style="width:12px;height:12px;vertical-align:middle"></i> <?= t('قفل کارگاه مبدأ') ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($can_manage && !$is_open): ?>
                    <?php if ($can_edit_this_wh): ?>
                    <button onclick="openEditWH(<?= $wh['id'] ?>, '<?= substr($wh['start_time'],0,5) ?>', '<?= substr($wh['end_time'],0,5) ?>')" class="btn btn-ghost btn-sm" title="<?= t('btn_edit') ?>"><i data-lucide="edit"></i></button>
                    <?php if (!$temp_grant): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('chat_delete') ?>')"><?= get_csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $wh['id'] ?>">
                        <input type="hidden" name="date" value="<?= $selected_date ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="trash-2"></i></button>
                    </form>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="tag" style="background:#f1f5f9;color:#64748b;font-size:11px" title="<?= t('تغییر ساعت نیروی مأمور فقط توسط کارگاه مبدأ یا مدیر پروژه امکان‌پذیر است') ?>"><i data-lucide="lock" style="width:12px;height:12px;vertical-align:middle"></i> <?= t('قفل کارگاه مبدأ') ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($wh['user_id'] == $_SESSION['user_id'] && $wh['end_time'] !== '00:00:00' && ($wh['protest_status'] === 'none' || empty($wh['protest_status']))): ?>
                <button onclick="openProtestModal(<?= $wh['id'] ?>, '<?= substr($wh['start_time'],0,5) ?>', '<?= substr($wh['end_time'],0,5) ?>')" class="btn btn-warning btn-sm"><?= t('btn_protest') ?></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($work_hours)): ?>
        <div class="text-center text-muted" style="padding:35px"><?= t('wh_no_records') ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- باکس مأموریت‌های این تاریخ (مبدأ) -->
<?php if (!empty($day_missions)): ?>
<div class="card mb-4 animate-in" style="border-right:4px solid #3b82f6">
    <div class="card-header d-flex justify-between align-center flex-wrap gap-2">
        <h3 class="card-title">🚀 <?= t('مأموریت‌های این تاریخ') ?> (<?= count($day_missions) ?> <?= t('per_count') ?>)</h3>
        <?php if ($can_manage && !empty($day_missions)): ?>
        <div class="d-flex align-center gap-2 flex-wrap" style="font-size:12px">
            <span class="text-muted" id="msSelCount" style="font-size:11px"></span>
            <button type="button" onclick="openBulkMissionModal('checkout')" class="btn btn-success btn-sm" disabled id="btnBulkMissionCheckout"><i data-lucide="log-out"></i> <?= t('خروج دسته‌جمعی مأموریت‌ها') ?></button>
            <button type="button" onclick="openBulkMissionModal('edit')" class="btn btn-primary btn-sm" disabled id="btnBulkMissionEdit"><i data-lucide="edit"></i> <?= t('ویرایش دسته‌جمعی ساعت') ?></button>
            <?php if (!$temp_grant): ?>
            <button type="button" onclick="bulkMissionDelete()" class="btn btn-danger btn-sm" disabled id="btnBulkMissionDelete"><i data-lucide="trash-2"></i> <?= t('حذف دسته‌جمعی') ?></button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="table-container"><table>
        <thead>
            <tr>
                <?php if ($can_manage): ?>
                <th style="width:36px">
                    <input type="checkbox" id="msSelectAll" onchange="toggleAllMS(this)">
                </th>
                <?php endif; ?>
                <th><?= t('نیرو') ?></th>
                <th><?= t('کارگاه مبدأ') ?></th>
                <th><?= t('کارگاه مقصد') ?></th>
                <th><?= t('wh_hours') ?> <?= t('ورود / شروع') ?></th>
                <th><?= t('wh_hours') ?> <?= t('خروج / پایان') ?></th>
                <th><?= t('wh_status') ?></th>
                <?php if ($can_manage): ?><th><?= t('wh_actions') ?></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($day_missions as $m):
            $src_name = $ws_name_map[(int)$m['source_workshop_id']] ?? '—';
            $tgt_name = $ws_name_map[(int)$m['target_workshop_id']] ?? '—';
            $m_start = $m['effective_start_time'] ? substr($m['effective_start_time'], 0, 5) : ($m['start_time'] ? substr($m['start_time'], 0, 5) : '-');
            $m_end = ($m['effective_end_time'] && $m['effective_end_time'] !== '00:00:00') ? substr($m['effective_end_time'], 0, 5) : (($m['end_time'] && $m['end_time'] !== '00:00:00') ? substr($m['end_time'], 0, 5) : '');
            $is_active = ($m['status'] === 'active' && empty($m_end));
            if ((int)$m['target_workshop_id'] === (int)$ws_id) {
                $status_line = strtr(t('نیروی کمکی از کارگاه «{workshop}»'), ['{workshop}' => $src_name]);
            } else {
                $status_line = strtr(t('مأموریت به کارگاه «{workshop}»'), ['{workshop}' => $tgt_name]);
            }
        ?>
        <tr>
            <?php if ($can_manage): ?>
            <td>
                <input type="checkbox" class="ms-cb" value="<?= $m['id'] ?>" data-active="<?= $is_active ? 1 : 0 ?>" onchange="updateMSCount()">
            </td>
            <?php endif; ?>
            <td><strong><?= htmlspecialchars($m['full_name']) ?></strong></td>
            <td><?= htmlspecialchars($src_name) ?></td>
            <td><?= htmlspecialchars($tgt_name) ?></td>
            <td><?= $m_start ?></td>
            <td><?= $m_end ?: '<span class="text-warning">در حال مأموریت...</span>' ?></td>
            <td>
                <?php if ($is_active): ?>
                    <span class="tag" style="background:#dbeafe;color:#1d4ed8">🚀 <?= $status_line ?></span>
                <?php else: ?>
                    <span class="tag" style="background:#dcfce7;color:#15803d">🏁 پایان یافته</span>
                <?php endif; ?>
            </td>
            <?php if ($can_manage): ?>
            <td>
                <div class="d-flex gap-1">
                    <?php if ($is_active): ?>
                    <button type="button" onclick="endMission(<?= (int)$m['id'] ?>, <?= htmlspecialchars(json_encode($m['full_name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-success btn-sm" title="<?= t('ثبت') ?> <?= t('wh_end') ?>"><i data-lucide="flag"></i></button>
                    <?php endif; ?>
                    <button type="button" onclick="editMissionHours(<?= (int)$m['id'] ?>, <?= htmlspecialchars(json_encode($m['full_name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, '<?= $m_start ?>', '<?= $m_end ?>', <?= htmlspecialchars(json_encode($m['description'] ?: ($m['wh_note'] ?: ''), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-primary btn-sm" title="<?= t('ویرایش ساعت مأموریت') ?>"><i data-lucide="edit"></i></button>
                    <?php if (!$temp_grant): ?>
                    <button type="button" onclick="deleteMission(<?= (int)$m['id'] ?>, <?= htmlspecialchars(json_encode($m['full_name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-danger btn-sm" title="<?= t('حذف مأموریت') ?>"><i data-lucide="trash-2"></i></button>
                    <?php endif; ?>
                </div>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<!-- Group Check-in Modal (with checkboxes) -->
<div id="checkinGroupModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:500px">
        <div class="modal-header"><h3 class="modal-title"><?= t('wh_checkin_group_title') ?></h3><button onclick="closeModal('checkinGroupModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="checkin">
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:12px"><?= t('wh_select_personnel') ?></p>
                <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="work_date" class="form-control" value="<?= $selected_date ?>" readonly></div>
                <div class="form-group"><label class="form-label"><?= t('wh_start') ?></label><input type="time" name="start_time" class="form-control" value="<?= date('H:i') ?>"></div>
                <?php if (!empty($mission_targets)): ?>
                <!-- §3.2: مأموریت گروهی — کارگاه مقصد مشترک + تیک 🚀 کنار هر نفر -->
                <div class="form-group" style="padding:10px;background:#eff6ff;border-radius:8px">
                    <label class="form-label">🚀 <?= t('مأموریت') ?> (<?= t('اختیاری') ?>)</label>
                    <select name="mission_ws" id="groupMissionWs" class="form-control" onchange="groupMissionChanged()">
                        <option value=""><?= t('بدون') ?> <?= t('مأموریت') ?> — <?= t('btn_checkin') ?> <?= t('در') ?> <?= t('همین') ?> <?= t('کارگاه') ?></option>
                        <?php foreach ($mission_targets as $wid => $wname): ?>
                        <option value="<?= $wid ?>"><?= t('مأموریت') ?> <?= t('به') ?> <?= t('کارگاه') ?> «<?= $wname ?>»</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted" style="font-size:10px;margin-top:4px"><?= t('برای') ?> <?= t('نفرهایی') ?> <?= t('که') ?> <?= t('تیک') ?> 🚀 <?= t('آن') ?>‌<?= t('ها') ?> <?= t('فعال') ?> <?= t('باشد') ?>، <?= t('رکورد') ?> <?= t('wh_hours') ?> <?= t('در') ?> <?= t('per_to_workshop') ?> <?= t('ثبت') ?> <?= t('می‌شود') ?> (<?= t('per_count') ?> <?= t('در') ?> <?= t('کارگاه') ?> <?= t('اصلی') ?> <?= t('می‌ماند') ?>)</div>
                </div>
                <?php endif; ?>
                <!-- Filter checkboxes -->
                <div style="display:flex;gap:12px;margin-bottom:8px;font-size:12px">
                    <label class="form-check"><input type="checkbox" checked onchange="filterGroupList(this,'employee')"><span><?= t('sal_employee') ?></span></label>
                    <label class="form-check"><input type="checkbox" checked onchange="filterGroupList(this,'worker')"><span><?= t('sal_worker') ?></span></label>
                    <label class="form-check"><input type="checkbox" checked onchange="filterGroupList(this,'peymankar')"><span><?= t('پیمانکار') ?></span></label>
                </div>
                <div class="mb-2"><button type="button" onclick="document.querySelectorAll('#groupPersonnelList input[name=\'selected_users[]\']:not(:disabled)').forEach(c=>c.checked=true)" class="btn btn-ghost btn-sm"><?= t('wh_select_all') ?></button>
                <button type="button" onclick="document.querySelectorAll('#groupPersonnelList input[type=checkbox]:not(:disabled)').forEach(c=>c.checked=false)" class="btn btn-ghost btn-sm"><?= t('wh_select_none') ?></button></div>
                <div id="groupPersonnelList" style="max-height:250px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:8px">
                    <?php foreach ($personnel as $p):
                        $has_open = false;
                        foreach ($work_hours as $wh) {
                            if ($wh['user_id'] == $p['id'] && $wh['end_time'] === '00:00:00') { $has_open = true; break; }
                        }
                        $ptype = $p['is_karigar'] == 2 ? 'peymankar' : ($p['is_karigar'] == 1 ? 'worker' : 'employee');
                    ?>
                    <label class="form-check group-person" data-type="<?= $ptype ?>" style="padding:6px 4px;border-bottom:1px solid var(--border);<?= $has_open ? 'opacity:0.5' : '' ?>">
                        <input type="checkbox" name="selected_users[]" value="<?= $p['id'] ?>" id="gp_sel_<?= $p['id'] ?>" <?= $has_open ? 'disabled' : '' ?>>
                        <span><?= $p['full_name'] ?>
                        <?php if ($p['is_karigar'] == 1): ?> <span class="tag tag-karigar" style="font-size:9px"><?= t('sal_worker') ?></span>
                        <?php elseif ($p['is_karigar'] == 2): ?> <span class="tag" style="font-size:9px;background:#e0e7ff;color:#3730a3"><?= t('پیمانکار') ?></span>
                        <?php endif; ?>
                        <?= $has_open ? '<span class="tag tag-pending" style="font-size:9px">' . t('wh_has_open') . '</span>' : '' ?></span>
                        <?php if (!$has_open && !empty($mission_targets)): ?>
                        <span class="group-mission-wrap" style="margin-right:auto;display:none;font-size:11px"><input type="checkbox" name="mission_user[]" value="<?= $p['id'] ?>" class="group-mission-check" onchange="missionTick(this, <?= $p['id'] ?>)"> 🚀 <?= t('مأموریت') ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-group mt-2"><label class="form-label"><?= t('wh_note') ?></label><input type="text" name="note" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_checkin') ?></button></div>
        </form>
    </div>
</div>

<!-- Single Check-in Modal -->
<div id="checkinSingleModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:420px">
        <div class="modal-header"><h3 class="modal-title"><?= t('wh_checkin_single_title') ?></h3><button onclick="closeModal('checkinSingleModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="checkin_single">
            <div class="modal-body">
                <!-- Filter checkboxes -->
                <div style="display:flex;gap:12px;margin-bottom:8px;font-size:12px">
                    <label class="form-check"><input type="checkbox" checked onchange="filterSingleList(this,'employee')"><span><?= t('sal_employee') ?></span></label>
                    <label class="form-check"><input type="checkbox" checked onchange="filterSingleList(this,'worker')"><span><?= t('sal_worker') ?></span></label>
                    <label class="form-check"><input type="checkbox" checked onchange="filterSingleList(this,'peymankar')"><span><?= t('پیمانکار') ?></span></label>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('wh_name') ?> *</label>
                    <select name="user_id" class="form-control" required id="singleCheckinUser">
                        <option value="">...</option>
                        <?php foreach ($personnel as $p):
                            $has_open = false;
                            foreach ($work_hours as $wh) {
                                if ($wh['user_id'] == $p['id'] && $wh['end_time'] === '00:00:00') { $has_open = true; break; }
                            }
                            $ptype = $p['is_karigar'] == 2 ? 'peymankar' : ($p['is_karigar'] == 1 ? 'worker' : 'employee');
                        ?>
                        <option value="<?= $p['id'] ?>" data-type="<?= $ptype ?>" <?= $has_open ? 'disabled' : '' ?>><?= $p['full_name'] ?> <?= $has_open ? '(' . t('wh_has_open') . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label"><?= t('wh_date') ?></label><input type="text" name="work_date" class="form-control" value="<?= $selected_date ?>" readonly></div>
                <div class="form-group"><label class="form-label"><?= t('wh_start') ?></label><input type="time" name="start_time" class="form-control" value="<?= date('H:i') ?>"></div>
                <?php if (!empty($mission_targets)): ?>
                <!-- §3.1: تیک مأموریت — ساعت برای کارگاه مقصد ثبت می‌شود -->
                <div class="form-group" style="padding:10px;background:#eff6ff;border-radius:8px">
                    <label class="form-check" style="margin-bottom:8px"><input type="checkbox" name="is_mission" value="1" id="singleMissionTick" onchange="singleMissionChanged()"><span>🚀 <?= t('مأموریت') ?> <?= t('به') ?> <?= t('کارگاه') ?> <?= t('دیگر') ?></span></label>
                    <div id="singleMissionBox" style="display:none">
                        <select name="mission_ws" class="form-control">
                            <option value=""><?= t('per_to_workshop') ?> <?= t('را') ?> <?= t('انتخاب کنید') ?></option>
                            <?php foreach ($mission_targets as $wid => $wname): ?>
                            <option value="<?= $wid ?>"><?= $wname ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted" style="font-size:10px;margin-top:4px"><?= t('per_count') ?> <?= t('در') ?> <?= t('کارگاه') ?> <?= t('اصلی') ?> <?= t('می‌ماند') ?>؛ <?= t('wh_hours') ?>/<?= t('رکورد') ?> <?= t('در') ?> <?= t('per_to_workshop') ?> <?= t('حساب') ?> <?= t('می‌شود') ?> <?= t('و') ?> <?= t('در') ?> <?= t('این') ?> <?= t('صفحه') ?> <?= t('به') ?><?= t('‌صورت') ?> «<?= t('مأموریت') ?> <?= t('به') ?> <?= t('per_to_workshop') ?>» <?= t('btn_show') ?> <?= t('داده') ?> <?= t('می‌شود') ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="form-group"><label class="form-label"><?= t('wh_note') ?></label><input type="text" name="note" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_checkin') ?></button></div>
        </form>
    </div>
</div>

<!-- ثبت چندروزهٔ یک نیرو: انتخاب بازه، تیک روزها و ساعت مستقل هر روز -->
<div id="batchEntryModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:780px">
        <div class="modal-header"><h3 class="modal-title">🗓️ <?= t('ثبت') ?> <?= t('nav_work_hours') ?> <?= t('در') ?> <?= t('چند') ?> <?= t('wh_day') ?></h3><button onclick="closeModal('batchEntryModal')" class="modal-close">✕</button></div>
        <form method="POST" id="batchEntryForm"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="multi_day_entry">
            <div class="modal-body">
                <p class="text-muted" style="font-size:12px;line-height:1.8"><?= t('یک') ?> <?= t('نیرو') ?> <?= t('و') ?> <?= t('بازهٔ') ?> <?= t('wh_date') ?> <?= t('را') ?> <?= t('انتخاب کنید') ?>. <?= t('سپس') ?> <?= t('فقط') ?> <?= t('روزهای') ?> <?= t('موردنظر') ?> <?= t('را') ?> <?= t('تیک') ?> <?= t('بزنید') ?> <?= t('و') ?> <?= t('wh_checkout_start') ?>/<?= t('nav_logout') ?> <?= t('هر') ?> <?= t('wh_day') ?> <?= t('را') ?> <?= t('جدا') ?> <?= t('ثبت') ?> <?= t('کنید') ?>. <?= t('اگر') ?> <?= t('همان') ?> <?= t('wh_day') ?> <?= t('فقط') ?> <?= t('یک') ?> <?= t('رکورد') ?> <?= t('sal_regular') ?> <?= t('داشته باشد') ?>، <?= t('با') ?> <?= t('همین') ?> <?= t('wh_hours') ?>‌<?= t('ها') ?> <?= t('اصلاح') ?> <?= t('می‌شود') ?>؛ <?= t('رکوردهای') ?> <?= t('مأموریت') ?> <?= t('یا') ?> <?= t('چندشیفت') ?> <?= t('دست') ?>‌<?= t('نخورده') ?> <?= t('می‌مانند') ?>.</p>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('نیرو') ?> *</label>
                        <select name="user_id" class="form-control" required><option value=""><?= t('انتخاب کنید') ?></option><?php foreach ($personnel as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['full_name']) ?><?= $p['is_karigar'] == 2 ? ' (' . t('پیمانکار') . ')' : ($p['is_karigar'] == 1 ? ' (' . t('کارگر') . ')' : '') ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="form-group"><label class="form-label"><?= t('wh_note') ?> <?= t('مشترک') ?></label><input type="text" name="note" class="form-control" placeholder="<?= t('اختیاری') ?>"></div>
                </div>
                <div class="form-row" style="align-items:end">
                    <div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_date') ?> *</label><input type="text" id="batchStartDate" class="form-control jalali-date" value="<?= htmlspecialchars($selected_date, ENT_QUOTES) ?>" required></div>
                    <div class="form-group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_date') ?> *</label><input type="text" id="batchEndDate" class="form-control jalali-date" value="<?= htmlspecialchars($selected_date, ENT_QUOTES) ?>" required></div>
                    <div class="form-group"><button type="button" onclick="buildBatchDays()" class="btn btn-primary" style="margin-top:25px"><?= t('ساخت') ?> <?= t('rep_days') ?></button></div>
                </div>
                <div id="batchDayHint" class="text-muted" style="font-size:11px;margin:2px 0 8px"><?= t('حداکثر') ?> ۶۲ <?= t('wh_day') ?> <?= t('در') ?> <?= t('هر') ?> <?= t('ثبت') ?> <?= t('ساخته') ?> <?= t('می‌شود') ?>.</div>
                <div class="d-flex gap-2 mb-2"><button type="button" class="btn btn-ghost btn-sm" onclick="setBatchChecks(true)"><?= t('wh_select_all') ?></button><button type="button" class="btn btn-ghost btn-sm" onclick="setBatchChecks(false)"><?= t('برداشتن') ?> <?= t('همه') ?></button></div>
                <div id="batchDaysBox" style="max-height:320px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:8px"><div class="text-muted text-center" style="padding:22px;font-size:12px"><?= t('بازهٔ') ?> <?= t('wh_date') ?> <?= t('را') ?> <?= t('وارد') ?> <?= t('و') ?> «<?= t('ساخت') ?> <?= t('rep_days') ?>» <?= t('را') ?> <?= t('بزنید') ?>.</div></div>
            </div>
            <div class="modal-footer"><button type="button" onclick="closeModal('batchEntryModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button><button type="submit" class="btn btn-warning"><?= t('ثبت') ?> <?= t('روزهای انتخاب‌شده') ?></button></div>
        </form>
    </div>
</div>

<!-- §3.5: End Mission Modal -->
<div id="endMissionModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:420px">
        <div class="modal-header"><h3 class="modal-title">🏁 <?= t('ثبت') ?> <?= t('wh_end') ?> <?= t('مأموریت') ?></h3><button onclick="closeModal('endMissionModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="end_mission">
            <input type="hidden" name="mission_id" id="em_id">
            <div class="modal-body">
                <p><?= t('نیرو') ?>: <strong id="em_name"></strong></p>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('wh_end') ?> *</label><input type="text" name="end_date" class="form-control jalali-date" value="<?= $selected_date ?>" required></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_hours') ?> <?= t('wh_end') ?> *</label><input type="time" name="end_time" class="form-control" value="<?= date('H:i') ?>" required></div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?> (<?= t('اختیاری') ?>)</label><input type="text" name="mission_desc" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('پروژه') ?> <?= t('تعمیرات') ?> <?= t('سقف') ?> <?= t('به') ?> <?= t('اتمام') ?> <?= t('رسید') ?>"></div>
                <p class="text-muted" style="font-size:11px"><?= t('با') ?> <?= t('ثبت') ?> <?= t('پایان') ?>، <?= t('ورودِ') ?> <?= t('بازِ') ?> <?= t('این') ?> <?= t('نیرو') ?> <?= t('در') ?> <?= t('per_to_workshop') ?> <?= t('با') ?> <?= t('همین') ?> <?= t('wh_hours') ?> <?= t('بسته') ?> <?= t('می‌شود') ?> <?= t('و') ?> <?= t('lea_from') ?> <?= t('لیست') ?> <?= t('مأموریت') ?>‌<?= t('های') ?> <?= t('فعال') ?> <?= t('خارج') ?> <?= t('می‌گردد.') ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('endMissionModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
                <button type="submit" class="btn btn-success"><?= t('ثبت') ?> <?= t('wh_end') ?> <?= t('مأموریت') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ویرایش ساعت تک مأموریت -->
<div id="editMissionModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:440px">
        <div class="modal-header">
            <h3 class="modal-title">✏️ <?= t('ویرایش ساعت مأموریت') ?> - <span id="em_edit_name"></span></h3>
            <button type="button" onclick="closeModal('editMissionModal')" class="modal-close">✕</button>
        </div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit_mission">
            <input type="hidden" name="mission_id" id="em_edit_id">
            <input type="hidden" name="date" value="<?= $selected_date ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('ساعت ورود / شروع') ?> *</label>
                        <input type="time" name="start_time" id="em_edit_start" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('ساعت خروج / پایان') ?></label>
                        <input type="time" name="end_time" id="em_edit_end" class="form-control" placeholder="00:00">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('توضیحات مأموریت') ?></label>
                    <input type="text" name="mission_desc" id="em_edit_desc" class="form-control">
                </div>
                <p class="text-muted" style="font-size:11px">📌 <?= t('ساعت مأموریت هم در کارگاه مبدأ و هم در کارگاه مقصد با این مقادیر بروزرسانی می‌شود.') ?></p>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button>
                <button type="button" onclick="closeModal('editMissionModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- خروج دسته جمعی مأموریت‌ها -->
<div id="bulkMissionCheckoutModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:420px">
        <div class="modal-header">
            <h3 class="modal-title">🚪 <?= t('خروج دسته جمعی مأموریت‌ها') ?></h3>
            <button type="button" onclick="closeModal('bulkMissionCheckoutModal')" class="modal-close">✕</button>
        </div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="bulk_end_mission">
            <input type="hidden" name="date" value="<?= $selected_date ?>">
            <div id="bulk_ms_checkout_inputs"></div>
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:12px">
                    <?= t('ثبت پایان و ساعت خروج برای') ?> <strong id="bulk_ms_co_count">0</strong> <?= t('مأموریت انتخاب‌شده:') ?>
                </p>
                <div class="form-group">
                    <label class="form-label"><?= t('ساعت پایان / خروج') ?> *</label>
                    <input type="time" name="end_time" class="form-control" value="<?= date('H:i') ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-success"><?= t('ثبت خروج مأموریت‌ها') ?></button>
                <button type="button" onclick="closeModal('bulkMissionCheckoutModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ویرایش دسته جمعی ساعت مأموریت‌ها -->
<div id="bulkMissionEditModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:420px">
        <div class="modal-header">
            <h3 class="modal-title">✏️ <?= t('ویرایش دسته جمعی ساعت مأموریت‌ها') ?></h3>
            <button type="button" onclick="closeModal('bulkMissionEditModal')" class="modal-close">✕</button>
        </div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="bulk_edit_mission">
            <input type="hidden" name="date" value="<?= $selected_date ?>">
            <div id="bulk_ms_edit_inputs"></div>
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:12px">
                    <?= t('ویرایش ساعت برای') ?> <strong id="bulk_ms_ed_count">0</strong> <?= t('مأموریت انتخاب‌شده:') ?>
                </p>
                <div class="form-group">
                    <label class="form-label"><?= t('ساعت شروع جدید') ?> (<?= t('خالی برای عدم تغییر') ?>)</label>
                    <input type="time" name="bulk_start_time" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('ساعت خروج جدید') ?> (<?= t('خالی برای عدم تغییر') ?>)</label>
                    <input type="time" name="bulk_end_time" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary"><?= t('اعمال تغییرات') ?></button>
                <button type="button" onclick="closeModal('bulkMissionEditModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Checkout Modal (with time editing) -->
<div id="checkoutModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:400px">
        <div class="modal-header"><h3 class="modal-title"><?= t('wh_checkout_title') ?></h3><button onclick="closeModal('checkoutModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="checkout">
            <input type="hidden" name="id" id="checkout_id">
            <input type="hidden" name="date" value="<?= $selected_date ?>">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('wh_checkout_start') ?></label><input type="text" id="checkout_start_display" class="form-control" readonly disabled></div>
                <div class="form-group"><label class="form-label"><?= t('wh_checkout_end') ?> *</label><input type="time" name="end_time" id="checkout_end_time" class="form-control" value="<?= date('H:i') ?>" required></div>
                <p class="text-muted" style="font-size:11px"><?= t('wh_checkout_hint') ?></p>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success"><?= t('btn_save') ?></button></div>
        </form>
    </div>
</div>

<!-- Report Modal (select type + person) -->
<div id="reportModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:420px">
        <div class="modal-header"><h3 class="modal-title"><?= t('گزارش نیروها') ?></h3><button onclick="closeModal('reportModal')" class="modal-close">✕</button></div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label"><?= t('sal_type') ?> <?= t('نیرو') ?></label>
                <select id="reportType" class="form-control" onchange="filterReportList()">
                    <option value="all"><?= t('همه') ?></option>
                    <option value="0"><?= t('sal_employee') ?></option>
                    <option value="1"><?= t('sal_worker') ?></option>
                    <option value="2"><?= t('پیمانکار') ?></option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('انتخاب نیرو') ?></label>
                <select id="reportPerson" class="form-control">
                    <option value=""><?= t('انتخاب کنید') ?></option>
                    <?php foreach ($personnel as $p):
                        $ptype = $p['is_karigar'] == 2 ? 'peymankar' : ($p['is_karigar'] == 1 ? 'worker' : 'employee');
                    ?>
                    <option value="<?= $p['id'] ?>" data-type="<?= $p['is_karigar'] ?>"><?= $p['full_name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary btn-block" onclick="goToReport()"><?= t('دریافت') ?> <?= t('گزارش') ?></button>
        </div>
    </div>
</div>

<!-- Edit Work Hours Modal -->
<div id="editWHModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:400px">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_edit') ?></h3><button onclick="closeModal('editWHModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_wh_id">
            <input type="hidden" name="date" value="<?= $selected_date ?>">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('wh_start') ?> *</label><input type="time" name="start_time" id="edit_wh_start" class="form-control" required></div>
                <div class="form-group"><label class="form-label"><?= t('wh_end') ?> *</label><input type="time" name="end_time" id="edit_wh_end" class="form-control" required></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button></div>
        </form>
    </div>
</div>

<?php if ($can_manage): ?>
<!-- Bulk Operations Modal -->
<div id="bulkModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:440px">
        <div class="modal-header"><h3 class="modal-title" id="bulkModalTitle"><?= t('wh_actions') ?> <?= t('دسته') ?><?= t('‌جمعی') ?></h3><button onclick="closeModal('bulkModal')" class="modal-close">✕</button></div>
        <form method="POST" id="bulkForm"><?= get_csrf_field() ?>
            <input type="hidden" name="action" id="bulk_action" value="">
            <div id="bulkIds"></div>
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:12px"><?= t('روی') ?> <strong id="bulkCountTxt">0</strong> <?= t('رکورد') ?> <?= t('انتخاب') ?>‌<?= t('شده') ?> <?= t('اعمال') ?> <?= t('می‌شود') ?></p>
                <div class="form-row" id="bulkTimeInputs">
                    <div class="form-group" id="bulkStartWrap"><label class="form-label"><?= t('wh_checkout_start') ?></label><input type="time" name="start_time" id="bulk_start" class="form-control" value="08:00"></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_checkout_end') ?></label><input type="time" name="end_time" id="bulk_end" class="form-control" value="<?= date('H:i') ?>"></div>
                </div>
                <p class="text-muted" style="font-size:11px" id="bulkHint"></p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('bulkModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
                <button type="submit" class="btn btn-primary" id="bulkSubmitBtn"><?= t('ثبت') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Protest Modal (worker) -->
<div id="protestModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:450px">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_protest') ?></h3><button onclick="closeModal('protestModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="protest">
            <input type="hidden" name="id" id="protest_id">
            <input type="hidden" name="date" value="<?= $selected_date ?>">
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:12px"><?= t('wh_checkout_start') ?> <?= t('و') ?> <?= t('nav_logout') ?> <?= t('صحیح') ?> <?= t('خود') ?> <?= t('را') ?> <?= t('وارد کنید') ?></p>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_checkout_start') ?> <?= t('صحیح') ?></label><input type="time" name="protest_start" class="form-control" id="protest_start" required></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_checkout_end') ?> <?= t('صحیح') ?></label><input type="time" name="protest_end" class="form-control" id="protest_end" required></div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?></label><textarea name="protest_note" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-warning"><?= t('btn_submit') ?></button></div>
        </form>
    </div>
</div>

<script>
function openEditWH(id, start, end) {
    document.getElementById('edit_wh_id').value = id;
    document.getElementById('edit_wh_start').value = start;
    document.getElementById('edit_wh_end').value = end;
    openModal('editWHModal');
}
function openProtestModal(id, start, end) {
    document.getElementById('protest_id').value = id;
    document.getElementById('protest_start').value = start;
    document.getElementById('protest_end').value = end;
    openModal('protestModal');
}
function openCheckoutModal(id, startTime) {
    document.getElementById('checkout_id').value = id;
    document.getElementById('checkout_start_display').value = startTime;
    document.getElementById('checkout_end_time').value = '<?= date('H:i') ?>';
    openModal('checkoutModal');
}

// ── <?= t('ثبت چندروزه') ?> ──
function openBatchEntryModal() {
    openModal('batchEntryModal');
    if (!document.querySelector('#batchDaysBox input[name="work_dates[]"]')) buildBatchDays();
}
function faDigits(v) { return (v || '').replace(/[۰-۹٠-٩]/g, function (c) { return '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(c) % 10; }); }
function parseBatchJalali(value) {
    var m = faDigits(value).replace(/-/g, '/').match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if (!m) return null;
    var d = { y:+m[1], m:+m[2], d:+m[3] };
    return (d.m >= 1 && d.m <= 12 && d.d >= 1 && d.d <= batchMonthDays(d.y,d.m)) ? d : null;
}
function batchLeap(y) { var r = y % 33; return [1,5,9,13,17,22,26,30].indexOf(r) !== -1; }
function batchMonthDays(y,m) { return m <= 6 ? 31 : (m <= 11 ? 30 : (batchLeap(y) ? 30 : 29)); }
function batchJalaliText(d) { return d.y + '/' + String(d.m).padStart(2,'0') + '/' + String(d.d).padStart(2,'0'); }
function batchNextJalali(d) { var n={y:d.y,m:d.m,d:d.d+1}; if(n.d>batchMonthDays(n.y,n.m)){n.d=1;n.m++;if(n.m>12){n.m=1;n.y++;}} return n; }
function batchCompare(a,b) { return a.y===b.y ? (a.m===b.m ? a.d-b.d : a.m-b.m) : a.y-b.y; }
function buildBatchDays() {
    var start = parseBatchJalali(document.getElementById('batchStartDate').value);
    var end = parseBatchJalali(document.getElementById('batchEndDate').value);
    var box = document.getElementById('batchDaysBox'), hint = document.getElementById('batchDayHint');
    if (!start || !end || batchCompare(start,end) > 0) { box.innerHTML='<div class="text-danger text-center" style="padding:18px"><?= t('بازهٔ') ?> <?= t('wh_date') ?> <?= t('شمسی') ?> <?= t('نامعتبر') ?> <?= t('است') ?>.</div>'; return; }
    var html='', day=start, n=0;
    while (batchCompare(day,end) <= 0 && n < 62) {
        var text=batchJalaliText(day);
        html += '<div class="batch-day-row" style="display:grid;grid-template-columns:30px minmax(100px,1fr) minmax(95px,1fr) minmax(95px,1fr);gap:8px;align-items:center;padding:7px 4px;border-bottom:1px solid var(--border)">'
            + '<input type="hidden" name="work_dates[]" value="'+text+'">'
            + '<input type="checkbox" name="use_dates[]" value="'+n+'" checked onchange="toggleBatchDay(this)">'
            + '<strong style="font-size:12px">'+text+'</strong>'
            + '<input type="time" name="start_times[]" class="form-control" value="08:00" style="min-width:0">'
            + '<input type="time" name="end_times[]" class="form-control" value="17:00" style="min-width:0">'
            + '</div>';
        day=batchNextJalali(day); n++;
    }
    if (!n) html='<div class="text-muted text-center" style="padding:18px"><?= t('روزی در این بازه نیست.') ?></div>';
    box.innerHTML=html;
    hint.textContent = n + ' <?= t('wh_day') ?> <?= t('ساخته شد') ?>' + (batchCompare(day,end) <= 0 ? ' — <?= t('برای') ?> <?= t('ثبت') ?>‌<?= t('های') ?> <?= t('طولانی') ?><?= t('‌تر') ?> <?= t('بازه') ?> <?= t('را') ?> <?= t('کوچک‌تر') ?> <?= t('کنید') ?>.' : '');
}
function toggleBatchDay(cb) { var row=cb.closest('.batch-day-row'); row.querySelectorAll('input[type=time]').forEach(function(i){i.disabled=!cb.checked;}); row.style.opacity=cb.checked?'1':'.5'; }
function setBatchChecks(on) { document.querySelectorAll('#batchDaysBox input[type=checkbox]').forEach(function(c){c.checked=on;toggleBatchDay(c);}); }

// ── §3 <?= t('مأموریت') ?> ──
function singleMissionChanged() {
    var t = document.getElementById('singleMissionTick');
    document.getElementById('singleMissionBox').style.display = t.checked ? 'block' : 'none';
}
function groupMissionChanged() {
    var v = document.getElementById('groupMissionWs').value;
    document.querySelectorAll('#groupPersonnelList .group-mission-wrap').forEach(function(w) {
        w.style.display = v ? '' : 'none';
        if (!v) w.querySelector('input[type=checkbox]').checked = false;
    });
}
function missionTick(cb, uid) {
    // تیک مأموریت ⇒ نفر حتماً باید در لیست ورود هم انتخاب شده باشد
    if (cb.checked) { var m = document.getElementById('gp_sel_' + uid); if (m && !m.disabled) m.checked = true; }
}
function endMission(mid, name) {
    document.getElementById('em_id').value = mid;
    document.getElementById('em_name').textContent = name;
    openModal('endMissionModal');
}

function editMissionHours(id, name, startTime, endTime, desc) {
    document.getElementById('em_edit_id').value = id;
    document.getElementById('em_edit_name').textContent = name || '';
    document.getElementById('em_edit_start').value = startTime || '';
    document.getElementById('em_edit_end').value = endTime || '';
    document.getElementById('em_edit_desc').value = desc || '';
    openModal('editMissionModal');
}

async function deleteMission(id, name) {
    if (await confirmAction('آیا از حذف مأموریت «' + (name || '') + '» و ساعات کاری مربوط به آن مطمئن هستید؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<?= get_csrf_field() ?><input type="hidden" name="action" value="delete_mission"><input type="hidden" name="mission_id" value="${id}"><input type="hidden" name="date" value="<?= $selected_date ?>">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleAllMS(master) {
    document.querySelectorAll('.ms-cb').forEach(function(cb) {
        cb.checked = master.checked;
    });
    updateMSCount();
}

function updateMSCount() {
    var checked = document.querySelectorAll('.ms-cb:checked');
    var count = checked.length;
    var badge = document.getElementById('msSelCount');
    if (badge) badge.textContent = count ? (count + ' مورد انتخاب شد') : '';
    
    var btnCo = document.getElementById('btnBulkMissionCheckout');
    var btnEd = document.getElementById('btnBulkMissionEdit');
    var btnDel = document.getElementById('btnBulkMissionDelete');
    
    if (btnCo) btnCo.disabled = (count === 0);
    if (btnEd) btnEd.disabled = (count === 0);
    if (btnDel) btnDel.disabled = (count === 0);
}

function openBulkMissionModal(type) {
    var checked = document.querySelectorAll('.ms-cb:checked');
    if (!checked.length) return;
    
    if (type === 'checkout') {
        var container = document.getElementById('bulk_ms_checkout_inputs');
        container.innerHTML = '';
        checked.forEach(function(cb) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'mission_ids[]';
            input.value = cb.value;
            container.appendChild(input);
        });
        document.getElementById('bulk_ms_co_count').textContent = checked.length;
        openModal('bulkMissionCheckoutModal');
    } else if (type === 'edit') {
        var container = document.getElementById('bulk_ms_edit_inputs');
        container.innerHTML = '';
        checked.forEach(function(cb) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'mission_ids[]';
            input.value = cb.value;
            container.appendChild(input);
        });
        document.getElementById('bulk_ms_ed_count').textContent = checked.length;
        openModal('bulkMissionEditModal');
    }
}

async function bulkMissionDelete() {
    var checked = document.querySelectorAll('.ms-cb:checked');
    if (!checked.length) return;
    if (await confirmAction('آیا از حذف دسته‌جمعی ' + checked.length + ' مأموریت انتخاب‌شده و ساعت‌های ثبت‌شدهٔ آن‌ها مطمئن هستید؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        var html = `<?= get_csrf_field() ?><input type="hidden" name="action" value="bulk_delete_mission"><input type="hidden" name="date" value="<?= $selected_date ?>">`;
        checked.forEach(function(cb) {
            html += `<input type="hidden" name="mission_ids[]" value="${cb.value}">`;
        });
        form.innerHTML = html;
        document.body.appendChild(form);
        form.submit();
    }
}

function filterGroupList(cb, type) {
    var show = cb.checked;
    document.querySelectorAll('#groupPersonnelList .group-person').forEach(function(el) {
        if (el.dataset.type === type) el.style.display = show ? '' : 'none';
    });
}

function filterSingleList(cb, type) {
    var show = cb.checked;
    var sel = document.getElementById('singleCheckinUser');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].dataset.type === type) {
            sel.options[i].style.display = show ? '' : 'none';
        }
    }
}

function filterReportList() {
    var type = document.getElementById('reportType').value;
    var sel = document.getElementById('reportPerson');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === '') continue;
        if (type === 'all' || sel.options[i].dataset.type === type) {
            sel.options[i].style.display = '';
        } else {
            sel.options[i].style.display = 'none';
        }
    }
}

function goToReport() {
    var uid = document.getElementById('reportPerson').value;
    if (!uid) { alert('<?= t('یک نیرو انتخاب کنید') ?>'); return; }
    window.open('index.php?page=work_report&user_id=' + uid, '_blank');
}

// ── <?= t('عملیات دسته‌جمعی') ?> ──
function getCheckedWH() {
    return Array.prototype.slice.call(document.querySelectorAll('.wh-check:checked:not(:disabled)'));
}
function updateBulkUI() {
    var checks = getCheckedWH();
    var anyOpen = checks.some(function(c) { return c.dataset.open == '1'; });
    var cnt = document.getElementById('whSelCount');
    if (cnt) cnt.textContent = checks.length ? checks.length + ' <?= t('مورد انتخاب شد') ?>' : '';
    ['btnBulkCheckout', 'btnBulkEdit', 'btnBulkDelete'].forEach(function(id) {
        var b = document.getElementById(id);
        if (b) b.disabled = checks.length === 0;
    });
    var bc = document.getElementById('btnBulkCheckout');
    if (bc) bc.disabled = !anyOpen;
    var sa = document.getElementById('whSelectAll');
    if (sa) {
        var all = document.querySelectorAll('.wh-check:not(:disabled)');
        sa.checked = all.length > 0 && checks.length === all.length;
    }
}
function toggleAllWH(master) {
    document.querySelectorAll('.wh-check:not(:disabled)').forEach(function(c) { c.checked = master.checked; });
    updateBulkUI();
}
function openBulkModal(mode) {
    var checks = getCheckedWH();
    if (!checks.length) return;
    var box = document.getElementById('bulkIds');
    box.innerHTML = '';
    checks.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'ids[]';
        inp.value = c.value;
        box.appendChild(inp);
    });
    document.getElementById('bulkCountTxt').textContent = checks.length;
    var act = document.getElementById('bulk_action');
    var title = document.getElementById('bulkModalTitle');
    var hint = document.getElementById('bulkHint');
    var btn = document.getElementById('bulkSubmitBtn');
    if (mode === 'checkout') {
        act.value = 'bulk_checkout';
        title.textContent = '⏱ <?= t('nav_logout') ?> <?= t('دسته‌جمعی') ?>';
        document.getElementById('bulkStartWrap').style.display = 'none';
        hint.textContent = '<?= t('فقط') ?> <?= t('ورودهای') ?> <?= t('بازِ') ?> <?= t('انتخاب') ?>‌<?= t('شده') ?> <?= t('با') ?> <?= t('این') ?> <?= t('wh_hours') ?> <?= t('بسته') ?> <?= t('می‌') ?><?= t('شوند') ?>.';
        btn.className = 'btn btn-success';
        btn.textContent = '<?= t('wh_checkout_title') ?>';
    } else {
        act.value = 'bulk_edit';
        title.textContent = '✏️ <?= t('btn_edit') ?> <?= t('دسته‌جمعی') ?>';
        document.getElementById('bulkStartWrap').style.display = '';
        hint.textContent = '<?= t('wh_checkout_start') ?> <?= t('و') ?> <?= t('خروجِ') ?> <?= t('همه') ?> <?= t('رکوردهای') ?> <?= t('انتخاب') ?>‌<?= t('شده') ?> <?= t('به') ?> <?= t('این') ?> <?= t('مقادیر') ?> <?= t('تغییر') ?> <?= t('می‌کند') ?>.';
        btn.className = 'btn btn-primary';
        btn.textContent = '<?= t('btn_save') ?> <?= t('btn_edit') ?>';
    }
    openModal('bulkModal');
}
function bulkDelete() {
    var checks = getCheckedWH();
    if (!checks.length) return;
    if (!confirm(checks.length + ' <?= t('رکورد') ?> <?= t('chat_delete') ?>')) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = <?= json_encode(get_csrf_field(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> + '<input type="hidden" name="action" value="bulk_delete">';
    checks.forEach(function(c) {
        f.innerHTML += '<input type="hidden" name="ids[]" value="' + c.value + '">';
    });
    document.body.appendChild(f);
    f.submit();
}

/* DISABLED: replaced by timepicker-ui CDN
// Use a predictable 24-hour text field instead of the browser's locale-dependent
// AM/PM time picker. Values remain HH:MM and are accepted by the existing PHP handlers.
(function () {
    var picker = null, activeInput = null, selectedHour = null;
    function closePicker(){ if(picker){ picker.remove(); picker=null; activeInput=null; } }
    function placePicker(){
        if(!picker||!activeInput)return;
        var r=activeInput.getBoundingClientRect(), x=Math.min(Math.max(8,r.left),window.innerWidth-296), y=r.bottom+6;
        if(y+390>window.innerHeight)y=Math.max(8,r.top-396);
        picker.style.left=x+'px'; picker.style.top=y+'px';
    }
    function makePicker(input){
        closePicker(); activeInput=input;
        var val=(input.value||'').match(/^(\d{1,2}):(\d{2})$/), h=val?parseInt(val[1],10):null, m=val?parseInt(val[2],10):0;
        picker=document.createElement('div'); picker.className='time24-picker';
        picker.innerHTML='<div class="t24-title">انتخاب ساعت ۲۴ ساعته</div><div class="t24-face"><div class="t24-hand"></div><div class="t24-center"></div></div><div class="t24-minutes"></div><div class="t24-actions"><button type="button" class="t24-clear">پاک کردن</button><button type="button" class="t24-close">بستن</button></div>';
        var face=picker.querySelector('.t24-face'), hand=picker.querySelector('.t24-hand');
        for(var i=0;i<24;i++){ var b=document.createElement('button'); b.type='button'; b.className='t24-num'; b.textContent=String(i).padStart(2,'0'); var a=(i/24)*Math.PI*2-Math.PI/2; b.style.left=(115+91*Math.cos(a))+'px'; b.style.top=(115+91*Math.sin(a))+'px'; if(i===h)b.classList.add('active'); b.onclick=(function(hour,btn){return function(){selectedHour=hour; face.querySelectorAll('.t24-num').forEach(function(x){x.classList.remove('active')});btn.classList.add('active'); hand.style.transform='translate(-50%,-100%) rotate('+(hour*15)+'deg)'; picker.querySelector('.t24-title').textContent='دقیقه را انتخاب کنید'; picker.querySelector('.t24-minutes').style.display='flex';};})(i,b); face.appendChild(b); }
        if(h!==null) hand.style.transform='translate(-50%,-100%) rotate('+(h*15)+'deg)';
        var mins=picker.querySelector('.t24-minutes'); for(var minute=0;minute<60;minute++){(function(min){var b=document.createElement('button');b.type='button';b.textContent=String(min).padStart(2,'0');b.onclick=function(){var hour=selectedHour===null?(h===null?0:h):selectedHour;input.value=String(hour).padStart(2,'0')+':'+String(min).padStart(2,'0');input.dispatchEvent(new Event('change',{bubbles:true}));closePicker();};mins.appendChild(b);})(minute);}
        if(h===null) mins.style.display='none'; else selectedHour=h;
        picker.querySelector('.t24-clear').onclick=function(){input.value='';closePicker();}; picker.querySelector('.t24-close').onclick=closePicker;
        document.body.appendChild(picker); placePicker();
    }
    function enable24HourInputs() {
        document.querySelectorAll('input[type="time"]').forEach(function (input) {
            if (input.dataset.hour24 === '1') return;
            input.dataset.hour24 = '1';
            try { input.type = 'text'; } catch (e) {}
            input.inputMode = 'numeric';
            input.placeholder = '13:00';
            input.autocomplete = 'off';
            input.setAttribute('pattern', '[0-2][0-9]:[0-5][0-9]');
            input.setAttribute('maxlength', '5');
            input.style.direction = 'ltr';
            input.style.textAlign = 'center';
            input.addEventListener('focus', function(){ makePicker(this); });
            input.addEventListener('click', function(){ makePicker(this); });
            function normalize24HourValue(el, complete) {
                var value = String(el.value || '')
                    .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
                    .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
                    .replace(/[^\d]/g, '');
                if (value.length > 4) value = value.slice(0, 4);
                if (value.length >= 3) value = value.slice(0, 2) + ':' + value.slice(2);
                el.value = value;
                if (complete && /^\d{2}:\d{2}$/.test(value)) {
                    var hh = Math.min(23, parseInt(value.slice(0, 2), 10));
                    var mm = Math.min(59, parseInt(value.slice(3, 5), 10));
                    el.value = String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
                }
            }
            input.addEventListener('input', function () { normalize24HourValue(this, false); });
            input.addEventListener('blur', function () { normalize24HourValue(this, true); });
        });
    }
    enable24HourInputs();
    new MutationObserver(enable24HourInputs).observe(document.body, { childList: true, subtree: true });
    window.addEventListener('resize',placePicker); window.addEventListener('scroll',placePicker,true);
    document.addEventListener('mousedown',function(e){if(picker && e.target!==activeInput && !picker.contains(e.target))closePicker();});
})();
*/
</script>

<?php include 'includes/footer.php'; ?>
