<?php
require_once __DIR__.'/learning_content_service.php';
require_once __DIR__.'/exam_mixed_paper.php';
require_once __DIR__.'/paper_format.php';
const EXAM_DOMAINS=QUESTION_PART_LEVELS;
const EXAM_TYPES=['MCQ','Very Short','Short','Long','Reasoning','Difference Between'];
function examCount(mixed $value,string $label,bool $zero=false):int {
    if(!is_scalar($value)||!preg_match('/^\d{1,3}$/',(string)$value)||(int)$value<($zero?0:1)||(int)$value>200)throw new InvalidArgumentException($label.' must be '.($zero?'0':'1').'–200.');return (int)$value;
}
function examPaperScope(PDO $db,array $actor,int $class,int $subject,bool $write=false,string $bankKind='exam'):void {
    if(!in_array($bankKind,['exam','learning'],true))throw new InvalidArgumentException('Invalid paper bank.');
    if(!learningPurposeAllowed($db,$actor,$bankKind==='exam'?'exam_paper':'paper'))throw new InvalidArgumentException('Your school package does not allow this paper generator.');
    if(!in_array($subject,array_map('intval',array_column(learningReadSubjects($db,$actor,$class),'id')),true))throw new InvalidArgumentException('Choose a visible subject in the selected class.');
    if(($write&&$actor['read_only'])||($actor['role']==='teacher'&&!teacherOwnsSubject($db,$actor['school_id'],$actor['id'],$class,$subject)))throw new InvalidArgumentException('Teachers may generate and manage grids only for their assigned class subjects.');
}
function examNormalizeGrid(PDO $db,array $actor,int $class,int $subject,array $input,bool $chapterMode=false,string $bankKind='exam'):array {
    examPaperScope($db,$actor,$class,$subject,false,$bankKind);
    $full=learningDecimalUnits($input['full_marks']??null,100000,'Full marks');$minutes=learningDecimalUnits($input['duration_minutes']??null,60000,'Exam duration');
    $catalog=learningCatalogChapters($db,$actor,$class,$subject);$names=[];foreach($catalog as $c)foreach($c['alias_ids'] as $id)$names[(int)$id]=$c;
    $resolve=function($ids,string $label)use($names):array {if(!is_array($ids)||!array_is_list($ids)||!count($ids)||count($ids)>100)throw new InvalidArgumentException('Select chapters for '.$label.'.');$found=[];foreach($ids as $id){$id=learningId($id);if(!isset($names[$id]))throw new InvalidArgumentException('A chapter in '.$label.' is unavailable in this class subject.');$found[$names[$id]['id']]=$names[$id];}return array_values($found);};
    if(!is_array($input['rows']??null)||!array_is_list($input['rows'])||count($input['rows'])<1||count($input['rows'])>50)throw new InvalidArgumentException('Add 1–50 question allocation rows.');
    $rows=[];$total=0;$count=0;$sectionTitles=[];$sectionRows=0;
    foreach($input['rows'] as $i=>$row){
        if(!is_array($row))throw new InvalidArgumentException('Invalid question allocation row.');
        $name=$row['name']??('Section '.($i+1));if(!is_string($name)||trim($name)===''||mb_strlen($name)>100)throw new InvalidArgumentException('Enter a section name of at most 100 characters.');
        $sectionId=$row['section_id']??null;$sectionTitle=$row['section_title']??'';
        if($sectionId!==null){$sectionId=learningId($sectionId);if($sectionId>26||!is_string($sectionTitle)||mb_strlen($sectionTitle)>80)throw new InvalidArgumentException('Choose a valid paper section.');$sectionTitle=trim($sectionTitle);if(isset($sectionTitles[$sectionId])&&$sectionTitles[$sectionId]!==$sectionTitle)throw new InvalidArgumentException('Use the same title for every allocation in a section.');$sectionTitles[$sectionId]=$sectionTitle;$sectionRows++;}
        $type=$row['type']??'';if(!is_string($type)||(!in_array($type,EXAM_TYPES,true)&&!($chapterMode&&$type==='Written')))throw new InvalidArgumentException('Choose a valid question type.');
        $chapters=$resolve($row['chapter_ids']??[],trim($name));$quantity=examCount($row['count']??null,'Question count');$marks=learningDecimalUnits($row['total_marks']??null,100000,'Section marks');
        $each=($row['marks_each']??'')!==''&&$row['marks_each']!==null?learningDecimalUnits($row['marks_each'],100000,'Marks per question'):null;
        if($each!==null&&$each*$quantity!==$marks)throw new InvalidArgumentException('Count × marks per question must equal section marks in '.$name.'.');
        $difficulty=$row['difficulty']??'';if(!is_string($difficulty)||!in_array($difficulty,['','Easy','Medium','Hard','Challenging'],true))throw new InvalidArgumentException('Choose a valid difficulty.');
        $domains=$row['domains']??[];if(!is_array($domains)||array_diff(array_keys($domains),array_keys(EXAM_DOMAINS)))throw new InvalidArgumentException('Use K, U, A, HA and KU domain counts.');
        $quota=[];foreach(EXAM_DOMAINS as $key=>$label)$quota[$key]=examCount($domains[$key]??0,$key.' count',true);if(array_sum($quota)>0&&array_sum($quota)!==$quantity)throw new InvalidArgumentException('K + U + A + HA + KU must equal the question count in '.$name.'.');
        // Saved exam grids may contain old topic IDs. Preserve their stored JSON,
        // but chapter-wide exam generation no longer applies those constraints.
        $topicIds=$bankKind==='learning'?($row['topic_ids']??[]):[];if(!is_array($topicIds)||!array_is_list($topicIds)||count($topicIds)>100)throw new InvalidArgumentException('Select valid topic filters.');$availableTopics=[];
        if($topicIds)foreach($chapters as $c)foreach(learningCatalogTopics($db,$actor,$class,$subject,(int)$c['id']) as $t)foreach($t['alias_ids'] as $id)$availableTopics[(int)$id]=$t['alias_ids'];
        $expandedTopics=[];foreach($topicIds as $id){$id=learningId($id);if(!isset($availableTopics[$id]))throw new InvalidArgumentException('Choose topics within the row chapters.');foreach($availableTopics[$id] as $alias)$expandedTopics[(int)$alias]=true;}$topicIds=array_keys($expandedTopics);
        $rows[]=['name'=>trim($name),'section_id'=>$sectionId,'section_title'=>$sectionId===null?'':$sectionTitle,'type'=>$type,'chapter_ids'=>array_map('intval',array_column($chapters,'id')),'chapter_names'=>array_column($chapters,'name'),'topic_ids'=>array_values(array_unique($topicIds)),'count'=>$quantity,'total_marks'=>learningDecimalString($marks),'total_units'=>$marks,'marks_each'=>$each===null?null:learningDecimalString($each),'each_units'=>$each,'difficulty'=>$difficulty,'domains'=>$quota];$total+=$marks;$count+=$quantity;
    }
    if($total!==$full||$count>200)throw new InvalidArgumentException('Section totals must equal full marks; the paper may contain at most 200 questions.');
    if($sectionRows&&$sectionRows!==count($rows))throw new InvalidArgumentException('Assign every allocation to a section, or leave all section assignments blank.');
    $targets=[];$targetNames=[];$targetInput=$input['chapter_targets']??[];if(!is_array($targetInput)||!array_is_list($targetInput)||count($targetInput)>100)throw new InvalidArgumentException('Choose valid chapter targets.');
    foreach($targetInput as $t){if(!is_array($t))throw new InvalidArgumentException('Invalid chapter target.');$selected=$resolve([$t['chapter_id']??null],'chapter target');$c=$selected[0];if(isset($targetNames[$c['name']]))throw new InvalidArgumentException('Use one target per chapter.');$targetNames[$c['name']]=true;
        $n=($t['count']??'')!==''&&$t['count']!==null?examCount($t['count'],'Chapter question count',true):null;
        $m=($t['marks']??'')!==''&&$t['marks']!==null?learningDecimalUnits($t['marks'],100000,'Chapter marks',true):null;
        if($n===null&&$m===null)continue;$targets[]=['chapter_id'=>(int)$c['id'],'name'=>$c['name'],'count'=>$n,'marks'=>$m===null?null:learningDecimalString($m),'units'=>$m];
    }
    $domainInput=$input['cognitive_marks']??[];if(!is_array($domainInput)||array_diff(array_keys($domainInput),array_keys(EXAM_DOMAINS)))throw new InvalidArgumentException('Choose valid cognitive mark targets.');$domainMarks=[];
    if(array_filter($domainInput,static fn($v)=>$v!==''&&$v!==null)){foreach(EXAM_DOMAINS as $key=>$label)$domainMarks[$key]=learningDecimalUnits($domainInput[$key]??0,100000,$key.' marks',true);if(array_sum($domainMarks)!==$full)throw new InvalidArgumentException('Cognitive mark targets must total the full marks.');}
    $reference=$input['reference_key']??'';if(!is_string($reference))throw new InvalidArgumentException('Choose a valid reference grid.');if($reference&&!in_array($reference,array_column(examPaperReferences($db,$actor,$class,$subject),'key'),true))throw new InvalidArgumentException('That CDC reference does not match this class subject.');
    return ['version'=>1,'reference_key'=>$reference,'full_marks'=>learningDecimalString($full),'full_units'=>$full,'duration_minutes'=>learningDecimalString($minutes),'duration_units'=>$minutes,'rows'=>$rows,'chapter_targets'=>$targets,'cognitive_marks'=>array_map('learningDecimalString',$domainMarks),'cognitive_units'=>$domainMarks,'question_count'=>$count];
}
function examGridInputJson(mixed $value):array {
    if(!is_string($value)||strlen($value)>50000)throw new InvalidArgumentException('Grid configuration must be valid JSON of at most 50 KB.');
    try{$data=json_decode($value,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new InvalidArgumentException('The grid configuration is invalid. Reload the editor.');}
    if(!is_array($data))throw new InvalidArgumentException('Invalid grid configuration.');return $data;
}
/** Build a server-owned grid from the simple chapter table; never change bank marks. */
function examChapterGridInput(PDO $db,array $actor,int $class,int $subject,array $input):array {
    examPaperScope($db,$actor,$class,$subject);
    $kind=$input['generation_kind']??'';
    if($kind==='mixed')return examMixedGridInput($db,$actor,$class,$subject,examGridInputJson($input['mixed_config_json']??null));
    if(!is_string($kind)||!in_array($kind,['written','mcq'],true))throw new InvalidArgumentException('Choose Written Questions or MCQs.');
    $allocations=$input['chapters']??[];
    if(!is_array($allocations)||!count($allocations)||count($allocations)>100)throw new InvalidArgumentException('Choose chapter allocations in this class subject.');
    $catalog=[];foreach(learningCatalogChapters($db,$actor,$class,$subject) as $chapter)foreach($chapter['alias_ids'] as $id)$catalog[(int)$id]=$chapter;
    $rows=[];$seen=[];$total=0;
    foreach($allocations as $id=>$allocation){
        $id=learningId($id);$chapter=$catalog[$id]??null;
        if(!$chapter||!is_array($allocation))throw new InvalidArgumentException('Choose only chapters from the selected class subject.');
        $canonical=(int)$chapter['id'];if(isset($seen[$canonical]))throw new InvalidArgumentException('Use one allocation per chapter.');$seen[$canonical]=true;
        $quantity=examCount($allocation['count']??0,'Chapter question count',true);
        $topics=[]; // Exam questions can combine any topics in the chapter.
        if(!$quantity)continue;
        $marks=learningDecimalUnits($allocation['marks']??null,100000,'Chapter marks');$total+=$marks;
        $rows[]=['name'=>mb_substr($chapter['name'],0,100),'type'=>$kind==='mcq'?'MCQ':'Written','chapter_ids'=>[$canonical],'topic_ids'=>$topics,'count'=>$quantity,'total_marks'=>learningDecimalString($marks)];
    }
    if(!$rows)throw new InvalidArgumentException('Enter a question count greater than 0 for at least one chapter.');
    return ['full_marks'=>learningDecimalString($total),'duration_minutes'=>$input['duration_minutes']??null,'rows'=>$rows];
}
function examGenerateChapterPaper(PDO $db,array $actor,int $class,int $subject,array $input):array {
    return examGeneratePaper($db,$actor,$class,$subject,examChapterGridInput($db,$actor,$class,$subject,$input),true);
}
/** Only a visually verified CDC reference is bundled; other subjects use administrator grids. */
function examPaperReferences(PDO $db,array $actor,int $class,int $subject):array {
    examPaperScope($db,$actor,$class,$subject);$q=$db->prepare('SELECT name FROM classes WHERE id=?');$q->execute([$class]);$name=$q->fetchColumn();$q=$db->prepare('SELECT name_en FROM subjects WHERE id=?');$q->execute([$subject]);$subjectName=$q->fetchColumn();
    if(!in_array(classNameKey((string)$name),[classNameKey('Class 9'),classNameKey('Class 10')],true)||questionImportSubjectKey((string)$subjectName)!=='optional mathematics')return [];
    $chapters=array_map('intval',array_column(learningCatalogChapters($db,$actor,$class,$subject),'id'));
    return [['key'=>'cdc-opt-math-2082-g9-10','name'=>'CDC Optional Mathematics 9–10 (2082) — reference','url'=>'https://giwmscdnone.gov.np/media/pdf_upload/Specification%20Grid%20and%20Model%20Question-G9-Opt.%20Math-web_nrbp00y.pdf','edition'=>'2082','area_marks'=>['Algebra'=>17,'Trigonometry'=>17,'Geometry'=>20,'Vector'=>7,'Statistics'=>7,'Limits and continuity'=>7],
        'warning'=>'Reference totals only. Map actual chapters and review within/cross-area content, mixed-domain subparts, accessibility alternatives and cognitive weights before issuing an official paper. Whole-question cognitive labels are not a substitute for subpart-level CDC assessment.',
        'config'=>['reference_key'=>'cdc-opt-math-2082-g9-10','full_marks'=>75,'duration_minutes'=>180,'cognitive_marks'=>['K'=>12,'U'=>18,'A'=>30,'HA'=>15],'rows'=>[
            ['name'=>'Objective questions','type'=>'MCQ','chapter_ids'=>$chapters,'count'=>11,'marks_each'=>1,'total_marks'=>11,'domains'=>['K'=>3,'U'=>3,'A'=>3,'HA'=>2]],
            ['name'=>'Within-content-area questions (review allocation)','type'=>'Short','chapter_ids'=>$chapters,'count'=>12,'total_marks'=>40],
            ['name'=>'Cross-content-area questions (review allocation)','type'=>'Long','chapter_ids'=>$chapters,'count'=>4,'total_marks'=>24]
        ]]]];
}
function examGridCanEdit(PDO $db,array $actor,array $grid):bool {
    if($actor['read_only']||!learningPurposeAllowed($db,$actor,'exam_paper'))return false;
    if($actor['role']==='super_admin'&&$actor['school_id']===0&&(int)$grid['school_id']===0)return true;
    return (int)$grid['school_id']===$actor['school_id']&&(int)($grid['created_by_user_id']??0)===$actor['id']&&learningCanCreate($db,$actor,(int)$grid['class_id'],(int)$grid['subject_id']);
}
function examGridList(PDO $db,array $actor,int $class,int $subject,bool $archived=false):array {
    if(!learningPurposeAllowed($db,$actor,'exam_paper'))return [];$q=$db->prepare('SELECT * FROM exam_paper_grids WHERE school_id IN(0,?) AND archived_at IS '.($archived?'NOT ':'').'NULL ORDER BY name,id');$q->execute([$actor['school_id']]);$rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$scope=learningRecordScope($db,$actor,$r);if(!$scope||$scope['class_id']!==$class||$scope['subject_id']!==$subject)continue;$r['can_edit']=examGridCanEdit($db,$actor,$r);if($archived&&!$r['can_edit'])continue;$r['local_class_id']=$class;$r['local_subject_id']=$subject;$rows[]=$r;}return $rows;
}
function examGridRecord(PDO $db,array $actor,int $id,bool $archived=false):array {
    $q=$db->prepare('SELECT * FROM exam_paper_grids WHERE id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r||!learningPurposeAllowed($db,$actor,'exam_paper'))throw new InvalidArgumentException('Exam grid not found.');
    $scope=learningRecordScope($db,$actor,$r);if(!$scope||($r['archived_at']&&(!$archived||!examGridCanEdit($db,$actor,$r))))throw new InvalidArgumentException('Exam grid not found.');
    $r['can_edit']=examGridCanEdit($db,$actor,$r);$r['local_class_id']=$scope['class_id'];$r['local_subject_id']=$scope['subject_id'];return $r;
}
function examSaveGrid(PDO $db,array $actor,int $class,int $subject,string $name,array $input,?int $id=null,?int $revision=null):int {
    return learningWrite($db,function()use($db,$actor,$class,$subject,$name,$input,$id,$revision){
        examPaperScope($db,$actor,$class,$subject,true);if(!learningCanCreate($db,$actor,$class,$subject))throw new InvalidArgumentException('Create exam grids only for your assigned class subjects.');
        $name=trim($name);if($name===''||mb_strlen($name)>200)throw new InvalidArgumentException('Enter a grid name of at most 200 characters.');
        if($id){$old=examGridRecord($db,$actor,$id,true);if(!$old['can_edit']||$old['archived_at'])throw new InvalidArgumentException('Only the creator may edit an active exam grid.');if($revision!==(int)$old['revision'])throw new InvalidArgumentException('This grid changed in another session. Reload it before saving.');}
        $config=examNormalizeGrid($db,$actor,$class,$subject,$input);$json=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if($id)$db->prepare('UPDATE exam_paper_grids SET class_id=?,subject_id=?,name=?,config_json=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$class,$subject,$name,$json,$id]);
        else $id=insertRecord($db,'exam_paper_grids',['school_id'=>$actor['school_id'],'class_id'=>$class,'subject_id'=>$subject,'name'=>$name,'config_json'=>$json,'created_by_user_id'=>$actor['id']]);
        learningAudit($db,$actor,isset($old)?'Edit exam grid':'Create exam grid','exam_paper_grids',$id);return $id;
    });
}
function examArchiveGrid(PDO $db,array $actor,int $id,bool $archive):void {learningWrite($db,function()use($db,$actor,$id,$archive){$r=examGridRecord($db,$actor,$id,true);if(!examGridCanEdit($db,$actor,$r))throw new InvalidArgumentException('Only the grid creator may archive or restore it.');if(!$archive)curriculumAssertGridRestorable($db,examGridInputJson($r['config_json']));$db->prepare('UPDATE exam_paper_grids SET archived_at=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$archive?date('Y-m-d H:i:s'):null,$id]);learningAudit($db,$actor,$archive?'Archive exam grid':'Restore exam grid','exam_paper_grids',$id);});}
/** Bounded exact allocation, including overlapping chapter pools, variable marks and time. */
function examGeneratePaper(PDO $db,array $actor,int $class,int $subject,array $input,bool $chapterMode=false,string $bankKind='exam'):array {
    $grid=examNormalizeGrid($db,$actor,$class,$subject,$input,$chapterMode,$bankKind);$pool=learningContentList($db,$actor,'questions',['class_id'=>$class,'subject_id'=>$subject],$bankKind==='exam'?'exam_paper':'paper');
    $questions=[];
    foreach($pool as $q){try{$q['marks_units']=learningDecimalUnits((string)$q['marks'],100000,'Question marks');$q['minutes_units']=$bankKind==='learning'&&empty($q['required_minutes'])?0:learningDecimalUnits((string)$q['required_minutes'],60000,'Question minutes');}catch(InvalidArgumentException $e){continue;}
        if(!in_array($q['cognitive_domain'],EXAM_DOMAINS,true))continue;
        $q['cognitive_units']=questionCognitiveUnits($q);
        if($q['type']==='MCQ'){$en=json_decode($q['options']??'{}',true);$np=json_decode($q['options_np']??'{}',true);if(!is_array($en)||!is_array($np)||!in_array($q['correct_option'],['A','B','C','D'],true))continue;$valid=true;foreach(['A','B','C','D'] as $o)if(!questionImportHasContent($en[$o]??'')&&!questionImportHasContent($np[$o]??''))$valid=false;if(!$valid)continue;}
        $questions[(int)$q['id']]=$q;
    }
    $slots=[];$eligibleRows=[];$rowTotals=[];$rowCounts=[];$groupSerial=0;$eligibleIds=[];
    foreach($grid['rows'] as $i=>$row){
        $candidates=[];foreach($questions as $id=>$q)if(($q['type']===$row['type']||($row['type']==='Written'&&in_array($q['type'],array_diff(EXAM_TYPES,['MCQ']),true)))&&in_array($q['chapter'],$row['chapter_names'],true)&&(!$row['difficulty']||$q['difficulty']===$row['difficulty'])&&($row['each_units']===null||$q['marks_units']===$row['each_units'])&&(!$row['topic_ids']||in_array((int)($q['catalog_topic_id']?:$q['topic_id']),$row['topic_ids'],true)))$candidates[]=$id;
        shuffle($candidates);if(count($candidates)<$row['count'])throw new InvalidArgumentException($row['name'].': needs '.$row['count'].' matching '.$row['type'].' questions; only '.count($candidates).' are available.');
        $eligibleRows[$i]=$candidates;$rowTotals[$i]=$row['total_units'];$rowCounts[$i]=$row['count'];$groups=array_sum($row['domains'])?$row['domains']:['*'=>$row['count']];
        foreach($groups as $domain=>$n){if(!$n)continue;$ids=$domain==='*'?$candidates:array_values(array_filter($candidates,static fn($id)=>count(array_filter($questions[$id]['cognitive_units']))===1&&($questions[$id]['cognitive_units'][EXAM_DOMAINS[$domain]]??0)>0));
            if(count($ids)<$n)throw new InvalidArgumentException($row['name'].': needs '.$n.' '.$domain.' questions; only '.count($ids).' match.');
            foreach($ids as $id)$eligibleIds[$id]=true;
            if(count($eligibleIds)>2000)throw new InvalidArgumentException('More than 2000 matching exam questions: narrow chapter, type, difficulty, topic or mark filters before generating.');
            $group=++$groupSerial;for($j=0;$j<$n;$j++)$slots[]=['row'=>$i,'group'=>$group,'seq'=>$j,'ids'=>$ids];
        }
    }
    usort($slots,static fn($a,$b)=>count($a['ids'])<=>count($b['ids']));
    $used=[];$selected=[];$groupLast=[];$chapterCounts=[];$chapterMarks=[];$domainMarks=[];$time=0;$nodes=0;$started=hrtime(true);$targets=[];foreach($grid['chapter_targets'] as $t)$targets[$t['name']]=$t;
    $remainingRows=function()use(&$used,&$rowTotals,&$rowCounts,$eligibleRows,$questions):bool{
        foreach($rowCounts as $i=>$n){if(!$n){if($rowTotals[$i]!==0)return false;continue;}$marks=[];foreach($eligibleRows[$i] as $id)if(!isset($used[$id]))$marks[]=$questions[$id]['marks_units'];if(count($marks)<$n)return false;sort($marks);if(array_sum(array_slice($marks,0,$n))>$rowTotals[$i]||array_sum(array_slice($marks,-$n))<$rowTotals[$i])return false;}return true;
    };
    $solve=function(int $at)use(&$solve,$slots,$questions,$grid,$targets,&$used,&$selected,&$groupLast,&$rowTotals,&$rowCounts,&$chapterCounts,&$chapterMarks,&$domainMarks,&$time,&$nodes,$started,$remainingRows):bool{
        if(++$nodes>100000||hrtime(true)-$started>3000000000)throw new InvalidArgumentException('This grid is too complex to solve within the safe limit. Narrow chapter/mark pools or add more specific allocation rows.');
        if($at===count($slots)){foreach($targets as $ch=>$t)if(($t['count']!==null&&($chapterCounts[$ch]??0)!==$t['count'])||($t['units']!==null&&($chapterMarks[$ch]??0)!==$t['units']))return false;foreach($grid['cognitive_units'] as $d=>$m)if(($domainMarks[EXAM_DOMAINS[$d]]??0)!==$m)return false;return true;}
        $slot=$slots[$at];$row=$slot['row'];$group=$slot['group'];$oldLast=$groupLast[$group]??-1;
        foreach($slot['ids'] as $rank=>$id){if($rank<=$oldLast||isset($used[$id]))continue;$q=$questions[$id];$m=$q['marks_units'];$min=$q['minutes_units'];$ch=$q['chapter'];$d=$q['cognitive_domain'];
            if($m>$rowTotals[$row]||$time+$min>$grid['duration_units'])continue;
            $target=$targets[$ch]??null;if($target&&(($target['count']!==null&&($chapterCounts[$ch]??0)+1>$target['count'])||($target['units']!==null&&($chapterMarks[$ch]??0)+$m>$target['units'])))continue;
            $exceeds=false;foreach($q['cognitive_units'] as $category=>$units){$key=array_search($category,EXAM_DOMAINS,true);if(isset($grid['cognitive_units'][$key])&&($domainMarks[$category]??0)+$units>$grid['cognitive_units'][$key])$exceeds=true;}if($exceeds)continue;
            $used[$id]=true;$selected[$at]=$id;$groupLast[$group]=$rank;$rowTotals[$row]-=$m;$rowCounts[$row]--;$chapterCounts[$ch]=($chapterCounts[$ch]??0)+1;$chapterMarks[$ch]=($chapterMarks[$ch]??0)+$m;foreach($q['cognitive_units'] as $category=>$units)$domainMarks[$category]=($domainMarks[$category]??0)+$units;$time+=$min;
            if($remainingRows()&&$solve($at+1))return true;
            unset($used[$id],$selected[$at]);$groupLast[$group]=$oldLast;$rowTotals[$row]+=$m;$rowCounts[$row]++;$chapterCounts[$ch]--;$chapterMarks[$ch]-=$m;foreach($q['cognitive_units'] as $category=>$units)$domainMarks[$category]-=$units;$time-=$min;
        }return false;
    };
    if(!$remainingRows()||!$solve(0))throw new InvalidArgumentException('No complete paper meets these counts, marks, chapter/cognitive targets and time. Add matching exam questions or adjust the grid.');
    $rows=$grid['rows'];foreach($rows as &$r)$r['questions']=[];unset($r);foreach($selected as $at=>$id)$rows[$slots[$at]['row']]['questions'][]=$questions[$id];$all=[];foreach($rows as $r)foreach($r['questions'] as $q)$all[]=$q;
    return ['grid'=>$grid,'rows'=>$rows,'questions'=>$all,'total_units'=>array_sum(array_column($all,'marks_units')),'required_units'=>$time,'chapter_counts'=>$chapterCounts,'chapter_units'=>$chapterMarks,'cognitive_units'=>$domainMarks];
}
