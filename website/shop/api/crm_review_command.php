<?php
/** Review command endpoint; HMAC authenticated and disabled by default. */
declare(strict_types=1);
$private=dirname(__DIR__,3).'/site-crm.local.php'; if (is_file($private)) require_once $private;
require_once __DIR__.'/../../includes/shop/CrmReviewCommands.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!shop_review_command_configured()) { http_response_code(503); echo '{"ok":false,"error":"disabled"}'; exit; }
$body=file_get_contents('php://input',false,null,0,8193);
if ($body===false || strlen($body)>8192) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!shop_review_command_signature_valid((string)SHOP_REVIEW_COMMAND_KEY,(string)($_SERVER['HTTP_X_REVIEW_COMMAND_TIMESTAMP'] ?? ''),(string)($_SERVER['HTTP_X_REVIEW_COMMAND_SIGNATURE'] ?? ''),$body,time())) { http_response_code(403); echo '{"ok":false}'; exit; }
$data=json_decode($body,true); if (!shop_review_command_validate($data,(string)SHOP_CRM_SOURCE_ID)) { http_response_code(422); echo '{"ok":false}'; exit; }
require_once __DIR__.'/../../config.php'; require_once BASE_PATH.'/includes/db.php';
try {
    $pdo=DB::pdo(); shop_review_command_ensure($pdo); shop_review_command_require_innodb($pdo);
    $enabled=shop_review_command_action_enabled($data['type']);
    $result=shop_review_command_apply($pdo,$data,(string)SHOP_CRM_SOURCE_ID,time(),$enabled);
    echo json_encode(['ok'=>true,'command_id'=>$data['command_id'],'result'=>$result]);
} catch (DomainException $e) { http_response_code(409); echo '{"ok":false,"error":"command_conflict"}'; }
catch (Throwable $e) { error_log('Shop review command failed'); http_response_code(503); echo '{"ok":false}'; }
