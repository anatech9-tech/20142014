<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/Settings.php';

Auth::requirePermission('settings.edit');

$action = $_POST['action'] ?? '';
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false]);
}

if ($action === 'save') {
    $data = $_POST['settings'] ?? [];
    if (!is_array($data)) sts_json(400, ['success' => false]);
    foreach ($data as $key => $value) {
        Settings::set($key, $value, Auth::userId());
    }
    Auth::logActivity(Auth::userId(), 'update_settings', null, null, count($data) . ' items');
    sts_json(200, ['success' => true, 'message' => 'تنظیمات ذخیره شد.']);
}

sts_json(400, ['success' => false]);
