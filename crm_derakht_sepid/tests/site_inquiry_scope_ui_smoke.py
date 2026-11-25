#!/usr/bin/env python3
"""Render actual inbox page with SQLite and stubbed shell; not HTTP or a browser."""
import json,os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_inquiries.php',app/'pages/site_inquiries.php')
    shutil.copy(root/'includes/site_inquiries.php',app/'includes/site_inquiries.php')
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
$case=$argv[1] ?? 'admin';
function require_login(){} function is_strict_admin(){return in_array($GLOBALS['case'],['admin','readonly','inactive','post_reconcile','post_readonly','view_allowed','view_invalid'],true);}
function managed_team_ids($pdo){return ($GLOBALS['case']==='manager') ? [10] : [];}
function is_nazer_kol(){return in_array($GLOBALS['case'],['readonly','post_readonly'],true);} function access_is_special_account(){return true;}
function access_mode($key){return 'editor';} function showToast($x,$y=''){} function redirect($x){exit;} function operations_audit(...$x){} function to_jalali($s){return $s;} function get_csrf_field(){return '<input name="csrf_token" value="test">';}
$_SESSION=['user_id'=>in_array($case,['former','manager','post_former','post_lead','post_lead_failed','post_lead_duplicate','post_lead_other','view_forbidden'],true)?2:($case==='new'?3:1)]; $_SERVER['REQUEST_METHOD']='GET'; if (in_array($case,['view_allowed','view_forbidden'],true)) $_GET=['view'=>'1']; if ($case==='view_invalid') $_GET=['view'=>'0'];
class PageTestPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }}
$pdo=new PageTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT,team_id INTEGER,full_name TEXT,username TEXT)',
 'CREATE TABLE teams(id INTEGER PRIMARY KEY,name TEXT)',
 'CREATE TABLE crm_leads(id INTEGER PRIMARY KEY,name TEXT,owner_user_id INTEGER,team_id INTEGER,company TEXT,phone TEXT,mobile TEXT,source TEXT,product_interest TEXT,notes TEXT,status TEXT,created_by INTEGER)',
 'CREATE TABLE crm_site_inquiries(id INTEGER PRIMARY KEY,name TEXT,email TEXT,phone TEXT,subject TEXT,message TEXT,owner_user_id INTEGER,team_id INTEGER,lead_id INTEGER,status TEXT,received_at TEXT,assigned_at TEXT,assigned_by INTEGER)',
 'CREATE TABLE crm_site_inquiry_owner_events(id INTEGER PRIMARY KEY,inquiry_id INTEGER,lead_id INTEGER,actor_id INTEGER,old_owner INTEGER,old_team INTEGER,new_owner INTEGER,new_team INTEGER,created_at TEXT)'
] as $q) $pdo->exec($q);
$pdo->exec("INSERT INTO users VALUES(1,'active','admin',NULL,'Admin','admin'),(2,'active','user',10,'Old','old'),(3,'active','user',20,'New','new'); INSERT INTO teams VALUES(10,'A'),(20,'B'); INSERT INTO crm_leads(id,name,owner_user_id,team_id,notes) VALUES(11,'<lead>',3,20,'Historical private note')");
if ($case==='inactive') $pdo->exec("UPDATE users SET status='inactive' WHERE id=1");
$pdo->exec("INSERT INTO crm_site_inquiries VALUES(1,'<inquiry>','a@example.test','', 'Subject','Private message',2,10,11,'assigned','2025-01-01 12:00:00',NULL,NULL)");
$pdo->exec("INSERT INTO crm_site_inquiries VALUES(2,'Orphan','a@example.test','', 'Subject','Orphan message',2,10,99,'assigned','2025-01-01 12:00:00',NULL,NULL)");
$pdo->exec("INSERT INTO crm_site_inquiries VALUES(3,'New Lead','unique@example.test','09121234567', 'Unique Subject','Unique confidential message',2,10,NULL,'assigned','2025-01-01 12:00:00',NULL,NULL)");
if (in_array($case,['post_lead','post_lead_failed','post_lead_duplicate','post_lead_other'],true)) {
 if ($case==='post_lead_duplicate') { $pdo->exec("INSERT INTO crm_leads(id,name,owner_user_id,team_id,notes) VALUES(12,'Existing',2,10,'old note'); UPDATE crm_site_inquiries SET lead_id=12 WHERE id=3"); }
 if ($case==='post_lead_failed') $pdo->exec("CREATE TRIGGER fail_link BEFORE UPDATE OF lead_id ON crm_site_inquiries BEGIN SELECT RAISE(ABORT,'link'); END");
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['id'=>$case==='post_lead_other'?'1':'3','action'=>'lead'];
 register_shutdown_function(function() use ($pdo) { $data=['count'=>(int)$pdo->query('SELECT COUNT(*) FROM crm_leads')->fetchColumn(),'new'=>$pdo->query('SELECT name,phone,notes FROM crm_leads WHERE id<>11')->fetch(PDO::FETCH_ASSOC) ?: null,'link'=>$pdo->query('SELECT lead_id FROM crm_site_inquiries WHERE id=3')->fetchColumn(),'original'=>$pdo->query('SELECT email,subject,message FROM crm_site_inquiries WHERE id=3')->fetch(PDO::FETCH_ASSOC),'old'=>$pdo->query('SELECT notes FROM crm_leads WHERE id=11')->fetchColumn()]; echo 'LEAD:'.json_encode($data,JSON_UNESCAPED_UNICODE); });
}
if (in_array($case,['post_reconcile','post_former','post_readonly'],true)) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['id'=>'1','action'=>'reconcile','lead_id'=>'11','lead_owner'=>'3','lead_team'=>'20'];
 register_shutdown_function(function() use ($pdo) { echo 'RESULT:'.$pdo->query('SELECT owner_user_id FROM crm_site_inquiries WHERE id=1')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_site_inquiry_owner_events')->fetchColumn(); });
}
include __DIR__.'/pages/site_inquiries.php';
''')
    def run(case):
        p=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in p.stdout+p.stderr,p.stdout+p.stderr
        return p.stdout
    admin=run('admin'); assert '&lt;lead&gt;' in admin and '<lead>' not in admin and 'تطبیق دستی ارجاع با سرنخ</button>' in admin and 'سرنخ مرتبط یافت نشد' in admin and 'csrf_token' in admin
    for role in ('former','new','manager'):
        html=run(role); assert 'Private message' not in html and 'Orphan message' not in html and 'تطبیق دستی ارجاع با سرنخ</button>' not in html
    readonly=run('readonly'); assert 'Private message' in readonly and 'تطبیق دستی ارجاع با سرنخ</button>' not in readonly and 'ثبت پاسخ‌داده‌شده</button>' not in readonly
    assert 'دسترسی مجاز نیست' in run('inactive')
    assert 'RESULT:3:1' in run('post_reconcile')
    assert 'RESULT:2:0' in run('post_former') and 'RESULT:2:0' in run('post_readonly')
    assert 'Private message' in run('view_allowed') and 'Orphan message' not in run('view_allowed')
    assert 'Private message' not in run('view_forbidden') and 'شناسه درخواست نامعتبر' in run('view_invalid')
    created=json.loads(run('post_lead').split('LEAD:',1)[1]); assert created['count']==2 and int(created['link'])>0
    assert created['new']['name']=='New Lead' and created['new']['phone']=='09121234567'
    assert all(x not in created['new']['notes'] for x in ('unique@example.test','Unique Subject','Unique confidential message'))
    assert created['original']['message']=='Unique confidential message' and created['old']=='Historical private note'
    failed=json.loads(run('post_lead_failed').split('LEAD:',1)[1]); assert failed['count']==1 and failed['link'] is None and failed['old']=='Historical private note'
    duplicate=json.loads(run('post_lead_duplicate').split('LEAD:',1)[1]); assert duplicate['count']==2 and duplicate['link']==12
    other=json.loads(run('post_lead_other').split('LEAD:',1)[1]); assert other['count']==1 and other['link'] is None
    print('PASS inbox scopes, deep links, minimal lead creation, duplicate prevention and rollback')
