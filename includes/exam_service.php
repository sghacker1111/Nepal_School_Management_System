<?php
require_once __DIR__ . '/grading.php';

function examHasMarks(PDO $db, int $examId): bool {
    $q=$db->prepare('SELECT COUNT(*) FROM marks WHERE exam_id=?');
    $q->execute([$examId]);
    return (int)$q->fetchColumn()>0;
}

/** Publishing must never turn missing assessments into a published pass. */
function changeExamState(PDO $db, int $schoolId, int $examId, string $action): void {
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM exams WHERE id=? AND school_id=?');
        $q->execute([$examId,$schoolId]);$exam=$q->fetch();
        if(!$exam)throw new InvalidArgumentException('Exam not found in this school.');
        if($action==='publish') {
            if(!$exam['is_locked'])throw new InvalidArgumentException('Lock marks before publishing results.');
            $q=$db->prepare('SELECT * FROM exam_subjects WHERE exam_id=?');$q->execute([$examId]);$schemes=$q->fetchAll();
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
            $db->prepare('UPDATE exams SET is_locked=1 WHERE id=? AND school_id=?')->execute([$examId,$schoolId]);
        } elseif($action==='reopen') {
            // Reopening explicitly withdraws published results while staff correct marks.
            $db->prepare('UPDATE exams SET is_published=0,is_locked=0 WHERE id=? AND school_id=?')->execute([$examId,$schoolId]);
        } elseif($action==='delete') {
            if($exam['is_locked']||$exam['is_published']||examHasMarks($db,$examId))throw new InvalidArgumentException('Exams with saved marks or locked results cannot be deleted. Reopen marks to make corrections.');
            $db->prepare('DELETE FROM exam_student_attendance WHERE exam_id=?')->execute([$examId]);
            $db->prepare('DELETE FROM exam_subjects WHERE exam_id=?')->execute([$examId]);
            $db->prepare('DELETE FROM exams WHERE id=? AND school_id=?')->execute([$examId,$schoolId]);
        } else throw new InvalidArgumentException('Unknown exam action.');
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
}
