<?php
/** Offline/test only; this file is excluded from production publication. */
function openIsolatedSchoolSqlite(array $settings):PDO {
    if(!($settings['isolated_test']??false)||PHP_SAPI!=='cli'&&PHP_SAPI!=='cli-server')throw new SchoolDatabaseException('SQLITE_TEST_ONLY','MySQL is required for normal operation.');
    $path=$settings['sqlite_path'];$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    $pdoClass=class_exists('Pdo\\Sqlite')?'Pdo\\Sqlite':'PDO';$db=new $pdoClass('sqlite:'.$path,null,null,$options);
    $db->exec('PRAGMA busy_timeout=5000');$db->exec('PRAGMA journal_mode=WAL');$db->exec('PRAGMA synchronous=NORMAL');
    $substring=static function($value,$delimiter,$count){$parts=explode($delimiter,(string)$value);return implode($delimiter,$count>0?array_slice($parts,0,$count):array_slice($parts,$count));};
    if(method_exists($db,'createFunction'))$db->createFunction('SUBSTRING_INDEX',$substring,3);else $db->sqliteCreateFunction('SUBSTRING_INDEX',$substring,3);
    $fresh=!$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();$root=dirname(__DIR__,2);
    if($fresh){$fixture=$root.'/tests/fixtures/database_sqlite.sql';if(!is_file($fixture))throw new RuntimeException('The isolated SQLite fixture is missing.');$db->exec(file_get_contents($fixture));}
    require_once $root.'/includes/schema.php';
    if(!$fresh){
        $meta=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='app_meta'")->fetchColumn();$version=$meta?$db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn():false;
        if($version!==false&&(!ctype_digit((string)$version)||(int)$version>SCHOOL_SCHEMA_VERSION))throw new InvalidArgumentException('This database schema requires a newer application; no downgrade was performed.');
        if($version!==(string)SCHOOL_SCHEMA_VERSION){$dir=dirname($path).'/.backups';if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('Cannot create test upgrade snapshot.');$db->exec('VACUUM INTO '.$db->quote($dir.'/school-before-upgrade-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite'));}
    }
    migrateSchoolSchema($db);require_once $root.'/includes/accounting_schema.php';migrateSchoolExtensions($db);return $db;
}
