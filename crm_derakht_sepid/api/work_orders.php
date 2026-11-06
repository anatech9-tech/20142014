<?php
/**
 * Work Orders API - دستور کار
 */
ob_start();
require_once __DIR__ . '/../config.php';

if (!is_logged_in()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => t('لطفاً ابتدا وارد شوید')]);
    exit;
}

$ws_id = $_SESSION['workshop_id'] ?? 0;
$user_id = $_SESSION['user_id'];

if (!$ws_id) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => t('کارگاه انتخاب نشده')]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!function_exists('api_response')) {
    function api_response($data) {
        if (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
           || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? '')) {
    if (!$is_ajax && empty($_GET['ajax'])) {
        showToast(t('درخواست نامعتبر یا منقضی است. صفحه را دوباره باز کنید.'), 'error');
        redirect('index.php?page=work_orders');
    }
    api_response(['error' => t('درخواست نامعتبر یا منقضی است. صفحه را دوباره باز کنید.')]);
}

// ── Create work order ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $teams = [];
    if (!empty($_POST['target_teams']) && is_array($_POST['target_teams'])) {
        $teams = array_intersect($_POST['target_teams'], ['ejra', 'hse', 'anbar']);
    } elseif (!empty($_POST['target_team']) && in_array($_POST['target_team'], ['ejra', 'hse', 'anbar'], true)) {
        $teams = [$_POST['target_team']];
    }

    if (empty($teams)) {
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('حداقل یک واحد را انتخاب کنید'), 'error');
            redirect('index.php?page=work_orders');
        }
        api_response(['error' => t('تیم نامعتبر')]);
    }

    $order_date_input = $_POST['order_date'] ?? date('Y-m-d');

    if (strpos($order_date_input, '/') !== false) {
        $parts = explode('/', $order_date_input);
        $g = jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        $order_date_g = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
    } else {
        $order_date_g = $order_date_input;
    }

    $shift_type = $_POST['shift'] ?? 'day';
    if (!in_array($shift_type, ['day', 'night'])) $shift_type = 'day';

    $descriptions = $_POST['item_description'] ?? [];
    $progresses = $_POST['item_progress'] ?? [];
    $next_days = $_POST['item_next_day'] ?? [];

    $items = [];
    for ($i = 0; $i < count($descriptions); $i++) {
        $desc = trim($descriptions[$i] ?? '');
        if (empty($desc)) continue;
        $items[] = [
            'row_num' => $i + 1,
            'description' => $desc,
            'progress' => trim($progresses[$i] ?? ''),
            'next_day' => trim($next_days[$i] ?? ''),
        ];
    }

    if (empty($items)) {
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('حداقل یک ردیف فعالیت الزامی است'), 'error');
            redirect('index.php?page=work_orders');
        }
        api_response(['error' => t('حداقل یک ردیف فعالیت الزامی است')]);
    }

    try {
        $pdo->beginTransaction();
        $order_id = 0;
        foreach ($teams as $target_team) {
            $stmt = $pdo->prepare("INSERT INTO work_orders (workshop_id, created_by, target_team, shift_type, order_date, items) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ws_id, $user_id, $target_team, $shift_type, $order_date_g, json_encode($items, JSON_UNESCAPED_UNICODE)]);
            $order_id = $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("INSERT INTO work_order_items (work_order_id, row_num, description, progress, next_day) VALUES (?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $itemStmt->execute([$order_id, $item['row_num'], $item['description'], $item['progress'], $item['next_day']]);
            }
        }

        $pdo->commit();
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('دستور کار با موفقیت ارسال شد'));
            redirect('index.php?page=work_orders&tab=' . urlencode($teams[0]));
        }
        api_response(['success' => true, 'id' => $order_id]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('خطا در ذخیره دستور کار: ') . $e->getMessage(), 'error');
            redirect('index.php?page=work_orders');
        }
        api_response(['error' => t('خطا در ذخیره') . ': ' . $e->getMessage()]);
    }
}

