<?php
/** File fixtures only; not real PHP/MySQL/host permission or backup restore tests. */
require_once __DIR__.'/../includes/operations_hub.php'; require_once __DIR__.'/../includes/backup_web.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$dir=sys_get_temp_dir().'/backup_test_'.bin2hex(random_bytes(5)); mkdir($dir,0700); $dir.='/';
try {
    $prior=umask(0); $f=operations_backup_open_exclusive($dir.'daily.sql'); fwrite($f,'SYNTHETIC DATA'); fclose($f); umask($prior);
    clearstatcache(true,$dir.'daily.sql'); $permissions=fileperms($dir.'daily.sql');
    check(($permissions & 0777)===0600,'backup file created private even under permissive umask');
    try { operations_backup_open_exclusive($dir.'daily.sql'); check(false,'exclusive create'); } catch (RuntimeException $e) { check(file_get_contents($dir.'daily.sql')==='SYNTHETIC DATA','existing file never overwritten'); }
    operations_backup_copy_atomic($dir.'daily.sql',$dir.'weekly.sql',$dir);
    clearstatcache(true,$dir.'weekly.sql'); check(file_get_contents($dir.'weekly.sql')==='SYNTHETIC DATA' && (fileperms($dir.'weekly.sql') & 0777)===0600,'atomic periodic copy private and complete');
    $fp=backup_web_open($dir,'weekly.sql'); check(is_resource($fp) && stream_get_contents($fp)==='SYNTHETIC DATA','regular SQL file can be opened'); fclose($fp);
    foreach (['../daily.sql','daily.sql/../weekly.sql','.htaccess','weekly.php',"bad\x00.sql",str_repeat('a',129).'.sql'] as $name) check(backup_web_open($dir,$name)===false,'invalid filename refused');
    if (function_exists('symlink') && @symlink($dir.'daily.sql',$dir.'link.sql')) {
        check(backup_web_open($dir,'link.sql')===false,'symlink download refused');
        try { operations_backup_copy_atomic($dir.'link.sql',$dir.'bad.sql',$dir); check(false,'symlink copy'); } catch (RuntimeException $e) { check(!file_exists($dir.'bad.sql'),'symlink backup source refused'); }
        unlink($dir.'link.sql');
    } else echo "SKIP symlink support unavailable in fixture\n";
    try { operations_backup_copy_atomic($dir.'absent.sql',$dir.'bad.sql',$dir); check(false,'missing source'); } catch (RuntimeException $e) { check(!file_exists($dir.'bad.sql'),'missing source does not publish copy'); }
    check(count(glob($dir.'.backup_copy_*.tmp') ?: [])===0,'no temporary copy remains');
} finally { foreach (glob($dir.'*') ?: [] as $f) @unlink($f); foreach (glob($dir.'.backup_copy_*.tmp') ?: [] as $f) @unlink($f); @rmdir($dir); }
