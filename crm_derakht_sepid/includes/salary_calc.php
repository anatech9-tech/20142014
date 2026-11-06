<?php
/**
 * موتور محاسبه حقوق — مطابق مستند «payroll_rules_spec_fa»
 *
 * - ساعت کاری کارگاه 08:00 تا 17:00 — ناهار 13:00 تا 14:00 جزو حقوق است و کسر نمی‌شود
 * - کارمند: عادی 07:00-17:00 ضریب ۱ | 17:00-21:00 ضریب ۱.۳۳ | شب ۲۱:00-۷:00 ضریب ۲
 *           جمعه ۱.۳۳ | تعطیل رسمی ۲ | هر دقیقه فقط با یک ضریب (بالاترین) حساب می‌شود
 * - کارگر: بدون ضریب — ساعت قابل‌پرداخت: جمعه ×1.8 | شبِ کمتر از ۴ ساعت = ۴ ساعت | تعطیل رسمی = عادی
 * - قانون روز بعد شب‌کاری: ورود 07:00-13:00 → 08:00 محاسبه | ورود بعد 13:00 → 08:00 + تأخیر از ۱۳:۰۰
 */

/**
 * Get the effective start time for a shift considering previous night work.
 */
function get_effective_start($shift_start_mins, $night_type) {
    // قانون مستند §3.4 — روز بعد از شب‌کاری:
    // ورود بین 07:00 تا 13:00 → ساعت محاسباتی 08:00
    // ورود بعد از 13:00 → 08:00 + مقدار تأخیر نسبت به 13:00
    // (ورود عصر/شب بعد از ساعت ۱۸ یعنی شروع شیفت شب جدید → اصلاح نمی‌شود)
    if (!$night_type) return $shift_start_mins;

    $seven = 7 * 60;      // 07:00
    $eight = 8 * 60;      // 08:00
    $thirteen = 13 * 60;  // 13:00
    $evening = 18 * 60;   // 18:00

    if ($shift_start_mins >= $seven && $shift_start_mins <= $thirteen) {
        return $eight;
    }
    if ($shift_start_mins > $thirteen && $shift_start_mins < $evening) {
        return $eight + ($shift_start_mins - $thirteen);
    }
    return $shift_start_mins; // قبل از ۷ صبح یا ورود عصر مثل ورود بعدی شیفت شب → واقعی
}

/**
 * Get night shift type from previous day's shifts.
 */
function get_night_type($prev_shifts, $prev_date) {
    if (empty($prev_shifts)) return null;

    $latest_night_end = null;
    foreach ($prev_shifts as $shift) {
        if (!$shift['is_night']) continue;
        $end = strtotime($prev_date . ' ' . $shift['end_time']);
        if (strtotime($shift['end_time']) < strtotime('12:00:00')) {
            $end += 86400;
        }
        if ($latest_night_end === null || $end > $latest_night_end) {
            $latest_night_end = $end;
        }
    }

    if ($latest_night_end === null) return null;

    $three_am = strtotime($prev_date . ' 03:00:00') + 86400;
    if ($latest_night_end <= $three_am) {
        return 'early';
    } else {
        return 'late';
    }
}

/**
 * Calculate salary for an employee (کارمند) for a single day.
 *
 * Time boundaries:
 * - 7:00-17:00 → regular (coefficient 1)
 * - 17:00-21:00 → overtime (coefficient 1.33)
 * - 21:00-3:00 → night (coefficient 2)
 * - 3:00-7:00 → night (coefficient 2)
 * - Friday: coefficient 1.33 for all hours
 * - Holiday: coefficient 2 for all hours
 *
 * Late arrival rule:
 * - Previous night ended before 3 AM → if actual start >= 11:00, effective start = actual - 3h; else 8:00
 * - Previous night ended after 3 AM → if actual start >= 13:00, effective start = actual - 5h; else 8:00
 */
