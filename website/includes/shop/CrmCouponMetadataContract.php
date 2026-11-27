<?php
/** Pure allowlisted coupon metadata. Coupon code and monetary terms are deliberately excluded. */
declare(strict_types=1);
function shop_coupon_metadata_uint($value,bool $zero=false): int {
    if (!is_int($value) && !is_string($value)) throw new DomainException('Invalid coupon metadata');
    $s=(string)$value; $max=(string)PHP_INT_MAX;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D',$s) || strlen($s)>strlen($max) || (strlen($s)===strlen($max) && strcmp($s,$max)>0) || (!$zero && $s==='0')) throw new DomainException('Invalid coupon metadata');
    return (int)$s;
}
function shop_coupon_metadata_snapshot(array $r): array {
    $id=shop_coupon_metadata_uint($r['id'] ?? null); $active=shop_coupon_metadata_uint($r['is_active'] ?? null,true); $type=$r['type'] ?? null;
    if (!is_string($type) || !in_array($type,['percent','fixed'],true) || !in_array($active,[0,1],true)) throw new DomainException('Invalid coupon metadata');
    return ['id'=>$id,'type'=>$type,'is_active'=>$active];
}
function shop_coupon_metadata_event(string $source,string $event,int $revision,array $row): array {
    if (!preg_match('/^[a-f0-9]{32}$/D',$source) || !preg_match('/^[a-f0-9]{32}$/D',$event) || $revision<=0) throw new DomainException('Invalid coupon metadata event');
    return ['type'=>'shop_coupon_meta_v1','source_id'=>$source,'event_id'=>$event,'revision'=>$revision,'coupon'=>shop_coupon_metadata_snapshot($row)];
}
