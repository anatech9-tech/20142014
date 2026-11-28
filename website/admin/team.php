<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('team.view');
$pageTitle = 'مدیریت تیم';
$activeMenu = 'team';

$team = DB::fetchAll('SELECT * FROM team_members ORDER BY display_order, id');
require_once BASE_PATH . '/includes/ContentEditGuard.php';
foreach ($team as &$entry) $entry['__edit_token'] = content_edit_token('team', $entry);
unset($entry);
?>
<?php ob_start(); ?>
<div class="card">
    <p>اگر اطلاعات فعلی با نسخهٔ این صفحه متفاوت باشد، ویرایش یا حذف رد می‌شود؛ صفحه را تازه و اطلاعات را دوباره بررسی کنید.</p>
    <div class="card-header">
        <h2 class="card-title">اعضای تیم</h2>
        <?php if (Auth::can('team.edit')): ?>
            <button class="btn btn-primary" onclick="openModal('teamModal'); document.getElementById('teamForm').reset(); document.getElementById('teamId').value='0';">
                ➕ افزودن عضو جدید
            </button>
        <?php endif; ?>
    </div>

    <?php if (empty($team)): ?>
        <div class="empty"><div class="empty-icon">👥</div>عضوی ثبت نشده</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>تصویر</th><th>نام</th><th>نقش</th><th>ترتیب</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($team as $m): ?>
                    <tr>
                        <td>
                            <?php if ($m['image_path']): ?>
                                <img src="<?= htmlspecialchars($m['image_path']) ?>" style="width:48px; height:48px; border-radius:50%; object-fit:cover;" alt="">
                            <?php else: ?>
                                <div style="width:48px; height:48px; border-radius:50%; background:var(--bg-3); display:flex; align-items:center; justify-content:center; color:var(--secondary); font-weight:700;">
                                    <?= mb_substr($m['name'], 0, 1) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($m['name']) ?></strong><br><small style="color:var(--text-2);"><?= htmlspecialchars($m['handle'] ?? '') ?></small></td>
                        <td><?= htmlspecialchars($m['role']) ?></td>
                        <td><?= $m['display_order'] ?></td>
                        <td>
                            <span class="badge <?= $m['is_active'] ? 'new' : 'closed' ?>"><?= $m['is_active'] ? 'فعال' : 'غیرفعال' ?></span>
                        </td>
                        <td>
                            <?php if (Auth::can('team.edit')): ?>
                                <button class="btn btn-sm" onclick='editTeam(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>ویرایش</button>
                            <?php endif; ?>
                            <?php if (Auth::can('team.delete')): ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteTeam(<?= (int)$m['id'] ?>, '<?= $m['__edit_token'] ?>')">حذف</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="teamModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">ویرایش عضو تیم</h3>
            <button class="modal-close" data-close-modal="teamModal">×</button>
        </div>
        <form id="teamForm">
            <div class="modal-body">
                <input type="hidden" id="teamId" name="id" value="0">
                <input type="hidden" id="teamEditToken" name="expected_token" value="">
                <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">

                <div class="form-row">
                    <div class="form-field">
                        <label>نام <span class="required">*</span></label>
                        <input type="text" name="name" required>
                    </div>
                    <div class="form-field">
                        <label>نقش <span class="required">*</span></label>
                        <input type="text" name="role" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>عنوان کوتاه (handle)</label>
                        <input type="text" name="handle" placeholder="CEO">
                    </div>
                    <div class="form-field">
                        <label>ترتیب نمایش</label>
                        <input type="number" name="display_order" value="0">
                    </div>
                </div>

                <div class="form-field">
                    <label>بیوگرافی</label>
                    <textarea name="bio"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>ایمیل</label>
                        <input type="email" name="email">
                    </div>
                    <div class="form-field">
                        <label>لینکدین</label>
                        <input type="url" name="linkedin_url" dir="ltr">
                    </div>
                </div>

                <div class="form-field">
                    <label>تصویر فعلی</label>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="text" name="image_path" id="teamImage" placeholder="/assets/team/6.webp" dir="ltr">
                        <input type="file" id="teamImageFile" accept="image/*" style="display:none">
                        <button type="button" class="btn btn-sm" onclick="document.getElementById('teamImageFile').click()">📁 آپلود</button>
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>فعال (نمایش در سایت)</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal="teamModal">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function editTeam(m) {
    document.getElementById('teamId').value = m.id;
    document.getElementById('teamEditToken').value = m.__edit_token;
    document.querySelector('[name="name"]').value = m.name || '';
    document.querySelector('[name="role"]').value = m.role || '';
    document.querySelector('[name="handle"]').value = m.handle || '';
    document.querySelector('[name="bio"]').value = m.bio || '';
    document.querySelector('[name="email"]').value = m.email || '';
    document.querySelector('[name="linkedin_url"]').value = m.linkedin_url || '';
    document.querySelector('[name="display_order"]').value = m.display_order || 0;
    document.querySelector('[name="is_active"]').checked = m.is_active == 1;
    document.getElementById('teamImage').value = m.image_path || '';
    openModal('teamModal');
}

async function deleteTeam(id, expectedToken) {
    if (!confirm('این عضو حذف شود؟')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('csrf_token', csrfToken);
    fd.append('expected_token', expectedToken);
    try { await stsFetch('api/team.php', { method: 'POST', body: fd }); location.reload(); }
    catch (err) { alert(err.message); }
}

document.getElementById('teamForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'save');
    try {
        await stsFetch('api/team.php', { method: 'POST', body: fd });
        location.reload();
    } catch (err) {
        alert(err.message);
    }
});

document.getElementById('teamImageFile')?.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('subdir', 'team');
    fd.append('csrf_token', csrfToken);
    try {
        const r = await stsFetch('api/upload.php', { method: 'POST', body: fd });
        document.getElementById('teamImage').value = r.url;
    } catch (err) {
        alert('خطا در آپلود: ' + err.message);
    }
});
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
