<?php
// کانال‌ها زیرمجموعهٔ چت روم هستند؛ مسیر قدیمی channels نیز به همین نمای داخلی هدایت می‌شود.
if (($_GET['view'] ?? '') === 'channels') {
    include __DIR__ . '/channels.php';
    exit;
}

$pageTitle = t('nav_chat');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
$is_nazer_chat = isset($_SESSION['role']) && $_SESSION['role'] === 'nazer';
$is_admin_chat = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$chat_has_channels = db_has_column($pdo, 'chat_messages', 'channel_id');
$public_channel_filter = $chat_has_channels ? " AND (cm.channel_id IS NULL OR cm.channel_id = 0) AND (cm.workshop_id IS NULL OR cm.workshop_id = " . (int)$ws_id . ")" : '';

// Handle new message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_chat') {
    $receiver = (int)($_POST['receiver_id'] ?? 0);
    $msg_text = sanitize($_POST['message_text'] ?? '');
    $emoji_chosen = sanitize($_POST['emoji'] ?? '');
    // پیام کوتاهِ داشبورد باید پس از ارسال دوباره به همان داشبورد برگردد.
    $return_to_dashboard = (($_POST['return_to'] ?? '') === 'dashboard' && $receiver === 0);

    if ($is_nazer_chat && $receiver != 0) {
        showToast(t('chat_nazer_only_group'), 'error');
        redirect("index.php?page=chat");
    }
    if (!$is_admin_chat && !$is_nazer_chat && $receiver != 0 && $receiver != 1) {
        showToast(t('فقط امکان ارسال به مدیر یا گروه عمومی را دارید'), 'error');
        redirect("index.php?page=chat");
    }

    if ($msg_text || $emoji_chosen || !empty($_FILES['chat_file'])) {
        try {
            $attachment_path = null;
            $attachment_type = 'emoji';

            if (!empty($_FILES['chat_file']) && $_FILES['chat_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../uploads/chat/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = 'file_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . basename($_FILES['chat_file']['name']);
                $attachment_path = 'uploads/chat/' . $filename;
                move_uploaded_file($_FILES['chat_file']['tmp_name'], $upload_dir . $filename);
                $mime = $_FILES['chat_file']['type'] ?? '';
                // Compress images only
                if (strpos($mime, 'image') !== false) {
                    compress_image($upload_dir . $filename, 1000, 80);
                }
                if (strpos($mime, 'image') !== false) $attachment_type = 'image';
                elseif (strpos($mime, 'audio') !== false || strpos($mime, 'video') !== false) $attachment_type = 'voice';
                else $attachment_type = 'file';
            }

            $final_message = $msg_text ? $msg_text : ($emoji_chosen ? $emoji_chosen : ($attachment_path ? t('فایل ارسالی') : ''));

            if ($chat_has_channels) {
                $stmt = $pdo->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, attachment, attachment_type, is_read, workshop_id, channel_id, is_system) VALUES (?, ?, ?, ?, ?, 0, ?, NULL, 0)");
                $stmt->execute([$user_id, $receiver, $final_message, $attachment_path, $attachment_type, $ws_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, attachment, attachment_type, is_read) VALUES (?, ?, ?, ?, ?, 0)");
                $stmt->execute([$user_id, $receiver, $final_message, $attachment_path, $attachment_type]);
            }
        } catch (Exception $e) {
            showToast(t('خطا در ارسال پیام'), 'error');
        }
    }
    redirect($return_to_dashboard ? 'index.php?page=dashboard' : ("index.php?page=chat&to=" . $receiver));
}

// Handle delete message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_msg') {
    $msg_id = (int)$_POST['msg_id'];
    $pdo->prepare("DELETE FROM chat_messages WHERE id = ? AND sender_id = ?")->execute([$msg_id, $user_id]);
    showToast(t('پیام حذف شد'));
    $back_to = (int)($_POST['back_to'] ?? 0);
    redirect("index.php?page=chat&to=" . $back_to);
}

