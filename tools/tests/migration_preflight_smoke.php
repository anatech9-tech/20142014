<?php
/** Pure metadata fixture; no MySQL, network, runtime credentials or production data. */
define('MIGRATION_PREFLIGHT_TESTING',true);
require_once __DIR__.'/../migration_preflight.php';
function check($value,$label) { if (!$value) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
foreach (['site','crm'] as $component) {
 $specs=preflight_specs($component); $tables=[]; $columns=[];
 foreach ($specs as $spec) foreach ($spec['tables'] as $name=>$cols) { $tables[$name]='InnoDB'; foreach ($cols as $col) $columns[$name][$col]=true; }
 $tables['secret_table']='PRIVATE'; $columns['secret_table']['secret_column']=true;
 $result=preflight_evaluate($specs,$tables,$columns);
 check($result['required_checks_passed'] && $result['optional_checks_passed'],$component.' complete fixture passes scoped checks');
 check(!str_contains(json_encode($result),'secret_table') && !str_contains(json_encode($result),'secret_column'),$component.' unlisted metadata not included in report');
 $requiredName=$component==='site'?'orders':'users';
 unset($tables[$requiredName]); $result=preflight_evaluate($specs,$tables,$columns);
 check(!$result['required_checks_passed'] && in_array($requiredName,$result['groups'][$component==='site'?'shop_core':'crm_core']['missing_or_not_visible'],true),$component.' missing critical table detected');
 $tables[$requiredName]='MyISAM'; $result=preflight_evaluate($specs,$tables,$columns);
 check(!$result['required_checks_passed'] && in_array($requiredName,$result['groups'][$component==='site'?'shop_core':'crm_core']['non_innodb_or_unknown'],true),$component.' non-transactional table detected');
 $tables[$requiredName]='InnoDB'; unset($columns[$requiredName]['id']); $result=preflight_evaluate($specs,$tables,$columns);
 check(!$result['required_checks_passed'] && in_array($requiredName.'.id',$result['groups'][$component==='site'?'shop_core':'crm_core']['missing_columns_or_not_visible'],true),$component.' missing required column detected');
 $columns[$requiredName]['id']=true; $optionalName=$component==='site'?'shop_crm_outbox':'crm_shop_shipping_packages'; unset($tables[$optionalName]);
 $result=preflight_evaluate($specs,$tables,$columns);
 check($result['required_checks_passed'] && !$result['optional_checks_passed'],$component.' missing optional table reported without claiming bridge ready');
}
try { preflight_specs('other'); check(false,'unknown component denied'); } catch (InvalidArgumentException $e) { check(true,'unknown component denied'); }
// Exercise main with a synthetic metadata-only connection, without a database.
if (extension_loaded('pdo_mysql')) {
    class PreflightFakeRows { public array $parameters=[]; public function __construct(private array $rows) {} public function execute(array $parameters): bool { $this->parameters=$parameters; return true; } public function fetchAll(): array { return $this->rows; } }
    class PreflightFakeConnection {
        public array $queries=[];
        public function prepare(string $sql): PreflightFakeRows {
            $this->queries[]=$sql;
            if (str_contains($sql,'information_schema.TABLES')) {
                $rows=[]; foreach (preflight_specs('site') as $group) foreach ($group['tables'] as $table=>$cols) $rows[]=['TABLE_NAME'=>$table,'ENGINE'=>'InnoDB'];
                $rows[]=['TABLE_NAME'=>'private_table','ENGINE'=>'MyISAM']; return new PreflightFakeRows($rows);
            }
            if (str_contains($sql,'information_schema.COLUMNS')) {
                $rows=[]; foreach (preflight_specs('site') as $group) foreach ($group['tables'] as $table=>$cols) foreach ($cols as $col) $rows[]=['TABLE_NAME'=>$table,'COLUMN_NAME'=>$col];
                $rows[]=['TABLE_NAME'=>'private_table','COLUMN_NAME'=>'private_column']; return new PreflightFakeRows($rows);
            }
            throw new RuntimeException('unexpected query');
        }
    }
    putenv('MIGRATION_DB_HOST=localhost'); putenv('MIGRATION_DB_NAME=test_db'); putenv('MIGRATION_DB_USER=secret_user'); putenv('MIGRATION_DB_PASS=secret_password');
    $fake=new PreflightFakeConnection(); ob_start();
    $exit=preflight_main(['tool','site'],fn($dsn,$u,$p,$options)=>$fake); $output=ob_get_clean(); $result=json_decode($output,true);
    check($exit===0 && $result['required_checks_passed'] && $result['optional_checks_passed'],'metadata-only connection produces scoped report');
    check(count($fake->queries)===2 && count(array_filter($fake->queries,fn($q)=>str_starts_with($q,'SELECT ') && str_contains($q,'information_schema.')))===2,'only two metadata SELECT queries issued');
    check(!str_contains($output,'secret_user') && !str_contains($output,'secret_password') && !str_contains($output,'test_db') && !str_contains($output,'private_table') && !str_contains($output,'private_column'),'report omits credentials, database and unrelated metadata');
    putenv('MIGRATION_DB_HOST'); putenv('MIGRATION_DB_NAME'); putenv('MIGRATION_DB_USER'); putenv('MIGRATION_DB_PASS');
}
