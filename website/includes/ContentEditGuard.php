<?php
declare(strict_types=1);
/** Optimistic edit guard for the legacy services/team panels. Not an authentication token. */
function content_edit_fields(string $kind): array {
    if ($kind==='service') return ['id','icon_path','title_fa','title_en','description_fa','description_en','display_order','is_active','created_at','updated_at'];
    if ($kind==='team') return ['id','name','role','handle','bio','image_path','email','linkedin_url','display_order','is_active','created_at','updated_at'];
    throw new InvalidArgumentException('Unknown content type');
}
function content_edit_table(string $kind): string {
    if ($kind==='service') return 'services';
    if ($kind==='team') return 'team_members';
    throw new InvalidArgumentException('Unknown content type');
}
function content_edit_token(string $kind,array $row): string {
    $values=[];
    foreach (content_edit_fields($kind) as $field) {
        if (!array_key_exists($field,$row) || (!is_scalar($row[$field]) && $row[$field]!==null)) throw new RuntimeException('Content snapshot unavailable');
        $values[$field]=$row[$field]===null ? null : (string)$row[$field];
    }
    $json=json_encode([$kind,$values],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json===false) throw new RuntimeException('Content snapshot unavailable');
    return hash('sha256',$json);
}
/** Must use a MySQL/InnoDB connection and a transaction-aware audit callback on the same connection. */
function content_edit_existing(PDO $pdo,string $kind,int $id,$expected,string $action,array $data,callable $audit): bool {
    $table=content_edit_table($kind);
    if ($id<=0 || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected) || !in_array($action,['save','delete'],true)) return false;
    $allowed=array_values(array_diff(content_edit_fields($kind),['id','created_at','updated_at']));
    if ($action==='save' && (count($data)!==count($allowed) || array_diff(array_keys($data),$allowed))) throw new InvalidArgumentException('Invalid content fields');
    if ($action==='delete' && $data) throw new InvalidArgumentException('Invalid delete fields');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $check=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ([$table,'admin_activity_log'] as $name) { $check->execute([$name]); if (strtoupper((string)$check->fetchColumn())!=='INNODB') throw new RuntimeException('InnoDB required'); }
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT '.implode(',',content_edit_fields($kind)).' FROM '.$table.' WHERE id=? FOR UPDATE'); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals(content_edit_token($kind,$row),$expected)) { $pdo->rollBack(); return false; }
        if ($action==='delete') { $st=$pdo->prepare('DELETE FROM '.$table.' WHERE id=?'); $st->execute([$id]); if ($st->rowCount()!==1) throw new RuntimeException('Delete failed'); }
        else {
            $fields=array_keys($data); $sql='UPDATE '.$table.' SET '.implode(',',array_map(static fn($f)=>$f.'=?',$fields)).' WHERE id=?';
            $st=$pdo->prepare($sql); $st->execute([...array_values($data),$id]);
        }
        $audit(); $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
