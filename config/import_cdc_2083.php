<?php
/** Safe deployment/local entry point. Database connection backs up SQLite before upgrading. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/config.php';
require_once __DIR__.'/../includes/database.php';
$db=getDB();
$summary=['database_type'=>DB_TYPE,'dataset'=>cdc2083Data()['version'],'source_sha256'=>cdc2083Data()['source_sha256'],'source_subject_rows'=>(int)$db->query("SELECT COUNT(*) FROM cdc_reference WHERE category='CLASS_SUBJECTS'")->fetchColumn(),'classes'=>(int)$db->query('SELECT COUNT(*) FROM classes')->fetchColumn(),'mapped_workbook_rows'=>(int)$db->query('SELECT COUNT(*) FROM class_subjects WHERE curriculum_key IS NOT NULL')->fetchColumn(),'review_mappings'=>(int)$db->query("SELECT COUNT(*) FROM class_subjects WHERE curriculum_status IN ('REVIEW_OPTIONAL','REVIEW_11','NO_VERIFY_12')")->fetchColumn(),'student_marks_preserved'=>(int)$db->query('SELECT COUNT(*) FROM marks')->fetchColumn()];
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
