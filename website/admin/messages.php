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

$pageTitle = 'پیام‌های تماس';
$activeMenu = 'messages';

// Filters
$status = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';

$where = [];
$params = [];
if ($status && in_array($status, ['new', 'in_progress', 'replied', 'closed', 'spam'], true)) {
    $where[] = 'm.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = '(m.name LIKE ? OR m.email LIKE ? OR m.subject LIKE ? OR m.message LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s, $s]);
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$messages = DB::fetchAll(
    "SELECT m.*, u.full_name AS assigned_name
     FROM contact_messages m
     LEFT JOIN admin_users u ON u.id = m.assigned_to
     $whereSQL
     ORDER BY m.created_at DESC
     LIMIT 200",
    $params
);

ob_start();
?>
<div class="card">
    <form method="get" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="جستجو..." style="flex: 1; min-width: 200px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 9px 14px; color: var(--text-strong);">
        <select name="status" data-auto-submit style="background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 9px 14px; color: var(--text);">
            <option value="">همه وضعیت‌ها</option>
            <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>جدید</option>
            <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>در حال بررسی</option>
            <option value="replied" <?= $status === 'replied' ? 'selected' : '' ?>>پاسخ داده شده</option>
            <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>بسته شده</option>
            <option value="spam" <?= $status === 'spam' ? 'selected' : '' ?>>اسپم</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">اعمال فیلتر</button>
    </form>

    <?php if (empty($messages)): ?>
        <div class="empty">
            <div class="empty-icon">✉️</div>
            پیامی یافت نشد
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>نام</th>
                        <th>موضوع</th>
                        <th>اولویت</th>
                        <th>وضعیت</th>
                        <th>ارجاع به</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($messages as $m): ?>
                    <tr style="<?= $m['status'] === 'new' ? 'background: rgba(26, 111, 181, 0.05);' : '' ?>">
                        <td>
                            <a href="message-view.php?id=<?= $m['id'] ?>" style="color: var(--text-strong); font-weight: 600;">
                                <?= htmlspecialchars($m['name']) ?>
                            </a>
                            <br><small style="color: var(--text-2);"><?= htmlspecialchars($m['email']) ?></small>
                        </td>
                        <td>
                            <?= htmlspecialchars(mb_substr($m['subject'] ?: '(بدون موضوع)', 0, 50)) ?>
                            <?php if (mb_strlen($m['subject'] ?? '') > 50): ?>...<?php endif; ?>
                        </td>
                        <td><span class="badge <?= $m['priority'] ?>"><?= $m['priority'] ?></span></td>
                        <td><span class="badge <?= $m['status'] ?>"><?= $m['status'] ?></span></td>
                        <td>
                            <?= $m['assigned_name'] ? htmlspecialchars($m['assigned_name']) : '<span style="color: var(--text-2);">—</span>' ?>
                        </td>
                        <td><small><?= date('Y/m/d H:i', strtotime($m['created_at'])) ?></small></td>
                        <td>
                            <a href="message-view.php?id=<?= $m['id'] ?>" class="btn btn-sm">مشاهده</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
