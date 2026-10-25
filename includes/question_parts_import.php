<?php
/** A spreadsheet row is one part; a nonblank Question Group joins parts atomically. */
function questionImportGroups(array $rows,array $map):array {
    $groups=[];$positions=[];
    foreach(array_slice($rows,1) as $offset=>$row){
        if(!array_filter($row,static fn($v)=>trim((string)$v)!==''))continue;
        $group=trim((string)($row[$map['question_group']??-1]??''));
        if(mb_strlen($group)>80)throw new InvalidArgumentException('Row '.($offset+2).': Question Group must contain at most 80 characters.');
        $key=$group===''?'row:'.$offset:'group:'.$group;
        if(!isset($positions[$key])){$positions[$key]=count($groups);$groups[]=['rows'=>[],'numbers'=>[],'structured'=>$group!==''];}
        $at=$positions[$key];$groups[$at]['rows'][]=$row;$groups[$at]['numbers'][]=$offset+2;
    }
    return $groups;
}
function questionImportCategory(string $value):string {
    $key=strtolower(preg_replace('/[^a-z]/i','',$value));
    foreach(QUESTION_PART_LEVELS as $code=>$name)if(in_array($key,[strtolower($code),strtolower(str_replace(' ','',$name)),strtolower(str_replace(' ','',$name).$code)],true))return $code;
    throw new InvalidArgumentException('Cognitive Level must be K, U, A, HA or KU.');
}
/** Resolve all rows before saving a group: no partial main question can be retained. */
function questionImportGroupInput(array $group,array $map,string $mode,string $kind):array {
    $row=$group['rows'][0];$get=static fn($field)=>(string)($row[$map[$field]??-1]??'');
    $types=['mcq'=>'MCQ','very short'=>'Very Short','veryshort'=>'Very Short','short'=>'Short','long'=>'Long','reasoning'=>'Reasoning','difference between'=>'Difference Between','differencebetween'=>'Difference Between'];
    $type=$types[curriculumNameKey($get('type'))]??null;
    if(!$type)throw new InvalidArgumentException('Type must be MCQ, Very Short, Short, Long, Reasoning or Difference Between.');
    if($kind==='mcq'&&$type!=='MCQ')throw new InvalidArgumentException('The MCQs import accepts Type MCQ only. Use Other Questions for written-answer questions.');
    if($kind==='other'&&$type==='MCQ')throw new InvalidArgumentException('Use the MCQs import and its four-option sample for Type MCQ.');
    $levels=['easy'=>'Easy','medium'=>'Medium','hard'=>'Hard','challenging'=>'Challenging'];$difficulty=$levels[curriculumNameKey($get('difficulty'))]??null;
    if(!$difficulty)throw new InvalidArgumentException('Difficulty must be Easy, Medium, Hard or Challenging.');
    $minutes=learningDecimalString(learningDecimalUnits($get('required_minutes'),60000,'Required minutes'));
    $merged=[];foreach(['statement_en','statement_np','answer','answer_np','source','part_display','mathematics_rule','correct_option','option_a','option_a_np','option_b','option_b_np','option_c','option_c_np','option_d','option_d_np'] as $field)$merged[$field]='';
    foreach($group['rows'] as $index=>$partRow){
        $value=static fn($field)=>(string)($partRow[$map[$field]??-1]??'');
        if(($types[curriculumNameKey($value('type'))]??null)!==$type||($levels[curriculumNameKey($value('difficulty'))]??null)!==$difficulty||learningDecimalString(learningDecimalUnits($value('required_minutes'),60000,'Required minutes'))!==$minutes)throw new InvalidArgumentException('Every row in a Question Group must use the same Type, Difficulty and whole-question Required Minutes.');
        foreach($merged as $field=>$previous){$current=trim($value($field));if($current==='')continue;if($previous!==''&&$previous!==$current)throw new InvalidArgumentException('Conflicting '.str_replace('_',' ',$field).' values in one Question Group. Supply once or repeat the same value.');$merged[$field]=$current;}
    }
    $structured=$group['structured']||$merged['statement_en']!==''||$merged['statement_np']!==''||trim($get('part_label'))!=='';
    if(!$structured){
        $content=questionImportRowContent($get,$mode);if($type!=='MCQ'&&$content['type']==='MCQ')throw new InvalidArgumentException('Rows with MCQ options must use Type MCQ.');
        return $content+['difficulty'=>$difficulty,'cognitive_domain'=>QUESTION_PART_LEVELS[questionImportCategory($get('cognitive_domain'))],'required_minutes'=>$minutes,'source'=>$merged['source'],'import_marks'=>learningDecimalString(learningDecimalUnits($get('marks'),100000,'Question marks'))];
    }
    if(count($group['rows'])>40)throw new InvalidArgumentException('A Question Group can have at most 40 subquestion rows.');
    if($type==='MCQ'&&count($group['rows'])!==1)throw new InvalidArgumentException('Each MCQ group has one prompt and one set of four choices. Use different Question Groups for additional MCQs.');
    $display=strtolower($merged['part_display']?:'both');$display=match($display){'both','marks + cognitive','marks and cognitive'=>'both','marks','marks only'=>'marks','cognitive','cognitive only'=>'cognitive',default=>throw new InvalidArgumentException('Part Display must be both, marks or cognitive.')};
    $parts=[];foreach($group['rows'] as $i=>$partRow){$value=static fn($field)=>(string)($partRow[$map[$field]??-1]??'');$parts[]=['label'=>trim($value('part_label'))?:questionPartLabel($i),'en'=>questionImportContent($value('question_text'),$mode),'np'=>questionImportContent($value('question_text_np'),$mode),'marks'=>$value('marks'),'level'=>questionImportCategory($value('cognitive_domain'))];}
    $input=['type'=>$type,'difficulty'=>$difficulty,'required_minutes'=>$minutes,'source'=>$merged['source'],'question_layout'=>'structured','part_display'=>$display,'statement_en'=>questionImportContent($merged['statement_en'],$mode),'statement_np'=>questionImportContent($merged['statement_np'],$mode),'subquestions'=>$parts];
    foreach(['answer','answer_np','option_a','option_a_np','option_b','option_b_np','option_c','option_c_np','option_d','option_d_np'] as $field)$input[$field]=questionImportContent($merged[$field],$mode);
    $input['correct_option']=$merged['correct_option'];$input['mathematics_rule']=strtolower($merged['mathematics_rule']?:'strict');
    return $input;
}
