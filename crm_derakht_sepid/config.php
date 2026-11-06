<?php
// Database Configuration
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
// ⚠️ هرگز مقادیر واقعی دیتابیس را اینجا ننویسید — این فایل در گیت است.
// مقادیر واقعی را در config.local.php (که گیت‌نمی‌شود) بگذارید یا در install_db.php وارد کنید.
if (!defined('DB_HOST')) define('DB_HOST', 'CHANGE_ME');
if (!defined('DB_NAME')) define('DB_NAME', 'CHANGE_ME');
if (!defined('DB_USER')) define('DB_USER', 'CHANGE_ME');
if (!defined('DB_PASS')) define('DB_PASS', 'CHANGE_ME');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// App Settings
define('APP_NAME', 'درخت سپید تجارت پارس');
define('APP_VERSION', '18.0.0');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('BACKUP_DIR', __DIR__ . '/backups/');
define('LOGO_DIR', __DIR__ . '/uploads/logos/');
define('RESID_DIR', __DIR__ . '/uploads/resid/');

// Session
$__session_secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $__session_secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
unset($__session_secure);
date_default_timezone_set('Asia/Tehran');

// Error logging: خطاها در لاگ ثبت شوند اما جزئیات داخلی به کاربر نمایش داده نشود.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// §fix v22: صفحات PHP هرگز کش نشوند — قبلاً سرویس‌ورکر با respondWith تازگی را
// تضمین می‌کرد که با ریدایرکت‌ها تضاد داشت؛ حالا این هدر آن کار را می‌کند.
if (!headers_sent()) {
    header('Cache-Control: no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// Database Connection
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("خطا در اتصال به دیتابیس: " . $e->getMessage());
}

// §v10: ساعت MySQL رو با ساعت تهران هم‌راستا کن — قبلاً MySQL با ساعت UTCِ هاست کار می‌کرد
// و NOW()/CURDATE()/HOUR() نسبت به date() در PHP حدود ۳.۵ ساعت اختلاف داشت
// (ریشهٔ «رفتن گزارش‌های صبح تو شیفت شب» و جابه‌جایی تاریخ نزدیک نیمه‌شب بود)
try { $pdo->exec("SET time_zone = '+03:30'"); } catch (PDOException $e) { /* هاست اجازه نداد — با ساعت PHP ادامه می‌دهیم */ }

// §v11: تازه‌سازی پرمیشن‌های سشن از دیتابیس در هر درخواست — وقتی ادمین دسترسی کسی را
// از صفحه پرسنل تغییر می‌دهد، بلافاصله (بدون نیاز به ورود مجدد) اعمال شود
if (isset($_SESSION['user_id'])) {
    try {
        $__st = $pdo->prepare("SELECT permission FROM user_permissions WHERE user_id = ?");
        $__st->execute([$_SESSION['user_id']]);
        $_SESSION['permissions'] = $__st->fetchAll(PDO::FETCH_COLUMN);
        unset($__st);
    } catch (Throwable $e) {}
}

// Language system
require_once __DIR__ . '/includes/lang.php';

// Salary calculation functions
require_once __DIR__ . '/includes/salary_calc.php';
// WTTP COIN ledger helpers (safe no-op until migrate.php has been run)
require_once __DIR__ . '/includes/arash_coin.php';
// Operational helpers: backups, anti-cheat, system messages and audit utilities
require_once __DIR__ . '/includes/operations_hub.php';

// Helper Functions
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function require_permission($perm) {
    if (!isset($_SESSION['permissions']) || !in_array($perm, $_SESSION['permissions'])) {
        http_response_code(403);
        echo json_encode(['error' => t('error_access_denied')]);
        exit;
    }
}

/**
 * §v18: بعد از ثبت/ویرایش (POST) فیلترهای صفحهٔ قبلی (ماه/سال، تاریخ، پیمانکار، تب…) حفظ شود.
 * فقط وقتی مقصد ریدایرکت همان صفحه است و خودش آن پارامتر را ندارد، از صفحهٔ مبدأ کپی می‌شود.
 */
function redirect_keep_context($url) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return $url;
    $ctx = (string)($_POST['_ctx'] ?? '');
    if ($ctx === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $ref = parse_url((string)$_SERVER['HTTP_REFERER']);
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!empty($ref['host']) && $host !== '' && stripos($host, $ref['host']) !== 0) return $url;
        $ctx = (string)($ref['query'] ?? '');
    }
    $ctx = ltrim($ctx, '?');
    if ($ctx === '' || strpos($url, 'index.php?') !== 0) return $url;
    $frag = '';
    if (($h = strpos($url, '#')) !== false) { $frag = substr($url, $h); $url = substr($url, 0, $h); }
    parse_str($ctx, $from);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $to);
    if (empty($from['page']) || ($to['page'] ?? '') !== $from['page']) return $url . $frag;
    static $keep = ['fy','fm','all','month','year','m','y','jy','jm','jd','fmonth','fday','fcat','ftype','cat','type','shift','sort','dir','tab','date','date_from','date_to','from','to','search','scope','view','mode','user','detail_user','folder','lm','jalali_year','jalali_month','channel_id','ws'];
    $add = [];
    foreach ($keep as $k) {
        if (isset($from[$k]) && !isset($to[$k]) && is_scalar($from[$k]) && $from[$k] !== '') $add[$k] = $from[$k];
    }
    if ($add) $url .= '&' . http_build_query($add);
    return $url . $frag;
}

