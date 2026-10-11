<?php
require_once __DIR__.'/school_validation.php';
function catalogNameKey(string $name):string {
    $name=html_entity_decode($name,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $name=trim(preg_replace('/\s+/u',' ',$name));
    return function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
}
function classNameKey(string $name):string {
    $name=catalogNameKey(strtr($name,['०'=>'0','१'=>'1','२'=>'2','३'=>'3','४'=>'4','५'=>'5','६'=>'6','७'=>'7','८'=>'8','९'=>'9']));
    if(preg_match('/^(?:(?:class|grade|कक्षा)\s*)?0*([1-9]|1[0-2])$/u',$name,$match))return 'grade:'.(int)$match[1];
    return $name;
}
/** Called inside the mutation transaction, before checking and writing names. */
function catalogWriteLock(PDO $db,int $school):void {
    if(!$db->inTransaction())throw new LogicException('Catalog changes require a transaction.');
    if($school>0)$db->prepare('UPDATE schools SET workflow_version=workflow_version WHERE id=?')->execute([$school]);
    else $db->exec("UPDATE app_meta SET meta_value=meta_value WHERE meta_key='workflow_schema'");
}
function validateUniqueClassName(PDO $db,int $school,string $name,int $except=0):void {
    $key=classNameKey($name);if($key==='')throw new InvalidArgumentException('Class name is required.');
    $q=$db->prepare('SELECT id,name FROM classes WHERE school_id=? AND id<>?');$q->execute([$school,$except]);
    foreach($q as $row)if(classNameKey($row['name'])===$key)throw new InvalidArgumentException('Class with this name or grade already exists (including archived classes). Edit the existing class instead.');
}
function validateUniqueClassSubject(PDO $db,int $school,int $class,array $subject,int $exceptSubject=0):void {
    schoolRecord($db,'classes',$class,$school);
    $keys=[];foreach(['name_en','name_np','code'] as $field)$keys[$field]=catalogNameKey((string)($subject[$field]??''));
    if($keys['name_en']==='')throw new InvalidArgumentException('Subject name in English is required.');
    $q=$db->prepare('SELECT s.id,s.name_en,s.name_np,s.code FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id WHERE cs.school_id=? AND cs.class_id=? AND cs.subject_id<>?');$q->execute([$school,$class,$exceptSubject]);
    foreach($q as $r)foreach($keys as $field=>$value)if($value!==''&&$value===catalogNameKey((string)$r[$field]))throw new InvalidArgumentException('Duplicate Subject Blocked: this class already has a subject with the same name or code, including hidden or optional subjects.');
}
function archiveLegacySevenClass(PDO $db,int $school,int $id):void {
    $class=schoolRecord($db,'classes',$id,$school);
    $q=$db->prepare("SELECT id FROM users WHERE school_id=? AND username=? AND role='teacher'");$q->execute([$school,'sgsunil']);
    if($school<1||$class['name']!=='7'||!$q->fetchColumn())throw new InvalidArgumentException('This cleanup applies only to the class named exactly 7 in the school containing teacher sgsunil.');
    // Never cascade-delete students, results, fees or teaching history.
    $db->prepare('UPDATE classes SET is_active=0 WHERE school_id=? AND id=?')->execute([$school,$id]);
}
