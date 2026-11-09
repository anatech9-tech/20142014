<?php
/**
 * WTTP COIN — حذف شد (۲۰۲۶-۱۱-۰۸)
 * ─────────────────────────────────
 * سیستم «کوین» از محصول درخت سپید تجارت پارس خارج شده است. این فایل فقط
 * باقی‌ماندهٔ امضای توابع است تا صفحات قدیمی (حقوق/انبار/گزارش روزانه) که
 * هنوز آن‌ها را صدا می‌زنند، بدون خطای «تابع تعریف‌نشده» اجرا شوند و هیچ
 * اثری ایجاد نکنند. هیچ چیزی در پایگاه داده خوانده یا نوشته نمی‌شود.
 *
 * جدولی از WTTP COIN در نصب تازه دیگر ساخته نمی‌شود؛ روی هاست‌های قدیمی
 * جدول‌ها دست نمی‌خورند و حذف نمی‌شوند.
 */

function arash_coin_tables_ready(PDO $pdo): bool { return false; }
function arash_coin_v16_ready(PDO $pdo): bool { return false; }
function arash_coin_has_event_date(PDO $pdo): bool { return false; }
function arash_coin_icon_markup(string $class = 'arash-coin-tiny', string $alt = ''): string { return ''; }
function arash_coin_setting(PDO $pdo, string $key, $default = null) { return $default; }
function arash_coin_default_task_value(PDO $pdo): int { return 0; }
function arash_coin_balance(PDO $pdo, int $user_id, ?int $workshop_id = null): int { return 0; }
function arash_coin_capped_types(): array { return []; }
function arash_coin_tx_date_expression(PDO $pdo, string $alias = 't'): string { return "t.created_at"; }
function arash_coin_daily_limit_status(PDO $pdo, int $user_id, ?string $event_date = null, ?string $exclude_source = null): array { return ['used' => 0, 'limit' => 0, 'remaining' => 0]; }
function arash_coin_cap_event_amount(PDO $pdo, int $user_id, string $event_type, int $requested, string $event_date, string $source_key): int { return 0; }
function arash_coin_add_transaction(PDO $pdo, int $user_id, int $amount, string $type, string $description, ?int $workshop_id = null, ?int $created_by = null, ?string $source_key = null, ?int $rule_id = null, ?string $event_date = null): ?int { return null; }
function arash_coin_set_event_amount(PDO $pdo, int $user_id, int $amount, string $type, string $description, int $workshop_id, string $source_key, ?int $created_by = null, ?int $rule_id = null, ?string $event_date = null): ?array { return null; }
function arash_coin_event_rule(PDO $pdo, int $workshop_id, string $event_type, string $subject, string $team, string $event_date): ?array { return null; }
function arash_coin_active_challenges(PDO $pdo, int $workshop_id, string $event_type, string $subject, string $team, string $event_date): array { return []; }
function arash_coin_event_type_label(string $type): string { return ''; }
function arash_coin_award_event(PDO $pdo, int $user_id, int $workshop_id, string $event_type, int $base_coins, string $description, string $event_date, string $source_key, string $subject = '', string $team = '', ?int $created_by = null): ?array { return null; }
function arash_coin_remove_event(PDO $pdo, string $source_key): void {}
function arash_coin_task_rule(PDO $pdo, int $workshop_id, string $task_text, string $team, string $event_date): ?array { return null; }
function arash_coin_award_work_order_item(PDO $pdo, int $item_id, int $approved_by): ?array { return null; }
function arash_coin_reverse_work_order_item(PDO $pdo, int $item_id, ?int $actor_id = null, string $reason = ''): ?array { return null; }
function arash_coin_award_daily_report(PDO $pdo, int $report_id, int $approved_by): ?array { return null; }
function arash_coin_reverse_daily_report(PDO $pdo, int $report_id, ?int $actor_id = null, string $reason = ''): ?array { return null; }
function arash_coin_work_hour_metrics(array $wh): array { return ['early' => 0, 'overtime' => 0, 'night' => 0]; }
function arash_coin_sync_work_hour_rewards(PDO $pdo, int $work_hour_id, ?int $actor_id = null): array { return []; }
function arash_coin_remove_work_hour_rewards(PDO $pdo, int $work_hour_id): void {}
function arash_coin_award_inventory_activity(PDO $pdo, int $user_id, int $workshop_id, string $source_key, string $description, ?string $event_date = null): ?array { return null; }
function arash_coin_run_monthly_leave_rewards(PDO $pdo, int $workshop_id, ?string $as_of = null): int { return 0; }
function arash_coin_active_multiplier_notices(PDO $pdo, int $workshop_id, ?string $date = null): array { return []; }
function arash_coin_week_bounds(?string $date = null): array { $date = $date ?: date('Y-m-d'); $w = (int)date('w', strtotime($date)); $since_sat = ($w + 1) % 7; $start = date('Y-m-d', strtotime($date . " -{$since_sat} day")); return [$start, date('Y-m-d', strtotime($start . ' +6 day'))]; }
function arash_coin_month_bounds(?string $date = null): array { $date = $date ?: date('Y-m-d'); if (function_exists('jalali_month_bounds') && function_exists('gregorian_to_jalali')) { $j = gregorian_to_jalali((int)substr($date, 0, 4), (int)substr($date, 5, 2), (int)substr($date, 8, 2)); return jalali_month_bounds((int)$j[0], (int)$j[1]); } return [date('Y-m-01'), $date]; }
function arash_coin_period_leaderboard(PDO $pdo, int $workshop_id, string $start, string $end, int $limit = 10): array { return []; }
function arash_coin_period_badges(PDO $pdo, int $workshop_id, string $start, string $end): array { return []; }
function arash_coin_team_leaderboard(PDO $pdo, int $workshop_id, string $start, string $end): array { return []; }

/** تأیید خودکار گزارش روزانه: فقط وضعیت را approved می‌کند؛ کوینی در کار نیست. */
function daily_report_auto_approve(PDO $pdo, int $report_id, int $actor_id): array {
    $out = ['coin' => null, 'task_coin' => null];
    if ($report_id <= 0) return $out;
    try {
        if (function_exists('db_has_column') && db_has_column($pdo, 'daily_reports', 'status')) {
            $sets = ["status = 'approved'"]; $params = [];
            if (db_has_column($pdo, 'daily_reports', 'approved_by')) { $sets[] = 'approved_by = ?'; $params[] = $actor_id; }
            if (db_has_column($pdo, 'daily_reports', 'approved_at')) { $sets[] = 'approved_at = NOW()'; }
            $params[] = $report_id;
            $pdo->prepare("UPDATE daily_reports SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }
    } catch (Throwable $e) { /* بدون خطا به کاربر */ }
    return $out;
}

/** پیش از حذف گزارش روزانه صدا زده می‌شود؛ دیگر چیزی برای برگرداندن وجود ندارد. */
function daily_report_reverse_coins(PDO $pdo, int $report_id, ?int $actor_id = null, string $reason = ''): void {}
