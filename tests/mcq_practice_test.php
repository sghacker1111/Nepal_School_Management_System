<?php
require __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f,'directory'=>$directory]=erpTestFixture();
require __DIR__.'/../includes/user_management.php';require __DIR__.'/../includes/auth.php';require __DIR__.'/../includes/mcq_practice.php';require_once __DIR__.'/../includes/student_learning.php';
$admin=$f['ids']['school_admin'];$class=$f['class'];$subject=$f['subject'];
// Recreate the previous users shape on a disposable copy, never the original.
$db->exec('VACUUM INTO '.$db->quote($directory.'/schema13.sqlite'));
$old=new PDO('sqlite:'.$directory.'/schema13.sqlite');$old->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$old->exec('DROP INDEX idx_users_student_learning');$old->exec('ALTER TABLE users DROP COLUMN student_record_id');
$old->exec("UPDATE app_meta SET meta_value='13' WHERE meta_key='workflow_schema'");
$before=[];foreach(['users','students','marks','ac_invoices','ac_payments','ac_journals','ac_journal_lines'] as $table)$before[$table]=$old->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
migrateSchoolExtensions($old);
foreach($before as $table=>$rows){$cols=array_column($old->query('PRAGMA table_info('.$table.')')->fetchAll(),'name');if($table==='users')$cols=array_diff($cols,['student_record_id']);erpEqual($old->query('SELECT '.implode(',',$cols).' FROM '.$table.' ORDER BY id')->fetchAll(),$rows,'Schema13 preserves '.$table);}
erpEqual($old->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn(),'15','Schema13 upgrade reaches15');
erpEqual((int)$old->query('SELECT COUNT(*) FROM users WHERE student_record_id IS NOT NULL')->fetchColumn(),0,'Does not invent student links');
migrateSchoolExtensions($old);erpEqual($old->query('PRAGMA integrity_check')->fetchColumn(),'ok','Repeated migration is safe');$old=null;
$input=['full_name'=>'Test Student','username'=>'student_practice','password'=>'TestPassword123!','role'=>'student','status'=>1,'student_record_id'=>$f['student']];
$studentUser=saveSchoolUser($db,$admin,'school_admin',1,$input);
$account=$db->query("SELECT * FROM users WHERE id=$studentUser")->fetch();
erpCheck(password_verify($input['password'],$account['password'])&&$input['password']!==$account['password'],'Student password is hashed');
erpEqual((int)$account['student_record_id'],$f['student'],'Student account links enrollment');
erpReject(fn()=>saveSchoolUser($db,$admin,'school_admin',1,[...$input,'username'=>'duplicate_student']),'One account per student');
erpReject(fn()=>saveSchoolUser($db,$admin,'school_admin',1,[...$input,'username'=>'no_link','student_record_id'=>'']),'Student link required');
erpReject(fn()=>saveSchoolUser($db,$admin,'school_admin',1,[...$input,'username'=>'foreign_link','student_record_id'=>$f['otherStudent']]),'Cross-school student link rejected');
erpReject(fn()=>saveSchoolUser($db,$f['ids']['teacher'],'teacher',1,$input),'Teacher cannot provision student access');
erpEqual(array_map('intval',array_column(learningClasses($db,1,'student',$studentUser),'id')),[$class],'Only enrolled class');
$optional=(int)$db->query("SELECT subject_id FROM class_subjects WHERE class_id=$class AND is_optional=1 LIMIT 1")->fetchColumn();
erpCheck(!in_array($optional,array_map('intval',array_column(learningSubjects($db,1,$class,'student',$studentUser),'id')),true),'Unselected optional subject not offered');
$db->prepare('UPDATE students SET optional_subject_i=? WHERE id=?')->execute([$optional,$f['student']]);
erpCheck(in_array($optional,array_map('intval',array_column(learningSubjects($db,1,$class,'student',$studentUser),'id')),true),'Selected optional subject available');
erpReject(fn()=>practiceScope($db,1,'student',$studentUser,$f['otherClass'],$subject),'Other school class rejected');
$q=$db->prepare("INSERT INTO questions(school_id,class_id,subject_id,type,chapter,question_text,options,options_np,correct_option,answer) VALUES(1,?,?,'MCQ','Sets',?,?,?,?,'Explanation')");
$ids=[];foreach(['A','B','C'] as $key){$q->execute([$class,$subject,'<p>Practice &amp; sets '.$key.'</p><script>alert(1)</script>',json_encode(['A'=>'One','B'=>'Two','C'=>'Three','D'=>'Four']),'{}',$key]);$ids[]=(int)$db->lastInsertId();}
$q->execute([$class,$subject,'Invalid options','{"A":"One"}','{}','A']);
$db->exec("UPDATE questions SET visibility='public'"); // Synthetic pre-published practice pool.
$pool=practicePool($db,1,'student',$studentUser,$class,$subject);$pool=array_values(array_filter($pool,fn($q)=>in_array($q['id'],$ids,true)));
erpEqual(count($pool),3,'Usable MCQs included');erpCheck(!str_contains($pool[0]['text'],'<script'),'Question HTML sanitized');
$catalog=practiceCatalog($pool);erpCheck(!isset($catalog[0]['key'])&&!isset($catalog[0]['options'])&&!isset($catalog[0]['explanation']),'Catalog has no answer keys');
$start=['class_id'=>$class,'subject_id'=>$subject,'selection_mode'=>'chosen','question_ids'=>$ids,'count'=>3,'search'=>'Practice & sets'];
$state=practiceStart($db,1,'student',$studentUser,$start);practiceValidateSession($db,1,'student',$studentUser,$state);
$orders=[];for($i=0;$i<12;$i++){$shuffled=practiceStart($db,1,'student',$studentUser,$start);$orders[implode(',',array_column($shuffled['questions'],'id'))]=true;}
erpCheck(count($orders)>1,'Selected questions are shuffled on each new practice');
erpEqual(count(array_unique(array_column($state['questions'],'id'))),3,'No duplicate questions in a practice');
$public=practicePublicState($state);erpCheck(!isset($public['question']['key'])&&!isset($public['feedback']),'Unanswered question has no answer key');
$answer=['token'=>$state['token'],'index'=>0,'question_id'=>$state['questions'][0]['id'],'choice'=>'D'];
erpReject(function()use(&$state,$answer){practiceTransition($state,'next',$answer);},'Must answer before next');
$feedback=practiceTransition($state,'answer',$answer);erpEqual($feedback['feedback']['correct'],false,'Wrong answer graded by server');
erpEqual(practiceTransition($state,'answer',$answer),$feedback,'Same-answer retry is idempotent');
erpReject(function()use(&$state,$answer){practiceTransition($state,'answer',[...$answer,'choice'=>'A']);},'Cannot change submitted answer');
erpEqual(practiceTransition($state,'next',$answer)['index'],1,'Next question advances once');
erpReject(function()use(&$state,$answer){practiceTransition($state,'next',$answer);},'Stale transition cannot skip questions');
for($i=1;$i<3;$i++){$answer=['token'=>$state['token'],'index'=>$i,'question_id'=>$state['questions'][$i]['id'],'choice'=>$state['questions'][$i]['key']];$feedback=practiceTransition($state,'answer',$answer);erpEqual($feedback['feedback']['correct'],true,'Correct answer graded');$public=practiceTransition($state,'next',$answer);}
erpEqual($public['score'],2,'Final score correct');erpEqual(count($public['review']),3,'Full answer review');erpEqual($public['complete'],true,'Completed state');
foreach([['count'=>0],['count'=>101],['count'=>'abc'],['question_ids'=>[$ids[0],$ids[0]],'count'=>2],['question_ids'=>[999999],'count'=>1],['class_id'=>[]],['search'=>[]],['selection_mode'=>'forged']] as $bad)erpReject(fn()=>practiceStart($db,1,'student',$studentUser,array_replace($start,$bad)),'Invalid practice input rejected');
erpReject(fn()=>practiceValidateSession($db,1,'student',$studentUser,[...$state,'expires'=>time()-1]),'Expired session rejected');
erpReject(fn()=>practiceValidateSession($db,1,'student',$admin,$state),'Different actor cannot use session');
$db->prepare("UPDATE students SET status='withdrawn' WHERE id=?")->execute([$f['student']]);erpReject(fn()=>studentLearningRecord($db,1,$studentUser),'Inactive enrollment revokes learning');
$db->prepare("UPDATE students SET status='active' WHERE id=?")->execute([$f['student']]);
saveSchoolUser($db,$admin,'school_admin',1,[...$input,'user_id'=>$studentUser,'password'=>'']);erpCheck(password_verify($input['password'],$db->query("SELECT password FROM users WHERE id=$studentUser")->fetchColumn()),'Editing preserves existing password');
echo 'MCQ practice service checks passed: '.$GLOBALS['erp_checks']."\n";
