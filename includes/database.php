<?php
/** PDO connection. Failed MySQL connections never silently open another database. */
class Database {
    private static $instance;
    private $conn;
    private function __construct() {
        $freshDatabase = false;
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        if (DB_TYPE === 'sqlite') {
            $pdoClass = class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : 'PDO';
            $this->conn = new $pdoClass('sqlite:' . DB_SQLITE_PATH, null, null, $options);
            $this->conn->exec('PRAGMA busy_timeout = 5000');
            $this->conn->exec('PRAGMA journal_mode = WAL');
            $this->conn->exec('PRAGMA synchronous = NORMAL');
            $substringIndex = static function ($value, $delimiter, $count) {
                $parts = explode($delimiter, (string)$value);
                return implode($delimiter, $count > 0 ? array_slice($parts, 0, $count) : array_slice($parts, $count));
            };
            if (method_exists($this->conn, 'createFunction')) $this->conn->createFunction('SUBSTRING_INDEX', $substringIndex, 3);
            else $this->conn->sqliteCreateFunction('SUBSTRING_INDEX', $substringIndex, 3);
            if (!$this->conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn()) {
                $freshDatabase = true;
                $this->conn->exec(file_get_contents(dirname(__DIR__) . '/database_sqlite.sql'));
            }
        } elseif (DB_TYPE === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
            $this->conn = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, $options);
        } else { throw new RuntimeException('Unsupported database type.'); }
        require_once __DIR__ . '/schema.php';
        if (DB_TYPE==='sqlite' && !$freshDatabase) {
            $hasMeta=$this->conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='app_meta'")->fetchColumn();
            $version=$hasMeta?$this->conn->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn():false;
            if($version!=='3') {
                $backupDir=dirname(DB_SQLITE_PATH).'/.backups';
                if(!is_dir($backupDir)&&!mkdir($backupDir,0750,true))throw new RuntimeException('Cannot create pre-upgrade database backup directory.');
                $backup=$backupDir.'/school-before-upgrade-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
                $this->conn->exec('VACUUM INTO '.$this->conn->quote($backup));
            }
        }
        migrateSchoolSchema($this->conn);
    }
    public static function getInstance() { return self::$instance ??= new self(); }
    public function getConnection() { return $this->conn; }
    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize database connection.'); }
}
function getDB() { return Database::getInstance()->getConnection(); }
