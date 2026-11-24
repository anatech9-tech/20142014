<?php
/**
 * Admin API — تنظیمات فروشگاه
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/Settings.php';

Auth::requireLogin();
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}
Auth::requirePermission('shop.settings');

$userId = Auth::userId();

Settings::set('shop_name', trim((string)($_POST['shop_name'] ?? 'فروشگاه درخت سپید')), $userId);
Settings::set('shop_per_page', (string)max(4, min(48, (int)($_POST['shop_per_page'] ?? 12))), $userId);
Settings::set('shop_shipping_cost', (string)max(0, (int)($_POST['shop_shipping_cost'] ?? 0)), $userId);
Settings::set('shop_free_shipping_min', (string)max(0, (int)($_POST['shop_free_shipping_min'] ?? 0)), $userId);
Settings::set('shop_usd_manual', trim((string)($_POST['shop_usd_manual'] ?? '')), $userId);
Settings::set('shop_usd_default', (string)max(0, (int)($_POST['shop_usd_default'] ?? 950000)), $userId);
Settings::set('shop_rate_ttl', (string)max(60, min(86400, (int)($_POST['shop_rate_ttl'] ?? 1800))), $userId);

// با تغییر نرخ دستی، کَش نرخ را بی‌اعتبار می‌کنیم تا نرخ جدید بلافاصله اعمال شود
Settings::set('usd_rate_cache', '0', $userId);
Settings::set('usd_rate_cache_ts', '0', $userId);

sts_json(200, ['success' => true]);
