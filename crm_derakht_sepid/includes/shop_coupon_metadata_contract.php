<?php
/** Strict shape for minimal coupon metadata or a separate optional absence marker. */
declare(strict_types=1);
function crm_shop_coupon_metadata_validate($data,string $source): bool {
    if (!preg_match('/^[a-f0-9]{32}$/D',$source) || !is_array($data) || count($data)!==5
        || array_diff(array_keys($data),['type','source_id','event_id','revision','coupon'])
        || !in_array($data['type'] ?? null,['shop_coupon_meta_v1','shop_coupon_meta_deleted_v1'],true) || ($data['source_id'] ?? null)!==$source
        || !is_string($data['event_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$data['event_id']) || !is_int($data['revision'] ?? null) || $data['revision']<=0) return false;
    $r=$data['coupon'] ?? null;
    if (($data['type'] ?? null)==='shop_coupon_meta_deleted_v1') return is_array($r) && count($r)===2 && !array_diff(array_keys($r),['id','deleted']) && is_int($r['id'] ?? null) && $r['id']>0 && ($r['deleted'] ?? null)===true;
    return is_array($r) && count($r)===3 && !array_diff(array_keys($r),['id','type','is_active']) && is_int($r['id'] ?? null) && $r['id']>0
        && is_string($r['type'] ?? null) && in_array($r['type'],['percent','fixed'],true) && is_int($r['is_active'] ?? null) && in_array($r['is_active'],[0,1],true);
}
