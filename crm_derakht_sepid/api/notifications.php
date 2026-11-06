<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['error' => t('درخواست نامعتبر یا منقضی است.')]);
    exit;
}

// Get messages for a specific person
if (isset($_GET['person_id'])) {
    $person_id = (int)$_GET['person_id'];
    $ws_id = $_SESSION['workshop_id'] ?? 0;
    
    // Messages sent by admin to this person + replies from this person
    $stmt = $pdo->prepare("SELECT n.title, n.message, n.is_task, n.created_at, nr.attachment, nr.reply_to_task, u.full_name as sender FROM notifications n JOIN notification_recipients nr ON n.id = nr.notification_id JOIN users u ON n.created_by = u.id WHERE nr.user_id = ? AND n.created_by != ? AND n.created_by IN (SELECT user_id FROM workshop_personnel WHERE workshop_id = ?) ORDER BY n.created_at DESC LIMIT 50");
    $stmt->execute([$person_id, $person_id, $ws_id]);
    $sent = $stmt->fetchAll();
    
    // Replies from this person
    $stmt = $pdo->prepare("SELECT n.title, n.message, n.is_task, n.created_at, nr.attachment, nr.reply_to_task, u.full_name as sender FROM notifications n JOIN notification_recipients nr ON n.id = nr.notification_id JOIN users u ON n.created_by = u.id WHERE n.created_by = ? AND nr.user_id != ? ORDER BY n.created_at DESC LIMIT 50");
    $stmt->execute([$person_id, $person_id]);
    $replies = $stmt->fetchAll();
    
    $all = array_merge($sent, $replies);
    usort($all, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
    
    $result = [];
    foreach ($all as $row) {
        $result[] = [
            'title' => $row['title'],
            'message' => $row['message'],
            'is_task' => (int)$row['is_task'],
            'reply_to_task' => (int)$row['reply_to_task'],
            'attachment' => $row['attachment'],
            'sender' => $row['sender'],
            'date' => to_jalali($row['created_at']),
        ];
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

// §v11: شمارش پیام‌های نخوانده چت (برای بج زنده روی آیکون پیام‌رسان)
if ($action === 'chat_count') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode(['count' => (int)$stmt->fetchColumn()]);
    exit;
}

if ($action === 'count') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification_recipients WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode(['count' => (int)$stmt->fetchColumn()]);
    exit;
}

if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE notification_recipients SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'invalid action']);
