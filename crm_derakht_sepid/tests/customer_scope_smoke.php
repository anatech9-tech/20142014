<?php
/** SQLite tests for the customer page's scope and write check; does not replace MySQL tests. */
$source = file_get_contents(__DIR__ . '/../pages/crm_customers.php');
$begin = strpos($source, 'function crm_can_edit_record(');
$end = strpos($source, '// ═══════════ اکشن‌ها', $begin);
eval(substr($source, $begin, $end - $begin));
$scopeBegin = strpos($source, 'if ($canAll) { [$scopeSql, $scopeParams]');
$scopeEnd = strpos($source, '// ═══════════ نمایش تک‌رکورد', $scopeBegin);
$scopeCode = substr($source, $scopeBegin, $scopeEnd - $scopeBegin);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER, team_id INTEGER); CREATE TABLE crm_customers (id INTEGER, owner_user_id INTEGER, created_by INTEGER, team_id INTEGER)');
$pdo->exec('INSERT INTO users VALUES (1,10),(2,10),(3,20),(4,20)');
$pdo->exec('INSERT INTO crm_customers VALUES (1,1,1,10),(2,2,2,10),(3,3,3,20),(4,4,1,20),(5,1,NULL,10)');
function check_scope($me, $managed, $canAll, $expected, $label) {
    global $pdo, $scopeCode;
    eval($scopeCode);
    $stmt=$pdo->prepare("SELECT c.id FROM crm_customers c WHERE $scopeSql ORDER BY c.id");
    $stmt->execute($scopeParams); $actual=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($actual !== $expected) { fwrite(STDERR, "$label: ".json_encode($actual)."\n"); exit(1); }
    echo "PASS $label\n";
}
check_scope(1,[],false,[1,4,5],'sales own creator and owner');
check_scope(2,[10],false,[1,2,4,5],'team manager');
check_scope(3,[20],false,[3,4],'other manager');
check_scope(99,[],true,[1,2,3,4,5],'admin');
foreach ([
    [[ 'created_by'=>1,'owner_user_id'=>4,'team_id'=>20],false,[],1,true,'creator edit'],
    [[ 'created_by'=>2,'owner_user_id'=>2,'team_id'=>10],false,[],1,false,'other edit denied'],
    [[ 'created_by'=>3,'owner_user_id'=>3,'team_id'=>20],false,[10],2,false,'manager other team denied'],
    [[ 'created_by'=>2,'owner_user_id'=>2,'team_id'=>10],false,[10],1,true,'manager team edit'],
    [[ 'created_by'=>null,'owner_user_id'=>1,'team_id'=>10],false,[],1,true,'legacy owner edit'],
] as [$row,$all,$managed,$me,$want,$label]) {
    if (crm_can_edit_record($row,$all,$managed,$me) !== $want) { fwrite(STDERR,"FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
