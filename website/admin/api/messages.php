<?php
/**
 * Messages API
 * POST /api/messages.php?action=reply|delete|assign|status
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

Auth::requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!Auth::verifyCsrf($csrf)) {
    sts_json(403, ['success' => false, 'error' => 'توکن امنیتی نامعتبر.']);
}

try {
    if ($action === 'reply') {
        Auth::requirePermission('messages.reply');

        $id = (int) ($_POST['id'] ?? 0);
        $reply = sts_clean($_POST['reply_message'] ?? '', 5000);
        $status = $_POST['status'] ?? 'replied';
        $priority = $_POST['priority'] ?? 'normal';

        if (!$id || mb_strlen($reply) < 10) {
            sts_json(400, ['success' => false, 'error' => 'پاسخ باید حداقل ۱۰ کاراکتر باشد.']);
        }

        $msg = DB::fetch('SELECT email, name, subject FROM contact_messages WHERE id = ?', [$id]);
        if (!$msg) {
            sts_json(404, ['success' => false, 'error' => 'پیام یافت نشد.']);
        }

        // Send email reply
        $emailSubject = 'پاسخ به پیام شما: ' . ($msg['subject'] ?: 'پیام شما');
        $emailBody = "سلام {$msg['name']},\n\n$reply\n\nبا احترام,\n" . SITE_NAME;
        $headers = [
            'From: ' . SITE_NAME . ' <' . SITE_EMAIL . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        @mail($msg['email'], $emailSubject, $emailBody, implode("\r\n", $headers));

        DB::update('contact_messages', [
            'reply_message' => $reply,
            'replied_at'    => date('Y-m-d H:i:s'),
            'replied_by'    => Auth::userId(),
            'status'        => in_array($status, ['in_progress','replied','closed','spam'], true) ? $status : 'replied',
            'priority'      => in_array($priority, ['low','normal','high','urgent'], true) ? $priority : 'normal',
        ], 'id = :id', ['id' => $id]);

        Auth::logActivity(Auth::userId(), 'reply_message', 'contact_message', $id);

        sts_json(200, ['success' => true, 'message' => 'پاسخ ارسال شد.']);
    }

    if ($action === 'delete') {
        Auth::requirePermission('messages.delete');
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) sts_json(400, ['success' => false, 'error' => 'شناسه نامعتبر.']);

        DB::delete('contact_messages', 'id = :id', ['id' => $id]);
        Auth::logActivity(Auth::userId(), 'delete_message', 'contact_message', $id);
        sts_json(200, ['success' => true]);
    }

    if ($action === 'status') {
        Auth::requirePermission('messages.assign');
        $id = (int) ($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $valid = ['new', 'in_progress', 'replied', 'closed', 'spam'];
        if (!$id || !in_array($status, $valid, true)) {
            sts_json(400, ['success' => false, 'error' => 'ورودی نامعتبر.']);
        }
        DB::update('contact_messages', ['status' => $status], 'id = :id', ['id' => $id]);
        Auth::logActivity(Auth::userId(), 'change_status', 'contact_message', $id, $status);
        sts_json(200, ['success' => true]);
    }

    sts_json(400, ['success' => false, 'error' => 'عملیات ناشناخته.']);
} catch (Throwable $e) {
    error_log('Messages API error: ' . $e->getMessage());
    sts_json(500, ['success' => false, 'error' => 'خطای سرور: ' . $e->getMessage()]);
}
