<?php
require __DIR__.'/test_support.php';$t=erpTestFixture();$db=$t['db'];$f=$t['fixture'];require __DIR__.'/learning_sharing_fixture.php';$f=learningSharingFixture($db,$f);
require_once __DIR__.'/../includes/learning_test_paper.php';require_once __DIR__.'/../includes/exam_question_import.php';
$actor=learningActor($db,$f['ids']['teacher']);$class=$f['class'];$subject=$f['subject'];
$c1=curriculumEnsureChapter($db,1,$class,$subject,'Learning allocation 1');$c2=curriculumEnsureChapter($db,1,$class,$subject,'Learning allocation 2');
$t1=curriculumEnsureTopic($db,1,$c1,'Learning topic A');$t2=curriculumEnsureTopic($db,1,$c1,'Learning topic B');$t3=curriculumEnsureTopic($db,1,$c2,'Learning topic C');
$base=['class_id'=>$class,'subject_id'=>$subject,'visibility'=>'public','cognitive_domain'=>'Knowledge','difficulty'=>'Easy','option_a'=>'1','option_b'=>'2','option_c'=>'3','option_d'=>'4','correct_option'=>'A'];$ids=[];
foreach([['Learning allocation 1',$t1,'MCQ',1],['Learning allocation 1',$t1,'MCQ',1],['Learning allocation 1',$t2,'MCQ',1],['Learning allocation 2',$t3,'Short',2.5]] as $i=>[$ch,$topic,$type,$marks]){
    $input=['chapter'=>$ch,'topic_id'=>$topic,'type'=>$type,'marks'=>$marks,'question_text'=>'Learning row '.$i]+$base;
    if($type!=='MCQ')foreach(['option_a','option_b','option_c','option_d','correct_option'] as $field)unset($input[$field]);
    $ids[]=learningSaveQuestion($db,$actor,$input);
}
$exam=learningSaveQuestion($db,$actor,['chapter'=>'Learning allocation 1','topic_id'=>$t1,'type'=>'MCQ','marks'=>1,'required_minutes'=>1,'question_text'=>'Do not leak exam item']+$base,null,'exam');
$before=$db->query('SELECT * FROM questions ORDER BY id')->fetchAll();
$rows=[['chapter_id'=>$c1,'topic_id'=>$t1,'type'=>'MCQ','count'=>2,'total_marks'=>2],['chapter_id'=>$c2,'topic_id'=>$t3,'type'=>'Short','count'=>1,'total_marks'=>2.5]];
$paper=learningTestPaper($db,$actor,$class,$subject,['duration_minutes'=>20,'rows'=>$rows]);
erpEqual(count($paper['questions']),3,'All row counts filled');erpEqual($paper['total_units'],450,'Exact decimal marks');erpEqual(count(array_unique(array_column($paper['questions'],'id'))),3,'No duplicate questions');
erpCheck(!in_array($exam,array_column($paper['questions'],'id'),true),'Exam bank never enters unit tests');
erpEqual($paper['required_units'],0,'Legacy untimed questions remain eligible');
erpReject(fn()=>learningTestPaper($db,$actor,$class,$subject,['rows'=>[array_replace($rows[0],['topic_id'=>$t3])]]),'Cannot use a topic from another chapter');
erpReject(fn()=>learningTestPaper($db,$actor,$class,$subject,['rows'=>[array_replace($rows[0],['count'=>3,'total_marks'=>3])]]),'Cannot silently borrow another topic');
erpReject(fn()=>learningTestPaper($db,$actor,$class,$subject,['rows'=>[array_replace($rows[0],['total_marks'=>1])]]),'No silent paper mark overrides');
$overlap=[array_replace($rows[0],['count'=>2,'total_marks'=>2]),array_replace($rows[0],['count'=>1,'total_marks'=>1])];
erpReject(fn()=>learningTestPaper($db,$actor,$class,$subject,['rows'=>$overlap]),'Overlapping pools cannot duplicate questions');
$chapterWide=learningTestPaper($db,$actor,$class,$subject,['rows'=>[array_replace($rows[0],['topic_id'=>'','count'=>3,'total_marks'=>3])]]);erpEqual(count($chapterWide['questions']),3,'Blank topic includes whole chapter');
erpReject(fn()=>learningTestPaper($db,$actor,$class,$subject,['rows'=>[['count'=>0]]]),'All zero rows rejected');
erpEqual($db->query('SELECT * FROM questions ORDER BY id')->fetchAll(),$before,'Generation preserves stored content, ownership and marks');
$timingEdit=['class_id'=>$class,'subject_id'=>$subject,'chapter'=>'Learning allocation 2','topic_id'=>$t3,'type'=>'Short','marks'=>2.5,'question_text'=>'Learning row 3','visibility'=>'public','required_minutes'=>'2.5'];
learningSaveQuestion($db,$actor,$timingEdit,$ids[3]);erpEqual((float)$db->query('SELECT required_minutes FROM questions WHERE id='.$ids[3])->fetchColumn(),2.5,'Optional learning timing can be set');
$withoutTiming=$timingEdit;unset($withoutTiming['required_minutes']);learningSaveQuestion($db,$actor,$withoutTiming,$ids[3]);erpEqual((float)$db->query('SELECT required_minutes FROM questions WHERE id='.$ids[3])->fetchColumn(),2.5,'Legacy clients that omit timing retain it');
$timingEdit['required_minutes']='';learningSaveQuestion($db,$actor,$timingEdit,$ids[3]);erpEqual($db->query('SELECT required_minutes FROM questions WHERE id='.$ids[3])->fetchColumn(),null,'Optional learning timing can be cleared explicitly');
// Exam samples/imports need only existing class, subject and chapter, even if
// the chapter has no topics at all.
$emptyChapter=curriculumEnsureChapter($db,1,$class,$subject,'Chapter without topics');$selection=['import_class_id'=>$class,'import_subject_id'=>$subject,'import_chapter_id'=>$emptyChapter];
foreach(['normal','html'] as $mode)foreach(['mcq','other'] as $kind){
    [$headers,$samples]=examImportSample($db,$actor,$mode,$class,$subject,$kind,$selection);
    erpCheck(!in_array('Topic',$headers,true),'Exam sample omits Topic');
    $result=examImportQuestions($db,$actor,[$headers,...$samples],$mode,'private',$kind,$selection);erpEqual($result['saved'],count($samples),'Chapter-only sample roundtrip');
    foreach($result['ids'] as $id)erpEqual($db->query('SELECT topic_id FROM questions WHERE id='.$id)->fetchColumn(),null,'Exam import binds no single topic');
}
erpReject(fn()=>examImportSample($db,$actor,'normal',$class,$subject,'mcq',$selection,'learning'),'Learning imports still require topic');
// Editing a legacy exam item through the topic-free form does not detach it.
$old=$db->query('SELECT * FROM questions WHERE id='.$exam)->fetch();$edit=['class_id'=>$class,'subject_id'=>$subject,'chapter'=>'Learning allocation 1','type'=>'MCQ','marks'=>1,'required_minutes'=>1,'question_text'=>'Edited legacy exam']+$base;
learningSaveQuestion($db,$actor,$edit,$exam,'exam');$updated=$db->query('SELECT * FROM questions WHERE id='.$exam)->fetch();erpEqual($updated['topic_id'],$old['topic_id'],'Legacy exam topic reference retained on edit');
echo 'Learning test papers/chapter-only exam import passed: '.$GLOBALS['erp_checks'].' checks.'.PHP_EOL;
