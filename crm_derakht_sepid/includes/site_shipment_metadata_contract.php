<?php
/** Pure validator for a future independent-shipment bridge; no receiver or database writes. */
declare(strict_types=1);
function crm_site_shipment_metadata_validate($data,string $source): bool {
    if (!preg_match('/^[a-f0-9]{32}$/D',$source) || !is_array($data) || count($data)!==5
        || array_diff(array_keys($data),['type','source_id','event_id','revision','shipment'])
        || ($data['type'] ?? null)!=='site_shipment_meta_v1' || ($data['source_id'] ?? null)!==$source
        || !is_string($data['event_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$data['event_id'])
        || !is_int($data['revision'] ?? null) || $data['revision']<=0) return false;
    $shipment=$data['shipment'] ?? null;
    return is_array($shipment) && count($shipment)===4 && !array_diff(array_keys($shipment),['id','service_type','status','is_public'])
        && is_int($shipment['id'] ?? null) && $shipment['id']>0
        && is_string($shipment['service_type'] ?? null) && in_array($shipment['service_type'],['air','sea','land','multimodal'],true)
        && is_string($shipment['status'] ?? null) && in_array($shipment['status'],['registered','picked_up','in_transit','customs_clearance','out_for_delivery','delivered','cancelled','on_hold'],true)
        && is_int($shipment['is_public'] ?? null) && in_array($shipment['is_public'],[0,1],true);
}
