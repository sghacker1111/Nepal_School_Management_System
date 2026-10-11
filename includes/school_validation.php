<?php
require_once __DIR__.'/teaching_scope.php';
require_once __DIR__.'/result_scope.php';
/** Validate relationships before any screen changes school data. */
function studentEmisRegistrationNumber($value,bool $required=false): ?string {
    if($value===null)$value='';
    if(!is_string($value)||preg_match('/[[:cntrl:]]/u',$value))throw new InvalidArgumentException('Enter a valid EMIS Registration Number.');
    $value=trim($value);
    if($value===''){if($required)throw new InvalidArgumentException('EMIS Registration Number is required.');return null;}
    if(!preg_match('/^.{1,100}$/us',$value))throw new InvalidArgumentException('EMIS Registration Number must be at most 100 characters.');
    return $value;
}
function schoolRecord(PDO $db, string $table, int $id, int $school,bool $lock=false): array {
    $allowed = ['classes','sections','subjects','students','academic_years','exams','users','class_subjects','chapters','topics','questions','specification_grids'];
    if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Invalid record type.');
    $stmt = $db->prepare("SELECT * FROM `$table` WHERE id=? AND school_id=?".($lock&&DB_TYPE==='mysql'?' FOR UPDATE':''));
    $stmt->execute([$id,$school]);
    $row = $stmt->fetch();
    if (!$row) throw new InvalidArgumentException('The selected '.str_replace('_',' ', $table).' record does not belong to this school.');
    return $row;
}
function validateSchoolReferences(PDO $db, int $school, string $module, array $data, array $query = []): void {
    if (in_array($module, ['super_admin','auth'], true)) return;
    $values = array_replace($query, $data);
    if($module==='students'&&array_key_exists('emis_registration_number',$data))studentEmisRegistrationNumber($data['emis_registration_number']);
    if($module==='students' && isset($data['gender']) && !in_array($data['gender'],['Male','Female','Other'],true))throw new InvalidArgumentException('Choose a valid gender.');
    if($module==='students' && isset($data['status']) && !in_array($data['status'],['active','inactive','transferred','dropout','passed'],true))throw new InvalidArgumentException('Choose a valid enrollment status.');
    if($module==='exams') {
        require_once __DIR__.'/nepali_dates.php';examDateInputs($data);
        if(isset($data['result_system']) && !in_array($data['result_system'],['gpa','percentage'],true))throw new InvalidArgumentException('Choose GPA or percentage results.');
        if(isset($data['type']) && !in_array($data['type'],['unit','terminal','final','practical'],true))throw new InvalidArgumentException('Choose a valid examination type.');
    }
    $refs = ['class_id'=>'classes','init_class_id'=>'classes','assigned_class_id'=>'classes','section_id'=>'sections','subject_id'=>'subjects','student_id'=>'students','student'=>'students','academic_year_id'=>'academic_years','exam_id'=>'exams','class'=>'classes','section'=>'sections','exam'=>'exams','prev_exam'=>'exams','subject'=>'subjects'];
    $records = [];
    foreach ($refs as $key=>$table) {
        if (empty($values[$key]) || is_array($values[$key])) continue;
        // Human student numbers are not foreign keys.
        if ($key === 'student_id' && $module === 'students') continue;
        $records[$table] = schoolRecord($db,$table,(int)$values[$key],$school);
    }
    if (isset($records['sections'],$records['classes']) && $records['sections']['class_id'] != $records['classes']['id']) throw new InvalidArgumentException('The section must belong to the selected class.');
    if ($module==='exams' && isset($data['map_subject'], $records['classes'], $records['subjects'])) {
        $mapped=$db->prepare('SELECT cs.id FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=? AND cs.class_id=? AND cs.subject_id=? AND (cs.is_active=1 OR cs.is_optional=1) AND COALESCE(s.is_hidden,0)=0');
        $mapped->execute([$school,$records['classes']['id'],$records['subjects']['id']]);
        if(!$mapped->fetchColumn())throw new InvalidArgumentException('Assign this subject to the class before adding it to the exam.');
    }
    if(isset($data['chapter_id']) && $data['chapter_id']) {
        $chapter=schoolRecord($db,'chapters',(int)$data['chapter_id'],$school);
        if($chapter['class_id']!=($records['classes']['id']??0) || $chapter['subject_id']!=($records['subjects']['id']??0))throw new InvalidArgumentException('The topic must belong to the selected chapter, class and subject.');
    }
    foreach (['teacher_ids','teacher_id','class_teacher_id'] as $key) {
        foreach ((array)($data[$key] ?? []) as $id) {
            if (!$id) continue;
            $teacher = schoolRecord($db,'users',(int)$id,$school);
            if ($teacher['role'] !== 'teacher' || !$teacher['status']) throw new InvalidArgumentException('Choose an active teacher from this school.');
        }
    }
    foreach (['optional_subject_i','optional_subject_ii','optional_subject_iii'] as $key) {
        if (empty($data[$key])) continue;
        schoolRecord($db,'subjects',(int)$data[$key],$school);
        $stmt=$db->prepare('SELECT id FROM class_subjects WHERE class_id=? AND subject_id=? AND school_id=? AND is_optional=1 AND is_active=1');
        $stmt->execute([$records['classes']['id'] ?? 0,(int)$data[$key],$school]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Optional subjects must be active optional choices for the selected class.');
    }
    $choices=array_filter(array_map('intval',[$data['optional_subject_i']??0,$data['optional_subject_ii']??0,$data['optional_subject_iii']??0]));
    if(count($choices)!==count(array_unique($choices)))throw new InvalidArgumentException('Choose different optional subjects.');
    if(!empty($data['optional_subject_iii']) && !in_array($records['classes']['name']??'', ['Class 11','Class 12'],true))throw new InvalidArgumentException('The third optional subject is for Grades 11 and 12.');
    foreach ([['th_full_marks','th_pass_marks'],['in_full_marks','in_pass_marks'],['theory_full','theory_pass'],['practical_full','practical_pass'],['full_marks','pass_marks']] as [$full,$pass]) {
        if (!isset($data[$full]) || is_array($data[$full])) continue;
        if (!is_numeric($data[$full]) || (float)$data[$full]<0 || !is_numeric($data[$pass] ?? 0) || (float)($data[$pass]??0)<0 || (float)($data[$pass]??0)>(float)$data[$full]) throw new InvalidArgumentException('Pass marks must be between zero and full marks.');
    }
    if ($module === 'attendance' && isset($data['attendance'])) {
        if (!is_array($data['attendance'])) throw new InvalidArgumentException('Invalid attendance data.');
        $date=$data['date_ad'] ?? '';
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new InvalidArgumentException('Enter a valid attendance date.');
        foreach ($data['attendance'] as $id=>$status) {
            $student=schoolRecord($db,'students',(int)$id,$school);
            if ($student['class_id']!=($records['classes']['id']??0) || (isset($records['sections']) && $student['section_id']!=$records['sections']['id'])) throw new InvalidArgumentException('Attendance includes a student outside the selected class or section.');
            if (!in_array($status,['P','A','L','H'],true)) throw new InvalidArgumentException('Invalid attendance status.');
        }
    }
}
