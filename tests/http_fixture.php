<?php
if(PHP_SAPI!=='cli'||!getenv('ERP_DB_PATH'))exit(1);
require __DIR__.'/../config/config.php';require __DIR__.'/../includes/database.php';
$db=getDB();
$hash=password_hash('TestPassword123!',PASSWORD_DEFAULT);
$db->prepare('UPDATE users SET password=?,school_id=NULL,status=1 WHERE role=?')->execute([$hash,'super_admin']);
$ids=[];
foreach(['school_admin','teacher','principal','exam_officer','accountant','cashier','auditor'] as $role){$db->prepare('INSERT INTO users (username,password,role,full_name,school_id,status) VALUES (?,?,?,?,1,1)')->execute([$role,$hash,$role,'Test '.ucwords(str_replace('_',' ',$role))]);$ids[$role]=(int)$db->lastInsertId();}
$db->exec("UPDATE schools SET question_permission_status='approved',question_view_permission='approved',learning_hub_permission_status='approved' WHERE id=1");
$class=(int)$db->query("SELECT id FROM classes WHERE school_id=1 AND name='Class 9'")->fetchColumn();
$subject=(int)$db->query("SELECT subject_id FROM class_subjects WHERE class_id=$class AND is_optional=0 ORDER BY id LIMIT 1")->fetchColumn();
$db->prepare('UPDATE classes SET class_teacher_id=?,class_teacher_name=? WHERE id=?')->execute([$ids['teacher'],'Test Teacher',$class]);
$db->prepare('UPDATE class_subjects SET teacher_id=? WHERE class_id=? AND subject_id=?')->execute([$ids['teacher'],$class,$subject]);
$db->prepare('INSERT INTO sections (class_id,name,school_id,class_teacher_id) VALUES (?,?,1,?)')->execute([$class,'A',$ids['teacher']]);$section=(int)$db->lastInsertId();
$db->prepare('INSERT INTO students (student_id,academic_year_id,class_id,section_id,first_name_en,last_name_en,gender,school_id,status) VALUES (?,1,?,?,?,?,?,1,?)')->execute(['FIXTURE-1',$class,$section,'Test','Student','Male','active']);$student=(int)$db->lastInsertId();
$db->prepare('INSERT INTO exams (name,type,academic_year_id,school_id,has_internal,result_system) VALUES (?,?,1,1,1,?)')->execute(['Regression Exam','terminal','gpa']);$exam=(int)$db->lastInsertId();
$db->prepare('INSERT INTO exam_subjects (exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours,external_credit_hours,internal_credit_hours,is_optional) VALUES (?,?,?,75,26.25,25,10,4,3,1,0)')->execute([$exam,$class,$subject]);
$db->exec("INSERT INTO schools (name_en) VALUES ('Other School')");$otherSchool=(int)$db->lastInsertId();provisionSchool($db,$otherSchool);
$otherClass=(int)$db->query("SELECT id FROM classes WHERE school_id=$otherSchool AND name='Class 9'")->fetchColumn();
$otherYear=(int)$db->query("SELECT id FROM academic_years WHERE school_id=$otherSchool LIMIT 1")->fetchColumn();
$db->prepare("INSERT INTO students(student_id,academic_year_id,class_id,first_name_en,last_name_en,gender,school_id,status) VALUES ('OTHER-1',?,?,'Other','Student','Male',?,'active')")->execute([$otherYear,$otherClass,$otherSchool]);$otherStudent=(int)$db->lastInsertId();
$db->prepare("INSERT INTO users(username,password,role,full_name,school_id,status) VALUES ('other_admin',?,'school_admin','Other Admin',?,1)")->execute([$hash,$otherSchool]);$otherUser=(int)$db->lastInsertId();
echo json_encode(compact('class','subject','section','student','exam','otherClass','otherSchool','otherStudent','otherUser','ids'));
