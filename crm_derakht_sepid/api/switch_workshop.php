<?php
require_once __DIR__ . '/../config.php';
require_login();

$id = (int)($_GET['id'] ?? 0);

if (can_see_all_workshops()) {
    // ناظر کل، مدیر پروژه و ادمین می‌توانند به هر کارگاه فعالی سوییچ کنند (حتی بدون عضویت در پرسنل)
    $stmt = $pdo->prepare("SELECT * FROM workshops WHERE id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $workshop = $stmt->fetch();
} else {
    $stmt = $pdo->prepare("SELECT w.* FROM workshops w JOIN workshop_personnel wp ON w.id = wp.workshop_id WHERE w.id = ? AND wp.user_id = ? AND w.status = 'active'");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $workshop = $stmt->fetch();
}

if ($workshop) {
    $_SESSION['workshop_id'] = $workshop['id'];
    $_SESSION['workshop_name'] = $workshop['name'];
    $_SESSION['workshop_logo'] = $workshop['logo'];

    if (is_strict_admin() || is_project_manager()) {
        // ادمین و مدیر پروژه: همه پرمیشن‌ها (از همه کارگاه‌ها) حفظ می‌شود
        $perm_stmt = $pdo->prepare("SELECT DISTINCT permission FROM user_permissions WHERE user_id = ?");
        $perm_stmt->execute([$_SESSION['user_id']]);
        $_SESSION['permissions'] = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif (!is_nazer()) {
        $perm_stmt = $pdo->prepare("SELECT permission FROM user_permissions WHERE user_id = ? AND workshop_id = ?");
        $perm_stmt->execute([$_SESSION['user_id'], $id]);
        $_SESSION['permissions'] = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
        // اگر برای این کارگاه پرمیشنی ثبت نشده، پرمیشن‌های سراسری کاربر حفظ شود
        if (empty($_SESSION['permissions'])) {
            $perm_stmt = $pdo->prepare("SELECT DISTINCT permission FROM user_permissions WHERE user_id = ? AND (workshop_id IS NULL OR workshop_id = 0)");
            $perm_stmt->execute([$_SESSION['user_id']]);
            $_SESSION['permissions'] = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

// §fix v22: ریدایرکت مطلق به صفحهٔ کارگاه‌ها.
// قبلاً به HTTP_REFERER (و اگر نبود، مسیر نسبی «index.php») می‌رفت؛ مسیر نسبی
// از داخل /api/ به /api/index.php حل می‌شد و صفحهٔ خراب/کد خام نمایش می‌داد.
$script = $_SERVER['SCRIPT_NAME'] ?? '';
if (strpos($script, '/api/') !== false) {
    $app_root = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');
    redirect(($app_root ? $app_root : '') . '/index.php?page=workshops');
} else {
    redirect('index.php?page=workshops');
}
