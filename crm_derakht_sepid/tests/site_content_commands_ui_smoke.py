#!/usr/bin/env python3
"""Real CRM page in SQLite fixture. No browser, real MySQL, HTTP or production data."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_content_commands.php',app/'pages/site_content_commands.php')
    for name in ('site_content_commands.php','site_content_command_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
function is_nazer_kol(){return $GLOBALS['case']==='readonly';} function access_is_special_account(){return true;} function access_mode($x){return 'editor';}
function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function redirect($s){exit;}
define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_SERVICE_EDIT_ENABLED',true); define('SITE_CONTENT_TEAM_EDIT_ENABLED',true); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); define('SITE_CONTENT_COMMAND_KEY',str_repeat('b',32)); define('SITE_CONTENT_COMMAND_URL','https://example.test/api');
class UIPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { $sql=str_replace(' FOR UPDATE','',$sql); if (str_contains($sql,'ON DUPLICATE KEY UPDATE object_id=object_id')) { $sql=str_replace('INSERT INTO crm_site_content_command_targets','INSERT OR IGNORE INTO crm_site_content_command_targets',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE object_id=object_id','',$sql); } return parent::prepare($sql,$options); } }
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_SERVER['REQUEST_METHOD']='GET'; $pdo=new UIPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
$pdo->exec('CREATE TABLE crm_site_content_command_targets(source_id TEXT,kind TEXT,object_id INTEGER,PRIMARY KEY(source_id,kind,object_id))');
$pdo->exec('CREATE TABLE crm_site_content_commands(command_id TEXT PRIMARY KEY,source_id TEXT,kind TEXT,object_id INTEGER,actor_id INTEGER,payload TEXT,payload_hash TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$pdo->exec('CREATE TABLE crm_site_content_command_events(id INTEGER PRIMARY KEY,command_id TEXT,actor_id INTEGER,action TEXT,created_at TEXT)');
if (in_array($case,['preview','none','one','both','readonly_post','bad_token'],true)) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['action'=>$case==='preview'?'preview':'enqueue','kind'=>'service','object_id'=>'1','token'=>$case==='bad_token'?'bad':str_repeat('a',64),'first'=>'<script>title</script>','second'=>'Hello <script>text</script>'];
 if (in_array($case,['one','both','readonly_post','bad_token'],true)) $_POST['confirm_identity']='1'; if (in_array($case,['both','readonly_post','bad_token'],true)) $_POST['confirm_change']='1';
 if ($case==='readonly_post') $case='readonly';
 register_shutdown_function(function() use ($pdo) { echo '|RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_site_content_commands')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_site_content_command_events')->fetchColumn(); });
}
include __DIR__.'/pages/site_content_commands.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,text=True,capture_output=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('FAIL ', 'Fatal error','Uncaught','Warning:','Parse error')),r.stdout+r.stderr
        return r.stdout
    page=run('admin'); assert 'csrf_token' in page and 'name="action" value="preview"' in page and 'name="action" value="enqueue"' not in page
    preview=run('preview'); assert '|RESULT:0:0' in preview and '&lt;script&gt;title&lt;/script&gt;' in preview and '<script>' not in preview and 'name="confirm_identity"' in preview and 'name="confirm_change"' in preview
    for case in ('none','one','readonly_post','bad_token'): assert '|RESULT:0:0' in run(case)
    assert '|RESULT:1:1' in run('both')
    assert 'name="action" value="preview"' not in run('readonly')
    for case in ('viewer','inactive','forged'): assert 'دسترسی مجاز نیست' in run(case)
    index=(root/'index.php').read_text(); assert "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/') and "case 'site_content_commands':" in index
    print('PASS CRM content command preview escaping, role/read-only gates, confirmations and transactional enqueue fixtures')
