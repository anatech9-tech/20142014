<?php
/** Read exactly one record; only selected fields and a comparison token leave the site. */
require_once __DIR__.'/ContentLookupContract.php'; require_once __DIR__.'/../ContentEditGuard.php';
function content_lookup_read(PDO $pdo,array $request): array {
    if (!content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID) || !content_lookup_kind_enabled($request['kind'])) throw new DomainException('Invalid lookup');
    $kind=$request['kind']; $rowKind=in_array($kind,['service_en','service_visibility','service_order','service_image','service_delete'],true)?'service':(in_array($kind,['team_profile','team_contact','team_visibility','team_order','team_image','team_delete'],true)?'team':$kind); $sql='SELECT '.implode(',',content_edit_fields($rowKind)).' FROM '.content_edit_table($rowKind).' WHERE id=? LIMIT 1';
    $st=$pdo->prepare($sql); $st->execute([$request['object_id']]); $row=$st->fetch(PDO::FETCH_ASSOC);
    $response=['type'=>'site_content_lookup_result_v1','source_id'=>$request['source_id'],'request_id'=>$request['request_id'],'kind'=>$kind,'object_id'=>$request['object_id'],'found'=>(bool)$row];
    if ($row) {
        if (in_array($kind,['service_image','team_image'],true)) {
            $first=$rowKind==='service'?$row['title_fa']:$row['name']; $second=($rowKind==='service'?$row['icon_path']:$row['image_path']) ?? '';
        } elseif (in_array($kind,['service_order','team_order'],true)) {
            $first=$rowKind==='service'?$row['title_fa']:$row['name']; $order=$row['display_order'];
            if ((!is_int($order) && !is_string($order)) || !content_lookup_order_valid((string)$order)) throw new RuntimeException('Order unavailable');
            $second=(string)$order;
        } elseif (in_array($kind,['service_visibility','team_visibility','service_delete','team_delete'],true)) {
            $first=$rowKind==='service'?$row['title_fa']:$row['name']; $active=$row['is_active'];
            if ((!is_int($active) && !is_string($active)) || !in_array((string)$active,['0','1'],true)) throw new RuntimeException('Visibility unavailable');
            $second=(string)$active;
        } elseif ($kind==='team_contact') {
            $first=$row['email'] ?? ''; $second=$row['linkedin_url'] ?? '';
        } else {
            $first=$kind==='service'?$row['title_fa']:($kind==='service_en'?($row['title_en'] ?? ''):($kind==='team_profile'?($row['handle'] ?? ''):$row['name'])); $second=$kind==='service'?$row['description_fa']:($kind==='service_en'?($row['description_en'] ?? ''):($kind==='team_profile'?($row['bio'] ?? ''):$row['role']));
        }
        if (!content_lookup_text_valid($first) || !content_lookup_text_valid($second)) throw new RuntimeException('Content unavailable');
        $response['token']=content_edit_token($rowKind,$row); $response['first']=$first; $response['second']=$second;
    }
    return $response;
}
/** Read only identity, label and active flag, at most ten records per request. */
function content_lookup_list(PDO $pdo,array $request): array {
    if (!content_lookup_list_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID) || !content_lookup_list_enabled($request['kind'])) throw new DomainException('Invalid list');
    $kind=$request['kind']; $table=content_edit_table($kind); $name=$kind==='service'?'title_fa':'name';
    $st=$pdo->prepare('SELECT id,'.$name.',is_active FROM '.$table.' WHERE id>? ORDER BY id ASC LIMIT 11'); $st->execute([$request['after_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>10; if ($more) array_pop($rows); $items=[];
    foreach ($rows as $row) {
        $id=$row['id']; $active=$row['is_active']; $label=$row[$name];
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id) || !in_array((string)$active,['0','1'],true) || !content_lookup_text_valid($label) || strlen($label)>800) throw new RuntimeException('List unavailable');
        $items[]=['id'=>(int)$id,'label'=>$label,'is_active'=>(int)$active];
    }
    $result=['type'=>'site_content_list_result_v1','source_id'=>$request['source_id'],'request_id'=>$request['request_id'],'kind'=>$kind,'after_id'=>$request['after_id'],'items'=>$items,'next_after'=>$more ? $items[count($items)-1]['id'] : null];
    if (!content_lookup_list_response_valid($result,$request)) throw new RuntimeException('List invalid');
    return $result;
}
