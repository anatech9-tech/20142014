#!/usr/bin/env python3
"""Page filtering, keyset browsing and event display on SQLite, not a real browser/HTTP."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    app=pathlib.Path(temp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_content_commands.php',app/'pages/site_content_commands.php')
    for name in ('site_content_commands.php','site_content_command_contract.php','site_content_lookup.php','site_content_lookup_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>');(app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';} function is_nazer_kol(){return $GLOBALS['case']==='readonly';}
function access_is_special_account(){return true;} function access_mode($x){return 'editor';} function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function redirect($s){exit;}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_SERVER['REQUEST_METHOD']='POST';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin')");
$pdo->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
if ($case!=='missing_history') $pdo->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$source=str_repeat('a',32);$now=time();$stamp='2026-01-01 12:00:00';
for($i=1;$i<=65;$i++){
 $id=str_pad(dechex($i),32,'0',STR_PAD_LEFT); $c=['type'=>'service_text_v1','command_id'=>$id,'source_id'=>$source,'object_id'=>$i,'actor_id'=>1,'expected_token'=>str_repeat('d',64),'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>['title_fa'=>'<script>Secret</script>','description_fa'=>'Body']];$body=json_encode($c);
 $hash=$case==='tampered'&&$i===1?str_repeat('f',64):hash('sha256',$body);
 $pdo->prepare('INSERT INTO crm_site_content_commands VALUES(?,?,?,?,?,?,?,?,?,?,?,NULL)')->execute([$id,$source,'service',$i,1,$body,$hash,$i<=40?'stopped':'queued',0,$stamp,$stamp]);
}
$id=str_pad('1',32,'0',STR_PAD_LEFT);
if ($case!=='missing_history') for($i=1;$i<=52;$i++)$pdo->prepare('INSERT INTO crm_site_content_command_events(command_id,actor_id,action,created_at) VALUES(?,?,?,?)')->execute([$id,1,$i===52?'<script>event</script>':'stopped',$stamp]);
if (in_array($case,['inspect','tampered','missing_history'],true)) $_POST=['action'=>'inspect','command_id'=>$id];
else { $_POST=['action'=>'browse','queue_state'=>in_array($case,['filter','page2'],true)?'stopped':($case==='bad_filter'?"stopped' OR 1=1 --":'all')];
 if ($case==='page2') {$_POST['before_created']=$stamp;$_POST['before_command']=str_pad(dechex(11),32,'0',STR_PAD_LEFT);}
 if ($case==='bad_cursor') {$_POST['before_created']='2026-02-30 12:00:00';$_POST['before_command']=$id;}
}
include __DIR__.'/pages/site_content_commands.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Uncaught','Parse error')),r.stdout+r.stderr
        return r.stdout
    def idof(n): return format(n,'032x')
    page=run('filter'); assert idof(40) in page and idof(11) in page and idof(10) not in page and idof(41) not in page and 'صفحهٔ بعد صف' in page
    page2=run('page2'); assert idof(10) in page2 and idof(1) in page2 and idof(11) not in page2 and 'صفحهٔ بعد صف' not in page2
    detail=run('inspect'); assert '&lt;script&gt;Secret&lt;/script&gt;' in detail and '&lt;script&gt;event&lt;/script&gt;' in detail and '<script>event</script>' not in detail and 'رویدادهای قدیمی‌تر نمایش داده نشده‌اند' in detail and 'value="resolve"' in detail
    tampered=run('tampered'); assert 'ناسازگار' in tampered and 'Secret' not in tampered and 'value="resolve"' in tampered
    assert 'دریافت تاریخچه ممکن نبود' in run('missing_history')
    assert 'فیلتر صف معتبر نیست' in run('bad_filter') and 'صفحهٔ صف معتبر نیست' in run('bad_cursor')
    assert 'دسترسی مجاز نیست' in run('viewer') and 'name="action" value="inspect"' not in run('readonly')
    assert "verify_csrf($_POST['csrf_token'] ?? '')" in (root/'index.php').read_text()
    print('PASS queue browsing: filtered keyset pages, exact ID, bounded escaped history, tampering, access and invalid input')
