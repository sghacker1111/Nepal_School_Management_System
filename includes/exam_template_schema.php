<?php
/** Additive migration: legacy exams remain manual, with their schemes untouched. */
function migrateExamTemplates(PDO $db):void {
    $pk=DB_TYPE==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':'INT AUTO_INCREMENT PRIMARY KEY';
    $jsonType=DB_TYPE==='mysql'?'MEDIUMTEXT':'TEXT';
    $suffix=DB_TYPE==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $db->exec("CREATE TABLE IF NOT EXISTS exam_templates (id $pk,template_key VARCHAR(60) NOT NULL UNIQUE,name VARCHAR(150) NOT NULL,type VARCHAR(20) NOT NULL,term_no INTEGER NOT NULL,has_internal INTEGER NOT NULL,result_system VARCHAR(20) NOT NULL,date_calendar VARCHAR(10) NOT NULL,date_format VARCHAR(10) NOT NULL,is_active INTEGER NOT NULL DEFAULT 1,revision INTEGER NOT NULL DEFAULT 1,config_json $jsonType,updated_by INTEGER,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)$suffix");
    $db->exec("CREATE TABLE IF NOT EXISTS exam_template_history (id $pk,template_id INTEGER NOT NULL,revision INTEGER NOT NULL,snapshot_json $jsonType NOT NULL,changed_by INTEGER NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)$suffix");
    $columns=dbColumns($db,'exams');
    foreach(['creation_mode'=>"VARCHAR(15) NOT NULL DEFAULT 'manual'",'template_id'=>'INTEGER NULL','template_revision'=>'INTEGER NULL','template_snapshot'=>"$jsonType NULL"] as $name=>$type)if(!in_array($name,$columns,true))$db->exec("ALTER TABLE exams ADD COLUMN $name $type");
    $q=$db->prepare('SELECT id FROM exam_templates WHERE template_key=?');
    $insert=$db->prepare('INSERT INTO exam_templates(template_key,name,type,term_no,has_internal,result_system,date_calendar,date_format) VALUES(?,?,?,?,1,?,?,?)');
    foreach([['first','First Terminal Examination','terminal',1],['second','Second Terminal Examination','terminal',2],['final','Third / Final Terminal Examination','final',3]] as [$key,$name,$type,$term]){
        $q->execute([$key]);if(!$q->fetchColumn())$insert->execute([$key,$name,$type,$term,'gpa','BS','ymd']);
    }
}
