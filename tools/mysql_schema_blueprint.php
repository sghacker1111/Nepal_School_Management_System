<?php
/** Owned local fixtures only. Never opens a normal school database. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$args=getopt('',['action:','database:','manifest:']);$action=$args['action']??'';$path=$args['database']??'';
$parent=is_string($path)?realpath(dirname($path)):false;$temp=realpath(sys_get_temp_dir());
if(!$parent||!$temp||!str_starts_with(strtolower(str_replace('\\','/',$parent).'/'),strtolower(str_replace('\\','/',$temp).'/'))||!in_array($action,['blueprint','metadata'],true)||is_link($path)){fwrite(STDERR,"An owned system-temporary SQLite path and supported action are required.\n");exit(1);}
if($action==='blueprint'&&file_exists($path)){fwrite(STDERR,"Blueprint target must be new.\n");exit(1);}
if($action==='metadata'&&!is_file($path)){fwrite(STDERR,"Metadata target must already exist.\n");exit(1);}
putenv('ERP_RUNTIME=test');putenv('ERP_TEST_SQLITE=1');putenv('ERP_DB_TYPE=sqlite');putenv('ERP_DB_PATH='.$path);
require dirname(__DIR__).'/config/bootstrap.php';
try{
    if($action==='blueprint'){require dirname(__DIR__).'/includes/database.php';$db=getDB();}
    else{
        $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        require dirname(__DIR__).'/includes/schema.php';require dirname(__DIR__).'/includes/accounting_schema.php';require dirname(__DIR__).'/includes/exam_template_schema.php';
        foreach($db->query('SELECT id FROM schools')->fetchAll(PDO::FETCH_COLUMN) as $school)provisionSchoolAccounting($db,(int)$school);
        migrateExamTemplates($db);
    }
    if(isset($args['manifest'])){
        $tables=[];foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table)$tables[$table]=dbColumns($db,$table);
        file_put_contents($args['manifest'],json_encode(['schema_version'=>SCHOOL_SCHEMA_VERSION,'tables'=>$tables],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    }
    echo json_encode(['action'=>$action,'schema_version'=>SCHOOL_SCHEMA_VERSION])."\n";
}catch(Throwable $error){fwrite(STDERR,"Offline schema operation failed. Review the private fixture; no original database was opened.\n");exit(1);}
