<?php
/** Catalog definitions belong to system admin; schools select teaching offerings.
 * Uses existing school copies and small app_meta overrides, without a schema change.
 * Explicit catalog saves/synchronization write data; ordinary requests only read it.
 */
require_once __DIR__.'/school_setup.php';

function offeringStateKey(int $school,int $class,int $subject=0):string {return 'school_offering:'.$school.':'.$class.':'.$subject;}
function offeringRequiredDefault(PDO $db,array $mapping):bool {
    $q=$db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=?');$q->execute(['catalog_required:'.$mapping['class_id'].':'.$mapping['subject_id']]);$value=$q->fetchColumn();
    return $value===false?empty($mapping['is_optional']):$value==='1';
}
function offeringSetRequiredDefault(PDO $db,int $class,int $subject,bool $required):void {
    if(!$db->inTransaction())throw new LogicException('Defaults require a catalog transaction.');
    $key='catalog_required:'.$class.':'.$subject;$db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute([$key]);$db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?)')->execute([$key,$required?'1':'0']);
}
function offeringState(PDO $db,int $school,int $class,int $subject=0):?string {
    $q=$db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=?');$q->execute([offeringStateKey($school,$class,$subject)]);
    $value=$q->fetchColumn();return $value===false?null:(string)$value;
}
function offeringStoreState(PDO $db,int $school,int $class,int $subject,?string $state):void {
    $key=offeringStateKey($school,$class,$subject);
    $db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute([$key]);
    if($state!==null)$db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?)')->execute([$key,$state]);
}
function offeringAssertActor(PDO $db,int $actor,string $role,int $school):void {
    $q=$db->prepare('SELECT role,school_id FROM users WHERE id=? AND status=1');$q->execute([$actor]);$user=$q->fetch();
    if(!$user||$user['role']!==$role||!in_array($role,['super_admin','school_admin'],true)||($role!=='super_admin'&&(int)$user['school_id']!==$school))throw new InvalidArgumentException('Only the system administrator or this school administrator can select school offerings.');
    if($school<1)throw new InvalidArgumentException('Choose an individual school.');
}
/** Reuse exact identities only; never renumber or delete linked school records. */
function offeringLocalCopy(PDO $db,string $table,int $school,array $source):int {
    $copyKey='catalog_copy:'.$table.':'.$school.':'.$source['id'];
    $stored=$db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=?');$stored->execute([$copyKey]);$copyId=(int)$stored->fetchColumn();
    $matches=[];
    if($copyId){$q=$db->prepare("SELECT * FROM `$table` WHERE school_id=? AND id=?");$q->execute([$school,$copyId]);$matches=$q->fetchAll();}
    if(!$matches){
    $q=$db->prepare("SELECT * FROM `$table` WHERE school_id=? AND source_id=? ORDER BY id");$q->execute([$school,$source['id']]);$matches=$q->fetchAll();
    }
    if(!$matches){
        $q=$db->prepare("SELECT * FROM `$table` WHERE school_id=? ORDER BY id");$q->execute([$school]);
        foreach($q as $row){
            $same=$table==='classes'?classNameKey($row['name'])===classNameKey($source['name']):catalogNameKey(subjectCanonicalName($row['name_en']))===catalogNameKey(subjectCanonicalName($source['name_en']))&&catalogNameKey((string)$row['code'])===catalogNameKey((string)$source['code'])&&$row['type']===$source['type'];
            if($same&&$table==='subjects'){
                // Legacy CDC subjects can have identical names/codes in different grades.
                $scope=$db->prepare('SELECT c.name FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.school_id=cs.school_id WHERE cs.school_id=? AND cs.subject_id=?');
                $scope->execute([0,$source['id']]);$sourceClasses=array_map('classNameKey',$scope->fetchAll(PDO::FETCH_COLUMN));
                $scope->execute([$school,$row['id']]);$localClasses=array_map('classNameKey',$scope->fetchAll(PDO::FETCH_COLUMN));
                if($sourceClasses&&$localClasses&&!array_intersect($sourceClasses,$localClasses))$same=false;
            }
            if($same)$matches[]=$row;
        }
    }
    if(count($matches)>1)throw new InvalidArgumentException('More than one school record matches catalog '.$table.' #'.$source['id'].'. Resolve its identity before synchronizing.');
    if(!$matches){
        $row=$source;$row['school_id']=$school;$row['source_id']=$source['id'];
        if($table==='classes'){$row['class_teacher_id']=null;$row['class_teacher_name']=null;$row['class_teacher_signature']=null;}
        $id=insertRecord($db,$table,$row);$db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?)')->execute([$copyKey,(string)$id]);return $id;
    }
    $row=$matches[0];
    $anySourceVisible=empty($source['is_hidden']);
    if($table==='subjects'){
        // Some older provisioning shared a single subject across grades. Keep its IDs;
        // refuse conflicting identity edits instead of duplicating or moving its history.
        $aliases=$db->prepare('SELECT meta_key FROM app_meta WHERE meta_key LIKE ? AND meta_value=?');$aliases->execute(['catalog_copy:subjects:'.$school.':%',(string)$row['id']]);
        foreach($aliases as $alias){$sourceId=(int)substr($alias['meta_key'],strrpos($alias['meta_key'],':')+1);if($sourceId===(int)$source['id'])continue;$other=schoolRecord($db,'subjects',$sourceId,0);$anySourceVisible=$anySourceVisible||empty($other['is_hidden']);foreach(['name_en','code','type'] as $field)if(catalogNameKey((string)$other[$field])!==catalogNameKey((string)$source[$field]))throw new InvalidArgumentException('An older school shares this subject identity across grades. Align the matching catalog names, codes and student-elective type before changing it. Existing records were retained.');}
    }
    $db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute([$copyKey]);$db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?)')->execute([$copyKey,(string)$row['id']]);
    $fields=$table==='classes'?['name','name_np','level','grading_type']:['name_en','name_np','code','type','full_marks','pass_marks','credit_hours','th_credit_hours','in_credit_hours','th_full_marks','th_pass_marks','in_full_marks','in_pass_marks','assessment_method','curriculum_version','internal_components'];
    $values=['source_id'=>(int)($row['source_id']?:$source['id'])];foreach($fields as $field)if(array_key_exists($field,$source))$values[$field]=$source[$field];
    if($table==='subjects')$values['is_hidden']=$anySourceVisible?0:1;
    // Local hiding is a system-admin exception. Global hiding remains authoritative too.
    if($table==='classes')$values['is_active']=!empty($source['is_active'])&&offeringState($db,$school,(int)$row['id'])!=='blocked'?1:0;
    $db->prepare("UPDATE `$table` SET ".implode(',',array_map(static fn($f)=>"`$f`=?",array_keys($values))).' WHERE id=? AND school_id=?')->execute([...array_values($values),$row['id'],$school]);
    return (int)$row['id'];
}
/** Caller owns the transaction and global catalog lock, then school locks in ID order. */
function syncSchoolOfferings(PDO $db,int $school,bool $newSchool=false):void {
    require_once __DIR__.'/catalog_validation.php';
    if(!$db->inTransaction())throw new LogicException('Catalog synchronization requires a transaction.');
    catalogWriteLock($db,$school);$classes=[];$subjects=[];
    foreach($db->query('SELECT * FROM classes WHERE school_id=0 ORDER BY id')->fetchAll() as $source)$classes[$source['id']]=offeringLocalCopy($db,'classes',$school,$source);
    foreach($db->query('SELECT * FROM subjects WHERE school_id=0 ORDER BY id')->fetchAll() as $source)$subjects[$source['id']]=offeringLocalCopy($db,'subjects',$school,$source);
    $q=$db->prepare('SELECT * FROM class_subjects WHERE school_id=? AND class_id=? AND subject_id=? ORDER BY id');
    foreach($db->query('SELECT * FROM class_subjects WHERE school_id=0 ORDER BY id')->fetchAll() as $source){
        if(!isset($classes[$source['class_id']],$subjects[$source['subject_id']]))continue;
        $class=$classes[$source['class_id']];$subject=$subjects[$source['subject_id']];$q->execute([$school,$class,$subject]);$rows=$q->fetchAll();
        $link='catalog_mapping:'.$school.':'.$class.':'.$subject;$db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute([$link]);$db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?)')->execute([$link,(string)$source['id']]);
        $state=offeringState($db,$school,$class,$subject);
        $sourceSubject=schoolRecord($db,'subjects',(int)$source['subject_id'],0);
        $available=!empty($source['is_active'])&&empty($sourceSubject['is_hidden']);
        $required=offeringRequiredDefault($db,$source);
        if($state===null&&$rows&&!$required&&!$newSchool){
            $state=array_filter($rows,static fn($r)=>!empty($r['is_active']))?'enabled':'disabled';
            offeringStoreState($db,$school,$class,$subject,$state);
        }
        $fields=['credit_hours','th_credit_hours','in_credit_hours','th_full_marks','th_pass_marks','in_full_marks','in_pass_marks','is_optional','assessment_method','curriculum_version','curriculum_key','curriculum_status','source_notes','subject_order','internal_components'];
        $values=[];foreach($fields as $field)if(array_key_exists($field,$source))$values[$field]=$source[$field];
        if(!$rows){
            $active=$available&&$state!=='blocked'&&($state==='enabled'||$required);
            insertRecord($db,'class_subjects',$values+['class_id'=>$class,'subject_id'=>$subject,'school_id'=>$school,'teacher_id'=>null,'section_id'=>null,'is_active'=>$active?1:0]);
        }else foreach($rows as $row){
            $active=$available&&$state!=='blocked'&&($state==='enabled'||($required||($state!=='disabled'&&!$newSchool&&!empty($row['is_active']))));
            $update=$values+['is_active'=>$active?1:0];
            $db->prepare('UPDATE class_subjects SET '.implode(',',array_map(static fn($f)=>"`$f`=?",array_keys($update))).' WHERE id=? AND school_id=?')->execute([...array_values($update),$row['id'],$school]);
        }
    }
}
function syncAllSchoolOfferings(PDO $db):void {
    require_once __DIR__.'/catalog_validation.php';
    if(!$db->inTransaction())throw new LogicException('Catalog synchronization requires a transaction.');
    catalogWriteLock($db,0);
    foreach($db->query('SELECT id FROM schools ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $school)syncSchoolOfferings($db,(int)$school);
}
/** Every active workflow uses this same school/class/subject membership predicate. */
function schoolSubjectAssignedSql(string $school,string $class,string $subject):string {
    $link=DB_TYPE==='mysql'?"CONCAT('catalog_mapping:',offered.school_id,':',offered.class_id,':',offered.subject_id)":"('catalog_mapping:'||offered.school_id||':'||offered.class_id||':'||offered.subject_id)";
    return "EXISTS(SELECT 1 FROM class_subjects offered JOIN classes offered_class ON offered_class.id=offered.class_id AND offered_class.school_id=offered.school_id JOIN subjects offered_subject ON offered_subject.id=offered.subject_id AND offered_subject.school_id=offered.school_id WHERE offered.school_id=$school AND offered.class_id=$class AND offered.subject_id=$subject AND offered.is_active=1 AND offered_class.is_active=1 AND COALESCE(offered_subject.is_hidden,0)=0 AND (EXISTS(SELECT 1 FROM app_meta source_link WHERE source_link.meta_key=$link) OR NOT EXISTS(SELECT 1 FROM subjects source_subject WHERE source_subject.id=offered_subject.source_id AND source_subject.school_id=0 AND COALESCE(source_subject.is_hidden,0)=1)) AND NOT EXISTS(SELECT 1 FROM app_meta source_link JOIN class_subjects source_mapping ON source_mapping.id=CAST(source_link.meta_value AS UNSIGNED) AND source_mapping.school_id=0 JOIN subjects source_subject ON source_subject.id=source_mapping.subject_id AND source_subject.school_id=0 WHERE source_link.meta_key=$link AND (source_mapping.is_active=0 OR COALESCE(source_subject.is_hidden,0)=1)) AND NOT EXISTS(SELECT 1 FROM classes source_class WHERE source_class.id=offered_class.source_id AND source_class.school_id=0 AND source_class.is_active=0))";
}
function schoolSubjectAssigned(PDO $db,int $school,int $class,int $subject):bool {
    $q=$db->query('SELECT CASE WHEN '.schoolSubjectAssignedSql((string)$school,(string)$class,(string)$subject).' THEN 1 ELSE 0 END');return (bool)$q->fetchColumn();
}
function schoolOfferedSubjects(PDO $db,int $school,?int $class=null):array {
    $predicate=schoolSubjectAssignedSql('s.school_id',$class===null?'cs.class_id':(string)$class,'s.id');
    $q=$db->prepare('SELECT DISTINCT s.* FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=s.school_id WHERE s.school_id=? AND '.$predicate.' ORDER BY s.name_en,s.id');$q->execute([$school]);return $q->fetchAll();
}
function schoolExamOfferingRows(PDO $db,int $school,array $exam,array $rows,int $class=0):array {
    if($school===0)return $rows;
    if(!empty($exam['is_locked'])||!empty($exam['is_published'])){
        $q=$db->prepare('SELECT meta_key FROM app_meta WHERE meta_key LIKE ?');$q->execute(['exam_offering_excluded:'.(int)$exam['id'].':%']);$excluded=[];foreach($q as $r)$excluded[(int)substr($r['meta_key'],strrpos($r['meta_key'],':')+1)]=true;
        return array_values(array_filter($rows,static fn($r)=>!isset($excluded[(int)($r['mapping_id']??$r['id']??0)])));
    }
    $q=$db->prepare('SELECT cs.class_id,cs.subject_id FROM class_subjects cs WHERE cs.school_id=? AND '.schoolSubjectAssignedSql('cs.school_id','cs.class_id','cs.subject_id'));$q->execute([$school]);$allowed=[];foreach($q as $r)$allowed[$r['class_id'].'/'.$r['subject_id']]=true;
    return array_values(array_filter($rows,static fn($r)=>isset($allowed[($r['class_id']??$class).'/'.$r['subject_id']])));
}
/** Only selection, teachers, sections and weekly periods are school-admin writable. */
function saveSchoolOffering(PDO $db,int $school,int $actor,string $role,int $class,int $subject,string $action,array $input=[]):void {
    require_once __DIR__.'/catalog_validation.php';
    offeringAssertActor($db,$actor,$role,$school);
    $db->beginTransaction();
    try{
        catalogWriteLock($db,0);catalogWriteLock($db,$school);
        $c=schoolRecord($db,'classes',$class,$school);$s=schoolRecord($db,'subjects',$subject,$school);
        $q=$db->prepare('SELECT * FROM class_subjects WHERE school_id=? AND class_id=? AND subject_id=? ORDER BY id');$q->execute([$school,$class,$subject]);$mappings=$q->fetchAll();
        if(!$mappings)throw new InvalidArgumentException('Ask the system administrator to map this subject to the class first.');
        $source=null;
        $link=$db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=?');$link->execute(['catalog_mapping:'.$school.':'.$class.':'.$subject]);$sourceMapping=(int)$link->fetchColumn();
        if($sourceMapping)$source=schoolRecord($db,'class_subjects',$sourceMapping,0);
        if(!$source&&$c['source_id']&&$s['source_id']){$q->execute([0,$c['source_id'],$s['source_id']]);$source=$q->fetch()?:null;}
        $compulsory=$source?offeringRequiredDefault($db,$source):empty($mappings[0]['is_optional']);$blocked=offeringState($db,$school,$class,$subject)==='blocked';
        if(in_array($action,['assign','unassign','block','restore'],true)){
            if(in_array($action,['block','restore'],true)&&$role!=='super_admin')throw new InvalidArgumentException('Only system admin may change school exceptions.');
            if($role!=='super_admin'&&($blocked||$compulsory))throw new InvalidArgumentException($blocked?'System admin has hidden this subject for your school.':'Compulsory subjects are assigned by system admin. Request a school exception to hide one.');
            $active=in_array($action,['assign','restore'],true);
            if($active&&(!empty($s['is_hidden'])||empty($c['is_active'])||($source&&(empty($source['is_active'])||!empty(schoolRecord($db,'subjects',(int)$source['subject_id'],0)['is_hidden'])))))throw new InvalidArgumentException('The catalog subject or class is hidden. Ask system admin to enable it first.');
            $state=$action==='block'?'blocked':($active?'enabled':'disabled');
            offeringStoreState($db,$school,$class,$subject,$state);
            $db->prepare('UPDATE class_subjects SET is_active=? WHERE school_id=? AND class_id=? AND subject_id=?')->execute([$active?1:0,$school,$class,$subject]);
        }elseif($action==='teachers'){
            if(!schoolSubjectAssigned($db,$school,$class,$subject))throw new InvalidArgumentException('Assign this subject before setting its teachers.');
            $ids=$input['teacher_ids']??[];if(!is_array($ids))throw new InvalidArgumentException('Choose valid school teachers.');$teachers=[];
            foreach($ids as $id){if(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/',(string)$id))throw new InvalidArgumentException('Choose valid school teachers.');$t=schoolRecord($db,'users',(int)$id,$school);if($t['role']!=='teacher'||!$t['status'])throw new InvalidArgumentException('Choose active teachers from this school.');$teachers[]=(int)$id;}
            $section=(int)($input['section_id']??0);if($section&&schoolRecord($db,'sections',$section,$school)['class_id']!=$class)throw new InvalidArgumentException('Choose a section of this class.');
            $weekly=$input['weekly_periods']??'';if(!is_scalar($weekly)||($weekly!==''&&(!preg_match('/^\d{1,2}$/',(string)$weekly)||(int)$weekly>50)))throw new InvalidArgumentException('Weekly periods must be 0 to 50.');
            $mappingId=(int)($input['offering_mapping']??0);
            if(!$mappingId&&count($mappings)===1)$mappingId=(int)$mappings[0]['id'];
            if(!in_array($mappingId,array_map('intval',array_column($mappings,'id')),true))throw new InvalidArgumentException('Choose the class-subject section assignment to update.');
            $db->prepare('UPDATE class_subjects SET teacher_id=?,section_id=?,weekly_periods=? WHERE school_id=? AND class_id=? AND subject_id=? AND id=?')->execute([$teachers?implode(',',array_unique($teachers)):null,$section?:null,$weekly===''?null:(int)$weekly,$school,$class,$subject,$mappingId]);
        }else throw new InvalidArgumentException('Choose a valid school offering action.');
        $db->prepare('INSERT INTO activity_logs(user_id,action,table_name,record_id,ip_address) VALUES(?,?,?,?,?)')->execute([$actor,'School offering '.$action,'class_subjects',$mappings[0]['id'],$_SERVER['REMOTE_ADDR']??'local']);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
