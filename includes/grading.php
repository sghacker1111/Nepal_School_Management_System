<?php
/** Assessment uses the saved exam scheme, not today's catalog values. */
function erpGradeForPercentage(float $percentage): array {
    if (!is_finite($percentage) || $percentage<0 || $percentage>100.000001) return ['NG',0.0];
    foreach ([[90,'A+',4.0],[80,'A',3.6],[70,'B+',3.2],[60,'B',2.8],[50,'C+',2.4],[40,'C',2.0],[35,'D',1.6]] as [$min,$grade,$gp]) if($percentage>=$min) return [$grade,$gp];
    return ['NG',0.0];
}
function erpFinalGrade(float $gp): string {
    foreach ([[3.6,'A+'],[3.2,'A'],[2.8,'B+'],[2.4,'B'],[2.0,'C+'],[1.6,'C']] as [$min,$grade]) if($gp>$min+0.000001)return $grade;
    return $gp>=1.6?'D':'NG';
}
function erpMarkValue($value, float $full): ?float {
    if ($value === '' || $value === null) return null;
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<0 || (float)$value>$full+0.000001) throw new InvalidArgumentException('Marks must be between zero and the configured full marks.');
    return round((float)$value,2);
}
function erpCalculateSubjectResult(array $scheme, array $mark, array $exam): array {
    $tf=(float)($scheme['theory_full']??100);
    $pf=!empty($exam['has_internal']) && ($exam['type']??'')!=='unit'?(float)($scheme['practical_full']??0):0.0;
    $th=$mark['theory_obtained']??null; $pr=$pf>0?($mark['practical_obtained']??null):0.0;
    $incomplete=($tf>0 && $th===null)||($pf>0 && $pr===null);
    $failed=$incomplete || ($tf>0 && (float)$th<(float)($scheme['theory_pass']??0)) || ($pf>0 && (float)$pr<(float)($scheme['practical_pass']??0));
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
    $gp=$failed?0:round($weight>0?($tc*$tgp+$pc*$pgp)/$weight:0,2);
    $pct=$tf+$pf>0?round(((float)$th+(float)$pr)/($tf+$pf)*100,2):0;
    $grade=$failed?'NG':erpFinalGrade($gp);
    return ['theory_obtained'=>$th,'practical_obtained'=>$pr,'theory_full'=>$tf,'practical_full'=>$pf,'theory_pass'=>(float)($scheme['theory_pass']??0),'practical_pass'=>(float)($scheme['practical_pass']??0),'internal_credit_hours'=>$pc,'external_credit_hours'=>$tc,'credit_hours'=>$credit,'internal_gp'=>$pgp,'external_gp'=>$tgp,'internal_grade'=>$pf<=0?'—':(($pr===null || (float)$pr<(float)($scheme['practical_pass']??0))?'NG':$pg),'external_grade'=>$tf<=0?'—':(($th===null || (float)$th<(float)($scheme['theory_pass']??0))?'NG':$tg),'grade'=>$grade,'grade_point'=>$gp,'percentage'=>$pct,'failed'=>$failed,'incomplete'=>$incomplete,'remarks'=>$incomplete?'Incomplete':($failed?'NG':'Pass')];
}
function erpReportSummary(array $marks, array $exam, string $system): array {
    $rows=[];$credits=0;$weighted=0;$full=0;$obtained=0;$failed=false;$incomplete=false;
    foreach($marks as $m){
        $result=erpCalculateSubjectResult($m,$m,$exam);$rows[]=array_merge($m,$result);
        $credits+=$result['credit_hours'];$weighted+=$result['credit_hours']*$result['grade_point'];
        $full+=$result['theory_full']+$result['practical_full'];$obtained+=(float)$result['theory_obtained']+(float)$result['practical_obtained'];
        $failed=$failed||$result['failed'];$incomplete=$incomplete||$result['incomplete'];
    }
    if(!$marks){$failed=true;$incomplete=true;}
    $average=$system==='percentage'?($full>0?round($obtained/$full*100,2):0):($credits>0?round($weighted/$credits,2):0);
    $text=$incomplete?'Incomplete':($system==='percentage'?number_format($average,2).'%':($failed?'NG':number_format($average,2)));
    $grade=$incomplete?'Incomplete':($failed?'NG':($system==='percentage'?($average>=80?'Distinction':($average>=60?'First Division':($average>=45?'Second Division':'Third Division'))):erpFinalGrade($average)));
    return ['marks_elaborated'=>$rows,'total_credit_hours'=>$credits,'weighted_gp_sum'=>$weighted,'has_failed_subject'=>$failed,'grand_full'=>$full,'grand_obtained'=>$obtained,'average_gpa'=>$average,'final_gpa_text'=>$text,'final_grade'=>$grade,'remarks_summary'=>$incomplete?'Incomplete':($failed?($system==='percentage'?'Failed':'Not Graded'):($system==='percentage'?'Passed':'Graded'))];
}
