<?php
/** Exam-bank imports reuse the normal bank's HTML and curriculum security rules. */
require_once __DIR__.'/learning_content_service.php';
require_once __DIR__.'/xlsx_helper.php';

function examImportSubjects(PDO $db,array $actor,array $classes):array {
    $result=[];
    foreach($classes as $class)foreach(learningReadSubjects($db,$actor,(int)$class['id']) as $subject){
        if(learningCanCreate($db,$actor,(int)$class['id'],(int)$subject['id']))$result[(int)$class['id']][]=$subject;
    }
    return $result;
}

function examImportHeaders(array $headers,string $mode):array {
    questionImportFormat($mode);
    if(count($headers)>64)throw new InvalidArgumentException('Use at most 64 columns in the import file.');
    $aliases=[
        'class'=>['class','classname','grade','classlevel','standard'],
        'subject'=>['subject','subjectname','course','sub','subjectcode'],
        'chapter'=>['chapter','chaptername','unit','lesson','chapterunit'],
        'topic'=>['topic','topicname','subtopic'],
        'type'=>['type','questiontype','qtype'],
        'difficulty'=>['difficulty','difficultylevel'],
        'source'=>['source','contentsource'],
        'cognitive_domain'=>['cognitivelevel','cognitivedomain','cognitive','domain','bloom','bloomslevel','kuaha','taxonomylevel'],
        'marks'=>['marks','mark','weightage','points'],
        'required_minutes'=>['requiredminutes','requiredtime','minutes','timeinminutes','durationminutes'],
        'question_text'=>['question','questiontext','questionenglish','questionen','qenglish','qtext','englishquestion','qus','qusen','qusenglish'],
        'question_text_np'=>['questionnepali','questionnp','qnepali','qtextnp','nepaliquestion','qusnp','qusnepali'],
        'answer'=>['answer','answeren','answerenglish','ans','ansen','ansenglish','answermodelen','modelanswer','modelanswerenglish','expectedanswer','expectedansweren'],
        'answer_np'=>['answernp','answernepali','ansnp','ansnepali','answermodelnp','modelanswernepali','expectedanswernp','expectedanswernepali'],
        'correct_option'=>['correctoption','correct','answerkey','key','correctanswer','correctchoice'],
    ];
    foreach(['a','b','c','d'] as $option){
        $aliases['option_'.$option]=['option'.$option,'option'.$option.'en','option'.$option.'english','choice'.$option,'choice'.$option.'english'];
        $aliases['option_'.$option.'_np']=['option'.$option.'np','option'.$option.'nepali','choice'.$option.'np','choice'.$option.'nepali'];
    }
    $map=[];$seen=[];
    foreach($headers as $index=>$header){
        $key=strtolower(preg_replace('/[^a-z0-9]/i','',(string)$header));
        if(in_array($key,['schoolid','createdbyuserid','bankkind','studentvisible','visibility','questionid','id'],true))throw new InvalidArgumentException('Do not include ownership, visibility, student release or ID columns. These are set by your account and this section.');
        $isSource=(str_contains($key,'html')||str_contains($key,'source'))&&(bool)preg_match('/question|answer|option|choice|qus|ans/',$key);
        if($isSource)$key=str_replace(['html','source'],'',$key);
        foreach($aliases as $field=>$names){
            if(!in_array($key,$names,true))continue;
            $variant=$isSource?'html':'normal';
            if(isset($seen[$field][$variant]))throw new InvalidArgumentException('Duplicate column for '.$field.'. Keep one normal and, optionally, one HTML Source column.');
            $seen[$field][$variant]=true;
            $priority=$isSource?($mode==='html'?2:0):1;
            if(!isset($map[$field])||$priority>$map[$field]['priority'])$map[$field]=['index'=>$index,'priority'=>$priority];
            break;
        }
    }
    foreach(['class','subject','chapter','type','difficulty','cognitive_domain','marks','required_minutes'] as $field){
        if(!isset($map[$field]))throw new InvalidArgumentException('Missing required column: '.str_replace('_',' ',$field).'. Download an Exam Questions sample with the correct headings.');
    }
    if(!isset($map['question_text'])&&!isset($map['question_text_np']))throw new InvalidArgumentException('Include Question (English) or Question (Nepali).');
    return array_map(static fn($entry)=>$entry['index'],$map);
}

