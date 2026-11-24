<?php
/**
 * Admin Layout Component
 * استفاده: include این فایل و $pageTitle, $activeMenu, $contentEcho رو پر کنید
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

Auth::requireLogin();

$user = Auth::user();
$currentPage = $activeMenu ?? '';
$pageTitle = $pageTitle ?? 'پنل مدیریت';

// Flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$menu = [
    ['key' => 'dashboard',  'icon' => '🏠', 'label' => 'داشبورد',    'url' => '/admin/',         'perm' => null],
    ['key' => 'messages',   'icon' => '✉️', 'label' => 'پیام‌ها',    'url' => '/admin/messages.php', 'perm' => 'messages.view'],
    ['key' => 'shipments',  'icon' => '📦', 'label' => 'محموله‌ها',   'url' => '/admin/shipments.php','perm' => 'shipments.view'],
    ['key' => 'team',       'icon' => '👥', 'label' => 'تیم',       'url' => '/admin/team.php',     'perm' => 'team.view'],
    ['key' => 'services',   'icon' => '⚙️', 'label' => 'خدمات',     'url' => '/admin/services.php', 'perm' => 'services.view'],
    ['key' => 'blog',       'icon' => '📰', 'label' => 'بلاگ',     'url' => '/admin/blog.php',     'perm' => 'blog.view'],
    ['key' => 'shop-products',  'icon' => '🛍️', 'label' => 'محصولات',      'url' => '/admin/shop-products.php',  'perm' => 'shop.products'],
    ['key' => 'shop-categories','icon' => '🗂️', 'label' => 'دسته‌بندی‌ها', 'url' => '/admin/shop-categories.php','perm' => 'shop.products'],
    ['key' => 'shop-orders',    'icon' => '🧾', 'label' => 'سفارش‌ها',      'url' => '/admin/shop-orders.php',    'perm' => 'shop.orders'],
    ['key' => 'shop-customers', 'icon' => '👤', 'label' => 'مشتریان',       'url' => '/admin/shop-customers.php', 'perm' => 'shop.customers'],
    ['key' => 'shop-reviews',   'icon' => '⭐', 'label' => 'دیدگاه‌ها',     'url' => '/admin/shop-reviews.php',   'perm' => 'shop.reviews'],
    ['key' => 'shop-coupons',   'icon' => '🏷️', 'label' => 'کدهای تخفیف',  'url' => '/admin/shop-coupons.php',   'perm' => 'shop.coupons'],
    ['key' => 'shop-settings',  'icon' => '🛒', 'label' => 'تنظیمات فروشگاه', 'url' => '/admin/shop-settings.php','perm' => 'shop.settings'],
    ['key' => 'analytics',  'icon' => '📊', 'label' => 'آمار',     'url' => '/admin/analytics.php','perm' => 'analytics.view'],
    ['key' => 'users',      'icon' => '🔐', 'label' => 'کاربران',   'url' => '/admin/users.php',    'perm' => 'users.view'],
    ['key' => 'settings',   'icon' => '🛠️', 'label' => 'تنظیمات',   'url' => '/admin/settings.php', 'perm' => 'settings.view'],
];

// Filter menu by permission
$menu = array_filter($menu, function($item) {
    if ($item['perm'] === null) return true;
    return Auth::can($item['perm']);
});

$unreadMessages = 0;
try {
    $unreadMessages = (int) DB::fetchColumn(
        "SELECT COUNT(*) FROM contact_messages WHERE status = 'new'"
    );
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — پنل مدیریت</title>
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
    <link rel="icon" href="/logo.webp" type="image/webp">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken()) ?>">
</head>
<body>
    <button class="mobile-toggle" id="mobileToggle" aria-label="منو">
        <span></span><span></span><span></span>
    </button>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="/logo.webp" alt="لوگو" class="sidebar-logo">
            <div>
                <strong><?= SITE_NAME ?></strong>
                <small>پنل مدیریت</small>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($menu as $item): ?>
                <a href="<?= $item['url'] ?>"
                   class="nav-item <?= $currentPage === $item['key'] ? 'active' : '' ?>">
                    <span class="icon"><?= $item['icon'] ?></span>
                    <span class="label"><?= $item['label'] ?></span>
                    <?php if ($item['key'] === 'messages' && $unreadMessages > 0): ?>
                        <span class="badge"><?= $unreadMessages ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="avatar"><?= mb_substr($user['full_name'], 0, 1) ?></div>
                <div class="user-meta">
                    <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                    <small><?= htmlspecialchars($user['role']) ?></small>
                </div>
            </div>
            <a href="/admin/logout.php" class="logout-btn" title="خروج">↩</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
            <div class="topbar-actions">
                <a href="/" target="_blank" class="btn-icon" title="مشاهده سایت">🌐</a>
            </div>
        </header>

        <?php if ($flashSuccess): ?>
            <div class="alert success"><?= htmlspecialchars($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert error"><?= htmlspecialchars($flashError) ?></div>
        <?php endif; ?>

        <div class="content">
            <?= $contentEcho ?? '' ?>
        </div>
    </main>

    <script src="/admin/assets/js/admin.js"></script>
</body>
</html>
