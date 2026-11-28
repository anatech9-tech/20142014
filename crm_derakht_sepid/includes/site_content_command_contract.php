<?php
declare(strict_types=1);
/** Identical, strict wire contract on both systems. Selected text, inactive creation, or independently opt-in visibility, order, team contact and image path fields only. */
function content_command_keys(): array { return ['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_CATEGORY_META_KEY','SHOP_COUPON_META_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_COMMAND_KEY','SHOP_REVIEW_CONTENT_GUARD_KEY','SHOP_REVIEW_META_CRM_KEY','SITE_BLOG_CONTENT_GUARD_KEY','SITE_BLOG_META_KEY','SITE_SHIPMENT_META_KEY','SITE_CONTENT_LOOKUP_KEY']; }
function content_command_configured(bool $outgoing): bool {
    if (!defined('SITE_CONTENT_COMMAND_ENABLED') || SITE_CONTENT_COMMAND_ENABLED!==true || !defined('SITE_CONTENT_COMMAND_KEY') || !is_string(SITE_CONTENT_COMMAND_KEY) || strlen(SITE_CONTENT_COMMAND_KEY)<32 || !defined('SITE_CONTENT_COMMAND_SOURCE_ID') || !is_string(SITE_CONTENT_COMMAND_SOURCE_ID) || !preg_match('/^[a-f0-9]{32}$/D',SITE_CONTENT_COMMAND_SOURCE_ID)) return false;
    foreach (content_command_keys() as $other) if (defined($other) && is_string(constant($other)) && hash_equals(constant($other),SITE_CONTENT_COMMAND_KEY)) return false;
    if ($outgoing && (!defined('SITE_CONTENT_COMMAND_URL') || !is_string(SITE_CONTENT_COMMAND_URL) || !filter_var(SITE_CONTENT_COMMAND_URL,FILTER_VALIDATE_URL) || strtolower((string)parse_url(SITE_CONTENT_COMMAND_URL,PHP_URL_SCHEME))!=='https')) return false;
    return true;
}
function content_command_kind_enabled(string $kind): bool {
    if ($kind==='service_create') return PHP_INT_SIZE>=8 && defined('SITE_CONTENT_SERVICE_CREATE_ENABLED') && SITE_CONTENT_SERVICE_CREATE_ENABLED===true;
    if ($kind==='team_create') return PHP_INT_SIZE>=8 && defined('SITE_CONTENT_TEAM_CREATE_ENABLED') && SITE_CONTENT_TEAM_CREATE_ENABLED===true;
    if ($kind==='service') return defined('SITE_CONTENT_SERVICE_EDIT_ENABLED') && SITE_CONTENT_SERVICE_EDIT_ENABLED===true;
    if ($kind==='service_image') return content_command_kind_enabled('service') && defined('SITE_CONTENT_SERVICE_IMAGE_EDIT_ENABLED') && SITE_CONTENT_SERVICE_IMAGE_EDIT_ENABLED===true;
    if ($kind==='service_order') return PHP_INT_SIZE>=8 && content_command_kind_enabled('service') && defined('SITE_CONTENT_SERVICE_ORDER_EDIT_ENABLED') && SITE_CONTENT_SERVICE_ORDER_EDIT_ENABLED===true;
    if ($kind==='service_visibility') return content_command_kind_enabled('service') && defined('SITE_CONTENT_SERVICE_VISIBILITY_EDIT_ENABLED') && SITE_CONTENT_SERVICE_VISIBILITY_EDIT_ENABLED===true;
    if ($kind==='service_en') return content_command_kind_enabled('service') && defined('SITE_CONTENT_SERVICE_EN_EDIT_ENABLED') && SITE_CONTENT_SERVICE_EN_EDIT_ENABLED===true;
    if ($kind==='team') return defined('SITE_CONTENT_TEAM_EDIT_ENABLED') && SITE_CONTENT_TEAM_EDIT_ENABLED===true;
    if ($kind==='team_contact') return content_command_kind_enabled('team') && defined('SITE_CONTENT_TEAM_CONTACT_EDIT_ENABLED') && SITE_CONTENT_TEAM_CONTACT_EDIT_ENABLED===true;
    if ($kind==='team_image') return content_command_kind_enabled('team') && defined('SITE_CONTENT_TEAM_IMAGE_EDIT_ENABLED') && SITE_CONTENT_TEAM_IMAGE_EDIT_ENABLED===true;
    if ($kind==='team_order') return PHP_INT_SIZE>=8 && content_command_kind_enabled('team') && defined('SITE_CONTENT_TEAM_ORDER_EDIT_ENABLED') && SITE_CONTENT_TEAM_ORDER_EDIT_ENABLED===true;
    if ($kind==='team_visibility') return content_command_kind_enabled('team') && defined('SITE_CONTENT_TEAM_VISIBILITY_EDIT_ENABLED') && SITE_CONTENT_TEAM_VISIBILITY_EDIT_ENABLED===true;
    if ($kind==='team_profile') return content_command_kind_enabled('team') && defined('SITE_CONTENT_TEAM_PROFILE_EDIT_ENABLED') && SITE_CONTENT_TEAM_PROFILE_EDIT_ENABLED===true;
    return false;
}
function content_command_text($value,int $min,int $max,bool $multiline=false): bool {
    if (!is_string($value) || strlen($value)>$max*4 || preg_match('//u',$value)!==1 || trim($value)!==$value || preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value)) return false;
    $length=preg_match_all('/./us',$value); return $length!==false && $length >= $min && $length <= $max;
}
/** Blank clears a contact; nonblank links are limited to HTTPS LinkedIn domains. */
function content_command_contact_valid($email,$url): bool {
    if (!is_string($email) || !is_string($url) || strlen($email)>200 || strlen($url)>500) return false;
    if ($email!=='' && (trim($email)!==$email || filter_var($email,FILTER_VALIDATE_EMAIL)===false || preg_match('/[^\x21-\x7e]/',$email))) return false;
    if ($url==='') return true;
    if (trim($url)!==$url || preg_match('/[^\x21-\x7e]|\\\\/',$url) || filter_var($url,FILTER_VALIDATE_URL)===false) return false;
    $parts=parse_url($url); if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? ''))!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port']!==443)) return false;
    $host=strtolower((string)($parts['host'] ?? ''));
    return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*linkedin\.com$/D',$host)===1;
}
/** Narrow, relative web-root paths only; no URLs, traversal, SVG or upload occurs here. */
function content_command_image_path_valid(string $kind,$path): bool {
    if (!in_array($kind,['service','team'],true) || !is_string($path) || strlen($path)>500) return false;
    if ($path==='') return true;
    if (str_contains($path,'..') || str_contains($path,'//')) return false;
    $prefix=$kind==='service'?'assets/icons':'(?:assets/team|uploads/team)';
    return preg_match('~^/?'.$prefix.'/(?:[A-Za-z0-9][A-Za-z0-9_-]*/)*[A-Za-z0-9][A-Za-z0-9_-]*\.(?:png|jpe?g|gif|webp)$~iD',$path)===1;
}
function content_command_validate($c,string $source): bool {
    $keys=['type','command_id','source_id','object_id','actor_id','expected_token','issued_at','expires_at','fields'];
    if (!is_array($c) || count($c)!==count($keys) || array_diff(array_keys($c),$keys) || !preg_match('/^[a-f0-9]{32}$/D',$source) || ($c['source_id'] ?? null)!==$source
      || !is_string($c['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$c['command_id']) || !is_string($c['expected_token'] ?? null)) return false;
    $create=in_array($c['type'] ?? null,['service_create_v1','team_create_v1'],true);
    if ($create ? (($c['object_id'] ?? null)!==0 || $c['expected_token']!=='') : (!is_int($c['object_id'] ?? null) || $c['object_id']<=0 || !preg_match('/^[a-f0-9]{64}$/D',$c['expected_token']))) return false;
    foreach (['actor_id','issued_at','expires_at'] as $key) if (!is_int($c[$key] ?? null) || $c[$key]<=0) return false;
    if ($c['issued_at']>PHP_INT_MAX-900 || $c['expires_at']!==$c['issued_at']+900 || !is_array($c['fields'] ?? null)) return false;
    $f=$c['fields'];
    if (($c['type'] ?? null)==='service_image_v1') return count($f)===1 && array_key_exists('icon_path',$f) && content_command_image_path_valid('service',$f['icon_path']);
    if (($c['type'] ?? null)==='team_image_v1') return count($f)===1 && array_key_exists('image_path',$f) && content_command_image_path_valid('team',$f['image_path']);
    if (in_array($c['type'] ?? null,['service_order_v1','team_order_v1'],true)) return count($f)===1 && array_key_exists('display_order',$f) && is_int($f['display_order']) && $f['display_order']>=-2147483648 && $f['display_order']<=2147483647;
    if (in_array($c['type'] ?? null,['service_visibility_v1','team_visibility_v1'],true)) return count($f)===1 && array_key_exists('is_active',$f) && is_int($f['is_active']) && in_array($f['is_active'],[0,1],true);
    if (count($f)!==2) return false;
    if (in_array($c['type'] ?? null,['service_text_v1','service_create_v1'],true)) return !array_diff(array_keys($f),['title_fa','description_fa']) && content_command_text($f['title_fa'] ?? null,2,200) && content_command_text($f['description_fa'] ?? null,1,1000,true);
    if (($c['type'] ?? null)==='service_english_text_v1') return !array_diff(array_keys($f),['title_en','description_en']) && content_command_text($f['title_en'] ?? null,0,200) && content_command_text($f['description_en'] ?? null,0,1000,true);
    if (($c['type'] ?? null)==='team_contact_v1') return !array_diff(array_keys($f),['email','linkedin_url']) && content_command_contact_valid($f['email'] ?? null,$f['linkedin_url'] ?? null);
    if (($c['type'] ?? null)==='team_profile_v1') return !array_diff(array_keys($f),['handle','bio']) && content_command_text($f['handle'] ?? null,0,100) && content_command_text($f['bio'] ?? null,0,1000,true);
    if (in_array($c['type'] ?? null,['team_text_v1','team_create_v1'],true)) return !array_diff(array_keys($f),['name','role']) && content_command_text($f['name'] ?? null,2,100) && content_command_text($f['role'] ?? null,2,200);
    return false;
}
function content_command_mode(array $c): string {
    if (($c['type'] ?? '')==='service_image_v1') return 'service_image';
    if (($c['type'] ?? '')==='team_image_v1') return 'team_image';
    if (($c['type'] ?? '')==='service_order_v1') return 'service_order';
    if (($c['type'] ?? '')==='team_order_v1') return 'team_order';
    if (($c['type'] ?? '')==='service_create_v1') return 'service_create';
    if (($c['type'] ?? '')==='team_create_v1') return 'team_create';
    if (($c['type'] ?? '')==='service_visibility_v1') return 'service_visibility';
    if (($c['type'] ?? '')==='team_visibility_v1') return 'team_visibility';
    if (($c['type'] ?? '')==='service_text_v1') return 'service';
    if (($c['type'] ?? '')==='service_english_text_v1') return 'service_en';
    if (($c['type'] ?? '')==='team_text_v1') return 'team';
    if (($c['type'] ?? '')==='team_contact_v1') return 'team_contact';
    if (($c['type'] ?? '')==='team_profile_v1') return 'team_profile';
    return '';
}
function content_command_kind(array $c): string { $mode=content_command_mode($c); return in_array($mode,['service_en','service_visibility','service_create','service_order','service_image'],true)?'service':(in_array($mode,['team_profile','team_visibility','team_create','team_order','team_contact','team_image'],true)?'team':$mode); }
function content_command_type(string $mode): string { if ($mode==='service_image') return 'service_image_v1'; if ($mode==='team_image') return 'team_image_v1'; if ($mode==='team_contact') return 'team_contact_v1'; if ($mode==='service_order') return 'service_order_v1'; if ($mode==='team_order') return 'team_order_v1'; if ($mode==='service_create') return 'service_create_v1'; if ($mode==='team_create') return 'team_create_v1'; if ($mode==='service_visibility') return 'service_visibility_v1'; if ($mode==='team_visibility') return 'team_visibility_v1'; return $mode==='service'?'service_text_v1':($mode==='service_en'?'service_english_text_v1':($mode==='team'?'team_text_v1':($mode==='team_profile'?'team_profile_v1':''))); }
function content_command_signature_valid(string $key,string $ts,string $sig,string $body,int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300 && preg_match('/^[a-f0-9]{64}$/D',$sig)===1 && hash_equals(hash_hmac('sha256','site-content-command-v1.'.$ts.'.'.$body,$key),$sig);
}
