<?php
require_once __DIR__.'/test_support.php';['db'=>$db]=erpTestFixture();
erpEqual(SCHOOL_SCHEMA_VERSION,18,'Correction schema version');
erpCheck(in_array('open_key',dbColumns($db,'question_correction_requests'),true),'Correction table installed');
require_once __DIR__.'/../includes/question_correction_schema.php';
$before=$db->query('SELECT * FROM questions ORDER BY id')->fetchAll();migrateQuestionCorrections($db);migrateQuestionCorrections($db);
erpEqual($db->query('SELECT * FROM questions ORDER BY id')->fetchAll(),$before,'Idempotent migration preserves questions');
erpEqual((int)$db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn(),18,'Version dispatch complete');
erpCheck((bool)$db->query("SELECT sql FROM sqlite_master WHERE name='question_correction_requests'")->fetchColumn(),'Portable table exists');
// Recreate precisely the previous schema version. Existing runtime data must not
// be re-normalized just because this release adds an unrelated request table.
$db->exec('DROP TABLE question_correction_requests');
$db->exec("UPDATE app_meta SET meta_value='17' WHERE meta_key='workflow_schema'");
$db->exec("UPDATE subjects SET name_en='Compulsory English' WHERE id=(SELECT MIN(id) FROM subjects)");
$protected=[];foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name<>'app_meta'")->fetchAll(PDO::FETCH_COLUMN) as $table)$protected[$table]=$db->query('SELECT * FROM "'.$table.'" ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
migrateSchoolExtensions($db);
foreach($protected as $table=>$rows)erpCheck($db->query('SELECT * FROM "'.$table.'" ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC)===$rows,'Schema17 upgrade preserves every existing row: '.$table);
erpEqual((int)$db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn(),18,'Schema17 advances after additive upgrade');
erpCheck(in_array('open_key',dbColumns($db,'question_correction_requests'),true),'Schema17 correction table created');
echo 'Correction schema passed: '.$GLOBALS['erp_checks'].PHP_EOL;