// ── Complete a single item ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'complete_item') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $is_completed = (int)($_POST['is_completed'] ?? 0);
    $progress = sanitize($_POST['progress'] ?? '');
    $next_day = sanitize($_POST['next_day'] ?? '');

    try {
        $stmt = $pdo->prepare("SELECT woi.*, wo.workshop_id, wo.order_date, wo.target_team, wo.shift_type FROM work_order_items woi JOIN work_orders wo ON woi.work_order_id = wo.id WHERE woi.id = ?");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();

        if (!$item) {
            api_response(['error' => t('ردیف یافت نشد') . ': item_id=' . $item_id]);
        }
        if ($item['workshop_id'] != $ws_id) {
            api_response(['error' => t('دسترسی غیرمجاز') . ': ws mismatch']);
        }

        $completed_by = $is_completed ? $user_id : null;
        $completed_at = $is_completed ? date('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare("UPDATE work_order_items SET is_completed = ?, completed_by = ?, completed_at = ?, progress = ?, next_day = ? WHERE id = ?");
        $stmt->execute([$is_completed, $completed_by, $completed_at, $progress, $next_day, $item_id]);

        // Auto-add to daily report when completed — هر آیتم یک ردیف مستقل در گزارش روزِ انجام
        $report_date = $item['order_date'];
        if ($is_completed) {
            if (!db_has_column($pdo, 'daily_reports', 'shift') || !db_has_column($pdo, 'daily_reports', 'source_item_id')) {
                api_response(['error' => t('ابتدا یک‌بار migrate.php را در مرورگر اجرا کنید بعد دوباره تلاش کنید')]);
            }

            // شیفت گزارش = شیفت خود دستور کار (دستور شب → گزارش شیفت شب)
            $rep_shift = in_array($item['shift_type'] ?? '', ['day', 'night']) ? $item['shift_type'] : 'day';
            // تاریخ گزارش = روزِ انجام؛ اگر شیفت شب است و قبل از ظهر انجام شده → متعلق به شبِ روز قبل
            $done_ts = time();
            $report_date = date('Y-m-d', $done_ts);
            if ($rep_shift === 'night' && (int)date('G', $done_ts) < 12) {
                $report_date = date('Y-m-d', $done_ts - 43200);
            }

            $order_date = $item['order_date'];
            $category = in_array($item['target_team'] ?? '', ['ejra', 'hse', 'anbar']) ? $item['target_team'] : 'ejra';
            $shift_icon = ($rep_shift === 'night') ? '🌙' : '☀️';

            // اگر دستور مربوط به روزهای قبل است، تاریخ صدورش هم ذکر شود
            if ($order_date && $order_date < $report_date) {
                $report_text = '📋 ' . t('از دستور کار') . ' ' . to_jalali($order_date) . " ({$shift_icon} " . t('انجام شد') . "):\n{$item['description']}";
            } else {
                $report_text = '📋 ' . t('دستور کار انجام شده') . " ({$shift_icon}):\n{$item['description']}";
            }
            if ($progress) $report_text .= "\n" . t('پیشرفت:') . " {$progress}";
            if ($next_day) $report_text .= "\n" . t('پیش‌بینی روز بعد:') . " {$next_day}";

            // اگر قبلاً برای همین آیتم ردیف گزارش ساخته شده → به‌روزرسانی (نه تکرار)
            $stmt3 = $pdo->prepare("SELECT id FROM daily_reports WHERE source_item_id = ? AND workshop_id = ?");
            $stmt3->execute([$item_id, $ws_id]);
            $existing = $stmt3->fetch();

            if ($existing) {
                $pdo->prepare("UPDATE daily_reports SET user_id = ?, report_date = ?, report_text = ?, wo_description = ?, wo_progress = ?, wo_next_day = ?, category = ?, shift = ? WHERE id = ?")
                    ->execute([$user_id, $report_date, $report_text, $item['description'], $progress, $next_day, $category, $rep_shift, $existing['id']]);
                $__rid = (int)$existing['id'];
            } else {
                $pdo->prepare("INSERT INTO daily_reports (user_id, workshop_id, report_date, report_text, wo_description, wo_progress, wo_next_day, category, shift, source_item_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, $ws_id, $report_date, $report_text, $item['description'], $progress, $next_day, $category, $rep_shift, $item_id]);
                $__rid = (int)$pdo->lastInsertId();
            }
            // §fix: گزارش دستور کار بدون تأیید مدیر نهایی و آرش‌کوین پرداخت می‌شود
            $__auto = daily_report_auto_approve($pdo, $__rid, (int)$user_id);
        } else {
            // پس‌گرفتن تیک «انجام شد» → حذف از گزارش روزانه
            if (db_has_column($pdo, 'daily_reports', 'source_item_id')) {
                // §fix: آرش‌کوینِ گزارش هم با حذف گزارش برگردد
                $__old = $pdo->prepare("SELECT id FROM daily_reports WHERE source_item_id = ? AND workshop_id = ?");
                $__old->execute([$item_id, $ws_id]);
                foreach ($__old->fetchAll(PDO::FETCH_COLUMN) as $__oid) daily_report_reverse_coins($pdo, (int)$__oid, (int)$user_id, 'برگشت وضعیت انجام کار');
                $pdo->prepare("DELETE FROM daily_reports WHERE source_item_id = ? AND workshop_id = ?")->execute([$item_id, $ws_id]);
            }
        }


        // Check if all items completed
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(is_completed) as done FROM work_order_items WHERE work_order_id = ?");
        $stmt->execute([$item['work_order_id']]);
        $counts = $stmt->fetch();

        if ($counts['total'] == $counts['done']) {
            $pdo->prepare("UPDATE work_orders SET status = 'completed', completed_by = ?, completed_at = NOW() WHERE id = ?")->execute([$user_id, $item['work_order_id']]);
        } else {
            $pdo->prepare("UPDATE work_orders SET status = 'pending', completed_by = NULL, completed_at = NULL WHERE id = ?")->execute([$item['work_order_id']]);
        }

        // پاداش دستورکار پس از «تأیید گزارش متصل» توسط مدیر داده می‌شود، نه صرفاً با تیک انجام شد.
        // با برداشتن تیک نیز هر پاداشِ تأییدشدهٔ قبلی پاک می‌شود.
        $coin_award = ($is_completed && !empty($__auto['task_coin'])) ? $__auto['task_coin'] : null;
        if (!$is_completed) arash_coin_reverse_work_order_item($pdo, $item_id, $user_id, 'برگشت وضعیت انجام کار');

        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast($is_completed ? t('کار به عنوان انجام‌شده ثبت شد') : t('وضعیت انجام کار برگشت داده شد'));
            redirect('index.php?page=work_orders');
        }

        api_response(['success' => true, 'is_completed' => $is_completed, 'report_date' => $report_date ?? ($item['order_date'] ?? ''), 'coin_award' => $coin_award]);
    } catch (PDOException $e) {
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('خطا: ') . $e->getMessage(), 'error');
            redirect('index.php?page=work_orders');
        }
        api_response(['error' => t('خطا') . ': ' . $e->getMessage()]);
    }
}

