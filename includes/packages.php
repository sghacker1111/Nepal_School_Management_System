<?php
function schoolPackages(): array {
    return ['full'=>'Full ERP','result_system'=>'Result System','learning_system'=>'Learning System','paper_generator'=>'Paper Generator System','question_view'=>'Question View System','question_add'=>'Question Add System','accounting'=>'Accounting & Billing System','exam_generator'=>'Legacy Exam Generator Package'];
}
function schoolPackageAllows(string $package,string $module): bool {
    if($package==='full')return true;
    if(in_array($module,['auth','settings','classes','subjects'],true))return true;
    $features=[
        'result_system'=>['students','teachers','attendance','exams','results','reports'],
        'learning_system'=>['learning','curriculum','specification_grids'],
        'paper_generator'=>['paper_generator','questions','specification_grids','ocr_scanner'],
        'question_view'=>['questions'],
        'question_add'=>['questions'],
        'accounting'=>['accounting','students'],
        'exam_generator'=>['learning','curriculum','paper_generator','questions','specification_grids','ocr_scanner']
    ];
    return in_array($module,$features[$package]??[],true);
}
