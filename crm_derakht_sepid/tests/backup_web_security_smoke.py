#!/usr/bin/env python3
"""Settings early backup paths with synthetic fixtures; no HTTP, live DB or restore."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/settings.php',app/'pages/settings.php')
    for name in ('letterhead.php','backup_web.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'run.php').write_text('''<?php
function t($s){return $s;} function verify_csrf($v){return $v==='token';} function is_strict_admin(){return !in_array($GLOBALS['case'],['viewer','manager'],true);} function is_nazer_kol(){return $GLOBALS['case']==='readonly';} function access_is_special_account(){return false;} function access_mode($x){return $GLOBALS['case']==='access_viewer'?'viewer':'editor';}
function operations_stream_database($pdo,$tmp) { $GLOBALS['backup_calls']++; file_put_contents($tmp,'SYNTHETIC BACKUP'); if ($GLOBALS['case']==='backup_failure') throw new RuntimeException('canary-sensitive'); }
class BackupTestPDO extends PDO { public int $queries=0; public int $executions=0; public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false { $this->queries++; return parent::query($query); } public function exec(string $query): int|false { $this->executions++; return parent::exec($query); } }
$case=$argv[1]; $pdo=new BackupTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']); $pdo->queries=0; $pdo->executions=0;
$dir=sys_get_temp_dir().'/backup_ui_'.bin2hex(random_bytes(5)); mkdir($dir,0700); define('BACKUP_DIR',$dir.'/'); file_put_contents(BACKUP_DIR.'known.sql','SYNTHETIC BACKUP'); file_put_contents(BACKUP_DIR.'.htaccess','SYNTHETIC HIDDEN');
$backup_calls=0; $_SESSION=['user_id'=>1]; $_SERVER['REQUEST_METHOD']='POST'; http_response_code(200);
if ($case==='restore') { $_POST=['action'=>'restore']; $_FILES=['restore_file'=>['error'=>UPLOAD_ERR_OK,'tmp_name'=>'/missing-untrusted.sql']]; }
elseif (in_array($case,['backup','backup_failure'],true)) $_POST=['action'=>'backup'];
else { $_POST=['action'=>'download_backup','filename'=>$case==='traversal'?'../known.sql':($case==='hidden'?'.htaccess':'known.sql')]; }
if ($case!=='restore') $_POST['csrf_token']=$case==='csrf_bad'?'invalid':'token';
include __DIR__.'/pages/settings.php';
$temps=glob(BACKUP_DIR.'.manual_*.tmp') ?: []; echo '|STATUS:'.http_response_code().':Q'.$pdo->queries.':E'.$pdo->executions.':B'.$backup_calls.':T'.count($temps);
foreach (glob(BACKUP_DIR.'*') ?: [] as $f) @unlink($f); @unlink(BACKUP_DIR.'.htaccess'); @rmdir($dir);
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr and 'canary-sensitive' not in r.stdout+r.stderr,r.stdout+r.stderr
        return r.stdout
    restore=run('restore'); assert 'بازیابی از طریق وب غیرفعال است' in restore and '|STATUS:403:Q0:E0:B0:T0' in restore
    for case in ('viewer','manager','inactive','forged','readonly','access_viewer'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '|STATUS:403:Q0:E0:B0:T0' in denied and 'SYNTHETIC BACKUP' not in denied
    assert '|STATUS:419:Q0:E0:B0:T0' in run('csrf_bad')
    assert 'SYNTHETIC BACKUP|STATUS:200:Q0:E0:B0:T0' in run('admin')
    assert 'SYNTHETIC BACKUP|STATUS:200:Q0:E0:B1:T0' in run('backup')
    assert '|STATUS:503:Q0:E0:B1:T0' in run('backup_failure')
    for case in ('traversal','hidden'):
        result=run(case); assert '|STATUS:404:Q0:E0:B0:T0' in result and 'SYNTHETIC' not in result
    index=(root/'index.php').read_text(); assert "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/')
    print('PASS web restore blocked before SQL, active-admin backup authorization, filename, cleanup and early paths')
