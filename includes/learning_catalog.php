<?php
require_once __DIR__.'/subject_names.php';
require_once __DIR__.'/catalog_validation.php';
/** Identity resolution is independent of actor privileges and never uses substrings. */
function learningCatalogScope(PDO $db,int $school,int $class,int $subject):array {
    $q=$db->prepare('SELECT c.*,s.id subject_id,s.name_en,s.code,s.type,s.source_id subject_source_id FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=c.school_id JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id WHERE c.school_id=? AND c.id=? AND s.id=?');
    $q->execute([$school,$class,$subject]);$local=$q->fetch(PDO::FETCH_ASSOC);
    if(!$local)throw new InvalidArgumentException('Choose a subject assigned to the selected class.');
    $scope=['school_id'=>$school,'class_id'=>$class,'subject_id'=>$subject];
    if($school===0)return ['local'=>$scope,'shared'=>$scope];
    $q=$db->query('SELECT DISTINCT c.id class_id,c.name,s.id subject_id,s.name_en,s.code,s.type FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id AND s.school_id=0 WHERE c.school_id=0');
    $candidates=[];$sources=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(classNameKey($row['name'])!==classNameKey($local['name'])||$row['type']!==$local['type'])continue;
        $nameMatch=catalogNameKey(subjectCanonicalName($row['name_en']))===catalogNameKey(subjectCanonicalName($local['name_en']));
        $codeMatch=trim((string)$local['code'])!==''&&catalogNameKey((string)$row['code'])===catalogNameKey((string)$local['code']);
        if(!$nameMatch&&!$codeMatch)continue;
        $mapped=['school_id'=>0,'class_id'=>(int)$row['class_id'],'subject_id'=>(int)$row['subject_id']];
        if((int)$local['subject_source_id']===(int)$row['subject_id'])$sources[]=$mapped;
        if($codeMatch||$nameMatch)$candidates[]=$mapped;
    }
    $matches=$sources?:$candidates;
    if(count($matches)>1)throw new InvalidArgumentException('Shared subject identity is ambiguous. Ask the administrator to correct its mapping.');
    return ['local'=>$scope,'shared'=>$matches[0]??null];
}
