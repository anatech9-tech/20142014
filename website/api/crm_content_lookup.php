<?php
/** Independently opt-in read-only endpoint. DB opens only after HMAC and input validation. */
declare(strict_types=1);
$private=dirname(__DIR__,2).'/site-crm.local.php'; if (is_file($private)) require_once $private;
require_once __DIR__.'/../includes/content/ContentLookup.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!content_lookup_configured(false)) { http_response_code(503); echo '{"ok":false,"error":"disabled"}'; exit; }
$body=file_get_contents('php://input',false,null,0,1025); if ($body===false || strlen($body)>1024) { http_response_code(413); echo '{"ok":false}'; exit; }
if (!content_lookup_signature_valid('site-content-lookup-request-v1',SITE_CONTENT_LOOKUP_KEY,(string)($_SERVER['HTTP_X_CONTENT_LOOKUP_TIMESTAMP'] ?? ''),(string)($_SERVER['HTTP_X_CONTENT_LOOKUP_SIGNATURE'] ?? ''),$body,time())) { http_response_code(403); echo '{"ok":false}'; exit; }
$request=json_decode($body,true); $isList=content_lookup_list_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID);
if (!$isList && !content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID)) { http_response_code(422); echo '{"ok":false}'; exit; }
if ($isList ? !content_lookup_list_enabled($request['kind']) : !content_lookup_kind_enabled($request['kind'])) { http_response_code(503); echo '{"ok":false,"error":"action_disabled"}'; exit; }
require_once __DIR__.'/../config.php'; require_once BASE_PATH.'/includes/db.php';
try {
    $result=$isList?content_lookup_list(DB::pdo(),$request):content_lookup_read(DB::pdo(),$request); $data=json_encode($result,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($data===false || strlen($data)>20000) throw new RuntimeException('Encoding failed');
    $ts=(string)time(); $sig=hash_hmac('sha256','site-content-lookup-response-v1.'.$ts.'.'.$data,SITE_CONTENT_LOOKUP_KEY);
    echo json_encode(['data'=>$data,'timestamp'=>$ts,'signature'=>$sig],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) { error_log('Content lookup failed'); http_response_code(503); echo '{"ok":false}'; }
