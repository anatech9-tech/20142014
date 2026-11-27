<?php
/** Strict metadata-only blog messages, including separately opt-in absence marker. */
declare(strict_types=1);
function crm_site_blog_metadata_validate($data,string $source): bool {
    if (!preg_match('/^[a-f0-9]{32}$/D',$source) || !is_array($data) || count($data)!==5
        || array_diff(array_keys($data),['type','source_id','event_id','revision','post'])
        || !in_array($data['type'] ?? null,['site_blog_meta_v1','site_blog_meta_deleted_v1'],true) || ($data['source_id'] ?? null)!==$source
        || !is_string($data['event_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$data['event_id']) || !is_int($data['revision'] ?? null) || $data['revision']<=0) return false;
    $r=$data['post'] ?? null;
    if (($data['type'] ?? null)==='site_blog_meta_deleted_v1') return is_array($r) && count($r)===2 && !array_diff(array_keys($r),['id','deleted']) && is_int($r['id'] ?? null) && $r['id']>0 && ($r['deleted'] ?? null)===true;
    return is_array($r) && count($r)===2 && !array_diff(array_keys($r),['id','is_published']) && is_int($r['id'] ?? null) && $r['id']>0 && is_int($r['is_published'] ?? null) && in_array($r['is_published'],[0,1],true);
}
