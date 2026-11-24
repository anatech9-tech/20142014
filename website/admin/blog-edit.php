<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('blog.edit');
$pageTitle = 'ویرایش مقاله';
$activeMenu = 'blog';

$id = (int) ($_GET['id'] ?? 0);
$post = $id ? DB::fetch('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
$categories = DB::fetchAll('SELECT * FROM blog_categories ORDER BY display_order');
?>
<?php ob_start(); ?>
<form id="blogForm">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">

    <div style="display: grid; grid-template-columns: 1fr 280px; gap: 20px;">
        <div>
            <div class="card">
                <div class="form-field">
                    <label>عنوان (فارسی) <span class="required">*</span></label>
                    <input type="text" name="title_fa" required value="<?= htmlspecialchars($post['title_fa'] ?? '') ?>">
                </div>
                <div class="form-field">
                    <label>عنوان (English)</label>
                    <input type="text" name="title_en" dir="ltr" value="<?= htmlspecialchars($post['title_en'] ?? '') ?>">
                </div>
                <div class="form-field">
                    <label>slug (خالی بگذارید تا خودکار ساخته شود)</label>
                    <input type="text" name="slug" dir="ltr" value="<?= htmlspecialchars($post['slug'] ?? '') ?>">
                </div>
                <div class="form-field">
                    <label>چکیده (فارسی)</label>
                    <textarea name="excerpt_fa" style="min-height: 80px;"><?= htmlspecialchars($post['excerpt_fa'] ?? '') ?></textarea>
                </div>
                <div class="form-field">
                    <label>محتوای فارسی <span class="required">*</span></label>
                    <textarea name="content_fa" required style="min-height: 400px; font-family: 'Vazirmatn', monospace; line-height: 2;"><?= htmlspecialchars($post['content_fa'] ?? '') ?></textarea>
                </div>
                <div class="form-field">
                    <label>محتوای English (HTML supported)</label>
                    <textarea name="content_en" dir="ltr" style="min-height: 200px; font-family: monospace;"><?= htmlspecialchars($post['content_en'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title" style="margin-bottom: 16px;">سئو</h3>
                <div class="form-field">
                    <label>Meta Title</label>
                    <input type="text" name="meta_title" value="<?= htmlspecialchars($post['meta_title'] ?? '') ?>">
                </div>
                <div class="form-field">
                    <label>Meta Description</label>
                    <textarea name="meta_description" style="min-height: 60px;"><?= htmlspecialchars($post['meta_description'] ?? '') ?></textarea>
                </div>
                <div class="form-field">
                    <label>Meta Keywords (با کاما جدا کنید)</label>
                    <input type="text" name="meta_keywords" dir="ltr" value="<?= htmlspecialchars($post['meta_keywords'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <h3 class="card-title" style="margin-bottom: 16px;">انتشار</h3>
                <div class="form-field">
                    <label>دسته‌بندی</label>
                    <select name="category_id">
                        <option value="">— بدون دسته —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($post['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name_fa']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="checkbox-row" style="margin: 8px 0;">
                    <input type="checkbox" name="is_published" value="1" <?= ($post['is_published'] ?? 0) ? 'checked' : '' ?>>
                    <span>انتشار</span>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="is_featured" value="1" <?= ($post['is_featured'] ?? 0) ? 'checked' : '' ?>>
                    <span>مقاله ویژه ⭐</span>
                </label>
            </div>

            <div class="card">
                <h3 class="card-title" style="margin-bottom: 16px;">تصویر شاخص</h3>
                <input type="text" name="cover_image" id="coverImg" dir="ltr" value="<?= htmlspecialchars($post['cover_image'] ?? '') ?>" placeholder="/uploads/...">
                <input type="file" id="coverFile" accept="image/*" style="display:none">
                <button type="button" class="btn btn-sm btn-block" style="margin-top:8px;" onclick="document.getElementById('coverFile').click()">📁 آپلود تصویر</button>
                <?php if ($post['cover_image'] ?? ''): ?>
                    <img src="<?= htmlspecialchars($post['cover_image']) ?>" style="width:100%; margin-top:12px; border-radius:8px;">
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">💾 ذخیره مقاله</button>
            <a href="/admin/blog.php" class="btn btn-block" style="margin-top:8px;">انصراف</a>
        </div>
    </div>
</form>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

document.getElementById('coverFile')?.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('subdir', 'blog');
    fd.append('csrf_token', csrfToken);
    try {
        const r = await stsFetch('api/upload.php', { method: 'POST', body: fd });
        document.getElementById('coverImg').value = r.url;
    } catch (err) { alert('خطا: ' + err.message); }
});

document.getElementById('blogForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'save');
    try {
        const r = await stsFetch('api/blog.php', { method: 'POST', body: fd });
        if (r.success) {
            if (!document.querySelector('[name="id"]').value) {
                location.href = '/admin/blog-edit.php?id=' + r.id;
            } else {
                alert('ذخیره شد.');
            }
        }
    } catch (err) { alert(err.message); }
});
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
