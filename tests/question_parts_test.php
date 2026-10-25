<?php
require_once __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();
require_once __DIR__.'/learning_sharing_fixture.php';$f=learningSharingFixture($db,$f);
require_once __DIR__.'/../includes/exam_paper_service.php';require_once __DIR__.'/../includes/exam_question_language.php';require_once __DIR__.'/../includes/question_correction_service.php';
$base=['statement_en'=>'<p>A depositor invests Rs. 2,00,000 at 10%.</p>','statement_np'=>'<p>एक व्यक्तिले रु. 2,00,000 जम्मा गरे।</p>','display'=>'both','parts'=>[
 ['label'=>'a','en'=>'<p>State the formula \\(A=P(1+r)^n\\).</p>','np'=>'<p>सूत्र लेख्नुहोस्।</p>','marks'=>'1','level'=>'K'],
 ['label'=>'b','en'=>'<p>Find the interest.</p><p>Explain your calculation.</p>','np'=>'<p>ब्याज निकाल्नुहोस्।</p>','marks'=>'2.5','level'=>'KU'],
 ['label'=>'c','en'=>'<p>Compare the two years.</p>','np'=>'<p>दुई वर्षको तुलना गर्नुहोस्।</p>','marks'=>'1.25','level'=>'HA']]];
$structure=questionPartsNormalize($base,true);erpEqual($structure['total_units'],475,'Exact subquestion decimal total');
$english=questionPartsEncode($structure,'en');$nepali=questionPartsEncode($structure,'np');
erpEqual(questionPartsRead($english,$nepali),$structure,'Portable rich text roundtrip keeps structure and categories');
$html=examQuestionRich($english,$nepali,'both',true);
erpCheck(strpos($html,'एक व्यक्ति')<strpos($html,'A depositor'),'Statement prints Nepali before English');
erpCheck(strpos($html,'ब्याज')<strpos($html,'Find the interest.'),'Each part prints Nepali before English');
erpCheck(str_contains($html,'Explain your calculation. <span class="exam-part-score">(2.5KU)</span></p>'),'Suffix ends the last English paragraph');
erpEqual(substr_count($html,'exam-part-score'),3,'One suffix per part');
erpCheck(!str_contains($html,'lang="en">('),'Structured translations have separate lines without extra parentheses');
erpCheck(str_contains($html,'\\(A=P(1+r)^n\\)'),'Equations retain portable TeX');
erpCheck(str_contains($html,'<p><span class="exam-part-label">(a)</span> सूत्र'),'Subquestion label is inline with Nepali text');
erpCheck(str_contains(examQuestionPartLabelHtml('<div><p>Nested question</p></div>','(iv)'),'<div><p><span class="exam-part-label">(iv)</span> Nested question'),'Nested rich paragraph also keeps its label inline');
foreach(['both'=>'(2.5KU)','marks'=>'(2.5)','cognitive'=>'(KU)'] as $display=>$suffix){$copy=$structure;$copy['display']=$display;$render=examStructuredQuestionRich($copy,'en');erpCheck(str_contains($render,$suffix),'Saved suffix style '.$display);erpCheck(!str_contains($render,'ब्याज'),'English output is isolated');}
$ne=examQuestionRich($english,$nepali,'ne',true);erpCheck(str_contains($ne,'(2.5KU)')&&!str_contains($ne,'Find the interest.'),'Nepali-only output retains annotation');
$single=$base;$single['statement_en']='';foreach($single['parts'] as &$p)$p['en']='';unset($p);$single=questionPartsNormalize($single,true);
erpCheck(!str_contains(examQuestionRich(questionPartsEncode($single,'en'),questionPartsEncode($single,'np'),'en',true),'एक व्यक्ति'),'Absent English never copies Nepali into English');
$bad=$base;$bad['parts'][0]['level']='KU';erpReject(fn()=>questionPartsNormalize($bad,true),'KU is not the compulsory K');
$bad=$base;$bad['parts'][2]['level']='U';erpReject(fn()=>questionPartsNormalize($bad,true),'Mathematics requires final HA');
$bad=$base;$bad['parts'][1]['label']='a';erpReject(fn()=>questionPartsNormalize($bad),'Duplicate labels denied');
foreach(['0','-1','1.234',[],1001] as $invalid){$bad=$base;$bad['parts'][1]['marks']=$invalid;erpReject(fn()=>questionPartsNormalize($bad),'Invalid marks denied');}
$bad=$base;$bad['parts'][1]['level']='HACK';erpReject(fn()=>questionPartsNormalize($bad),'Unknown category denied');
$bad=$base;$bad['parts'][1]['en']='';$bad['parts'][1]['np']='';erpReject(fn()=>questionPartsNormalize($bad),'Empty bilingual subquestion denied');
$custom=$base;$custom['parts']=[['label'=>'iv','en'=>'Analyse this.','np'=>'','marks'=>'2','level'=>'KU']];erpEqual(questionPartsNormalize($custom)['parts'][0]['levels'],['KU'],'Other subjects permit custom labels and independent KU');
$bad=$base;$bad['parts'][0]['en']='<p onclick="bad()">Safe</p><script>alert(1)</script><img src="javascript:alert(1)">';$safe=questionPartsEncode(questionPartsNormalize($bad),'en');erpCheck(!str_contains($safe,'onclick')&&!str_contains($safe,'<script')&&!str_contains($safe,'javascript:'),'Rich subquestion bodies are sanitized');
erpReject(fn()=>questionPartsRead($english,str_replace('data-part-marks="2.5"','data-part-marks="2"',$nepali)),'Mismatched translated metadata denied');
$owner=learningActor($db,$f['ids']['teacher']);$chapter=curriculumEnsureChapter($db,1,$f['class'],$f['subject'],'Structured interest');$topic=curriculumEnsureTopic($db,1,$chapter,'Interest calculations');
$input=['class_id'=>$f['class'],'subject_id'=>$f['subject'],'chapter'=>'Structured interest','topic_id'=>$topic,'type'=>'Long','visibility'=>'public','required_minutes'=>'8','marks'=>'999','cognitive_domain'=>'Application','question_layout'=>'structured','statement_en'=>$base['statement_en'],'statement_np'=>$base['statement_np'],'subquestions'=>$base['parts']];
foreach(['learning','exam'] as $bank){
 $id=learningSaveQuestion($db,$owner,$input,null,$bank);$q=learningContentRecord($db,$owner,'questions',$id,$bank==='exam'?'exam_bank':'bank');
 erpEqual((float)$q['marks'],4.75,'Forged total overridden in '.$bank);$vector=questionCognitiveUnits($q);
 erpEqual($vector['Knowledge'],100,'K contribution independent');erpEqual($vector['Understanding'],0,'KU contributes nothing to U');erpEqual($vector['Knowledge and Understanding'],250,'KU gets all of its own marks');erpEqual($vector['Higher Ability'],125,'HA contribution independent');
 $filtered=learningContentList($db,$owner,'questions',['class_id'=>$f['class'],'subject_id'=>$f['subject'],'cognitive_domain'=>'Knowledge and Understanding'],$bank==='exam'?'exam_bank':'bank');erpCheck(in_array($id,array_column($filtered,'id')),'Filter sees subquestion category '.$bank);
 $grid=['full_marks'=>'4.75','duration_minutes'=>'20','rows'=>[['name'=>'Interest','type'=>'Long','chapter_ids'=>[$chapter],'count'=>1,'total_marks'=>'4.75']],'cognitive_marks'=>['K'=>1,'KU'=>'2.5','HA'=>'1.25']];
 $paper=examGeneratePaper($db,$owner,$f['class'],$f['subject'],$grid,false,$bank);erpEqual($paper['total_units'],475,'Paper uses automatic whole-question total');erpEqual($paper['cognitive_units']['Knowledge and Understanding'],250,'Paper allocates KU independently');
 $fingerprint=questionCorrectionFingerprint($q);$updated=array_replace($input,['subquestions'=>array_replace($base['parts'],[1=>array_replace($base['parts'][1],['marks'=>'3'])])]);learningSaveQuestion($db,$owner,$updated,$id,$bank);$q2=learningContentRecord($db,$owner,'questions',$id,$bank==='exam'?'exam_bank':'bank');erpCheck(questionCorrectionFingerprint($q2)!==$fingerprint,'Correction conflict includes part marks');
}
echo 'Structured question checks passed: '.$GLOBALS['erp_checks'].PHP_EOL;