function examImportClassId(array $classes,string $value):int {
    $key=questionImportLabel($value);$matches=[];
    $grade=static function(string $label):?int {
        $label=strtr(curriculumNameKey($label),['०'=>'0','१'=>'1','२'=>'2','३'=>'3','४'=>'4','५'=>'5','६'=>'6','७'=>'7','८'=>'8','९'=>'9']);
        return preg_match('/^(?:(?:class|grade|कक्षा)\s*)?([0-9]{1,2})$/u',$label,$match)?(int)$match[1]:null;
    };
    foreach($classes as $class)foreach(['name','name_np'] as $field){
        $name=(string)($class[$field]??'');
        if($name!==''&&questionImportLabel($name)===$key)$matches[(int)$class['id']]=true;
    }
    if(!$matches&&$grade($value)!==null)foreach($classes as $class){
        if($grade((string)$class['name'])===$grade($value))$matches[(int)$class['id']]=true;
    }
    if(count($matches)===1)return (int)array_key_first($matches);
    throw new InvalidArgumentException($matches?'Class name is ambiguous; use its exact listed name.':'Class "'.mb_substr(curriculumName($value),0,80).'" is not available. Use its exact listed name.');
}

/** Bound archive expansion and spreadsheet dimensions before the shared reader. */
function examImportReadUpload(array $file):array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Choose a valid Excel (.xlsx) or CSV file.');
    $extension=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
    if(!in_array($extension,['xlsx','csv'],true))throw new InvalidArgumentException('Only .xlsx and .csv files are supported. Save older Excel files as .xlsx.');
    if(filesize($file['tmp_name'])>5*1024*1024)throw new InvalidArgumentException('The import file must be 5 MB or smaller.');
    if($extension==='xlsx'){
        $zip=new ZipArchive();
        if($zip->open($file['tmp_name'])!==true)throw new InvalidArgumentException('This is not a valid XLSX workbook. Download a sample or export the file again.');
        try{
            if($zip->numFiles>100)throw new InvalidArgumentException('The workbook is too complex; copy the questions to a sample workbook.');
            $expanded=0;$hasSheet=false;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$expanded+=(int)$stat['size'];
                if($expanded>32*1024*1024)throw new InvalidArgumentException('The expanded workbook is too large. Split it into smaller imports.');
                if(!preg_match('/\.(?:xml|rels)$/i',$stat['name']))continue;
                $xml=$zip->getFromIndex($i);
                if($xml===false||stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)throw new InvalidArgumentException('The workbook contains unsupported XML declarations.');
                if(!preg_match('#^xl/worksheets/sheet\d+\.xml$#i',$stat['name']))continue;
                $hasSheet=true;$previous=libxml_use_internal_errors(true);
                try{$sheet=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET);}
                finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
                if(!$sheet||!isset($sheet->sheetData))throw new InvalidArgumentException('The workbook worksheet could not be read.');
                if(count($sheet->sheetData->row)>2001)throw new InvalidArgumentException('Import at most 2,000 question rows at a time.');
                $lastRow=0;
                foreach($sheet->sheetData->row as $row){
                    if(!preg_match('/^[1-9][0-9]{0,3}$/',(string)$row['r'])||(int)$row['r']>2001)throw new InvalidArgumentException('Keep question rows within the first 2,001 worksheet rows (including headings).');
                    $rowNumber=(int)$row['r'];if($rowNumber<=$lastRow)throw new InvalidArgumentException('Worksheet rows must be in order without duplicate row numbers.');$lastRow=$rowNumber;
                    foreach($row->c as $cell){
                        $ref=(string)$cell['r'];
                        if(!preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/',$ref,$match)||XlsxHelper::columnLetterToIndex($match[1])>=64)throw new InvalidArgumentException('Use the first 64 columns only; remove unused distant cells.');
                        if((int)$match[2]!==$rowNumber)throw new InvalidArgumentException('A worksheet cell does not match its row. Export the workbook again.');
                    }
                }
            }
            if(!$hasSheet)throw new InvalidArgumentException('The XLSX file has no readable question worksheet.');
        }finally{$zip->close();}
        $rows=XlsxHelper::read($file['tmp_name'],true);
    }else $rows=XlsxHelper::readCsv($file['tmp_name'],true);
    if(count($rows)<2)throw new InvalidArgumentException('Include the headings and at least one question row.');
    if(count($rows)>2001)throw new InvalidArgumentException('Import at most 2,000 question rows at a time.');
    foreach($rows as $row){
        if(count($row)>64)throw new InvalidArgumentException('Use at most 64 columns in the import file.');
        foreach($row as $cell)if(strlen($cell)>100000||!mb_check_encoding($cell,'UTF-8'))throw new InvalidArgumentException('Use UTF-8 text and keep each cell below 100,000 bytes.');
    }
    return $rows;
}

