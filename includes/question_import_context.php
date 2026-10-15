<?php
require_once __DIR__.'/learning_content_service.php';

/** Import identities are existing catalog records, never new names from a file. */
function questionImportClassId(array $classes,string $value):int {
    $key=questionImportLabel($value);$matches=[];
    $grade=static function(string $label):?int {
        $label=strtr(curriculumNameKey($label),['०'=>'0','१'=>'1','२'=>'2','३'=>'3','४'=>'4','५'=>'5','६'=>'6','७'=>'7','८'=>'8','९'=>'9']);
        return preg_match('/^(?:(?:class|grade|कक्षा)\s*)?([0-9]{1,2})$/u',$label,$m)?(int)$m[1]:null;
    };
    foreach($classes as $class)foreach(['name','name_np'] as $field){
        $name=(string)($class[$field]??'');if($name!==''&&questionImportLabel($name)===$key)$matches[(int)$class['id']]=true;
    }
    if(!$matches&&$grade($value)!==null)foreach($classes as $class)if($grade((string)$class['name'])===$grade($value))$matches[(int)$class['id']]=true;
    if(count($matches)===1)return (int)array_key_first($matches);
    throw new InvalidArgumentException($matches?'Class name is ambiguous; use its exact listed name.':'Class "'.mb_substr(curriculumName($value),0,80).'" is not available. Use its exact listed name.');
}

function questionImportWriterSubjects(PDO $db,array $actor,array $classes):array {
    $result=[];foreach($classes as $c)foreach(learningReadSubjects($db,$actor,(int)$c['id']) as $s)
        if(learningCanCreate($db,$actor,(int)$c['id'],(int)$s['id']))$result[(int)$c['id']][]=$s;
    return $result;
}

function questionImportContext(PDO $db,array $actor,array $input):array {
    $ids=[];
    foreach(['class','subject','chapter','topic'] as $field){
        $value=$input['import_'.$field.'_id']??null;
        if(!is_scalar($value)||!preg_match('/^[1-9][0-9]*$/',(string)$value))throw new InvalidArgumentException('Select an existing Class, Subject, Chapter and Topic before importing.');
        $ids[$field.'_id']=(int)$value;
    }
    $classes=learningReadClasses($db,$actor);$subjects=questionImportWriterSubjects($db,$actor,$classes)[$ids['class_id']]??[];
    $class=null;$subject=null;
    foreach($classes as $c)if((int)$c['id']===$ids['class_id'])$class=$c;
    foreach($subjects as $s)if((int)$s['id']===$ids['subject_id'])$subject=$s;
    if(!$class||!$subject)throw new InvalidArgumentException('Select a class subject that your account is allowed to import into.');
    $chapter=null;$topic=null;
    foreach(learningCatalogChapters($db,$actor,$ids['class_id'],$ids['subject_id']) as $c)if(in_array($ids['chapter_id'],$c['alias_ids'],true))$chapter=$c;
    if(!$chapter)throw new InvalidArgumentException('Select an existing Chapter in the selected Class and Subject.');
    foreach(learningCatalogTopics($db,$actor,$ids['class_id'],$ids['subject_id'],(int)$chapter['id']) as $t)if(in_array($ids['topic_id'],$t['alias_ids'],true))$topic=$t;
    if(!$topic)throw new InvalidArgumentException('Select an existing Topic in the selected Chapter.');
    $catalog=learningCatalogSelection($db,$actor,$ids['class_id'],$ids['subject_id'],['chapter'=>$chapter['name'],'topic'=>$topic['name']],false);
    return $ids+['class'=>$class,'subject'=>$subject,'chapter'=>$chapter,'topic'=>$topic,'catalog'=>$catalog,'classes'=>$classes,'subjects'=>$subjects];
}

/** Preflight the entire file before any save. No blank metadata fallbacks. */
function questionImportMatchContext(array $rows,array $map,array $context):void {
    foreach(['class','subject','chapter','topic'] as $field)if(!isset($map[$field]))throw new InvalidArgumentException('Import rejected: missing required '.$field.' column. No questions were imported.');
    $errors=[];
    foreach(array_slice($rows,1) as $offset=>$row){
        if(!array_filter($row,static fn($v)=>trim((string)$v)!==''))continue;
        try{
            $values=[];foreach(['class','subject','chapter','topic'] as $field){$values[$field]=trim((string)($row[$map[$field]]??''));if($values[$field]==='')throw new InvalidArgumentException(ucfirst($field).' is required in every spreadsheet row.');}
            if(questionImportClassId($context['classes'],$values['class'])!==$context['class_id'])throw new InvalidArgumentException('Class does not match the selected Class.');
            if(questionImportSubjectId($context['subjects'],$values['subject'])!==$context['subject_id'])throw new InvalidArgumentException('Subject does not match the selected Subject.');
            foreach(['chapter','topic'] as $field){
                $labels=array_filter([$context[$field]['name'],$context[$field]['name_np']??'']);
                if(!in_array(curriculumNameKey($values[$field]),array_map('curriculumNameKey',$labels),true))throw new InvalidArgumentException(ucfirst($field).' does not match the selected existing '.ucfirst($field).'. Imports cannot create chapters or topics.');
            }
        }catch(InvalidArgumentException $e){if(count($errors)<10)$errors[]='Row '.($offset+2).': '.$e->getMessage();}
    }
    if($errors)throw new InvalidArgumentException('Import rejected. No questions were imported. '.implode(' ',$errors));
}

function questionImportCatalog(PDO $db,array $actor,int $class,int $subject):array {
    if(!learningCanCreate($db,$actor,$class,$subject))throw new InvalidArgumentException('Choose an assigned class subject.');
    $chapters=learningCatalogChapters($db,$actor,$class,$subject);$topics=[];
    foreach($chapters as $c)$topics[(int)$c['id']]=learningCatalogTopics($db,$actor,$class,$subject,(int)$c['id']);
    return ['chapters'=>$chapters,'topics'=>$topics];
}

function questionImportSubjectLabel(array $context):string {
    $label=(string)$context['subject']['name_en'];
    try{questionImportSubjectId($context['subjects'],$label);}catch(InvalidArgumentException $e){$label=(string)$context['subject']['code'];}
    if(questionImportSubjectId($context['subjects'],$label)!==$context['subject_id'])throw new InvalidArgumentException('Use a unique subject name or code for samples.');
    return $label;
}

function questionImportSampleContext(array $sample,array $context):array {
    $labels=[$context['class']['name'],questionImportSubjectLabel($context),$context['chapter']['name'],$context['topic']['name']];
    foreach($sample['rows'] as &$row)foreach($labels as $index=>$label)$row[$index]=$label;
    unset($row);return $sample;
}
