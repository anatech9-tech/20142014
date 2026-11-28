<?php
/** Synthetic SQLite triggers; not a test of MySQL, real triggers or concurrent writes. */
class PostconditionPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace(' FOR UPDATE','',$sql);
        if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) {
            $sql=str_replace('INSERT INTO site_content_command_receipts','INSERT OR IGNORE INTO site_content_command_receipts',$sql);
            $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql);
        }
        return parent::prepare($sql,$options);
    }
}
foreach (['SERVICE_EDIT','SERVICE_EN_EDIT','SERVICE_VISIBILITY_EDIT','SERVICE_ORDER_EDIT','SERVICE_IMAGE_EDIT','TEAM_EDIT','TEAM_PROFILE_EDIT','TEAM_CONTACT_EDIT','TEAM_VISIBILITY_EDIT','TEAM_ORDER_EDIT','TEAM_IMAGE_EDIT'] as $flag) define('SITE_CONTENT_'.$flag.'_ENABLED',true);
require __DIR__.'/../../website/includes/content/ContentCommandReceiver.php';
function ck(bool $ok,string $message): void { if (!$ok) { fwrite(STDERR,"FAIL $message\n"); exit(1); } echo "PASS $message\n"; }
function database(): PostconditionPDO {
    $pdo=new PostconditionPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
    $pdo->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
    $pdo->exec('CREATE TABLE site_content_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
    $pdo->exec("INSERT INTO services VALUES (1,NULL,'عنوان قدیم',NULL,'Body',NULL,0,0,'before','before')");
    $pdo->exec("INSERT INTO team_members VALUES (1,'Old name','Old role',NULL,NULL,NULL,NULL,NULL,0,0,'before','before')");
    return $pdo;
}
function command(PDO $pdo,string $mode,array $fields): array {
    $kind=str_starts_with($mode,'service')?'service':'team'; $table=content_edit_table($kind);
    $row=$pdo->query('SELECT * FROM '.$table.' WHERE id=1')->fetch(PDO::FETCH_ASSOC); $now=time();
    return ['type'=>content_command_type($mode),'command_id'=>str_repeat('a',32),'source_id'=>str_repeat('b',32),'object_id'=>1,'actor_id'=>1,'expected_token'=>content_edit_token($kind,$row),'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$fields];
}
function apply_command(PDO $pdo,array $c): string { return content_receiver_apply($pdo,$c,$c['source_id'],hash('sha256',json_encode($c)), $c['issued_at']); }
$cases=[
    'service'=>['title_fa'=>'عنوان تازه','description_fa'=>"متن\nتازه"],
    'service_en'=>['title_en'=>'English','description_en'=>'Description'],
    'service_visibility'=>['is_active'=>1], 'service_order'=>['display_order'=>-2], 'service_image'=>['icon_path'=>''],
    'team'=>['name'=>'New name','role'=>'New role'], 'team_profile'=>['handle'=>'new','bio'=>'New bio'],
    'team_contact'=>['email'=>'x@example.test','linkedin_url'=>'https://linkedin.com/in/example'],
    'team_visibility'=>['is_active'=>1], 'team_order'=>['display_order'=>3], 'team_image'=>['image_path'=>''],
];
foreach ($cases as $mode=>$fields) {
    $pdo=database(); $c=command($pdo,$mode,$fields); $kind=content_command_kind($c); $table=content_edit_table($kind);
    $before=$pdo->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC);
    ck(apply_command($pdo,$c)==='applied',"$mode accepts expected change");
    $after=$pdo->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC);
    foreach ($fields as $k=>$v) ck((string)$after[$k]===(string)$v,"$mode verifies $k");
    foreach ($fields as $k=>$v) $after[$k]=$before[$k];
    ck($after===$before,"$mode retains other tracked columns");
    ck(apply_command($pdo,$c)==='applied' && (int)$pdo->query('SELECT COUNT(*) FROM site_content_command_receipts')->fetchColumn()===1,"$mode retry uses receipt");
}
foreach (['ignore','changed_target','changed_other','removed','receipt_changes_content'] as $case) {
    $pdo=database(); $c=command($pdo,'service',['title_fa'=>'Fresh title','description_fa'=>'Fresh body']);
    $before=$pdo->query('SELECT * FROM services')->fetch(PDO::FETCH_ASSOC);
    if ($case==='ignore') $pdo->exec('CREATE TRIGGER interference BEFORE UPDATE ON services BEGIN SELECT RAISE(IGNORE); END');
    if ($case==='changed_target') $pdo->exec("CREATE TRIGGER interference AFTER UPDATE ON services BEGIN UPDATE services SET title_fa='rewritten' WHERE id=NEW.id; END");
    if ($case==='changed_other') $pdo->exec("CREATE TRIGGER interference AFTER UPDATE ON services BEGIN UPDATE services SET title_en='rewritten' WHERE id=NEW.id; END");
    if ($case==='removed') $pdo->exec('CREATE TRIGGER interference AFTER UPDATE ON services BEGIN DELETE FROM services WHERE id=NEW.id; END');
    if ($case==='receipt_changes_content') $pdo->exec("CREATE TRIGGER interference AFTER UPDATE ON site_content_command_receipts BEGIN UPDATE services SET title_en='rewritten' WHERE id=1; END");
    try { apply_command($pdo,$c); ck(false,"$case must fail"); } catch (RuntimeException $e) {}
    ck($pdo->query('SELECT * FROM services')->fetch(PDO::FETCH_ASSOC)===$before && (int)$pdo->query('SELECT COUNT(*) FROM site_content_command_receipts')->fetchColumn()===0 && !$pdo->inTransaction(),"$case rolls back row and receipt");
    $pdo->exec('DROP TRIGGER interference'); ck(apply_command($pdo,$c)==='applied',"$case can retry after correction");
}
$pdo=database(); $c=command($pdo,'team',['name'=>'New name','role'=>'New role']);
$pdo->exec("CREATE TRIGGER timestamp_change AFTER UPDATE ON team_members BEGIN UPDATE team_members SET updated_at='after' WHERE id=NEW.id; END");
ck(apply_command($pdo,$c)==='applied' && $pdo->query('SELECT updated_at FROM team_members')->fetchColumn()==='after','automatic updated_at changes are allowed');
$pdo=database(); $c=command($pdo,'team',['name'=>'Old name','role'=>'Old role']);
ck(apply_command($pdo,$c)==='applied','no-op update is accepted without requiring changed-row count');
