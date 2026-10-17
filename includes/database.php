<?php
/** MySQL-only normal runtime: no schema writes or engine fallback on failure. */
require_once dirname(__DIR__).'/config/bootstrap.php';
class Database {
    private static $instance;private PDO $conn;
    private function __construct(){
        $settings=$GLOBALS['erp_database_settings'];
        if($settings['driver']==='sqlite'&&$settings['isolated_test']){
            $adapter=dirname(__DIR__).'/tools/mysql_migration/sqlite_runtime.php';
            if(!is_file($adapter))throw new SchoolDatabaseException('SQLITE_TEST_ONLY','MySQL is required for normal operation.');
            require_once $adapter;$this->conn=openIsolatedSchoolSqlite($settings);
        }else{$this->conn=openSchoolPdo($settings);require_once __DIR__.'/database_readiness.php';schoolAssertDatabaseReady($this->conn);}
        require_once __DIR__.'/school_setup.php';require_once __DIR__.'/cdc_2083.php';
        $GLOBALS['erp_cdc_percentage_bands']=array_map(static fn($r)=>json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR),$this->conn->query("SELECT payload FROM cdc_reference WHERE category='GRADE_2083' ORDER BY CAST(item_key AS UNSIGNED)")->fetchAll());
    }
    public static function getInstance(){return self::$instance??=new self();}
    public function getConnection():PDO{return $this->conn;}
    private function __clone(){}
    public function __wakeup(){throw new RuntimeException('Cannot unserialize database connection.');}
}
function getDB():PDO{return Database::getInstance()->getConnection();}
