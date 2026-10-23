<?php
// Never use the configured school database for regression tests.
$path = tempnam(sys_get_temp_dir(), 'erp-test-');
putenv('ERP_RUNTIME=test');putenv('ERP_TEST_SQLITE=1');putenv('ERP_DB_TYPE=sqlite');
putenv('ERP_DB_PATH='.$path);
require_once __DIR__.'/../config/bootstrap.php';
require_once __DIR__.'/../includes/database.php';
require_once __DIR__.'/../includes/school_validation.php';
$db=getDB();
$checks=0;
function expectWorkflow($ok,$message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function rejectWorkflow(callable $call,$message) { try {$call();} catch(InvalidArgumentException $e) {return;} throw new RuntimeException($message); }
$db->exec("INSERT INTO schools (name_en) VALUES ('Second school')");
$second=(int)$db->lastInsertId();
provisionSchool($db,$second);
$one=(int)$db->query("SELECT id FROM classes WHERE school_id=1 AND name='Class 9'")->fetchColumn();
$two=(int)$db->query("SELECT id FROM classes WHERE school_id=$second AND name='Class 9'")->fetchColumn();
expectWorkflow($one>0 && $two>0 && $one!==$two,'Schools need independent classes');
$before=(int)$db->query('SELECT COUNT(*) FROM classes')->fetchColumn();
provisionSchool($db,$second);
expectWorkflow($before===(int)$db->query('SELECT COUNT(*) FROM classes')->fetchColumn(),'Provisioning must be idempotent');
rejectWorkflow(fn()=>validateSchoolReferences($db,1,'students',['class_id'=>$two]),'Cross-school classes must be rejected');
$db->prepare('INSERT INTO sections (class_id,name,school_id) VALUES (?, ?, ?)')->execute([$two,'A',$second]);
$section=(int)$db->lastInsertId();
rejectWorkflow(fn()=>validateSchoolReferences($db,1,'students',['class_id'=>$one,'section_id'=>$section]),'Cross-school sections must be rejected');
rejectWorkflow(fn()=>validateSchoolReferences($db,1,'subjects',['full_marks'=>100,'pass_marks'=>101]),'Excess pass marks must be rejected');
rejectWorkflow(fn()=>validateSchoolReferences($db,1,'subjects',['th_full_marks'=>-1]),'Negative full marks must be rejected');
validateSchoolReferences($db,1,'subjects',['full_marks'=>75,'pass_marks'=>26.25]);
echo "Workflow migration and relationship checks passed ($checks assertions plus rejection checks)\n";