// Handle edit message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_msg') {
    $msg_id = (int)$_POST['msg_id'];
    $new_text = sanitize($_POST['new_text']);
    $pdo->prepare("UPDATE chat_messages SET message = ? WHERE id = ? AND sender_id = ?")->execute([$new_text, $msg_id, $user_id]);
    $back_to = (int)($_POST['back_to'] ?? 0);
    redirect("index.php?page=chat&to=" . $back_to);
}

// Mark messages as read
if ($user_id > 0) {
    try {
        $pdo->prepare($chat_has_channels ? "UPDATE chat_messages SET is_read = 1 WHERE receiver_id = 0 AND (channel_id IS NULL OR channel_id = 0) AND (workshop_id IS NULL OR workshop_id = ?) AND sender_id != ?" : "UPDATE chat_messages SET is_read = 1 WHERE receiver_id = 0 AND sender_id != ?")->execute($chat_has_channels ? [$ws_id,$user_id] : [$user_id]);
    } catch (Exception $e) {}
}

// Chat users list
$chat_users = [];
if ($is_admin_chat) {
    $users_stmt = $pdo->prepare("SELECT id, full_name, avatar FROM users WHERE status = 'active' AND id != ? ORDER BY full_name");
    $users_stmt->execute([$user_id]);
    $chat_users = $users_stmt->fetchAll();
} elseif ($is_nazer_chat) {
    $chat_users = [];
} else {
    $users_stmt = $pdo->prepare("SELECT id, full_name, avatar FROM users WHERE status = 'active' AND id != ? AND role = 'admin' ORDER BY full_name");
    $users_stmt->execute([$user_id]);
    $chat_users = $users_stmt->fetchAll();
}

// Selected receiver
$selected_receiver = (int)($_GET['to'] ?? ($_GET['user_id'] ?? 0));
if ($is_nazer_chat) $selected_receiver = 0;

// Get messages for selected chat (NOT public group)
$messages = [];
if ($user_id > 0) {
    try {
        if ($selected_receiver == 0) {
            $msg_stmt = $pdo->prepare("SELECT cm.*, u.full_name, u.avatar FROM chat_messages cm JOIN users u ON cm.sender_id = u.id WHERE cm.receiver_id = 0{$public_channel_filter} ORDER BY cm.created_at DESC, cm.id DESC LIMIT 150");
            $msg_stmt->execute();
            $messages = array_reverse($msg_stmt->fetchAll());
        } else {
            $msg_stmt = $pdo->prepare("SELECT cm.*, u.full_name, u.avatar FROM chat_messages cm JOIN users u ON cm.sender_id = u.id WHERE cm.sender_id = ? AND cm.receiver_id = ? AND cm.receiver_id != 0 ORDER BY cm.created_at DESC, cm.id DESC LIMIT 150");
            $msg_stmt->execute([$user_id, $selected_receiver]);
            $msgs1 = $msg_stmt->fetchAll();
            $msg_stmt2 = $pdo->prepare("SELECT cm.*, u.full_name, u.avatar FROM chat_messages cm JOIN users u ON cm.sender_id = u.id WHERE cm.sender_id = ? AND cm.receiver_id = ? AND cm.receiver_id != 0 ORDER BY cm.created_at DESC, cm.id DESC LIMIT 150");
            $msg_stmt2->execute([$selected_receiver, $user_id]);
            $msgs2 = $msg_stmt2->fetchAll();
            $messages = array_merge($msgs1, $msgs2);
            usort($messages, function($a, $b) { return strtotime($a['created_at']) - strtotime($b['created_at']); });
            $messages = array_slice($messages, -150);
        }
    } catch (Exception $e) {
        $messages = [];
    }
}

