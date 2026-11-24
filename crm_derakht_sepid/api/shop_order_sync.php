<?php
/** Authenticated, bounded server-to-server read-only shop replication. */
if (is_file(__DIR__.'/../config.local.php')) require_once __DIR__.'/../config.local.php';
require_once __DIR__.'/../includes/site_inquiries.php';
require_once __DIR__.'/../includes/shop_order_sync.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$key=defined('SHOP_CRM_BRIDGE_KEY') ? (string)SHOP_CRM_BRIDGE_KEY : '';
$source=defined('SHOP_CRM_SOURCE_ID') ? (string)SHOP_CRM_SOURCE_ID : '';
if (strlen($key)<32 || !preg_match('/^[a-f0-9]{32}$/D',$source)) { http_response_code(503); echo '{"ok":false,"error":"not_configured"}'; exit; }
$body=file_get_contents('php://input',false,null,0,524289);
if ($body===false || strlen($body)>524288) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!site_inquiry_signature_valid($key,(string)($_SERVER['HTTP_X_SITE_TIMESTAMP'] ?? ''),(string)($_SERVER['HTTP_X_SITE_SIGNATURE'] ?? ''),$body,time())) { http_response_code(403); echo '{"ok":false}'; exit; }
$data=json_decode($body,true);
if (!crm_shop_order_validate($data,$source)) { http_response_code(422); echo '{"ok":false,"error":"invalid"}'; exit; }
require_once __DIR__.'/../config.php';
if (!crm_shop_orders_ensure($pdo)) { http_response_code(503); echo '{"ok":false}'; exit; }
try {
    $result=crm_shop_order_store($pdo,$data,hash('sha256',$body));
    if ($result==='conflict') { http_response_code(409); echo '{"ok":false,"error":"revision_conflict"}'; exit; }
    echo json_encode(['ok'=>true,'event_id'=>$data['event_id'],'revision'=>$data['revision'],'result'=>$result]);
} catch (Throwable $e) { error_log('Shop replica reception failed'); http_response_code(500); echo '{"ok":false}'; }
