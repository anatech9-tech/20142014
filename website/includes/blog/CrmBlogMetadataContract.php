<?php
/** Only identity and published label, no post title, body or personal information. */
declare(strict_types=1);
function site_blog_metadata_uint($value,bool $zero=false): int {
    if (!is_int($value) && !is_string($value)) throw new DomainException('Invalid blog metadata');
    $s=(string)$value; $max=(string)PHP_INT_MAX;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D',$s) || strlen($s)>strlen($max) || (strlen($s)===strlen($max) && strcmp($s,$max)>0) || (!$zero && $s==='0')) throw new DomainException('Invalid blog metadata');
    return (int)$s;
}
function site_blog_metadata_snapshot(array $r): array {
    $id=site_blog_metadata_uint($r['id'] ?? null); $published=site_blog_metadata_uint($r['is_published'] ?? null,true);
    if (!in_array($published,[0,1],true)) throw new DomainException('Invalid blog metadata');
    return ['id'=>$id,'is_published'=>$published];
}
function site_blog_metadata_event(string $source,string $event,int $revision,array $row): array {
    if (!preg_match('/^[a-f0-9]{32}$/D',$source) || !preg_match('/^[a-f0-9]{32}$/D',$event) || $revision<=0) throw new DomainException('Invalid blog metadata event');
    return ['type'=>'site_blog_meta_v1','source_id'=>$source,'event_id'=>$event,'revision'=>$revision,'post'=>site_blog_metadata_snapshot($row)];
}
