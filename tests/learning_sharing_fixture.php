<?php
/** Synthetic extension shared by service and real-HTTP tests. */
function learningSharingFixture(PDO $db,array $f):array {
    $hash=password_hash('TestPassword123!',PASSWORD_DEFAULT);
    foreach(['co_teacher'=>['teacher',1,null],'unrelated_teacher'=>['teacher',1,null],'student_login'=>['student',1,$f['student']],'other_student_login'=>['student',$f['otherSchool'],$f['otherStudent']]] as $name=>[$role,$school,$student]){
        $db->prepare('INSERT INTO users(username,password,role,full_name,school_id,status,student_record_id) VALUES(?,?,?,?,?,1,?)')->execute([$name,$hash,$role,str_replace('_',' ',$name),$school,$student]);$f['ids'][$name]=(int)$db->lastInsertId();
    }
    $db->prepare('UPDATE class_subjects SET teacher_id=? WHERE school_id=1 AND class_id=? AND subject_id=?')->execute([$f['ids']['teacher'].','.$f['ids']['co_teacher'],$f['class'],$f['subject']]);
    $db->exec("UPDATE schools SET question_permission_status='approved',question_view_permission='approved',learning_hub_permission_status='approved'");
    $f['ids']['super_admin']=(int)$db->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();$f['ids']['other_admin']=$f['otherUser'];
    return $f;
}
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){require __DIR__.'/../config/config.php';require __DIR__.'/../includes/database.php';$f=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);echo json_encode(learningSharingFixture(getDB(),$f));}
