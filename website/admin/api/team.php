<?php
/**
 * Team Members API
 * Actions: list, save, delete, reorder
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/ContentEditGuard.php';

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
        if ($id && (!isset($_POST['image_path']) || !is_string($_POST['image_path']))) sts_json(400, ['success'=>false, 'error'=>'فیلد تصویر لازم است؛ صفحه را تازه کنید.']);
        $data = [
            'name'         => sts_clean($_POST['name'] ?? '', 100),
            'role'         => sts_clean($_POST['role'] ?? '', 200),
            'handle'       => sts_clean($_POST['handle'] ?? '', 100),
            'bio'          => sts_clean($_POST['bio'] ?? '', 1000),
            'image_path'   => sts_clean($_POST['image_path'] ?? '', 500),
            'email'        => sts_clean($_POST['email'] ?? '', 200),
            'linkedin_url' => sts_clean($_POST['linkedin_url'] ?? '', 500),
            'display_order' => (int) ($_POST['display_order'] ?? 0),
            'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
        ];
        // Only same-site paths; do not embed untrusted external URLs or traversal.
        if ($data['image_path']!=='' && (!preg_match('~^/?(?:assets|uploads/team)/[A-Za-z0-9_./-]+\.(?:png|jpg|jpeg|gif|webp|avif|svg)$~iD',$data['image_path']) || str_contains($data['image_path'],'..') || str_contains($data['image_path'],'//'))) sts_json(400, ['success'=>false, 'error'=>'مسیر تصویر باید مسیر معتبر داخلی باشد.']);
        if (mb_strlen($data['name']) < 2 || mb_strlen($data['role']) < 2) {
            sts_json(400, ['success' => false, 'error' => 'نام و نقش الزامی است.']);
        }
        if ($id) {
            $ok=content_edit_existing(DB::pdo(),'team',$id,$_POST['expected_token'] ?? null,'save',$data,static function() use ($id) { Auth::logActivity(Auth::userId(),'update_team','team_member',$id); });
            if (!$ok) sts_json(409,['success'=>false,'error'=>'عضو تغییر کرده یا دیگر موجود نیست؛ صفحه را تازه کنید.']);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = content_edit_create(DB::pdo(),'team',$data,static function(int $newId) { Auth::logActivity(Auth::userId(),'create_team','team_member',$newId); });
        }
        sts_json(200, ['success' => true, 'id' => $id]);
    }

    if ($action === 'delete') {
        Auth::requirePermission('team.delete');
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) sts_json(400, ['success' => false]);
        $ok=content_edit_existing(DB::pdo(),'team',$id,$_POST['expected_token'] ?? null,'delete',[],static function() use ($id) { Auth::logActivity(Auth::userId(),'delete_team','team_member',$id); });
        if (!$ok) sts_json(409,['success'=>false,'error'=>'عضو تغییر کرده یا دیگر موجود نیست؛ صفحه را تازه کنید.']);
        sts_json(200, ['success' => true]);
    }

    sts_json(400, ['success' => false, 'error' => 'عملیات ناشناخته.']);
} catch (Throwable $e) {
    error_log('Team API error');
    sts_json(500, ['success' => false, 'error' => 'عملیات انجام نشد؛ لاگ سرور را بررسی کنید.']);
}
