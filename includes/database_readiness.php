<?php
/** Read-only readiness; web requests never seed or upgrade school databases. */
function schoolAssertDatabaseReady(PDO $db):void {
    require_once __DIR__.'/schema.php';
    try{
        $version=$db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn();
        if($version!==(string)SCHOOL_SCHEMA_VERSION)throw new SchoolDatabaseException('MYSQL_SCHEMA_VERSION','MySQL schema is not ready for this application. Complete the offline migration before using it.');
        $file=dirname(__DIR__).'/config/data/mysql_schema.json';
        $required=is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR)['tables']:['users'=>['id','password'],'schools'=>['id'],'classes'=>['id'],'students'=>['id'],'marks'=>['id'],'cdc_reference'=>['payload']];
        $actual=[];foreach($db->query('SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll() as $row)$actual[$row['table_name']][]=$row['column_name'];
        foreach($required as $table=>$columns)if(array_diff($columns,$actual[$table]??[]))throw new SchoolDatabaseException('MYSQL_SCHEMA_INCOMPLETE','MySQL schema is incomplete. Import and verify the complete migration package.');
    }catch(SchoolDatabaseException $e){throw $e;}catch(Throwable $e){throw new SchoolDatabaseException('MYSQL_SCHEMA_UNAVAILABLE','MySQL schema could not be verified. Import and verify the migration package.');}
}
