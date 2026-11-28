#!/usr/bin/env python3
"""Real CRM page with synthetic contact/SQLite and fake network; no browser or real personal data."""
import os,pathlib,shutil,subprocess,tempfile,base64,json
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    app=pathlib.Path(temp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_content_commands.php',app/'pages/site_content_commands.php')
    for name in ('site_content_commands.php','site_content_command_contract.php','site_content_lookup_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>');(app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'includes/site_content_lookup.php').write_text('''<?php
require __DIR__.'/site_content_lookup_contract.php';
function crm_content_lookup_fetch(string $kind,int $id,?callable $transport=null): array {
 if (!content_lookup_kind_enabled($kind)) throw new DomainException('disabled');
 $GLOBALS['calls']++;if($GLOBALS['case']==='remote_error')throw new RuntimeException('private remote error');
 return ['type'=>'site_content_lookup_result_v1','source_id'=>str_repeat('a',32),'request_id'=>str_repeat('d',32),'kind'=>$kind,'object_id'=>$id,'found'=>$GLOBALS['case']!=='missing','token'=>str_repeat(in_array($GLOBALS['case'],['stale','enqueue_stale'],true)?'b':'a',64),'first'=>'<script>old</script>','second'=>'<svg>old</svg>'];
}
function crm_content_lookup_list_fetch(string $kind,int $after,?callable $transport=null): array {$GLOBALS['calls']++;return ['kind'=>$kind,'items'=>[['id'=>1,'label'=>'Member','is_active'=>0]],'next_after'=>null];}
''')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';} function is_nazer_kol(){return $GLOBALS['case']==='readonly';}
function access_is_special_account(){return true;} function access_mode($x){return 'editor';} function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function redirect($s){exit;}
$case=$argv[1];$calls=0;
define('SITE_CONTENT_COMMAND_ENABLED',true);define('SITE_CONTENT_TEAM_EDIT_ENABLED',true);define('SITE_CONTENT_TEAM_CONTACT_EDIT_ENABLED',$case!=='edit_off');
define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32));define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32));define('SITE_CONTENT_COMMAND_URL','https://example.test/command');
define('SITE_CONTENT_LOOKUP_ENABLED',true);define('SITE_CONTENT_TEAM_LOOKUP_ENABLED',true);define('SITE_CONTENT_TEAM_CONTACT_LOOKUP_ENABLED',$case!=='lookup_off');define('SITE_CONTENT_TEAM_LIST_ENABLED',true);
define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32));define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32));define('SITE_CONTENT_LOOKUP_URL',$case==='wrong_origin'?'https://other.test/lookup':'https://example.test/lookup');
class UIPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { $sql=str_replace(' FOR UPDATE','',$sql);if(str_contains($sql,'ON DUPLICATE KEY UPDATE object_id=object_id')){$sql=str_replace('INSERT INTO crm_site_content_command_targets','INSERT OR IGNORE INTO crm_site_content_command_targets',$sql);$sql=str_replace(' ON DUPLICATE KEY UPDATE object_id=object_id','',$sql);}return parent::prepare($sql,$options);}}
$_SESSION=['user_id'=>1];$_SERVER['REQUEST_METHOD']='POST';$pdo=new UIPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)');$pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
$pdo->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$pdo->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$pdo->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$action=in_array($case,['enqueue','enqueue_stale','no_confirm','one_confirm','clear_enqueue'],true)?'enqueue':(in_array($case,['preview','stale','bad_email','bad_link','wrong_origin','remote_error','edit_off','clear'],true)?'preview':($case==='list'?'list':'lookup'));
$_POST=['action'=>$action,'kind'=>$case==='list'?'team':'team_contact','object_id'=>'1','after_id'=>'0','token'=>str_repeat('a',64),'first'=>$case==='bad_email'?'bad':'person@example.test','second'=>$case==='bad_link'?'https://linkedin.com.evil.test/x':'https://www.linkedin.com/in/example'];
if(in_array($case,['clear','clear_enqueue'],true)){$_POST['first']='';$_POST['second']='';}
if(in_array($case,['enqueue','enqueue_stale','clear_enqueue','one_confirm'],true))$_POST['confirm_identity']='1';
if(in_array($case,['enqueue','enqueue_stale','clear_enqueue'],true))$_POST['confirm_change']='1';
register_shutdown_function(function()use($pdo){$rows=$pdo->query('SELECT payload FROM crm_site_content_commands')->fetchAll(PDO::FETCH_COLUMN);echo '|RESULT:'.$GLOBALS['calls'].':'.count($rows).':'.($rows?base64_encode($rows[0]):'');});
include __DIR__.'/pages/site_content_commands.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,text=True,capture_output=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Uncaught','Parse error','private remote error')),r.stdout+r.stderr
        return r.stdout
    lookup=run('lookup');assert '|RESULT:1:0:' in lookup and '&lt;script&gt;old&lt;/script&gt;' in lookup and '<script>old</script>' not in lookup and 'اطلاعات شخصی' in lookup and 'maxlength="500"' in lookup and 'name="compare" value="1"' in lookup
    preview=run('preview');assert '|RESULT:1:0:' in preview and 'مقایسهٔ اطلاعات تماس عضو' in preview and '&lt;svg&gt;old&lt;/svg&gt;' in preview and 'name="confirm_identity"' in preview and 'name="confirm_change"' in preview
    assert '|RESULT:1:0:' in run('clear') and 'پاک‌کردن' in run('clear')
    for case,expected in [('enqueue',{'email':'person@example.test','linkedin_url':'https://www.linkedin.com/in/example'}),('clear_enqueue',{'email':'','linkedin_url':''})]:
        out=run(case);assert '|RESULT:1:1:' in out
        payload=json.loads(base64.b64decode(out.split('|RESULT:1:1:')[1]));assert payload['type']=='team_contact_v1' and payload['fields']==expected
    for case in ('stale','enqueue_stale','bad_email','bad_link','wrong_origin','lookup_off','edit_off','readonly','viewer','inactive','forged','missing','remote_error','no_confirm','one_confirm'):
        out=run(case);assert ':0:' in out.split('|RESULT:')[1],(case,out)
    assert '|RESULT:0:0:' in run('wrong_origin') and '|RESULT:0:0:' in run('bad_email')
    assert '|RESULT:1:0:' in run('list') and 'دریافت تماس عضو' in run('list')
    # Direct preview/enqueue lacks compare flag, yet still performs fresh lookup.
    assert '|RESULT:1:0:' in preview and '|RESULT:1:1:' in run('enqueue')
    index=(root/'index.php').read_text();assert "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/')
    print('PASS contact UI: access, CSRF route, privacy warning, escaped comparison, mandatory reread, clearing and enqueue')
