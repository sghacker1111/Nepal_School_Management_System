<?php
/** Translate a small chapter/type table into the existing exact solver's grid. */
function examMixedGridInput(PDO $db,array $actor,int $class,int $subject,array $input):array {
    examPaperScope($db,$actor,$class,$subject);
    $allocations=$input['rows']??null;
    if(!is_array($allocations)||!array_is_list($allocations)||!$allocations||count($allocations)>50)throw new InvalidArgumentException('Add 1–50 mixed allocation rows.');
    $catalog=[];foreach(learningCatalogChapters($db,$actor,$class,$subject) as $c)foreach($c['alias_ids'] as $id)$catalog[(int)$id]=$c;
    $rows=[];$total=0;$count=0;$mcq=false;$written=false;
    foreach($allocations as $row){
        if(!is_array($row))throw new InvalidArgumentException('Choose a valid mixed allocation row.');
        $chapter=$catalog[learningId($row['chapter_id']??null)]??null;$type=$row['type']??null;
        if(!$chapter||!is_string($type)||!in_array($type,EXAM_TYPES,true))throw new InvalidArgumentException('Choose an existing chapter and question type in this class subject, including skipped rows.');
        $n=examCount($row['count']??0,'Question count',true);if(!$n)continue;
        $units=learningDecimalUnits($row['total_marks']??null,100000,'Row total marks');$total+=$units;$count+=$n;
        if($total>100000||$count>200)throw new InvalidArgumentException('A paper may contain at most 200 questions and 1,000 marks.');
        if($type==='MCQ')$mcq=true;else $written=true;
        $rows[]=['name'=>mb_substr($chapter['name'].' — '.$type,0,100),'type'=>$type,'chapter_ids'=>[(int)$chapter['id']],'count'=>$n,'total_marks'=>learningDecimalString($units)];
    }
    if(!$mcq||!$written)throw new InvalidArgumentException('Written + MCQs requires at least one selected MCQ row and one written-question row. Use the single-kind modes otherwise.');
    $duration=learningDecimalString(learningDecimalUnits($input['duration_minutes']??null,60000,'Paper duration'));
    return ['full_marks'=>learningDecimalString($total),'duration_minutes'=>$duration,'rows'=>$rows];
}
