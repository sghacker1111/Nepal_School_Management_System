<?php
require_once __DIR__.'/question_parts.php';

function paperFormatInput(array $input):array {
    $style=$input['heading_style']??'Group';
    if(!is_string($style)||!in_array($style,['Group','Section','None'],true))throw new InvalidArgumentException('Choose Group, Section or None for paper headings.');
    $format=['heading_style'=>$style];
    foreach(['paper_school_name'=>200,'paper_instructions'=>3000] as $key=>$maximum){
        $value=$input[$key]??($key==='paper_instructions'?'Attempt all questions. Figures beside questions or subquestions indicate marks.':'');
        if(!is_string($value)||mb_strlen($value)>$maximum)throw new InvalidArgumentException('Enter valid '.($key==='paper_school_name'?'school name':'instructions').' (maximum '.$maximum.' characters).');
        $format[$key]=trim(str_replace(["\r\n","\r"],"\n",$value));
    }
    return $format;
}
function paperDurationLabel(string $minutes):string {
    $seconds=(int)round((float)$minutes*60);$hours=intdiv($seconds,3600);$remainder=$seconds%3600;
    return sprintf('%02d:%02d',$hours,intdiv($remainder,60)).($remainder%60?sprintf(':%02d',$remainder%60):'').' Hours';
}
function paperFormatHeader(array $format,array $school,string $title,string $className,string $subjectName,array $paper):string {
    $schoolName=$format['paper_school_name']?:($school['name_en']??'School');
    $classLabel=preg_replace('/^\s*Class\s+/i','',$className);
    ob_start(); ?>
<header class="paper-custom-header"><h2><?= sanitize($schoolName) ?></h2><h3><?= sanitize($title) ?></h3></header>
<div class="paper-custom-meta"><span>Class: <?= sanitize($classLabel) ?></span><span>Time: <?= sanitize(paperDurationLabel((string)$paper['grid']['duration_minutes'])) ?></span><span>Full Marks: <?= sanitize(questionPartNumber($paper['total_units'])) ?></span></div>
<p class="paper-custom-subject">Subject: <?= sanitize($subjectName) ?></p>
<div class="paper-custom-instructions"><?php if($format['paper_instructions']!==''): ?><strong>Instructions</strong><div><?= sanitize($format['paper_instructions']) ?></div><?php endif; ?></div>
<?php return ob_get_clean();
}
/** Turn the custom group editor into ordinary, fully checked allocation rows. */
function paperSectionGridInput(array $input):array {
    $sections=$input['sections']??null;
    if(!is_array($sections)||!array_is_list($sections)||!$sections||count($sections)>26)throw new InvalidArgumentException('Add between 1 and 26 groups / sections.');
    $rows=[];$total=0;$quantity=0;
    foreach($sections as $index=>$section){
        if(!is_array($section))throw new InvalidArgumentException('Invalid paper section.');
        $title=$section['title']??'';if(!is_string($title)||mb_strlen($title)>80)throw new InvalidArgumentException('Section titles must be at most 80 characters.');
        $allocations=$section['rows']??null;if(!is_array($allocations)||!array_is_list($allocations)||!$allocations||count($allocations)>50)throw new InvalidArgumentException('Add question allocations inside every group / section.');
        $active=0;
        foreach($allocations as $allocation){
            if(!is_array($allocation))throw new InvalidArgumentException('Invalid section allocation.');
            $count=examCount($allocation['count']??0,'Question count',true);if(!$count)continue;
            $marks=learningDecimalUnits($allocation['total_marks']??null,100000,'Allocation marks');
            $rows[]=['section_id'=>$index+1,'section_title'=>trim($title),'name'=>mb_substr((trim($title)?:'Block '.($index+1)).' / '.(is_string($allocation['type']??null)?$allocation['type']:''),0,100),'chapter_ids'=>$allocation['chapter_ids']??[],'topic_ids'=>[],'type'=>$allocation['type']??'','count'=>$count,'total_marks'=>learningDecimalString($marks),'marks_each'=>$allocation['marks_each']??''];
            $active++;$total+=$marks;$quantity+=$count;
        }
        if(!$active)throw new InvalidArgumentException('Each group / section needs at least one allocation with a question count above 0.');
    }
    if(count($rows)>50||$quantity>200||$total>100000)throw new InvalidArgumentException('Use at most 50 allocations, 200 questions and 1,000 marks per paper.');
    return ['duration_minutes'=>$input['duration_minutes']??150,'full_marks'=>learningDecimalString($total),'rows'=>$rows];
}
