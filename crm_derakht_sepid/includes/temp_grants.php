<?php
/**
 * دسترسی موقت و محدود ثبت ساعت کاری.
 * هر مجوز دقیقاً به یک کاربر، کارگاه، تاریخ و شیفت (روز/شب) تعلق دارد.
 */

function temp_grants_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS temp_entry_grants (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) NOT NULL,
        workshop_id INT(11) NOT NULL,
        grant_date DATE NOT NULL,
        shift_type ENUM('day','night') NOT NULL DEFAULT 'day',
        created_by INT(11) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        KEY idx_tg_user (user_id, revoked_at),
        KEY idx_tg_scope (workshop_id, grant_date, shift_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ارتقای بی‌خطر نسخه‌های قبلی.
    if (function_exists('db_has_column') && !db_has_column($pdo, 'temp_entry_grants', 'shift_type')) {
        $pdo->exec("ALTER TABLE temp_entry_grants ADD COLUMN shift_type ENUM('day','night') NOT NULL DEFAULT 'day' AFTER grant_date");
    }
    // کلید قدیمی اجازهٔ دو مجوز روز و شب برای یک تاریخ را نمی‌داد.
    try { $pdo->exec("ALTER TABLE temp_entry_grants DROP INDEX uq_grant"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE temp_entry_grants ADD UNIQUE KEY uq_grant_shift (user_id, workshop_id, grant_date, shift_type)"); } catch (Throwable $e) {}
}

/** بازهٔ اعتبار: روز ۰۶ تا ۲۲؛ شب ۱۸ همان روز تا ۱۰ صبح فردا. */
function temp_grant_window(string $grant_date, string $shift_type = 'day'): array {
    if ($shift_type === 'night') {
        return [$grant_date . ' 18:00:00', date('Y-m-d 10:00:00', strtotime($grant_date . ' +1 day'))];
    }
    return [$grant_date . ' 06:00:00', $grant_date . ' 22:00:00'];
}

function temp_grant_status(array $g): string {
    if (!empty($g['revoked_at'])) return 'revoked';
    [$starts, $ends] = temp_grant_window((string)$g['grant_date'], (string)($g['shift_type'] ?? 'day'));
    $now = date('Y-m-d H:i:s');
    if ($now < $starts) return 'scheduled';
    if ($now >= $ends) return 'expired';
    return 'active';
}

function temp_grant_active(PDO $pdo, int $user_id, int $workshop_id = 0): ?array {
    temp_grants_ensure_table($pdo);
    $sql = "SELECT * FROM temp_entry_grants WHERE user_id = ? AND revoked_at IS NULL";
    $params = [$user_id];
    if ($workshop_id > 0) { $sql .= " AND workshop_id = ?"; $params[] = $workshop_id; }
    $sql .= " ORDER BY grant_date DESC, id DESC LIMIT 20";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll() as $g) if (temp_grant_status($g) === 'active') return $g;
    return null;
}

function temp_grant_shift_label(array $g): string {
    return (($g['shift_type'] ?? 'day') === 'night') ? 'شیفت شب' : 'شیفت روز';
}

/**
 * آیا این رکورد ساعت (start_time/end_time/is_night) به شیفت مجوز تعلق دارد؟
 * شب: پرچم شب، شروع از ۱۸ تا ۰۶، یا خروج قبل از ورود؛ در غیر این صورت روز.
 */
function temp_grant_row_in_shift(array $r, string $shift): bool {
    $h = (int)substr((string)$r['start_time'], 0, 2);
    $end = (string)($r['end_time'] ?? '00:00:00');
    $night = !empty($r['is_night']) || $h >= 18 || $h < 6 || ($end !== '00:00:00' && $end < (string)$r['start_time']);
    return $shift === 'night' ? $night : !$night;
}