function examImportQuestions(PDO $db,array $actor,array $rows,string $mode,string $visibility):array {
    questionImportFormat($mode);learningVisibility($visibility);
    if(!learningPurposeAllowed($db,$actor,'exam_bank')||$actor['read_only']||!in_array($actor['role'],['super_admin','school_admin','teacher'],true))throw new InvalidArgumentException('Your account cannot import exam questions.');
    $classes=learningReadClasses($db,$actor);$subjects=examImportSubjects($db,$actor,$classes);
    if(!$subjects)throw new InvalidArgumentException('No assigned class subjects are available for importing.');
    $map=examImportHeaders($rows[0],$mode);$ids=[];$errors=[];$skipped=0;
    // Any unexpected storage error rolls back the whole batch. Invalid rows alone
    // are skipped, with savepoints retaining neither catalog inserts nor questions.
    return learningWrite($db,function()use($db,$actor,$rows,$map,$mode,$visibility,$classes,$subjects,&$ids,&$errors,&$skipped){
        foreach(array_slice($rows,1) as $offset=>$row){
            if(!array_filter($row,static fn($v)=>trim((string)$v)!==''))continue;
            $db->exec('SAVEPOINT exam_import_row');
            try{
                $get=static fn($field)=>(string)($row[$map[$field]??-1]??'');
                $class=examImportClassId($classes,$get('class'));
                $subject=questionImportSubjectId($subjects[$class]??[],$get('subject'));
                $types=['mcq'=>'MCQ','very short'=>'Very Short','veryshort'=>'Very Short','short'=>'Short','long'=>'Long','reasoning'=>'Reasoning','difference between'=>'Difference Between','differencebetween'=>'Difference Between'];
                $type=$types[curriculumNameKey($get('type'))]??null;
                if(!$type)throw new InvalidArgumentException('Type must be MCQ, Very Short, Short, Long, Reasoning or Difference Between.');
                $levels=['easy'=>'Easy','medium'=>'Medium','hard'=>'Hard','challenging'=>'Challenging'];$difficulty=$levels[curriculumNameKey($get('difficulty'))]??null;
                if(!$difficulty)throw new InvalidArgumentException('Difficulty must be Easy, Medium, Hard or Challenging.');
                $domainKey=strtolower(preg_replace('/[^a-z]/i','',$get('cognitive_domain')));
                $domains=['k'=>'Knowledge','knowledge'=>'Knowledge','knowledgek'=>'Knowledge','u'=>'Understanding','understanding'=>'Understanding','understandingu'=>'Understanding','a'=>'Application','application'=>'Application','applicationa'=>'Application','ha'=>'Higher Ability','higherability'=>'Higher Ability','higherabilityha'=>'Higher Ability'];
                $domain=$domains[$domainKey]??null;if(!$domain)throw new InvalidArgumentException('Cognitive Level must be K, U, A or HA.');
                $source=curriculumNameKey($get('source'));$permitted=$actor['school_id']===0?['shared','shared catalog']:['school','this school'];
                if($source!==''&&$source!=='auto'&&!in_array($source,$permitted,true))throw new InvalidArgumentException('Source must be Auto or '.($actor['school_id']===0?'Shared':'School').'; the school and creator are assigned by your account.');
                $marks=learningDecimalString(learningDecimalUnits($get('marks'),100000,'Question marks'));
                $minutes=learningDecimalString(learningDecimalUnits($get('required_minutes'),60000,'Required minutes'));
                if(trim($get('chapter'))==='')throw new InvalidArgumentException('Chapter is required.');
                $content=questionImportRowContent($get,$mode);
                if($type!=='MCQ'&&$content['type']==='MCQ')throw new InvalidArgumentException('Rows with MCQ options must use Type MCQ.');
                $content['marks']=$marks;
                $ids[]=learningSaveQuestion($db,$actor,$content+['class_id'=>$class,'subject_id'=>$subject,'chapter'=>$get('chapter'),'topic'=>$get('topic'),'type'=>$type,'difficulty'=>$difficulty,'cognitive_domain'=>$domain,'required_minutes'=>$minutes,'visibility'=>$visibility,'student_visible'=>'0'],null,'exam');
                $db->exec('RELEASE SAVEPOINT exam_import_row');
            }catch(InvalidArgumentException $error){
                $db->exec('ROLLBACK TO SAVEPOINT exam_import_row');$db->exec('RELEASE SAVEPOINT exam_import_row');$skipped++;
                if(count($errors)<10)$errors[]='Row '.($offset+2).': '.$error->getMessage();
            }
        }
        if(!$ids)throw new InvalidArgumentException('No exam questions were imported. '.($errors?$skipped.' row(s) skipped. '.implode(' ',$errors).($skipped>count($errors)?' Only the first 10 errors are shown.':''):'The file contains no question rows.'));
        return ['ids'=>$ids,'saved'=>count($ids),'skipped'=>$skipped,'errors'=>$errors];
    });
}

