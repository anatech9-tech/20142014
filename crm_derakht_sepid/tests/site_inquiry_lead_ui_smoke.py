#!/usr/bin/env python3
"""Render actual lead list with SQLite fixtures and a stubbed shell, not an HTTP test."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    for name in ('pages/crm_leads.php','includes/site_inquiries.php'): shutil.copy(root/name,app/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
$case=$argv[1] ?? 'admin';
function require_login(){} function is_strict_admin(){return $GLOBALS['case']==='admin';} function is_finance_officer(){return $GLOBALS['case']==='finance';}
function current_team_id($p){return 20;} function managed_team_ids($p){return [];} function all_teams($p){return [];}
function access_is_special_account(){return $GLOBALS['case']==='special';} function t($s){return $s;} function get_csrf_field(){return '<input name="csrf_token" value="test">';} function js_arg($s){return json_encode($s);}
function crm_scope_where($p,$alias){return is_strict_admin() || is_finance_officer() ? ['1=1',[]] : ["$alias.owner_user_id=?",[(int)$_SESSION['user_id']]];}
$_SESSION=['user_id'=>$case==='admin'?1:($case==='finance'?4:3)]; $_SERVER['REQUEST_METHOD']='GET'; $_GET=[];
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('FIELD',function(...$args){return 0;});
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT,team_id INTEGER,username TEXT,full_name TEXT)',
 'CREATE TABLE teams(id INTEGER PRIMARY KEY,name TEXT)',
 'CREATE TABLE crm_leads(id INTEGER PRIMARY KEY,name TEXT,company TEXT,phone TEXT,mobile TEXT,source TEXT,product_interest TEXT,notes TEXT,status TEXT,owner_user_id INTEGER,team_id INTEGER,created_by INTEGER,converted_customer_id INTEGER)',
 'CREATE TABLE crm_site_inquiries(id INTEGER PRIMARY KEY,owner_user_id INTEGER,team_id INTEGER,lead_id INTEGER)'
] as $q) $pdo->exec($q);
$pdo->exec("INSERT INTO users VALUES(1,'active','admin',NULL,'admin','Admin'),(2,'active','user',10,'old','Old'),(3,'active','user',20,'new','New'),(4,'active','finance',NULL,'fin','Finance'); INSERT INTO teams VALUES(10,'A'),(20,'B'); INSERT INTO crm_leads VALUES(11,'Lead','','0912','','فرم سایت','','no message','new',3,20,1,NULL); INSERT INTO crm_site_inquiries VALUES(5,2,10,11)");
if ($case==='aligned') $pdo->exec('UPDATE crm_site_inquiries SET owner_user_id=3,team_id=20 WHERE id=5');
if ($case==='inactive') $pdo->exec("UPDATE users SET status='inactive' WHERE id=3");
include __DIR__.'/pages/crm_leads.php';
''')
    def run(case):
        p=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in p.stdout+p.stderr,p.stdout+p.stderr
        return p.stdout
    assert 'site_inquiries&amp;view=5' in run('admin')
    assert 'site_inquiries&amp;view=5' in run('aligned')
    for case in ('finance','new','inactive','special'):
        assert 'site_inquiries&amp;view=5' not in run(case),case
    print('PASS lead page only links to currently authorized inbox requests')
