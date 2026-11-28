<?php
/** Filesystem fixture only. Existing daily files are not proof of a valid snapshot. */
function t($s) { return $s; } function gregorian_to_jalali($y,$m,$d) { return [1405,1,2]; }
$dir=sys_get_temp_dir().'/backup_auto_'.bin2hex(random_bytes(5)); mkdir($dir,0700); define('BACKUP_DIR',$dir.'/');
require_once __DIR__.'/../includes/operations_hub.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$daily=BACKUP_DIR.'auto_daily_'.date('Y-m-d').'.sql';
try {
    file_put_contents($daily,''); $r=operations_auto_backups($p); check(!$r['ok'],'empty daily does not count as success'); unlink($daily);
    file_put_contents(BACKUP_DIR.'other.sql','EXAMPLE');
    if (function_exists('symlink') && @symlink(BACKUP_DIR.'other.sql',$daily)) { $r=operations_auto_backups($p); check(!$r['ok'],'symlink daily does not count as success'); unlink($daily); }
    else echo "SKIP symlink support unavailable\n";
    file_put_contents($daily,'EXAMPLE'); $r=operations_auto_backups($p); check($r['ok'] && $r['made']!==['daily'],'existing nonempty daily accepted without claiming valid restore');
} finally { foreach (glob(BACKUP_DIR.'*') ?: [] as $f) @unlink($f); @unlink(BACKUP_DIR.'.backup.lock'); @rmdir($dir); }
