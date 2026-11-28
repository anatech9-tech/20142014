<?php
/** Read exactly one record; only two selected text fields and a comparison token leave the site. */
require_once __DIR__.'/ContentLookupContract.php'; require_once __DIR__.'/../ContentEditGuard.php';
function content_lookup_read(PDO $pdo,array $request): array {
    if (!content_lookup_request_valid($request,SITE_CONTENT_LOOKUP_SOURCE_ID) || !content_lookup_kind_enabled($request['kind'])) throw new DomainException('Invalid lookup');
    $kind=$request['kind']; $sql='SELECT '.implode(',',content_edit_fields($kind)).' FROM '.content_edit_table($kind).' WHERE id=? LIMIT 1';
    $st=$pdo->prepare($sql); $st->execute([$request['object_id']]); $row=$st->fetch(PDO::FETCH_ASSOC);
    $response=['type'=>'site_content_lookup_result_v1','source_id'=>$request['source_id'],'request_id'=>$request['request_id'],'kind'=>$kind,'object_id'=>$request['object_id'],'found'=>(bool)$row];
    if ($row) {
        $first=$kind==='service'?$row['title_fa']:$row['name']; $second=$kind==='service'?$row['description_fa']:$row['role'];
        if (!content_lookup_text_valid($first) || !content_lookup_text_valid($second)) throw new RuntimeException('Content unavailable');
        $response['token']=content_edit_token($kind,$row); $response['first']=$first; $response['second']=$second;
    }
    return $response;
}
