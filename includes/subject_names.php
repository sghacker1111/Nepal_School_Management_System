<?php
/** Application labels only: never rewrite CDC source data or lesson text. */
function subjectCanonicalName(string $name):string {
    $key=mb_strtolower(trim(preg_replace('/\s+/u',' ',$name)),'UTF-8');
    foreach(['Nepali','English','Mathematics'] as $canonical)if(in_array($key,[strtolower($canonical),'compulsory '.strtolower($canonical)],true))return $canonical;
    return $name;
}
function migrateCanonicalSubjectNames(PDO $db):void {
    $update=$db->prepare('UPDATE subjects SET name_en=? WHERE id=?');
    foreach($db->query('SELECT id,name_en FROM subjects')->fetchAll(PDO::FETCH_ASSOC) as $row){$name=subjectCanonicalName((string)$row['name_en']);if($name!==$row['name_en'])$update->execute([$name,$row['id']]);}
}
