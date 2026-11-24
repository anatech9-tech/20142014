<?php
/**
 * فروشگاه — پروفایل و ویرایش اطلاعات
 */
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
// ------------------

use Shop\Customers;

if (!Customers::isLoggedIn()) {
    header('Location: /account/login');
    exit;
}
$shopUser = Customers::user();

$shopTitle  = 'حساب کاربری';
$shopActive = 'account';
require __DIR__ . '/../shop/_header.php';
?>

<div class="page-head">
    <div class="breadcrumb"><a href="/">خانه</a> / حساب کاربری</div>
    <h1>مدیریت حساب کاربری</h1>
</div>

<div style="max-width:680px; margin-bottom:50px;">
    <div id="formErrors"></div>
    <form class="form-card" data-ajax-submit="api/auth.php" data-errors-into="formErrors" style="margin-bottom:20px;">
        <h3 style="color:#fff; margin-top:0;">📇 اطلاعات</h3>
        <input type="hidden" name="mode" value="profile">
        <div class="form-grid">
            <div class="field">
                <label>نام و نام خانوادگی <span class="req">*</span></label>
                <input type="text" name="full_name" required value="<?= htmlspecialchars($shopUser['full_name']) ?>" maxlength="100">
            </div>
            <div class="field">
                <label>ایمیل <span class="req">*</span></label>
                <input type="email" name="email" required value="<?= htmlspecialchars($shopUser['email']) ?>" maxlength="200">
            </div>
            <div class="field">
                <label>موبایل</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($shopUser['phone']) ?>" maxlength="20" placeholder="09xxxxxxxxx">
            </div>
            <div class="field">
                <label>کد پستی</label>
                <input type="text" name="postal_code" value="<?= htmlspecialchars($shopUser['postal_code']) ?>" maxlength="20">
            </div>
            <div class="field">
                <label>استان</label>
                <input type="text" name="province" value="<?= htmlspecialchars($shopUser['province']) ?>" maxlength="100">
            </div>
            <div class="field">
                <label>شهر</label>
                <input type="text" name="city" value="<?= htmlspecialchars($shopUser['city']) ?>" maxlength="100">
            </div>
            <div class="field full">
                <label>آدرس</label>
                <textarea name="address" placeholder="آدرس پیش‌فرض ارسال"><?= htmlspecialchars($shopUser['address']) ?></textarea>
            </div>
        </div>
        <button type="submit" class="btn btn-gold" style="margin-top:18px;">ذخیره اطلاعات</button>
    </form>

    <form class="form-card" data-ajax-submit="api/auth.php" data-errors-into="formErrors">
        <h3 style="color:#fff; margin-top:0;">🔐 تغییر رمز عبور</h3>
        <input type="hidden" name="mode" value="password">
        <div class="form-grid">
            <div class="field">
                <label>رمز فعلی <span class="req">*</span></label>
                <input type="password" name="current_password" required maxlength="200">
            </div>
            <div class="field">
                <label>رمز جدید <span class="req">*</span> (حداقل ۸ کاراکتر)</label>
                <input type="password" name="new_password" required minlength="8" maxlength="200">
            </div>
        </div>
        <button type="submit" class="btn btn-outline" style="margin-top:18px;">تغییر رمز</button>
    </form>

    <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
        <a href="/account/orders" class="btn btn-ghost">📦 سفارش‌های من</a>
        <form data-ajax-submit="api/auth.php" id="logoutForm">
            <input type="hidden" name="mode" value="logout">
            <button type="submit" class="btn btn-ghost" style="color:var(--shop-red);">خروج از حساب</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../shop/_footer.php'; ?>