function redirect($url) {
    $url = redirect_keep_context($url);
    header("Location: $url");
    exit;
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generate_token() {
    return bin2hex(random_bytes(32));
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * §v13: JSON امن برای گذاشتن داخل onclick='fn(...)'
 *
 * قبلاً از json_encode با JSON_HEX_QUOT|JSON_HEX_APOS استفاده می‌شد. آن پرچم‌ها
 * «همهٔ» کوتیشن‌ها را به \u0022 و \u0027 تبدیل می‌کنند — از جمله کوتیشن‌هایی که
 * ساختار خود JSON هستند. نتیجه رشته‌ای بود که جاوااسکریپت نمی‌توانست تجزیه کند
 * و کل هندلر با SyntaxError می‌مرد (دکمه بی‌اثر به نظر می‌رسید).
 *
 * راه درست: json_encode معمولی + htmlspecialchars با ENT_QUOTES. اینجا فقط
 * آپستروف به &#39; تبدیل می‌شود که مرورگر قبل از اجرای JS آن را بازمی‌گرداند.
 */
function js_arg($value) {
    return htmlspecialchars(
        json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ENT_QUOTES,
        'UTF-8'
    );
}

function get_csrf_field() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_token();
    }
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

// Jalali (Shamsi) Date Functions
function gregorian_to_jalali($g_y, $g_m, $g_d) {
    if (!$g_y || !$g_m || !$g_d || $g_m < 1 || $g_m > 12 || $g_d < 1 || $g_d > 31) {
        return [0, 0, 0];
    }
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    
    $gy = $g_y - 1600;
    $gm = $g_m - 1;
    $gd = $g_d - 1;
    
    $g_day_no = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
    for ($i = 0; $i < $gm; ++$i) {
        $g_day_no += $g_days_in_month[$i];
    }
    if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
        $g_day_no++;
    }
    $g_day_no += $gd;
    
    $j_day_no = $g_day_no - 79;
    $j_np = floor($j_day_no / 12053);
    $j_day_no %= 12053;
    
    $jy = 979 + 33 * $j_np + 4 * floor($j_day_no / 1461);
    $j_day_no %= 1461;
    
    if ($j_day_no >= 366) {
        $jy += floor(($j_day_no - 1) / 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }
    
    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) {
        $j_day_no -= $j_days_in_month[$i];
    }
    $jm = $i + 1;
    $jd = $j_day_no + 1;
    
    return [$jy, $jm, $jd];
}

