<?php
/**
 * ══ موتور سیاست‌های سازمانی و کمیسیون — سند نهایی «ساختار سازمانی و نظام جبران خدمات» ══
 *
 * سیاست‌های ابلاغی شرکت (docs/COMPANY_ORG_POLICY.md) — تمام درصدها از دیتابیس خوانده می‌شوند،
 * نه از کد (قاعدهٔ شرکت): جدول مرجع `commission_tier_rules` (خودترمیم؛ قابل ویرایش ادمین).
 *
 * دامنه:
 *   - دستهٔ نیرو: users.staff_category ∈ {sales, office, manager, ''}  (خالی = قدیمی/کارگر روزانه/پیمانکار)
 *   - شروع حقوق کارشناس فروش: users.salary_effective_from — اگر خالی، از تاریخ اولین معامله برندهٔ خودش
 *   - کمیسیون پله‌ای بر «سود ناخالص مفروض» (profit = sales × margin_pct) محاسبه می‌شود؛
 *     margin_pct پیش‌فرض از settings.commission_margin_pct (۲۵٪ طبق سند) — قابل تغییر.
 *
 * ══ v1 محدودیت‌ها (عمداً شفاف) ══
 *   - مبنای «فروش وصول‌شده» فعلاً "معاملاتی که status='won' دارند" هستند؛ فیلد وصول جداگانه نداریم (v2).
 *   - حاشیهٔ سود واقعی هر معامله فیلد مستقل ندارد؛ margin_pct سراسری استفاده می‌شود (v2: cost روی deal).
 *   - پرداخت کمیسیون را موتور خودکار انجام نمی‌دهد؛ «accrual (محاسبه و رصد زنده)» نمایش داده می‌شود.
 */

// موتور به توابع جلالی ماه از salary_calc.php وابسته است — خودبسنده باشد
require_once __DIR__ . '/salary_calc.php';

