<?php
require_once __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();require_once __DIR__.'/learning_sharing_fixture.php';$f=learningSharingFixture($db,$f);
require_once __DIR__.'/../includes/exam_paper_service.php';require_once __DIR__.'/../includes/exam_paper_groups.php';
$actor=learningActor($db,$f['ids']['teacher']);$chapter=curriculumEnsureChapter($db,1,$f['class'],$f['subject'],'Paper layout QA');
$base=['class_id'=>$f['class'],'subject_id'=>$f['subject'],'chapter'=>'Paper layout QA','difficulty'=>'Medium','required_minutes'=>'1','visibility'=>'public'];
foreach(['exam','learning'] as $bank)foreach([['MCQ',1],['Very Short',1],['Short',2],['Short',2],['Long',4]] as [$type,$marks])learningSaveQuestion($db,$actor,$base+['question_text'=>'Paper format '.$type,'type'=>$type,'marks'=>$marks,'option_a'=>$type==='MCQ'?'A':'','option_b'=>$type==='MCQ'?'B':'','option_c'=>$type==='MCQ'?'C':'','option_d'=>$type==='MCQ'?'D':'','correct_option'=>$type==='MCQ'?'A':''],null,$bank);
$row=static fn($type,$count,$marks)=>['chapter_ids'=>[$chapter],'type'=>$type,'count'=>$count,'total_marks'=>$marks];
$input=['duration_minutes'=>150,'sections'=>[['title'=>'Objective questions','rows'=>[$row('MCQ',1,1),$row('Very Short',1,1)]],['title'=>'Written questions','rows'=>[$row('Short',2,4),$row('Long',1,4)]]]];
$before=$db->query('SELECT * FROM questions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach(['exam','learning'] as $bank){
    $grid=paperSectionGridInput($input);$paper=examGeneratePaper($db,$actor,$f['class'],$f['subject'],$grid,false,$bank);
    erpEqual($paper['total_units'],1000,'Full marks calculated for '.$bank);$groups=examPaperGroups($paper);
    erpEqual(array_column($groups,'label'),['Group - A — Objective questions','Group - B — Written questions'],'Configured section order retained');
    erpEqual(array_column($groups,'calculation'),['(2 × 1 = 2)','(2 × 2 + 1 × 4 = 8)'],'Group calculation uses actual question marks');
    $numbers=[];foreach($groups as $group)foreach($group['items'] as $item)$numbers[]=$item['main_no'];erpEqual($numbers,[1,2,3,4,5],'Continuous numbering across mixed-type sections');
    erpEqual(examPaperGroups($paper,'Section')[1]['label'],'Section - B — Written questions','Section label option');
    $none=examPaperGroups($paper,'None');erpEqual(count($none),1,'None combines blocks without losing questions');erpEqual($none[0]['label'],'','No printed heading in None mode');erpEqual(count($none[0]['items']),5,'All questions retained in None mode');
    erpEqual(count(array_unique(array_column($paper['questions'],'id'))),5,'No repeated question');
    $header=paperFormatHeader(paperFormatInput(['paper_school_name'=>'Example <School>','paper_instructions'=>"Read carefully.\nAnswer all questions."]),[], 'First Terminal Examination','Class 10','Compulsory Mathematics',$paper);
    erpCheck(str_contains($header,'Example &lt;School&gt;')&&str_contains($header,'Class: 10')&&str_contains($header,'Time: 02:30 Hours')&&str_contains($header,'Full Marks: 10')&&str_contains($header,'Subject: Compulsory Mathematics'),'Header metadata and escaping');
}
erpEqual(paperGroupCalculation(array_fill(0,10,['marks_units'=>400])),'(10 × 4 = 40)','Requested equal-weight multiplication format');
erpEqual(paperDurationLabel('150.50'),'02:30:30 Hours','Fractional minutes are not silently lost');
foreach([['heading_style'=>'<script>'],['paper_school_name'=>[]],['paper_instructions'=>str_repeat('x',3001)]] as $bad)erpReject(fn()=>paperFormatInput($bad),'Invalid presentation rejected');
$bad=$input;$bad['sections']=[];erpReject(fn()=>paperSectionGridInput($bad),'Empty section list rejected');
$bad=$input;$bad['sections'][0]['rows'][0]['count']=0;$bad['sections'][0]['rows'][1]['count']=0;erpReject(fn()=>paperSectionGridInput($bad),'Empty active section rejected');
$bad=$input;$bad['sections'][0]['title']=str_repeat('x',81);erpReject(fn()=>paperSectionGridInput($bad),'Oversize section title rejected');
$bad=$input;$bad['sections'][0]['rows'][0]['chapter_ids']=[999999];erpReject(fn()=>examGeneratePaper($db,$actor,$f['class'],$f['subject'],paperSectionGridInput($bad)),'Foreign chapter rejected');
$bad=$input;$bad['sections'][1]['rows'][0]['count']=3;erpReject(fn()=>examGeneratePaper($db,$actor,$f['class'],$f['subject'],paperSectionGridInput($bad)),'Insufficient question pool rejected');
$bad=paperSectionGridInput($input);$bad['rows'][0]['section_title']='Conflict';erpReject(fn()=>examNormalizeGrid($db,$actor,$f['class'],$f['subject'],$bad),'Conflicting section names rejected');
erpEqual($db->query('SELECT * FROM questions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),$before,'Formatting and generation preserve every question');
echo 'Paper format passed: '.$GLOBALS['erp_checks'].' assertions; custom header, hours, sections, None, mixed types, exact marks, both banks and preservation.'.PHP_EOL;
