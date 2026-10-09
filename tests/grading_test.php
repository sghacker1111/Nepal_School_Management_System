<?php
require __DIR__.'/../includes/grading.php';
function gradeCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
gradeCheck(erpGradeForPercentage(79.999)[0]==='B+','Fractional percentages must not fall into a grading gap');
gradeCheck(erpGradeForPercentage(80)[0]==='A','80 percent is A');
gradeCheck(erpGradeForPercentage(34.99)[0]==='NG','Below 35 is NG');
$scheme=['theory_full'=>75,'theory_pass'=>26.25,'practical_full'=>25,'practical_pass'=>10,'credit_hours'=>4,'external_credit_hours'=>3,'internal_credit_hours'=>1];
$r=erpCalculateSubjectResult($scheme,['theory_obtained'=>60,'practical_obtained'=>20],['has_internal'=>1,'type'=>'terminal']);
gradeCheck($r['grade_point']===3.6&&!$r['failed'],'Both components at 80 percent give GPA 3.6');
$r=erpCalculateSubjectResult($scheme,['theory_obtained'=>60,'practical_obtained'=>0],['has_internal'=>0,'type'=>'terminal']);
gradeCheck($r['grade_point']===3.6,'Disabled internal assessment must not reduce GPA');
$r=erpCalculateSubjectResult($scheme,['theory_obtained'=>null,'practical_obtained'=>20],['has_internal'=>1]);
gradeCheck($r['incomplete']&&$r['failed'],'Unentered marks cannot be reported as a pass');
$m=array_merge($scheme,['theory_obtained'=>60,'practical_obtained'=>20]);
$s=erpReportSummary([$m],['has_internal'=>1,'type'=>'terminal'],'percentage');
gradeCheck($s['final_gpa_text']==='80.00%','Terminal percentage reports must not display GPA as a percentage');
$s=erpReportSummary([$m],['has_internal'=>0,'type'=>'unit'],'gpa');
gradeCheck($s['final_gpa_text']==='3.60','Unit exam GPA must not be a percentage');
foreach([-1,76,'invalid'] as $bad){try{erpMarkValue($bad,75);throw new RuntimeException('Invalid mark accepted');}catch(InvalidArgumentException $e){}}
echo "Grading checks passed\n";
