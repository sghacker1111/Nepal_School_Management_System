<?php
require_once __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();require_once __DIR__.'/learning_sharing_fixture.php';$f=learningSharingFixture($db,$f);
require_once __DIR__.'/../includes/exam_paper_service.php';require_once __DIR__.'/../includes/exam_question_language.php';require_once __DIR__.'/../includes/exam_question_import.php';
$admin=learningActor($db,$f['ids']['super_admin']);$teacher=learningActor($db,$f['ids']['teacher']);$before=[];foreach(['students','marks','exams','ac_payments','questions'] as $table)$before[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
erpReject(fn()=>mathematicsInstallDefaults($db,$teacher),'Teacher cannot install shared catalog');
$result=mathematicsInstallDefaults($db,$admin);erpEqual($result['grids_added'],9,'All nine source-backed defaults installed');erpEqual($result['classes'],2,'Both shared grades installed');
$again=mathematicsInstallDefaults($db,$admin);erpEqual($again['grids_added'],0,'Default grids never overwritten on reinstall');erpEqual($again['chapters_added'],0,'Chapter setup idempotent');
foreach($before as $table=>$rows)erpEqual($db->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),$rows,'Setup preserves '.$table);
$scope=$db->query("SELECT c.id class_id,s.id subject_id FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id WHERE c.school_id=0 AND c.name='Class 10' AND s.type='compulsory' AND s.name_en='Mathematics'")->fetch(PDO::FETCH_ASSOC);$class=(int)$scope['class_id'];$subject=(int)$scope['subject_id'];
$areas=mathematicsAreas($db,$admin,$class,$subject);erpEqual(count($areas),7,'Seven content areas');erpCheck(count($areas[1]['chapter_ids'])>=3,'Arithmetic has several chapters');erpCheck(count($areas[2]['chapter_ids'])>=4,'Mensuration has several chapters');
$refs=mathematicsReferences($db,$admin,$class,$subject);erpEqual(count($refs),4,'Class 10 CDC, First, Mid and Pre-Qualifying');
foreach(mathematicsData()['profiles'] as $profile){erpEqual(array_sum(array_column($profile['areas'],'marks')),75,'75 source marks '.$profile['key']);erpEqual(array_sum(array_column($profile['areas'],'question_count')),16,'16 source main questions '.$profile['key']);}
$preset=array_values(array_filter($refs,static fn($ref)=>$ref['key']==='pabson-cmath-2083-g10-prequal'))[0];$grid=$preset['config'];
// Build 49 separately tagged subquestions inside 16 context questions. These are
// disposable fixtures, not generated content inserted into an owner's database.
foreach($preset['source_areas'] as $i=>$area){$parts=array_fill(0,$area['question_count'],[]);$next=0;
    foreach($area['cognitive_items'] as $level=>$count){$marks=$area['cognitive_marks'][$level];for($j=0;$j<$count;$j++){$at=$next++%count($parts);$partMarks=$j===$count-1?$marks-($count-1):1;$parts[$at][]=['label'=>questionPartLabel(count($parts[$at])),'en'=>'<p>Source allocation fixture '.$level.'.</p>','np'=>'','marks'=>(string)$partMarks,'level'=>$level];}}
    $chapter=schoolRecord($db,'chapters',$grid['rows'][$i]['chapter_ids'][0],0);
    foreach($parts as $set)learningSaveQuestion($db,$admin,['class_id'=>$class,'subject_id'=>$subject,'chapter'=>$chapter['name'],'type'=>'Long','difficulty'=>'Medium','required_minutes'=>1,'visibility'=>'public','question_layout'=>'structured','mathematics_rule'=>'cdc','statement_en'=>'<p>Area fixture '.$area['area'].'.</p>','subquestions'=>$set],null,'exam');
}
$paper=examGeneratePaper($db,$admin,$class,$subject,$grid);erpEqual(count($paper['questions']),16,'Exact 16-question source paper');erpEqual($paper['total_units'],7500,'Exact 75 marks');
foreach($paper['rows'] as $i=>$row){$marks=[];$items=[];foreach($row['questions'] as $question){foreach(questionCognitiveUnits($question) as $key=>$value)$marks[$key]=($marks[$key]??0)+$value;foreach(questionCognitiveItems($question) as $key=>$value)$items[$key]=($items[$key]??0)+$value;}
    foreach($preset['source_areas'][$i]['cognitive_items'] as $level=>$count){erpEqual($items[EXAM_DOMAINS[$level]]??0,$count,'Per-area '.$row['name'].' '.$level.' item count');erpEqual($marks[EXAM_DOMAINS[$level]]??0,$preset['source_areas'][$i]['cognitive_marks'][$level]*100,'Per-area '.$row['name'].' '.$level.' marks');}
}
$bad=$grid;$bad['rows'][0]['cognitive_items']['K']=2;erpReject(fn()=>examGeneratePaper($db,$admin,$class,$subject,$bad),'Impossible area item target cannot silently pass');
$bad=$grid;$bad['rows'][0]['chapter_ids']=$grid['rows'][1]['chapter_ids'];erpReject(fn()=>examGeneratePaper($db,$admin,$class,$subject,$bad),'Area chapter mismatch rejected');
$changed=$areas;$changed[0]['name']='Set Theory';mathematicsSaveAreas($db,$admin,$class,$subject,$changed);erpEqual(mathematicsAreas($db,$admin,$class,$subject)[0]['name'],'Set Theory','Admin can change area display name');
$bad=$changed;$bad[1]['chapter_ids'][]=$bad[0]['chapter_ids'][0];erpReject(fn()=>mathematicsSaveAreas($db,$admin,$class,$subject,$bad),'Chapter cannot be assigned to two areas');erpReject(fn()=>mathematicsSaveAreas($db,$teacher,$class,$subject,$changed),'School users cannot edit global area mapping');
$again=mathematicsInstallDefaults($db,$admin);erpEqual($again['grids_added'],0,'Admin area/grid edits survive setup retry');erpEqual(mathematicsAreas($db,$admin,$class,$subject)[0]['name'],'Set Theory','Area customization survives setup');
$q=$paper['questions'][0];$structure=questionPartsRead($q['question_text'],$q['question_text_np'],true);erpEqual($structure['math_rule'],'cdc','CDC rule persists across both rich fields');
$chapter=$areas[0]['chapter_ids'][0];$topic=curriculumEnsureTopic($db,0,$chapter,'CDC import fixture');$selection=['import_class_id'=>$class,'import_subject_id'=>$subject,'import_chapter_id'=>$chapter,'import_topic_id'=>$topic];
foreach(['exam','learning'] as $bank)foreach(['normal','html'] as $mode){
    [$headers,$rows]=examImportSample($db,$admin,$mode,$class,$subject,'other',$selection,$bank);$map=examImportHeaders($headers,$mode,'other');$rows=array_slice($rows,2,2);
    foreach($rows as &$row)$row[$map['mathematics_rule']]='cdc';unset($row);
    $import=examImportQuestions($db,$admin,[$headers,...$rows],$mode,'public','other',$selection,$bank);$saved=$db->query('SELECT * FROM questions WHERE id='.$import['ids'][0])->fetch(PDO::FETCH_ASSOC);$parsed=questionPartsRead($saved['question_text'],$saved['question_text_np'],true);
    erpEqual($parsed['math_rule'],'cdc','CDC mathematics import mode retained '.$bank.' '.$mode);erpEqual(count($parsed['parts']),2,'CDC import permits source question without HA');
    foreach($rows as &$row)$row[$map['mathematics_rule']]='strict';unset($row);erpReject(fn()=>examImportQuestions($db,$admin,[$headers,...$rows],$mode,'public','other',$selection,$bank),'Same mathematics import rejects a strict question without HA');
}
foreach(['students','marks','exams','ac_payments'] as $table)erpEqual($db->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),$before[$table],'Generation preserves '.$table);
echo 'Mathematics catalog passed: '.$GLOBALS['erp_checks'].' checks; additive setup, all nine grids, areas, exact per-area marks/items, 16 main questions, KU isolation and admin-only writes.'.PHP_EOL;