// ── Save manual report ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_manual_report') {
    $report_date_input = $_POST['report_date'] ?? date('Y-m-d');
    $category = $_POST['category'] ?? '';
    if (!in_array($category, ['ejra', 'hse', 'anbar'])) $category = current_user_team($pdo, $user_id);
    $report_text = sanitize($_POST['report_text'] ?? '');
    $wo_desc = sanitize($_POST['wo_description'] ?? '');
    $wo_prog = sanitize($_POST['wo_progress'] ?? '');
    $wo_next = sanitize($_POST['wo_next_day'] ?? '');

    if (strpos($report_date_input, '/') !== false) {
        $parts = explode('/', $report_date_input);
        $g = jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        $report_date_g = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
    } else {
        $report_date_g = $report_date_input;
    }

    if (empty($report_text) && empty($wo_desc)) {
        api_response(['error' => t('گزارش خالی است')]);
    }

    // If wo_desc is empty, extract from report_text
    if (empty($wo_desc) && !empty($report_text)) {
        $wo_desc = $report_text;
        $wo_prog = '';
        $wo_next = '';
    }

    // Build report_text for display
    $display_text = '';
    if ($wo_desc) {
        $display_text = '📋 ' . $wo_desc;
        if ($wo_prog) $display_text .= "\n" . t('پیشرفت:') . ' ' . $wo_prog;
        if ($wo_next) $display_text .= "\n" . t('پیش‌بینی روز بعد:') . ' ' . $wo_next;
    } elseif ($report_text) {
        $display_text = $report_text;
    }

    // تشخیص شیفت گزارش‌دهنده (با سازگاری کامل اگر migrate هنوز اجرا نشده)
    $dr_has_shift = db_has_column($pdo, 'daily_reports', 'shift');
    $dr_has_src   = db_has_column($pdo, 'daily_reports', 'source_item_id');
    $rep_shift = $dr_has_shift ? detect_report_shift($pdo, $ws_id, $user_id, $report_date_g) : 'day';

    try {
        // ادغام فقط با گزارش‌های دستیِ همان نفر/روز/واحد/شیفت — هرگز با ردیفِ متعلق به آیتم دستور کار
        $match_sql = "SELECT id FROM daily_reports WHERE user_id = ? AND workshop_id = ? AND report_date = ? AND category = ?";
        $match_params = [$user_id, $ws_id, $report_date_g, $category];
        if ($dr_has_shift) { $match_sql .= " AND shift = ?"; $match_params[] = $rep_shift; }
        if ($dr_has_src)   { $match_sql .= " AND source_item_id IS NULL"; }
        $stmt = $pdo->prepare($match_sql);
        $stmt->execute($match_params);
        $existing = $stmt->fetch();

        if ($existing) {
            // join با جداکننده واقعی newline (بدون باگ بک‌اسلش)
            $pdo->prepare("UPDATE daily_reports SET report_text = CONCAT(COALESCE(report_text,''), ?, ?), wo_description = CONCAT(COALESCE(wo_description,''), ?, ?), wo_progress = CONCAT(COALESCE(wo_progress,''), ?, ?), wo_next_day = CONCAT(COALESCE(wo_next_day,''), ?, ?) WHERE id = ?")
                ->execute([chr(10).chr(10), $display_text, chr(10), $wo_desc, chr(10), $wo_prog, chr(10), $wo_next, $existing['id']]);
            $__rid = (int)$existing['id'];
        } elseif ($dr_has_shift) {
            $pdo->prepare("INSERT INTO daily_reports (user_id, workshop_id, report_date, report_text, wo_description, wo_progress, wo_next_day, category, shift) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$user_id, $ws_id, $report_date_g, $display_text, $wo_desc, $wo_prog, $wo_next, $category, $rep_shift]);
            $__rid = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare("INSERT INTO daily_reports (user_id, workshop_id, report_date, report_text, wo_description, wo_progress, wo_next_day, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$user_id, $ws_id, $report_date_g, $display_text, $wo_desc, $wo_prog, $wo_next, $category]);
            $__rid = (int)$pdo->lastInsertId();
        }
        // §fix: گزارش کار بدون تأیید مدیر نهایی و آرش‌کوین پرداخت می‌شود
        daily_report_auto_approve($pdo, $__rid, (int)$user_id);

        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('گزارش کار با موفقیت ثبت شد و به فرم گزارش روزانه اضافه شد'));
            redirect('index.php?page=work_orders&tab=' . urlencode($category));
        }

        api_response(['success' => true]);
    } catch (PDOException $e) {
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('خطا در ثبت گزارش: ') . $e->getMessage(), 'error');
            redirect('index.php?page=work_orders&tab=' . urlencode($category));
        }
        api_response(['error' => t('خطا') . ': ' . $e->getMessage()]);
    }
}

