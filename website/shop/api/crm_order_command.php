<?php
/** Server-to-server only; disabled unless explicitly configured outside web root. */
declare(strict_types=1);
$private=dirname(__DIR__,3).'/site-crm.local.php';
if (is_file($private)) require_once $private;
require_once __DIR__.'/../../includes/shop/CrmOrderCommands.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!shop_command_configured()) { http_response_code(503); echo '{"ok":false,"error":"disabled"}'; exit; }
$body=file_get_contents('php://input',false,null,0,8193);
if ($body===false || strlen($body)>8192) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!shop_command_signature_valid((string)SHOP_CRM_COMMAND_KEY,(string)($_SERVER['HTTP_X_COMMAND_TIMESTAMP'] ?? ''),(string)($_SERVER['HTTP_X_COMMAND_SIGNATURE'] ?? ''),$body,time())) { http_response_code(403); echo '{"ok":false}'; exit; }
$data=json_decode($body,true);
if (!shop_command_validate($data,(string)SHOP_CRM_SOURCE_ID)) { http_response_code(422); echo '{"ok":false}'; exit; }
require_once __DIR__.'/../../config.php';
require_once BASE_PATH.'/includes/db.php';
require_once BASE_PATH.'/includes/shop/CrmOrderSync.php';
try {
    $pdo=DB::pdo(); shop_command_ensure($pdo); shop_command_require_innodb($pdo,$data['type']==='shop_cancel_v1');
    $result=shop_command_apply($pdo,$data,(string)SHOP_CRM_SOURCE_ID,time());
    echo json_encode(['ok'=>true,'command_id'=>$data['command_id'],'result'=>$result]);
} catch (DomainException $e) { http_response_code(409); echo '{"ok":false,"error":"command_conflict"}'; }
catch (Throwable $e) { error_log('Shop CRM command execution failed'); http_response_code(503); echo '{"ok":false}'; }
