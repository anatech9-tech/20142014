<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
Auth::requireLogin();

if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false]);
}

if ($_POST['action'] !== 'save') sts_json(400, ['success' => false]);
Auth::requirePermission('users.edit');

$id = (int) ($_POST['id'] ?? 0);
$data = [
    'username'  => sts_clean($_POST['username'] ?? '', 50),
    'email'     => sts_clean($_POST['email'] ?? '', 200),
    'full_name' => sts_clean($_POST['full_name'] ?? '', 100),
    'role'      => $_POST['role'] ?? 'support',
    'is_active' => !empty($_POST['is_active']) ? 1 : 0,
];

$validRoles = ['superadmin', 'manager', 'sales', 'operations', 'support'];
if (!in_array($data['role'], $validRoles, true)) $data['role'] = 'support';

if (mb_strlen($data['username']) < 3 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    sts_json(400, ['success' => false, 'error' => 'نام کاربری یا ایمیل نامعتبر.']);
}

$password = $_POST['password'] ?? '';
if ($password) {
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        sts_json(400, ['success' => false, 'error' => 'رمز عبور کوتاه است.']);
    }
    $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
}

if ($id) {
    DB::update('admin_users', $data, 'id = :id', ['id' => $id]);
} else {
    if (empty($data['password_hash'])) sts_json(400, ['success' => false, 'error' => 'رمز عبور الزامی است.']);
    $id = DB::insert('admin_users', $data);
}
Auth::logActivity(Auth::userId(), $id ? 'update_user' : 'create_user', 'admin_user', $id);
sts_json(200, ['success' => true, 'id' => $id]);
