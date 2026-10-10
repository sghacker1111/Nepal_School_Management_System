<?php
function schoolPackages(): array {
    return ['full'=>'Full ERP','result_system'=>'Result System','learning_system'=>'Learning System','paper_generator'=>'Paper Generator System','question_view'=>'Question View System','question_add'=>'Question Add System','accounting'=>'Accounting & Billing System','exam_generator'=>'Legacy Exam Generator Package'];
}
function schoolPackageAllows(string $package,string $module): bool {
    if(!array_key_exists($package,schoolPackages()))return false;
    if($package==='full')return true;
    if(in_array($module,['auth','settings','classes','subjects'],true))return true;
    $features=[
        'result_system'=>['students','teachers','attendance','exams','results','reports'],
        'learning_system'=>['learning','curriculum','specification_grids'],
        'paper_generator'=>['paper_generator','questions','curriculum','specification_grids','ocr_scanner'],
        'question_view'=>['questions'],
        'question_add'=>['questions'],
        'accounting'=>['accounting','students'],
        'exam_generator'=>['learning','curriculum','paper_generator','questions','specification_grids','ocr_scanner']
    ];
    return in_array($module,$features[$package]??[],true);
}

/** Null preserves a legacy single package. An explicit empty array revokes access. */
function schoolSelectedPackages(array $school):array {
    if(($school['packages_json']??null)===null){$package=$school['subscription_type']??'';return array_key_exists($package,schoolPackages())?[$package]:[];}
    try{$packages=json_decode($school['packages_json'],true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){return [];}
    if(!is_array($packages)||!array_is_list($packages))return [];
    foreach($packages as $package)if(!is_string($package)||!array_key_exists($package,schoolPackages()))return [];
    return array_values(array_unique($packages));
}
function schoolSubscriptionState(array $school,?string $today=null):string {
    require_once __DIR__.'/nepali_dates.php';
    $today=$today??(new DateTimeImmutable('now',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d');
    if(!(int)($school['subscription_enabled']??1))return 'disabled';
    if(!schoolSelectedPackages($school))return 'no_packages';
    $mode=$school['subscription_mode']??'no_expiry';
    if($mode==='no_expiry')return 'active';
    if(!in_array($mode,['yearly','custom'],true))return 'invalid';
    try{$start=schoolDateToAd($school['subscription_start']??'');$end=schoolDateToAd($school['subscription_end']??'');}catch(InvalidArgumentException $e){return 'invalid';}
    if(!$start||!$end||$end<$start)return 'invalid';
    if($today<$start)return 'scheduled';
    return $today>$end?'expired':'active'; // End date is inclusive in Nepal time.
}
function schoolSubscriptionAllows(array $school,string $module,?string $today=null):bool {
    if(schoolSubscriptionState($school,$today)!=='active')return false;
    foreach(schoolSelectedPackages($school) as $package)if(schoolPackageAllows($package,$module))return true;
    return false;
}
function schoolSubscriptionCanAddQuestions(array $school):bool {
    return schoolSubscriptionState($school)==='active'&&(bool)array_intersect(schoolSelectedPackages($school),['full','question_add','exam_generator']);
}
function schoolSubscriptionInput(array $input):array {
    require_once __DIR__.'/nepali_dates.php';
    $packages=$input['packages']??[];
    if(!is_array($packages)||!array_is_list($packages))throw new InvalidArgumentException('Choose packages using the checkboxes.');
    foreach($packages as $package)if(!is_string($package)||!array_key_exists($package,schoolPackages()))throw new InvalidArgumentException('Choose valid subscription packages.');
    $packages=array_values(array_unique($packages));sort($packages);
    $mode=$input['subscription_mode']??'yearly';
    if(!in_array($mode,['no_expiry','yearly','custom'],true))throw new InvalidArgumentException('Choose yearly, custom dates or no expiry.');
    $enabled=isset($input['subscription_enabled'])?1:0;
    if($enabled&&!$packages)throw new InvalidArgumentException('Tick at least one package before activating the school account.');
    $start=null;$end=null;
    if($mode!=='no_expiry'){
        $start=schoolDateToAd($input['subscription_start']??'');
        if(!$start)throw new InvalidArgumentException('Choose a subscription start date.');
        if($mode==='yearly'){
            $begin=new DateTimeImmutable($start);
            // Clamp leap-day anniversaries, then make the renewal boundary exclusive.
            $year=(int)$begin->format('Y')+1;$month=(int)$begin->format('m');$day=(int)$begin->format('d');
            $first=$begin->setDate($year,$month,1);$next=$first->setDate($year,$month,min($day,(int)$first->format('t')));
            $end=$next->modify('-1 day')->format('Y-m-d');
        }else{$end=schoolDateToAd($input['subscription_end']??'');if(!$end)throw new InvalidArgumentException('Choose an expiry date.');}
        if($end<$start)throw new InvalidArgumentException('Expiry date must not be before the subscription start date.');
    }
    return ['packages_json'=>json_encode($packages,JSON_THROW_ON_ERROR),'subscription_type'=>in_array('full',$packages,true)?'full':($packages[0]??'unavailable'),'subscription_mode'=>$mode,'subscription_start'=>$start,'subscription_end'=>$end,'subscription_enabled'=>$enabled];
}
function saveSchoolSubscription(PDO $db,int $school,array $config):void {
    $q=$db->prepare('SELECT id FROM schools WHERE id=?');$q->execute([$school]);if(!$q->fetchColumn())throw new InvalidArgumentException('School not found.');
    $db->prepare('UPDATE schools SET packages_json=?,subscription_type=?,subscription_mode=?,subscription_start=?,subscription_end=?,subscription_enabled=? WHERE id=?')->execute([$config['packages_json'],$config['subscription_type'],$config['subscription_mode'],$config['subscription_start'],$config['subscription_end'],$config['subscription_enabled'],$school]);
}
function migrateSchoolSubscriptionSchema(PDO $db):void {
    $fields=['packages_json'=>'TEXT','subscription_mode'=>"VARCHAR(20) NOT NULL DEFAULT 'no_expiry'",'subscription_start'=>'DATE','subscription_end'=>'DATE','subscription_enabled'=>'INTEGER NOT NULL DEFAULT 1'];
    $existing=dbColumns($db,'schools');foreach($fields as $name=>$type)if(!in_array($name,$existing,true))$db->exec("ALTER TABLE schools ADD COLUMN $name $type");
    // Do not invent purchased packages or expiry dates for existing schools.
}
