<?php
/** Synchronous, separately opt-in lookup; no data is persisted here. */
require_once __DIR__.'/site_content_lookup_contract.php';
function crm_content_lookup_fetch(string $kind,int $id,?callable $transport=null): array {
    if (!content_lookup_configured(true) || !content_lookup_kind_enabled($kind) || $id<=0) throw new DomainException('دریافت این نوع داده فعال یا آماده نیست.');
    $request=['type'=>'site_content_lookup_v1','request_id'=>bin2hex(random_bytes(16)),'source_id'=>SITE_CONTENT_LOOKUP_SOURCE_ID,'kind'=>$kind,'object_id'=>$id];
    if (!content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID)) throw new DomainException('درخواست نامعتبر است.');
    return crm_content_lookup_exchange($request,'content_lookup_response_valid',$transport);
}
function crm_content_lookup_list_fetch(string $kind,int $after,?callable $transport=null): array {
    if (!content_lookup_configured(true) || !content_lookup_list_enabled($kind) || $after<0) throw new DomainException('فهرست این نوع داده فعال یا آماده نیست.');
    $request=['type'=>'site_content_list_v1','request_id'=>bin2hex(random_bytes(16)),'source_id'=>SITE_CONTENT_LOOKUP_SOURCE_ID,'kind'=>$kind,'after_id'=>$after];
    if (!content_lookup_list_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID)) throw new DomainException('درخواست نامعتبر است.');
    return crm_content_lookup_exchange($request,'content_lookup_list_response_valid',$transport);
}
/** Validate authenticated response and bind it to exactly one request. */
function crm_content_lookup_exchange(array $request,callable $validate,?callable $transport=null): array {
    $body=json_encode($request,JSON_UNESCAPED_SLASHES); if ($body===false) throw new RuntimeException('Encoding failed');
    [$http,$outer]=($transport ?? 'crm_content_lookup_transport')($body);
    if ($http!==200 || !is_array($outer) || count($outer)!==3 || array_diff(array_keys($outer),['data','timestamp','signature']) || !is_string($outer['data'] ?? null) || strlen($outer['data'])>20000 || !is_string($outer['timestamp'] ?? null) || !is_string($outer['signature'] ?? null)
        || !content_lookup_signature_valid('site-content-lookup-response-v1',SITE_CONTENT_LOOKUP_KEY,$outer['timestamp'],$outer['signature'],$outer['data'],time())) throw new RuntimeException('Lookup response unavailable');
    $result=json_decode($outer['data'],true); if (!$validate($result,$request)) throw new RuntimeException('Lookup response invalid');
    return $result;
}
function crm_content_lookup_transport(string $body): array {
    if (!content_lookup_configured(true) || !function_exists('curl_init')) throw new RuntimeException('Lookup disabled');
    $ts=(string)time(); $sig=hash_hmac('sha256','site-content-lookup-request-v1.'.$ts.'.'.$body,SITE_CONTENT_LOOKUP_KEY); $response=''; $ch=curl_init(SITE_CONTENT_LOOKUP_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Content-Lookup-Timestamp: '.$ts,'X-Content-Lookup-Signature: '.$sig],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>45000) return 0; $response.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$ok===false?0:$http,json_decode($response,true)];
}