function jalali_to_gregorian($j_y, $j_m, $j_d) {
    if (!$j_y || !$j_m || !$j_d || $j_m < 1 || $j_m > 12 || $j_d < 1 || $j_d > 31) {
        return [0, 0, 0];
    }
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    
    $jy = $j_y - 979;
    $jm = $j_m - 1;
    $jd = $j_d - 1;
    
    $j_day_no = 365 * $jy + floor($jy / 33) * 8 + floor(($jy % 33 + 3) / 4);
    for ($i = 0; $i < $jm; ++$i) {
        $j_day_no += $j_days_in_month[$i];
    }
    $j_day_no += $jd;
    
    $g_day_no = $j_day_no + 79;
    
    $gy = 1600 + 400 * floor($g_day_no / 146097);
    $g_day_no %= 146097;
    
    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100 * floor($g_day_no / 36524);
        $g_day_no %= 36524;
        if ($g_day_no >= 365) {
            $g_day_no++;
        } else {
            $leap = false;
        }
    }
    
    $gy += 4 * floor($g_day_no / 1461);
    $g_day_no %= 1461;
    
    if ($g_day_no >= 366) {
        $leap = false;
        $g_day_no--;
        $gy += floor($g_day_no / 365);
        $g_day_no %= 365;
    }
    
    for ($i = 0; $g_day_no >= $g_days_in_month[$i] + ($i == 1 && $leap ? 1 : 0); $i++) {
        $g_day_no -= $g_days_in_month[$i] + ($i == 1 && $leap ? 1 : 0);
    }
    $gm = $i + 1;
    $gd = $g_day_no + 1;
    
    return [$gy, $gm, $gd];
}

function to_jalali($date) {
    if (!$date) return '';
    $parts = explode('-', $date);
    if (count($parts) != 3) return '';
    $j = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
    if ($j[0] == 0 && $j[1] == 0 && $j[2] == 0) return '';
    if ($j[0] < 1 || $j[1] < 1 || $j[2] < 1) return '';
    return $j[0] . '/' . str_pad($j[1], 2, '0', STR_PAD_LEFT) . '/' . str_pad($j[2], 2, '0', STR_PAD_LEFT);
}

