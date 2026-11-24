<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('blog.view');
$pageTitle = 'مدیریت بلاگ';
$activeMenu = 'blog';

$status = $_GET['status'] ?? '';
$where = ''; $params = [];
if ($status === 'published') $where = 'WHERE is_published = 1';
if ($status === 'draft') $where = 'WHERE is_published = 0';

$posts = DB::fetchAll("SELECT p.*, c.name_fa AS category_name, u.full_name AS author_name FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id LEFT JOIN admin_users u ON u.id = p.author_id $where ORDER BY p.created_at DESC", $params);
$categories = DB::fetchAll('SELECT * FROM blog_categories ORDER BY display_order');
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">مقالات</h2>
        <div style="display:flex; gap:8px;">
            <a href="?status=" class="btn btn-sm <?= !$status ? 'btn-primary' : '' ?>">همه</a>
            <a href="?status=published" class="btn btn-sm <?= $status === 'published' ? 'btn-primary' : '' ?>">منتشر شده</a>
            <a href="?status=draft" class="btn btn-sm <?= $status === 'draft' ? 'btn-primary' : '' ?>">پیش‌نویس</a>
            <?php if (Auth::can('blog.edit')): ?>
                <a href="blog-edit.php" class="btn btn-primary btn-sm">➕ مقاله جدید</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($posts)): ?>
        <div class="empty"><div class="empty-icon">📰</div>مقاله‌ای یافت نشد</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>عنوان</th><th>دسته</th><th>نویسنده</th><th>بازدید</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($p['title_fa']) ?></strong>
                            <?php if ($p['is_featured']): ?>⭐<?php endif; ?>
                            <br><small style="color:var(--text-2);">/<?= htmlspecialchars($p['slug']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                        <td><small><?= htmlspecialchars($p['author_name'] ?? '-') ?></small></td>
                        <td><?= number_format($p['views']) ?></td>
                        <td>
                            <span class="badge <?= $p['is_published'] ? 'replied' : 'in_progress' ?>">
                                <?= $p['is_published'] ? 'منتشر' : 'پیش‌نویس' ?>
                            </span>
                        </td>
                        <td><small><?= date('Y/m/d', strtotime($p['created_at'])) ?></small></td>
                        <td>
                            <a href="/blog/<?= htmlspecialchars($p['slug']) ?>" target="_blank" class="btn btn-sm">👁️</a>
                            <?php if (Auth::can('blog.edit')): ?>
                                <a href="blog-edit.php?id=<?= $p['id'] ?>" class="btn btn-sm">ویرایش</a>
                            <?php endif; ?>
                            <?php if (Auth::can('blog.delete')): ?>
                                <button class="btn btn-sm btn-danger" data-confirm="مقاله حذف شود؟" onclick="deletePost(<?= $p['id'] ?>)">حذف</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
async function deletePost(id) {
    const fd = new FormData();
    fd.append('action', 'delete'); fd.append('id', id); fd.append('csrf_token', csrfToken);
    await stsFetch('api/blog.php', { method: 'POST', body: fd });
    location.reload();
}
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
