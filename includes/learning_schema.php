<?php
require_once __DIR__.'/subject_names.php';
require_once __DIR__.'/catalog_validation.php';
/** Additive migration; the presence of visibility marks completed legacy backfill. */
function migrateLearningContent(PDO $db):void {
    $common=['created_by_user_id'=>'INTEGER NULL','visibility'=>"VARCHAR(10) NOT NULL DEFAULT 'private'",'archived_at'=>'TIMESTAMP NULL','updated_at'=>'TIMESTAMP NULL','catalog_chapter_id'=>'INTEGER NULL','catalog_topic_id'=>'INTEGER NULL'];
    $legacy=[];
    foreach(['questions'=>$common,'learning_notes'=>$common+['school_id'=>'INTEGER NOT NULL DEFAULT 0','class_id'=>'INTEGER NULL','subject_id'=>'INTEGER NULL','chapter_id'=>'INTEGER NULL','topic_id'=>'INTEGER NULL']] as $table=>$columns){
        $existing=dbColumns($db,$table);$legacy[$table]=!in_array('visibility',$existing,true);
        foreach($columns as $name=>$type)if(!in_array($name,$existing,true))$db->exec("ALTER TABLE `$table` ADD COLUMN `$name` $type");
    }
    $own=!$db->inTransaction();if($own)$db->beginTransaction();
    try {
        foreach($legacy as $table=>$first)if($first)$db->exec("UPDATE `$table` SET visibility='public'");
        if($legacy['learning_notes']){
            $scopes=$db->query('SELECT DISTINCT c.id class_id,c.name,s.id subject_id,s.name_en FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id AND s.school_id=0 WHERE c.school_id=0')->fetchAll(PDO::FETCH_ASSOC);
            $update=$db->prepare('UPDATE learning_notes SET class_id=?,subject_id=? WHERE id=?');
            foreach($db->query('SELECT id,class_level,subject_name FROM learning_notes')->fetchAll(PDO::FETCH_ASSOC) as $note){
                $matches=array_values(array_filter($scopes,static fn($r)=>classNameKey((string)$r['name'])===classNameKey((string)$note['class_level'])&&catalogNameKey(subjectCanonicalName((string)$r['name_en']))===catalogNameKey(subjectCanonicalName((string)$note['subject_name']))));
                if(count($matches)===1)$update->execute([$matches[0]['class_id'],$matches[0]['subject_id'],$note['id']]);
            }
        }
        migrateCanonicalSubjectNames($db);
        if($own)$db->commit();
    }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
    foreach(['questions','learning_notes'] as $table)foreach(['scope'=>'school_id,class_id,subject_id,visibility,archived_at','creator'=>'created_by_user_id,school_id'] as $key=>$fields){
        $name='idx_'.$table.'_learning_'.$key;
        if(DB_TYPE==='sqlite')$db->exec("CREATE INDEX IF NOT EXISTS $name ON $table($fields)");
        elseif(!$db->query("SHOW INDEX FROM $table WHERE Key_name='$name'")->fetch())$db->exec("CREATE INDEX $name ON $table($fields)");
    }
}
