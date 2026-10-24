<?php
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/report_issue.php';
require_once __DIR__.'/exam_template_service.php';

function examHasMarks(PDO $db, int $examId): bool {
    $q=$db->prepare('SELECT COUNT(*) FROM marks WHERE exam_id=?');
    $q->execute([$examId]);
    return (int)$q->fetchColumn()>0;
}

/** Adding IN is allowed; changing the saved theory scheme or removing IN is not. */
function updateExamDetails(PDO $db,int $schoolId,int $examId,array $input): bool {
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM exams WHERE id=? AND school_id=?'.(DB_TYPE==='mysql'?' FOR UPDATE':''));
        $q->execute([$examId,$schoolId]);$exam=$q->fetch();
        if(!$exam)throw new InvalidArgumentException('Exam not found in this school.');
        assertExamConfigurationEditable($exam);
        if($exam['is_locked']||$exam['is_published'])throw new InvalidArgumentException('Reopen this exam before changing its configuration.');
        $type=$input['type']??$exam['type'];$system=$input['result_system']??$exam['result_system'];
        if(isset($input['has_internal'])&&!in_array($input['has_internal'],[0,1,'0','1'],true))throw new InvalidArgumentException('Choose a valid internal-assessment option.');
        $internal=isset($input['has_internal'])?(int)$input['has_internal']:0;
        if(!in_array($type,['terminal','final','unit','practical'],true)||!in_array($system,['gpa','percentage'],true)||!in_array($internal,[0,1],true))throw new InvalidArgumentException('Choose a valid exam configuration.');
        $addingInternal=!(int)$exam['has_internal']&&$internal===1;
        if($addingInternal&&$type==='unit')throw new InvalidArgumentException('Unit tests use theory-only marks. Internal assessment is available for terminal, final and practical exams.');
        if(examHasMarks($db,$examId)&&($type!==$exam['type']||$system!==$exam['result_system']||((int)$exam['has_internal']===1&&$internal===0)))throw new InvalidArgumentException('The grading system cannot change after marks have been entered. You may add internal assessment, or correct the exam name, dates and issue date.');
        if(!is_string($input['name']??null))throw new InvalidArgumentException('Exam name is required.');
        $name=trim($input['name']);if($name==='')throw new InvalidArgumentException('Exam name is required.');
        $issue=reportIssueDate($exam,$input);
        $dates=examDateInputs($input,$exam);$start=$dates['start_date'];$end=$dates['end_date'];
        if($start&&$end&&$end<$start)throw new InvalidArgumentException('The exam end date must not be before its start date.');
        $db->prepare('UPDATE exams SET name=?,type=?,start_date=?,end_date=?,has_internal=?,result_system=?,issue_date_mode=?,issue_date=?,date_calendar=?,date_format=? WHERE id=? AND school_id=?')->execute([$name,$type,$start,$end,$internal,$system,$issue['mode'],$issue['manual']?:null,$issue['display'],$issue['format'],$examId,$schoolId]);
        if($addingInternal) {
            $q=$db->prepare('SELECT es.*,s.in_full_marks subject_in_full,s.in_pass_marks subject_in_pass,s.in_credit_hours subject_in_credit FROM exam_subjects es JOIN subjects s ON s.id=es.subject_id AND s.school_id=? WHERE es.exam_id=?');$q->execute([$schoolId,$examId]);
            foreach($q->fetchAll() as $scheme) {
                if(($scheme['assessment_method']??'')==='THEMATIC_CONTINUOUS'||(float)$scheme['practical_full']>0)continue;
                $catalog=$db->prepare('SELECT * FROM class_subjects WHERE school_id=? AND class_id=? AND subject_id=? ORDER BY id LIMIT 1');$catalog->execute([$schoolId,$scheme['class_id'],$scheme['subject_id']]);$source=$catalog->fetch()?:[];
                if(($source['assessment_method']??'')==='THEMATIC_CONTINUOUS')continue;
                $full=$source['in_full_marks']??$scheme['subject_in_full'];
                if($full===null)throw new InvalidArgumentException('Configure internal full marks for each class subject before enabling internal assessment.');
                $full=(float)$full;if($full<=0)continue;
                $pass=(float)($source['in_pass_marks']??$scheme['subject_in_pass']??$full*0.4);
                if($pass<0||$pass>$full)throw new InvalidArgumentException('The class subject has invalid internal pass marks.');
                $credit=(float)($scheme['credit_hours']??4);$internalCredit=$scheme['internal_credit_hours'];$externalCredit=$scheme['external_credit_hours'];
                if((float)$internalCredit<=0)$internalCredit=(float)($source['in_credit_hours']??$scheme['subject_in_credit']??0);
                if((float)$internalCredit<=0)$internalCredit=$credit*$full/((float)$scheme['theory_full']+$full);
                if((float)$externalCredit<=0)$externalCredit=max(0,$credit-(float)$internalCredit);
                $db->prepare('UPDATE exam_subjects SET practical_full=?,practical_pass=?,internal_credit_hours=?,external_credit_hours=? WHERE id=? AND exam_id=?')->execute([$full,$pass,$internalCredit,$externalCredit,$scheme['id'],$examId]);
            }
            // A zero entered by theory-only saving is not evidence of an IN assessment.
            $db->prepare("UPDATE marks SET practical_obtained=NULL WHERE exam_id=? AND internal_breakdown IS NULL AND (practical_obtained=0 OR practical_obtained IS NULL) AND EXISTS(SELECT 1 FROM exam_subjects es WHERE es.exam_id=marks.exam_id AND es.subject_id=marks.subject_id AND es.practical_full>0 AND COALESCE(es.assessment_method,'')<>'THEMATIC_CONTINUOUS')")->execute([$examId]);
            $q=$db->prepare('SELECT m.*,es.theory_full,es.theory_pass,es.practical_full,es.practical_pass,es.credit_hours,es.external_credit_hours,es.internal_credit_hours,es.assessment_method,es.curriculum_version FROM marks m JOIN students st ON st.id=m.student_id AND st.school_id=? JOIN exam_subjects es ON es.exam_id=m.exam_id AND es.subject_id=m.subject_id AND es.class_id=st.class_id WHERE m.exam_id=?');$q->execute([$schoolId,$examId]);
            $current=array_replace($exam,['has_internal'=>1]);
            foreach($q->fetchAll() as $mark){$result=erpCalculateSubjectResult($mark,$mark,$current);$db->prepare('UPDATE marks SET grade=?,grade_point=?,remarks=? WHERE id=?')->execute([$system==='percentage'?'':$result['grade'],$system==='percentage'?0:$result['grade_point'],$result['remarks'],$mark['id']]);}
        }
        $db->commit();return $addingInternal;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

/** Publishing must never turn missing assessments into a published pass. */
function changeExamState(PDO $db, int $schoolId, int $examId, string $action): void {
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM exams WHERE id=? AND school_id=?'.(DB_TYPE==='mysql'?' FOR UPDATE':''));
        $q->execute([$examId,$schoolId]);$exam=$q->fetch();
        if(!$exam)throw new InvalidArgumentException('Exam not found in this school.');
        if($action==='publish') {
            if(!$exam['is_locked'])throw new InvalidArgumentException('Lock marks before publishing results.');
            $q=$db->prepare('SELECT * FROM exam_subjects WHERE exam_id=?');$q->execute([$examId]);$schemes=schoolExamOfferingRows($db,$schoolId,$exam,$q->fetchAll());
            if(!$schemes)throw new InvalidArgumentException('Configure exam subjects before publishing.');
            $expected=0;
            foreach($schemes as $scheme) {
                $sql="SELECT s.id FROM students s WHERE s.school_id=? AND s.academic_year_id=? AND s.class_id=? AND (s.status='active' OR EXISTS(SELECT 1 FROM marks m WHERE m.exam_id=? AND m.student_id=s.id))";
                $params=[$schoolId,$exam['academic_year_id'],$scheme['class_id'],$examId];
                if(!empty($scheme['is_optional'])) {
                    $sql.=' AND (s.optional_subject_i=? OR s.optional_subject_ii=? OR s.optional_subject_iii=?)';
                    array_push($params,$scheme['subject_id'],$scheme['subject_id'],$scheme['subject_id']);
                }
                $q=$db->prepare($sql);$q->execute($params);
                foreach($q->fetchAll(PDO::FETCH_COLUMN) as $student) {
                    $expected++;
                    $m=$db->prepare('SELECT * FROM marks WHERE exam_id=? AND student_id=? AND subject_id=?');
                    $m->execute([$examId,$student,$scheme['subject_id']]);$mark=$m->fetch()?:[];
                    if(erpCalculateSubjectResult($scheme,$mark,$exam)['incomplete'])throw new InvalidArgumentException('Results are incomplete. Enter all required marks for every enrolled student before publishing.');
                }
            }
            if(!$expected)throw new InvalidArgumentException('There are no enrolled students for the configured exam classes.');
            $db->prepare('UPDATE exams SET is_published=1,is_locked=1 WHERE id=? AND school_id=?')->execute([$examId,$schoolId]);
        } elseif($action==='lock') {
            if(!$exam['is_locked']){
                $q=$db->prepare('SELECT * FROM exam_subjects WHERE exam_id=?');$q->execute([$examId]);$schemes=$q->fetchAll();
                $active=array_column(schoolExamOfferingRows($db,$schoolId,$exam,$schemes),null,'id');
                $db->prepare('DELETE FROM app_meta WHERE meta_key LIKE ?')->execute(['exam_offering_excluded:'.$examId.':%']);
                $exclude=$db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?)');
                foreach($schemes as $scheme)if(!isset($active[$scheme['id']]))$exclude->execute(['exam_offering_excluded:'.$examId.':'.$scheme['id'],'1']);
            }
            $db->prepare('UPDATE exams SET is_locked=1 WHERE id=? AND school_id=?')->execute([$examId,$schoolId]);
        } elseif($action==='reopen') {
            // Reopening explicitly withdraws published results while staff correct marks.
            $db->prepare('UPDATE exams SET is_published=0,is_locked=0 WHERE id=? AND school_id=?')->execute([$examId,$schoolId]);
            $db->prepare('DELETE FROM app_meta WHERE meta_key LIKE ?')->execute(['exam_offering_excluded:'.$examId.':%']);
        } elseif($action==='delete') {
            if($exam['is_locked']||$exam['is_published']||examHasMarks($db,$examId))throw new InvalidArgumentException('Exams with saved marks or locked results cannot be deleted. Reopen marks to make corrections.');
            $db->prepare('DELETE FROM exam_student_attendance WHERE exam_id=?')->execute([$examId]);
            $db->prepare('DELETE FROM exam_subjects WHERE exam_id=?')->execute([$examId]);
            $db->prepare('DELETE FROM exams WHERE id=? AND school_id=?')->execute([$examId,$schoolId]);
        } else throw new InvalidArgumentException('Unknown exam action.');
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
}
