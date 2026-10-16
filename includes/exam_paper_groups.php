<?php
/** One numbering model for the question sheet and the answer key. */
function examPaperGroups(array $paper,string $headingStyle='Group'):array {
    if(!in_array($headingStyle,['Group','Section'],true))throw new InvalidArgumentException('Choose Group or Section headings.');
    $types=['MCQ'=>'Multiple Choice Questions','Very Short'=>'Very Short Questions','Short'=>'Short Questions','Long'=>'Long Questions','Reasoning'=>'Reasoning Questions','Difference Between'=>'Difference Between'];
    $byType=[];$seen=[];
    foreach($paper['questions'] as $q){
        if(!isset($types[$q['type']])||isset($seen[$q['id']]))throw new InvalidArgumentException('Invalid or repeated generated question. Regenerate the paper.');
        $seen[$q['id']]=true;$byType[$q['type']][]=$q;
    }
    $groups=[];$main=1;
    foreach($types as $type=>$name){
        if(empty($byType[$type]))continue;$letter=chr(65+count($groups));$items=[];
        foreach($byType[$type] as $i=>$q)$items[]=['question'=>$q,'main_no'=>$type==='MCQ'?1:$main++,'sub_no'=>$type==='MCQ'?$i+1:null];
        if($type==='MCQ')$main=2;
        $groups[]=['letter'=>$letter,'label'=>$headingStyle.' '.$letter.' — '.$name,'type'=>$type,'marks_units'=>array_sum(array_column($byType[$type],'marks_units')),'items'=>$items];
    }
    return $groups;
}
