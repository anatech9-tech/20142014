<?php
/** Poll recent completed CDRs while an administrator has the calls screen open. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/telefonchy.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!is_logged_in() || !is_strict_admin()) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }
if (!verify_csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(419); echo json_encode(['ok'=>false]); exit; }
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$locked = false;
try {
    // The DB lock and timestamp are shared across all open browsers and PHP workers.
    $locked = (int)$pdo->query("SELECT GET_LOCK('wttp_calls_refresh',0)")->fetchColumn() === 1;
    if (!$locked) { echo json_encode(['ok'=>true,'throttled'=>true]); exit; }
    $last=(int)tel_setting($pdo,'calls_background_attempt','0');
    if (time()-$last < 40) { echo json_encode(['ok'=>true,'throttled'=>true]); exit; }
    tel_set_setting($pdo,'calls_background_attempt',(string)time());
    // Honor the existing AI auto setting and its daily cap (up to two new analyses per pass).
    $r=tel_sync_calls($pdo,3,2);
    $poll = empty($r['errors']) ? tel_poll_pending_analyses($pdo,1,5) : ['polled'=>0];
    echo json_encode(['ok'=>empty($r['errors']),'inserted'=>(int)$r['inserted'],'polled'=>(int)($poll['polled'] ?? 0)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Calls background refresh: '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false]);
} finally {
    if ($locked) { try { $pdo->query("SELECT RELEASE_LOCK('wttp_calls_refresh')"); } catch (Throwable $e) {} }
}
