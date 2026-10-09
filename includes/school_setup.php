<?php
/** Shared catalog records are templates. Operational records always belong to one school. */
function insertRecord(PDO $db, string $table, array $row): int {
    unset($row['id']);
    $columns = implode(',', array_map(static fn($key) => "`$key`", array_keys($row)));
    $db->prepare("INSERT INTO `$table` ($columns) VALUES (" . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return (int)$db->lastInsertId();
}
function ensureUniversalClasses(PDO $db): void {
    $names = array_merge(['Nursery','LKG','UKG','ECD'], array_map(static fn($i)=>'Class '.$i, range(1,12)));
    $check = $db->prepare('SELECT id FROM classes WHERE name=? AND school_id=0');
    foreach ($names as $name) {
        $check->execute([$name]);
        if (!$check->fetchColumn()) insertRecord($db,'classes',['name'=>$name,'name_np'=>$name,'level'=>preg_match('/Class (9|10|11|12)$/',$name)?'Secondary':'Basic','grading_type'=>'gpa','school_id'=>0,'is_active'=>1]);
    }
}
function provisionSchool(PDO $db, int $schoolId): void {
    if ($schoolId < 1) return;
    $check = $db->prepare('SELECT workflow_version FROM schools WHERE id=?');
    $check->execute([$schoolId]);
    $version = $check->fetchColumn();
    if ($version === false || (int)$version >= 2) return;
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) $db->beginTransaction();
    try {
        $maps = ['classes'=>[], 'subjects'=>[]];
        foreach (array_keys($maps) as $table) {
            $name = $table === 'classes' ? 'name' : 'name_en';
            foreach ($db->query("SELECT * FROM `$table` WHERE school_id=0")->fetchAll() as $row) {
                $source = (int)$row['id'];
                $lookup = $db->prepare("SELECT id FROM `$table` WHERE school_id=? AND (source_id=? OR (LOWER(TRIM(`$name`))=LOWER(TRIM(?))" . ($table==='subjects' ? ' AND COALESCE(code,\'\')=COALESCE(?,\'\')' : '') . ')) ORDER BY id LIMIT 1');
                $params = [$schoolId,$source,$row[$name]];
                if ($table==='subjects') $params[]=$row['code'];
                $lookup->execute($params);
                $target = $lookup->fetchColumn();
                if (!$target) {
                    $row['school_id']=$schoolId;
                    $row['source_id']=$source;
                    if ($table==='classes') { $row['class_teacher_id']=null; $row['class_teacher_name']=null; $row['class_teacher_signature']=null; }
                    $target=insertRecord($db,$table,$row);
                } else {
                    $db->prepare("UPDATE `$table` SET source_id=? WHERE id=? AND source_id IS NULL")->execute([$source,$target]);
                }
                $maps[$table][$source]=(int)$target;
            }
        }
        // Move only this school's references. Shared questions/curricula keep their template IDs.
        foreach ($maps['classes'] as $source=>$target) {
            foreach (['students','sections','class_subjects','class_routines','class_routines_history','questions','chapters','topics','specification_grids'] as $table) {
                $db->prepare("UPDATE `$table` SET class_id=? WHERE class_id=? AND school_id=?")->execute([$target,$source,$schoolId]);
            }
            $db->prepare('UPDATE exam_subjects SET class_id=? WHERE class_id=? AND exam_id IN (SELECT id FROM exams WHERE school_id=?)')->execute([$target,$source,$schoolId]);
        }
        foreach ($maps['subjects'] as $source=>$target) {
            foreach (['class_subjects','class_routines','class_routines_history','questions','chapters','topics','specification_grids'] as $table) {
                $db->prepare("UPDATE `$table` SET subject_id=? WHERE subject_id=? AND school_id=?")->execute([$target,$source,$schoolId]);
            }
            foreach (['optional_subject_i','optional_subject_ii','optional_subject_iii'] as $field) $db->prepare("UPDATE students SET $field=? WHERE $field=? AND school_id=?")->execute([$target,$source,$schoolId]);
            $db->prepare('UPDATE exam_subjects SET subject_id=? WHERE subject_id=? AND exam_id IN (SELECT id FROM exams WHERE school_id=?)')->execute([$target,$source,$schoolId]);
            $db->prepare('UPDATE marks SET subject_id=? WHERE subject_id=? AND student_id IN (SELECT id FROM students WHERE school_id=?)')->execute([$target,$source,$schoolId]);
        }
        foreach ($db->query('SELECT * FROM class_subjects WHERE school_id=0')->fetchAll() as $row) {
            if (!isset($maps['classes'][$row['class_id']],$maps['subjects'][$row['subject_id']])) continue;
            $row['class_id']=$maps['classes'][$row['class_id']];
            $row['subject_id']=$maps['subjects'][$row['subject_id']];
            $lookup=$db->prepare('SELECT id FROM class_subjects WHERE school_id=? AND class_id=? AND subject_id=?');
            $lookup->execute([$schoolId,$row['class_id'],$row['subject_id']]);
            if ($lookup->fetchColumn()) continue;
            $row['school_id']=$schoolId; $row['teacher_id']=null; $row['section_id']=null;
            insertRecord($db,'class_subjects',$row);
        }
        $chapterMap=[];
        foreach($db->query('SELECT * FROM chapters WHERE school_id=0')->fetchAll() as $chapter){
            if(!isset($maps['classes'][$chapter['class_id']],$maps['subjects'][$chapter['subject_id']]))continue;
            $source=$chapter['id'];$chapter['class_id']=$maps['classes'][$chapter['class_id']];$chapter['subject_id']=$maps['subjects'][$chapter['subject_id']];$chapter['school_id']=$schoolId;
            $q=$db->prepare('SELECT id FROM chapters WHERE school_id=? AND class_id=? AND subject_id=? AND name=?');$q->execute([$schoolId,$chapter['class_id'],$chapter['subject_id'],$chapter['name']]);
            $chapterMap[$source]=(int)($q->fetchColumn()?:insertRecord($db,'chapters',$chapter));
        }
        foreach($db->query('SELECT * FROM topics WHERE school_id=0')->fetchAll() as $topic){
            if(!isset($chapterMap[$topic['chapter_id']],$maps['classes'][$topic['class_id']],$maps['subjects'][$topic['subject_id']]))continue;
            $topic['chapter_id']=$chapterMap[$topic['chapter_id']];$topic['class_id']=$maps['classes'][$topic['class_id']];$topic['subject_id']=$maps['subjects'][$topic['subject_id']];$topic['school_id']=$schoolId;
            $q=$db->prepare('SELECT id FROM topics WHERE school_id=? AND chapter_id=? AND name=?');$q->execute([$schoolId,$topic['chapter_id'],$topic['name']]);
            if(!$q->fetchColumn())insertRecord($db,'topics',$topic);
        }
        $db->prepare('UPDATE schools SET workflow_version=2 WHERE id=?')->execute([$schoolId]);
        if ($ownTransaction) $db->commit();
    } catch (Throwable $e) { if ($ownTransaction && $db->inTransaction()) $db->rollBack(); throw $e; }
}