function ensure_org_commission_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // ستون‌های خودترمیم روی users
    foreach ([['staff_category', "VARCHAR(20) NOT NULL DEFAULT ''"], ['salary_effective_from', "DATE NULL DEFAULT NULL"]] as [$col, $def]) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME = ?");
            $st->execute([$col]);
            if ((int)$st->fetchColumn() === 0) $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
        } catch (Throwable $e) { /* نادیده */ }
    }

    // سمت جدید سند نهایی (مدیر دیجیتال مارکتینگ) — برای نصب‌های موجود
    try {
        $pdo->exec("INSERT IGNORE INTO org_roles (code, name, stage, description, sort_order) VALUES
            ('marketing_manager', 'مدیر دیجیتال مارکتینگ', 'stage1', 'مدیریت کانال‌های آنلاین و لیدهای دیجیتال؛ نقش جداگانه از مدیر فروش', 25),
            ('order_coordinator', 'هماهنگ‌کنندهٔ سفارشات', 'stage1', 'پروندهٔ یکتا، پیگیری، ارائهٔ به‌روزرسانی مشتری و بستن پرونده', 40)");
    } catch (Throwable $e) {}

    // سمت‌های چندگانه (مدیر هم‌درآنِ چند سمت است — مثل «مدیر دیجیتال مارکتینگ + مدیر توسعه بازار»)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_org_roles (
            user_id INT NOT NULL,
            org_role_id INT NOT NULL,
            PRIMARY KEY (user_id, org_role_id),
            KEY idx_uor_role (org_role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    // مدارک اسکن‌شدهٔ پرسنل (قرارداد، شناسنامه، کارت ملی، …)
    // جدول مرجع نصب (install_db.php) — تعریف یکسان برای خودترمیمی
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_documents (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            workshop_id INT(11) NULL,
            document_type VARCHAR(50) NULL,
            title VARCHAR(200) NULL,
            file_path VARCHAR(255) NULL,
            expiry_date DATE NULL,
            notes VARCHAR(500) NULL,
            uploaded_by INT(11) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pd_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    // قاعدهٔ پرداخت: پله‌های تارگت (قابل‌ویرایش توسط ادمین؛ بذر از سند نهایی)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS commission_tier_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_code VARCHAR(40) NOT NULL,
            min_pct INT NOT NULL DEFAULT 0,
            max_pct INT NULL DEFAULT NULL,
            rate_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            note VARCHAR(150) NULL,
            UNIQUE KEY uq_tier (role_code, min_pct),
            KEY idx_tier_role (role_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // بذر از سند نهایی (فقط هنگام خالی بودن)
        $c = (int)$pdo->query("SELECT COUNT(*) FROM commission_tier_rules")->fetchColumn();
        if ($c === 0) {
            $seed = [
                // کارشناس فروش — تارگت فردی ۱٬۲۵۰م؛ کمیسیون از سود (اسلاید 15)
                ['sales_expert', 0, 70, 10.00, 'کمتر از ۷۰٪'],
                ['sales_expert', 70, 90, 15.00, '۷۰٪ تا کمتر از ۹۰٪'],
                ['sales_expert', 90, 100, 18.00, '۹۰٪ تا کمتر از ۱۰۰٪'],
                ['sales_expert', 100, 120, 22.00, '۱۰۰٪ تا کمتر از ۱۲۰٪'],
                ['sales_expert', 120, null, 25.00, '۱۲۰٪ و بیشتر (سقف)'],
                // مدیر فروش — تارگت تیم ۵٬۰۰۰م؛ از سود معاملات واحد فروش (اسلاید 17)
                ['sales_manager', 0, 70, 3.00, 'کمتر از ۷۰٪'],
                ['sales_manager', 70, 90, 5.00, '۷۰٪ تا کمتر از ۹۰٪'],
                ['sales_manager', 90, 100, 7.00, '۹۰٪ تا کمتر از ۱۰۰٪'],
                ['sales_manager', 100, 120, 8.00, '۱۰۰٪ تا کمتر از ۱۲۰٪'],
                ['sales_manager', 120, null, 10.00, '۱۲۰٪ و بیشتر (سقف)'],
                // توسعه بازار — تارگت ۵۰۰م؛ پاداش از سود مشتری جدید منتسب (اسلاید 18)
                ['business_development', 0, 50, 10.00, 'کمتر از ۵۰٪'],
                ['business_development', 50, 100, 20.00, '۵۰٪ تا کمتر از ۱۰۰٪'],
                ['business_development', 100, 150, 25.00, '۱۰۰٪ تا کمتر از ۱۵۰٪'],
                ['business_development', 150, 200, 30.00, '۱۵۰٪ تا کمتر از ۲۰۰٪'],
                ['business_development', 200, null, 35.00, '۲۰۰٪ و بیشتر (سقف)'],
            ];
            $st = $pdo->prepare("INSERT IGNORE INTO commission_tier_rules (role_code, min_pct, max_pct, rate_pct, note) VALUES (?,?,?,?,?)");
            foreach ($seed as $row) $st->execute($row);
        }
    } catch (Throwable $e) {}

    // تارگت‌های پیش‌فرض (میلیون تومان → تومان)
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $ins->execute(['commission_margin_pct', '25']);      // حاشیهٔ سود ناخالص مفروض طبق سند (قابل تغییر)
        $ins->execute(['commission_target_sales_toman', '1250000000']);  // ۱٫۲۵ میلیارد تومان (۱٬۲۵۰ میلیون)
        $ins->execute(['commission_target_team_toman', '5000000000']);   // ۵ میلیارد تومان
        $ins->execute(['commission_target_bd_toman', '500000000']);      // ۵۰۰ میلیون تومان
    } catch (Throwable $e) {}
}

function commission_setting(PDO $pdo, string $key, string $default): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

function commission_targets(PDO $pdo): array {
    return [
        'margin_pct' => (float)commission_setting($pdo, 'commission_margin_pct', '25'),
        'sales_toman' => (float)commission_setting($pdo, 'commission_target_sales_toman', '1250000000'),
        'team_toman'  => (float)commission_setting($pdo, 'commission_target_team_toman', '5000000000'),
        'bd_toman'    => (float)commission_setting($pdo, 'commission_target_bd_toman', '500000000'),
    ];
}

/** نرخ پله برای یک «تحقق» (نسبت فروش به تارگت، 0..∞) */
function tier_rate(PDO $pdo, string $role_code, float $achievementRatio): float {
    $pct = max(0.0, $achievementRatio * 100.0);
    try {
        $st = $pdo->prepare("SELECT min_pct, max_pct, rate_pct FROM commission_tier_rules WHERE role_code = ? ORDER BY min_pct DESC");
        $st->execute([$role_code]);
        foreach ($st->fetchAll() as $row) {
            $lo = (float)$row['min_pct'];
            $hi = $row['max_pct'] === null ? null : (float)$row['max_pct'];
            if (($pct >= $lo && ($hi === null || $pct < $hi)) || ($hi === null && $pct >= $lo)) {
                return (float)$row['rate_pct'];
            }
        }
    } catch (Throwable $e) {}
    return 10.0;
}

/** فروش کلِ خالصِ برندهٔ یک کارشناس در یک ماه شمسی ('jy-jm') */
/** نرخ ارز به تومان — از تنظیمات خوانده می‌شود؛ کارفرما باید وارد کند (۰ = نامشخص) */
function fx_to_toman(PDO $pdo): array {
    return [
        'USD' => (float)commission_setting($pdo, 'fx_to_toman_USD', '0'),
        'EUR' => (float)commission_setting($pdo, 'fx_to_toman_EUR', '0'),
        'CNY' => (float)commission_setting($pdo, 'fx_to_toman_CNY', '0'),
        'AED' => (float)commission_setting($pdo, 'fx_to_toman_AED', '0'),
        'IRR' => (float)commission_setting($pdo, 'fx_to_toman_IRR', '0.1'),
    ];
}

/** فروش ماه به تفکیک ارز → ['toman'=> float, 'missing'=> string[] (ارزهای بدون نرخ)] */
function month_sales_by_currency(PDO $pdo, string $whereExtra, array $params, string $monthKey): array {
    [$jy, $jm] = array_map('intval', explode('-', $monthKey));
    [$bStart, $bEnd] = jalali_month_bounds($jy, $jm);
    $rates = fx_to_toman($pdo);
    $toman = 0.0; $missing = [];
    try {
        $st = $pdo->prepare("SELECT COALESCE(currency,'USD') AS cur, COALESCE(SUM(estimated_value),0) AS s FROM crm_deals
            WHERE status = 'won' AND updated_at BETWEEN ? AND ? {$whereExtra} GROUP BY currency");
        $st->execute(array_merge([$bStart . ' 00:00:00', $bEnd . ' 23:59:59'], $params));
        foreach ($st->fetchAll() as $row) {
            $cur = strtoupper((string)$row['cur']);
            $rate = $rates[$cur] ?? 0.0;
            $s = (float)$row['s'];
            if ($rate <= 0 && $s > 0) { $missing[] = $cur; continue; }
            $toman += $s * $rate;
        }
    } catch (Throwable $e) {}
    return ['toman' => $toman, 'missing' => $missing];
}

function user_month_sales(PDO $pdo, int $userId, string $monthKey): float {
    $r = month_sales_by_currency($pdo, "AND owner_user_id = ?", [$userId], $monthKey);
    return $r['toman'];
}

function team_month_sales(PDO $pdo, string $monthKey): array {
    $r = month_sales_by_currency($pdo, "AND EXISTS (SELECT 1 FROM users u WHERE u.id = crm_deals.owner_user_id AND u.staff_category = 'sales')", [], $monthKey);
    return $r;
}


/** تاریخ شروع حقوق کارشناس فروش: salary_effective_from اگر ست باشد، وگرنه اولین معامله برندهٔ خودش */
function salary_start_for(PDO $pdo, int $userId, string $staffCategory): ?string {
    try {
        $st = $pdo->prepare("SELECT salary_effective_from FROM users WHERE id = ?");
        $st->execute([$userId]);
        $eff = $st->fetchColumn();
        if (!empty($eff)) return (string)$eff;
        if ($staffCategory !== 'sales') return null; // مدیر/اداری/قدیمی: از قرارداد (نمایش دست‌کاری نمی‌شود)
        $st2 = $pdo->prepare("SELECT MIN(updated_at) FROM crm_deals WHERE owner_user_id = ? AND status = 'won'");
        $st2->execute([$userId]);
        $first = $st2->fetchColumn();
        return $first ? substr((string)$first, 0, 10) : null;
    } catch (Throwable $e) { return null; }
}

/** محاسبهٔ کامل کمیسیون ماهانهٔ یک نفر (نمایش زنده؛ پرداخت انجام نمی‌دهد) */
function commission_snapshot(PDO $pdo, int $userId, string $monthKey): array {
    ensure_org_commission_schema($pdo);
    $targets = commission_targets($pdo);
    try {
        $st = $pdo->prepare("SELECT u.staff_category, u.salary_effective_from, (SELECT GROUP_CONCAT(orol.code) FROM user_org_roles uor JOIN org_roles orol ON orol.id = uor.org_role_id WHERE uor.user_id = u.id) AS role_codes
            FROM users u WHERE u.id = ?");
        $st->execute([$userId]);
        $u = $st->fetch() ?: ['staff_category' => '', 'salary_effective_from' => null, 'role_codes' => ''];
    } catch (Throwable $e) { $u = ['staff_category' => '', 'salary_effective_from' => null, 'role_codes' => '']; }

    $cat = (string)$u['staff_category'];
    $snap = ['category' => $cat, 'sales' => 0.0, 'achievement' => 0.0, 'rate' => 0.0, 'commission' => 0.0, 'target' => 0.0, 'role' => '', 'start_from' => salary_start_for($pdo, $userId, $cat)];

    if ($cat === 'sales') {
        $sr = month_sales_by_currency($pdo, "AND owner_user_id = ?", [$userId], $monthKey);
        $sales = $sr['toman'];
        $margin = $targets['margin_pct'] / 100.0;
        $profit = $sales * $margin;
        $ratio = $targets['sales_toman'] > 0 ? $sales / $targets['sales_toman'] : 0;
        $rate = tier_rate($pdo, 'sales_expert', $ratio);
        $snap = array_merge($snap, ['role' => 'sales_expert', 'sales' => $sales, 'target' => $targets['sales_toman'], 'achievement' => $ratio * 100, 'rate' => $rate, 'commission' => $profit * $rate / 100, 'missing_fx' => $sr['missing']]);
    } elseif ($cat === 'manager') {
        $roles = array_filter(explode(',', (string)$u['role_codes']));
        $isBD = in_array('business_development', $roles, true);
        $isSM = in_array('sales_manager', $roles, true);
        if (!$isBD && !$isSM) {
            // سِمت‌هایی که در سند نهایی پلهٔ تارگت ندارند (مثل مدیر دیجیتال مارکتینگ) — نمایش «بدون پله»
            $snap = array_merge($snap, ['role' => implode('+', $roles), 'sales' => 0.0, 'target' => 0.0, 'achievement' => 0.0, 'rate' => 0.0, 'commission' => 0.0, 'missing_fx' => []]);
        } elseif ($isBD) {
            $dr = month_sales_by_currency($pdo, "AND owner_user_id = ?", [$userId], $monthKey);
            $sales = $dr['toman'];
            $ratio = $targets['bd_toman'] > 0 ? $sales / $targets['bd_toman'] : 0;
            $rate = tier_rate($pdo, 'business_development', $ratio);
            $profit = $sales * ($targets['margin_pct'] / 100.0);
            $snap = array_merge($snap, ['role' => 'business_development', 'sales' => $sales, 'target' => $targets['bd_toman'], 'achievement' => $ratio * 100, 'rate' => $rate, 'commission' => $profit * $rate / 100, 'missing_fx' => $dr['missing']]);
        } else {
            $tr = team_month_sales($pdo, $monthKey);
            $sales = $tr['toman'];
            $ratio = $targets['team_toman'] > 0 ? $sales / $targets['team_toman'] : 0;
            $rate = tier_rate($pdo, 'sales_manager', $ratio);
            $profit = $sales * ($targets['margin_pct'] / 100.0);
            $snap = array_merge($snap, ['role' => 'sales_manager', 'sales' => $sales, 'target' => $targets['team_toman'], 'achievement' => $ratio * 100, 'rate' => $rate, 'commission' => $profit * $rate / 100, 'missing_fx' => $tr['missing']]);
        }
    }
    return $snap;
}

function jalali_current_month_key(): string { return jalali_month_key(date('Y-m-d')); }