function calc_employee_day($shifts, $date, $is_holiday, $settings = [], $prev_shifts = [], $prev_date = '') {
    $is_friday = (date('w', strtotime($date)) == 5);

    $regular = 0;
    $overtime = 0;
    $night = 0;
    $friday_hours = 0;
    $holiday_hours = 0;

    // Determine night type from previous day
    $night_type = get_night_type($prev_shifts, $prev_date);

    foreach ($shifts as $shift) {
        if ($shift['end_time'] === '00:00:00') continue;

        $start_ts = strtotime($date . ' ' . $shift['start_time']);
        $end_ts = strtotime($date . ' ' . $shift['end_time']);
        if ($end_ts <= $start_ts) $end_ts += 86400;

        $total_dur = ($end_ts - $start_ts) / 3600;
        if ($total_dur <= 0) continue;

        // Holiday: all hours go to holiday bucket
        if ($is_holiday) {
            $holiday_hours += $total_dur;
            continue;
        }

        // Friday: همه ساعت‌های واقعی با ضریب جمعه (۱.۳۳) — طبق مستند، بدون ساعت اضافه
        if ($is_friday) {
            $friday_hours += $total_dur;
            continue;
        }

        // قانون روز بعد شب‌کاری (مستند §3.4) — ورود محاسباتی جایگزین ورود واقعی میشه؛
        // مدت قابل‌پرداخت = خروج واقعی منهای ورود محاسباتی (مثلاً ورود 10:30 → از 08:00 محاسبه)
        $start_mins = (int)date('H', $start_ts) * 60 + (int)date('i', $start_ts);
        $effective_start_mins = get_effective_start($start_mins, $night_type);
        $effective_dur_mins = (int)round($total_dur * 60) - ($effective_start_mins - $start_mins);
        if ($effective_dur_mins <= 0) continue;

        $end_mins_total = $effective_start_mins + $effective_dur_mins;

        // Walk through the shift by minute, classifying each minute
        $cursor = $effective_start_mins;
        while ($cursor < $end_mins_total) {
            $m = $cursor % 1440;

            // Boundaries: 07:00, 17:00, 21:00, 03:00+1day
            $boundaries = [420, 1020, 1260, 1620];
            $next_b = $end_mins_total;
            foreach ($boundaries as $b) {
                $dist = $b - $m;
                if ($dist <= 0) $dist += 1440;
                $b_abs = $cursor + $dist;
                if ($b_abs < $next_b) {
                    $next_b = $b_abs;
                }
            }

            $seg_mins = min($next_b - $cursor, $end_mins_total - $cursor);
            $seg_hours = $seg_mins / 60;
            if ($seg_hours <= 0) break;

            // 7:00-17:00 = regular, 17:00-21:00 = overtime, 21:00-3:00 = night, 3:00-7:00 = night
            if ($m >= 420 && $m < 1020) {
                $regular += $seg_hours;
            } elseif ($m >= 1020 && $m < 1260) {
                $overtime += $seg_hours;
            } elseif ($m >= 1260 || $m < 420) {
                $night += $seg_hours;
            }

            $cursor += $seg_mins;
        }
    }

    return [
        'regular' => round($regular, 2),
        'overtime' => round($overtime, 2),
        'night' => round($night, 2),
        'friday_hours' => round($friday_hours, 2),
        'holiday_hours' => round($holiday_hours, 2),
    ];
}

/**
 * Calculate salary for a worker (کارگر) for a single day.
 *
 * Same late arrival rule applies.
 */
function calc_worker_day($shifts, $date, $is_holiday, $daily_salary = 0, $prev_shifts = [], $prev_date = '') {
    $is_friday = (date('w', strtotime($date)) == 5);

    $night_type = get_night_type($prev_shifts, $prev_date ?: '');

    $total_worked = 0;   // ساعات واقعی (با اعمال قانون روز بعد شب‌کاری روی ورود)
    $night_worked = 0;
    $payable = 0;        // ساعات قابل‌پرداخت: کار روزانه منهای ناهار ۱۳–۱۴

    foreach ($shifts as $shift) {
        if ($shift['end_time'] === '00:00:00') continue;

        $start = strtotime($date . ' ' . $shift['start_time']);
        $end = strtotime($date . ' ' . $shift['end_time']);
        if ($end <= $start) $end += 86400;
        $dur = ($end - $start) / 3600;
        if ($dur <= 0) continue;

        // قانون مستند §3.4 — کاهش زمان واقعی، نه افزایش
        $start_mins_actual = (int)date('H', $start) * 60 + (int)date('i', $start);
        $effective_start = get_effective_start($start_mins_actual, $night_type);
        $effective_dur = $dur - ($effective_start - $start_mins_actual) / 60;
        if ($effective_dur < 0) $effective_dur = 0;

        $total_worked += $effective_dur;
        if ($shift['is_night']) {
            $night_worked += $effective_dur;
            $payable += $effective_dur;                       // شیفت شب → ناهار ندارد
        } else {
            // روز عادی: ساعت ناهار (۱۳ تا ۱۴) جزو کار محسوب نمی‌شود
            // تا حضور ۸ تا ۱۷ دقیقاً = ۸ ساعت (یک روز کامل) حقوق بدهد
            $eff_start_ts = $start + ($effective_start - $start_mins_actual) * 60;
            $lunch_start = strtotime($date . ' 13:00');
            $lunch_end   = strtotime($date . ' 14:00');
            $lunch_overlap = max(0, min($end, $lunch_end) - max($eff_start_ts, $lunch_start));
            $payable += max(0, $effective_dur - $lunch_overlap / 3600);
        }
    }

    // تعطیل رسمی برای کارگر = حقوق عادی (بدون ضریب)
    // جمعه: تا سقف یک روز کامل (۸ ساعت) پرداخت می‌شود و نباید از حضور
    // کوتاهِ ۸ تا ۱۳، یک ساعت اضافه‌کاری مصنوعی ساخته شود.
    if ($is_friday && $payable > 0) {
        $payable = min($payable * 1.8, 8);
    } else {
        // مستند §6.4 — شیفت شب کمتر از ۴ ساعت = ۴ ساعت (فقط وقتی جمعه نیست تا تداخل نشه)
        if ($night_worked > 0 && $night_worked < 4) {
            $payable += (4 - $night_worked);
            $night_worked = 4;
        }
    }

    $paid_hours = min($payable, 8);
    $overtime_hours = max(0, $payable - 8);

    return [
        'payable_hours' => round($payable, 2),          // ← ساعت قابل‌پرداخت (حقوق = این × روزانه/۸)
        'paid_hours' => round($paid_hours, 2),
        'overtime_hours' => round($overtime_hours, 2),
        'night_hours' => round($night_worked, 2),
        'total_worked' => round($total_worked, 2),
        'day_count' => $payable > 0 ? 1 : 0,            // روز حضور (فقط نمایش)
        'extra_days' => round($overtime_hours / 8, 2),  // سازگاری با نمایش قدیمی
    ];
}

