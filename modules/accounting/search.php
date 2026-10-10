<?php
require_once __DIR__.'/../../includes/accounting_ui.php';
header('Content-Type: application/json');
$q=trim((string)($_GET['q']??''));if(mb_strlen($q)<2){echo '[]';exit;}$q='%'.substr($q,0,100).'%';
$rows=$ac->rows("SELECT s.id,s.student_id,s.first_name_en,s.last_name_en,c.name class_name,s.guardian_phone FROM students s JOIN classes c ON c.id=s.class_id AND c.school_id=s.school_id WHERE s.school_id=? AND (s.first_name_en LIKE ? OR s.last_name_en LIKE ? OR s.student_id LIKE ? OR s.guardian_phone LIKE ? OR s.father_phone LIKE ? OR c.name LIKE ?) ORDER BY s.first_name_en,s.last_name_en LIMIT 20",[$school,$q,$q,$q,$q,$q,$q]);
echo json_encode(array_map(fn($s)=>['id'=>(int)$s['id'],'label'=>$s['first_name_en'].' '.$s['last_name_en'].' · '.$s['student_id'].' · '.$s['class_name']],$rows),JSON_UNESCAPED_UNICODE);
