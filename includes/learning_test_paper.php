<?php
require_once __DIR__.'/exam_paper_service.php';

/** Unit/test papers use the learning bank, never the staff-only exam bank. */
function learningTestPaper(PDO $db,array $actor,int $class,int $subject,array $input):array {
    examPaperScope($db,$actor,$class,$subject,false,'learning');
    $allocations=$input['rows']??null;
    if(!is_array($allocations)||!array_is_list($allocations)||!count($allocations)||count($allocations)>50)throw new InvalidArgumentException('Add 1–50 chapter/topic allocation rows.');
    $rows=[];$total=0;$quantity=0;
    foreach($allocations as $i=>$allocation){
        if(!is_array($allocation))throw new InvalidArgumentException('Choose a valid test-paper row.');
        $count=examCount($allocation['count']??0,'Row '.($i+1).' question count',true);if(!$count)continue;
        $chapter=learningId($allocation['chapter_id']??null);$topic=$allocation['topic_id']??'';
        $topicIds=$topic===''||$topic===null?[]:[learningId($topic)];
        $marks=learningDecimalUnits($allocation['total_marks']??null,100000,'Row '.($i+1).' total marks');
        $rows[]=['name'=>'Allocation '.($i+1),'chapter_ids'=>[$chapter],'topic_ids'=>$topicIds,'type'=>$allocation['type']??'','count'=>$count,'total_marks'=>learningDecimalString($marks)];
        $total+=$marks;$quantity+=$count;
    }
    if(!$rows)throw new InvalidArgumentException('Enter a question count above 0 in at least one allocation row.');
    if($total>100000||$quantity>200)throw new InvalidArgumentException('A test paper may contain at most 200 questions and 1,000 marks.');
    $paper=examGeneratePaper($db,$actor,$class,$subject,['full_marks'=>learningDecimalString($total),'duration_minutes'=>$input['duration_minutes']??60,'rows'=>$rows],false,'learning');
    foreach($paper['rows'] as &$row){
        $label=$row['type'].' — '.implode(', ',$row['chapter_names']);
        if($row['topic_ids'])foreach(learningCatalogTopics($db,$actor,$class,$subject,(int)$row['chapter_ids'][0]) as $topic)if(array_intersect($topic['alias_ids'],$row['topic_ids']))$label.=' — '.$topic['name'];
        $row['name']=$label;
    }unset($row);return $paper;
}
