<?php
// Synthetic configuration values only: no real connection settings are loaded.
$implementation=__DIR__.'/../includes/database_settings.php';
if(!is_file($implementation)){fwrite(STDERR,"FAIL: MySQL settings validator is not implemented.\n");exit(1);}
require $implementation;
$checks=0;
function dbCheck(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function dbReject(array $env,?array $private,string $label):void{try{schoolDatabaseSettings($env,$private);}catch(SchoolDatabaseException $e){dbCheck(!str_contains($e->getMessage(),'SENTINEL_PRIVATE_PASSWORD'),$label.' secret');return;}throw new RuntimeException($label.' accepted');}
$valid=['ERP_DB_HOST'=>'127.0.0.1','ERP_DB_NAME'=>'erp_migration_test_settings','ERP_DB_USER'=>'synthetic','ERP_DB_PASS'=>'SENTINEL_PRIVATE_PASSWORD'];
dbReject([],null,'Missing MySQL settings');
$settings=schoolDatabaseSettings($valid);
dbCheck($settings['driver']==='mysql'&&$settings['port']===3306&&$settings['charset']==='utf8mb4','Safe MySQL defaults');
dbCheck($settings['isolated_test']===false,'Normal configuration cannot be test storage');
$s=schoolDatabaseSettings($valid+['ERP_DB_PORT'=>'3307'],['host'=>'ignored.invalid','port'=>9999,'database'=>'ignored','username'=>'ignored','password'=>'ignored']);
dbCheck($s['host']==='127.0.0.1'&&$s['port']===3307&&$s['database']==='erp_migration_test_settings','Environment wins over private settings');
dbCheck(schoolDatabaseSettings(array_replace($valid,['ERP_DB_PASS'=>'0']))['password']==='0','Literal zero password is not treated as unset');
foreach(['0','65536','1;dbname=other','-1','1.5'] as $port)dbReject($valid+['ERP_DB_PORT'=>$port],null,'Invalid port');
foreach(['host;dbname=other',"host\ninvalid",'host\\invalid'] as $host)dbReject(array_replace($valid,['ERP_DB_HOST'=>$host]),null,'DSN injection');
dbReject(array_replace($valid,['ERP_DB_NAME'=>'school;host=other']),null,'Database identifier injection');
dbReject($valid+['ERP_DB_TYPE'=>'sqlite','ERP_DB_PATH'=>__DIR__.'/../database.sqlite'],null,'Implicit local SQLite');
dbReject($valid+['ERP_DB_TYPE'=>'sqlite','ERP_DB_PATH'=>__DIR__.'/../database.sqlite','ERP_RUNTIME'=>'test','ERP_TEST_SQLITE'=>'1'],null,'Original database cannot be a fixture');
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-test-'.bin2hex(random_bytes(12));mkdir($directory,0700);
try{
    $s=schoolDatabaseSettings(['ERP_DB_TYPE'=>'sqlite','ERP_DB_PATH'=>$directory.'/test.sqlite','ERP_RUNTIME'=>'test','ERP_TEST_SQLITE'=>'1']);
    dbCheck($s['driver']==='sqlite'&&$s['isolated_test']===true,'Explicit owned temporary SQLite fixture');
}finally{rmdir($directory);}
try{openSchoolPdo(array_replace($settings,['port'=>1]));throw new RuntimeException('Closed port unexpectedly connected');}
catch(SchoolDatabaseException $e){dbCheck(!str_contains((string)$e,'SENTINEL_PRIVATE_PASSWORD')&&!str_contains($e->getMessage(),'synthetic'),'Connection exception is sanitized');}
echo "Database settings checks passed ($checks).\n";
