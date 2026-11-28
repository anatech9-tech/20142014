<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/ContentEditGuard.php';
Auth::requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF invalid']);
}

try {
    if ($action === 'save') {
        Auth::requirePermission('services.edit');
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'icon_path'      => sts_clean($_POST['icon_path'] ?? '', 500),
            'title_fa'       => sts_clean($_POST['title_fa'] ?? '', 200),
            'title_en'       => sts_clean($_POST['title_en'] ?? '', 200),
            'description_fa' => sts_clean($_POST['description_fa'] ?? '', 1000),
            'description_en' => sts_clean($_POST['description_en'] ?? '', 1000),
            'display_order'  => (int) ($_POST['display_order'] ?? 0),
            'is_active'      => !empty($_POST['is_active']) ? 1 : 0,
        ];
        if (mb_strlen($data['title_fa']) < 2) sts_json(400, ['success' => false, 'error' => 'عنوان فارسی الزامی است.']);
        if ($id) {
            $ok=content_edit_existing(DB::pdo(),'service',$id,$_POST['expected_token'] ?? null,'save',$data,static function() use ($id) { Auth::logActivity(Auth::userId(),'save_service','service',$id); });
            if (!$ok) sts_json(409,['success'=>false,'error'=>'خدمت تغییر کرده یا دیگر موجود نیست؛ صفحه را تازه کنید.']);
        } else {
            $id = DB::insert('services', $data);
            Auth::logActivity(Auth::userId(), 'save_service', 'service', $id);
        }
        sts_json(200, ['success' => true, 'id' => $id]);
    }

    if ($action === 'delete') {
        Auth::requirePermission('services.delete');
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) sts_json(400, ['success' => false]);
        $ok=content_edit_existing(DB::pdo(),'service',$id,$_POST['expected_token'] ?? null,'delete',[],static function() use ($id) { Auth::logActivity(Auth::userId(),'delete_service','service',$id); });
        if (!$ok) sts_json(409,['success'=>false,'error'=>'خدمت تغییر کرده یا دیگر موجود نیست؛ صفحه را تازه کنید.']);
        sts_json(200, ['success' => true]);
    }

    sts_json(400, ['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    sts_json(500, ['success' => false, 'error' => 'عملیات انجام نشد؛ لاگ سرور را بررسی کنید.']);
}
