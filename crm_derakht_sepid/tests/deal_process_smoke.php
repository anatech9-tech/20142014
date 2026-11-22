<?php
/** Isolated smoke tests for the real POST controller. SQLite substitutes for MySQL;
 * run once per case: php tests/deal_process_smoke.php <case>.
 * The production DDL/locking syntax is translated only in this harness.
 */
class TestPDO extends PDO {
    public function exec(string $statement): int|false {
        if (str_contains($statement, 'ENGINE=InnoDB')) return 0;
        return parent::exec($statement);
    }
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','INSERT IGNORE INTO'],['','INSERT OR IGNORE INTO'],$query),$options);
    }
}
function require_login() {} function is_strict_admin(){return true;} function is_finance_officer(){return false;}
function managed_team_ids(PDO $pdo){return [];} function access_mode($key){return 'editor';} function is_nazer_kol(){return false;}
function operations_audit(...$args){} function showToast($message,$type='success'){$GLOBALS['toast']=[$message,$type];}
function redirect($url){exit;}
$case=$argv[1] ?? ''; $pdo=new TestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach ([
    'CREATE TABLE users(id INTEGER PRIMARY KEY,full_name TEXT)',
    'CREATE TABLE crm_customers(id INTEGER PRIMARY KEY,name TEXT)',
    'CREATE TABLE crm_deals(id INTEGER PRIMARY KEY,customer_id INTEGER,owner_user_id INTEGER,team_id INTEGER,status TEXT,title TEXT)',
    'CREATE TABLE crm_deal_process(deal_id INTEGER PRIMARY KEY,stage_key TEXT,quote_id INTEGER,created_by INTEGER,updated_by INTEGER)',
    'CREATE TABLE crm_deal_process_events(id INTEGER PRIMARY KEY AUTOINCREMENT,deal_id INTEGER,from_stage TEXT,to_stage TEXT,action TEXT,actor_id INTEGER,quote_id INTEGER,note TEXT,satisfaction_score INTEGER,customer_visible INTEGER,notification_status TEXT)',
    'CREATE TABLE crm_quotes(id INTEGER PRIMARY KEY,deal_id INTEGER,status TEXT)',
    'CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES (1,'Test')"); $pdo->exec("INSERT INTO crm_deals VALUES (1,NULL,1,1,'open','Test')");
$scenarios=[
    'invalid_quote'=>['requirements','advance',['quote_id'=>99],'requirements',0],
    'foreign_quote'=>['requirements','advance',['quote_id'=>2],'requirements',0],
    'select_quote'=>['requirements','advance',['quote_id'=>1],'quotation',1],
    'not_fixed'=>['quotation','advance',[],'quotation',0],
    'fixed'=>['quotation','advance',[],'customer_decision',1],
    'no_customer_confirmation'=>['customer_decision','advance',[],'customer_decision',0],
    'customer_confirmation'=>['customer_decision','advance',['confirmed'=>'1'],'proforma',1],
    'no_score'=>['satisfaction','advance',[],'satisfaction',0],
    'score'=>['satisfaction','advance',['score'=>'5','note'=>'خوب'],'completed',1],
    'back_without_reason'=>['delivery','back',[],'delivery',0],
    'back_with_reason'=>['delivery','back',['note'=>'اصلاح'],'settlement',1],
    'stale'=>['delivery','advance',['expected_stage'=>'sourcing'],'delivery',0],
    'start_new'=>['','start',[],'contact',1],
    'start_legacy'=>['','start',['initial_stage'=>'shipping','note'=>'پرونده قبلی'],'shipping',1],
    'legacy_without_reason'=>['','start',['initial_stage'=>'shipping'],null,0],
    'legacy_quotation'=>['','start',['initial_stage'=>'quotation','note'=>'old'],null,0],
];
if (!isset($scenarios[$case])) {fwrite(STDERR,"unknown case\n");exit(2);}
[$stage,$action,$fields,$expected,$eventCount]=$scenarios[$case];
$fixed=$case==='fixed'?'fixed':'in_flow';
$pdo->prepare('INSERT INTO crm_quotes(id,deal_id,status) VALUES (1,1,?)')->execute([$fixed]);
$pdo->exec("INSERT INTO crm_quotes(id,deal_id,status) VALUES (2,2,'fixed')");
if ($stage!=='') $pdo->prepare('INSERT INTO crm_deal_process VALUES (1,?,1,1,1)')->execute([$stage]);
$_SESSION=['user_id'=>1]; $_GET=['id'=>1]; $_SERVER['REQUEST_METHOD']='POST'; $_POST=array_merge(['action'=>$action,'expected_stage'=>$stage],$fields);
register_shutdown_function(function() use($pdo,$case,$expected,$eventCount){
    $row=$pdo->query('SELECT * FROM crm_deal_process WHERE deal_id=1')->fetch();
    $events=$pdo->query('SELECT * FROM crm_deal_process_events')->fetchAll();
    $ok=($row['stage_key'] ?? null)===$expected && count($events)===$eventCount;
    if ($case==='start_legacy') $ok=$ok && $events[0]['action']==='legacy_start' && (int)$events[0]['customer_visible']===0;
    if ($case==='select_quote') $ok=$ok && (int)$row['quote_id']===1 && (int)$events[0]['quote_id']===1;
    if ($case==='score') $ok=$ok && (int)$events[0]['satisfaction_score']===5;
    if ($case==='customer_confirmation') $ok=$ok && (int)$events[0]['customer_visible']===1 && $events[0]['notification_status']==='not_configured';
    if (!$ok) {fwrite(STDERR,"FAIL $case ".json_encode([$row,$events,$GLOBALS['toast']??null],JSON_UNESCAPED_UNICODE)."\n");exit(1);}
    echo "PASS $case\n";
});
require __DIR__.'/../pages/crm_deal_process.php';
