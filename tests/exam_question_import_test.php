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
$selection=['import_class_id'=>$f['class'],'import_subject_id'=>$f['subject'],'import_chapter_id'=>$chapter,'import_topic_id'=>$topic];
$import=static function($who,$rows,$mode='normal',$visibility='private',$kind='mcq')use($db,&$selection){return examImportQuestions($db,$who,$rows,$mode,$visibility,$kind,$selection);};
$row=[$class,$subject,'Bulk unit','Bulk topic','MCQ','Challenging','School','HA','0.25','0.50','Exam-only MCQ','परीक्षाको प्रश्न','Correct is B.','सही उत्तर B हो।','A','B','C','D','B'];
$result=$import($teacher,[$headers,$row]);
erpEqual($result['saved'],1,'MCQ saves');$qid=$result['ids'][0];$q=$db->query('SELECT * FROM questions WHERE id='.$qid)->fetch();
erpEqual($q['bank_kind'],'exam','Exam bank only');erpEqual((int)$q['student_visible'],0,'Staff-only import');erpEqual($q['correct_option'],'B','Correct key saved');erpEqual((int)$q['created_by_user_id'],$teacher['id'],'Creator is signed-in user');erpEqual((float)$q['marks'],0.25,'Fractional marks retained');erpEqual((float)$q['required_minutes'],0.5,'Fractional time retained');
$bad=$row;$bad[18]='E';erpReject(fn()=>$import($teacher,[$headers,$bad]),'Invalid key is not guessed');
$bad=$row;$bad[16]='';erpReject(fn()=>$import($teacher,[$headers,$bad],'html'),'Missing MCQ option rejected');
$nepali=$row;$nepali[10]='';erpEqual($import($teacher,[$headers,$nepali])['saved'],1,'Nepali-only question supported');
// Storage failures must undo earlier saved rows, including audit records.
$count=(int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn();$logs=(int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$db->exec("CREATE TRIGGER exam_import_failure BEFORE INSERT ON questions WHEN NEW.question_text='Storage fail' BEGIN SELECT RAISE(ABORT,'synthetic storage failure'); END");
$bad=$row;$bad[10]='Storage fail';
try{$import($teacher,[$headers,$row,$bad]);throw new RuntimeException('Storage failure was ignored');}catch(PDOException $expected){erpCheck(true,'Unexpected storage error propagated');}
erpEqual((int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn(),$count,'Whole batch questions rolled back');erpEqual((int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),$logs,'Whole batch audit rolled back');$db->exec('DROP TRIGGER exam_import_failure');
// Super-admin imports must also use pre-existing selected catalog records.
$sharedClass=(int)$db->query("SELECT id FROM classes WHERE school_id=0 AND name='Class 10'")->fetchColumn();
$sharedSubject=$db->query("SELECT s.code FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=0 WHERE cs.class_id=$sharedClass LIMIT 1")->fetchColumn();
erpCheck((bool)$sharedSubject,'Shared subject fixture exists');
$shared=$row;$shared[0]='Class 10';$shared[1]=$sharedSubject;$shared[2]='New bulk catalog';$shared[3]='New bulk topic';$shared[6]='Shared';
$teacherSelection=$selection;
erpReject(fn()=>$import($admin,[$headers,$shared],'html','public'),'No automatic catalog creation for super admin');
erpEqual((int)$db->query("SELECT COUNT(*) FROM chapters WHERE name='New bulk catalog'")->fetchColumn(),0,'Import cannot create a shared chapter');
$sharedSubjectId=(int)$db->query("SELECT s.id FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=0 WHERE cs.class_id=$sharedClass LIMIT 1")->fetchColumn();
$sharedChapter=curriculumEnsureChapter($db,0,$sharedClass,$sharedSubjectId,'New bulk catalog');$sharedTopic=curriculumEnsureTopic($db,0,$sharedChapter,'New bulk topic');
$selection=['import_class_id'=>$sharedClass,'import_subject_id'=>$sharedSubjectId,'import_chapter_id'=>$sharedChapter,'import_topic_id'=>$sharedTopic];
$result=$import($admin,[$headers,$shared],'html','public');erpEqual($result['saved'],1,'Super-admin imports into an existing shared catalog');
$import($admin,[$headers,$shared],'html','public');
erpEqual((int)$db->query("SELECT COUNT(*) FROM topics WHERE name='New bulk topic'")->fetchColumn(),1,'Repeated imports reuse topic identities');
$selection=$teacherSelection;
$readOnly=$teacher;$readOnly['read_only']=true;erpReject(fn()=>$import($readOnly,[$headers,$row]),'Read-only import blocked');
$student=learningActor($db,$f['ids']['student_login']);erpReject(fn()=>$import($student,[$headers,$row]),'Student import blocked');
$otherHeaders=array_slice($headers,0,14);$otherRow=array_slice($row,0,14);$otherRow[4]='Short';
erpReject(fn()=>examImportKind(null),'Missing category rejected');erpReject(fn()=>examImportKind('mixed'),'Mixed category unavailable');
erpReject(fn()=>examImportHeaders($headers,'normal','other'),'Other Questions has no option columns');
erpReject(fn()=>examImportHeaders($otherHeaders,'html','mcq'),'MCQs need all four option headers');
erpReject(fn()=>examImportHeaders(array_slice($headers,0,18),'normal','mcq'),'MCQs need a correct key header');
erpReject(fn()=>$import($teacher,[$headers,$otherRow]),'Wrong row type cannot enter MCQs');
$wrongOther=$otherRow;$wrongOther[4]='MCQ';erpReject(fn()=>$import($teacher,[$otherHeaders,$wrongOther],'normal','private','other'),'MCQ rows cannot enter written importer');
$otherResult=$import($teacher,[$otherHeaders,$otherRow],'html','private','other');$other=$db->query('SELECT * FROM questions WHERE id='.$otherResult['ids'][0])->fetch();
erpEqual($other['type'],'Short','Written type is preserved');erpEqual($other['options'],null,'No English options on written imports');erpEqual($other['options_np'],null,'No Nepali options on written imports');erpEqual($other['correct_option'],null,'No answer key on written imports');
foreach(['normal','html'] as $mode)foreach(['mcq','other'] as $kind){
    [$sampleHeaders,$sampleRows]=examImportSample($db,$teacher,$mode,$f['class'],$f['subject'],$kind,$selection);
    erpEqual(count($sampleHeaders),$kind==='mcq'?30:21,'Chapter-only template includes all metadata and bilingual part-answer columns '.$kind.' '.$mode);
    erpEqual(count($sampleRows),$kind==='mcq'?2:6,'Separate template example count '.$kind.' '.$mode);
    if($kind==='other')erpEqual(array_column($sampleRows,3),['Very Short','Short','Long','Long','Long','Difference Between'],'All requested written types shown');
}
// A metadata mismatch after a good row rejects the entire file without writes.
$before=(int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn();
foreach([0,1,2] as $index){$bad=$row;$bad[$index]='Unmatched value';erpReject(fn()=>$import($teacher,[$headers,$row,$bad]),'Whole batch metadata mismatch');erpEqual((int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn(),$before,'No earlier row saved');}
foreach(array_keys($selection) as $field){$badSelection=$selection;unset($badSelection[$field]);erpReject(fn()=>questionImportContext($db,$teacher,$badSelection),'All four selections required');}
erpReject(fn()=>examImportQuestions($db,$teacher,[$headers,$row],'normal','private'),'No selection bypass at service level');
$db->prepare('UPDATE chapters SET name_np=? WHERE id=?')->execute(['आयात अध्याय',$chapter]);$db->prepare('UPDATE topics SET name_np=? WHERE id=?')->execute(['आयात विषय',$topic]);
$npLabels=$row;$npLabels[2]='आयात अध्याय';$npLabels[3]='आयात विषय';
$npImport=$import($teacher,[$headers,$npLabels]);erpEqual($npImport['saved'],1,'Existing Nepali catalog labels match the same selected IDs');
erpEqual($db->query('SELECT chapter FROM questions WHERE id='.$npImport['ids'][0])->fetchColumn(),'Bulk unit','Nepali labels keep the canonical existing chapter identity');
echo 'Exam question import service passed: '.$GLOBALS['erp_checks'].' checks; separate categories, exact classes, headers, MCQs, written answers, rollback, shared catalog and ownership.'.PHP_EOL;
