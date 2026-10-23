<?php
/** Persistent, native-PDO connection for synthetic HTTP fixture setup/assertions. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('ERP_TEST_MYSQL') !== '1' || getenv('ERP_DB_HOST') !== '127.0.0.1' || !str_starts_with(getenv('ERP_DB_NAME') ?: '', 'erp_migration_test_')) exit(1);
require_once __DIR__.'/../config/bootstrap.php';
require_once __DIR__.'/../includes/database.php';
$db = getDB();
while (($line = fgets(STDIN)) !== false) {
    try {
        $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $action = $message['action'] ?? 'query';
        if ($action === 'close') break;
        if ($action === 'commit') { if ($db->inTransaction()) $db->commit(); $result = []; }
        elseif ($action === 'rollback') { if ($db->inTransaction()) $db->rollBack(); $result = []; }
        else {
            $sql = $message['sql'];
            if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $sql) && !$db->inTransaction()) $db->beginTransaction();
            $statement = $db->prepare($sql);
            $statement->execute($message['parameters'] ?? []);
            $types=[];for($index=0;$index<$statement->columnCount();$index++)$types[]=($statement->getColumnMeta($index)['native_type']??'');
            $result = ['rows' => $statement->columnCount() ? $statement->fetchAll(PDO::FETCH_NUM) : [], 'types'=>$types, 'lastrowid' => (int)$db->lastInsertId(), 'rowcount' => $statement->rowCount()];
        }
        echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $error) {
        echo json_encode(['ok' => false, 'error' => get_class($error), 'code' => (string)$error->getCode()], JSON_THROW_ON_ERROR), "\n";
    }
    flush();
}
if ($db->inTransaction()) $db->rollBack();
