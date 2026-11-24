<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('messages.view');

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    $_SESSION['flash_error'] = 'شناسه پیام نامعتبر.';
    sts_redirect('/admin/messages.php');
}

$message = DB::fetch(
    "SELECT m.*, u1.full_name AS assigned_name, u2.full_name AS replied_by_name
     FROM contact_messages m
     LEFT JOIN admin_users u1 ON u1.id = m.assigned_to
     LEFT JOIN admin_users u2 ON u2.id = m.replied_by
     WHERE m.id = ?",
    [$id]
);

if (!$message) {
    $_SESSION['flash_error'] = 'پیام یافت نشد.';
    sts_redirect('/admin/messages.php');
}

// Auto mark as in_progress if currently new
if ($message['status'] === 'new' && Auth::can('messages.assign')) {
    DB::update('contact_messages', [
        'status' => 'in_progress',
        'assigned_to' => Auth::userId(),
    ], 'id = :id', ['id' => $id]);
    Auth::logActivity(Auth::userId(), 'view_message', 'contact_message', $id, 'مشاهده پیام');
}

$pageTitle = 'پیام از ' . $message['name'];
$activeMenu = 'messages';

ob_start();
?>
<div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px;">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?= htmlspecialchars($message['subject'] ?: '(بدون موضوع)') ?></h2>
            <span class="badge <?= $message['status'] ?>"><?= $message['status'] ?></span>
        </div>

        <div style="background: var(--bg); padding: 20px; border-radius: 10px; border-right: 4px solid var(--secondary); white-space: pre-wrap; line-height: 1.9; color: var(--text);">
<?= nl2br(htmlspecialchars($message['message'])) ?>
        </div>

        <div style="margin-top: 20px; display: flex; gap: 8px; flex-wrap: wrap;">
            <?php if ($message['email']): ?>
                <a href="mailto:<?= htmlspecialchars($message['email']) ?>" class="btn">📧 ایمیل</a>
            <?php endif; ?>
            <?php if ($message['phone']): ?>
                <a href="tel:<?= htmlspecialchars($message['phone']) ?>" class="btn">📞 تماس</a>
            <?php endif; ?>
            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $message['phone'] ?? '') ?>" target="_blank" class="btn">💬 واتساپ</a>
        </div>

        <?php if ($message['reply_message']): ?>
            <div style="margin-top: 20px;">
                <h3 style="color: var(--success); font-size: 1rem; margin-bottom: 10px;">✓ پاسخ ارسال شده</h3>
                <div style="background: rgba(46, 204, 113, 0.08); padding: 16px; border-radius: 10px; border-right: 3px solid var(--success); white-space: pre-wrap; line-height: 1.8;">
<?= nl2br(htmlspecialchars($message['reply_message'])) ?>
                </div>
                <small style="color: var(--text-2); margin-top: 8px; display: block;">
                    توسط <?= htmlspecialchars($message['replied_by_name'] ?? '-') ?>
                    در <?= date('Y/m/d H:i', strtotime($message['replied_at'])) ?>
                </small>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <h3 class="card-title" style="margin-bottom: 16px;">جزئیات فرستنده</h3>
            <dl class="detail-grid">
                <dt>نام:</dt><dd><?= htmlspecialchars($message['name']) ?></dd>
                <dt>ایمیل:</dt><dd dir="ltr" style="text-align: right;"><?= htmlspecialchars($message['email']) ?></dd>
                <?php if ($message['phone']): ?>
                    <dt>تلفن:</dt><dd dir="ltr" style="text-align: right;"><?= htmlspecialchars($message['phone']) ?></dd>
                <?php endif; ?>
                <dt>IP:</dt><dd style="font-family: monospace; font-size: 0.85rem;"><?= htmlspecialchars($message['ip_address'] ?: '-') ?></dd>
                <dt>تاریخ ارسال:</dt><dd><?= date('Y/m/d H:i:s', strtotime($message['created_at'])) ?></dd>
                <dt>اولویت:</dt><dd><span class="badge <?= $message['priority'] ?>"><?= $message['priority'] ?></span></dd>
                <dt>ارجاع به:</dt><dd><?= htmlspecialchars($message['assigned_name'] ?? '-') ?></dd>
            </dl>
        </div>

        <?php if (Auth::can('messages.reply')): ?>
        <div class="card">
            <h3 class="card-title" style="margin-bottom: 16px;">پاسخ به مشتری</h3>
            <form id="replyForm">
                <div class="form-field">
                    <textarea name="reply_message" required minlength="10" placeholder="پاسخ شما..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-field">
                        <label>تغییر وضعیت</label>
                        <select name="status">
                            <option value="in_progress">در حال بررسی</option>
                            <option value="replied">پاسخ داده شده</option>
                            <option value="closed">بسته</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>اولویت</label>
                        <select name="priority">
                            <option value="low" <?= $message['priority'] === 'low' ? 'selected' : '' ?>>کم</option>
                            <option value="normal" <?= $message['priority'] === 'normal' ? 'selected' : '' ?>>عادی</option>
                            <option value="high" <?= $message['priority'] === 'high' ? 'selected' : '' ?>>بالا</option>
                            <option value="urgent" <?= $message['priority'] === 'urgent' ? 'selected' : '' ?>>فوری</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">ارسال پاسخ و ذخیره</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (Auth::can('messages.delete')): ?>
        <div class="card">
            <form method="post" action="api/messages.php" data-confirm="این پیام برای همیشه حذف خواهد شد.">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $message['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
                <button type="submit" class="btn btn-danger btn-block">🗑️ حذف این پیام</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (Auth::can('messages.reply')): ?>
<script>
document.getElementById('replyForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'reply');
    fd.append('id', <?= $id ?>);
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);

    try {
        const res = await fetch('api/messages.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'خطا');
        }
    } catch (err) {
        alert('خطا: ' + err.message);
    }
});
</script>
<?php endif; ?>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
