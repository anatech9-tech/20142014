<?php
/** Authenticated, opt-in product snapshots from the shop; no mutation of the CRM product bank. */
if (is_file(__DIR__.'/../config.local.php')) require_once __DIR__.'/../config.local.php';
require_once __DIR__.'/../includes/shop_catalog_sync.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!crm_shop_catalog_configured()) { http_response_code(503); echo '{"ok":false,"error":"disabled"}'; exit; }
$body=file_get_contents('php://input',false,null,0,16385);
if ($body===false || strlen($body)>16384) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!crm_shop_catalog_signature_valid((string)SHOP_CATALOG_CRM_KEY,(string)($_SERVER['HTTP_X_CATALOG_TIMESTAMP'] ?? ''),(string)($_SERVER['HTTP_X_CATALOG_SIGNATURE'] ?? ''),$body,time())) { http_response_code(403); echo '{"ok":false}'; exit; }
$data=json_decode($body,true);
if (!crm_shop_catalog_validate($data,(string)SHOP_CRM_SOURCE_ID)) { http_response_code(422); echo '{"ok":false,"error":"invalid"}'; exit; }
require_once __DIR__.'/../config.php';
if (!crm_shop_catalog_ensure($pdo)) { http_response_code(503); echo '{"ok":false}'; exit; }
try {
    crm_shop_catalog_require_innodb($pdo);
    $result=crm_shop_catalog_store($pdo,$data,hash('sha256',$body));
    if ($result==='conflict') { http_response_code(409); echo '{"ok":false,"error":"revision_conflict"}'; exit; }
    echo json_encode(['ok'=>true,'event_id'=>$data['event_id'],'revision'=>$data['revision'],'result'=>$result]);
} catch (Throwable $e) { error_log('Shop catalogue reception failed'); http_response_code(500); echo '{"ok":false}'; }
