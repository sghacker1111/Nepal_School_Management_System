<?php
/** Shipped bootstrap; credentials belong only in database.local.php/environment. */
require_once dirname(__DIR__).'/includes/database_settings.php';
$previousHandler=set_exception_handler(static function(Throwable $error)use(&$previousHandler):void{
    if($error instanceof SchoolDatabaseException){
        error_log('School database unavailable ['.$error->diagnostic.']');
        if(PHP_SAPI==='cli'){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
        http_response_code(503);header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store, private');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Database setup required</title><h1>MySQL unavailable</h1><p>'.htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8').'</p></html>';exit;
    }
    if(is_callable($previousHandler)){$previousHandler($error);return;}throw $error;
});
error_reporting(E_ALL);ini_set('display_errors',getenv('ERP_DEBUG')==='1'?'1':'0');ini_set('log_errors','1');
$privateFile=__DIR__.'/database.local.php';
try{$privateSettings=is_file($privateFile)?require $privateFile:null;}
catch(Throwable $error){throw new SchoolDatabaseException('MYSQL_CONFIG_INVALID','The private MySQL settings file could not be loaded. Check its PHP syntax.');}
if($privateSettings!==null&&!is_array($privateSettings))throw new SchoolDatabaseException('MYSQL_CONFIG_INVALID','The private MySQL settings file must return an array.');
$GLOBALS['erp_database_settings']=schoolDatabaseSettings(getenv()?:[],$privateSettings);
$dbSettings=$GLOBALS['erp_database_settings'];define('DB_TYPE',$dbSettings['driver']);define('DB_CHARSET','utf8mb4');
if(DB_TYPE==='mysql'){
    define('DB_HOST',$dbSettings['host']);define('DB_PORT',$dbSettings['port']);define('DB_NAME',$dbSettings['database']);define('DB_USER',$dbSettings['username']);define('DB_PASS',$dbSettings['password']);
}else{define('DB_SQLITE_PATH',$dbSettings['sqlite_path']);}
unset($privateSettings,$privateFile,$dbSettings);
define('APP_NAME','Nepal School ERP');require_once dirname(__DIR__).'/includes/http_security.php';
if(!defined('APP_URL')){
    $protocol=schoolRequestIsHttps()?'https://':'http://';$host=preg_replace('/[^a-zA-Z0-9.\-:\[\]]/','',$_SERVER['HTTP_HOST']??'localhost:8000');
    $allowedHosts=array_filter(array_map('trim',explode(',',getenv('ERP_ALLOWED_HOSTS')?:'')));
    if($allowedHosts&&!in_array(strtolower($host),array_map('strtolower',$allowedHosts),true)){http_response_code(400);exit('Invalid host.');}
    $docRoot=str_replace('\\','/',realpath($_SERVER['DOCUMENT_ROOT']??'')?:'');$projectRoot=str_replace('\\','/',realpath(dirname(__DIR__)));
    $basePath=$docRoot!==''&&str_starts_with($projectRoot,$docRoot)?substr($projectRoot,strlen($docRoot)):'';
    $basePath='/'.trim($basePath,'/');if($basePath==='/')$basePath='';define('APP_URL',$protocol.$host.$basePath);
}
define('TIMEZONE','Asia/Kathmandu');date_default_timezone_set(TIMEZONE);
define('UPLOAD_DIR',dirname(__DIR__).'/uploads/');define('MAX_FILE_SIZE',5242880);define('ALLOWED_EXTENSIONS',['jpg','jpeg','png','pdf','doc','docx']);
define('SESSION_TIMEOUT',3600);define('CSRF_TOKEN_NAME','csrf_token');define('DATE_FORMAT_AD','Y-m-d');define('DATE_FORMAT_BS','Y-m-d');
