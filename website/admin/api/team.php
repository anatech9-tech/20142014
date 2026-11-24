<?php
/**
 * Team Members API
 * Actions: list, save, delete, reorder
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

Auth::requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!Auth::verifyCsrf($csrf)) {
    sts_json(403, ['success' => false, 'error' => 'توکن امنیتی نامعتبر.']);
}

try {
    if ($action === 'save') {
        Auth::requirePermission('team.edit');
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'name'         => sts_clean($_POST['name'] ?? '', 100),
            'role'         => sts_clean($_POST['role'] ?? '', 200),
            'handle'       => sts_clean($_POST['handle'] ?? '', 100),
            'bio'          => sts_clean($_POST['bio'] ?? '', 1000),
            'email'        => sts_clean($_POST['email'] ?? '', 200),
            'linkedin_url' => sts_clean($_POST['linkedin_url'] ?? '', 500),
            'display_order' => (int) ($_POST['display_order'] ?? 0),
            'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
        ];
        if (mb_strlen($data['name']) < 2 || mb_strlen($data['role']) < 2) {
            sts_json(400, ['success' => false, 'error' => 'نام و نقش الزامی است.']);
        }
        if ($id) {
            DB::update('team_members', $data, 'id = :id', ['id' => $id]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = DB::insert('team_members', $data);
        }
        Auth::logActivity(Auth::userId(), $id ? 'update_team' : 'create_team', 'team_member', $id);
        sts_json(200, ['success' => true, 'id' => $id]);
    }

    if ($action === 'delete') {
        Auth::requirePermission('team.delete');
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) sts_json(400, ['success' => false]);
        DB::delete('team_members', 'id = :id', ['id' => $id]);
        Auth::logActivity(Auth::userId(), 'delete_team', 'team_member', $id);
        sts_json(200, ['success' => true]);
    }

    sts_json(400, ['success' => false, 'error' => 'عملیات ناشناخته.']);
} catch (Throwable $e) {
    error_log('Team API error: ' . $e->getMessage());
    sts_json(500, ['success' => false, 'error' => $e->getMessage()]);
}
