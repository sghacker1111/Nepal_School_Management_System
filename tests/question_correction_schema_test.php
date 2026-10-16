<?php
require_once __DIR__.'/test_support.php';['db'=>$db]=erpTestFixture();
erpEqual(SCHOOL_SCHEMA_VERSION,18,'Correction schema version');
erpCheck(in_array('open_key',dbColumns($db,'question_correction_requests'),true),'Correction table installed');
require_once __DIR__.'/../includes/question_correction_schema.php';
$before=$db->query('SELECT * FROM questions ORDER BY id')->fetchAll();migrateQuestionCorrections($db);migrateQuestionCorrections($db);
erpEqual($db->query('SELECT * FROM questions ORDER BY id')->fetchAll(),$before,'Idempotent migration preserves questions');
erpEqual((int)$db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn(),18,'Version dispatch complete');
erpCheck((bool)$db->query("SELECT sql FROM sqlite_master WHERE name='question_correction_requests'")->fetchColumn(),'Portable table exists');
echo 'Correction schema passed: '.$GLOBALS['erp_checks'].PHP_EOL;