// Poll endpoint
if (isset($_GET['poll']) && $_GET['poll'] == 1) {
    // §perf: پولینگ نباید قفل سشن را نگه دارد (صف‌شدن درخواست‌ها → خطای 502)
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $poll_sub = (int)($_GET['to'] ?? 0);
    $poll_msgs = [];
    if ($user_id > 0) {
        try {
            if ($poll_sub == 0) {
                $poll_stmt = $pdo->prepare("SELECT cm.*, u.full_name, u.avatar FROM chat_messages cm JOIN users u ON cm.sender_id = u.id WHERE cm.receiver_id = 0{$public_channel_filter} ORDER BY cm.created_at DESC, cm.id DESC LIMIT 150");
                $poll_stmt->execute();
                $poll_msgs = array_reverse($poll_stmt->fetchAll());
            } else {
                $poll_stmt = $pdo->prepare("SELECT cm.*, u.full_name, u.avatar FROM chat_messages cm JOIN users u ON cm.sender_id = u.id WHERE cm.sender_id = ? AND cm.receiver_id = ? AND cm.receiver_id != 0 ORDER BY cm.created_at DESC, cm.id DESC LIMIT 150");
                $poll_stmt->execute([$user_id, $poll_sub]);
                $p1 = $poll_stmt->fetchAll();
                $poll_stmt2 = $pdo->prepare("SELECT cm.*, u.full_name, u.avatar FROM chat_messages cm JOIN users u ON cm.sender_id = u.id WHERE cm.sender_id = ? AND cm.receiver_id = ? AND cm.receiver_id != 0 ORDER BY cm.created_at DESC, cm.id DESC LIMIT 150");
                $poll_stmt2->execute([$poll_sub, $user_id]);
                $p2 = $poll_stmt2->fetchAll();
                $poll_msgs = array_merge($p1, $p2);
                usort($poll_msgs, function($a, $b) { return strtotime($a['created_at']) - strtotime($b['created_at']); });
                $poll_msgs = array_slice($poll_msgs, -150);
            }
        } catch (Exception $e) {}
    }
    echo '<div id="poll-messages">';
    if (empty($poll_msgs)) {
        echo '<div class="text-center text-muted" style="padding:30px;">' . t('هنوز پیامی ارسال نشده') . '</div>';
    } else {
        $prev_date = '';
        foreach ($poll_msgs as $msg) {
            $is_me = $msg['sender_id'] == $user_id;
            $msg_date = date('Y-m-d', strtotime($msg['created_at']));
            $msg_time = date('H:i', strtotime($msg['created_at']));
            // Date separator
            if ($msg_date !== $prev_date) {
                $prev_date = $msg_date;
                echo '<div style="text-align:center;margin:16px 0 8px"><span style="background:var(--border);padding:4px 14px;border-radius:20px;font-size:11px;color:var(--text-secondary)">' . to_jalali($msg_date) . '</span></div>';
            }
            $avatar_char = mb_substr($msg['full_name'] ?? '?', 0, 1);
            echo '<div style="display:flex;justify-content:' . ($is_me ? 'flex-start' : 'flex-end') . ';margin-bottom:8px;flex-direction:row;gap:8px;align-items:flex-end">';
            if (!$is_me) {
                if (!empty($msg['avatar']) && file_exists(__DIR__ . '/../' . $msg['avatar'])) {
                    echo '<img src="' . htmlspecialchars($msg['avatar'], ENT_QUOTES, 'UTF-8') . '" style="width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0" alt="avatar">';
                } else {
                    echo '<div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#8B5CF6,#6366F1);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:12px;flex-shrink:0">' . $avatar_char . '</div>';
                }
            }
            echo '<div style="max-width:70%;padding:10px 14px;border-radius:16px;font-size:13px;line-height:1.6;background:' . ($is_me ? 'var(--primary)' : 'var(--border)') . ';color:' . ($is_me ? '#fff' : 'var(--text)') . ';border-bottom-right-radius:' . ($is_me ? '4px' : '16px') . ';border-bottom-left-radius:' . (!$is_me ? '4px' : '16px') . ';box-shadow:0 2px 8px rgba(0,0,0,0.05)">';
            if (!$is_me) echo '<div style="font-size:11px;font-weight:600;margin-bottom:3px;opacity:0.8">' . htmlspecialchars($msg['full_name']) . '</div>';
            if ($msg['message']) {
                $msg_text_clean = htmlspecialchars($msg['message']);
                if (strlen($msg['message']) < 5 && mb_strlen($msg['message']) <= 2) {
                    echo '<span style="font-size:36px">' . $msg_text_clean . '</span>';
                } else {
                    echo '<p style="margin:0;word-break:break-word">' . nl2br($msg_text_clean) . '</p>';
                }
            }
            if ($msg['attachment']) {
                if ($msg['attachment_type'] === 'image') echo '<img src="' . htmlspecialchars($msg['attachment'], ENT_QUOTES, 'UTF-8') . '" style="max-width:220px;max-height:200px;border-radius:8px;margin-top:6px;display:block;cursor:pointer" onclick="openLightbox(this.src)" alt="' . htmlspecialchars(t('عکس'), ENT_QUOTES, 'UTF-8') . '">';
                if ($msg['attachment_type'] === 'voice') echo '<audio controls style="margin-top:6px;width:200px"><source src="' . $msg['attachment'] . '" type="audio/webm"></audio>';
                if ($msg['attachment_type'] === 'video') echo '<video controls style="max-width:260px;border-radius:8px;margin-top:6px;display:block"><source src="' . $msg['attachment'] . '"></video>';
            }
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">';
            echo '<span style="font-size:10px;opacity:0.6">' . $msg_time . '</span>';
            echo '<div style="position:relative">';
            echo '<button onclick="toggleMsgMenu(event, \'msgmenu' . $msg['id'] . '\')" style="background:none;border:none;cursor:pointer;font-size:16px;opacity:0.4;color:inherit;padding:0 4px">⋮</button>';
            echo '<div id="msgmenu' . $msg['id'] . '" class="msg-menu hidden" style="position:absolute;bottom:100%;' . ($is_me ? 'right' : 'left') . ':0;background:var(--surface);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);z-index:60;min-width:120px;overflow:hidden">';
            echo '<button onclick="replyMsg(\'' . htmlspecialchars(addslashes($msg['message'])) . '\', ' . $msg['sender_id'] . ')" style="display:block;width:100%;text-align:start;padding:8px 14px;border:none;background:none;cursor:pointer;font-size:12px;font-family:inherit;color:var(--text)">💬 ' . t('پاسخ') . '</button>';
            if ($is_me) {
                echo '<button onclick="editMsg(' . $msg['id'] . ', ' . htmlspecialchars(json_encode((string)$msg['message'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ', ' . $poll_sub . ');closeAllMsgMenus()" style="display:block;width:100%;text-align:start;padding:8px 14px;border:none;background:none;cursor:pointer;font-size:12px;font-family:inherit;color:var(--text)">✏️ ' . t('ویرایش') . '</button>';
                echo '<form method="POST" style="margin:0" onsubmit="return confirm(\'' . t('حذف شود؟') . '\')">' . get_csrf_field();
                echo '<input type="hidden" name="action" value="delete_msg"><input type="hidden" name="msg_id" value="' . $msg['id'] . '"><input type="hidden" name="back_to" value="' . $poll_sub . '">';
                echo '<button type="submit" style="display:block;width:100%;text-align:start;padding:8px 14px;border:none;background:none;cursor:pointer;font-size:12px;font-family:inherit;color:#dc2626">🗑️ ' . t('btn_delete') . '</button></form>';
            }
            echo '</div></div></div></div></div>';
        }
    }
    echo '</div>';
    exit;
}

include 'includes/header.php';
?>

<div class="tabs mb-4 animate-in chat-mode-tabs"><a href="index.php?page=chat" class="tab active"><?= t('💬 گفت‌وگو') ?></a><a href="index.php?page=chat&view=channels" class="tab">📣 <?= t('کانال‌ها') ?></a></div>
<div class="chat-wrapper animate-in">
    <div class="chat-sidebar" id="chatSidebar">
        <h3 class="card-title mb-3" style="padding:0 4px"><?= t('chat_group') ?> & <?= t('chat_contacts') ?></h3>
        <a href="index.php?page=chat&to=0" class="chat-contact <?= $selected_receiver == 0 ? 'active' : '' ?>">
            <div class="chat-avatar" style="background:linear-gradient(135deg,var(--primary),var(--accent))">🌐</div>
            <div style="flex:1;min-width:0">
                <strong style="font-size:13px"><?= t('chat_group') ?></strong>
            </div>
        </a>
        <?php foreach ($chat_users as $cu): ?>
        <a href="index.php?page=chat&to=<?= $cu['id'] ?>" class="chat-contact <?= $selected_receiver == $cu['id'] ? 'active' : '' ?>">
            <?php if (!empty($cu['avatar']) && file_exists(__DIR__ . '/../' . $cu['avatar'])): ?>
            <img src="<?= htmlspecialchars($cu['avatar'], ENT_QUOTES, 'UTF-8') ?>" class="chat-avatar" style="object-fit:cover">
            <?php else: ?>
            <div class="chat-avatar"><?= mb_substr($cu['full_name'], 0, 1) ?></div>
            <?php endif; ?>
            <div style="flex:1;min-width:0">
                <strong style="font-size:13px"><?= htmlspecialchars($cu['full_name']) ?></strong>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="chat-main">
        <div class="chat-header-bar">
            <button onclick="document.getElementById('chatSidebar').classList.toggle('show')" class="icon-btn mobile-chat-toggle"><i data-lucide="menu"></i></button>
            <?php if ($selected_receiver > 0): ?>
                <?php $cname = ''; foreach ($chat_users as $cu) { if ($cu['id'] == $selected_receiver) { $cname = $cu['full_name']; break; } } ?>
                <span style="font-weight:600;font-size:14px"><?= htmlspecialchars($cname) ?></span>
            <?php else: ?>
                <span style="font-weight:600;font-size:14px"><?= t('chat_group_all') ?></span>
            <?php endif; ?>
        </div>

        <div class="chat-messages" id="chatMessages">
            <?php if (empty($messages)): ?>
            <div class="text-center text-muted" style="padding:40px"><?= t('chat_no_messages') ?></div>
            <?php else: ?>
            <?php $prev_date = ''; ?>
            <?php foreach ($messages as $msg): ?>
            <?php
                $is_me = $msg['sender_id'] == $user_id;
                $msg_date = date('Y-m-d', strtotime($msg['created_at']));
                $msg_time = date('H:i', strtotime($msg['created_at']));
            ?>
            <?php if ($msg_date !== $prev_date): ?>
                <?php $prev_date = $msg_date; ?>
                <div style="text-align:center;margin:16px 0 8px"><span style="background:var(--border);padding:4px 14px;border-radius:20px;font-size:11px;color:var(--text-secondary)"><?= to_jalali($msg_date) ?></span></div>
            <?php endif; ?>
            <div class="chat-bubble-row <?= $is_me ? 'me' : 'them' ?>">
                <?php if (!$is_me): ?>
                    <?php if (!empty($msg['avatar']) && file_exists(__DIR__ . '/../' . $msg['avatar'])): ?>
                    <img src="<?= htmlspecialchars($msg['avatar'], ENT_QUOTES, 'UTF-8') ?>" class="chat-avatar-sm" style="object-fit:cover">
                    <?php else: ?>
                    <div class="chat-avatar-sm"><?= mb_substr($msg['full_name'] ?? '?', 0, 1) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="chat-bubble <?= $is_me ? 'bubble-me' : 'bubble-them' ?>">
                    <?php if (!$is_me): ?><div style="font-size:11px;font-weight:600;margin-bottom:2px;opacity:0.8"><?= !empty($msg['is_system']) ? '⚙️ ' . t('پیام سیستمی') : htmlspecialchars($msg['full_name']) ?></div><?php endif; ?>
                    <?php if ($msg['message']): ?>
                        <?php if (strlen($msg['message']) < 5 && mb_strlen($msg['message']) <= 2): ?>
                            <span style="font-size:36px"><?= htmlspecialchars($msg['message']) ?></span>
                        <?php else: ?>
                            <p style="margin:0;word-break:break-word"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($msg['attachment']): ?>
                        <?php if ($msg['attachment_type'] === 'image'): ?>
                            <img src="<?= $msg['attachment'] ?>" style="max-width:220px;max-height:200px;border-radius:8px;margin-top:6px;display:block;cursor:pointer" onclick="openLightbox(this.src)" alt="<?= t('عکس') ?>">
                        <?php elseif ($msg['attachment_type'] === 'voice'): ?>
                            <audio controls style="margin-top:6px;width:200px"><source src="<?= $msg['attachment'] ?>" type="audio/webm"></audio>
                        <?php elseif ($msg['attachment_type'] === 'video'): ?>
                            <video controls style="max-width:260px;border-radius:8px;margin-top:6px;display:block"><source src="<?= $msg['attachment'] ?>"></video>
                        <?php else: ?>
                            <a href="<?= $msg['attachment'] ?>" target="_blank" style="display:inline-block;margin-top:6px;padding:6px 12px;background:rgba(255,255,255,0.15);border-radius:8px;text-decoration:none;color:inherit;font-size:12px">📎 <?= t('فایل') ?></a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:4px">
                        <span style="font-size:10px;opacity:0.6"><?= $msg_time ?></span>
                        <div style="position:relative">
                            <button onclick="toggleMsgMenu(event, 'msgmenu<?= $msg['id'] ?>')" style="background:none;border:none;cursor:pointer;font-size:16px;opacity:0.4;color:inherit;padding:0 4px" title="<?= t('گزینه') ?>‌<?= t('ها') ?>">⋮</button>
                            <div id="msgmenu<?= $msg['id'] ?>" class="msg-menu hidden" style="position:absolute;bottom:100%;<?= $is_me ? 'right' : 'left' ?>:0;background:var(--surface);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);z-index:60;min-width:120px;overflow:hidden">
                                <button onclick="replyMsg('<?= htmlspecialchars(addslashes($msg['message'])) ?>', <?= $msg['sender_id'] ?>)" style="display:block;width:100%;text-align:right;padding:8px 14px;border:none;background:none;cursor:pointer;font-size:12px;font-family:inherit;color:var(--text)">💬 <?= t('پاسخ') ?></button>
                                <?php if ($is_me): ?>
                                <button onclick="editMsg(<?= $msg['id'] ?>, '<?= htmlspecialchars(addslashes($msg['message']), ENT_QUOTES) ?>', <?= $selected_receiver ?>);closeAllMsgMenus()" style="display:block;width:100%;text-align:right;padding:8px 14px;border:none;background:none;cursor:pointer;font-size:12px;font-family:inherit;color:var(--text)">✏️ <?= t('btn_edit') ?></button>
                                <form method="POST" style="margin:0" onsubmit="return confirm('<?= t('chat_delete') ?>')"><?= get_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_msg">
                                    <input type="hidden" name="msg_id" value="<?= $msg['id'] ?>">
                                    <input type="hidden" name="back_to" value="<?= $selected_receiver ?>">
                                    <button type="submit" style="display:block;width:100%;text-align:right;padding:8px 14px;border:none;background:none;cursor:pointer;font-size:12px;font-family:inherit;color:var(--danger)">🗑️ <?= t('btn_delete') ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($selected_receiver >= 0): ?>
        <div class="chat-input-bar">
            <form method="POST" enctype="multipart/form-data" id="chatForm" onsubmit="event.preventDefault();sendChatAjax();return false;" style="display:flex;gap:8px;align-items:center;width:100%"><?= get_csrf_field() ?>
                <input type="hidden" name="action" value="send_chat">
                <input type="hidden" name="receiver_id" value="<?= $selected_receiver ?>">
                <input type="hidden" name="emoji" id="emoji-input" value="">
                <div style="position:relative">
                    <button type="button" onclick="document.getElementById('emoji-picker').classList.toggle('hidden')" class="icon-btn" style="font-size:18px">😀</button>
                    <div id="emoji-picker" class="hidden" style="position:absolute;bottom:45px;left:0;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:8px;box-shadow:var(--shadow);z-index:50;display:flex;gap:4px;flex-wrap:wrap;width:240px">
                        <?php foreach (['😀','😂','😍','👍','❤️','🎉','🔥','💯','🙏','✨','😢','😠','🤝','👋','💪'] as $em): ?>
                        <button type="button" onclick="document.getElementById('emoji-input').value='<?= $em ?>';document.getElementById('emoji-picker').classList.add('hidden')" style="font-size:20px;background:none;border:none;cursor:pointer;padding:3px"><?= $em ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label class="icon-btn" style="cursor:pointer;font-size:16px" title="<?= t('عکس') ?> <?= t('یا') ?> <?= t('فایل') ?>">
                    📎<input type="file" name="chat_file" accept="image/*,audio/*,video/*" style="display:none" onchange="if(this.files[0])sendChatAjax()">
                </label>
                <button type="button" class="icon-btn" style="font-size:16px" title="<?= t('برای صحبت نگه دارید') ?>" onmousedown="startMic()" onmouseup="stopMic()">🎤</button>
                <input type="text" name="message_text" class="form-control" placeholder="<?= t('chat_write_message') ?>" style="flex:1;min-width:80px" autocomplete="off" id="chatInput">
                <button type="submit" class="btn btn-primary btn-sm"><?= t('chat_send') ?></button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit message modal -->
<div id="editMsgModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:400px">
        <div class="modal-header"><h3 class="modal-title"><?= t('chat_edit_message') ?></h3><button onclick="closeModal('editMsgModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="edit_msg">
            <input type="hidden" name="msg_id" id="edit_msg_id">
            <input type="hidden" name="back_to" id="edit_msg_back">
            <div class="modal-body">
                <textarea name="new_text" class="form-control" rows="3" id="edit_msg_text" required></textarea>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button></div>
        </form>
    </div>
</div>

<!-- Lightbox -->
<div id="lightbox" class="hidden" onclick="this.classList.add('hidden')" style="position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:20px">
    <img id="lightboxImg" src="" style="max-width:95%;max-height:95%;border-radius:8px;object-fit:contain">
    <button onclick="document.getElementById('lightbox').classList.add('hidden')" style="position:absolute;top:16px;left:16px;background:rgba(255,255,255,0.2);border:none;color:white;font-size:24px;width:40px;height:40px;border-radius:50%;cursor:pointer">✕</button>
</div>

<script>
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.remove('hidden');
    document.getElementById('lightbox').style.display = 'flex';
}

function editMsg(id, text, backTo) {
    document.getElementById('edit_msg_id').value = id;
    document.getElementById('edit_msg_text').value = text;
    document.getElementById('edit_msg_back').value = backTo;
    openModal('editMsgModal');
}

function toggleMsgMenu(e, menuId) {
    e.stopPropagation();
    closeAllMsgMenus();
    var menu = document.getElementById(menuId);
    if (menu) menu.classList.toggle('hidden');
}

function closeAllMsgMenus() {
    document.querySelectorAll('.msg-menu').forEach(function(m) { m.classList.add('hidden'); });
}

function replyMsg(text, senderId) {
    closeAllMsgMenus();
    var input = document.getElementById('chatInput');
    if (input) {
        input.value = '@ replied: ' + text.substring(0, 50) + '... | ';
        input.focus();
    }
}

document.addEventListener('click', function() { closeAllMsgMenus(); });

// Scroll to bottom
var ch = document.getElementById('chatMessages');
if (ch) ch.scrollTop = ch.scrollHeight;

// Send chat via AJAX
function sendChatAjax() {
    var form = document.getElementById('chatForm');
    var fd = new FormData(form);
    fetch('index.php?page=chat', { method: 'POST', body: fd })
        .then(function() {
            document.getElementById('chatInput').value = '';
            document.getElementById('emoji-input').value = '';
            pollMessages();
        });
}

// Poll messages
var __lastMsgCount = -1;
var __pollBusy = false, __pollTick = 0;
function pollMessages() {
    // §perf: هم‌زمان فقط یک درخواست؛ اگر تب پنهان است هر ~۳۰ ثانیه (برای اعلان مرورگر)
    if (__pollBusy) return;
    __pollBusy = true;
    var sub = document.querySelector('input[name="receiver_id"]').value;
    fetch('index.php?page=chat&poll=1&to=' + sub)
        .then(function(r) { __pollBusy = false; return r.text(); }, function(e) { __pollBusy = false; throw e; })
        .then(function(html) {
            var container = document.getElementById('chatMessages');
            var temp = document.createElement('div');
            temp.innerHTML = html;
            var newContent = temp.querySelector('#poll-messages');
            if (newContent && container) {
                // ═══ §13.5: <?= t('اعلان مرورگر') ?> <?= t('برای') ?> <?= t('پیام جدید') ?> ═══
                var msgs = newContent.querySelectorAll('.chat-msg, [data-msg-id]');
                var cnt = msgs.length;
                if (__lastMsgCount >= 0 && cnt > __lastMsgCount && document.hidden
                    && 'Notification' in window && Notification.permission === 'granted') {
                    var lastMsg = msgs[cnt - 1];
                    var lang = (document.documentElement.lang || 'fa').toLowerCase().split('-')[0];
                    var dir = document.documentElement.dir || (lang === 'en' ? 'ltr' : 'rtl');
                    var newMessage = {fa: '<?= t('پیام جدید') ?>', ar: 'رسالة جديدة', en: 'New message'}[lang] || 'New message';
                    var workshop = {fa: '<?= t('کارگاه') ?>', ar: 'الورشة', en: 'Workshop'}[lang] || 'Workshop';
                    var body = (lastMsg.textContent || '').replace(/\s+/g, ' ').trim().substring(0, 90) || newMessage;
                    var who = document.querySelector('.chat-head-title, .chat-title, h3');
                    var n = new Notification((who ? who.textContent.trim() : newMessage) + ' — ' + workshop, {
                        body: body,
                        icon: 'assets/icons/icon-192.png',
                        dir: dir, lang: lang, tag: 'kargah-chat-' + sub
                    });
                    n.onclick = function () { window.focus(); n.close(); };
                }
                __lastMsgCount = cnt;
                container.innerHTML = newContent.innerHTML;
                container.scrollTop = container.scrollHeight;
            }
        });
}
setInterval(function () { if (document.hidden && (++__pollTick % 5) !== 0) return; pollMessages(); }, 6000);
document.addEventListener('visibilitychange', function () { if (!document.hidden) pollMessages(); });

// Voice recording
var mediaRecorder = null;
var audioChunks = [];
function startMic() {
    if (mediaRecorder && mediaRecorder.state === 'recording') return;
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];
        mediaRecorder.ondataavailable = function(e) { audioChunks.push(e.data); };
        mediaRecorder.onstop = function() {
            var blob = new Blob(audioChunks, { type: 'audio/webm' });
            var file = new File([blob], 'voice_' + Date.now() + '.webm', { type: 'audio/webm' });
            var fd = new FormData();
            fd.append('csrf_token', <?= json_encode($_SESSION['csrf_token'] ?? '') ?>);
            fd.append('action', 'send_chat');
            fd.append('receiver_id', document.querySelector('input[name="receiver_id"]').value);
            fd.append('chat_file', file);
            fetch('index.php?page=chat', { method: 'POST', body: fd })
                .then(function() { pollMessages(); });
            stream.getTracks().forEach(function(t) { t.stop(); });
        };
        mediaRecorder.start();
    }).catch(function() { alert('<?= t('امکان ضبط صدا وجود ندارد') ?>'); });
}
function stopMic() {
    if (mediaRecorder && mediaRecorder.state === 'recording') mediaRecorder.stop();
}

// Enter to send
document.getElementById('chatInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChatAjax(); }
});

lucide.createIcons();
</script>
<?php include 'includes/footer.php'; ?>
