<?php
/**
 * §Web Push — ارسال خودکار اعلان‌ها (برای اجرای دوره‌ای با کرون)
 *
 * روی هاست یک Cron به این شکل بگذارید (هر ۵ دقیقه):
 *   php /path/to/push/send.php
 * یا اگر هاست فقط URL قبول می‌کند، یک فایل .php محافظ با توکن بسازید و آن را صدا بزنید.
 *
 * خروجی: لاگ خطوط در push/send.log — وضعیت قبلی در push/state.json
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/webpush.php';

$dir = __DIR__;
$state_file = $dir . '/state.json';
$log_file = $dir . '/send.log';
function wp_log($msg) {
    global $log_file;
    @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

$state = [];
if (is_file($state_file)) { $j = json_decode((string)file_get_contents($state_file), true); if (is_array($j)) $state = $j; }
$state['chat_max'] = (int)($state['chat_max'] ?? 0);
$state['reports'] = (int)($state['reports'] ?? -1);
$state['overdue'] = (int)($state['overdue'] ?? -1);

try {
    // ۱) وضعیت فعلی
    $chat_max = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM chat_messages")->fetchColumn();
    $reports = (int)$pdo->query("SELECT COUNT(*) FROM daily_reports WHERE status = 'pending'")->fetchColumn();
    $overdue = 0;
    $st = $pdo->query("SELECT t.type, t.out_type, t.checkout_date, i.reissue_days
        FROM inventory_transactions t JOIN inventory_items i ON i.id = t.item_id
        WHERE t.deleted_at IS NULL AND ((t.type IN ('checkout','consumable') AND t.return_date IS NULL) OR (t.type = 'return' AND t.return_approved = 0))");
    foreach ($st->fetchAll() as $t) {
        $eff = $t['type'];
        if ($eff === 'return') continue;
        if (!empty($t['out_type'])) $eff = $t['out_type'];
        if ($eff === 'custody') continue;
        $iv = (int)($t['reissue_days'] ?? 0);
        if ($iv <= 0 || !$t['checkout_date']) continue;
        if ((int)floor((time() - strtotime($t['checkout_date'])) / 86400) >= $iv) $overdue++;
    }

    // ۲) ساخت رویدادهای جدید
    $events = [];
    if ($chat_max > $state['chat_max']) {
        $events[] = ['title' => '💬 پیام جدید در چت کارگاه', 'body' => 'یک پیام جدید برای شما رسیده است', 'url' => 'index.php?page=chat'];
    }
    if ($reports > $state['reports']) {
        $events[] = ['title' => '📝 گزارش جدید در انتظار تأیید', 'body' => $reports . ' گزارش برای بررسی وجود دارد', 'url' => 'index.php?page=daily_reports'];
    }
    if ($overdue > 0 && $overdue !== $state['overdue']) {
        $events[] = ['title' => '📦 هشدار انبار', 'body' => $overdue . ' کالا بیشتر از بازه مجاز تحویل است', 'url' => 'index.php?page=inventory'];
    }

    // ۳) ارسال
    $keys_file = $dir . '/vapid.json';
    $sent = 0; $failed = 0; $removed = 0;
    if (!empty($events) && is_file($keys_file)) {
        $keys = json_decode((string)file_get_contents($keys_file), true);
        if (is_array($keys) && !empty($keys['public']) && !empty($keys['private_pem'])) {
            $subs = $pdo->query("SELECT * FROM push_subscriptions")->fetchAll();
            foreach ($subs as $sub) {
                foreach ($events as $ev) {
                    $payload = json_encode(['title' => $ev['title'], 'body' => $ev['body'], 'url' => $ev['url']], JSON_UNESCAPED_UNICODE);
                    $body = wp_encrypt_payload($payload, wp_b64u_decode($sub['p256dh']), wp_b64u_decode($sub['auth']));
                    if (!$body) { $failed++; continue; }
                    $res = wp_send($sub['endpoint'], $body, $keys['public'], $keys['private_pem']);
                    if ($res['ok']) { $sent++; }
                    else {
                        $failed++;
                        if (in_array($res['code'], [404, 410], true)) {
                            $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$sub['id']]);
                            $removed++;
                        }
                    }
                }
            }
        }
    }
    wp_log("events=" . count($events) . " sent={$sent} failed={$failed} removed={$removed} (chat={$chat_max} rep={$reports} ow={$overdue})");

    // ۴) ذخیره وضعیت
    $state['chat_max'] = $chat_max;
    $state['reports'] = $reports;
    $state['overdue'] = $overdue;
    $state['ts'] = time();
    @file_put_contents($state_file, json_encode($state));
} catch (Throwable $e) {
    wp_log('ERROR: ' . $e->getMessage());
}
