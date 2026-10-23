<?php
/** Only process environment/private PHP configuration controls storage. */
ini_set('zend.exception_ignore_args','1');
final class SchoolDatabaseException extends RuntimeException {
    public function __construct(public readonly string $diagnostic,string $message){parent::__construct($message);}
}
function schoolDatabaseSettings(array $environment,?array $privateSettings=null):array {
    $privateSettings??=[];
    $pick=static fn(string $env,string $key,mixed $default=null)=>array_key_exists($env,$environment)?$environment[$env]:($privateSettings[$key]??$default);
    $driver=$pick('ERP_DB_TYPE','driver','mysql');
    if($driver==='sqlite'){
        $path=$environment['ERP_DB_PATH']??'';$temp=realpath(sys_get_temp_dir());
        $parent=is_string($path)&&$path!==''?realpath(dirname($path)):false;
        $normalize=static fn(string $p)=>strtolower(str_replace('\\','/',$p));
        $loopback=PHP_SAPI==='cli'||(PHP_SAPI==='cli-server'&&in_array($_SERVER['REMOTE_ADDR']??'127.0.0.1',['127.0.0.1','::1'],true));
        if(($environment['ERP_RUNTIME']??'')!=='test'||($environment['ERP_TEST_SQLITE']??'')!=='1'||!$loopback||!$parent||!$temp||!($normalize($parent)===$normalize($temp)||str_starts_with($normalize($parent),$normalize($temp).'/'))||is_link($path)||!is_writable($parent))throw new SchoolDatabaseException('SQLITE_TEST_ONLY','MySQL is required for normal operation. SQLite is limited to explicit disposable tests.');
        return ['driver'=>'sqlite','sqlite_path'=>$path,'isolated_test'=>true,'charset'=>'utf8mb4'];
    }
    if($driver!=='mysql')throw new SchoolDatabaseException('MYSQL_DRIVER_REQUIRED','MySQL is required for normal operation.');
    $settings=['driver'=>'mysql','host'=>$pick('ERP_DB_HOST','host'),'port'=>$pick('ERP_DB_PORT','port',3306),'database'=>$pick('ERP_DB_NAME','database'),'username'=>$pick('ERP_DB_USER','username'),'password'=>$pick('ERP_DB_PASS','password'),'charset'=>'utf8mb4','isolated_test'=>false];
    foreach(['host','database','username','password'] as $key)if(!is_string($settings[$key])||$settings[$key]==='')throw new SchoolDatabaseException('MYSQL_CONFIG_REQUIRED','MySQL is not configured. Set config/database.local.php or the ERP_DB environment settings.');
    if(!preg_match('/\A[a-zA-Z0-9.\-:\[\]_]+\z/D',$settings['host'])||!preg_match('/\A[a-zA-Z0-9_]+\z/D',$settings['database'])||preg_match('/[\x00-\x1f\x7f]/',$settings['username']))throw new SchoolDatabaseException('MYSQL_CONFIG_INVALID','MySQL settings contain an invalid server or database identifier.');
    if((!is_string($settings['port'])&&!is_int($settings['port']))||!preg_match('/\A[0-9]+\z/D',(string)$settings['port'])||(int)$settings['port']<1||(int)$settings['port']>65535)throw new SchoolDatabaseException('MYSQL_PORT_INVALID','MySQL port must be between 1 and 65535.');
    $settings['port']=(int)$settings['port'];return $settings;
}
function openSchoolPdo(array $settings):PDO {
    if(($settings['driver']??null)!=='mysql')throw new SchoolDatabaseException('MYSQL_DRIVER_REQUIRED','MySQL is required for this connection.');
    try{
        $pdo=new PDO('mysql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$settings['database'].';charset=utf8mb4',$settings['username'],$settings['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");return $pdo;
    }catch(PDOException $e){throw new SchoolDatabaseException('MYSQL_CONNECT_FAILED','MySQL connection failed. Check the private database settings and server availability.');}
}
