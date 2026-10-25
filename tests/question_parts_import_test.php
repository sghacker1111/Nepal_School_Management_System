<?php
require_once __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();
require_once __DIR__.'/learning_sharing_fixture.php';$f=learningSharingFixture($db,$f);
require_once __DIR__.'/../includes/exam_question_import.php';require_once __DIR__.'/../includes/exam_question_language.php';
$teacher=learningActor($db,$f['ids']['teacher']);
$chapter=curriculumEnsureChapter($db,1,$f['class'],$f['subject'],'Structured import');$topic=curriculumEnsureTopic($db,1,$chapter,'Grouped rows');
$selection=['import_class_id'=>$f['class'],'import_subject_id'=>$f['subject'],'import_chapter_id'=>$chapter,'import_topic_id'=>$topic];
$protected=[];foreach(['students','marks','exams','chapters','topics'] as $table)$protected[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach(['exam','learning'] as $bank)foreach(['normal','html'] as $mode)foreach(['other','mcq'] as $kind){
 [$headers,$rows]=examImportSample($db,$teacher,$mode,$f['class'],$f['subject'],$kind,$selection,$bank);
 erpCheck(count($headers)<64,'Samples stay inside safe workbook dimensions');
 $result=examImportQuestions($db,$teacher,[$headers,...$rows],$mode,'private',$kind,$selection,$bank);
 erpEqual($result['saved'],$kind==='mcq'?2:4,'Samples import multiple main questions '.$bank.' '.$mode.' '.$kind);
 erpEqual($result['skipped'],0,'Every sample row is valid');
 $qid=$result['ids'][$kind==='mcq'?1:2];$q=learningContentRecord($db,$teacher,'questions',$qid,$bank==='exam'?'exam_bank':'bank');$parts=questionPartsRead($q['question_text'],$q['question_text_np']);
 erpEqual(count($parts['parts']),$kind==='mcq'?1:3,'Rows grouped into exact subquestion count');
 erpEqual((float)$q['marks'],$kind==='mcq'?2.5:4.0,'Total marks are computed once per question');
 $html=examQuestionRich($q['question_text'],$q['question_text_np'],'both',true);$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($doc);
 $label=$xpath->query('//span[@class="exam-part-label"]')->item(0);erpCheck($label&&$label->parentNode instanceof DOMElement&&$label->parentNode->tagName==='p','Label is inside the Nepali text paragraph');
 erpCheck(str_contains($html,$kind==='mcq'?'(2.5U)':'(2KU)'),'Cognitive annotations retained');
 if($kind==='mcq'){
  erpEqual($q['correct_option'],'B','MCQ answer key retained');erpEqual(count(json_decode($q['options'],true)),4,'MCQ options retained');
  $edit=$q;foreach(['bank_kind','school_id','created_by_user_id'] as $field)unset($edit[$field]);learningSaveQuestion($db,$teacher,$edit,$qid,$bank);erpCheck(questionPartsRead(learningContentRecord($db,$teacher,'questions',$qid,$bank==='exam'?'exam_bank':'bank')['question_text'],$q['question_text_np'])!==null,'Imported MCQ can be edited without losing its structure');
 }
}
[$headers,$rows]=examImportSample($db,$teacher,'normal',$f['class'],$f['subject'],'other',$selection,'exam');$map=examImportHeaders($headers,'normal','other');
$partRows=array_slice($rows,2,3);$second=$partRows;foreach($second as &$r){$r[$map['question_group']]='WRITTEN-2';$r[$map['part_display']]='marks';}unset($r);
// Group rows may be interleaved; identities stay separate and in first-seen order.
$mixed=[$partRows[0],$second[0],$partRows[1],$second[1],$partRows[2],$second[2]];
$result=examImportQuestions($db,$teacher,[$headers,...$mixed],'normal','private','other',$selection);erpEqual($result['saved'],2,'Interleaved main questions are grouped independently');
$bad=$partRows;$bad[1][$map['marks']]='0';$before=(int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn();
$result=examImportQuestions($db,$teacher,[$headers,$rows[0],...$bad],'normal','private','other',$selection);erpEqual($result['saved'],1,'Independent good question retained');erpEqual($result['skipped'],3,'Every row of invalid group reported skipped');erpEqual((int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn(),$before+1,'No partial group saved');
foreach(['statement_en','required_minutes','type','part_display'] as $field){$bad=$partRows;$bad[1][$map[$field]]=$field==='required_minutes'?'9':($field==='type'?'Short':'Conflicting value');erpReject(fn()=>examImportQuestions($db,$teacher,[$headers,...$bad],'normal','private','other',$selection),'Conflicting grouped '.$field.' rejected');}
$bad=$partRows;$bad[1][$map['part_label']]='a';erpReject(fn()=>examImportQuestions($db,$teacher,[$headers,...$bad],'normal','private','other',$selection),'Duplicate group labels rejected');
$bad=$partRows;$bad[1][$map['subject']]='Unknown subject';$before=(int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn();erpReject(fn()=>examImportQuestions($db,$teacher,[$headers,$rows[0],...$bad],'normal','private','other',$selection),'Scope mismatch rejects entire batch');erpEqual((int)$db->query('SELECT COUNT(*) FROM questions')->fetchColumn(),$before,'Preflight never saves earlier questions');
// Header priority covers statements as well as subquestion rich text.
$dual=[...$headers,'Statement (English) HTML Source'];erpEqual(examImportHeaders($dual,'normal','other')['statement_en'],$map['statement_en'],'Normal statement column wins in normal mode');erpEqual(examImportHeaders($dual,'html','other')['statement_en'],count($headers),'HTML statement column wins in HTML mode');
$normal=$partRows;$normal[0][$map['question_text']]='Literal <script>alert(1)</script> < 2';$normal[1][$map['question_text']]='Continued';$result=examImportQuestions($db,$teacher,[$headers,...$normal],'normal','private','other',$selection);$q=$db->query('SELECT * FROM questions WHERE id='.$result['ids'][0])->fetch(PDO::FETCH_ASSOC);erpCheck(str_contains($q['question_text'],'&lt;script&gt;')&&!str_contains($q['question_text'],'<script>'),'Normal import treats HTML as literal text');
foreach($protected as $table=>$snapshot)erpEqual($db->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),$snapshot,'Grouped imports preserve '.$table);
echo 'Structured import checks passed: '.$GLOBALS['erp_checks'].PHP_EOL;
