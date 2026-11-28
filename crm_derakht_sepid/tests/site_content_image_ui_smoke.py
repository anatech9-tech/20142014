#!/usr/bin/env python3
"""Image path page with SQLite and fake lookups; no browser, MySQL, host or HTTP."""
import os,pathlib,shutil,subprocess,tempfile,base64,json
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    app=pathlib.Path(temp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_content_commands.php',app/'pages/site_content_commands.php')
    for name in ('site_content_commands.php','site_content_command_contract.php','site_content_lookup_contract.php'):shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>');(app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'includes/site_content_lookup.php').write_text('''<?php
require __DIR__.'/site_content_lookup_contract.php';
function crm_content_lookup_fetch(string $kind,int $id,?callable $transport=null): array {
 if(!content_lookup_kind_enabled($kind))throw new DomainException('disabled');$GLOBALS['calls']++;if($GLOBALS['case']==='remote_error')throw new RuntimeException('private remote error');
 return ['type'=>'site_content_lookup_result_v1','source_id'=>str_repeat('a',32),'request_id'=>str_repeat('d',32),'kind'=>$kind,'object_id'=>$id,'found'=>$GLOBALS['case']!=='missing','token'=>str_repeat(in_array($GLOBALS['case'],['stale','enqueue_stale'],true)?'b':'a',64),'first'=>'<script>name</script>','second'=>'<svg>path</svg>'];
}
function crm_content_lookup_list_fetch(string $kind,int $after,?callable $transport=null):array{$GLOBALS['calls']++;return ['kind'=>$kind,'items'=>[['id'=>1,'label'=>'Member','is_active'=>0]],'next_after'=>null];}
''')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';} function is_nazer_kol(){return $GLOBALS['case']==='readonly';}
function access_is_special_account(){return true;} function access_mode($x){return 'editor';} function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function redirect($s){exit;}
$kind=$argv[1];$case=$argv[2];$calls=0;
define('SITE_CONTENT_COMMAND_ENABLED',true);define('SITE_CONTENT_SERVICE_EDIT_ENABLED',true);define('SITE_CONTENT_TEAM_EDIT_ENABLED',true);define('SITE_CONTENT_SERVICE_IMAGE_EDIT_ENABLED',$case!=='edit_off');define('SITE_CONTENT_TEAM_IMAGE_EDIT_ENABLED',$case!=='edit_off');
define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32));define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32));define('SITE_CONTENT_COMMAND_URL','https://example.test/command');
define('SITE_CONTENT_LOOKUP_ENABLED',true);define('SITE_CONTENT_SERVICE_LOOKUP_ENABLED',true);define('SITE_CONTENT_TEAM_LOOKUP_ENABLED',true);define('SITE_CONTENT_SERVICE_IMAGE_LOOKUP_ENABLED',$case!=='lookup_off');define('SITE_CONTENT_TEAM_IMAGE_LOOKUP_ENABLED',$case!=='lookup_off');define('SITE_CONTENT_SERVICE_LIST_ENABLED',true);define('SITE_CONTENT_TEAM_LIST_ENABLED',true);
define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32));define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32));define('SITE_CONTENT_LOOKUP_URL',$case==='wrong_origin'?'https://other.test/lookup':'https://example.test/lookup');
class UIPDO extends PDO {public function prepare(string $sql,array $options=[]):PDOStatement|false{$sql=str_replace(' FOR UPDATE','',$sql);if(str_contains($sql,'ON DUPLICATE KEY UPDATE object_id=object_id')){$sql=str_replace('INSERT INTO crm_site_content_command_targets','INSERT OR IGNORE INTO crm_site_content_command_targets',$sql);$sql=str_replace(' ON DUPLICATE KEY UPDATE object_id=object_id','',$sql);}return parent::prepare($sql,$options);}}
$_SESSION=['user_id'=>1];$_SERVER['REQUEST_METHOD']='POST';$pdo=new UIPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)');$pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
$pdo->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');$pdo->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');$pdo->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
$action=in_array($case,['enqueue','enqueue_stale','no_confirm','one_confirm','clear_enqueue'],true)?'enqueue':(in_array($case,['preview','stale','bad_path','no_action','clear_with_path','wrong_origin','remote_error','edit_off','clear'],true)?'preview':($case==='list'?'list':'lookup'));
$path=$kind==='service'?'assets/icons/valid.png':'uploads/team/valid.png';
$_POST=['action'=>$action,'kind'=>$case==='list'?$kind:$kind.'_image','object_id'=>'1','after_id'=>'0','token'=>str_repeat('a',64),'image_action'=>$case==='no_action'?'':(in_array($case,['clear','clear_enqueue','clear_with_path'],true)?'clear':'replace'),'desired_image'=>in_array($case,['clear','clear_enqueue'],true)?'':($case==='bad_path'?'https://evil.test/x.png':$path)];
if(in_array($case,['enqueue','enqueue_stale','clear_enqueue','one_confirm'],true))$_POST['confirm_identity']='1';if(in_array($case,['enqueue','enqueue_stale','clear_enqueue'],true))$_POST['confirm_change']='1';
register_shutdown_function(function()use($pdo){$rows=$pdo->query('SELECT payload FROM crm_site_content_commands')->fetchAll(PDO::FETCH_COLUMN);echo '|RESULT:'.$GLOBALS['calls'].':'.count($rows).':'.($rows?base64_encode($rows[0]):'');});
include __DIR__.'/pages/site_content_commands.php';
''')
    def run(kind,case):
        r=subprocess.run([php,str(app/'run.php'),kind,case],cwd=app,text=True,capture_output=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Uncaught','Parse error','private remote error')),r.stdout+r.stderr
        return r.stdout
    for kind in ('service','team'):
        lookup=run(kind,'lookup');assert '|RESULT:1:0:' in lookup and '&lt;script&gt;name&lt;/script&gt;' in lookup and '&lt;svg&gt;path&lt;/svg&gt;' in lookup and '<svg>path</svg>' not in lookup and 'name="image_action"' in lookup and 'value="" selected' in lookup and '<img' not in lookup
        preview=run(kind,'preview');assert '|RESULT:1:0:' in preview and 'مقایسهٔ مسیر تصویر سایت' in preview and 'name="confirm_identity"' in preview and 'name="confirm_change"' in preview and 'name="desired_image"' in preview
        clear=run(kind,'clear');assert '|RESULT:1:0:' in clear and 'پاک‌کردن مسیر' in clear
        for case in ('enqueue','clear_enqueue'):
            out=run(kind,case);assert '|RESULT:1:1:' in out
            payload=json.loads(base64.b64decode(out.split('|RESULT:1:1:')[1]));field='icon_path' if kind=='service' else 'image_path';path='assets/icons/valid.png' if kind=='service' else 'uploads/team/valid.png'
            assert payload['type']==kind+'_image_v1' and payload['fields']=={field:'' if case=='clear_enqueue' else path}
        for case in ('stale','enqueue_stale','bad_path','no_action','clear_with_path','wrong_origin','lookup_off','edit_off','readonly','viewer','inactive','forged','missing','remote_error','no_confirm','one_confirm'):
            out=run(kind,case);assert ':0:' in out.split('|RESULT:')[1],(kind,case,out)
        assert '|RESULT:0:0:' in run(kind,'bad_path') and '|RESULT:0:0:' in run(kind,'wrong_origin')
        assert '|RESULT:1:0:' in run(kind,'list') and 'بررسی مسیر تصویر' in run(kind,'list')
    index=(root/'index.php').read_text();assert "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/')
    print('PASS service/team image UI: opt-ins, roles, mandatory reread, escaping, explicit clear and enqueue')