// ── Delete work order (admin/nazer only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_order') {
    $order_id = (int)($_POST['order_id'] ?? 0);

    if (!is_strict_admin() && !is_nazer()) {
        api_response(['error' => t('دسترسی غیرمجاز')]);
    }

    try {
        // پاداش آیتم‌های تکمیل‌شده هم با حذف دستورکار برگردد تا کیف پول دقیق بماند.
        try {
            $coinItems = $pdo->prepare("SELECT woi.id FROM work_order_items woi JOIN work_orders wo ON wo.id = woi.work_order_id WHERE woi.work_order_id = ? AND wo.workshop_id = ? AND woi.is_completed = 1");
            $coinItems->execute([$order_id, $ws_id]);
            foreach ($coinItems->fetchAll(PDO::FETCH_COLUMN) as $coinItemId) {
                arash_coin_reverse_work_order_item($pdo, (int)$coinItemId, $user_id, 'حذف دستور کار');
            }
        } catch (Throwable $ignore) {}
        // پاداش گزارش‌های تأییدشدهٔ متصل هم پیش از حذف برگردد.
        if (db_has_column($pdo, 'daily_reports', 'source_item_id')) {
            try {
                $coinReports = $pdo->prepare("SELECT dr.id, dr.status FROM daily_reports dr JOIN work_order_items woi ON dr.source_item_id=woi.id WHERE woi.work_order_id=? AND dr.workshop_id=?");
                $coinReports->execute([$order_id,$ws_id]);
                foreach ($coinReports->fetchAll(PDO::FETCH_ASSOC) as $coinReport) if (($coinReport['status'] ?? '') === 'approved') arash_coin_reverse_daily_report($pdo,(int)$coinReport['id'],$user_id,'حذف دستور کار');
            } catch (Throwable $ignore) {}
        }
        // ردیف‌های گزارش روزانه متصل به آیتم‌های این دستور هم حذف شود
        if (db_has_column($pdo, 'daily_reports', 'source_item_id')) {
            $pdo->prepare("DELETE dr FROM daily_reports dr JOIN work_order_items woi ON dr.source_item_id = woi.id WHERE woi.work_order_id = ? AND dr.workshop_id = ?")->execute([$order_id, $ws_id]);
        }
        $pdo->prepare("DELETE FROM work_order_items WHERE work_order_id = ?")->execute([$order_id]);
        $pdo->prepare("DELETE FROM work_orders WHERE id = ? AND workshop_id = ?")->execute([$order_id, $ws_id]);
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('دستور کار حذف شد'));
            redirect('index.php?page=work_orders');
        }
        api_response(['success' => true]);
    } catch (PDOException $e) {
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('خطا: ') . $e->getMessage(), 'error');
            redirect('index.php?page=work_orders');
        }
        api_response(['error' => t('خطا') . ': ' . $e->getMessage()]);
    }
}

