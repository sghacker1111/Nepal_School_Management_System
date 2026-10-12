<?php
/** Additive only: existing timetables and their history remain intact. */
function migrateRoutineProfiles(PDO $db):void {
    $pk=DB_TYPE==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':'INT AUTO_INCREMENT PRIMARY KEY';
    foreach(['class_routines','class_routines_history'] as $table){
        $columns=dbColumns($db,$table);
        foreach(['start_time','end_time'] as $column)if(!in_array($column,$columns,true))$db->exec("ALTER TABLE $table ADD COLUMN $column VARCHAR(5) NULL");
    }
    $tables=[
        'routine_profiles'=>"name VARCHAR(100) NOT NULL,name_key VARCHAR(100) NOT NULL,payload_json TEXT NOT NULL,mode VARCHAR(10) NOT NULL,revision INTEGER NOT NULL DEFAULT 1,created_by INTEGER NOT NULL,updated_by INTEGER NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(school_id,class_id,name_key)",
        'routine_profile_history'=>"profile_id INTEGER NOT NULL,revision INTEGER NOT NULL,payload_json TEXT NOT NULL,name VARCHAR(100) NOT NULL,changed_by INTEGER NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'class_routine_layouts'=>"layout_json TEXT NOT NULL,profile_id INTEGER,updated_by INTEGER NOT NULL,UNIQUE(school_id,class_id)",
        'routine_layout_history'=>"layout_json TEXT NOT NULL,profile_id INTEGER,changed_by INTEGER NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];
    foreach($tables as $table=>$definition)$db->exec("CREATE TABLE IF NOT EXISTS $table (id $pk,school_id INTEGER NOT NULL,class_id INTEGER NOT NULL,$definition)".(DB_TYPE==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
}
