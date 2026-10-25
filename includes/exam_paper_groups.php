<?php
require_once __DIR__.'/question_parts.php';
function paperGroupCalculation(array $questions):string {
    $counts=[];foreach($questions as $q){$mark=(int)$q['marks_units'];$counts[$mark]=($counts[$mark]??0)+1;}
    $terms=[];foreach($counts as $mark=>$count)$terms[]=$count.' × '.questionPartNumber($mark);
    return '('.implode(' + ',$terms).' = '.questionPartNumber(array_sum(array_column($questions,'marks_units'))).')';
}
/** One numbering model for the question sheet and the answer key. */
function examPaperGroups(array $paper,string $headingStyle='Group'):array {
    if(!in_array($headingStyle,['Group','Section','None'],true))throw new InvalidArgumentException('Choose Group, Section or None headings.');
    $types=['MCQ'=>'Multiple Choice Questions','Very Short'=>'Very Short Questions','Short'=>'Short Questions','Long'=>'Long Questions','Reasoning'=>'Reasoning Questions','Difference Between'=>'Difference Between'];
    $byType=[];$seen=[];
    foreach($paper['questions'] as $q){
        if(!isset($types[$q['type']])||isset($seen[$q['id']]))throw new InvalidArgumentException('Invalid or repeated generated question. Regenerate the paper.');
        $seen[$q['id']]=true;$byType[$q['type']][]=$q;
    }
    $custom=(bool)array_filter($paper['rows']??[],static fn($row)=>($row['section_id']??null)!==null);
    if($custom||$headingStyle==='None'){
        $blocks=[];
        if($headingStyle==='None')$blocks=[['title'=>'','questions'=>$paper['questions']]];
        else foreach($paper['rows'] as $row){$key=$row['section_id'];$blocks[$key]??=['title'=>$row['section_title'],'questions'=>[]];array_push($blocks[$key]['questions'],...$row['questions']);}
        $groups=[];$main=1;$included=[];
        foreach($blocks as $block){$items=[];foreach($block['questions'] as $q){if(!isset($seen[$q['id']])||isset($included[$q['id']]))throw new InvalidArgumentException('Invalid section question allocation.');$included[$q['id']]=true;$items[]=['question'=>$q,'main_no'=>$main++,'sub_no'=>null];}
            $letter=chr(65+count($groups));$groups[]=['letter'=>$letter,'label'=>$headingStyle==='None'?'':$headingStyle.' - '.$letter.($block['title']!==''?' — '.$block['title']:''),'type'=>'Mixed','marks_units'=>array_sum(array_column($block['questions'],'marks_units')),'calculation'=>paperGroupCalculation($block['questions']),'items'=>$items];
        }
        if(count($included)!==count($seen))throw new InvalidArgumentException('A generated question is missing from its section.');
        return $groups;
    }
    $groups=[];$main=1;
    foreach($types as $type=>$name){
        if(empty($byType[$type]))continue;$letter=chr(65+count($groups));$items=[];
        foreach($byType[$type] as $i=>$q)$items[]=['question'=>$q,'main_no'=>$type==='MCQ'?1:$main++,'sub_no'=>$type==='MCQ'?$i+1:null];
        if($type==='MCQ')$main=2;
        $groups[]=['letter'=>$letter,'label'=>$headingStyle.' '.$letter.' — '.$name,'type'=>$type,'marks_units'=>array_sum(array_column($byType[$type],'marks_units')),'calculation'=>paperGroupCalculation($byType[$type]),'items'=>$items];
    }
    return $groups;
}
