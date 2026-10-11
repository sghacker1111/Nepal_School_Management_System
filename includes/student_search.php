<?php
/** Text search never includes class/status names: those are exact-ID filters. */
function studentSearchTerm($value):string {
    if(!is_string($value))throw new InvalidArgumentException('Enter a valid student search.');
    $value=trim($value);if(mb_strlen($value)>100)throw new InvalidArgumentException('Student search must be at most 100 characters.');return $value;
}
function studentSearchWhere(string $search,array &$params,string $alias='s'):string {
    if(!in_array($alias,['s','st'],true))throw new InvalidArgumentException('Invalid student search scope.');
    $search=trim($search);
    if($search==='')return '';
    if(mb_strlen($search)>100)throw new InvalidArgumentException('Student search must be at most 100 characters.');
    $value='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
    $fields=["$alias.first_name_en","$alias.last_name_en","$alias.first_name_np","$alias.last_name_np","CONCAT_NAME", "$alias.student_id","$alias.roll_number","$alias.emis_id","$alias.emis_registration_number"];
    $name=DB_TYPE==='mysql'?"CONCAT($alias.first_name_en,' ',COALESCE($alias.last_name_en,''))":"$alias.first_name_en || ' ' || COALESCE($alias.last_name_en,'')";
    $parts=[];
    foreach($fields as $field){$parts[]=($field==='CONCAT_NAME'?$name:$field)." LIKE ? ESCAPE '!'";$params[]=$value;}
    return ' AND ('.implode(' OR ',$parts).')';
}
