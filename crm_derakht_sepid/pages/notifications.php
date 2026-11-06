<?php
$pageTitle = t('nav_notifications');
$ws_id = $_SESSION['workshop_id'] ?? 0;

if (isset($_GET['fetch_person'])) {
    $person_id = (int)$_GET['fetch_person'];
    $stmt = $pdo->prepare("SELECT n.*, u.full_name as target_name FROM notifications n LEFT JOIN users u ON n.target_user_id = u.id WHERE n.target_user_id = ? ORDER BY n.created_at DESC LIMIT 50");
    $stmt->execute([$person_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($messages, JSON_UNESCAPED_UNICODE);
    exit;
}

// Uploads dir
$upload_dir = __DIR__ . '/../uploads/attachments/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send') {
        $is_group = isset($_POST['is_group']) ? 1 : 0;
        $is_task = isset($_POST['is_task']) ? 1 : 0;
        $message = sanitize($_POST['message'] ?? '');
        
        if ($is_task && !empty($_POST['task_title'])) {
            $message = '📋 ' . t('دستور کار') . ': ' . sanitize($_POST['task_title']) . "\n" . $message;
        }
        
        $stmt = $pdo->prepare("INSERT INTO notifications (title, message, type, is_group, is_task, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([sanitize($_POST['title']), $message, $_POST['type'] ?? 'info', $is_group, $is_task, $_SESSION['user_id']]);
        $notif_id = $pdo->lastInsertId();
        
        if ($is_group) {
            $stmt = $pdo->prepare("INSERT INTO notification_recipients (notification_id, user_id) SELECT ?, user_id FROM workshop_personnel WHERE workshop_id = ? AND status = 'active'");
            $stmt->execute([$notif_id, $ws_id]);
        } else {
            $users = $_POST['users'] ?? [];
            $stmt = $pdo->prepare("INSERT INTO notification_recipients (notification_id, user_id) VALUES (?, ?)");
            foreach ($users as $uid) {
                $stmt->execute([$notif_id, (int)$uid]);
            }
        }
        showToast($is_task ? t('دستور کار ارسال شد') : t('پیام ارسال شد'));
        redirect("index.php?page=notifications");
    }
    
    if ($action === 'reply') {
        $notif_id = (int)$_POST['notif_id'];
        $message = sanitize($_POST['reply_message'] ?? '');
        $is_task_reply = isset($_POST['reply_to_task']) && $_POST['reply_to_task'] == '1' ? 1 : 0;
        $attachment = '';
        
        if (!empty($_FILES['reply_attachment']['name'])) {
            $filename = uniqid() . '_' . basename($_FILES['reply_attachment']['name']);
            move_uploaded_file($_FILES['reply_attachment']['tmp_name'], $upload_dir . $filename);
            // Compress images
            $att_mime = $_FILES['reply_attachment']['type'] ?? '';
            if (strpos($att_mime, 'image') !== false) {
                compress_image($upload_dir . $filename, 1000, 80);
            }
            $attachment = $filename;
        }
        
        // Get the notification's creator to send reply to them
        $stmt = $pdo->prepare("SELECT created_by FROM notifications WHERE id = ?");
        $stmt->execute([$notif_id]);
        $creator = $stmt->fetchColumn();
        
        // Create a new notification as reply
        $reply_title = ($is_task_reply ? '📋 ' . t('پاسخ دستور کار') : '💬 ' . t('پاسخ پیام'));
        $stmt = $pdo->prepare("INSERT INTO notifications (title, message, type, is_group, is_task, created_by) VALUES (?, ?, 'info', 0, ?, ?)");
        $stmt->execute([$reply_title, $message, $is_task_reply, $_SESSION['user_id']]);
        $reply_id = $pdo->lastInsertId();
        
        // Send to creator
        $stmt = $pdo->prepare("INSERT INTO notification_recipients (notification_id, user_id, reply_to_task, attachment, message_text) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reply_id, $creator, $is_task_reply, $attachment, $message]);
        
        // Mark original as read for this user
        $stmt = $pdo->prepare("UPDATE notification_recipients SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $_SESSION['user_id']]);
        
        showToast(t('پاسخ ارسال شد'));
        redirect("index.php?page=notifications");
    }
    
    if ($action === 'mark_read') {
        $stmt = $pdo->prepare("UPDATE notification_recipients SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        redirect("index.php?page=notifications");
    }
    
    if ($action === 'mark_single_read') {
        $stmt = $pdo->prepare("UPDATE notification_recipients SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([(int)$_POST['notif_id'], $_SESSION['user_id']]);
        redirect("index.php?page=notifications");
    }
}

// Get notifications for current user
$stmt = $pdo->prepare("SELECT n.*, nr.is_read, nr.read_at, nr.reply_to_task, nr.attachment, nr.message_text, u.full_name as sender FROM notifications n JOIN notification_recipients nr ON n.id = nr.notification_id JOIN users u ON n.created_by = u.id WHERE nr.user_id = ? ORDER BY n.created_at DESC LIMIT 100");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// Get replies (notifications created by this user where others replied)
$stmt = $pdo->prepare("SELECT n.*, nr.is_read, nr.read_at, nr.reply_to_task, nr.attachment, u.full_name as sender FROM notifications n JOIN notification_recipients nr ON n.id = nr.notification_id JOIN users u ON n.created_by = u.id WHERE n.created_by = ? AND n.is_group = 0 AND nr.user_id != ? ORDER BY n.created_at DESC LIMIT 100");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$my_notifications_with_replies = $stmt->fetchAll();

// Count unread
$unread_count = 0;
foreach ($notifications as $n) { if (!$n['is_read']) $unread_count++; }

// Personnel for sending
$stmt = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN workshop_personnel wp ON u.id = wp.user_id WHERE wp.workshop_id = ? AND wp.status = 'active' ORDER BY u.full_name");
$stmt->execute([$ws_id]);
$personnel = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_notifications') ?> <?php if ($unread_count > 0): ?><span class="tag tag-pending" style="margin-right:8px"><?= $unread_count ?></span><?php endif; ?></h2>
    <div class="btn-group">
        <?php if (has_permission('manage_notifications')): ?>
        <button onclick="openModal('sendModal')" class="btn btn-primary"><i data-lucide="send"></i> <?= t('btn_submit') ?></button>
        <button onclick="openModal('taskSendModal')" class="btn btn-warning"><i data-lucide="clipboard-list"></i> <?= t('btn_checkin') ?></button>
        <button onclick="openPersonMessages()" class="btn btn-info"><i data-lucide="message-square"></i> <?= t('not_unread') ?></button>
        <?php endif; ?>
    </div>
</div>

<!-- Notifications List -->
<div class="animate-in">
    <?php foreach ($notifications as $n): ?>
    <?php
        $border = 'var(--info)';
        if ($n['is_task']) $border = 'var(--warning)';
        if ($n['reply_to_task']) $border = 'var(--success)';
        if ($n['type'] == 'danger') $border = 'var(--danger)';
    ?>
    <div class="card mb-3" style="border-right: 4px solid <?= $border ?>; opacity: <?= $n['is_read'] ? '0.75' : '1' ?>">
        <div class="d-flex justify-between align-center">
            <div>
                <strong><?= $n['title'] ?></strong>
                <?php if ($n['is_task']): ?><span class="tag tag-warning" style="font-size:10px;margin-right:6px">📋 <?= t('nav_work_orders') ?></span><?php endif; ?>
                <?php if ($n['reply_to_task']): ?><span class="tag tag-active" style="font-size:10px;margin-right:6px">💬 <?= t('پاسخ') ?> <?= t('nav_work_orders') ?></span><?php endif; ?>
                <span class="text-muted" style="font-size:12px;margin-right:10px"><?= t('lea_from') ?>: <?= $n['sender'] ?></span>
            </div>
            <span class="text-muted" style="font-size:12px"><?= to_jalali($n['created_at']) ?></span>
        </div>
        <p class="mt-2" style="white-space:pre-wrap"><?= nl2br($n['message']) ?></p>
        
        <?php if ($n['attachment']): ?>
        <div class="mt-2"><a href="uploads/attachments/<?= $n['attachment'] ?>" target="_blank" class="btn btn-ghost btn-sm"><i data-lucide="paperclip"></i> <?= t('فایل پیوست') ?></a></div>
        <?php endif; ?>
        
        <div class="mt-2 d-flex gap-2" style="align-items:center">
            <?php if (!$n['is_read']): ?><span class="tag tag-pending"><?= t('جدید') ?></span><?php endif; ?>
            
            <?php if ($n['is_task'] && !$n['is_read']): ?>
            <button onclick="openReplyModal(<?= $n['id'] ?>, <?= $n['is_task'] ?>)" class="btn btn-success btn-sm"><?= t('پاسخ به') ?> <?= t('nav_work_orders') ?></button>
            <?php endif; ?>
            
            <?php if ($n['is_task'] && $n['is_read']): ?>
            <span class="tag tag-active" style="font-size:10px">✓ <?= t('پاسخ') ?> <?= t('داده') ?> <?= t('شد') ?></span>
            <?php endif; ?>
            
            <?php if ($n['is_task'] && !$n['is_read']): ?>
            <form method="POST" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="mark_single_read"><input type="hidden" name="notif_id" value="<?= $n['id'] ?>"><button class="btn btn-ghost btn-sm"><?= t('بی‌خیال') ?></button></form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($notifications)): ?>
    <div class="empty-state"><i data-lucide="bell"></i><h3><?= t('پیامی ندارید') ?></h3></div>
    <?php endif; ?>
</div>

<!-- Send Message Modal -->
<div id="sendModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:600px">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_submit') ?> <?= t('پیام') ?></h3><button onclick="closeModal('sendModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('عنوان') ?> *</label><input type="text" name="title" class="form-control" required></div>
                <div class="form-group"><label class="form-label"><?= t('متن پیام *') ?></label><textarea name="message" class="form-control" required rows="3"></textarea></div>
                <div class="form-group"><label class="form-label"><?= t('sal_type') ?></label>
                    <select name="type" class="form-control"><option value="info"><?= t('اطلاع‌رسانی') ?></option><option value="warning"><?= t('warning') ?></option><option value="success"><?= t('موفقیت') ?></option><option value="danger"><?= t('مهم') ?></option></select>
                </div>
                <div class="form-group"><label class="form-check"><input type="checkbox" name="is_group" value="1" checked><span><?= t('btn_submit') ?> <?= t('به همه') ?></span></label></div>
                <div class="form-group" id="usersGroup" style="display:none"><label class="form-label"><?= t('انتخاب افراد') ?></label>
                    <select name="users[]" class="form-control" multiple size="5">
                        <?php foreach ($personnel as $p): ?><option value="<?= $p['id'] ?>"><?= $p['full_name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_submit') ?></button></div>
        </form>
    </div>
</div>

<!-- Send Task Modal -->
<div id="taskSendModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:600px">
        <div class="modal-header"><h3 class="modal-title">📋 <?= t('btn_submit') ?> <?= t('nav_work_orders') ?> <?= t('sal_daily') ?></h3><button onclick="closeModal('taskSendModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="is_task" value="1">
            <input type="hidden" name="type" value="warning">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('عنوان') ?> <?= t('nav_work_orders') ?> *</label><input type="text" name="task_title" class="form-control" required placeholder="<?= t('مثال') ?>: <?= t('بازدید') ?> <?= t('lea_from') ?> <?= t('ساختمان') ?>"></div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?> <?= t('و') ?> <?= t('sal_details') ?> *</label><textarea name="message" class="form-control" required rows="4" placeholder="<?= t('sal_details') ?> <?= t('کار') ?> <?= t('مورد') ?> <?= t('نظر') ?>..."></textarea></div>
                <div class="form-group"><label class="form-check"><input type="checkbox" name="is_group" value="1" checked><span><?= t('btn_submit') ?> <?= t('به همه') ?></span></label></div>
                <div class="form-group" id="taskUsersGroup" style="display:none"><label class="form-label"><?= t('انتخاب افراد') ?></label>
                    <select name="users[]" class="form-control" multiple size="5">
                        <?php foreach ($personnel as $p): ?><option value="<?= $p['id'] ?>"><?= $p['full_name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-warning" style="color:#000"><?= t('btn_submit') ?> <?= t('nav_work_orders') ?></button></div>
        </form>
    </div>
</div>

<!-- Reply Modal -->
<div id="replyModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:500px">
        <div class="modal-header"><h3 class="modal-title"><?= t('پاسخ به') ?> <?= t('nav_work_orders') ?></h3><button onclick="closeModal('replyModal')" class="modal-close">✕</button></div>
        <form method="POST" enctype="multipart/form-data"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="notif_id" id="reply_notif_id">
            <input type="hidden" name="reply_to_task" id="reply_to_task_val">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('متن پیام *') ?></label><textarea name="reply_message" class="form-control" required rows="3"></textarea></div>
                <div class="form-group"><label class="form-label"><?= t('فایل پیوست') ?></label><input type="file" name="reply_attachment" class="form-control" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip"></div>
                <label class="form-check"><input type="checkbox" name="reply_to_task_check" id="reply_task_check" checked><span>📋 <?= t('پاسخ به') ?> <?= t('nav_work_orders') ?></span></label>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success"><?= t('btn_submit') ?> <?= t('پاسخ') ?></button></div>
        </form>
    </div>
</div>

<!-- Person Messages Modal -->
<div id="personMsgModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:700px">
        <div class="modal-header"><h3 class="modal-title" id="personMsgTitle"><?= t('nav_notifications') ?></h3><button onclick="closeModal('personMsgModal')" class="modal-close">✕</button></div>
        <div class="modal-body" style="max-height:60vh;overflow-y:auto" id="personMsgBody">
            <div class="form-group">
                <label class="form-label"><?= t('انتخاب نیرو') ?></label>
                <select id="personSelect" class="form-control" onchange="loadPersonMessages()">
                    <option value=""><?= t('انتخاب کنید') ?></option>
                    <?php foreach ($personnel as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= $p['full_name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="personMessages"></div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[name="is_group"]').forEach(cb => {
    cb.addEventListener('change', function() {
        var groupId = this.closest('form').querySelector('[name="action"]').value === 'send' ? 'usersGroup' : 'taskUsersGroup';
        if (this.closest('#sendModal')) document.getElementById('usersGroup').style.display = this.checked ? 'none' : 'block';
        if (this.closest('#taskSendModal')) document.getElementById('taskUsersGroup').style.display = this.checked ? 'none' : 'block';
    });
});

// Also handle the task send modal's is_group checkbox
var taskIsGroup = document.querySelector('#taskSendModal [name="is_group"]');
if (taskIsGroup) {
    taskIsGroup.addEventListener('change', function() {
        document.getElementById('taskUsersGroup').style.display = this.checked ? 'none' : 'block';
    });
}

function openReplyModal(notifId, isTask) {
    document.getElementById('reply_notif_id').value = notifId;
    document.getElementById('reply_to_task_val').value = isTask ? '1' : '0';
    document.getElementById('reply_task_check').checked = isTask == 1;
    openModal('replyModal');
}

function openPersonMessages() {
    openModal('personMsgModal');
}

function loadPersonMessages() {
    var userId = document.getElementById('personSelect').value;
    var name = document.getElementById('personSelect').options[document.getElementById('personSelect').selectedIndex].text;
    document.getElementById('personMsgTitle').textContent = '<?= t('پیام') ?>‌<?= t('های') ?> ' + name;
    if (!userId) { document.getElementById('personMessages').innerHTML = ''; return; }
    
    document.getElementById('personMessages').innerHTML = '<div class="text-center" style="padding:20px"><div class="spinner" style="margin:0 auto"></div></div>';
    
    fetch('index.php?page=notifications&fetch_person=' + userId)
        .then(r => r.json())
        .then(data => {
            if (data.length === 0) {
                document.getElementById('personMessages').innerHTML = '<p class="text-muted text-center"><?= t('پیامی وجود ندارد') ?></p>';
                return;
            }
            var html = '';
            data.forEach(function(msg) {
                var borderColor = msg.is_task == 1 ? 'var(--warning)' : (msg.reply_to_task == 1 ? 'var(--success)' : 'var(--info)');
                var badge = msg.is_task == 1 ? '<span class="tag tag-warning" style="font-size:10px">📋 <?= t('دستور کار') ?></span>' : 
                           (msg.reply_to_task == 1 ? '<span class="tag tag-active" style="font-size:10px">💬 <?= t('پاسخ دستور کار') ?></span>' : '');
                html += '<div style="padding:10px;margin-bottom:8px;border-right:3px solid ' + borderColor + ';background:var(--bg);border-radius:8px">';
                html += '<div class="d-flex justify-between align-center"><strong>' + msg.title + '</strong> ' + badge + ' <span class="text-muted" style="font-size:11px">' + msg.date + '</span></div>';
                html += '<p style="margin-top:6px;font-size:13px;white-space:pre-wrap">' + msg.message + '</p>';
                if (msg.attachment) html += '<a href="uploads/attachments/' + msg.attachment + '" target="_blank" class="btn btn-ghost btn-sm" style="font-size:11px">📎 <?= t('فایل پیوست') ?></a>';
                html += '</div>';
            });
            document.getElementById('personMessages').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('personMessages').innerHTML = '<p class="text-danger"><?= t('خطا در دریافت') ?></p>';
        });
}
</script>

<?php include 'includes/footer.php'; ?>
