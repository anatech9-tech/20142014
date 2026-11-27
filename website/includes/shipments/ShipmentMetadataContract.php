<?php
/** Pure projection for a future independent-shipment bridge; no I/O or activation. */
declare(strict_types=1);

/** PDO commonly returns integers as strings. Reject malformed, signed and overflowed IDs. */
function site_shipment_metadata_uint($value, bool $zero = false): int {
    if (!is_int($value) && !is_string($value)) throw new DomainException('Invalid shipment metadata');
    $value=(string)$value; $max=(string)PHP_INT_MAX;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D',$value) || strlen($value)>strlen($max)
        || (strlen($value)===strlen($max) && strcmp($value,$max)>0) || (!$zero && $value==='0')) {
        throw new DomainException('Invalid shipment metadata');
    }
    return (int)$value;
}

/** Only these four fields are selected for a prospective transfer. This is not an anonymization guarantee. */
function site_shipment_metadata_snapshot(array $row): array {
    $id=site_shipment_metadata_uint($row['id'] ?? null);
    $public=site_shipment_metadata_uint($row['is_public'] ?? null,true);
    $service=$row['service_type'] ?? null; $status=$row['status'] ?? null;
    if (!is_string($service) || !in_array($service,['air','sea','land','multimodal'],true)
        || !is_string($status) || !in_array($status,['registered','picked_up','in_transit','customs_clearance','out_for_delivery','delivered','cancelled','on_hold'],true)
        || !in_array($public,[0,1],true)) throw new DomainException('Invalid shipment metadata');
    return ['id'=>$id,'service_type'=>$service,'status'=>$status,'is_public'=>$public];
}

/** Caller must supply a durable monotonic revision and unique event ID; no network is performed here. */
function site_shipment_metadata_event(string $source, string $event, int $revision, array $row): array {
    if (!preg_match('/^[a-f0-9]{32}$/D',$source) || !preg_match('/^[a-f0-9]{32}$/D',$event) || $revision<=0) throw new DomainException('Invalid shipment metadata event');
    return ['type'=>'site_shipment_meta_v1','source_id'=>$source,'event_id'=>$event,'revision'=>$revision,'shipment'=>site_shipment_metadata_snapshot($row)];
}
