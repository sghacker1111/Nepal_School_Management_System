<?php
/** Signature image, rule, person's name, then post. Width follows the content. */
function printSignature(string $name,string $post,?string $image=null):string {
    $escape=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    static $styled=false;$style='';
    if(!$styled){$styled=true;$style='<style>.print-signature{display:inline-grid;grid-template-columns:minmax(5rem,max-content);justify-items:center;max-width:100%;text-align:center;vertical-align:bottom;break-inside:avoid;font-size:inherit;line-height:1.25}.print-signature-image{height:32px;display:flex;align-items:end;justify-content:center;width:100%;margin-bottom:3px}.print-signature-image img{max-height:32px;max-width:140px;object-fit:contain}.print-signature-rule{border-top:1px solid currentColor;width:100%;margin-bottom:3px}.print-signature-name{font-weight:600;max-width:100%;overflow-wrap:anywhere}.print-signature-post{font-size:.9em;max-width:100%;overflow-wrap:anywhere}.print-signatures{display:flex;justify-content:space-around;align-items:end;gap:15px;margin-top:20px}</style>';}
    $picture='';
    if($image&&preg_match('/\.(?:png|jpe?g)$/i',$image))$picture='<img src="'.$escape(APP_URL.'/uploads/'.rawurlencode(basename(str_replace('\\','/',$image)))).'" alt="'.$escape($post.' signature').'">';
    return $style.'<div class="print-signature"><div class="print-signature-image">'.$picture.'</div><div class="print-signature-rule"></div><div class="print-signature-name">'.$escape($name).'</div><div class="print-signature-post">'.$escape($post).'</div></div>';
}
function schoolClassTeacherSignature(PDO $db,int $schoolId,array $school,int $classId):string {
    static $cache=[];$key=spl_object_id($db).':'.$schoolId.':'.$classId;
    if(!isset($cache[$key])){
        $q=$db->prepare("SELECT c.class_teacher_name,c.class_teacher_signature,u.full_name,u.signature FROM classes c LEFT JOIN users u ON u.id=c.class_teacher_id AND u.school_id=c.school_id AND u.role='teacher' WHERE c.id=? AND c.school_id=?");
        $q->execute([$classId,$schoolId]);$cache[$key]=$q->fetch()?:[];
    }
    $c=$cache[$key];
    return printSignature($c['full_name']??$c['class_teacher_name']??'','Class Teacher',($c['class_teacher_signature']??null)?:($c['signature']??$school['class_teacher_signature']??null));
}
function schoolPrintSignatures(PDO $db,int $schoolId,array $school,int $classId):string {
    return '<div class="print-signatures">'.schoolClassTeacherSignature($db,$schoolId,$school,$classId)
        .printSignature($school['exam_coordinator_name']??'','Exam Coordinator',$school['exam_coordinator_signature']??null)
        .printSignature($school['principal_name']??'','Head Teacher',$school['principal_signature']??null).'</div>';
}
function printUserSignature(PDO $db,int $school,int $user,string $post):string {
    $q=$db->prepare("SELECT full_name,signature FROM users WHERE id=? AND (school_id=? OR role='super_admin')");$q->execute([$user,$school]);$person=$q->fetch()?:[];
    return printSignature($person['full_name']??'',$post,$person['signature']??null);
}
