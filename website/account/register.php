<?php
/**
 * فروشگاه — ثبت‌نام مشتری
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

$shopTitle  = 'ثبت‌نام';
$shopActive = 'account';
require __DIR__ . '/../shop/_header.php';
?>

<div class="page-head">
    <div class="breadcrumb"><a href="/">خانه</a> / ثبت‌نام</div>
    <h1>ساخت حساب کاربری</h1>
    <p>برای ثبت سفارش سریع‌تر، پیگیری و ثبت نظر</p>
</div>

<div style="max-width:560px; margin:20px auto 50px;">
    <div id="formErrors"></div>
    <form class="form-card" data-ajax-submit="/shop/api/auth.php" data-errors-into="formErrors">
        <input type="hidden" name="mode" value="register">
        <div class="form-grid">
            <div class="field">
                <label>نام و نام خانوادگی <span class="req">*</span></label>
                <input type="text" name="full_name" required maxlength="100">
            </div>
            <div class="field">
                <label>نام کاربری <span class="req">*</span></label>
                <input type="text" name="username" required minlength="3" maxlength="50" placeholder="حداقل ۳ کاراکتر">
            </div>
            <div class="field full">
                <label>ایمیل <span class="req">*</span></label>
                <input type="email" name="email" required maxlength="200">
            </div>
            <div class="field full">
                <label>رمز عبور <span class="req">*</span></label>
                <input type="password" name="password" required minlength="8" maxlength="200" placeholder="حداقل ۸ کاراکتر">
            </div>
        </div>
        <button type="submit" class="btn btn-gold btn-block" style="margin-top:20px;">ثبت‌نام و ورود</button>
    </form>
    <p style="text-align:center; margin-top:18px; color:var(--shop-muted);">
        قبلاً ثبت‌نام کرده‌اید؟ <a href="/account/login.php">وارد شوید</a>
    </p>
</div>

<?php require __DIR__ . '/../shop/_footer.php'; ?>