// ═══════════════════════════════════════════════════════════════
// Jalali month helpers — حقوق هر ماه = از ۱ تا آخر همان ماه شمسی
// ═══════════════════════════════════════════════════════════════

// Given jalali year+month → [gregorian 'Y-m-d' start, 'Y-m-d' end]
function jalali_month_bounds($jy, $jm) {
    $g1 = jalali_to_gregorian((int)$jy, (int)$jm, 1);
    if ((int)$jm === 12) {
        $g2 = jalali_to_gregorian((int)$jy + 1, 1, 1);
    } else {
        $g2 = jalali_to_gregorian((int)$jy, (int)$jm + 1, 1);
    }
    $start = sprintf('%04d-%02d-%02d', $g1[0], $g1[1], $g1[2]);
    $next_start = sprintf('%04d-%02d-%02d', $g2[0], $g2[1], $g2[2]);
    $end = date('Y-m-d', strtotime($next_start . ' -1 day'));
    return [$start, $end];
}

// Gregorian date 'Y-m-d' → jalali month key 'jy-jm' e.g. '1405-4'
function jalali_month_key($gdate) {
    $j = gregorian_to_jalali((int)substr($gdate, 0, 4), (int)substr($gdate, 5, 2), (int)substr($gdate, 8, 2));
    return $j[0] . '-' . $j[1];
}

// 'jy-jm' → ['jy'=>…, 'jm'=>…, 'label'=>'تیر ۱۴۰۵', 'start'=>g, 'end'=>g]
function jalali_month_from_key($key) {
    $parts = explode('-', $key);
    $jy = (int)$parts[0];
    $jm = (int)$parts[1];
    [$start, $end] = jalali_month_bounds($jy, $jm);
    return [
        'jy' => $jy,
        'jm' => $jm,
        'label' => jalali_month_name($jm) . ' ' . $jy,
        'start' => $start,
        'end' => $end,
    ];
}

// ═══════════════════════════════════════════════════════════════
// نمایش ساعت به فرمت ساعت:دقیقه (اسناد §3 — محاسبات دقیقه‌محور)
// fmt_hm(124.3833) → "124:23"
// ═══════════════════════════════════════════════════════════════
function fmt_hm($hours_decimal) {
    $neg = $hours_decimal < 0;
    $total_mins = (int)round(abs($hours_decimal) * 60);
    $h = intdiv($total_mins, 60);
    $m = $total_mins % 60;
    return ($neg ? '-' : '') . $h . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

// ═══════════════════════════════════════════════════════════════
// کسر مساعده/حقوق پرداختی از حساب حقوق
// قانون: هر رکورد دقیقاً یک‌بار و در ماه «تسویه»‌اش کسر می‌شود:
//  - وضعیت paid → ماهِ paid_at  |  وضعیت approved (هنوز پرداخت نشده) → ماهِ approved_at
function advance_deduction_month(PDO $pdo, $uid, $ws, $start, $end) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM advances WHERE user_id = ?" . _ws_filter($ws) . " AND payment_type IN ('advance','salary','debt') AND ((status = 'paid' AND paid_at IS NOT NULL AND DATE(paid_at) BETWEEN ? AND ?) OR (status = 'approved' AND approved_at IS NOT NULL AND DATE(approved_at) BETWEEN ? AND ?))");
    $st->execute([$uid, $start, $end, $start, $end]);
    return (float)$st->fetchColumn();
}