// ── Delete a single item ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_item') {
    $item_id = (int)($_POST['item_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("SELECT woi.*, wo.workshop_id FROM work_order_items woi JOIN work_orders wo ON woi.work_order_id = wo.id WHERE woi.id = ?");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();

        if (!$item || $item['workshop_id'] != $ws_id) {
            api_response(['error' => t('ردیف یافت نشد')]);
        }

        if (!empty($item['is_completed'])) {
            arash_coin_reverse_work_order_item($pdo, $item_id, $user_id, 'حذف آیتم دستور کار');
        }
        if (db_has_column($pdo, 'daily_reports', 'source_item_id')) {
            try {
                $coinReports = $pdo->prepare("SELECT id,status FROM daily_reports WHERE source_item_id=? AND workshop_id=?");
                $coinReports->execute([$item_id,$ws_id]);
                foreach ($coinReports->fetchAll(PDO::FETCH_ASSOC) as $coinReport) if (($coinReport['status'] ?? '') === 'approved') arash_coin_reverse_daily_report($pdo,(int)$coinReport['id'],$user_id,'حذف آیتم دستور کار');
            } catch (Throwable $ignore) {}
        }
        $pdo->prepare("DELETE FROM work_order_items WHERE id = ?")->execute([$item_id]);
        if (db_has_column($pdo, 'daily_reports', 'source_item_id')) {
            $pdo->prepare("DELETE FROM daily_reports WHERE source_item_id = ? AND workshop_id = ?")->execute([$item_id, $ws_id]);
        }
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('ردیف حذف شد'));
            redirect('index.php?page=work_orders');
        }
        api_response(['success' => true]);
    } catch (PDOException $e) {
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('خطا: ') . $e->getMessage(), 'error');
            redirect('index.php?page=work_orders');
        }
        api_response(['error' => t('خطا') . ': ' . $e->getMessage()]);
    }
}

