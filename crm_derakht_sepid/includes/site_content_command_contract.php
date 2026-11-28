<?php
declare(strict_types=1);
/** Identical, strict wire contract on both systems. Selected text fields only. */
function content_command_keys(): array { return ['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_CATEGORY_META_KEY','SHOP_COUPON_META_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_COMMAND_KEY','SHOP_REVIEW_CONTENT_GUARD_KEY','SHOP_REVIEW_META_CRM_KEY','SITE_BLOG_CONTENT_GUARD_KEY','SITE_BLOG_META_KEY','SITE_SHIPMENT_META_KEY','SITE_CONTENT_LOOKUP_KEY']; }
function content_command_configured(bool $outgoing): bool {
    if (!defined('SITE_CONTENT_COMMAND_ENABLED') || SITE_CONTENT_COMMAND_ENABLED!==true || !defined('SITE_CONTENT_COMMAND_KEY') || !is_string(SITE_CONTENT_COMMAND_KEY) || strlen(SITE_CONTENT_COMMAND_KEY)<32 || !defined('SITE_CONTENT_COMMAND_SOURCE_ID') || !is_string(SITE_CONTENT_COMMAND_SOURCE_ID) || !preg_match('/^[a-f0-9]{32}$/D',SITE_CONTENT_COMMAND_SOURCE_ID)) return false;
    foreach (content_command_keys() as $other) if (defined($other) && is_string(constant($other)) && hash_equals(constant($other),SITE_CONTENT_COMMAND_KEY)) return false;
    if ($outgoing && (!defined('SITE_CONTENT_COMMAND_URL') || !is_string(SITE_CONTENT_COMMAND_URL) || !filter_var(SITE_CONTENT_COMMAND_URL,FILTER_VALIDATE_URL) || strtolower((string)parse_url(SITE_CONTENT_COMMAND_URL,PHP_URL_SCHEME))!=='https')) return false;
    return true;
}
function content_command_kind_enabled(string $kind): bool {
    if ($kind==='service') return defined('SITE_CONTENT_SERVICE_EDIT_ENABLED') && SITE_CONTENT_SERVICE_EDIT_ENABLED===true;
    if ($kind==='service_en') return content_command_kind_enabled('service') && defined('SITE_CONTENT_SERVICE_EN_EDIT_ENABLED') && SITE_CONTENT_SERVICE_EN_EDIT_ENABLED===true;
    if ($kind==='team') return defined('SITE_CONTENT_TEAM_EDIT_ENABLED') && SITE_CONTENT_TEAM_EDIT_ENABLED===true;
    return false;
}
function content_command_text($value,int $min,int $max,bool $multiline=false): bool {
    if (!is_string($value) || strlen($value)>$max*4 || preg_match('//u',$value)!==1 || trim($value)!==$value || preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value)) return false;
    $length=preg_match_all('/./us',$value); return $length!==false && $length >= $min && $length <= $max;
}
function content_command_validate($c,string $source): bool {
    $keys=['type','command_id','source_id','object_id','actor_id','expected_token','issued_at','expires_at','fields'];
    if (!is_array($c) || count($c)!==count($keys) || array_diff(array_keys($c),$keys) || !preg_match('/^[a-f0-9]{32}$/D',$source) || ($c['source_id'] ?? null)!==$source
      || !is_string($c['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$c['command_id']) || !is_string($c['expected_token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$c['expected_token'])) return false;
    foreach (['object_id','actor_id','issued_at','expires_at'] as $key) if (!is_int($c[$key] ?? null) || $c[$key]<=0) return false;
    if ($c['issued_at']>PHP_INT_MAX-900 || $c['expires_at']!==$c['issued_at']+900 || !is_array($c['fields'] ?? null) || count($c['fields'])!==2) return false;
    $f=$c['fields'];
    if (($c['type'] ?? null)==='service_text_v1') return !array_diff(array_keys($f),['title_fa','description_fa']) && content_command_text($f['title_fa'] ?? null,2,200) && content_command_text($f['description_fa'] ?? null,1,1000,true);
    if (($c['type'] ?? null)==='service_english_text_v1') return !array_diff(array_keys($f),['title_en','description_en']) && content_command_text($f['title_en'] ?? null,0,200) && content_command_text($f['description_en'] ?? null,0,1000,true);
    if (($c['type'] ?? null)==='team_text_v1') return !array_diff(array_keys($f),['name','role']) && content_command_text($f['name'] ?? null,2,100) && content_command_text($f['role'] ?? null,2,200);
    return false;
}
function content_command_mode(array $c): string {
    if (($c['type'] ?? '')==='service_text_v1') return 'service';
    if (($c['type'] ?? '')==='service_english_text_v1') return 'service_en';
    if (($c['type'] ?? '')==='team_text_v1') return 'team';
    return '';
}
function content_command_kind(array $c): string { $mode=content_command_mode($c); return $mode==='service_en'?'service':$mode; }
function content_command_type(string $mode): string { return $mode==='service'?'service_text_v1':($mode==='service_en'?'service_english_text_v1':($mode==='team'?'team_text_v1':'')); }
function content_command_signature_valid(string $key,string $ts,string $sig,string $body,int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300 && preg_match('/^[a-f0-9]{64}$/D',$sig)===1 && hash_equals(hash_hmac('sha256','site-content-command-v1.'.$ts.'.'.$body,$key),$sig);
}
