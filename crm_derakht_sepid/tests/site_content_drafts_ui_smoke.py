#!/usr/bin/env python3
"""Render real internal-draft page with SQLite fixtures; not a browser or HTTP test."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_content_drafts.php',app/'pages/site_content_drafts.php'); shutil.copy(root/'includes/site_content_drafts.php',app/'includes/site_content_drafts.php'); shutil.copy(root/'includes/site_content_draft_reviews.php',app/'includes/site_content_draft_reviews.php')
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
function is_nazer_kol(){return in_array($GLOBALS['case'],['readonly','post_readonly','post_review_readonly'],true);} function access_is_special_account(){return true;} function access_mode($x){return 'viewer';}
function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function operations_audit(...$x){} function redirect($s){exit;}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET';
class DraftUIPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
$pdo=new DraftUIPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
if ($case!=='missing') {
 if ($case==='review_broken') $pdo->exec('CREATE TABLE crm_site_content_draft_reviews(id INTEGER)');
 elseif ($case!=='review_missing') $pdo->exec('CREATE TABLE crm_site_content_draft_reviews(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER,draft_version INTEGER,actor_id INTEGER,created_at TEXT,UNIQUE(draft_id,draft_version,actor_id))');
 $pdo->exec('CREATE TABLE crm_site_content_drafts(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,body TEXT,version INTEGER,created_by INTEGER,updated_by INTEGER,created_at TEXT,updated_at TEXT)');
 $pdo->exec('CREATE TABLE crm_site_content_draft_versions(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER,version INTEGER,title TEXT,body TEXT,actor_id INTEGER,action TEXT,restored_version INTEGER,created_at TEXT,UNIQUE(draft_id,version))');
 $pdo->prepare('INSERT INTO crm_site_content_drafts VALUES(1,?,?,2,1,1,?,?)')->execute(['<script>title</script>','<script>body</script>','2025-01-01','2025-01-02']);
 $st=$pdo->prepare('INSERT INTO crm_site_content_draft_versions(draft_id,version,title,body,actor_id,action,restored_version,created_at) VALUES(1,?,?,?,1,?,NULL,?)');
 $st->execute([1,'old title','<script>old</script>','create','2025-01-01']); $st->execute([2,'<script>title</script>','<script>body</script>','edit','2025-01-02']);
}
if (in_array($case,['selected','readonly','history','post_restore','post_unconfirmed','post_readonly','post_stale','review_missing','review_broken','history_same','post_review','post_review_unconfirmed','post_review_stale','post_review_readonly'],true)) $_GET=['draft'=>'1','history'=>'1'];
if ($case==='history_same') $_GET=['draft'=>'1','history'=>'2'];
if (str_starts_with($case,'post_')) {
 $_SERVER['REQUEST_METHOD']='POST';
 if (str_starts_with($case,'post_review')) { $_POST=['action'=>'review','draft_id'=>'1','version'=>$case==='post_review_stale'?'1':'2']; if ($case!=='post_review_unconfirmed') $_POST['confirm_review']='1'; }
 elseif ($case==='post_create') $_POST=['action'=>'create','draft_id'=>'0','version'=>'0','title'=>'new','body'=>'text'];
 elseif ($case==='post_edit') $_POST=['action'=>'edit','draft_id'=>'1','version'=>'2','title'=>'edited','body'=>'new text'];
 else { $_POST=['action'=>'restore','draft_id'=>'1','version'=>$case==='post_stale'?'1':'2','restore_version'=>'1']; if ($case!=='post_unconfirmed') $_POST['confirm_restore']='1'; }
 register_shutdown_function(function() use ($pdo) { echo 'RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_site_content_drafts')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_site_content_draft_versions')->fetchColumn().':'.$pdo->query('SELECT version FROM crm_site_content_drafts WHERE id=1')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_site_content_draft_reviews')->fetchColumn(); });
}
include __DIR__.'/pages/site_content_drafts.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr,r.stdout+r.stderr
        return r.stdout
    page=run('selected'); assert 'نه منتشر می‌شود' in page and 'name="csrf_token"' in page and '&lt;script&gt;body&lt;/script&gt;' in page and '<script>' not in page
    history=run('history'); assert '&lt;script&gt;old&lt;/script&gt;' in history and 'name="confirm_restore"' in history and 'name="csrf_token"' in history and 'مقایسه داخلی نسخه تاریخی' in history and 'تفاوت متن: بله' in history and '<script>' not in history
    assert 'name="confirm_review"' in page and 'تأیید انتشار نیست' in page
    assert 'ثبت بررسی آماده نیست' in run('review_missing') and 'ذخیره نسخه جدید' in run('review_missing')
    assert 'ثبت بررسی آماده نیست' in run('review_broken') and 'ذخیره نسخه جدید' in run('review_broken')
    assert 'تفاوت عنوان: خیر' in run('history_same') and 'تفاوت متن: خیر' in run('history_same')
    readonly=run('readonly'); assert '<form method="post"' not in readonly and '&lt;script&gt;body&lt;/script&gt;' in readonly
    assert 'RESULT:2:3:2' in run('post_create') and 'RESULT:1:3:3' in run('post_edit') and 'RESULT:1:3:3' in run('post_restore')
    for case in ('post_unconfirmed','post_readonly','post_stale'): assert 'RESULT:1:2:2:0' in run(case)
    assert 'RESULT:1:2:2:1' in run('post_review')
    for case in ('post_review_unconfirmed','post_review_stale','post_review_readonly'): assert 'RESULT:1:2:2:0' in run(case)
    for case in ('viewer','inactive','forged'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '<table' not in denied
    assert 'جدول پیش‌نویس آماده نیست' in run('missing')
    index=(root/'index.php').read_text(); assert "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index("pages/") and "case 'site_content_drafts'" in index
    print('PASS content draft role, escaping, history, confirmation, stale write and read-only UI fixture')
