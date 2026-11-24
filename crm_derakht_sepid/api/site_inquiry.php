<?php
/** Server-to-server website contact endpoint; no browser session or public token. */
if (is_file(__DIR__ . '/../config.local.php')) require_once __DIR__ . '/../config.local.php';
require_once __DIR__ . '/../includes/site_inquiries.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$key = defined('SITE_CRM_BRIDGE_KEY') ? (string)SITE_CRM_BRIDGE_KEY : '';
if (strlen($key) < 32) { http_response_code(503); echo '{"ok":false,"error":"not_configured"}'; exit; }
$body = file_get_contents('php://input', false, null, 0, 32769);
if ($body === false || strlen($body)>32768) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!site_inquiry_signature_valid($key, (string)($_SERVER['HTTP_X_SITE_TIMESTAMP'] ?? ''), (string)($_SERVER['HTTP_X_SITE_SIGNATURE'] ?? ''), $body, time())) {
    http_response_code(403); echo '{"ok":false}'; exit;
}
$data=site_inquiry_validate(json_decode($body,true));
if (!$data) { http_response_code(422); echo '{"ok":false,"error":"invalid"}'; exit; }
require_once __DIR__ . '/../config.php';
if (!ensure_site_inquiries($pdo)) { http_response_code(503); echo '{"ok":false}'; exit; }
try {
    $result=site_inquiry_store($pdo,$data,hash('sha256',$body));
    if ($result==='conflict') { http_response_code(409); echo '{"ok":false,"error":"event_conflict"}'; exit; }
    echo json_encode(['ok'=>true,'event_id'=>$data['event_id'],'result'=>$result]);
} catch (Throwable $e) { error_log('Site inquiry reception failed'); http_response_code(500); echo '{"ok":false}'; }