// ── Get work orders for admin ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $filter_date = $_GET['date'] ?? '';
    $where = "wo.workshop_id = ?";
    $params = [$ws_id];
    if ($filter_date) { $where .= " AND wo.order_date = ?"; $params[] = $filter_date; }

    $stmt = $pdo->prepare("SELECT wo.*, u.full_name as creator_name FROM work_orders wo JOIN users u ON wo.created_by = u.id WHERE $where ORDER BY wo.created_at DESC LIMIT 50");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $itemStmt = $pdo->prepare("SELECT woi.*, u.full_name as completed_by_name FROM work_order_items woi LEFT JOIN users u ON woi.completed_by = u.id WHERE woi.work_order_id = ? ORDER BY woi.row_num");
    foreach ($orders as &$o) {
        $itemStmt->execute([$o['id']]);
        $o['items'] = $itemStmt->fetchAll();
        $completed = 0;
        foreach ($o['items'] as $item) { if ($item['is_completed']) $completed++; }
        $o['completed_count'] = $completed;
        $o['total_count'] = count($o['items']);
    }

    api_response($orders);
}

// ── Edit a single item (admin/nazer) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_item') {
    if (!is_strict_admin() && !is_nazer()) {
        api_response(['error' => t('دسترسی غیرمجاز')]);
    }
    $item_id = (int)($_POST['item_id'] ?? 0);
    $desc = trim((string)($_POST['description'] ?? ''));
    $progress = trim((string)($_POST['progress'] ?? ''));
    $next_day = trim((string)($_POST['next_day'] ?? ''));
    if ($desc === '') api_response(['error' => t('شرح فعالیت خالی است')]);

    try {
        $stmt = $pdo->prepare("SELECT woi.*, wo.workshop_id FROM work_order_items woi JOIN work_orders wo ON woi.work_order_id = wo.id WHERE woi.id = ?");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();
        if (!$item || $item['workshop_id'] != $ws_id) api_response(['error' => t('ردیف یافت نشد')]);

        $pdo->prepare("UPDATE work_order_items SET description = ?, progress = ?, next_day = ? WHERE id = ?")
            ->execute([$desc, $progress, $next_day, $item_id]);

        // ردیف گزارش مرتبط هم همگام شود
        if (db_has_column($pdo, 'daily_reports', 'source_item_id')) {
            $st2 = $pdo->prepare("SELECT id FROM daily_reports WHERE source_item_id = ? AND workshop_id = ?");
            $st2->execute([$item_id, $ws_id]);
            if ($rep2 = $st2->fetch()) {
                $txt = '📋 ' . t('دستور کار انجام شده') . " :\n{$desc}";
                if ($progress !== '') $txt .= "\n" . t('پیشرفت:') . " {$progress}";
                if ($next_day !== '') $txt .= "\n" . t('پیش‌بینی روز بعد:') . " {$next_day}";
                $pdo->prepare("UPDATE daily_reports SET report_text = ?, wo_description = ?, wo_progress = ?, wo_next_day = ? WHERE id = ?")
                    ->execute([$txt, $desc, $progress, $next_day, $rep2['id']]);
            }
        }
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('ردیف ویرایش شد'));
            redirect('index.php?page=work_orders');
        }
        api_response(['success' => true]);
    } catch (PDOException $e) {
        if (!$is_ajax && empty($_GET['ajax'])) {
            showToast(t('خطا: ') . $e->getMessage(), 'error');
            redirect('index.php?page=work_orders');
        }
        api_response(['error' => t('خطا') . ': ' . $e->getMessage()]);
    }
}

api_response(['error' => t('action نامعتبر')]);
