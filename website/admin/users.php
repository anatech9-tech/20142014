<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('users.view');
$pageTitle = 'مدیریت کاربران';
$activeMenu = 'users';

$users = DB::fetchAll('SELECT id, username, email, full_name, role, is_active, last_login_at, last_login_ip, created_at FROM admin_users ORDER BY id');
$roles = [
    'superadmin' => 'مدیر کل',
    'manager'    => 'مدیر',
    'sales'      => 'فروش',
    'operations' => 'عملیات',
    'support'    => 'پشتیبانی',
];
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">کاربران پنل</h2>
        <?php if (Auth::can('users.edit')): ?>
            <button class="btn btn-primary" onclick="openModal('userModal'); document.getElementById('userForm').reset(); document.getElementById('userId').value='0';">➕ افزودن کاربر</button>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>نام</th><th>نام کاربری</th><th>ایمیل</th><th>نقش</th><th>آخرین ورود</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td dir="ltr" style="text-align:right;"><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="badge"><?= $roles[$u['role']] ?? $u['role'] ?></span></td>
                        <td><small><?= $u['last_login_at'] ? date('Y/m/d H:i', strtotime($u['last_login_at'])) : '<span style="color:var(--text-2);">هرگز</span>' ?><br><?= htmlspecialchars($u['last_login_ip'] ?? '') ?></small></td>
                        <td><span class="badge <?= $u['is_active'] ? 'new' : 'closed' ?>"><?= $u['is_active'] ? 'فعال' : 'غیرفعال' ?></span></td>
                        <td>
                            <?php if (Auth::can('users.edit') && $u['id'] != Auth::userId()): ?>
                                <button class="btn btn-sm" onclick='editUser(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>ویرایش</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-backdrop" id="userModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">کاربر</h3>
            <button class="modal-close" data-close-modal="userModal">×</button>
        </div>
        <form id="userForm">
            <div class="modal-body">
                <input type="hidden" id="userId" name="id" value="0">
                <div class="form-field">
                    <label>نام کامل <span class="required">*</span></label>
                    <input type="text" name="full_name" required>
                </div>
                <div class="form-row">
                    <div class="form-field">
                        <label>نام کاربری <span class="required">*</span></label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-field">
                        <label>ایمیل <span class="required">*</span></label>
                        <input type="email" name="email" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field">
                        <label>نقش</label>
                        <select name="role">
                            <?php foreach ($roles as $v => $l): ?>
                                <option value="<?= $v ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>تلفن</label>
                        <input type="tel" name="phone" dir="ltr">
                    </div>
                </div>
                <div class="form-field">
                    <label>رمز عبور جدید (خالی = بدون تغییر)</label>
                    <input type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>">
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>فعال</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal="userModal">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
function editUser(u) {
    document.getElementById('userId').value = u.id;
    document.querySelector('[name="full_name"]').value = u.full_name;
    document.querySelector('[name="username"]').value = u.username;
    document.querySelector('[name="email"]').value = u.email;
    document.querySelector('[name="role"]').value = u.role;
    document.querySelector('[name="is_active"]').checked = u.is_active == 1;
    document.querySelector('[name="password"]').value = '';
    openModal('userModal');
}
document.getElementById('userForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'save');
    try {
        await stsFetch('api/users.php', { method: 'POST', body: fd });
        location.reload();
    } catch (err) { alert(err.message); }
});
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
