#!/usr/bin/env python3
"""Real metadata page in a SQLite fixture; not a browser or real HTTP session."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_blog_metadata.php',app/'pages/site_blog_metadata.php')
    for name in ('site_blog_draft_links.php','site_blog_metadata_contract.php','site_blog_metadata_sync.php','site_content_drafts.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
function is_nazer_kol(){return in_array($GLOBALS['case'],['readonly','post_readonly'],true);} function access_is_special_account(){return true;} function access_mode($x){return 'viewer';}
function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function operations_audit(...$x){} function redirect($s){exit;}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=['draft'=>'1']; $_SERVER['REQUEST_METHOD']='GET';
if ($case!=='disabled') { define('SITE_BLOG_META_ENABLED',true); define('SITE_BLOG_META_KEY',str_repeat('b',32)); define('SITE_BLOG_SOURCE_ID',str_repeat('a',32)); }
class BlogUIPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
$pdo=new BlogUIPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach (['CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_site_content_drafts(id INTEGER PRIMARY KEY,title TEXT,version INTEGER)',
 'CREATE TABLE crm_site_content_draft_versions(id INTEGER PRIMARY KEY)',
 'CREATE TABLE crm_site_blog_meta(id INTEGER PRIMARY KEY,source_id TEXT,post_id INTEGER,revision INTEGER,is_published INTEGER,received_at TEXT)',
 'CREATE TABLE crm_site_blog_draft_links(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER UNIQUE,source_id TEXT,post_id INTEGER,replica_id INTEGER,draft_version INTEGER,post_revision INTEGER,linked_by INTEGER,linked_at TEXT,UNIQUE(source_id,post_id))',
 'CREATE TABLE crm_site_blog_draft_link_events(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,draft_id INTEGER,source_id TEXT,post_id INTEGER,replica_id INTEGER,draft_version INTEGER,post_revision INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)'] as $sql) $pdo->exec($sql);
$pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
$pdo->exec("INSERT INTO crm_site_content_drafts VALUES(1,'<script>title</script>',3)");
$pdo->prepare('INSERT INTO crm_site_blog_meta VALUES(4,?,7,15,1,?)')->execute([str_repeat('a',32),'2025-01-01']);
$pdo->prepare('INSERT INTO crm_site_blog_meta VALUES(5,?,8,16,2,?)')->execute([str_repeat('a',32),'2025-01-01']);
$pdo->prepare('INSERT INTO crm_site_blog_meta VALUES(6,?,9,17,0,?)')->execute(['<script>','2025-01-01']);
if (in_array($case,['linked','drift','post_unlink','post_unlink_one'],true)) $pdo->prepare('INSERT INTO crm_site_blog_draft_links VALUES(1,1,?,7,4,3,15,1,?)')->execute([str_repeat('a',32),'2025-01-01']);
if ($case==='drift') $pdo->exec('UPDATE crm_site_blog_meta SET revision=16 WHERE id=4');
if (str_starts_with($case,'post_')) {
 $_SERVER['REQUEST_METHOD']='POST'; $unlink=in_array($case,['post_unlink','post_unlink_one'],true);
 $_POST=['draft'=>'1','draft_version'=>'3','action'=>$unlink?'unlink':'link','replica'=>'4','revision'=>$case==='post_stale'?'14':'15','link_id'=>'1'];
 if ($case!=='post_none') $_POST['confirm_identity']='1';
 if (!in_array($case,['post_none','post_one','post_unlink_one'],true)) $_POST['confirm_internal']='1';
 register_shutdown_function(function() use ($pdo) { echo 'RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_site_blog_draft_links')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_site_blog_draft_link_events')->fetchColumn(); });
}
include __DIR__.'/pages/site_blog_metadata.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr,r.stdout+r.stderr
        return r.stdout
    page=run('admin'); assert 'تغییر متن' in page and 'اثبات همسانی محتوا نیست' in page and 'name="confirm_identity"' in page and 'name="confirm_internal"' in page and 'csrf_token' in page
    assert '&lt;script&gt;title&lt;/script&gt;' in page and '<script>' not in page and page.count('ثبت ارجاع داخلی</button>')==1
    assert '<form method="post"' not in run('readonly') and '<form method="post"' not in run('disabled')
    assert 'قطع ارجاع داخلی</button>' in run('linked') and 'تطبیق دستی لازم است' in run('drift')
    for case in ('post_none','post_one','post_readonly','post_stale'): assert 'RESULT:0:0' in run(case)
    assert 'RESULT:1:1' in run('post_both') and 'RESULT:0:1' in run('post_unlink') and 'RESULT:1:0' in run('post_unlink_one')
    for case in ('viewer','inactive','forged'): assert 'دسترسی مجاز نیست' in run(case)
    index=(root/'index.php').read_text(); assert "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/')
    print('PASS blog linking page authorization, CSRF field, escaping, confirmations, stale and read-only fixtures')