/** Examples use a real writable class subject/catalog, not an arbitrary grade. */
function examImportSample(PDO $db,array $actor,string $mode,int $class=0,int $subject=0):array {
    questionImportFormat($mode);$classes=learningReadClasses($db,$actor);$writer=examImportSubjects($db,$actor,$classes);$selection=null;
    foreach($classes as $c){
        if($class&&(int)$c['id']!==$class)continue;
        foreach($writer[(int)$c['id']]??[] as $s){
            if($subject&&(int)$s['id']!==$subject)continue;
            $chapters=learningCatalogChapters($db,$actor,(int)$c['id'],(int)$s['id']);if(!$chapters)continue;
            $chapter=$chapters[0];$topics=[];
            // Prefer an example with a real topic, while allowing topic-free chapters.
            foreach($chapters as $candidate){$candidateTopics=learningCatalogTopics($db,$actor,(int)$c['id'],(int)$s['id'],(int)$candidate['id']);if($candidateTopics){$chapter=$candidate;$topics=$candidateTopics;break;}}
            $subjectLabel=(string)$s['name_en'];
            try{questionImportSubjectId($writer[(int)$c['id']],$subjectLabel);}catch(InvalidArgumentException $e){$subjectLabel=(string)$s['code'];questionImportSubjectId($writer[(int)$c['id']],$subjectLabel);}
            $selection=[$c,$subjectLabel,$chapter,$topics[0]['name']??''];break 2;
        }
    }
    if(!$selection)throw new InvalidArgumentException('Select an assigned class subject with an existing chapter before downloading a ready-to-import sample. Ask super admin to add missing chapters.');
    [$c,$subjectLabel,$chapter,$topic]=$selection;$source=$actor['school_id']===0?'Shared':'School';
    $headers=['Class','Subject','Chapter','Topic','Type','Difficulty','Source','Cognitive Level','Marks','Required Minutes','Question (English)','Question (Nepali)','Answer (English)','Answer (Nepali)','Option A (English)','Option A (Nepali)','Option B (English)','Option B (Nepali)','Option C (English)','Option C (Nepali)','Option D (English)','Option D (Nepali)','Correct Option'];
    $rows=[
        [$c['name'],$subjectLabel,$chapter['name'],$topic,'MCQ','Easy',$source,'K',1,2,'What is 1 + 1?','१ + १ कति हुन्छ?','The correct answer is 2.','सही उत्तर २ हो।','1','१','2','२','3','३','4','४','B'],
        [$c['name'],$subjectLabel,$chapter['name'],$topic,'Short','Medium',$source,'U',2.5,3.25,'Explain why 1/2 + 1/2 = 1.','१/२ + १/२ = १ किन हुन्छ?','Two halves make one whole.','दुई आधा मिलेर एक पूर्ण हुन्छ।','','','','','','','','',''],
    ];
    if($mode==='html'){
        foreach($headers as $i=>$header)if($i>=10&&$i<=21)$headers[$i]=$header.' HTML Source';
        foreach($rows as &$row)for($i=10;$i<=21;$i++)if($row[$i]!=='')$row[$i]='<p>'.htmlspecialchars($row[$i],ENT_QUOTES,'UTF-8').'</p>';unset($row);
        $rows[0][10]='<p><strong>What is</strong> \\(1+1\\)?</p>';
        $rows[1][10]='<p>Explain why \\(\\frac{1}{2}+\\frac{1}{2}=1\\).</p>';
        $rows[1][12]='<p>\\[\\frac{1+1}{2}=1\\]</p>';
    }
    return [$headers,$rows];
}
