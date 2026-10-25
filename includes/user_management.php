<?php
require_once __DIR__.'/account_security.php';
function saveSchoolUser(PDO $db, int $actorId, string $actorRole, int $actorSchool, array $input): int {
    $actorQuery=$db->prepare('SELECT role,school_id,status FROM users WHERE id=?');$actorQuery->execute([$actorId]);$actor=$actorQuery->fetch();
    if(!$actor||!(int)$actor['status']||$actor['role']!==$actorRole||($actorRole!=='super_admin'&&(int)$actor['school_id']!==$actorSchool))throw new InvalidArgumentException('An active administrator assigned to this school is required.');
    if (!in_array($actorRole,['super_admin','school_admin'],true)) throw new InvalidArgumentException('Only administrators can manage accounts.');
    $id=(int)($input['user_id']??0);
    $school=$actorRole==='super_admin'?(int)($input['school_id']??0):$actorSchool;
    $name=trim($input['full_name']??''); $username=trim($input['username']??'');
    $role=$input['role']??'teacher'; $password=$input['password']??'';
    $status=empty($input['status'])?0:1;
    $email=trim($input['email']??'');
    if (!in_array($role,['school_admin','principal','teacher','exam_officer','accountant','cashier','auditor','student'],true)) throw new InvalidArgumentException('Choose a valid school role.');
    if (!$name || !preg_match('/^[A-Za-z0-9_.@-]{3,50}$/',$username)) throw new InvalidArgumentException('Enter a name and a username of 3–50 letters, numbers, dots, hyphens or underscores.');
    if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if ((!$id || $password!=='') && strlen($password)<8) throw new InvalidArgumentException('Passwords must contain at least 8 characters.');
    $stmt=$db->prepare('SELECT id FROM schools WHERE id=?'); $stmt->execute([$school]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Select an existing school.');
    $existing=null;
    if ($id) {
        $stmt=$db->prepare('SELECT * FROM users WHERE id=? AND school_id=?'); $stmt->execute([$id,$school]); $existing=$stmt->fetch();
        if (!$existing || $existing['role']==='super_admin') throw new InvalidArgumentException('This account cannot be edited here or moved between schools.');
        $security=schoolAccountState($db,$id);
        if($security['blocked']||$security['retry'])throw new InvalidArgumentException('Unlock this account through an authorized administrator before editing its access or password.');
        if ($id===$actorId && (!$status || $role!==$actorRole)) throw new InvalidArgumentException('You cannot disable your own account or remove your administrator role.');
        if ($existing['role']==='school_admin' && $existing['status'] && (!$status || $role!=='school_admin')) {
            $stmt=$db->prepare("SELECT COUNT(*) FROM users WHERE school_id=? AND role='school_admin' AND status=1 AND id<>?"); $stmt->execute([$school,$id]);
            if (!(int)$stmt->fetchColumn()) throw new InvalidArgumentException('Keep at least one active school administrator.');
        }
    }
    $stmt=$db->prepare('SELECT id FROM users WHERE LOWER(username)=LOWER(?) AND id<>?'); $stmt->execute([$username,$id]);
    if ($stmt->fetchColumn()) throw new InvalidArgumentException('That username is already in use.');
    $db->beginTransaction();
    try {
        if($id){schoolSecurityUser($db,$id);$security=schoolAccountState($db,$id);if($security['blocked']||$security['retry'])throw new InvalidArgumentException('Unlock this account through an authorized administrator before editing its access or password.');}
        $studentRecord=null;
        if($role==='student'){
            $studentId=$input['student_record_id']??'';
            if(!is_scalar($studentId)||!ctype_digit((string)$studentId)||(int)$studentId<1)throw new InvalidArgumentException('Choose a student record for this account.');
            $q=$db->prepare('SELECT id,status FROM students WHERE id=? AND school_id=?');$q->execute([(int)$studentId,$school]);$linked=$q->fetch();
            if(!$linked||($status&&$linked['status']!=='active'))throw new InvalidArgumentException('Choose an active student belonging to this school.');
            $q=$db->prepare('SELECT id FROM users WHERE school_id=? AND student_record_id=? AND id<>?');$q->execute([$school,(int)$studentId,$id]);
            if($q->fetchColumn())throw new InvalidArgumentException('This student already has a login account. Edit that account instead.');
            $studentRecord=(int)$studentId;
        }
        $values=[$username,$name,$role,$email,trim($input['phone']??''),$status,$studentRecord];
        if ($id) {
            $sql='UPDATE users SET username=?,full_name=?,role=?,email=?,phone=?,status=?,student_record_id=?';
            if ($password!=='') { $sql.=',password=?'; $values[]=password_hash($password,PASSWORD_DEFAULT); }
            $sql.=' WHERE id=? AND school_id=?'; $values[]=$id; $values[]=$school; $db->prepare($sql)->execute($values);
            $db->prepare('UPDATE classes SET class_teacher_name=? WHERE class_teacher_id=? AND school_id=?')->execute([$name,$id,$school]);
        } else {
            $values[]=password_hash($password,PASSWORD_DEFAULT); $values[]=$school;
            $db->prepare('INSERT INTO users (username,full_name,role,email,phone,status,student_record_id,password,school_id) VALUES (?,?,?,?,?,?,?,?,?)')->execute($values);
            $id=(int)$db->lastInsertId();
        }
        $db->commit(); return $id;
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}
