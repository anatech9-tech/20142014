<?php
/** Authenticated opt-in shipment metadata receiver; separate from orders. */
if (is_file(__DIR__.'/../config.local.php')) require_once __DIR__.'/../config.local.php';
require_once __DIR__.'/../includes/site_shipment_metadata_sync.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!crm_site_shipment_sync_configured()) { http_response_code(503); echo '{"ok":false,"error":"disabled"}'; exit; }
$body=file_get_contents('php://input',false,null,0,2049);
if ($body===false || strlen($body)>2048) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!crm_site_shipment_sync_signature_valid((string)SITE_SHIPMENT_META_KEY,(string)($_SERVER['HTTP_X_SHIPMENT_META_TIMESTAMP'] ?? ''),(string)($_SERVER['HTTP_X_SHIPMENT_META_SIGNATURE'] ?? ''),$body,time())) { http_response_code(403); echo '{"ok":false}'; exit; }
$data=json_decode($body,true); if (!crm_site_shipment_metadata_validate($data,(string)SITE_SHIPMENT_SOURCE_ID)) { http_response_code(422); echo '{"ok":false,"error":"invalid"}'; exit; }
if ($data['type']==='site_shipment_meta_deleted_v1' && !crm_site_shipment_sync_deletion_enabled()) { http_response_code(503); echo '{"ok":false,"error":"deletion_disabled"}'; exit; }
require_once __DIR__.'/../config.php';
if (!crm_site_shipment_sync_ensure($pdo)) { http_response_code(503); echo '{"ok":false}'; exit; }
try {
    crm_site_shipment_sync_require_innodb($pdo); $result=crm_site_shipment_sync_store($pdo,$data,hash('sha256',$body));
    if ($result==='conflict') { http_response_code(409); echo '{"ok":false,"error":"revision_conflict"}'; exit; }
    echo json_encode(['ok'=>true,'event_id'=>$data['event_id'],'revision'=>$data['revision'],'result'=>$result]);
} catch (Throwable $e) { error_log('Shipment metadata reception failed'); http_response_code(500); echo '{"ok":false}'; }
