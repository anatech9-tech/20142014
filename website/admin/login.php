<?php
/**
 * Admin Login Page
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

// Already logged in? Redirect to dashboard
if (Auth::isLoggedIn()) {
    sts_redirect('/admin/');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'توکن امنیتی نامعتبر. صفحه را refresh کنید.';
    } else {
        $username = sts_clean($_POST['username'] ?? '', 50);
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);

        $result = Auth::login($username, $password, $remember);
        if ($result['success']) {
            $redirect = $_SESSION['redirect_after_login'] ?? '/admin/';
            unset($_SESSION['redirect_after_login']);
            sts_redirect($redirect);
        }
        $error = $result['error'];
    }
}

$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به پنل — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
    <div class="login-bg"></div>
    <div class="login-box">
        <div class="brand">
            <img src="/logo.webp" alt="لوگو" class="logo">
            <h1>پنل مدیریت</h1>
            <p><?= htmlspecialchars(SITE_NAME) ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="form-field">
                <label for="username">نام کاربری یا ایمیل</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    required
                    autofocus
                    value="<?= htmlspecialchars($username) ?>"
                    autocomplete="username"
                >
            </div>

            <div class="form-field">
                <label for="password">رمز عبور</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                >
            </div>

            <label class="checkbox-row">
                <input type="checkbox" name="remember" value="1">
                <span>مرا به خاطر بسپار (۷ روز)</span>
            </label>

            <button type="submit" class="btn-primary">ورود</button>
        </form>

        <div class="footer-link">
            <a href="/">← بازگشت به سایت</a>
        </div>
    </div>
</body>
</html>
