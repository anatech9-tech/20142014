<?php
/** HMAC-authenticated, disabled by default; never loads DB before authentication. */
declare(strict_types=1);
$private=dirname(__DIR__,2).'/site-crm.local.php'; if (is_file($private)) require_once $private;
require_once __DIR__.'/../includes/content/ContentCommandReceiver.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!content_command_configured(false)) { http_response_code(503); echo '{"ok":false,"error":"disabled"}'; exit; }
$body=file_get_contents('php://input',false,null,0,8193); if ($body===false || strlen($body)>8192) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!content_command_signature_valid(SITE_CONTENT_COMMAND_KEY,(string)($_SERVER['HTTP_X_CONTENT_COMMAND_TIMESTAMP'] ?? ''),(string)($_SERVER['HTTP_X_CONTENT_COMMAND_SIGNATURE'] ?? ''),$body,time())) { http_response_code(403); echo '{"ok":false}'; exit; }
$c=json_decode($body,true); if (!content_command_validate($c,SITE_CONTENT_COMMAND_SOURCE_ID)) { http_response_code(422); echo '{"ok":false}'; exit; }
$kind=content_command_kind($c); if (!content_command_kind_enabled(content_command_mode($c))) { http_response_code(503); echo '{"ok":false,"error":"action_disabled"}'; exit; }
require_once __DIR__.'/../config.php'; require_once BASE_PATH.'/includes/db.php';
try { $pdo=DB::pdo(); content_receiver_ensure($pdo); content_receiver_require_innodb($pdo,$kind); $result=content_receiver_apply($pdo,$c,SITE_CONTENT_COMMAND_SOURCE_ID,hash('sha256',$body),time()); echo json_encode(['ok'=>true,'command_id'=>$c['command_id'],'result'=>$result]); }
catch (DomainException $e) { http_response_code(409); echo '{"ok":false,"error":"receipt_conflict"}'; }
catch (Throwable $e) { error_log('Content command failed'); http_response_code(503); echo '{"ok":false}'; }
