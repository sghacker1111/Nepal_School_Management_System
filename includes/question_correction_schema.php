<?php
/** Additive only: no question, ownership or visibility backfill. */
function migrateQuestionCorrections(PDO $db):void {
    $pk=DB_TYPE==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':'INT AUTO_INCREMENT PRIMARY KEY';
    $db->exec("CREATE TABLE IF NOT EXISTS question_correction_requests (
        id $pk,question_id INTEGER NOT NULL,bank_kind VARCHAR(10) NOT NULL,question_type VARCHAR(30) NOT NULL,
        requester_user_id INTEGER NOT NULL,requester_school_id INTEGER NOT NULL,mistake TEXT NOT NULL,suggested_correction TEXT NOT NULL,
        snapshot_json TEXT NOT NULL,snapshot_hash VARCHAR(64) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'pending',
        response_text TEXT,responded_by INTEGER,responded_at TIMESTAMP NULL,response_read_at TIMESTAMP NULL,
        revision INTEGER NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL,
        open_key VARCHAR(64) NULL UNIQUE,
        FOREIGN KEY(question_id) REFERENCES questions(id),FOREIGN KEY(requester_user_id) REFERENCES users(id),
        FOREIGN KEY(requester_school_id) REFERENCES schools(id),FOREIGN KEY(responded_by) REFERENCES users(id)
    )".(DB_TYPE==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    accountingIndex($db,'question_correction_requests','idx_correction_queue','bank_kind,status,question_type,id');
    accountingIndex($db,'question_correction_requests','idx_correction_requester','requester_school_id,requester_user_id,status,id');
}
