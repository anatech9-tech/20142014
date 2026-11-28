<?php
declare(strict_types=1);
/** Authenticated, on-demand read-only lookup; identical copies on website and CRM. */
function content_lookup_key_names(): array { return ['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_CATEGORY_META_KEY','SHOP_COUPON_META_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_COMMAND_KEY','SHOP_REVIEW_CONTENT_GUARD_KEY','SHOP_REVIEW_META_CRM_KEY','SITE_BLOG_CONTENT_GUARD_KEY','SITE_BLOG_META_KEY','SITE_SHIPMENT_META_KEY','SITE_CONTENT_COMMAND_KEY']; }
function content_lookup_configured(bool $outgoing): bool {
    if (!defined('SITE_CONTENT_LOOKUP_ENABLED') || SITE_CONTENT_LOOKUP_ENABLED!==true || !defined('SITE_CONTENT_LOOKUP_KEY') || !is_string(SITE_CONTENT_LOOKUP_KEY) || strlen(SITE_CONTENT_LOOKUP_KEY)<32 || !defined('SITE_CONTENT_LOOKUP_SOURCE_ID') || !is_string(SITE_CONTENT_LOOKUP_SOURCE_ID) || !preg_match('/^[a-f0-9]{32}$/D',SITE_CONTENT_LOOKUP_SOURCE_ID)) return false;
    foreach (content_lookup_key_names() as $name) if (defined($name) && is_string(constant($name)) && hash_equals(constant($name),SITE_CONTENT_LOOKUP_KEY)) return false;
    if ($outgoing) {
        if (!defined('SITE_CONTENT_LOOKUP_URL') || !is_string(SITE_CONTENT_LOOKUP_URL) || !filter_var(SITE_CONTENT_LOOKUP_URL,FILTER_VALIDATE_URL)) return false;
        $url=parse_url(SITE_CONTENT_LOOKUP_URL);
        if (!is_array($url) || strtolower((string)($url['scheme'] ?? ''))!=='https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) return false;
    }
    return true;
}
function content_lookup_kind_enabled(string $kind): bool {
    if ($kind==='service') return defined('SITE_CONTENT_SERVICE_LOOKUP_ENABLED') && SITE_CONTENT_SERVICE_LOOKUP_ENABLED===true;
    if ($kind==='service_en') return content_lookup_kind_enabled('service') && defined('SITE_CONTENT_SERVICE_EN_LOOKUP_ENABLED') && SITE_CONTENT_SERVICE_EN_LOOKUP_ENABLED===true;
    if ($kind==='team') return defined('SITE_CONTENT_TEAM_LOOKUP_ENABLED') && SITE_CONTENT_TEAM_LOOKUP_ENABLED===true;
    return false;
}
function content_lookup_request_valid($r,string $source): bool {
    return is_array($r) && count($r)===5 && !array_diff(array_keys($r),['type','request_id','source_id','kind','object_id']) && ($r['type'] ?? null)==='site_content_lookup_v1'
        && preg_match('/^[a-f0-9]{32}$/D',$source)===1 && ($r['source_id'] ?? null)===$source && is_string($r['request_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D',$r['request_id'])===1
        && in_array($r['kind'] ?? null,['service','service_en','team'],true) && is_int($r['object_id'] ?? null) && $r['object_id']>0;
}
function content_lookup_signature_valid(string $domain,string $key,string $ts,string $sig,string $body,int $now): bool {
    return in_array($domain,['site-content-lookup-request-v1','site-content-lookup-response-v1'],true) && strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300 && preg_match('/^[a-f0-9]{64}$/D',$sig)===1 && hash_equals(hash_hmac('sha256',$domain.'.'.$ts.'.'.$body,$key),$sig);
}
function content_lookup_text_valid($value): bool { return is_string($value) && strlen($value)<=8192 && preg_match('//u',$value)===1 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value); }
function content_lookup_response_valid($r,array $request): bool {
    if (!is_array($r) || !is_bool($r['found'] ?? null)) return false;
    $expected=['type','source_id','request_id','kind','object_id','found'];
    if ($r['found']) $expected=array_merge($expected,['token','first','second']);
    if (count($r)!==count($expected) || array_diff(array_keys($r),$expected) || ($r['type'] ?? null)!=='site_content_lookup_result_v1' || ($r['source_id'] ?? null)!==$request['source_id'] || ($r['request_id'] ?? null)!==$request['request_id'] || ($r['kind'] ?? null)!==$request['kind'] || ($r['object_id'] ?? null)!==$request['object_id']) return false;
    if (!$r['found']) return true;
    return is_string($r['token'] ?? null) && preg_match('/^[a-f0-9]{64}$/D',$r['token'])===1 && content_lookup_text_valid($r['first'] ?? null) && content_lookup_text_valid($r['second'] ?? null);
}
/** Comparison is valid only when lookup and command target the same configured source and HTTPS origin. */
function content_lookup_matches_command(): bool {
    if (!content_lookup_configured(true) || !function_exists('content_command_configured') || !content_command_configured(true) || SITE_CONTENT_LOOKUP_SOURCE_ID!==SITE_CONTENT_COMMAND_SOURCE_ID) return false;
    $a=parse_url(SITE_CONTENT_LOOKUP_URL); $b=parse_url(SITE_CONTENT_COMMAND_URL);
    if (!is_array($a) || !is_array($b) || !isset($a['host'],$b['host']) || isset($a['user']) || isset($a['pass']) || isset($b['user']) || isset($b['pass'])) return false;
    return strtolower($a['host'])===strtolower($b['host']) && ($a['port'] ?? 443)===($b['port'] ?? 443);
}
/** Listing is additionally opt-in and page-limited; inactive items may be sensitive. */
function content_lookup_list_enabled(string $kind): bool {
    if (!content_lookup_kind_enabled($kind)) return false;
    if ($kind==='service') return defined('SITE_CONTENT_SERVICE_LIST_ENABLED') && SITE_CONTENT_SERVICE_LIST_ENABLED===true;
    if ($kind==='team') return defined('SITE_CONTENT_TEAM_LIST_ENABLED') && SITE_CONTENT_TEAM_LIST_ENABLED===true;
    return false;
}
function content_lookup_list_request_valid($r,string $source): bool {
    return is_array($r) && count($r)===5 && !array_diff(array_keys($r),['type','request_id','source_id','kind','after_id']) && ($r['type'] ?? null)==='site_content_list_v1'
        && preg_match('/^[a-f0-9]{32}$/D',$source)===1 && ($r['source_id'] ?? null)===$source && is_string($r['request_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D',$r['request_id'])===1
        && in_array($r['kind'] ?? null,['service','team'],true) && is_int($r['after_id'] ?? null) && $r['after_id']>=0;
}
function content_lookup_list_response_valid($r,array $request): bool {
    $keys=['type','source_id','request_id','kind','after_id','items','next_after'];
    if (!is_array($r) || count($r)!==count($keys) || array_diff(array_keys($r),$keys) || ($r['type'] ?? null)!=='site_content_list_result_v1'
        || ($r['source_id'] ?? null)!==$request['source_id'] || ($r['request_id'] ?? null)!==$request['request_id'] || ($r['kind'] ?? null)!==$request['kind'] || ($r['after_id'] ?? null)!==$request['after_id']
        || !is_array($r['items'] ?? null) || !array_is_list($r['items']) || count($r['items'])>10 || !array_key_exists('next_after',$r)) return false;
    $last=$request['after_id'];
    foreach ($r['items'] as $item) {
        if (!is_array($item) || count($item)!==3 || array_diff(array_keys($item),['id','label','is_active']) || !is_int($item['id'] ?? null) || $item['id']<=$last
            || !content_lookup_text_valid($item['label'] ?? null) || strlen($item['label'])>800 || !is_int($item['is_active'] ?? null) || !in_array($item['is_active'],[0,1],true)) return false;
        $last=$item['id'];
    }
    return $r['next_after']===null || (count($r['items'])===10 && is_int($r['next_after']) && $r['next_after']===$last);
}
