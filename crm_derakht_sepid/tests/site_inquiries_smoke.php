<?php
// Protocol, idempotency and visibility smoke tests (SQLite; MySQL still required).
require_once __DIR__.'/../includes/site_inquiries.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$key=str_repeat('k',32); $now=1700000000; $ts=(string)$now;
$data=['type'=>'contact','event_id'=>str_repeat('a',32),'name'=>'Test User','email'=>'a@example.com','phone'=>'123456789','subject'=>'Hello','message'=>'This is a sufficiently long message'];
$body=json_encode($data); $sig=hash_hmac('sha256',$ts.'.'.$body,$key);
check(site_inquiry_signature_valid($key,$ts,$sig,$body,$now),'signed request');
check(!site_inquiry_signature_valid($key,$ts,$sig,$body.'x',$now),'tampered body');
check(!site_inquiry_signature_valid($key,$ts,$sig,$body,$now+301),'expired signature');
check(!site_inquiry_signature_valid('short',$ts,$sig,$body,$now),'weak key');
check(site_inquiry_validate($data)!==null,'valid payload');
$bad=$data; $bad['event_id']='bad'; check(site_inquiry_validate($bad)===null,'invalid event');
$bad=$data; $bad['email']="bad\r\nx"; check(site_inquiry_validate($bad)===null,'invalid email');
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE crm_site_inquiries (id INTEGER PRIMARY KEY, event_id TEXT UNIQUE,payload_hash TEXT,name TEXT,email TEXT,phone TEXT,subject TEXT,message TEXT,received_at TEXT,owner_user_id INT,team_id INT)');
$v=site_inquiry_validate($data); $hash=hash('sha256',$body);
check(site_inquiry_store($pdo,$v,$hash)==='created','created');
check(site_inquiry_store($pdo,$v,$hash)==='duplicate','retry deduplicated');
check(site_inquiry_store($pdo,$v,hash('sha256',$body.'x'))==='conflict','event reuse rejected');
check((int)$pdo->query('SELECT COUNT(*) FROM crm_site_inquiries')->fetchColumn()===1,'single record');
$pdo->exec("INSERT INTO crm_site_inquiries(event_id,owner_user_id,team_id) VALUES ('b',2,10),('c',3,20),('d',4,10)");
$src=file_get_contents(__DIR__.'/../pages/site_inquiries.php');
$begin=strpos($src,"\$scope='i.owner_user_id=?'"); $end=strpos($src,'if (!ensure_site_inquiries', $begin);
$scopeCode=substr($src,$begin,$end-$begin);
function scope_test($me,$admin,$managed,$expected,$label) { global $pdo,$scopeCode; eval($scopeCode); $st=$pdo->prepare("SELECT i.event_id FROM crm_site_inquiries i WHERE $scope ORDER BY i.event_id"); $st->execute($scopeParams); check($st->fetchAll(PDO::FETCH_COLUMN)===$expected,$label); }
scope_test(2,false,[],['b'],'expert own only');
scope_test(2,false,[10],['b','d'],'manager team only');
scope_test(3,false,[20],['c'],'other team isolated');
scope_test(99,true,[],[str_repeat('a',32),'b','c','d'],'admin includes unassigned');