/** تبدیل اعداد فارسی/عربی به انگلیسی؛ برای همهٔ تاریخ‌ها و مبلغ‌های تایپی استفاده می‌شود. */
function normalize_digits($value) {
    return strtr((string)$value, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
}

function to_gregorian($date) {
    if (!$date) return '';
    $date = trim(normalize_digits($date));
    // ورودی میلادیِ داخلی سیستم را دست‌نخورده برگردان؛ فقط تاریخ‌های شمسی
    // (با / یا .) باید تبدیل شوند.
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $gm)) {
        if (checkdate((int)$gm[2], (int)$gm[3], (int)$gm[1])) {
            return sprintf('%04d-%02d-%02d', (int)$gm[1], (int)$gm[2], (int)$gm[3]);
        }
        return '';
    }
    $date = str_replace('.', '/', $date);
    $parts = explode('/', $date);
    if (count($parts) != 3) return $date;
    $jy = (int)$parts[0]; $jm = (int)$parts[1]; $jd = (int)$parts[2];
    if ($jy < 1 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return '';
    $g = jalali_to_gregorian($jy, $jm, $jd);
    if (empty($g[0]) || empty($g[1]) || empty($g[2]) || !checkdate((int)$g[1], (int)$g[2], (int)$g[0])) return '';
    return $g[0] . '-' . str_pad($g[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($g[2], 2, '0', STR_PAD_LEFT);
}

function jalali_now() {
    return to_jalali(date('Y-m-d'));
}

function jalali_month_name($m) {
    global $lang;
    $names = [
        'fa' => ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
        'ar' => ['', 'فروردين', 'أرديبهشت', 'خرداد', 'تير', 'مرداد', 'شهريور', 'مهر', 'آبان', 'آذر', 'دي', 'بهمن', 'إسفند'],
        'en' => ['', 'Farvardin', 'Ordibehesht', 'Khordad', 'Tir', 'Mordad', 'Shahrivar', 'Mehr', 'Aban', 'Azar', 'Dey', 'Bahman', 'Esfand'],
    ];
    return $names[$lang ?? 'fa'][$m] ?? '';
}

function localized_weekday_names() {
    global $lang;
    return [
        'fa' => ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'],
        'ar' => ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'],
        'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    ][$lang ?? 'fa'];
}

// Permission check helper
// Toast message helper
function showToast($message, $type = 'success') {
    $_SESSION['toast_message'] = $message;
    $_SESSION['toast_type'] = $type;
}

function has_permission($perm) {
    // ادمین و مدیر پروژه همیشه به همه‌چیز دسترسی دارند
    if (is_strict_admin() || is_project_manager()) return true;
    if (isset($_SESSION['permissions']) && in_array($perm, $_SESSION['permissions'])) return true;
    // §v11: «ویراستار» هر صفحه، پرمیشن‌های مدیریتی همان صفحه را هم دارد (پل سیستم قدیم به جدید)
    static $perm_page = [
        'manage_personnel'     => 'personnel',
        'manage_salary'        => 'salary',
        'manage_advances'      => 'advances',
        'manage_leaves'        => 'leaves',
        'manage_work_hours'    => 'work_hours',
        'manage_bonus_penalty' => 'bonus_penalty',
        'manage_subcontractors'=> 'subcontractors',
        'manage_contracts'     => 'contracts',
        'manage_inventory'     => 'inventory',
        'manage_purchases'     => 'inventory',
        'manage_arash_coins'   => 'arash_coin_admin',
        'manage_workshops'     => 'workshops',
        'office_manager'       => 'personnel',
        'audit_log'            => 'audit',
        'view_reports'         => 'rep_salary',
        'export_data'          => 'rep_yearly',
    ];
    if (isset($perm_page[$perm]) && function_exists('access_mode')) {
        return access_mode($perm_page[$perm]) === 'editor';
    }
    return false;
}

// ═══════════════════════════════════════════════════════════════
// §v11: سیستم دسترسی صفحه‌ای — هر صفحه: ویراستار (مدیریت کامل) / ناظر (فقط مشاهده)
// ═══════════════════════════════════════════════════════════════
// اکانت‌های ویژه که دسترسی‌شان مانند قبل و طبق روال خودشان است:
function access_special_usernames() { return ['yaser', 'javidhse']; }
function access_is_special_account($username = null) {
    $u = $username ?? ($_SESSION['username'] ?? '');
    return in_array($u, access_special_usernames(), true);
}

// رجیستری صفحات: personal = پایه (برای همه فعال، تیک = دیدن/مدیریت اطلاعات دیگران)
// manage = مدیریتی (بدون تیک = مخفی و بسته) | children = زیرگروه فرم‌ها و گزارش‌ها
function page_access_registry() {
    return [
        'personal' => [
            'title' => t('صفحات پایه') . ' — ' . t('برای همه با اطلاعات خودشان فعال‌اند (تیک = دسترسی به اطلاعات دیگران هم)'),
            'items' => [
                'work_hours'    => '⏰ ' . t('ساعت کاری'),
                'salary'        => '💰 ' . t('حقوق'),
                'advances'      => '💵 ' . t('مساعده'),
                'leaves'        => '📅 ' . t('مرخصی'),
                'daily_reports' => '📝 ' . t('گزارش کار روزانه'),
                'work_orders'   => '📋 ' . t('دستور کار'),
                'arash_coins'   => arash_coin_icon_markup('arash-coin-nav-icon') . t('کیف WTTP COIN'),
            ],
        ],
        'manage' => [
            'title' => t('صفحات مدیریتی') . ' — ' . t('بدون تیک مخفی و بسته‌اند'),
            'items' => [
                'personnel'      => '👥 ' . t('پرسنل'),
                'petty_cash'     => t('تنخواه') . ' (' . t('واریز') . '/' . t('تأیید') . ')',
                'bonus_penalty'  => '⭐ ' . t('پاداش و جریمه'),
                'subcontractors' => '🏗️ ' . t('پیمانکاران'),
                'contracts'      => '📄 ' . t('قراردادها'),
                'inventory'      => '📦 ' . t('انبار'),
                'workshops'      => '🏭 ' . t('کارگاه‌ها'),
                'archive'        => '🗃️ ' . t('بایگانی'),
                'audit'          => '📜 ' . t('لاگ تغییرات'),
                'settings'       => '⚙️ ' . t('تنظیمات'),
                'arash_coin_admin'=> arash_coin_icon_markup('arash-coin-nav-icon') . t('مدیریت WTTP COIN'),
                'reports'        => '📊 ' . t('گزارش‌ها'),
                'forms'          => '🧾 ' . t('فرم‌ها'),
            ],
        ],
        'children' => [
            'reports' => [
                'rep_salary'         => t('گزارش حقوق و دستمزد'),
                'rep_work_hours'     => t('گزارش کارکرد (ساعت کاری)'),
                'rep_bonuses'        => t('گزارش پاداش و جریمه'),
                'rep_subcontractors' => t('گزارش پیمانکاران'),
                'rep_monthly'        => t('بیلان ماهانه'),
                'rep_yearly'         => t('بیلان سالانه'),
            ],
            'forms' => [
                'form_ejra'       => t('فرم گزارش روزانه اجرا'),
                'form_hse'        => t('فرم گزارش روزانه واحد HSE'),
                'form_anbar'      => t('فرم گزارش کار انباردار'),
                'form_emp_pay'    => t('لیست واریزی کارمندان'),
                'form_worker_pay' => t('لیست واریزی کارگران'),
                'form_sub_pay'    => t('فرم واریزی پیمانکاران'),
                'form_petty_month'=> t('فرم تنخواه ماهانه'),
                'form_inv_count'  => t('فرم موجودی انبار / انبارگردانی'),
            ],
        ],
    ];
}
function access_personal_keys() { return array_keys(page_access_registry()['personal']['items']); }
function access_group_children($group) { $r = page_access_registry(); return array_keys($r['children'][$group] ?? []); }
function access_all_valid_keys() {
    $r = page_access_registry();
    $keys = array_merge(array_keys($r['personal']['items']), array_keys($r['manage']['items']));
    foreach ($r['children'] as $ch) $keys = array_merge($keys, array_keys($ch));
    return $keys;
}

// نقشهٔ دسترسی کاربر از دیتابیس (با کش درخواستی)
// §v12: ردیف‌های «ناظر موقت» (expires_at) بعد از پایان همان روز خودشان غیرفعال می‌شوند
function user_access_map(PDO $pdo, $user_id) {
    static $cache = [];
    static $cleaned_today = false;
    $user_id = (int)$user_id;
    // پاک‌سازی تنبل ردیف‌های منقضی — حداکثر یک‌بار در هر درخواست
    if (!$cleaned_today) {
        $cleaned_today = true;
        try { $pdo->exec("DELETE FROM user_page_access WHERE expires_at IS NOT NULL AND expires_at < CURDATE()"); } catch (Throwable $e) {}
    }
    if (!array_key_exists($user_id, $cache)) {
        $cache[$user_id] = [];
        try {
            $st = $pdo->prepare("SELECT page_key, mode FROM user_page_access WHERE user_id = ? AND (expires_at IS NULL OR expires_at >= CURDATE())");
            $st->execute([$user_id]);
            foreach ($st->fetchAll() as $row) $cache[$user_id][$row['page_key']] = $row['mode'];
        } catch (Throwable $e) {
            try { // fallback اگر ستون expires_at هنوز migrate نشده
                $st = $pdo->prepare("SELECT page_key, mode FROM user_page_access WHERE user_id = ?");
                $st->execute([$user_id]);
                foreach ($st->fetchAll() as $row) $cache[$user_id][$row['page_key']] = $row['mode'];
            } catch (Throwable $e2) {}
        }
        if (in_array('workshop_manager', $_SESSION['permissions'] ?? [], true) && (int)($_SESSION['user_id'] ?? 0) === $user_id) {
            // پرچم مدیر کارگاه خودِ کاربر جاری — در access_mode جداگانه مدیریت می‌شود
        }
    }
    return $cache[$user_id];
}
function user_access_map_for_admin(PDO $pdo) { // برای پرسنل: نقشهٔ همه کاربران یک‌جا
    $out = [];
    try {
        foreach ($pdo->query("SELECT user_id, page_key, mode, expires_at FROM user_page_access WHERE (expires_at IS NULL OR expires_at >= CURDATE())") as $row) {
            $out[$row['user_id']][$row['page_key']] = $row['mode'];
            if (!empty($row['expires_at'])) $out[$row['user_id']]['@temp'][] = $row['page_key']; // ناظر موقت تا پایان امروز
        }
        foreach ($pdo->query("SELECT DISTINCT user_id FROM user_permissions WHERE permission = 'workshop_manager'") as $row) {
            $out[$row['user_id']]['@wm'] = 'editor';
        }
        foreach ($pdo->query("SELECT id, role FROM users WHERE role IN ('project_manager', 'nazer_kol', 'nazer', 'karfarma')") as $row) {
            $out[$row['id']]['@role'] = $row['role'];
        }
    } catch (Throwable $e) {
        try {
            foreach ($pdo->query("SELECT user_id, page_key, mode FROM user_page_access") as $row) {
                $out[$row['user_id']][$row['page_key']] = $row['mode'];
            }
            foreach ($pdo->query("SELECT DISTINCT user_id FROM user_permissions WHERE permission = 'workshop_manager'") as $row) {
                $out[$row['user_id']]['@wm'] = 'editor';
            }
            foreach ($pdo->query("SELECT id, role FROM users WHERE role IN ('project_manager', 'nazer_kol', 'nazer', 'karfarma')") as $row) {
                $out[$row['id']]['@role'] = $row['role'];
            }
        } catch (Throwable $e2) {}
    }
    return $out;
}

// حالت دسترسی کاربر جاری به یک کلید: null | 'personal' | 'viewer' | 'editor'
function access_mode($page_key) {
    global $pdo;
    if (is_strict_admin() || is_project_manager()) return 'editor'; // مدیر سایت و مدیر پروژه: دسترسی کامل
    if (is_nazer_kol()) return 'viewer';                             // ناظر کل: فقط مشاهده
    if (is_karfarma()) {                                             // کارفرما: فقط فرم‌ها و گزارش‌های روزانه/HSE
        if (in_array($page_key, ['forms', 'form_ejra', 'form_hse', 'report_print', 'hse_report'], true)) return 'viewer';
        $personal = in_array($page_key, access_personal_keys(), true);
        return $personal ? 'personal' : null;
    }
    $personal = in_array($page_key, access_personal_keys(), true);
    if (access_is_special_account()) {
        // yaser / javidhse: دسترسی‌شان فقط با قوانین ویژهٔ خودشان (در index.php) — اینجا دست نمی‌خوریم
        return $personal ? 'personal' : null;
    }
    if (is_workshop_manager()) return 'editor';            // مدیر کارگاه: کامل در کارگاه خود
    $map = $pdo ? user_access_map($pdo, (int)($_SESSION['user_id'] ?? 0)) : [];
    if (isset($map[$page_key])) return $map[$page_key];
    // دسترسی‌های قدیمیِ زیرصفحه‌های انبار، بعد از ادغام همچنان دسترسی به ماژول انبار می‌دهند.
    if ($page_key === 'inventory') {
        foreach (['inventory_serials', 'purchases', 'depreciation'] as $legacy_key) {
            if (isset($map[$legacy_key])) return $map[$legacy_key];
        }
    }
    if ($page_key === 'work_hours' && isset($map['attendance_alerts'])) return $map['attendance_alerts'];
    return $personal ? 'personal' : null;
}

// دسترسی به هرزیرگروه یک گروه (مثلاً یکی از تب‌های گزارش‌ها)
function access_group_mode($group) {
    foreach (access_group_children($group) as $k) {
        $m = access_mode($k);
        if ($m === 'editor') return 'editor';
    }
    foreach (access_group_children($group) as $k) {
        $m = access_mode($k);
        if ($m === 'viewer') return 'viewer';
    }
    return null;
}

// نگاشت صفحه‌های routed به کلید دسترسی (برای گیت مرکزی index.php)
function access_gate_page_map() {
    return [
        'personnel'      => 'personnel',
        'workshops'      => 'workshops',
        'subcontractors' => 'subcontractors',
        'bonus_penalty'  => 'bonus_penalty',
        'contracts'      => 'contracts',
        'inventory'      => 'inventory',
        // مسیرهای قدیمی برای دسترسی و لینک‌های ذخیره‌شده، به بخش‌های داخلی انبار هدایت می‌شوند.
        'inventory_serials' => 'inventory',
        'inventory_label_print' => 'inventory',
        'depreciation'    => 'inventory',
        'purchases'       => 'inventory',
        'attendance_alerts'=> 'work_hours',
        'personnel_profile'=> 'personnel',
        'arash_coins'    => 'arash_coins',
        'archive'        => 'archive',
        'audit'          => 'audit',
        'settings'       => 'settings',
        'report_print'          => 'form_ejra',
        'hse_report'            => 'form_hse',
        'anbar_report'          => 'form_anbar',
        'employee_payment_print'=> 'form_emp_pay',
        'worker_payment_print'  => 'form_worker_pay',
        'subcontractor_payment_print' => 'form_sub_pay',
        'petty_cash_print'      => 'form_petty_month',
        'inventory_count_print' => 'form_inv_count',
    ];
}

// نگاشت تب‌های گزارش‌ها به کلید دسترسی
function access_report_type_keys() {
    return [
        'salary'         => 'rep_salary',
        'work_hours'     => 'rep_work_hours',
        'bonuses'        => 'rep_bonuses',
        'subcontractors' => 'rep_subcontractors',
        'monthly_full'   => 'rep_monthly',
        'yearly_full'    => 'rep_yearly',
    ];
}

// Role helpers — available to all pages before header.php
function is_strict_admin() { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function is_project_manager() {
    return (isset($_SESSION['role']) && $_SESSION['role'] === 'project_manager')
        || in_array('project_manager', $_SESSION['permissions'] ?? [], true);
}
function is_nazer_kol() {
    return (isset($_SESSION['role']) && in_array($_SESSION['role'], ['nazer', 'nazer_kol'], true))
        || in_array('nazer_kol', $_SESSION['permissions'] ?? [], true);
}
function is_nazer() {
    return is_nazer_kol();
}
function is_karfarma() {
    return (isset($_SESSION['role']) && $_SESSION['role'] === 'karfarma')
        || in_array('karfarma', $_SESSION['permissions'] ?? [], true)
        || (($_SESSION['username'] ?? '') === 'yaser');
}
function is_workshop_manager() {
    return in_array('workshop_manager', $_SESSION['permissions'] ?? [], true)
        || (isset($_SESSION['role']) && in_array($_SESSION['role'], ['manager', 'workshop_manager'], true));
}
function can_see_all_workshops() {
    return is_strict_admin() || is_project_manager() || is_nazer_kol();
}
function can_see_others_hours() {
    return is_strict_admin() || is_project_manager() || is_workshop_manager() || is_nazer_kol();
}
function can_edit() {
    return !is_nazer_kol() && !is_karfarma();
}

/**
 * بررسی دسترسی کاربر به مدیریت ساعت کاری یک کارگاه خاص
 */
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

/**
 * بررسی مجوز تغییر یا حذف ساعت نیروی مأمور از کارگاه دیگر (بند ۱)
 * مدیر کارگاه مقصد اجازه تغییر یا حذف ساعت نیروی مأمور را ندارد.
 * فقط مدیر پروژه یا کاربران دارای دسترسی در کارگاه مبدأ مجاز هستند.
 */
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

    // اگر رکورد مأموریت از کارگاه دیگر است: فقط کاربران دارای دسترسی در کارگاه مبدأ مجازند
    $uid = (int)($_SESSION['user_id'] ?? 0);
    return can_user_manage_ws_work_hours($pdo, $uid, $source_ws);
}

/**
 * دریافت تنظیمات با اولویت تنظیمات اختصاصی کارگاه و فال‌بک به تنظیمات عمومی
 */
function get_workshop_settings(PDO $pdo, ?int $ws_id = null): array {
    $settings = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        $wid = $ws_id ?? ($_SESSION['workshop_id'] ?? 0);
        if ($wid > 0) {
            $st = $pdo->prepare("SELECT setting_key, setting_value FROM workshop_settings WHERE workshop_id = ?");
            $st->execute([$wid]);
            while ($row = $st->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Throwable $e) {}
    return $settings;
}

// Work unit (واحد) detection from work_role: ejra | hse | anbar
function team_from_work_role($work_role) {
    $role = mb_strtolower(trim((string)$work_role));
    if (strpos($role, 'hse') !== false) return 'hse';
    if (strpos($role, 'انبار') !== false) return 'anbar';
    return 'ejra';
}
function current_user_team(PDO $pdo, $user_id) {
    $st = $pdo->prepare("SELECT work_role FROM users WHERE id = ?");
    $st->execute([$user_id]);
    return team_from_work_role($st->fetchColumn() ?: '');
}
function team_labels() {
    return ['ejra' => t('واحد اجرا'), 'hse' => t('واحد HSE'), 'anbar' => t('انباردار')];
}

// بررسی وجود ستون در جدول (با کش) — برای سازگاری قبل/بعد اجرای migrate.php
function db_has_column(PDO $pdo, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
            $st->execute([$table, $column]);
            $cache[$key] = (bool)$st->fetch();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

// تشخیص شیفت (روز/شب) یک کاربر در یک تاریخ — برای تگ زدن گزارش‌های روزانه
// اول ساعت کاری ثبت‌شده؛ اگر نبود، بر اساس ساعت فعلی سرور (۲۱ شب تا ۷ صبح = شب)
function detect_report_shift(PDO $pdo, $ws_id, $user_id, $date) {
    try {
        $st = $pdo->prepare("SELECT start_time, is_night FROM work_hours WHERE workshop_id = ? AND user_id = ? AND work_date = ?");
        $st->execute([$ws_id, $user_id, $date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $h = (int)substr((string)$r['start_time'], 0, 2);
            if (!empty($r['is_night']) || $h >= 18 || $h < 6) return 'night';
        }
        if (!empty($rows)) return 'day';
    } catch (Throwable $e) {}
    if ($date === date('Y-m-d')) {
        $h = (int)date('G');
        if ($h >= 21 || $h < 7) return 'night';
    }
    return 'day';
}

// Menu item keys with Persian labels — used for the menu-order setting (config + settings page)
function nav_menu_labels() {
    return [
        'dashboard'      => t('nav_dashboard'),
        'work_hours'     => t('nav_work_hours'),
        'rest_track'     => 'استراحت',
        'personnel'      => t('nav_personnel'),
        'salary'         => t('nav_salary'),
        'advances'       => t('nav_advances'),
        'leaves'         => t('nav_leaves'),
        'work_orders'    => t('nav_work_orders'),
        'reports'        => t('nav_reports'),
        'workshops'      => t('nav_workshops'),
        'subcontractors' => t('nav_subcontractors'),
        'petty_cash'     => t('nav_petty_cash'),
        'bonus_penalty'  => t('nav_bonus_penalty'),
        'contracts'      => t('nav_contracts'),
        'audit'          => t('nav_audit'),
        'settings'       => t('nav_settings'),
        'inventory'      => t('nav_inventory'),
        'arash_coins'    => t('nav_arash_coins'),
        'profile'        => t('nav_profile'),
        'chat'           => t('nav_chat'),
        'daily_reports'  => t('nav_daily_reports'),
        'archive'        => t('nav_archive'),
        'forms'          => t('nav_forms'),
    ];
}

/**
 * Compress an uploaded image file — auto-resize + quality reduction.
 * Saves back to the same path. Returns the path.
 * Max width/height: $max_px (default 1200). Quality: 82 (good balance).
 */
function compress_image($source_path, $max_px = 1200, $quality = 82) {
    if (!file_exists($source_path)) return $source_path;

    $mime = mime_content_type($source_path);
    $is_image = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    if (!$is_image) return $source_path;

    // Detect source type
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($source_path); $type = 'jpg'; break;
        case 'image/png':  $src = @imagecreatefrompng($source_path);  $type = 'png'; break;
        case 'image/gif':  $src = @imagecreatefromgif($source_path);  $type = 'gif'; break;
        case 'image/webp': $src = @imagecreatefromwebp($source_path); $type = 'webp'; break;
        default: return $source_path;
    }
    if (!$src) return $source_path;

    $orig_w = imagesx($src);
    $orig_h = imagesy($src);

    // Resize if larger than max
    $new_w = $orig_w;
    $new_h = $orig_h;
    if ($orig_w > $max_px || $orig_h > $max_px) {
        $ratio = min($max_px / $orig_w, $max_px / $orig_h);
        $new_w = (int)($orig_w * $ratio);
        $new_h = (int)($orig_h * $ratio);
    }

    if ($new_w == $orig_w && $new_h == $orig_h) {
        $dst = $src;
    } else {
        $dst = imagecreatetruecolor($new_w, $new_h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
        imagedestroy($src);
    }

    // Save compressed
    $tmp = $source_path . '.tmp';
    switch ($type) {
        case 'jpg': imagejpeg($dst, $tmp, $quality); break;
        case 'png':
            // PNG: reduce quality via palette (lossless for most cases)
            imagepng($dst, $tmp, 6); // compression level 6
            break;
        case 'gif': imagegif($dst, $tmp); break;
        case 'webp': imagewebp($dst, $tmp, $quality); break;
    }
    imagedestroy($dst);

    if (file_exists($tmp) && filesize($tmp) < filesize($source_path)) {
        rename($tmp, $source_path);
    } else {
        @unlink($tmp);
    }

    return $source_path;
}

/**
 * Quick compress for upload: move_uploaded_file + compress in one shot.
 */
function save_compressed_upload($tmp_name, $dest_dir, $filename, $max_px = 1200, $quality = 82) {
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0777, true);
    $full_path = $dest_dir . $filename;
    move_uploaded_file($tmp_name, $full_path);
    compress_image($full_path, $max_px, $quality);
    return $full_path;
}
