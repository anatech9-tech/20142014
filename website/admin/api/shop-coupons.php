<?php
/**
 * Admin API — کدهای تخفیف
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

Auth::requireLogin();
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}
Auth::requirePermission('shop.coupons');

$action = $_POST['action'] ?? 'save';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'delete') {
    DB::execute('DELETE FROM discount_codes WHERE id = ?', [$id]);
    sts_json(200, ['success' => true]);
}

// save
$code = strtoupper(trim((string)($_POST['code'] ?? '')));
if (!preg_match('/^[A-Z0-9_\-]{3,40}$/', $code)) {
    sts_json(422, ['success' => false, 'error' => 'کد تخفیف فقط حروف بزرگ انگلیسی، عدد، خط تیره و زیرخط (۳ تا ۴۰ کاراکتر).']);
}

$type = (string)($_POST['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
$value = (float)($_POST['value'] ?? 0);
if ($type === 'percent') $value = min(100, max(1, $value));
else $value = max(1, $value);

$data = [
    'code'        => $code,
    'type'        => $type,
    'value'       => $value,
    'min_amount'  => max(0, (int)($_POST['min_amount'] ?? 0)),
    'max_uses'    => max(0, (int)($_POST['max_uses'] ?? 0)),
    'expires_at'  => $_POST['expires_at'] !== '' ? date('Y-m-d H:i:s', strtotime((string)$_POST['expires_at'])) : null,
    'is_active'   => (int)($_POST['is_active'] ?? 1),
];

if ($id > 0) {
    DB::update('discount_codes', $data, 'id = :id', ['id' => $id]);
} else {
    $exists = DB::fetch('SELECT id FROM discount_codes WHERE code = ? LIMIT 1', [$code]);
    if ($exists) sts_json(422, ['success' => false, 'error' => 'این کد قبلاً ثبت شده است.']);
    DB::insert('discount_codes', $data);
}
sts_json(200, ['success' => true]);
