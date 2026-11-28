#!/usr/bin/env python3
"""Execute legacy content APIs against SQLite fixture; not MySQL locks or HTTP."""
import json
import os
import pathlib
import shutil
import subprocess
import tempfile

root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    app=pathlib.Path(temp)
    for d in ('admin/api','includes'): (app/d).mkdir(parents=True)
    for name in ('team','services'): shutil.copy(root/f'admin/api/{name}.php',app/f'admin/api/{name}.php')
    shutil.copy(root/'includes/ContentEditGuard.php',app/'includes/ContentEditGuard.php')
    (app/'config.php').write_text('''<?php
define('BASE_PATH',__DIR__); function sts_clean(string $s,int $max=5000): string { return mb_substr(trim($s),0,$max); }
function sts_json(int $status,array $body): void { echo json_encode(['status'=>$status,'body'=>$body],JSON_UNESCAPED_UNICODE); exit; }
''')
    (app/'includes/db.php').write_text('''<?php
class EngineRow extends PDOStatement { private string $table=''; public function __construct(){} public function execute(?array $p=null): bool{$this->table=$p[0];return true;} public function fetchColumn(int $column=0): mixed {return ($GLOBALS['case']==='engine_content_fail_create' && $this->table!=='admin_activity_log') || ($GLOBALS['case']==='engine_audit_fail_create' && $this->table==='admin_activity_log')?'MyISAM':'InnoDB';} }
class FakeContentPDO extends PDO { public function getAttribute(int $a): mixed {return $a===PDO::ATTR_DRIVER_NAME?'mysql':parent::getAttribute($a);} public function prepare(string $q,array $o=[]): PDOStatement|false {return str_contains($q,'information_schema.TABLES')?new EngineRow():parent::prepare(str_replace(' FOR UPDATE','',$q),$o);} }
class DB { public static PDO $p; public static function pdo():PDO{return self::$p;} public static function insert(string $table,array $data):int { $cols=array_keys($data); self::$p->prepare('INSERT INTO '.$table.'('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($data)); return (int)self::$p->lastInsertId(); } }
''')
    (app/'includes/Auth.php').write_text('''<?php
class Auth { public static function requireLogin(){} public static function requirePermission($permission){if (in_array($GLOBALS['case'],['denied','denied_create'],true)) sts_json(403,['success'=>false]);} public static function verifyCsrf($token){return $token==='ok';} public static function userId(){return 1;} public static function logActivity($a,$b,$c,$d){DB::pdo()->prepare('INSERT INTO admin_activity_log(action,entity_id) VALUES (?,?)')->execute([$b,$d]); if (in_array($GLOBALS['case'],['audit_fail','audit_fail_create'],true)) throw new Exception('sensitive exception');} }
''')
    (app/'run.php').write_text('''<?php
require __DIR__.'/config.php'; require __DIR__.'/includes/db.php'; require __DIR__.'/includes/ContentEditGuard.php';
$kind=$argv[1]; $case=$argv[2]; $table=$kind==='team'?'team_members':'services';
$p=DB::$p=new FakeContentPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$p->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$p->exec('CREATE TABLE admin_activity_log(id INTEGER PRIMARY KEY,action TEXT,entity_id INTEGER)');
$p->exec("INSERT INTO services VALUES(1,'/icon','First','Eng','body','body2',0,1,'2026','2026')");
$p->exec("INSERT INTO team_members VALUES(1,'Name','Role','handle','bio','/assets/old.png','private@example.test','/link',0,1,'2026','2026')");
$token=content_edit_token($kind==='team'?'team':'service',$p->query('SELECT * FROM '.$table.' WHERE id=1')->fetch(PDO::FETCH_ASSOC));
if (in_array($case,['stale','stale_delete'],true)) $p->exec("UPDATE $table SET display_order=9 WHERE id=1");
if ($case==='insert_fail_create') $p->exec("CREATE TRIGGER insert_failure BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, 'fixture'); END");
$_POST=['action'=>str_contains($case,'delete')?'delete':'save','id'=>str_contains($case,'create')?'0':'1','csrf_token'=>in_array($case,['csrf','csrf_create'],true)?'bad':'ok','expected_token'=>$case==='missing'?'':$token,'title_fa'=>'New title','title_en'=>'English','description_fa'=>'body','description_en'=>'body','icon_path'=>'/icon','name'=>'New name','role'=>'New role','handle'=>'h','bio'=>'b','email'=>'x@example.test','linkedin_url'=>'/link','image_path'=>$case==='bad_path'?'//bad.test/x':'/uploads/team/new.webp','display_order'=>'1','is_active'=>'1'];
if ($case==='missing_image') unset($_POST['image_path']);
register_shutdown_function(function() use ($p,$table,$kind) { $row=$p->query('SELECT * FROM '.$table.' WHERE id=1')->fetch(PDO::FETCH_ASSOC); echo '|STATE:'.json_encode(['rows'=>(int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),'name'=>$row ? $row[$kind==='team'?'name':'title_fa'] : null,'image'=>$row && $kind==='team'?$row['image_path']:null,'order'=>$row?(int)$row['display_order']:null,'audit'=>(int)$p->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn(),'audit_id'=>$p->query('SELECT entity_id FROM admin_activity_log LIMIT 1')->fetchColumn()]); });
require __DIR__.'/admin/api/'.$kind.'.php';
''')
    def run(kind,case):
        r=subprocess.run([php,str(app/'run.php'),kind,case],cwd=app,capture_output=True,text=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Parse error','Uncaught','sensitive exception')),r.stdout+r.stderr
        response,state=r.stdout.split('|STATE:'); return json.loads(response),json.loads(state)
    for kind in ('team','services'):
        response,state=run(kind,'save'); assert response['status']==200 and state['audit']==1 and state['name']==('New name' if kind=='team' else 'New title')
        if kind=='team': assert state['image']=='/uploads/team/new.webp'
        for case in ('stale','stale_delete','missing'):
            response,state=run(kind,case); assert response['status']==409 and state['rows']==1 and state['audit']==0 and state['name']==('Name' if kind=='team' else 'First')
        response,state=run(kind,'delete'); assert response['status']==200 and state['rows']==0 and state['audit']==1
        response,state=run(kind,'audit_fail'); assert response['status']==500 and state['audit']==0 and state['name']==('Name' if kind=='team' else 'First')
        for case in ('denied','csrf'):
            response,state=run(kind,case); assert response['status']==403 and state['audit']==0
        response,state=run(kind,'create'); assert response['status']==200 and response['body']['id']==2 and state['rows']==2 and state['audit']==1 and int(state['audit_id'])==2
        for case in ('audit_fail_create','insert_fail_create','engine_content_fail_create','engine_audit_fail_create'):
            response,state=run(kind,case); assert response['status']==500 and state['rows']==1 and state['audit']==0 and state['name']==('Name' if kind=='team' else 'First')
        for case in ('denied_create','csrf_create'):
            response,state=run(kind,case); assert response['status']==403 and state['rows']==1 and state['audit']==0
    for case in ('bad_path','missing_image'):
        response,state=run('team',case); assert response['status']==400 and state['image']=='/assets/old.png'
    print('PASS service/team API fixtures for stale edits/deletes, authorization, CSRF, audit rollback, atomic create, engine refusal and team image path')
