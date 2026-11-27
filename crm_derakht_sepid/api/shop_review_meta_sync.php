<?php
/** Authenticated, opt-in minimal review metadata from the shop; no personal review text or moderation. */
if (is_file(__DIR__.'/../config.local.php')) require_once __DIR__.'/../config.local.php';
require_once __DIR__.'/../includes/shop_review_meta_sync.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!crm_review_meta_configured()) { http_response_code(503); echo '{"ok":false,"error":"disabled"}'; exit; }
$body=file_get_contents('php://input',false,null,0,2049);
if ($body===false || strlen($body)>2048) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!crm_review_meta_signature_valid((string)SHOP_REVIEW_META_CRM_KEY,(string)($_SERVER['HTTP_X_REVIEW_META_TIMESTAMP'] ?? ''),(string)($_SERVER['HTTP_X_REVIEW_META_SIGNATURE'] ?? ''),$body,time())) { http_response_code(403); echo '{"ok":false}'; exit; }
$data=json_decode($body,true);
if (!crm_review_meta_validate($data,(string)SHOP_CRM_SOURCE_ID)) { http_response_code(422); echo '{"ok":false,"error":"invalid"}'; exit; }
if ($data['type']==='shop_review_meta_deleted' && !crm_review_meta_deletion_enabled()) { http_response_code(503); echo '{"ok":false,"error":"deletion_disabled"}'; exit; }
require_once __DIR__.'/../config.php';
if (!crm_review_meta_ensure($pdo)) { http_response_code(503); echo '{"ok":false}'; exit; }
try {
    crm_review_meta_require_innodb($pdo);
    $result=crm_review_meta_store($pdo,$data,hash('sha256',$body));
    if ($result==='conflict') { http_response_code(409); echo '{"ok":false,"error":"revision_conflict"}'; exit; }
    echo json_encode(['ok'=>true,'event_id'=>$data['event_id'],'revision'=>$data['revision'],'result'=>$result]);
} catch (Throwable $e) { error_log('Shop review metadata reception failed'); http_response_code(500); echo '{"ok":false}'; }
