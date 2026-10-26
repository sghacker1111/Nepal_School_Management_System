<?php
/** Feature shortcuts inherit the visible parent menu's role and package scope.
 * Only GET landing pages are indexed; record-specific actions stay on their pages.
 */
function schoolNavigationFeatures(array $navigation,string $role,int $school,bool $readOnly,bool $canAddQuestions):array {
    $parents=[];foreach($navigation as $items)foreach($items as $item)$parents[$item['key']]=$item;
    $features=[];
    $add=static function(string $parent,string $label,string $controller,array $query=[],array $roles=[],bool $write=false,string $keywords='')use(&$features,$parents,$role,$readOnly){
        if(!isset($parents[$parent])||($roles&&!in_array($role,$roles,true))||($write&&$readOnly))return;
        $features[]=['label'=>$label,'url'=>schoolRouteUrl($controller,$query),'keywords'=>$parents[$parent]['label'].' '.$keywords];
    };
    $admins=['super_admin','school_admin'];
    foreach(['students'=>'Student','teachers'=>'Teacher'] as $parent=>$label){
        $add($parent,'Add '.$label,'modules/'.$parent.'/add.php',[],$admins,true,'create new admission registration');
        $add($parent,'Import / export '.$label.' records','modules/'.$parent.'/import_export.php',[],$admins,true,'excel csv spreadsheet upload download template');
        $add($parent,'Edit '.$label.' records','modules/'.$parent.'/index.php',[],$admins,true,'update details');
    }
    $add('classes','Add class / section','modules/classes/add.php',[],$admins,true);
    $add('classes','Assign subjects and teachers','modules/classes/index.php',[],$admins,true,'class teaching compulsory optional school offerings');
    $add('subjects','Add subject','modules/subjects/add.php',[],['super_admin'],true);
    $add('exams','Create examination','modules/exams/add.php',[],$admins,true,'add exam');
    $add('exams','Admit cards and symbol numbers','modules/exams/index.php',[],[] ,false,'print examination');
    $add('results','Enter marks','modules/results/marks_entry.php',[],['super_admin','school_admin','principal','teacher','exam_officer'],true,'marks entry grades scores internal practical theory');
    $add('results','Result ledger','modules/results/ledger.php',[],[],false,'grades grade sheet marksheet GPA');
    $add('results','Print report cards','modules/results/print_bulk.php',[],[],false,'bulk results marksheets');
    foreach(['questions'=>['modules/learning/questions.php','Question Bank'], 'exam_questions'=>['modules/learning/exam_questions.php','Exam Questions'], 'exam_mcqs'=>['modules/learning/exam_mcqs.php','MCQs']] as $parent=>[$controller,$label]){
        if($canAddQuestions){
            $extra=$parent==='questions'?['bank_ui'=>1]:[];
            $add($parent,'Add question — '.$label,$controller,['view'=>'add']+$extra,['super_admin','school_admin','teacher'],true,'create statement subquestions answers');
            $add($parent,'Import questions — '.$label,$controller,['view'=>'import']+$extra,['super_admin','school_admin','teacher'],true,'excel csv html upload sample template answers');
        }
        $add($parent,'Archived questions — '.$label,$controller,['archived'=>'1'],['super_admin','school_admin','teacher'],false,'deleted restore');
    }
    $add('learning','Study notes, formulas and reading materials','modules/learning/index.php',[],[],false,'notes chapters topics');
    if($canAddQuestions)$add('learning','Add / edit study notes','modules/learning/index.php',[],['super_admin','school_admin','teacher'],true,'heading formulas reading material');
    $add('ocr_scanner','OCR editable question paper editor','modules/learning/ocr_scanner.php',[],[],false,'scan image PDF extract local text');
    $add('curriculum','Chapters, topics and mathematics areas','modules/learning/curriculum.php',[],[],false,'curriculum topic syllabus');
    foreach(['paper_generator'=>'modules/learning/paper_generator.php','exam_paper_generator'=>'modules/learning/exam_paper_generator.php'] as $parent=>$controller){
        $add($parent,'Groups / sections — '.($parent==='paper_generator'?'Test paper':'Exam paper'),$controller,['generation_mode'=>'sections'],[],false,'header instructions MCQ short long questions');
        $add($parent,'Answer keys and print options — '.($parent==='paper_generator'?'Test paper':'Exam paper'),$controller,[],[],false,'language English Nepali marks cognitive');
    }
    $add('super_admin_schools','School packages and subscriptions','modules/super_admin/schools.php',[],['super_admin'],false,'activate renew plan school');
    $add('super_admin_subjects','Compulsory subjects and CDC catalog','modules/super_admin/subjects.php',[],['super_admin'],false,'classes default curriculum');
    $add('super_admin_schools','Individual school classes and subjects','modules/super_admin/schools.php',[],['super_admin'],false,'offerings assign hide compulsory optional setup');
    $add('school_settings','School name, logo and contact details','modules/settings/school.php',[],$admins,true,'header address phone email');
    $add('cdc_grading','Grading scale and GPA settings','modules/settings/cdc.php',[],$admins,false,'CDC grades pass fail assessment');
    foreach(['specification_grids'=>'modules/learning/specification_grids.php','exam_specification_grids'=>'modules/learning/exam_specification_grids.php'] as $parent=>$controller)$add($parent,'Cognitive quotas and specification grids — '.($parent==='specification_grids'?'Question Bank':'Exam paper'),$controller,[],[],false,'K U KU A HA CDC PABSON knowledge understanding application higher ability marks area');
    $add('accounting_fees','Fee categories, structures and discounts','modules/accounting/fees.php',[],[],false,'billing setup concession');
    $add('accounting_reports','Income, expenses and financial statements','modules/accounting/reports.php',[],[],false,'balance trial cash flow profit loss');
    $add('accounting_settings','Accounting audit trail and settings','modules/accounting/settings.php',[],[],false,'logs history financial setup');
    $add('users','Unlock school user accounts','modules/settings/users.php',[],$admins,true,'blocked suspended password login security');
    $add('super_admin_users','Unlock user accounts','modules/super_admin/users.php',[],['super_admin'],true,'blocked suspended password login security');
    foreach([['My profile','modules/auth/profile.php','account details'],['Change password','modules/auth/change_password.php','account security password'],['Sign out','modules/auth/logout.php','logout log out']] as [$label,$controller,$keywords])$features[]=['label'=>$label,'url'=>schoolRouteUrl($controller),'keywords'=>$keywords];
    if(in_array($role,$admins,true))$features[]=['label'=>'Account security'.($role==='super_admin'?' / backup codes':''),'url'=>schoolRouteUrl('modules/auth/security.php'),'keywords'=>'recovery lockout password administrator'];
    return $features;
}
