<?php
require_once __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();require_once __DIR__.'/learning_sharing_fixture.php';$f=learningSharingFixture($db,$f);require_once __DIR__.'/../includes/exam_paper_service.php';
$actor=learningActor($db,$f['ids']['teacher']);$a=curriculumEnsureChapter($db,1,$f['class'],$f['subject'],'Mixed A');$b=curriculumEnsureChapter($db,1,$f['class'],$f['subject'],'Mixed B');
$base=['class_id'=>$f['class'],'subject_id'=>$f['subject'],'difficulty'=>'Medium','required_minutes'=>'1','visibility'=>'public'];$ids=[];
foreach([['MCQ','Mixed A','1'],['Short','Mixed A','2.25'],['Long','Mixed B','3.50']] as [$type,$chapter,$marks])$ids[]=learningSaveQuestion($db,$actor,$base+['type'=>$type,'chapter'=>$chapter,'marks'=>$marks,'question_text'=>'<p>Statement</p><p>(a) Part</p>','answer'=>'Model answer','option_a'=>$type==='MCQ'?'one':'','option_b'=>$type==='MCQ'?'two':'','option_c'=>$type==='MCQ'?'three':'','option_d'=>$type==='MCQ'?'four':'','correct_option'=>$type==='MCQ'?'A':''],null,'exam');
$config=['duration_minutes'=>'20','rows'=>[['chapter_id'=>$b,'type'=>'Long','count'=>1,'total_marks'=>'3.50'],['chapter_id'=>$a,'type'=>'MCQ','count'=>1,'total_marks'=>1],['chapter_id'=>$a,'type'=>'Short','count'=>1,'total_marks'=>'2.25'],['chapter_id'=>$b,'type'=>'Short','count'=>0,'total_marks'=>0]]];
$input=['generation_kind'=>'mixed','mixed_config_json'=>json_encode($config)];$before=$db->query('SELECT * FROM questions ORDER BY id')->fetchAll();
$paper=examGenerateChapterPaper($db,$actor,$f['class'],$f['subject'],$input);
erpEqual($paper['total_units'],675,'Mixed exact 1 + 2.25 + 3.50');erpEqual(count(array_unique(array_column($paper['questions'],'id'))),3,'No repeated IDs');
require_once __DIR__.'/../includes/exam_paper_groups.php';$groups=examPaperGroups($paper);erpEqual(array_column($groups,'type'),['MCQ','Short','Long'],'Type order not solver order');erpEqual(array_column($groups,'letter'),['A','B','C'],'Sequential letters');
erpEqual(examPaperGroups($paper,'Section')[0]['label'],'Section A — Multiple Choice Questions','Explicit section style');erpEqual($groups[0]['items'][0]['main_no'],1,'MCQs main question 1');erpEqual($groups[0]['items'][0]['sub_no'],1,'MCQ subnumber');erpEqual($groups[1]['items'][0]['main_no'],2,'Written continues numbering');
$onlyWritten=$paper;$onlyWritten['questions']=array_values(array_filter($paper['questions'],fn($q)=>$q['type']!=='MCQ'));erpEqual(examPaperGroups($onlyWritten)[0]['items'][0]['main_no'],1,'No MCQ written starts 1');erpEqual(examPaperGroups($onlyWritten)[0]['letter'],'A','No MCQ starts Group A');
erpEqual($db->query('SELECT * FROM questions ORDER BY id')->fetchAll(),$before,'Generation preserves stored content/marks');
$advancedInput=examChapterGridInput($db,$actor,$f['class'],$f['subject'],$input);$advanced=examGeneratePaper($db,$actor,$f['class'],$f['subject'],$advancedInput);erpEqual($advanced['total_units'],675,'Advanced repeated chapter rows retain exact quotas');erpEqual(array_column($advanced['rows'],'name'),array_column($advancedInput['rows'],'name'),'Audit retains advanced names');
erpEqual(array_sum(array_column($groups,'marks_units')),675,'Actual grouped marks sum exactly');
$allTypes=$paper;$allTypes['questions']=[];foreach(array_reverse(EXAM_TYPES) as $i=>$type)$allTypes['questions'][]=['id'=>1000+$i,'type'=>$type,'marks_units'=>100];erpEqual(array_column(examPaperGroups($allTypes),'type'),EXAM_TYPES,'All types use fixed order');
$badCases=[];$bad=$config;$bad['rows']=[];$badCases[]=$bad;
$bad=$config;foreach($bad['rows'] as &$r)$r['count']=0;unset($r);$badCases[]=$bad;
foreach([['MCQ'],['Long','Short']] as $types){$bad=$config;$bad['rows']=array_values(array_filter($bad['rows'],fn($r)=>in_array($r['type'],$types,true)));$badCases[]=$bad;}
foreach([['chapter_id',999999],['type','Written'],['count','1.5'],['total_marks','3.501']] as [$key,$value]){$bad=$config;$bad['rows'][0][$key]=$value;$badCases[]=$bad;}
$bad=$config;$bad['rows'][3]['chapter_id']=999999;$badCases[]=$bad;
$bad=$config;$bad['rows']=array_fill(0,51,$config['rows'][0]);$badCases[]=$bad;
$bad=$config;$bad['rows'][0]['count']=200;$badCases[]=$bad;
$bad=$config;$bad['rows'][0]['total_marks']=1000;$badCases[]=$bad;
$bad=$config;$bad['duration_minutes']='0.01';$badCases[]=$bad;
$bad=$config;$bad['rows'][0]['count']=2;$badCases[]=$bad;
foreach($badCases as $i=>$bad)erpReject(fn()=>examGenerateChapterPaper($db,$actor,$f['class'],$f['subject'],['generation_kind'=>'mixed','mixed_config_json'=>json_encode($bad)]),'Bad mixed allocation '.$i);
erpReject(fn()=>examGenerateChapterPaper($db,$actor,$f['class'],$f['subject'],['generation_kind'=>'mixed','mixed_config_json'=>str_repeat(' ',50001)]),'Oversize JSON');
$db->prepare("INSERT INTO questions(school_id,class_id,subject_id,chapter,type,question_text,marks,visibility,bank_kind) VALUES(1,?,?,'Mixed A','MCQ','Normal bank fallback must not be used',1,'public','learning')")->execute([$f['class'],$f['subject']]);
learningSetArchived($db,$actor,'questions',$ids[0],true,'exam');erpReject(fn()=>examGenerateChapterPaper($db,$actor,$f['class'],$f['subject'],$input),'No fallback to normal learning bank');
echo 'Mixed paper passed: '.$GLOBALS['erp_checks'].PHP_EOL;
