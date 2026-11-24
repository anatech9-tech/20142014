<?php
require_once __DIR__.'/../includes/SiteCrmBridge.php';
function check($x,$label) { if (!$x) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(!site_bridge_configured(),'disabled by default');
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE website_crm_outbox (id INTEGER PRIMARY KEY,event_id TEXT UNIQUE,payload TEXT,next_attempt_at TEXT,created_at TEXT)');
$id=site_bridge_queue($pdo,['name'=>'Tester','email'=>'a@example.com','phone'=>'123456','subject'=>'Test','message'=>'Test message long enough']);
$row=$pdo->query('SELECT * FROM website_crm_outbox')->fetch(PDO::FETCH_ASSOC); $data=json_decode($row['payload'],true);
check(strlen($id)===32 && $id===$data['event_id'],'stable random event');
check($data['type']==='contact' && $data['message']==='Test message long enough','stored payload');
$id2=site_bridge_queue($pdo,['name'=>'Tester','email'=>'a@example.com','phone'=>'','subject'=>'','message'=>'Another message']);
check($id!==$id2 && (int)$pdo->query('SELECT COUNT(*) FROM website_crm_outbox')->fetchColumn()===2,'separate submissions');
