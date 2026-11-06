<?php
/**
 * پیوند پیمانکار با پرسنل.
 *
 * یک پیمانکار می‌تواند هم بابت خدمات ثبت‌شده و هم بابت کارکرد ساعتیِ شخص
 * متصل‌شده مطالبه داشته باشد. این توابع هر دو بخش را با یک موتور واحد جمع
 * می‌کنند تا صفحه پیمانکاران و فرم واریزی دقیقاً اعداد یکسانی نشان دهند.
 */

function subcontractor_linked_person(PDO $pdo, int $subcontractor_id): ?array {
    try {
        $st = $pdo->prepare("SELECT s.id AS subcontractor_id, s.workshop_id, s.linked_user_id,
                u.full_name, u.is_karigar,
                COALESCE(wp.daily_salary, 0) AS daily_salary,
                COALESCE(wp.hourly_salary, 0) AS hourly_salary,
                COALESCE(wp.monthly_salary, 0) AS monthly_salary
            FROM subcontractors s
            LEFT JOIN users u ON u.id = s.linked_user_id
            LEFT JOIN workshop_personnel wp ON wp.user_id = s.linked_user_id AND wp.workshop_id = s.workshop_id
            WHERE s.id = ? LIMIT 1");
        $st->execute([$subcontractor_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return ($row && !empty($row['linked_user_id'])) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function subcontractor_payroll_settings(PDO $pdo): array {
    static $settings = null;
    if ($settings !== null) return $settings;
    $settings = [];
    try {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {}
    return $settings;
}


/**
 * دستمزد پیمانکارِ پرسنلی: ساعت حضور ثبت‌شده × نرخ ساعتی.
 * ناهار ۱۳ تا ۱۴ در شیفت روز کسر می‌شود تا حضور ۸ تا ۱۷، ۸ ساعت کارکرد باشد.
 */
function subcontractor_hourly_personnel_pay(PDO $pdo, int $user_id, int $ws_id, string $g_start, string $g_end, float $hourly_rate): array {
    $rows = [];
    $hours = 0.0;
    try {
        $st = $pdo->prepare("SELECT work_date, start_time, end_time, is_night, note FROM work_hours WHERE user_id=? AND workshop_id=? AND work_date BETWEEN ? AND ? ORDER BY work_date,start_time");
        $st->execute([$user_id, $ws_id, $g_start, $g_end]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (($row['end_time'] ?? '') === '00:00:00') continue;
            $start = strtotime($row['work_date'] . ' ' . $row['start_time']);
            $end = strtotime($row['work_date'] . ' ' . $row['end_time']);
            if ($end <= $start) $end += 86400;
            $duration = max(0, ($end - $start) / 3600);
            if (empty($row['is_night'])) {
                $lunch_start = strtotime($row['work_date'] . ' 13:00:00');
                $lunch_end = strtotime($row['work_date'] . ' 14:00:00');
                $duration -= max(0, min($end, $lunch_end) - max($start, $lunch_start)) / 3600;
            }
            $hours += max(0, $duration);
        }
    } catch (Throwable $e) {}
    return ['hours' => round($hours, 2), 'base_pay' => round($hours * max(0, $hourly_rate)), 'work_rows' => $rows];
}

/**
 * جمع کارکرد/کسورات شخصِ لینک‌شده در یک بازه. is_karigar=2 نیز مانند کارگر
 * محاسبه می‌شود؛ این همان نوع «پیمانکار پرسنلی» در پروژه است.
 */
function subcontractor_linked_person_period(PDO $pdo, ?array $link, string $g_start, string $g_end): array {
    $empty = [
        'linked' => false, 'user_id' => 0, 'name' => '', 'base_pay' => 0,
        'bonus' => 0, 'penalty' => 0, 'advance' => 0, 'net' => 0, 'work_rows' => [],
    ];
    if (!$link || empty($link['linked_user_id'])) return $empty;

    $uid = (int)$link['linked_user_id'];
    $ws_id = (int)$link['workshop_id'];
    $is_worker = (int)($link['is_karigar'] ?? 0) !== 0;
    $settings = subcontractor_payroll_settings($pdo);

    $contractor_hourly = (int)($link['is_karigar'] ?? 0) === 2;
    $hourly_summary = $contractor_hourly ? subcontractor_hourly_personnel_pay($pdo, $uid, $ws_id, $g_start, $g_end, (float)($link['hourly_salary'] ?? 0)) : null;
    $base = $contractor_hourly ? (float)$hourly_summary['base_pay'] : ($is_worker
        ? worker_month_pay($pdo, $uid, $ws_id, $g_start, $g_end, (float)($link['daily_salary'] ?? 0))
        : employee_month_pay($pdo, $uid, $ws_id, $g_start, $g_end, (float)($link['monthly_salary'] ?? 0), $settings));

    $bonus = 0.0;
    $penalty = 0.0;
    try {
        $st = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) AS total
            FROM bonuses_penalties
            WHERE user_id = ? AND workshop_id = ? AND status = 'approved' AND date BETWEEN ? AND ?
            GROUP BY type");
        $st->execute([$uid, $ws_id, $g_start, $g_end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['type'] ?? '') === 'bonus') $bonus = (float)$row['total'];
            elseif (($row['type'] ?? '') === 'penalty') $penalty = (float)$row['total'];
        }
    } catch (Throwable $e) {}

    $advance = advance_deduction_month($pdo, $uid, $ws_id, $g_start, $g_end);
    $work_rows = $hourly_summary ? $hourly_summary['work_rows'] : [];
    if (!$hourly_summary) {
        try {
            $st = $pdo->prepare("SELECT work_date, start_time, end_time, is_night, note
                FROM work_hours WHERE user_id = ? AND workshop_id = ? AND work_date BETWEEN ? AND ?
                ORDER BY work_date, start_time");
            $st->execute([$uid, $ws_id, $g_start, $g_end]);
            $work_rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    return [
        'linked' => true,
        'user_id' => $uid,
        'name' => (string)($link['full_name'] ?? ''),
        'is_worker' => $is_worker,
        'is_hourly_contractor' => $contractor_hourly,
        'hourly_rate' => $contractor_hourly ? (float)($link['hourly_salary'] ?? 0) : 0,
        'worked_hours' => $contractor_hourly ? (float)$hourly_summary['hours'] : 0,
        'base_pay' => (float)$base,
        'bonus' => $bonus,
        'penalty' => $penalty,
        'advance' => (float)$advance,
        'net' => (float)$base + $bonus - $penalty - (float)$advance,
        'work_rows' => $work_rows,
    ];
}

/**
 * ماندهٔ کارکرد پرسنلی تا یک تاریخ (شامل همان روز). برای مانده اول دورهٔ فرم
 * پیمانکار استفاده می‌شود.
 */
function subcontractor_linked_person_balance_upto(PDO $pdo, ?array $link, ?string $upto): array {
    $empty = [
        'linked' => false, 'user_id' => 0, 'name' => '', 'base_pay' => 0,
        'bonus' => 0, 'penalty' => 0, 'advance' => 0, 'net' => 0,
    ];
    if (!$link || empty($link['linked_user_id'])) return $empty;

    $uid = (int)$link['linked_user_id'];
    $ws_id = (int)$link['workshop_id'];
    $upto = $upto ?: date('Y-m-d');
    $is_worker = (int)($link['is_karigar'] ?? 0) !== 0;
    $settings = subcontractor_payroll_settings($pdo);

    $first = null;
    try {
        $st = $pdo->prepare("SELECT MIN(work_date) FROM work_hours WHERE user_id = ? AND workshop_id = ? AND work_date <= ?");
        $st->execute([$uid, $ws_id, $upto]);
        $first = $st->fetchColumn() ?: null;
    } catch (Throwable $e) {}

    $base = 0.0;
    $contractor_hourly = (int)($link['is_karigar'] ?? 0) === 2;
    if ($first) {
        if ($contractor_hourly) {
            $hourly_balance = subcontractor_hourly_personnel_pay($pdo, $uid, $ws_id, $first, $upto, (float)($link['hourly_salary'] ?? 0));
            $base = (float)$hourly_balance['base_pay'];
        } elseif ($is_worker) {
            $base = worker_month_pay($pdo, $uid, $ws_id, $first, $upto, (float)($link['daily_salary'] ?? 0));
        } else {
            $base = employee_month_pay($pdo, $uid, $ws_id, $first, $upto, (float)($link['monthly_salary'] ?? 0), $settings);
        }
    }

    $bonus = 0.0;
    $penalty = 0.0;
    try {
        $st = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) AS total
            FROM bonuses_penalties
            WHERE user_id = ? AND workshop_id = ? AND status = 'approved' AND date <= ?
            GROUP BY type");
        $st->execute([$uid, $ws_id, $upto]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['type'] ?? '') === 'bonus') $bonus = (float)$row['total'];
            elseif (($row['type'] ?? '') === 'penalty') $penalty = (float)$row['total'];
        }
    } catch (Throwable $e) {}

    // advance_deduction_upto تاریخِ انتهاییِ غیرشامل می‌خواهد.
    $advance = advance_deduction_upto($pdo, $uid, $ws_id, date('Y-m-d', strtotime($upto . ' +1 day')));
    return [
        'linked' => true,
        'user_id' => $uid,
        'name' => (string)($link['full_name'] ?? ''),
        'base_pay' => (float)$base,
        'bonus' => $bonus,
        'penalty' => $penalty,
        'advance' => (float)$advance,
        'net' => (float)$base + $bonus - $penalty - (float)$advance,
    ];
}

/**
 * ماندهٔ کل پیمانکار: مطالبات قدیمی + خدمات روزانه + کارکرد ساعتیِ فرد متصل +
 * ماندهٔ اول دوره − پرداخت‌ها.
 */
function sub_balance_upto(PDO $pdo, int $sid, ?string $upto): array {
    $face_sql = "SELECT COALESCE(SUM(f.amount),0) FROM subcontractor_face_items f WHERE f.subcontractor_id = ?";
    $daily_sql = "SELECT COALESCE(SUM(di.total_price),0) FROM subcontractor_daily_entry_items di JOIN subcontractor_daily_entries de ON di.entry_id = de.id WHERE de.subcontractor_id = ?";
    $paid_sql = "SELECT COALESCE(SUM(amount),0) FROM subcontractor_payments WHERE subcontractor_id = ?";
    $params = [$sid];
    if ($upto) {
        $face_sql .= " AND f.date <= ?";
        $daily_sql .= " AND de.entry_date <= ?";
        $paid_sql .= " AND payment_date <= ?";
        $params[] = $upto;
    }

    $face = 0.0; $daily = 0.0; $paid = 0.0; $opening = 0.0;
    try { $st = $pdo->prepare($face_sql);  $st->execute($params); $face = (float)$st->fetchColumn(); } catch (Throwable $e) {}
    try { $st = $pdo->prepare($daily_sql); $st->execute($params); $daily = (float)$st->fetchColumn(); } catch (Throwable $e) {}
    try { $st = $pdo->prepare($paid_sql);  $st->execute($params); $paid = (float)$st->fetchColumn(); } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT COALESCE(opening_balance,0) FROM subcontractors WHERE id = ?");
        $st->execute([$sid]);
        $opening = (float)$st->fetchColumn();
    } catch (Throwable $e) {}

    $link = subcontractor_linked_person($pdo, $sid);
    $personnel = subcontractor_linked_person_balance_upto($pdo, $link, $upto);
    $claims = $face + $daily + (float)$personnel['net'];
    return [
        'face' => $face,
        'daily' => $daily,
        'personnel' => $personnel,
        'personnel_claim' => (float)$personnel['net'],
        'paid' => $paid,
        'opening' => $opening,
        'claims' => $claims,
        'balance' => $claims + $opening - $paid,
    ];
}
