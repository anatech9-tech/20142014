<?php
/** Opt-in CRM service/team text and independently opt-in visibility, display order, team contact, and existing image path updates. Creation is inactive and opt-in. No deletion, file upload or account changes. */
require_once __DIR__.'/ContentCommandContract.php';
require_once __DIR__.'/../ContentEditGuard.php';
function content_receiver_schema(): string {
    return "CREATE TABLE IF NOT EXISTS site_content_command_receipts(command_id CHAR(32) NOT NULL PRIMARY KEY,request_hash CHAR(64) NOT NULL,source_id CHAR(32) NOT NULL,kind VARCHAR(12) NOT NULL,object_id BIGINT UNSIGNED NOT NULL,actor_id BIGINT UNSIGNED NOT NULL,result VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL,completed_at DATETIME NULL,KEY idx_content_receipt(kind,object_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function content_receiver_ensure(PDO $pdo): void { try { $pdo->query('SELECT 1 FROM site_content_command_receipts LIMIT 1'); } catch (Throwable $e) { $pdo->exec(content_receiver_schema()); } }
function content_receiver_require_innodb(PDO $pdo,string $kind): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ([content_edit_table($kind),'site_content_command_receipts'] as $t) { $st->execute([$t]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('InnoDB required'); }
}
/** Return a created ID only from the applied receipt for exactly this authenticated body. */
function content_receiver_created_id(PDO $pdo,string $commandId,string $hash): int {
    if (!preg_match('/^[a-f0-9]{32}$/D',$commandId) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new InvalidArgumentException('Invalid receipt');
    $st=$pdo->prepare('SELECT object_id FROM site_content_command_receipts WHERE command_id=? AND request_hash=? AND result=?'); $st->execute([$commandId,$hash,'applied']);
    $id=$st->fetchColumn();
    if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id) || (int)$id>4294967295) throw new RuntimeException('Receipt unavailable');
    return (int)$id;
}
/** Best-effort check at command application time. Does not make the file immutable or safe. */
function content_receiver_image_available(string $kind,string $path): bool {
    if (!content_command_image_path_valid($kind,$path)) return false;
    if ($path==='') return true;
    if (!function_exists('getimagesize')) return false;
    $root=realpath(dirname(__DIR__,2)); if ($root===false) return false;
    $relative=ltrim($path,'/'); $candidate=$root;
    clearstatcache(true);
    foreach (explode('/',$relative) as $part) { $candidate.='/'.$part; if (is_link($candidate)) return false; }
    $real=realpath($candidate);
    if ($real===false || !str_starts_with($real,$root.'/') || !is_file($real) || !is_readable($real)) return false;
    $size=@filesize($real); if ($size===false || $size<=0 || $size>5*1024*1024) return false;
    $info=@getimagesize($real); if (!is_array($info) || ($info[0] ?? 0)<=0 || ($info[1] ?? 0)<=0 || $info[0]>4096 || $info[1]>4096) return false;
    $ext=strtolower(pathinfo($real,PATHINFO_EXTENSION));
    $expected=['png'=>IMAGETYPE_PNG,'jpg'=>IMAGETYPE_JPEG,'jpeg'=>IMAGETYPE_JPEG,'gif'=>IMAGETYPE_GIF,'webp'=>IMAGETYPE_WEBP];
    return isset($expected[$ext]) && ($info[2] ?? null)===$expected[$ext];
}
/** Receiver checks authenticated source/config and InnoDB before calling. Same receipt+write transaction. */
function content_receiver_apply(PDO $pdo,array $c,string $source,string $hash,int $now): string {
    if (!content_command_validate($c,$source) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new InvalidArgumentException('Invalid command');
    $kind=content_command_kind($c); if (!content_command_kind_enabled(content_command_mode($c))) throw new RuntimeException('Action disabled');
    $table=content_edit_table($kind); $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO site_content_command_receipts(command_id,request_hash,source_id,kind,object_id,actor_id,result,created_at) VALUES (?,?,?,?,?,?,'processing',?) ON DUPLICATE KEY UPDATE command_id=command_id");
        $st->execute([$c['command_id'],$hash,$source,$kind,$c['object_id'],$c['actor_id'],date('Y-m-d H:i:s',$now)]);
        $st=$pdo->prepare('SELECT request_hash,result,object_id FROM site_content_command_receipts WHERE command_id=? FOR UPDATE'); $st->execute([$c['command_id']]); $old=$st->fetch(PDO::FETCH_ASSOC);
        if (!$old || !hash_equals((string)$old['request_hash'],$hash)) throw new DomainException('Receipt conflict');
        if ($old['result']!=='processing') { if (in_array(content_command_mode($c),['service_create','team_create'],true) && $old['result']==='applied' && ((int)$old['object_id']<=0 || (int)$old['object_id']>4294967295)) throw new RuntimeException('Receipt invalid'); $pdo->commit(); return (string)$old['result']; }
        $createdId=0; $mode=content_command_mode($c);
        if ($c['issued_at']>$now+300 || $now>$c['expires_at']) $result='expired';
        elseif (in_array($mode,['service_create','team_create'],true)) {
            $f=$c['fields'];
            if ($mode==='service_create') {
                $st=$pdo->prepare('INSERT INTO services (title_fa,description_fa,title_en,description_en,icon_path,display_order,is_active) VALUES (?,?,NULL,NULL,NULL,0,0)');
                $st->execute([$f['title_fa'],$f['description_fa']]);
            } else {
                $st=$pdo->prepare('INSERT INTO team_members (name,role,handle,bio,image_path,email,linkedin_url,display_order,is_active) VALUES (?,?,NULL,NULL,NULL,NULL,NULL,0,0)');
                $st->execute([$f['name'],$f['role']]);
            }
            $createdId=(int)$pdo->lastInsertId();
            if ($createdId<=0 || $createdId>4294967295) throw new RuntimeException('Invalid created ID');
            $st=$pdo->prepare('SELECT '.implode(',',content_edit_fields($kind)).' FROM '.$table.' WHERE id=? FOR UPDATE'); $st->execute([$createdId]); $new=$st->fetch(PDO::FETCH_ASSOC);
            $nulls=$kind==='service'?['title_en','description_en','icon_path']:['handle','bio','image_path','email','linkedin_url'];
            if (!$new || (string)$new['is_active']!=='0' || (string)$new['display_order']!=='0') throw new RuntimeException('Unexpected created content');
            foreach ($nulls as $field) if (!array_key_exists($field,$new) || $new[$field]!==null) throw new RuntimeException('Unexpected created content');
            foreach ($f as $field=>$value) if (($new[$field] ?? null)!==$value) throw new RuntimeException('Unexpected created content');
            $result='applied';
        } else {
            $result='conflict'; $st=$pdo->prepare('SELECT '.implode(',',content_edit_fields($kind)).' FROM '.$table.' WHERE id=? FOR UPDATE'); $st->execute([$c['object_id']]); $row=$st->fetch(PDO::FETCH_ASSOC);
            if ($row && hash_equals(content_edit_token($kind,$row),$c['expected_token'])) {
                $mode=content_command_mode($c); $f=$c['fields'];
                $imagePath=$mode==='service_image'?($f['icon_path'] ?? null):($mode==='team_image'?($f['image_path'] ?? null):null);
                // An unavailable image leaves the default durable conflict; never write it unchecked.
                if ($imagePath===null || content_receiver_image_available($kind,$imagePath)) {
                    if ($mode==='service_image') { $st=$pdo->prepare('UPDATE services SET icon_path=? WHERE id=?'); $args=[$f['icon_path'],$c['object_id']]; }
                    elseif ($mode==='team_image') { $st=$pdo->prepare('UPDATE team_members SET image_path=? WHERE id=?'); $args=[$f['image_path'],$c['object_id']]; }
                    elseif ($mode==='service_order' || $mode==='team_order') { $st=$pdo->prepare('UPDATE '.$table.' SET display_order=? WHERE id=?'); $args=[$f['display_order'],$c['object_id']]; }
                    elseif ($mode==='service_visibility' || $mode==='team_visibility') { $st=$pdo->prepare('UPDATE '.$table.' SET is_active=? WHERE id=?'); $args=[$f['is_active'],$c['object_id']]; }
                    elseif ($mode==='service') { $st=$pdo->prepare('UPDATE services SET title_fa=?,description_fa=? WHERE id=?'); $args=[$f['title_fa'],$f['description_fa'],$c['object_id']]; }
                    elseif ($mode==='service_en') { $st=$pdo->prepare('UPDATE services SET title_en=?,description_en=? WHERE id=?'); $args=[$f['title_en'],$f['description_en'],$c['object_id']]; }
                    elseif ($mode==='team_contact') { $st=$pdo->prepare('UPDATE team_members SET email=?,linkedin_url=? WHERE id=?'); $args=[$f['email'],$f['linkedin_url'],$c['object_id']]; }
                    elseif ($mode==='team_profile') { $st=$pdo->prepare('UPDATE team_members SET handle=?,bio=? WHERE id=?'); $args=[$f['handle'],$f['bio'],$c['object_id']]; }
                    else { $st=$pdo->prepare('UPDATE team_members SET name=?,role=? WHERE id=?'); $args=[$f['name'],$f['role'],$c['object_id']]; }
                    $st->execute($args);
                    $result='applied';
                }
            }
        }
        if ($createdId>0) { $st=$pdo->prepare("UPDATE site_content_command_receipts SET result=?,completed_at=?,object_id=? WHERE command_id=? AND result='processing'"); $st->execute([$result,date('Y-m-d H:i:s',$now),$createdId,$c['command_id']]); }
        else { $st=$pdo->prepare("UPDATE site_content_command_receipts SET result=?,completed_at=? WHERE command_id=? AND result='processing'"); $st->execute([$result,date('Y-m-d H:i:s',$now),$c['command_id']]); }
        if ($st->rowCount()!==1) throw new RuntimeException('Receipt update failed');
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
