<?php
/** Student accounts follow their current enrollment; no copied class permissions. */
function studentLearningRecord(PDO $db,int $school,int $actor):array {
    $q=$db->prepare("SELECT s.* FROM users u JOIN students s ON s.id=u.student_record_id AND s.school_id=u.school_id JOIN classes c ON c.id=s.class_id AND c.school_id=s.school_id WHERE u.id=? AND u.school_id=? AND u.role='student' AND u.status=1 AND s.status='active' AND c.is_active=1");
    $q->execute([$actor,$school]);$student=$q->fetch();
    if(!$student)throw new InvalidArgumentException('Your student account needs an active student record. Contact your school administrator.');
    return $student;
}
function migrateStudentLearningAccounts(PDO $db):void {
    if(!in_array('student_record_id',dbColumns($db,'users'),true))$db->exec('ALTER TABLE users ADD COLUMN student_record_id INTEGER NULL');
    accountingIndex($db,'users','idx_users_student_learning','school_id,student_record_id',true);
}
