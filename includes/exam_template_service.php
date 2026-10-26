<?php
require_once __DIR__.'/catalog_validation.php';
require_once __DIR__.'/report_issue.php';
require_once __DIR__.'/grading.php';

function examTemplateId($value):int {
    if((!is_string($value)&&!is_int($value))||!preg_match('/^[1-9][0-9]{0,9}$/',(string)$value)||(float)$value>2147483647)throw new InvalidArgumentException('Choose a valid exam template.');
    return (int)$value;
}
function examTemplates(PDO $db,bool $includeInactive=false):array {
    return $db->query('SELECT * FROM exam_templates'.($includeInactive?'':' WHERE is_active=1').' ORDER BY term_no,id')->fetchAll();
}
function examTemplate(PDO $db,int $id,bool $active=false,bool $lock=false):array {
    $q=$db->prepare('SELECT * FROM exam_templates WHERE id=?'.($active?' AND is_active=1':'').($lock&&DB_TYPE==='mysql'?' FOR UPDATE':''));$q->execute([$id]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('The exam template is unavailable. Choose an active default.');
    return $row;
}
function examTemplateActor(PDO $db,int $actor,?int $school=null):array {
    $q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$actor]);$u=$q->fetch();
    if(!$u||!$u['status']||($school===null?$u['role']!=='super_admin':(!in_array($u['role'],['super_admin','school_admin','exam_officer'],true)||($u['role']!=='super_admin'&&(int)$u['school_id']!==$school))))throw new InvalidArgumentException('Your active account cannot change these exam settings.');
    return $u;
}
function assertExamConfigurationEditable(array $exam):void {
    if(($exam['creation_mode']??'manual')==='automatic')throw new InvalidArgumentException('Automatic exam settings are read-only. Create a manual exam to customize the defaults.');
}
/** Universal class-subject rows; identifiers are compared exactly, not by substrings. */
function examTemplateCatalog(PDO $db):array {
    $rows=$db->query('SELECT cs.*,c.name class_name,s.name_en,s.code,s.id source_subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.school_id=0 JOIN subjects s ON s.id=cs.subject_id AND s.school_id=0 WHERE cs.school_id=0 AND c.is_active=1 AND cs.is_active=1 AND COALESCE(s.is_hidden,0)=0 AND '.schoolSubjectAssignedSql('cs.school_id','cs.class_id','cs.subject_id').' ORDER BY c.id,cs.subject_order,cs.id')->fetchAll();
    $catalog=[];
    foreach($rows as $r){
        $r['class_key']=classNameKey($r['class_name']);
        $r['theory_full']=$r['th_full_marks']??100;$r['theory_pass']=$r['th_pass_marks']??35;
        $r['practical_full']=$r['in_full_marks']??0;$r['practical_pass']=$r['in_pass_marks']??0;
        $r['external_credit_hours']=$r['th_credit_hours'];$r['internal_credit_hours']=$r['in_credit_hours'];
        $catalog[(int)$r['id']]=$r;
    }
    return $catalog;
}
function examTemplateSchemes(PDO $db,array $template):array {
    if($template['config_json']===null)return examTemplateCatalog($db);
    $config=json_decode($template['config_json'],true,512,JSON_THROW_ON_ERROR);
    if(!is_array($config)||!is_array($config['subjects']??null))throw new InvalidArgumentException('The exam template configuration needs administrator review.');
    return $config['subjects'];
}
function examTemplateSettings(array $input,array $old):array {
    $r=$old;
    foreach(['name','type','result_system','date_calendar','date_format'] as $k){$v=$input[$k]??$old[$k];if(!is_string($v))throw new InvalidArgumentException('Enter valid exam default settings.');$r[$k]=trim($v);}
    if($r['name']===''||mb_strlen($r['name'])>150||preg_match('/[[:cntrl:]]/u',$r['name']))throw new InvalidArgumentException('Exam name must be 1–150 characters.');
    foreach(['type'=>['terminal','final','unit','practical'],'result_system'=>['gpa','percentage'],'date_calendar'=>['AD','BS','both'],'date_format'=>['ymd','dmy','long']] as $k=>$choices)if(!in_array($r[$k],$choices,true))throw new InvalidArgumentException('Choose valid exam type, result system and calendar settings.');
    foreach(['has_internal','is_active'] as $k){$v=$input[$k]??0;if(!in_array($v,[0,1,'0','1'],true))throw new InvalidArgumentException('Choose valid default options.');$r[$k]=(int)$v;}
    $term=$input['term_no']??$old['term_no'];if(!in_array($term,[1,2,3,'1','2','3'],true))throw new InvalidArgumentException('Choose First, Second or Final term.');$r['term_no']=(int)$term;
    if($r['type']==='unit')$r['has_internal']=0;
    return $r;
}
function saveExamTemplate(PDO $db,int $actor,int $id,array $input):array {
    examTemplateActor($db,$actor);$db->beginTransaction();
    try{
        $old=examTemplate($db,$id,false,true);
        if(examTemplateId($input['revision']??0)!==(int)$old['revision'])throw new InvalidArgumentException('These defaults changed in another session. Reload before saving.');
        $r=examTemplateSettings($input,$old);$schemes=examTemplateSchemes($db,$old);$catalog=examTemplateCatalog($db);
        $overrides=$input['schemes']??[];if(!is_array($overrides))throw new InvalidArgumentException('Enter valid subject default settings.');
        foreach($overrides as $mapping=>$values){
            if(!isset($catalog[$mapping])||!is_array($values))throw new InvalidArgumentException('Choose a universal class-subject configuration.');
            $scheme=$schemes[$mapping]??$catalog[$mapping];
            foreach(['theory_full','theory_pass','practical_full','practical_pass','credit_hours','external_credit_hours','internal_credit_hours'] as $k){
                $v=$values[$k]??$scheme[$k];if($v===null||$v===''){if(in_array($k,['external_credit_hours','internal_credit_hours'],true)){$scheme[$k]=null;continue;}}
                if(!is_scalar($v)||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>1000||round((float)$v,2)!==(float)$v)throw new InvalidArgumentException('Marks and credits must be 0–1000, with at most two decimals.');$scheme[$k]=(float)$v;
            }
            if($scheme['theory_full']+$scheme['practical_full']<=0||$scheme['theory_pass']>$scheme['theory_full']||$scheme['practical_pass']>$scheme['practical_full']||$scheme['credit_hours']<=0)throw new InvalidArgumentException('Pass marks cannot exceed full marks; full marks and credit hours must be positive.');
            if(($scheme['assessment_method']??'')==='THEMATIC_CONTINUOUS'&&($scheme['theory_full']!=100||$scheme['practical_full']!=0))throw new InvalidArgumentException('Thematic assessment uses an overall 100-mark score, not a TH/IN split.');
            if($scheme['external_credit_hours']!==null&&$scheme['internal_credit_hours']!==null&&abs($scheme['external_credit_hours']+$scheme['internal_credit_hours']-$scheme['credit_hours'])>0.005)throw new InvalidArgumentException('TH and IN credit hours must add up to the total credit hours.');
            $schemes[$mapping]=$scheme;
        }
        $json=json_encode(['subjects'=>$schemes],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $db->prepare('INSERT INTO exam_template_history(template_id,revision,snapshot_json,changed_by) VALUES(?,?,?,?)')->execute([$id,$old['revision'],json_encode($old,JSON_THROW_ON_ERROR),$actor]);
        $db->prepare('UPDATE exam_templates SET name=?,type=?,term_no=?,has_internal=?,result_system=?,date_calendar=?,date_format=?,is_active=?,config_json=?,revision=revision+1,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$r['name'],$r['type'],$r['term_no'],$r['has_internal'],$r['result_system'],$r['date_calendar'],$r['date_format'],$r['is_active'],$json,$actor,$id]);
        $db->commit();return examTemplate($db,$id);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function examTemplateSnapshot(PDO $db,array $template):array {
    $snapshot=[];foreach(['id','revision','name','type','term_no','has_internal','result_system','date_calendar','date_format'] as $k)$snapshot[$k]=$template[$k];
    $snapshot['subjects']=examTemplateSchemes($db,$template);return $snapshot;
}
/** Both modes load a copy; only manual mode accepts school overrides. */
function createExamFromTemplate(PDO $db,int $school,int $actor,array $input):int {
    examTemplateActor($db,$actor,$school);if($school<1)throw new InvalidArgumentException('Choose a school before creating an exam.');
    $mode=$input['creation_mode']??'manual';if(!in_array($mode,['manual','automatic'],true))throw new InvalidArgumentException('Choose automatic or manual exam creation.');
    $id=examTemplateId($input['template_id']??($db->query('SELECT id FROM exam_templates WHERE is_active=1 ORDER BY term_no,id LIMIT 1')->fetchColumn()?:0));
    $db->beginTransaction();
    try{
        // Row write lock serializes capture of a revision with administrator edits.
        $db->prepare('UPDATE exam_templates SET revision=revision WHERE id=?')->execute([$id]);
        $template=examTemplate($db,$id,true,true);$snapshot=examTemplateSnapshot($db,$template);
        if(array_key_exists('template_revision',$input)&&examTemplateId($input['template_revision'])!==(int)$template['revision'])throw new InvalidArgumentException('These exam defaults changed while you were creating the exam. Reload and review the current defaults before saving.');
        if(array_key_exists('academic_year_id',$input)){
            $yearId=$input['academic_year_id'];
            if((!is_string($yearId)&&!is_int($yearId))||!preg_match('/^[1-9][0-9]{0,9}$/',(string)$yearId)||(float)$yearId>2147483647)throw new InvalidArgumentException('Choose a valid academic year.');
            $year=schoolRecord($db,'academic_years',(int)$yearId,$school);
        }else{
            $q=$db->prepare('SELECT * FROM academic_years WHERE school_id=? AND is_active=1 AND COALESCE(is_hidden,0)=0 ORDER BY id DESC LIMIT 1');$q->execute([$school]);$year=$q->fetch();
        }
        if(!$year||!empty($year['is_hidden']))throw new InvalidArgumentException('Choose an available academic year from school settings.');
        $settings=$mode==='automatic'?$template:examTemplateSettings(['has_internal'=>$template['has_internal'],...$input,'is_active'=>1],$template);
        $dates=examDateInputs($input);$issueInput=['display_calendar'=>$template['date_calendar'],'date_format'=>$template['date_format'],...$input];
        if($mode==='automatic'){ $issueInput['display_calendar']=$template['date_calendar'];$issueInput['date_format']=$template['date_format']; }
        $issue=reportIssueDate([],$issueInput);
        $q=$db->prepare('INSERT INTO exams(name,type,academic_year_id,start_date,end_date,is_published,is_locked,school_id,has_internal,result_system,issue_date_mode,issue_date,date_calendar,date_format,creation_mode,template_id,template_revision,template_snapshot) VALUES(?,?,?,?,?,0,0,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$settings['name'],$settings['type'],$year['id'],$dates['start_date'],$dates['end_date'],$school,$settings['has_internal'],$settings['result_system'],$issue['mode'],$issue['manual']?:null,$issue['display'],$issue['format'],$mode,$id,$template['revision'],json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $examId=(int)$db->lastInsertId();$exam=schoolRecord($db,'exams',$examId,$school);
        initializeExamTemplateSubjectsInTransaction($db,$school,$exam,0);
        $db->commit();return $examId;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function examTemplateSchemeForClass(array $rows,array $local):?array {
    $key=classNameKey($local['class_name']);
    $sameClass=array_filter($rows,static fn($r)=>$r['class_key']===$key);
    // Prefer persistent curriculum links; only legacy/custom copies fall back to exact labels.
    $rules=[
        static fn($r)=>!empty($local['curriculum_key'])&&$local['curriculum_key']===($r['curriculum_key']??null),
        static fn($r)=>!empty($local['source_id'])&&(int)$local['source_id']===(int)$r['source_subject_id'],
        static fn($r)=>catalogNameKey($local['name_en'])===catalogNameKey($r['name_en'])&&!empty($local['code'])&&catalogNameKey($local['code'])===catalogNameKey($r['code']??''),
        static fn($r)=>catalogNameKey($local['name_en'])===catalogNameKey($r['name_en']),
        static fn($r)=>!empty($local['code'])&&catalogNameKey($local['code'])===catalogNameKey($r['code']??'')
    ];
    foreach($rules as $rule){
        $matches=array_values(array_filter($sameClass,$rule));
        if(count($matches)>1)throw new InvalidArgumentException('Ambiguous default for '.$local['class_name'].' / '.$local['name_en'].'. Ask the system administrator to correct the catalog.');
        if($matches)return $matches[0];
    }
    return null;
}
function initializeExamTemplateSubjectsInTransaction(PDO $db,int $school,array $exam,int $class):int {
    $snapshot=json_decode($exam['template_snapshot']??'null',true,512,JSON_THROW_ON_ERROR);
    if(!is_array($snapshot)||!is_array($snapshot['subjects']??null))throw new InvalidArgumentException('This exam has no saved default configuration.');
    $q=$db->prepare('SELECT cs.*,c.name class_name,s.name_en,s.code,s.source_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.school_id=cs.school_id JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id WHERE cs.school_id=? AND c.is_active=1 AND cs.is_active=1 AND COALESCE(s.is_hidden,0)=0 AND '.schoolSubjectAssignedSql('cs.school_id','cs.class_id','cs.subject_id').($class?' AND cs.class_id=?':'').' ORDER BY cs.id');$q->execute([$school,...($class?[$class]:[])]);$rows=$q->fetchAll();
    $count=0;$seen=[];$exists=$db->prepare('SELECT id FROM exam_subjects WHERE exam_id=? AND class_id=? AND subject_id=?');
    $keys=['class_id','subject_id','theory_full','theory_pass','practical_full','practical_pass','credit_hours','external_credit_hours','internal_credit_hours','is_optional','assessment_method','curriculum_version','internal_components'];
    $insert=$db->prepare('INSERT INTO exam_subjects(exam_id,'.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys)+1,'?')).')');
    foreach($rows as $row){
        $identity=$row['class_id'].'/'.$row['subject_id'];if(isset($seen[$identity]))continue;$seen[$identity]=true;
        $exists->execute([$exam['id'],$row['class_id'],$row['subject_id']]);if($exists->fetchColumn())continue;
        $r=examTemplateSchemeForClass($snapshot['subjects'],$row);
        if(!$r){
            if($exam['creation_mode']==='automatic')throw new InvalidArgumentException('No system default exists for '.$row['class_name'].' / '.$row['name_en'].'. Use a manual exam, or ask the system administrator to add its default.');
            $r=$row;$r['theory_full']=$row['th_full_marks']??100;$r['theory_pass']=$row['th_pass_marks']??35;$r['practical_full']=$row['in_full_marks']??0;$r['practical_pass']=$row['in_pass_marks']??0;$r['external_credit_hours']=$row['th_credit_hours'];$r['internal_credit_hours']=$row['in_credit_hours'];
        }
        $r['class_id']=$row['class_id'];$r['subject_id']=$row['subject_id'];
        if(($r['assessment_method']??'')==='THEMATIC_CONTINUOUS'){$r['theory_full']=100;$r['theory_pass']=35;$r['practical_full']=0;$r['practical_pass']=0;$r['external_credit_hours']=null;$r['internal_credit_hours']=null;}
        elseif(!$exam['has_internal']||$exam['type']==='unit'){$r['practical_full']=0;$r['practical_pass']=0;$r['internal_credit_hours']=0;$r['external_credit_hours']=$r['credit_hours'];}
        $insert->execute([$exam['id'],...array_map(static fn($key)=>$r[$key]??null,$keys)]);$count++;
    }
    return $count;
}
function initializeExamTemplateSubjects(PDO $db,int $school,int $examId,int $class):int {
    schoolRecord($db,'classes',$class,$school);$db->beginTransaction();
    try{
        $exam=schoolRecord($db,'exams',$examId,$school,true);if($exam['is_locked']||$exam['is_published'])throw new InvalidArgumentException('Reopen the exam before initializing subjects.');
        $count=initializeExamTemplateSubjectsInTransaction($db,$school,$exam,$class);$db->commit();return $count;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
