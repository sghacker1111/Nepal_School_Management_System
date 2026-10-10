<?php
require_once __DIR__.'/cdc_2083.php';
/** Assessment uses the saved exam scheme, not today's catalog values. */
function erpGradeForPercentage(float $percentage): array {
    if (!is_finite($percentage) || $percentage<0 || $percentage>100.000001) return ['NG',0.0];
    foreach (($GLOBALS['erp_cdc_percentage_bands']??cdc2083Data()['GRADE_2083']) as $band) if($percentage>=$band['minimum_percent_inclusive'] && ($band['maximum_percent_exclusive']===null||$percentage<$band['maximum_percent_exclusive'])) return [$band['letter_grade'],(float)($band['grade_point']??0)];
    return ['NG',0.0];
}
function erpFinalGrade(float $gp): string {
    $gp=round($gp,2);
    if(!is_finite($gp)||$gp<1.6||$gp>4)return 'NG';
    foreach(cdc2083Data()['FINAL_GP_BANDS'] as $band)if($band['lower_GP_2dp']!==null&&$gp>=$band['lower_GP_2dp']&&$gp<=$band['upper_GP_2dp'])return $band['final_grade'];
    return 'NG';
}
function erpMarkValue($value, float $full): ?float {
    if ($value === '' || $value === null) return null;
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<0 || (float)$value>$full+0.000001) throw new InvalidArgumentException('Marks must be between zero and the configured full marks.');
    return round((float)$value,2);
}
function erpCalculateSubjectResult(array $scheme, array $mark, array $exam): array {
    $thematic=($scheme['assessment_method']??'')==='THEMATIC_CONTINUOUS';
    $tf=(float)($scheme['theory_full']??100);
    $pf=!$thematic && !empty($exam['has_internal']) && ($exam['type']??'')!=='unit'?(float)($scheme['practical_full']??0):0.0;
    $tp=(float)($scheme['theory_pass']??0);$pp=(float)($scheme['practical_pass']??0);
    if(!empty($scheme['curriculum_version'])){$tp=max($tp,$tf*0.35);$pp=max($pp,$pf*0.4);}
    $th=$mark['theory_obtained']??null; $pr=$pf>0?($mark['practical_obtained']??null):0.0;
    $incomplete=($tf>0 && $th===null)||($pf>0 && $pr===null);
    $failed=$incomplete || ($tf>0 && (float)$th<$tp) || ($pf>0 && (float)$pr<$pp);
    [$tg,$tgp]=erpGradeForPercentage($tf>0?(float)$th/$tf*100:0);
    [$pg,$pgp]=erpGradeForPercentage($pf>0?(float)$pr/$pf*100:0);
    $credit=(float)($scheme['exam_credit_hours']??$scheme['credit_hours']??$scheme['default_credit_hours']??4);
    if($credit<=0)$credit=4;
    $tc=$tf>0?(float)($scheme['external_credit_hours']??0):0;
    $pc=$pf>0?(float)($scheme['internal_credit_hours']??0):0;
    if ($pf<=0) {$tc=$credit;$pc=0;}
    elseif($tf<=0){$tc=0;$pc=$credit;}
    elseif($tc+$pc<=0){$tc=$credit*$tf/($tf+$pf);$pc=$credit-$tc;}
    $weight=$tc+$pc;
    $unroundedGp=$failed?0:($weight>0?($tc*$tgp+$pc*$pgp)/$weight:0);
    $gp=round($unroundedGp,2);
    $pct=$tf+$pf>0?round(((float)$th+(float)$pr)/($tf+$pf)*100,2):0;
    $grade=$failed?'NG':erpFinalGrade($gp);
    return ['theory_obtained'=>$th,'practical_obtained'=>$pr,'theory_full'=>$tf,'practical_full'=>$pf,'theory_pass'=>$tp,'practical_pass'=>$pp,'internal_credit_hours'=>$thematic?null:$pc,'external_credit_hours'=>$thematic?null:$tc,'credit_hours'=>$credit,'internal_gp'=>$pgp,'external_gp'=>$tgp,'internal_grade'=>$pf<=0?'—':(($pr===null || (float)$pr<$pp)?'NG':$pg),'external_grade'=>$tf<=0?'—':(($th===null || (float)$th<$tp)?'NG':$tg),'grade'=>$grade,'grade_point'=>$gp,'grade_point_unrounded'=>$unroundedGp,'percentage'=>$pct,'failed'=>$failed,'incomplete'=>$incomplete,'remarks'=>$incomplete?'Incomplete':($failed?'NG':'Pass')];
}
function erpReportSummary(array $marks, array $exam, string $system): array {
    $marks=erpSortSubjects($marks);
    $rows=[];$credits=0;$weighted=0;$full=0;$obtained=0;$failed=false;$incomplete=false;
    foreach($marks as $m){
        $result=erpCalculateSubjectResult($m,$m,$exam);$rows[]=array_merge($m,$result);
        $credits+=$result['credit_hours'];$weighted+=$result['credit_hours']*$result['grade_point_unrounded'];
        $full+=$result['theory_full']+$result['practical_full'];$obtained+=(float)$result['theory_obtained']+(float)$result['practical_obtained'];
        $failed=$failed||$result['failed'];$incomplete=$incomplete||$result['incomplete'];
    }
    if(!$marks){$failed=true;$incomplete=true;}
    $average=$system==='percentage'?($full>0?round($obtained/$full*100,2):0):($credits>0?round($weighted/$credits,2):0);
    $text=$incomplete?'Incomplete':($system==='percentage'?number_format($average,2).'%':($failed?'NG':number_format($average,2)));
    $grade=$incomplete?'Incomplete':($failed?'NG':($system==='percentage'?($average>=80?'Distinction':($average>=60?'First Division':($average>=45?'Second Division':'Third Division'))):erpFinalGrade($average)));
    return ['marks_elaborated'=>$rows,'total_credit_hours'=>$credits,'weighted_gp_sum'=>$weighted,'has_failed_subject'=>$failed,'grand_full'=>$full,'grand_obtained'=>$obtained,'average_gpa'=>$average,'final_gpa_text'=>$text,'final_grade'=>$grade,'remarks_summary'=>$incomplete?'Incomplete':($failed?($system==='percentage'?'Failed':'Not Graded'):($system==='percentage'?'Passed':'Graded'))];
}
/** The same order is used for marks entry, ledger and every report format. */
function erpSortSubjects(array $subjects):array{
    $rank=static function($s){$name=strtolower($s['subject_name']??$s['name_en']??'');if(!empty($s['is_optional'])||str_contains($name,'optional'))return 100;foreach(['nepali','english','math','science','social','health','local','mother tongue','sanskrit','serofero'] as $i=>$needle)if(str_contains($name,$needle))return $needle==='serofero'?3:$i;return 50;};
    usort($subjects,static function($a,$b)use($rank){return ($rank($a)<=>$rank($b))?:strcasecmp($a['subject_name']??$a['name_en']??'',$b['subject_name']??$b['name_en']??'')?:(($a['subject_id']??$a['id']??0)<=>($b['subject_id']??$b['id']??0));});return $subjects;
}
