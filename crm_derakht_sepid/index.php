<?php
require_once 'config.php';
require_once 'includes/temp_grants.php'; // §v16: دسترسی موقت ثبت ساعت

$page = $_GET['page'] ?? 'login';
$action = $_GET['action'] ?? '';

// ═══ مسیریابی امن اکشن‌ها بدون کلمه کلیدی api (جلوگیری از خطای 403 Forbidden فایروال OpenResty) ═══
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    require_once __DIR__ . '/api/download_excel.php';
    exit;
}
if ((isset($_GET['action']) && $_GET['action'] === 'switch_workshop') || isset($_GET['switch_workshop'])) {
    require_once __DIR__ . '/api/switch_workshop.php';
    exit;
}
// سازگاری با درخواست‌های احتمالی قبلی
if (isset($_GET['api'])) {
    $__api = (string)$_GET['api'];
    if ($__api === 'work_orders') {
        require_once __DIR__ . '/api/work_orders.php';
        exit;
    }
    if ($__api === 'notifications') {
        require_once __DIR__ . '/api/notifications.php';
        exit;
    }
    if ($__api === 'download_excel') {
        require_once __DIR__ . '/api/download_excel.php';
        exit;
    }
    if ($__api === 'switch_workshop') {
        require_once __DIR__ . '/api/switch_workshop.php';
        exit;
    }
}

// همهٔ POSTهای عبوری از index باید توکن CSRF معتبر داشته باشند؛ ورود استثناست چون هنوز نشست معتبر ندارد.
// FIXED: به جای exit خام، با toast و ریدایرکت برگرد تا کاربر متوجه شود و دوباره تلاش کند
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'login' && !verify_csrf($_POST['csrf_token'] ?? '')) {
    error_log('CSRF failed for user ' . ($_SESSION['user_id'] ?? 0) . ' page ' . $page . ' IP ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    // اگر درخواست AJAX/JSON بود، همان 419 را برگردان
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => t('error_invalid_request'), 'code' => 419]);
        exit;
    }
    // برای فرم‌های معمولی، با پیام برگرد
    if (function_exists('showToast')) {
        showToast(t('error_invalid_request') . ' - لطفاً صفحه را رفرش کنید و دوباره تلاش کنید', 'error');
    }
    $ref = $_SERVER['HTTP_REFERER'] ?? "index.php?page=$page";
    // جلوگیری از لوپ بی‌نهایت اگر رفرر خودش POST باشد
    if (strpos($ref, 'index.php') === false) $ref = "index.php?page=$page";
    header("Location: $ref");
    exit;
}

// صفحات مجاز برای yaser و کارفرما
$karfarma_pages = ['login', 'logout', 'dashboard', 'forms', 'profile'];

// صفحات مجاز برای javidhse (اسناد §11 — فقط فرم HSE + پروفایل)
$javidhse_pages = ['login', 'logout', 'forms', 'profile'];

// بررسی yaser و کارفرما
if (is_logged_in() && is_karfarma() && !in_array($page, $karfarma_pages)) {
    redirect('index.php?page=forms');
}

// بررسی javidhse
if (is_logged_in() && ($_SESSION['username'] ?? '') === 'javidhse' && !in_array($page, $javidhse_pages)) {
    redirect('index.php?page=forms');
}

// ناظر کل: فقط خواندنی — هرگونه درخواست POST مسدود می‌شود
if (is_logged_in() && is_nazer_kol() && $_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($page, ['login', 'logout'])) {
    showToast(t('error_viewer_readonly'), 'error');
    redirect('index.php?page=' . $page);
}

// ═══ §v11: گیت دسترسی صفحه‌ای (فقط حساب‌های عادی — ادمین/ناظر/مدیر کارگاه طبق قواعد خودشان) ═══
// yaser و javidhse هم که قبلاً بالا مدیریت شدند؛ اینجا فقط روی صفحات مدیریتی/فرم‌ها اعمال می‌شود
if (is_logged_in() && !access_is_special_account()) {
    $__gate_key = access_gate_page_map()[$page] ?? null;
    if ($__gate_key !== null) {
        $__mode = access_mode($__gate_key);
        if ($__mode === null) {
            showToast(t('error_access_denied'), 'error');
            redirect('index.php?page=dashboard');
        }
        if ($__mode === 'viewer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            showToast(t('error_viewer_readonly'), 'error');
            redirect('index.php?page=' . $page);
        }
    }
}

