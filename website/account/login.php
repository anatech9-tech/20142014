<?php
/**
 * فروشگاه — ورود مشتری
 */
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
// ------------------

use Shop\Customers;

if (Customers::isLoggedIn()) {
    header('Location: /account/');
    exit;
}

$shopTitle  = 'ورود';
$shopActive = 'account';
require __DIR__ . '/../shop/_header.php';
?>

<div class="page-head">
    <div class="breadcrumb"><a href="/">خانه</a> / ورود</div>
    <h1>ورود به حساب کاربری</h1>
</div>

<div style="max-width:480px; margin:20px auto 50px;">
    <div id="formErrors"></div>
    <form class="form-card" data-ajax-submit="/shop/api/auth.php" data-errors-into="formErrors">
        <div class="field" style="margin-bottom:16px;">
            <label>نام کاربری یا ایمیل <span class="req">*</span></label>
            <input type="text" name="login" required autofocus maxlength="200">
        </div>
        <div class="field" style="margin-bottom:20px;">
            <label>رمز عبور <span class="req">*</span></label>
            <input type="password" name="password" required maxlength="200">
        </div>
        <button type="submit" class="btn btn-gold btn-block">ورود</button>
    </form>
    <p style="text-align:center; margin-top:18px; color:var(--shop-muted);">
        حساب ندارید؟ <a href="/account/register.php">ثبت‌نام کنید</a>
    </p>
</div>

<?php require __DIR__ . '/../shop/_footer.php'; ?>
