<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
Auth::requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}

try {
    if ($action === 'save') {
        Auth::requirePermission('blog.edit');
        $id = (int) ($_POST['id'] ?? 0);
        $slug = trim($_POST['slug'] ?? '');
        if (!$slug) {
            $slug = mb_substr(strip_tags($_POST['title_fa'] ?? ''), 0, 100);
            $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}]+/u', '-', $slug) ?? '';
            $slug = trim($slug, '-');
        }
        if (!$slug) $slug = 'post-' . time();

        $data = [
            'category_id'     => !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null,
            'slug'            => $slug,
            'title_fa'        => sts_clean($_POST['title_fa'] ?? '', 300),
            'title_en'        => sts_clean($_POST['title_en'] ?? '', 300),
            'excerpt_fa'      => sts_clean($_POST['excerpt_fa'] ?? '', 500),
            'excerpt_en'      => sts_clean($_POST['excerpt_en'] ?? '', 500),
            'content_fa'      => $_POST['content_fa'] ?? '',
            'content_en'      => $_POST['content_en'] ?? '',
            'cover_image'     => sts_clean($_POST['cover_image'] ?? '', 500),
            'is_published'    => !empty($_POST['is_published']) ? 1 : 0,
            'is_featured'     => !empty($_POST['is_featured']) ? 1 : 0,
            'meta_title'      => sts_clean($_POST['meta_title'] ?? '', 300),
            'meta_description' => sts_clean($_POST['meta_description'] ?? '', 500),
            'meta_keywords'   => sts_clean($_POST['meta_keywords'] ?? '', 500),
        ];
        if (!empty($_POST['is_published'])) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }
        if (mb_strlen($data['title_fa']) < 3) sts_json(400, ['success' => false, 'error' => 'عنوان فارسی الزامی است.']);

        if ($id) {
            DB::update('blog_posts', $data, 'id = :id', ['id' => $id]);
        } else {
            $data['author_id'] = Auth::userId();
            $id = DB::insert('blog_posts', $data);
        }
        Auth::logActivity(Auth::userId(), $id ? 'update_post' : 'create_post', 'blog_post', $id);
        sts_json(200, ['success' => true, 'id' => $id]);
    }

    if ($action === 'delete') {
        Auth::requirePermission('blog.delete');
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) sts_json(400, ['success' => false]);
        DB::delete('blog_posts', 'id = :id', ['id' => $id]);
        Auth::logActivity(Auth::userId(), 'delete_post', 'blog_post', $id);
        sts_json(200, ['success' => true]);
    }

    sts_json(400, ['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    sts_json(500, ['success' => false, 'error' => $e->getMessage()]);
}
