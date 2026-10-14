<?php
/** Additive bank separation: never copy, relabel or delete existing questions. */
function migrateExamPaperSchema(PDO $db):void {
    $existing=dbColumns($db,'questions');
    foreach(['bank_kind'=>"VARCHAR(10) NOT NULL DEFAULT 'learning'",'difficulty'=>"VARCHAR(20) NOT NULL DEFAULT 'Medium'",'required_minutes'=>'DECIMAL(8,2) NULL','student_visible'=>'INTEGER NOT NULL DEFAULT 0'] as $name=>$type)if(!in_array($name,$existing,true))$db->exec("ALTER TABLE questions ADD COLUMN $name $type");
    $pk=DB_TYPE==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':'INT AUTO_INCREMENT PRIMARY KEY';
    $db->exec("CREATE TABLE IF NOT EXISTS exam_paper_grids (id $pk,school_id INTEGER NOT NULL,class_id INTEGER NOT NULL,subject_id INTEGER NOT NULL,name VARCHAR(200) NOT NULL,config_json TEXT NOT NULL,created_by_user_id INTEGER NULL,archived_at TIMESTAMP NULL,revision INTEGER NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL)");
    accountingIndex($db,'questions','idx_questions_bank_scope','school_id,bank_kind,class_id,subject_id,archived_at');
    accountingIndex($db,'exam_paper_grids','idx_exam_grids_scope','school_id,class_id,subject_id,archived_at');
}