// §v17: دسترسی موقت دیگر کاربر را قفل نمی‌کند؛ اعمال آن داخل صفحهٔ ساعت کاری است.

// Route handling
switch ($page) {
    case 'login':
        if (is_logged_in()) redirect('index.php?page=dashboard');
        include 'pages/login.php';
        break;
    case 'logout':
        session_destroy();
        redirect('index.php?page=login');
        break;
    case 'dashboard':
        require_login();
        include 'pages/dashboard.php';
        break;
    case 'workshops':
        require_login();
        include 'pages/workshops.php';
        break;
    case 'personnel':
        require_login();
        include 'pages/personnel.php';
        break;
    case 'work_hours':
        require_login();
        include 'pages/work_hours.php';
        break;
    case 'temp_entry':
        require_login();
        redirect('index.php?page=work_hours'); // صفحهٔ کیوسک قدیمی حذف شد
        break;
    case 'rest_track':
        require_login();
        include 'pages/rest_track.php';
        break;
    case 'salary':
        require_login();
        include 'pages/salary.php';
        break;
    case 'advances':
        require_login();
        include 'pages/advances.php';
        break;
    case 'notifications':
        require_login();
        include 'pages/notifications.php';
        break;
    case 'work_orders':
        require_login();
        include 'pages/work_orders.php';
        break;
    case 'petty_cash':
        require_login();
        include 'pages/petty_cash.php';
        break;
    case 'leaves':
        require_login();
        include 'pages/leaves.php';
        break;
    case 'reports':
        require_login();
        include 'pages/reports.php';
        break;
    case 'settings':
        require_login();
        include 'pages/settings.php';
        break;
    case 'profile':
        require_login();
        include 'pages/profile.php';
        break;
    case 'audit':
        require_login();
        include 'pages/audit.php';
        break;
    case 'chat_cleanup':
        require_login();
        include 'pages/chat_cleanup.php';
        break;
    case 'contracts':
        require_login();
        include 'pages/contracts.php';
        break;
    case 'chat':
        require_login();
        include 'pages/chat.php';
        break;
    case 'inventory':
        require_login();
        include 'pages/inventory.php';
        break;
    case 'inventory_serials':
        require_login();
        redirect('index.php?page=inventory&tab=serials' . (isset($_GET['item_id']) ? '&item_id=' . (int)$_GET['item_id'] : ''));
        break;
    case 'inventory_label_print':
        require_login();
        include 'pages/inventory_label_print.php';
        break;
    case 'depreciation':
        require_login();
        redirect('index.php?page=inventory&tab=depreciation');
        break;
    case 'purchases':
        require_login();
        redirect('index.php?page=inventory&tab=purchases');
        break;
    case 'attendance_alerts':
        require_login();
        redirect('index.php?page=work_hours&tab=alerts' . (isset($_GET['date']) ? '&date=' . urlencode($_GET['date']) : ''));
        break;
    case 'personnel_profile':
        require_login();
        include 'pages/personnel_profile.php';
        break;
    case 'channels':
        require_login();
        redirect('index.php?page=chat&view=channels');
        break;
    case 'daily_reports':
        require_login();
        include 'pages/daily_reports.php';
        break;
    case 'archive':
        require_login();
        include 'pages/archive.php';
        break;
    case 'salary_detail':
        require_login();
        include 'pages/salary_detail.php';
        break;
    case 'work_report':
        require_login();
        include 'pages/work_report.php';
        break;
    case 'forms':
        require_login();
        include 'pages/forms.php';
        break;
    case 'anbar_report':
        require_login();
        include 'pages/anbar_report.php';
        break;
    case 'employee_payment_print':
        require_login();
        include 'pages/employee_payment_print.php';
        break;
    case 'petty_cash_print':
        require_login();
        include 'pages/petty_cash_print.php';
        break;
    case 'inventory_count_print':
        require_login();
        include 'pages/inventory_count_print.php';
        break;
    case 'crm_customers':
        require_login();
        include 'pages/crm_customers.php';
        break;
    case 'crm_leads':
        require_login();
        include 'pages/crm_leads.php';
        break;
    case 'crm_deals':
        require_login();
        include 'pages/crm_deals.php';
        break;
    case 'crm_tasks':
        require_login();
        include 'pages/crm_tasks.php';
        break;
    case 'crm_teams':
        require_login();
        include 'pages/crm_teams.php';
        break;
    default:
        if (is_logged_in()) {
            redirect('index.php?page=dashboard');
        } else {
            redirect('index.php?page=login');
        }
}
