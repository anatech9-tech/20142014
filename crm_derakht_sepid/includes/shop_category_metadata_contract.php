<?php
/** Exact shape for delayed category metadata and independent absence markers. */
declare(strict_types=1);
function crm_shop_category_metadata_name($v): bool {
    return is_string($v) && strlen($v)<=400 && preg_match('//u',$v)===1 && preg_match('/[\x00-\x1f\x7f]/',$v)!==1 && preg_match_all('/./us',$v)<=100;
}
function crm_shop_category_metadata_validate($data,string $source): bool {
    if (!preg_match('/^[a-f0-9]{32}$/D',$source) || !is_array($data) || count($data)!==5
        || array_diff(array_keys($data),['type','source_id','event_id','revision','category'])
        || !in_array($data['type'] ?? null,['shop_category_meta_v1','shop_category_meta_deleted_v1'],true) || ($data['source_id'] ?? null)!==$source
        || !is_string($data['event_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$data['event_id']) || !is_int($data['revision'] ?? null) || $data['revision']<=0) return false;
    $r=$data['category'] ?? null;
    if (($data['type'] ?? null)==='shop_category_meta_deleted_v1') return is_array($r) && count($r)===2 && !array_diff(array_keys($r),['id','deleted']) && is_int($r['id'] ?? null) && $r['id']>0 && ($r['deleted'] ?? null)===true;
    return is_array($r) && count($r)===3 && !array_diff(array_keys($r),['id','name_fa','is_active']) && is_int($r['id'] ?? null) && $r['id']>0
        && crm_shop_category_metadata_name($r['name_fa'] ?? null) && is_int($r['is_active'] ?? null) && in_array($r['is_active'],[0,1],true);
}
