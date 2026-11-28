<?php
/** SQLite fixture translates SELECT FOR UPDATE; not a MySQL concurrency or real HTTP test. */
require_once __DIR__.'/../includes/ContentEditGuard.php';
class EngineFixture extends PDOStatement {
    private string $table='';
    public function __construct(private array $engines) {}
    public function execute(?array $params=null): bool { $this->table=(string)$params[0]; return true; }
    public function fetchColumn(int $column=0): mixed { return $this->engines[$this->table] ?? false; }
}
class GuardFixturePDO extends PDO {
    public array $engines=['services'=>'InnoDB','team_members'=>'InnoDB','admin_activity_log'=>'InnoDB'];
    public function getAttribute(int $a): mixed { return $a===PDO::ATTR_DRIVER_NAME?'mysql':parent::getAttribute($a); }
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        if (str_contains($sql,'information_schema.TABLES')) return new EngineFixture($this->engines);
        return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options);
    }
}
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$p=new GuardFixturePDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE services(id INTEGER PRIMARY KEY,icon_path TEXT,title_fa TEXT,title_en TEXT,description_fa TEXT,description_en TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$p->exec('CREATE TABLE team_members(id INTEGER PRIMARY KEY,name TEXT,role TEXT,handle TEXT,bio TEXT,image_path TEXT,email TEXT,linkedin_url TEXT,display_order INTEGER,is_active INTEGER,created_at TEXT,updated_at TEXT)');
$p->exec('CREATE TABLE admin_activity_log(id INTEGER PRIMARY KEY,action TEXT)');
$p->exec("INSERT INTO services VALUES(1,'/icon','First','Eng','body','body2',0,1,'2026-01-01','2026-01-01')");
$p->exec("INSERT INTO team_members VALUES(1,'Name','Role','handle','bio','/assets/old.png','private@example.test','/link',0,1,'2026-01-01','2026-01-01')");
$audit=static function() use ($p) { $p->exec("INSERT INTO admin_activity_log(action) VALUES('updated')"); };
foreach (['service'=>'services','team'=>'team_members'] as $kind=>$table) {
    $row=$p->query('SELECT * FROM '.$table.' WHERE id=1')->fetch(PDO::FETCH_ASSOC); $token=content_edit_token($kind,$row); $data=$row; unset($data['id'],$data['created_at'],$data['updated_at']);
    $field=$kind==='team'?'image_path':'title_fa'; $data[$field]=$kind==='team'?'/uploads/team/new.webp':'New';
    check(content_edit_existing($p,$kind,1,$token,'save',$data,$audit) && $p->query('SELECT '.$field.' FROM '.$table.' WHERE id=1')->fetchColumn()===$data[$field],$kind.' current edit commits');
    check(!content_edit_existing($p,$kind,1,$token,'save',$data,$audit) && !content_edit_existing($p,$kind,1,$token,'delete',[],$audit),$kind.' stale edit and delete refused');
    $fresh=content_edit_token($kind,$p->query('SELECT * FROM '.$table.' WHERE id=1')->fetch(PDO::FETCH_ASSOC));
    check(!content_edit_existing($p,$kind,1,null,'delete',[],$audit) && !content_edit_existing($p,$kind,99,$fresh,'delete',[],$audit),'invalid token and missing row refused');
    $before=(int)$p->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn();
    try { content_edit_existing($p,$kind,1,$fresh,'delete',[],static function() { throw new RuntimeException('injected audit failure'); }); check(false,'audit failure'); } catch (RuntimeException $e) {}
    check((int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===1 && (int)$p->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn()===$before && !$p->inTransaction(),$kind.' audit failure rolls back delete');
    $p->engines['admin_activity_log']='MyISAM';
    try { content_edit_existing($p,$kind,1,$fresh,'delete',[],$audit); check(false,'non InnoDB audit'); } catch (RuntimeException $e) { check(true,$kind.' refuses non InnoDB audit'); }
    $p->engines['admin_activity_log']='InnoDB';
    check(content_edit_existing($p,$kind,1,$fresh,'delete',[],$audit) && (int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,$kind.' current delete commits with audit');
}
try { content_edit_token('other',[]); check(false,'unknown kind'); } catch (InvalidArgumentException $e) { check(true,'unknown type rejected'); }
