<?php
require __DIR__.'/test_support.php';$t=erpTestFixture();$db=$t['db'];$f=$t['fixture'];
require __DIR__.'/learning_sharing_fixture.php';$f=learningSharingFixture($db,$f);
require __DIR__.'/../includes/exam_question_import.php';
$teacher=learningActor($db,$f['ids']['teacher']);$admin=learningActor($db,$f['ids']['super_admin']);
erpEqual(examImportClassId([['id'=>1,'name'=>'Class 1'],['id'=>10,'name'=>'Class 10'],['id'=>11,'name'=>'Class 11']], '1'),1,'Class 1 must not match 10/11');
erpEqual(examImportClassId([['id'=>1,'name'=>'Class 1'],['id'=>10,'name'=>'Class 10']], 'कक्षा १०'),10,'Nepali numeric class alias');
erpReject(fn()=>examImportClassId([['id'=>1,'name'=>'Class 1'],['id'=>10,'name'=>'Class 10']], 'Class 1 extra'),'No loose digit extraction');
erpReject(fn()=>examImportClassId([['id'=>1,'name'=>'Class 1'],['id'=>2,'name'=>'Grade 1']], '1'),'Ambiguous classes rejected');
$headers=['Class','Subject','Chapter','Topic','Type','Difficulty','Source','Cognitive Level','Marks','Required Minutes','Question (English)','Question (Nepali)','Answer (English)','Answer (Nepali)','Option A (English)','Option B (English)','Option C (English)','Option D (English)','Correct Option'];
$dual=[...$headers,'Question (English) HTML Source'];
erpEqual(examImportHeaders($dual,'normal')['question_text'],10,'Normal columns take priority in normal mode');
erpEqual(examImportHeaders($dual,'html')['question_text'],19,'HTML columns take priority in source mode');
erpReject(fn()=>examImportHeaders([...$dual,'Question English HTML'],'html'),'Duplicate HTML column rejected');
erpReject(fn()=>examImportHeaders([...$headers,'Bank Kind'],'normal'),'Cannot cross banks using a sheet');
erpReject(fn()=>examImportHeaders([...$headers,'Student Visible'],'normal'),'Cannot release questions using a sheet');
erpReject(fn()=>examImportHeaders(array_diff($headers,['Required Minutes']),'normal'),'Time header is required');
$class=$db->query('SELECT name FROM classes WHERE id='.$f['class'])->fetchColumn();$subject=$db->query('SELECT code FROM subjects WHERE id='.$f['subject'])->fetchColumn();
$chapter=curriculumEnsureChapter($db,1,$f['class'],$f['subject'],'Bulk unit');$topic=curriculumEnsureTopic($db,1,$chapter,'Bulk topic');
$row=[$class,$subject,'Bulk unit','Bulk topic','MCQ','Challenging','School','HA','0.25','0.50','Exam-only MCQ','परीक्षाको प्रश्न','Correct is B.','सही उत्तर B हो।','A','B','C','D','B'];
$result=examImportQuestions($db,$teacher,[$headers,$row],'normal','private');
erpEqual($result['saved'],1,'MCQ saves');$qid=$result['ids'][0];$q=$db->query('SELECT * FROM questions WHERE id='.$qid)->fetch();
erpEqual($q['bank_kind'],'exam','Exam bank only');erpEqual((int)$q['student_visible'],0,'Staff-only import');erpEqual($q['correct_option'],'B','Correct key saved');erpEqual((int)$q['created_by_user_id'],$teacher['id'],'Creator is signed-in user');erpEqual((float)$q['marks'],0.25,'Fractional marks retained');erpEqual((float)$q['required_minutes'],0.5,'Fractional time retained');
$bad=$row;$bad[18]='E';erpReject(fn()=>examImportQuestions($db,$teacher,[$headers,$bad],'normal','private'),'Invalid key is not guessed');
$bad=$row;$bad[16]='';erpReject(fn()=>examImportQuestions($db,$teacher,[$headers,$bad],'html','private'),'Missing MCQ option rejected');
$nepali=$row;$nepali[10]='';erpEqual(examImportQuestions($db,$teacher,[$headers,$nepali],'normal','private')['saved'],1,'Nepali-only question supported');
// Storage failures must undo earlier saved rows, including audit records.
$count=(int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn();$logs=(int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$db->exec("CREATE TRIGGER exam_import_failure BEFORE INSERT ON questions WHEN NEW.question_text='Storage fail' BEGIN SELECT RAISE(ABORT,'synthetic storage failure'); END");
$bad=$row;$bad[10]='Storage fail';
try{examImportQuestions($db,$teacher,[$headers,$row,$bad],'normal','private');throw new RuntimeException('Storage failure was ignored');}catch(PDOException $expected){erpCheck(true,'Unexpected storage error propagated');}
erpEqual((int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn(),$count,'Whole batch questions rolled back');erpEqual((int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),$logs,'Whole batch audit rolled back');$db->exec('DROP TRIGGER exam_import_failure');
// Super-admin may seed catalog names. Invalid rows cannot leave partial catalog.
$sharedClass=(int)$db->query("SELECT id FROM classes WHERE school_id=0 AND name='Class 10'")->fetchColumn();
$sharedSubject=$db->query("SELECT s.code FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=0 WHERE cs.class_id=$sharedClass LIMIT 1")->fetchColumn();
erpCheck((bool)$sharedSubject,'Shared subject fixture exists');
$shared=$row;$shared[0]='Class 10';$shared[1]=$sharedSubject;$shared[2]='New bulk catalog';$shared[3]='New bulk topic';$shared[6]='Shared';
$bad=$shared;$bad[2]='Rejected catalog';$bad[3]=str_repeat('x',256);
$result=examImportQuestions($db,$admin,[$headers,$shared,$bad],'html','public');erpEqual($result['saved'],1,'Super-admin imports shared exam content');erpEqual($result['skipped'],1,'Invalid shared row skipped');
erpEqual((int)$db->query("SELECT COUNT(*) FROM chapters WHERE name='Rejected catalog'")->fetchColumn(),0,'Row savepoint undoes rejected chapter creation');
erpEqual((int)$db->query("SELECT COUNT(*) FROM chapters WHERE name='New bulk catalog'")->fetchColumn(),1,'Shared chapter created once');
erpEqual((int)$db->query("SELECT COUNT(*) FROM topics WHERE name='New bulk topic'")->fetchColumn(),1,'Shared topic created once');
examImportQuestions($db,$admin,[$headers,$shared],'html','public');
erpEqual((int)$db->query("SELECT COUNT(*) FROM topics WHERE name='New bulk topic'")->fetchColumn(),1,'Repeated imports reuse topic identities');
$readOnly=$teacher;$readOnly['read_only']=true;erpReject(fn()=>examImportQuestions($db,$readOnly,[$headers,$row],'normal','private'),'Read-only import blocked');
$student=learningActor($db,$f['ids']['student_login']);erpReject(fn()=>examImportQuestions($db,$student,[$headers,$row],'normal','private'),'Student import blocked');
echo 'Exam question import service passed: '.$GLOBALS['erp_checks'].' checks; exact classes, headers, MCQs, rollback, shared catalog and ownership.'.PHP_EOL;
