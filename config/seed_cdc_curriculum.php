<?php
/** Safe, additive catalog setup. Never resets class, subject or student IDs. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/database.php';
require_once __DIR__.'/../includes/curriculum.php';
$db=getDB();
$db->beginTransaction();
try {
    ensureUniversalClasses($db);
    seedUniversalCurriculum($db);
    $db->commit();
    echo "Missing starter catalog entries added. Existing records preserved. Review school-specific CDC assessment rules before use.\n";
} catch(Throwable $e) {
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR, $e->getMessage()."\n");exit(1);
}
