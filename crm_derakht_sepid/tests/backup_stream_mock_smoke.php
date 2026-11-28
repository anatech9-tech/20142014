<?php
/** Mock PDO and filesystem fixture: no real PDO MySQL, SQL restore or concurrent writes. */
error_reporting(E_ALL & ~E_DEPRECATED);
function t($s){return $s;} function gregorian_to_jalali($y,$m,$d){return [1405,1,2];}
class BackupMockStatement extends PDOStatement {
    private array $rows; private int $idx=0;
    public function __construct(array $rows) { $this->rows=$rows; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->rows[$this->idx++] ?? false; }
    public function closeCursor(): bool { return true; }
}
class BackupMockPDO extends PDO {
    public bool $transaction=false; public bool $fail=false; public int $rollbacks=0; public int $commits=0;
    public function __construct(){parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
    public function exec(string $sql): int|false { if (str_starts_with($sql,'START TRANSACTION')) $this->transaction=true; return 0; }
    public function query(string $sql,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_starts_with($sql,'SHOW FULL TABLES')) return new BackupMockStatement(['fixture']);
        if (str_starts_with($sql,'SHOW CREATE TABLE')) return new BackupMockStatement([['fixture','CREATE TABLE `fixture` (`name` TEXT)']]);
        if (str_starts_with($sql,'SELECT * FROM')) { if ($this->fail) throw new RuntimeException('injected'); return new BackupMockStatement([['name'=>'safe sample'],['name'=>null]]); }
        throw new RuntimeException('unexpected query');
    }
    public function setAttribute(int $attribute,mixed $value): bool { return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->commits++; $this->transaction=false; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->transaction=false; return true; }
}
$dir=sys_get_temp_dir().'/backup_mock_'.bin2hex(random_bytes(5)); mkdir($dir,0700); define('BACKUP_DIR',$dir.'/');
require_once __DIR__.'/../includes/operations_hub.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$p=new BackupMockPDO(); $daily=BACKUP_DIR.'auto_daily_'.date('Y-m-d').'.sql';
try {
    $p->fail=true; $r=operations_auto_backups($p);
    check(!$r['ok'] && !file_exists($daily) && !(glob(BACKUP_DIR.'.backup_*.tmp') ?: []) && $p->rollbacks===1 && !$p->inTransaction(),'failed snapshot rolls back and does not publish partial backup');
    $p->fail=false; $r=operations_auto_backups($p); $body=file_get_contents($daily); clearstatcache(true,$daily);
    check($r['ok'] && in_array('daily',$r['made'],true) && $p->commits===1 && str_contains($body,'CREATE TABLE `fixture`') && str_contains($body,"'safe sample'") && str_contains($body,'NULL') && (fileperms($daily)&0777)===0600,'successful mock stream publishes private file');
    $r=operations_auto_backups($p); check($r['ok'] && !in_array('daily',$r['made'],true) && $p->commits===1,'existing daily not rewritten');
} finally { foreach (glob(BACKUP_DIR.'*') ?: [] as $f) @unlink($f); foreach (glob(BACKUP_DIR.'.backup_*.tmp') ?: [] as $f) @unlink($f); @unlink(BACKUP_DIR.'.backup.lock'); @rmdir($dir); }
