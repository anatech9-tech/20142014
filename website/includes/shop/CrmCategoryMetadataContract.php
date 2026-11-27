<?php
/** Read-only public category label and state; no products or management commands. */
declare(strict_types=1);
function shop_category_metadata_uint($value,bool $zero=false): int {
    if (!is_int($value) && !is_string($value)) throw new DomainException('Invalid category metadata');
    $s=(string)$value; $max=(string)PHP_INT_MAX;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D',$s) || strlen($s)>strlen($max) || (strlen($s)===strlen($max) && strcmp($s,$max)>0) || (!$zero && $s==='0')) throw new DomainException('Invalid category metadata');
    return (int)$s;
}
function shop_category_metadata_name($value): bool {
    return is_string($value) && strlen($value)<=400 && preg_match('//u',$value)===1 && preg_match('/[\x00-\x1f\x7f]/',$value)!==1 && preg_match_all('/./us',$value)<=100;
}
function shop_category_metadata_snapshot(array $r): array {
    $id=shop_category_metadata_uint($r['id'] ?? null); $active=shop_category_metadata_uint($r['is_active'] ?? null,true); $name=$r['name_fa'] ?? null;
    if (!shop_category_metadata_name($name) || !in_array($active,[0,1],true)) throw new DomainException('Invalid category metadata');
    return ['id'=>$id,'name_fa'=>$name,'is_active'=>$active];
}
function shop_category_metadata_event(string $source,string $event,int $revision,array $row): array {
    if (!preg_match('/^[a-f0-9]{32}$/D',$source) || !preg_match('/^[a-f0-9]{32}$/D',$event) || $revision<=0) throw new DomainException('Invalid category metadata event');
    return ['type'=>'shop_category_meta_v1','source_id'=>$source,'event_id'=>$event,'revision'=>$revision,'category'=>shop_category_metadata_snapshot($row)];
}