// جمع پرداخت‌های تسویه‌شده قبل از شروع ماه (برای مانده ماه‌های قبل)
function advance_deduction_upto(PDO $pdo, $uid, $ws, $before_start) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM advances WHERE user_id = ?" . _ws_filter($ws) . " AND payment_type IN ('advance','salary','debt') AND ((status = 'paid' AND paid_at IS NOT NULL AND DATE(paid_at) < ?) OR (status = 'approved' AND approved_at IS NOT NULL AND DATE(approved_at) < ?))");
    $st->execute([$uid, $before_start, $before_start]);
    return (float)$st->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════
// تاریخچه افزایش حقوق (اسناد §1) — حقوق معتبر در هر تاریخ
// ═══════════════════════════════════════════════════════════════

// خواندن یک‌باره تاریخچه حقوق یک کاربر: خروجی آرایه مرتب‌شده [start_date, new_salary]
// $fresh=true کش درون‌درخواستی را دور می‌زند (بعد از هر تغییر تاریخچه لازم است).
// ترتیب ثانویه id تضمین می‌کند اگر دو رکورد یک تاریخ داشتند، آخرینِ ثبت‌شده برنده شود.
function get_salary_history(PDO $pdo, $user_id, $fresh = false) {
    static $cache = [];
    if ($fresh) unset($cache[$user_id]);
    if (isset($cache[$user_id])) return $cache[$user_id];
    $rows = [];
    try {
        $st = $pdo->prepare("SELECT start_date, new_salary, old_salary FROM salary_history WHERE user_id = ? AND start_date IS NOT NULL AND start_date != '0000-00-00' ORDER BY start_date ASC, id ASC");
        $st->execute([$user_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    return $cache[$user_id] = $rows;
}

/**
 * §fix: «ویرایش پرسنل» و «تاریخچه حقوق» باید یک منبع واحد باشند.
 * ماشین حساب حقوق فقط از salary_history می‌خواند، ولی فرم ویرایش فقط ستون
 * workshop_personnel را عوض می‌کرد؛ نتیجه: صفحهٔ جزئیات عدد جدید را نشان می‌داد
 * ولی محاسبه با عدد قدیمی تاریخچه انجام می‌شد.
 *
 * قانون: ویرایش حقوق = اصلاح «حقوق مؤثر فعلی».
 *  - اگر افزایشِ تاریخ‌دارِ اجراشده وجود دارد → همان رکورد (آخرین مؤثر) اصلاح می‌شود؛
 *  - اگر فقط افزایش‌های آینده هست → پایهٔ قبل از اولین افزایش اصلاح می‌شود؛
 *  - اگر اصلاً افزایشی نیست → خود ستون پرسنل مبنا است (تاریخچه لازم نیست).
 * یک رکورد بدون تاریخ هم برای ردپا ثبت می‌شود (در محاسبه نادیده گرفته می‌شود).
 */
function salary_apply_edit(PDO $pdo, $uid, $ws_id, $old_value, $new_value, $changed_by) {
    $today = date('Y-m-d');
    $st = $pdo->prepare("SELECT id, start_date FROM salary_history WHERE user_id = ? AND start_date IS NOT NULL AND start_date != '0000-00-00' ORDER BY start_date ASC, id ASC");
    $st->execute([(int)$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $current = null;
    foreach ($rows as $r) { if ($r['start_date'] <= $today) $current = $r; }
    if ($current) {
        $pdo->prepare("UPDATE salary_history SET new_salary = ? WHERE id = ?")->execute([$new_value, $current['id']]);
    } elseif (!empty($rows)) {
        $pdo->prepare("UPDATE salary_history SET old_salary = ? WHERE id = ?")->execute([$new_value, $rows[0]['id']]);
    }
    $pdo->prepare("INSERT INTO salary_history (user_id, workshop_id, old_salary, new_salary, changed_by, reason, start_date) VALUES (?, ?, ?, ?, ?, 'ویرایش نرخ پرسنل', NULL)")
        ->execute([(int)$uid, (int)$ws_id, $old_value, $new_value, (int)$changed_by]);
    get_salary_history($pdo, (int)$uid, true);
}

/**
 * افزایش‌های تاریخ‌دار وقتی «تاریخشان می‌رسد» باید در ستون حقوق فعلی پرسنل هم
 * دیده شوند (فرم ویرایش/لیست/هدر جزئیات). هر درخواست حداکثر یک‌بار اجرا می‌شود.
 * فقط برای کارگر (روزانه) و کارمند (ماهانه) و فقط برای کارگاهِ همان رکورد.
 */
function salary_sync_current(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $rows = $pdo->query("SELECT user_id, workshop_id, new_salary FROM salary_history WHERE start_date IS NOT NULL AND start_date != '0000-00-00' AND start_date <= CURDATE() ORDER BY start_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $latest = [];
        foreach ($rows as $r) $latest[(int)$r['user_id']] = $r;
        $get = $pdo->prepare("SELECT wp.daily_salary, wp.monthly_salary, u.is_karigar FROM workshop_personnel wp JOIN users u ON u.id = wp.user_id WHERE wp.user_id = ? AND wp.workshop_id = ?");
        foreach ($latest as $uid => $r) {
            $get->execute([$uid, (int)$r['workshop_id']]);
            $wp = $get->fetch(PDO::FETCH_ASSOC);
            if (!$wp) continue;
            $k = (int)$wp['is_karigar'];
            if ($k === 2) continue;
            $col = $k === 1 ? 'daily_salary' : 'monthly_salary';
            if (abs((float)$wp[$col] - (float)$r['new_salary']) > 0.5) {
                $pdo->prepare("UPDATE workshop_personnel SET $col = ? WHERE user_id = ? AND workshop_id = ?")->execute([(float)$r['new_salary'], $uid, (int)$r['workshop_id']]);
            }
        }
    } catch (Throwable $e) {}
}

// حقوق مؤثر در یک تاریخ: آخرین رکورد تاریخچه که از آن تاریخ یا قبل‌تر فعال شده
/**
 * پایهٔ حقوق «فعلی» یک نفر — تنها منبع حقیقت برای لیست حقوق، جزئیات حقوق، تسویه و پیش‌نمایش.
 * $ws > 0  → ردیف عضویت همان کارگاه
 * $ws == 0 → (تجمیعی) بیشترین حقوق در عضویت‌های «فعال»؛ اگر عضویت فعالی نبود بیشترین حقوق بین همه.
 * نکته: عضویت‌های «منتقل‌شده» قدیمی نباید حقوق نفرِ فعال را بالا ببرند (باگ اختلاف لیست و جزئیات).
 */
function salary_effective_base(PDO $pdo, int $uid, int $ws = 0): array {
    if ($ws > 0) {
        $st = $pdo->prepare("SELECT COALESCE(daily_salary,0) d, COALESCE(monthly_salary,0) m FROM workshop_personnel WHERE user_id = ? AND workshop_id = ? LIMIT 1");
        $st->execute([$uid, $ws]);
        $r = $st->fetch();
        return ['daily_salary' => (float)($r['d'] ?? 0), 'monthly_salary' => (float)($r['m'] ?? 0)];
    }
    $st = $pdo->prepare("SELECT MAX(daily_salary) d, MAX(monthly_salary) m, COUNT(*) c FROM workshop_personnel WHERE user_id = ? AND status = 'active'");
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r || (int)$r['c'] === 0) {
        $st = $pdo->prepare("SELECT MAX(daily_salary) d, MAX(monthly_salary) m FROM workshop_personnel WHERE user_id = ?");
        $st->execute([$uid]);
        $r = $st->fetch();
    }
    return ['daily_salary' => (float)($r['d'] ?? 0), 'monthly_salary' => (float)($r['m'] ?? 0)];
}

function salary_at_date(array $history, $date, $default_salary) {
    $result = (float)$default_salary;
    $covered = false;
    foreach ($history as $h) {
        if ($h['start_date'] <= $date) {
            $result = (float)$h['new_salary'];
            $covered = true;
        } else {
            break;
        }
    }
    // §1: روزهای قبل از اولین رکورد تاریخچه → حقوق «قبل از افزایش»‌ی همان رکورد
    if (!$covered && !empty($history) && (float)($history[0]['old_salary'] ?? 0) > 0) {
        $result = (float)$history[0]['old_salary'];
    }
    return $result;
}

// نرخ ساعتی مؤثر: کارگر → روزانه/۸ | کارمند → ماهانه/۲۶/۸
function hourly_rate_at(array $history, $date, $is_karigar, $daily_default, $monthly_default) {
    if ($is_karigar) {
        return salary_at_date($history, $date, $daily_default) / 8;
    }
    return salary_at_date($history, $date, $monthly_default) / 26 / 8;
}

// ═══════════════════════════════════════════════════════════════
// محاسبات ماهانه مشترک + مانده طلب قبلی (اسناد §7)
// نرخ هر روز از تاریخچه حقوق تعیین می‌شود (§1)
// ═══════════════════════════════════════════════════════════════

// خواندن تعطیلات یک بازه (کش داخلی)
// §v8: فیلتر کارگاه برای کوئری‌های حقوق — با ws_id=0 (حقوق تجمیعی) فیلتر حذف می‌شه
function _ws_filter($ws_id, $col = 'workshop_id') {
    return (int)$ws_id > 0 ? " AND $col = " . (int)$ws_id : "";
}

function _holidays_between(PDO $pdo, $g_start, $g_end) {
    static $cache = [];
    $ck = $g_start . '_' . $g_end;
    if (isset($cache[$ck])) return $cache[$ck];
    $st = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $st->execute([$g_start, $g_end]);
    return $cache[$ck] = array_column($st->fetchAll(), 'holiday_date');
}

// گروه‌بندی ساعت‌های کاری کاربر در بازه بر اساس روز
function _daily_hours(PDO $pdo, $user_id, $ws_id, $g_start, $g_end) {
    $st = $pdo->prepare("SELECT * FROM work_hours WHERE user_id = ?" . _ws_filter($ws_id) . " AND work_date BETWEEN ? AND ? ORDER BY work_date, start_time");
    $st->execute([$user_id, $g_start, $g_end]);
    $daily = [];
    foreach ($st->fetchAll() as $h) $daily[$h['work_date']][] = $h;
    return $daily;
}


/**
 * خلاصهٔ مرخصی‌های تأییدشده در یک بازه.
 * مرخصی روزانهٔ تأییدشده «روز کاری الزامی» را کم می‌کند (مرخصی با حقوق)؛
 * غیبت ثبت‌نشده اینجا نیست و بنابراین مخرج را کم نمی‌کند و از حقوق کم می‌شود.
 */
function approved_leave_summary(PDO $pdo, $user_id, $ws_id, $g_start, $g_end, array $holidays = []): array {
    $out = ['daily_days' => 0.0, 'hourly_hours' => 0.0, 'dates' => []];
    try {
        $sql = "SELECT start_date, end_date, leave_type, hours FROM leave_requests WHERE user_id = ?" . _ws_filter($ws_id) . " AND status = 'approved' AND start_date <= ? AND end_date >= ?";
        $st = $pdo->prepare($sql);
        $st->execute([$user_id, $g_end, $g_start]);
        $covered = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $leave) {
            $type = $leave['leave_type'] ?? 'daily';
            if ($type === 'hourly') {
                $d = $leave['start_date'];
                if ($d >= $g_start && $d <= $g_end && date('w', strtotime($d)) != 5 && !in_array($d, $holidays, true)) {
                    // مجموع مرخصی ساعتی یک روز هرگز از یک روز کاری بیشتر حساب نمی‌شود.
                    $out['hourly_hours'] += max(0, (float)($leave['hours'] ?? 0));
                }
                continue;
            }
            $from = max($g_start, (string)$leave['start_date']);
            $to = min($g_end, (string)$leave['end_date']);
            for ($ts = strtotime($from); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
                $date = date('Y-m-d', $ts);
                if (date('w', $ts) == 5 || in_array($date, $holidays, true)) continue;
                $covered[$date] = true; // مرخصی‌های هم‌پوشان دوبار کم نشوند.
            }
        }
        $out['dates'] = array_keys($covered);
        $out['daily_days'] = (float)count($covered);
    } catch (Throwable $e) {}
    return $out;
}

/**
 * ساعات کاری الزامی کارمند در یک ماه/بازه.
 * روزهای جمعه و تعطیل رسمی همیشه حذف می‌شوند؛ فقط مرخصیِ تأییدشده از مخرج کم
 * می‌شود. در نتیجه غیبت بدون ثبت مرخصی، مخرج را حفظ کرده و حقوق آن روز پرداخت
 * نمی‌شود.
 */
function employee_required_hours(PDO $pdo, $user_id, $ws_id, $g_start, $g_end, array $settings = []): array {
    $daily_hours = (float)($settings['employee_daily_required_hours'] ?? 8);
    if ($daily_hours <= 0) $daily_hours = 8;
    $holidays = _holidays_between($pdo, $g_start, $g_end);
    $working_dates = [];
    for ($ts = strtotime($g_start); $ts <= strtotime($g_end); $ts = strtotime('+1 day', $ts)) {
        $date = date('Y-m-d', $ts);
        if (date('w', $ts) == 5 || in_array($date, $holidays, true)) continue;
        $working_dates[] = $date;
    }
    $leave = approved_leave_summary($pdo, $user_id, $ws_id, $g_start, $g_end, $holidays);
    $daily_leave_days = 0;
    foreach ($leave['dates'] as $date) if (in_array($date, $working_dates, true)) $daily_leave_days++;
    $working_day_count = count($working_dates);
    $base_required = $working_day_count * $daily_hours;

    // ساعت‌های مرخصیِ تأییدشده (روزانه + ساعتی)
    $leave_hours_total = ($daily_leave_days * $daily_hours) + (float)$leave['hourly_hours'];

    // ── §v13 سهمیهٔ مرخصی ماهانه ──────────────────────────────────────
    // هر کارمند ماهانه سهمیه‌ای مرخصی دارد. سهمیهٔ استفاده‌نشده «سوخت»
    // نمی‌شود: به همان اندازه از ساعت الزامی کم می‌شود تا کسی که مرخصی
    // نرفته و کامل کار کرده، بابت آن ساعت‌ها اضافه‌کار بگیرد.
    // اما اگر کسی بدون ثبت مرخصی غیبت کند، آن غیبت سهمیه را مصرف می‌کند
    // و دیگر تخفیفی در مخرج نمی‌گیرد (جریمهٔ اعلام‌نکردن مرخصی).
    $quota_days  = (float)($settings['employee_monthly_leave_quota_days'] ?? 2);
    if ($quota_days < 0) $quota_days = 0;
    $quota_hours = $quota_days * $daily_hours;

    // ساعت غیبتِ بدون مرخصی = روزهای کاریِ بدون هیچ ساعت کارکرد و بدون مرخصی
    $worked_map = _daily_hours($pdo, $user_id, $ws_id, $g_start, $g_end);
    $absent_hours = 0.0;
    $absent_dates = [];
    foreach ($working_dates as $date) {
        if (in_array($date, $leave['dates'], true)) continue;   // مرخصی روزانه دارد
        if (!empty($worked_map[$date])) continue;               // آن روز کار کرده
        $absent_dates[] = $date;
        $absent_hours += $daily_hours;
    }

    // باقیماندهٔ سهمیه بعد از کسر مرخصیِ ثبت‌شده و غیبتِ ثبت‌نشده
    $unused_quota = max(0.0, $quota_hours - $leave_hours_total - $absent_hours);

    $required = max(0, $base_required - $leave_hours_total - $unused_quota);
    return [
        'daily_hours' => $daily_hours,
        'calendar_work_days' => $working_day_count,
        'official_holidays' => count(array_filter($holidays, fn($d) => $d >= $g_start && $d <= $g_end && date('w', strtotime($d)) != 5)),
        'leave_days' => $daily_leave_days,
        'leave_hours' => (float)$leave['hourly_hours'],
        'required_hours' => round($required, 2),
        'base_required_hours' => round($base_required, 2),
        'quota_days' => $quota_days,
        'quota_hours' => round($quota_hours, 2),
        'absent_hours' => round($absent_hours, 2),
        'absent_dates' => $absent_dates,
        'unused_quota_hours' => round($unused_quota, 2),
    ];
}

/** نرخ ساعتی کارمند از حقوق ماهانه و ساعات الزامی همان ماه. */
function employee_hourly_rate_for_required_hours($monthly_salary, array $required): float {
    $denominator = (float)($required['required_hours'] ?? 0);
    // اگر کل ماه مرخصی تأییدشده باشد، برای جلوگیری از تقسیم بر صفر از ساعات
    // تقویمی استفاده می‌شود؛ موردهای معمول با همان «ساعات الزامی» محاسبه می‌شوند.
    if ($denominator <= 0) $denominator = (float)($required['base_required_hours'] ?? 0);
    return $denominator > 0 ? ((float)$monthly_salary / $denominator) : 0.0;
}

// دریافت ماهانه کارگر: تمام دقایق قابل‌پرداخت × نرخ ساعتی همان روز.
// این دقیقاً همان فرمول صفحه حقوق و جزئیات است؛ نسخهٔ قدیمی برای هر حضورِ کوتاه
// یک روز کامل حساب می‌کرد و باعث اختلاف فرم واریزی با صفحه حقوق می‌شد.
function worker_month_pay(PDO $pdo, $user_id, $ws_id, $g_start, $g_end, $daily_default) {
    $daily = _daily_hours($pdo, $user_id, $ws_id, $g_start, $g_end);
    $holidays = _holidays_between($pdo, $g_start, $g_end);
    $history = get_salary_history($pdo, $user_id);
    $pay = 0.0;
    $sorted = array_keys($daily); sort($sorted);
    foreach ($sorted as $di => $date) {
        $prev_d = $di > 0 ? $sorted[$di - 1] : '';
        $prev_s = $prev_d ? ($daily[$prev_d] ?? []) : [];
        $calc = calc_worker_day($daily[$date], $date, in_array($date, $holidays), (float)$daily_default, $prev_s, $prev_d);
        $rate = salary_at_date($history, $date, (float)$daily_default);
        $pay += $calc['payable_hours'] * ($rate / 8);
    }
    return round($pay);
}

/** نمایش ساعت ورود/خروج همهٔ شیفت‌های یک روز برای جزئیات حقوق. */
function workday_clock_labels(array $shifts): array {
    $starts = []; $ends = [];
    foreach ($shifts as $shift) {
        $start = substr((string)($shift['start_time'] ?? ''), 0, 5);
        $end = substr((string)($shift['end_time'] ?? ''), 0, 5);
        if ($start !== '') $starts[] = $start;
        if ($end !== '') $ends[] = $end === '00:00' ? t('باز') : $end;
    }
    return [
        'start_label' => $starts ? implode(' / ', $starts) : '—',
        'end_label' => $ends ? implode(' / ', $ends) : '—',
    ];
}

// دریافت ماهانه کارمند: جمع ضرایب ساعتی × نرخ ساعتی همان روز (ماهانه/۲۶/۸)
function employee_month_pay(PDO $pdo, $user_id, $ws_id, $g_start, $g_end, $monthly_default, $settings = []) {
    $daily = _daily_hours($pdo, $user_id, $ws_id, $g_start, $g_end);
    if (empty($daily)) return 0;
    $history = get_salary_history($pdo, $user_id);
    $ot = (float)($settings['overtime_coefficient'] ?? 1.33);
    $ni = (float)($settings['night_coefficient'] ?? 2);
    $fr = (float)($settings['friday_coefficient'] ?? 1.33);
    $ho = (float)($settings['holiday_coefficient'] ?? 2);

    // ساعات الزامی هر ماه شمسی جداگانه محاسبه می‌شود؛ بازه‌های بلند (ماندهٔ
    // ماه‌های قبل) نیز با همان قانون ماهانه باقی می‌مانند.
    $required_by_month = [];
    foreach (array_keys($daily) as $date) {
        $key = jalali_month_key($date);
        if (!isset($required_by_month[$key])) {
            $mi = jalali_month_from_key($key);
            $required_by_month[$key] = employee_required_hours($pdo, $user_id, $ws_id, $mi['start'], $mi['end'], $settings);
        }
    }

    $holidays = _holidays_between($pdo, $g_start, $g_end);
    $pay = 0.0;
    $sorted = array_keys($daily); sort($sorted);
    foreach ($sorted as $di => $date) {
        $prev_d = $di > 0 ? $sorted[$di - 1] : '';
        $prev_s = $prev_d ? ($daily[$prev_d] ?? []) : [];
        $calc = calc_employee_day($daily[$date], $date, in_array($date, $holidays, true), $settings, $prev_s, $prev_d);
        $salary_for_date = salary_at_date($history, $date, (float)$monthly_default);
        // طبق سند حقوق: فقط «کار عادی» نرخ پویا دارد. اضافه‌کاری، شب، جمعه و
        // تعطیل همیشه با نرخ ثابت ماهانه÷۲۶÷۸ و ضرایب قبلی محاسبه می‌شوند.
        $regular_hourly = employee_hourly_rate_for_required_hours($salary_for_date, $required_by_month[jalali_month_key($date)]);
        $special_hourly = $salary_for_date / 26 / 8;
        $pay += $calc['regular'] * $regular_hourly
              + $calc['overtime'] * $special_hourly * $ot
              + $calc['night'] * $special_hourly * $ni
              + $calc['friday_hours'] * $special_hourly * $fr
              + $calc['holiday_hours'] * $special_hourly * $ho;
    }
    return round($pay);
}

// مانده طلب قبلی = درآمد محاسبه‌شده تا قبل از شروع ماه + پاداش/جریمه تاییدشده − مبالغ پرداخت‌شده
function compute_prev_balance(PDO $pdo, $user_id, $ws_id, $is_karigar, $g_start, $daily_default, $monthly_default, $settings = []) {
    // پرداخت‌شده‌ها (مساعده/حقوق/بدهی تأییدشده) قبل از شروع ماه
    $paid = advance_deduction_upto($pdo, $user_id, $ws_id, $g_start);

    // پاداش/جریمه تاییدشده قبل از ماه
    $st = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) s FROM bonuses_penalties WHERE user_id = ?" . _ws_filter($ws_id) . " AND status = 'approved' AND date < ? GROUP BY type");
    $st->execute([$user_id, $g_start]);
    $bp = 0.0;
    foreach ($st->fetchAll() as $r) $bp += ($r['type'] === 'bonus' ? 1 : -1) * (float)$r['s'];

    // کارکرد از ابتدا تا قبل از ماه (با بازه‌ی کشف‌شده از اولین رکورد)
    $st = $pdo->prepare("SELECT MIN(work_date) FROM work_hours WHERE user_id = ?" . _ws_filter($ws_id) . " AND work_date < ?");
    $st->execute([$user_id, $g_start]);
    $first = $st->fetchColumn();
    $earned = 0;
    if ($first) {
        $prev_end = date('Y-m-d', strtotime($g_start . ' -1 day'));
        if ($is_karigar) {
            $earned = worker_month_pay($pdo, $user_id, $ws_id, $first, $prev_end, (float)$daily_default);
        } else {
            $earned = employee_month_pay($pdo, $user_id, $ws_id, $first, $prev_end, (float)$monthly_default, $settings);
        }
    }
    return round($earned + $bp - $paid);
}
