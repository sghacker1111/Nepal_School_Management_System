<?php
// Run ONLY against a disposable COPY of an existing database, never the original.
if(PHP_SAPI!=='cli' || !getenv('ERP_DB_PATH'))exit(1);
$path=realpath(getenv('ERP_DB_PATH'));
if(!$path || !str_starts_with(strtolower($path),strtolower(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)))throw new RuntimeException('Upgrade test requires a copy in the system temporary directory.');
$pdoClass=class_exists('Pdo\\Sqlite')?'Pdo\\Sqlite':'PDO';
$beforeDb=new $pdoClass('sqlite:'.$path);$counts=[];
foreach(['students','marks','users','attendances','exams'] as $table)$counts[$table]=(int)$beforeDb->query("SELECT COUNT(*) FROM $table")->fetchColumn();
$beforeDb=null;
putenv('ERP_RUNTIME=test');putenv('ERP_TEST_SQLITE=1');putenv('ERP_DB_TYPE=sqlite');
require __DIR__.'/../config/bootstrap.php';require __DIR__.'/../includes/database.php';
$db=getDB();
foreach($counts as $table=>$count)if($count!==(int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn())throw new RuntimeException("Upgrade changed $table record count");
if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Database integrity check failed');
if((int)$db->query('SELECT COUNT(*) FROM students s JOIN classes c ON c.id=s.class_id WHERE c.school_id<>s.school_id')->fetchColumn())throw new RuntimeException('Students still reference another school class');
if((int)$db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn()!==SCHOOL_SCHEMA_VERSION)throw new RuntimeException('Migration version missing');
$classCount=(int)$db->query('SELECT COUNT(*) FROM classes')->fetchColumn();migrateSchoolSchema($db);
migrateSchoolExtensions($db);
if($classCount!==(int)$db->query('SELECT COUNT(*) FROM classes')->fetchColumn())throw new RuntimeException('Migration is not idempotent');
if(!glob(dirname($path).'/.backups/*.sqlite'))throw new RuntimeException('Pre-upgrade backup missing');
$orphans=(int)$db->query('SELECT COUNT(*) FROM marks m WHERE NOT EXISTS(SELECT 1 FROM students s WHERE s.id=m.student_id) OR NOT EXISTS(SELECT 1 FROM exams e WHERE e.id=m.exam_id) OR NOT EXISTS(SELECT 1 FROM subjects s WHERE s.id=m.subject_id)')->fetchColumn();
echo 'Legacy upgrade passed; retained records: '.json_encode($counts)."; integrity, ownership, backup and idempotency verified. Existing orphan marks: $orphans (retained, not fabricated).\n";
