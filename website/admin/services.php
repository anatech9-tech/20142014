<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('services.view');
$pageTitle = 'مدیریت خدمات';
$activeMenu = 'services';
$services = DB::fetchAll('SELECT * FROM services ORDER BY display_order, id');
require_once BASE_PATH . '/includes/ContentEditGuard.php';
foreach ($services as &$entry) $entry['__edit_token'] = content_edit_token('service', $entry);
unset($entry);
?>
<?php ob_start(); ?>
<div class="card">
    <p>اگر اطلاعات فعلی با نسخهٔ این صفحه متفاوت باشد، ویرایش یا حذف رد می‌شود؛ صفحه را تازه و اطلاعات را دوباره بررسی کنید.</p>
    <div class="card-header">
        <h2 class="card-title">خدمات</h2>
        <?php if (Auth::can('services.edit')): ?>
            <button class="btn btn-primary" onclick="openModal('srvModal'); document.getElementById('srvForm').reset(); document.getElementById('srvId').value='0';">➕ افزودن خدمت</button>
        <?php endif; ?>
    </div>

    <?php if (empty($services)): ?>
        <div class="empty"><div class="empty-icon">⚙️</div>خدمتی ثبت نشده</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>آیکون</th><th>عنوان</th><th>توضیح</th><th>ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($services as $s): ?>
                    <tr>
                        <td>
                            <?php if ($s['icon_path']): ?>
                                <img src="<?= htmlspecialchars($s['icon_path']) ?>" style="width:40px; height:40px; object-fit:contain;" alt="">
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($s['title_fa']) ?></strong></td>
                        <td><small><?= htmlspecialchars(mb_substr($s['description_fa'], 0, 80)) ?><?= mb_strlen($s['description_fa']) > 80 ? '…' : '' ?></small></td>
                        <td><?= $s['display_order'] ?></td>
                        <td><span class="badge <?= $s['is_active'] ? 'new' : 'closed' ?>"><?= $s['is_active'] ? 'فعال' : 'غیرفعال' ?></span></td>
                        <td>
                            <?php if (Auth::can('services.edit')): ?>
                                <button class="btn btn-sm" onclick='editSrv(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>ویرایش</button>
                            <?php endif; ?>
                            <?php if (Auth::can('services.delete')): ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteSrv(<?= (int)$s['id'] ?>, '<?= $s['__edit_token'] ?>')">حذف</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal-backdrop" id="srvModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">خدمت</h3>
            <button class="modal-close" data-close-modal="srvModal">×</button>
        </div>
        <form id="srvForm">
            <div class="modal-body">
                <input type="hidden" id="srvId" name="id" value="0">
                <input type="hidden" id="srvEditToken" name="expected_token" value="">
                <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">

                <div class="form-row">
                    <div class="form-field">
                        <label>عنوان (فارسی) <span class="required">*</span></label>
                        <input type="text" name="title_fa" required>
                    </div>
                    <div class="form-field">
                        <label>عنوان (English)</label>
                        <input type="text" name="title_en" dir="ltr">
                    </div>
                </div>

                <div class="form-field">
                    <label>توضیح (فارسی) <span class="required">*</span></label>
                    <textarea name="description_fa" required></textarea>
                </div>

                <div class="form-field">
                    <label>توضیح (English)</label>
                    <textarea name="description_en" dir="ltr"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>مسیر آیکون</label>
                        <input type="text" name="icon_path" placeholder="assets/icons/..." dir="ltr">
                    </div>
                    <div class="form-field">
                        <label>ترتیب</label>
                        <input type="number" name="display_order" value="0">
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>فعال</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal="srvModal">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function editSrv(s) {
    document.getElementById('srvId').value = s.id;
    document.getElementById('srvEditToken').value = s.__edit_token;
    document.querySelector('[name="title_fa"]').value = s.title_fa || '';
    document.querySelector('[name="title_en"]').value = s.title_en || '';
    document.querySelector('[name="description_fa"]').value = s.description_fa || '';
    document.querySelector('[name="description_en"]').value = s.description_en || '';
    document.querySelector('[name="icon_path"]').value = s.icon_path || '';
    document.querySelector('[name="display_order"]').value = s.display_order || 0;
    document.querySelector('[name="is_active"]').checked = s.is_active == 1;
    openModal('srvModal');
}

async function deleteSrv(id, expectedToken) {
    if (!confirm('حذف شود؟')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('csrf_token', csrfToken);
    fd.append('expected_token', expectedToken);
    try { await stsFetch('api/services.php', { method: 'POST', body: fd }); location.reload(); }
    catch (err) { alert(err.message); }
}

document.getElementById('srvForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'save');
    try {
        await stsFetch('api/services.php', { method: 'POST', body: fd });
        location.reload();
    } catch (err) { alert(err.message); }
});
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
