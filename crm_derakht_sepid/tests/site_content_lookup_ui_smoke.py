#!/usr/bin/env python3
"""CRM lookup and comparison rendering with simulated signed-lookup result; no HTTP/browser."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for sub in ('pages','includes'): (app/sub).mkdir()
    shutil.copy(root/'pages/site_content_commands.php',app/'pages/site_content_commands.php')
    for f in ('site_content_commands.php','site_content_command_contract.php','site_content_lookup_contract.php'): shutil.copy(root/'includes'/f,app/'includes'/f)
    (app/'includes/header.php').write_text('<header>shell</header>');(app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'includes/site_content_lookup.php').write_text('''<?php
require __DIR__.'/site_content_lookup_contract.php';
function crm_content_lookup_fetch(string $kind,int $id,?callable $transport=null): array {
 $GLOBALS['calls']++; if ($GLOBALS['case']==='http_error') throw new RuntimeException('private remote error');
 return ['type'=>'site_content_lookup_result_v1','source_id'=>str_repeat('a',32),'request_id'=>str_repeat('d',32),'kind'=>$kind,'object_id'=>$id,'found'=>$GLOBALS['case']!=='missing','token'=>str_repeat($GLOBALS['case']==='changed'?'b':'a',64),'first'=>'Old <script>title</script>','second'=>'Old <svg>text</svg>'];
}
function crm_content_lookup_list_fetch(string $kind,int $after,?callable $transport=null): array {
 $GLOBALS['calls']++; return ['kind'=>$kind,'items'=>[['id'=>$after+1,'label'=>'List <script>title</script>','is_active'=>0]],'next_after'=>$after===0?10:null];
}
''')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';} function is_nazer_kol(){return $GLOBALS['case']==='readonly';}
function access_is_special_account(){return true;} function access_mode($x){return 'editor';} function get_csrf_field(){return '<input name="csrf_token" value="token">';}
function showToast($s,$t=''){} function redirect($s){exit;}
$case=$argv[1]; $calls=0;
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_SERVICE_EDIT_ENABLED',true); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/api/command');
define('SITE_CONTENT_LOOKUP_ENABLED',true); define('SITE_CONTENT_SERVICE_LOOKUP_ENABLED',true); define('SITE_CONTENT_SERVICE_LIST_ENABLED',$case!=='disabled_list'); define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32)); define('SITE_CONTENT_LOOKUP_URL',$case==='origin_mismatch'?'https://different.test/api':'https://example.test/api/lookup');
$_SESSION=['user_id'=>1]; $_SERVER['REQUEST_METHOD']='GET'; $pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
$pdo->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$pdo->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$pdo->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
if (in_array($case,['lookup','compare','changed','missing','http_error','origin_mismatch','readonly','viewer','inactive','forged','list','list_next','list_bad','disabled_list','list_readonly'],true)) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['action'=>in_array($case,['list','list_next','list_bad','disabled_list','list_readonly'],true)?'list':(in_array($case,['compare','changed','origin_mismatch'],true)?'preview':'lookup'),'kind'=>'service','object_id'=>'1','after_id'=>$case==='list_next'?'10':($case==='list_bad'?'-1':'0'),'token'=>str_repeat('a',64),'compare'=>'1','first'=>'New <script>title</script>','second'=>'New body'];
 if ($case==='list_readonly') $case='readonly';
}
register_shutdown_function(function() use ($pdo) { echo '|RESULT:'.$GLOBALS['calls'].':'.$pdo->query('SELECT COUNT(*) FROM crm_site_content_commands')->fetchColumn(); });
include __DIR__.'/pages/site_content_commands.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,text=True,capture_output=True,check=True)
        assert not any(s in r.stdout+r.stderr for s in ('Fatal error','Warning:','Parse error','Uncaught','private remote error')),r.stdout+r.stderr
        return r.stdout
    page=run('lookup'); assert '|RESULT:1:0' in page and '&lt;script&gt;title&lt;/script&gt;' in page and '<script>title</script>' not in page and 'name="compare" value="1"' in page and 'name="csrf_token"' in page
    comparison=run('compare'); assert '|RESULT:1:0' in comparison and 'مقایسه دو فیلد' in comparison and 'Old &lt;script&gt;title&lt;/script&gt;' in comparison and 'New &lt;script&gt;title&lt;/script&gt;' in comparison and 'name="action" value="enqueue"' in comparison
    changed=run('changed'); assert '|RESULT:1:0' in changed and 'دوباره دریافت و بررسی کنید' in changed and 'name="action" value="enqueue"' not in changed
    assert '|RESULT:0:0' in run('origin_mismatch') and 'به منبع یکسان متصل نیستند' in run('origin_mismatch')
    assert 'رکورد در سایت یافت نشد' in run('missing') and '|RESULT:1:0' in run('http_error')
    assert '|RESULT:0:0' in run('readonly')
    for case in ('viewer','inactive','forged'): assert '|RESULT:0:0' in run(case) and 'دسترسی مجاز نیست' in run(case)
    page=run('list'); assert '|RESULT:1:0' in page and 'List &lt;script&gt;title&lt;/script&gt;' in page and '<script>title</script>' not in page and 'غیرفعال' in page and 'name="action" value="lookup"' in page and 'name="after_id" value="10"' in page and 'صفحه بعد' in page
    assert '|RESULT:1:0' in run('list_next') and 'صفحه بعد' not in run('list_next')
    for case in ('list_bad','disabled_list','list_readonly'): assert '|RESULT:0:0' in run(case)
    print('PASS CRM lookup/comparison and paginated listing fixtures: escaping, gating, authorization, read-only and no queue')
