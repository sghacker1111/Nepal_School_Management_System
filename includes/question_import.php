<?php
require_once __DIR__.'/curriculum_catalog.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/subject_names.php';

function questionImportFormat(mixed $mode):string {
    if(!is_string($mode)||!in_array($mode,['normal','html'],true))throw new InvalidArgumentException('Choose Normal Text or HTML Source as the import method.');
    return $mode;
}

/** Convert editor/MathJax source carriers to portable TeX before sanitizing HTML. */
function questionImportMathSource(string $html):string {
    $doc=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="erp-import-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
    libxml_clear_errors();libxml_use_internal_errors($previous);$xpath=new DOMXPath($doc);
    foreach(iterator_to_array($xpath->query('//*[@data-tex]')) as $node){
        if(!in_array('math-tex',preg_split('/\s+/',trim($node->getAttribute('class'))),true))continue;
        $tex=$node->getAttribute('data-tex');$block=$node->getAttribute('data-mode')==='block';
        while($node->firstChild)$node->removeChild($node->firstChild);
        $node->appendChild($doc->createTextNode(($block?'\\[':'\\(').$tex.($block?'\\]':'\\)')));
    }
    foreach(iterator_to_array($xpath->query('//script')) as $node){
        $type=strtolower(trim($node->getAttribute('type')));
        if(!preg_match('/^math\/tex(?:;\s*mode=display)?$/',$type))continue;
        $block=str_contains($type,'display');$node->parentNode->replaceChild($doc->createTextNode(($block?'\\[':'\\(').$node->textContent.($block?'\\]':'\\)')),$node);
    }
    $root=$doc->getElementById('erp-import-root');$output='';if($root)foreach($root->childNodes as $node)$output.=$doc->saveHTML($node);
    return $output;
}
function questionImportContent(string $value,string $mode):string {
    questionImportFormat($mode);$value=trim($value);if($value==='')return '';
    // Normal mode treats angle brackets/ampersands literally; source mode retains
    // supported HTML. Both preserve TeX and pass through the same security filter.
    $html=$mode==='html'?questionImportMathSource($value):nl2br(htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));
    return fixNepaliInLatex($html);
}
function questionImportHasContent(string $value):bool {
    return trim(html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'))!==''||(bool)preg_match('/<img\b[^>]*\bsrc=/i',$value);
}
function questionImportRowContent(callable $get,string $mode):array {
    $q=questionImportContent($get('question_text'),$mode);$np=questionImportContent($get('question_text_np'),$mode);
    if(!questionImportHasContent($q)&&questionImportHasContent($np))$q=$np;
    if(!questionImportHasContent($q))throw new InvalidArgumentException('Question text is empty after checking its content.');
    $types=['very short'=>'Very Short','veryshort'=>'Very Short','short'=>'Short','long'=>'Long','reasoning'=>'Reasoning','difference between'=>'Difference Between','differencebetween'=>'Difference Between','mcq'=>'MCQ'];
    $type=$types[curriculumNameKey($get('type'))]??'Short';$en=[];$nepali=[];
    foreach(['A','B','C','D'] as $key){$en[$key]=questionImportContent($get('option_'.strtolower($key)),$mode);$nepali[$key]=questionImportContent($get('option_'.strtolower($key).'_np'),$mode);}
    if($type==='MCQ'||count(array_filter([...array_values($en),...array_values($nepali)],'questionImportHasContent'))) {
        $type='MCQ';foreach(['A','B','C','D'] as $key)if(!questionImportHasContent($en[$key])&&!questionImportHasContent($nepali[$key]))throw new InvalidArgumentException('MCQ option '.$key.' is empty. Supply all four options.');
        $correct=strtoupper(trim($get('correct_option')));if(!in_array($correct,['A','B','C','D'],true))throw new InvalidArgumentException('MCQ Correct Option must be A, B, C or D.');
        $options=json_encode($en,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$optionsNp=json_encode($nepali,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }else{$correct=null;$options=null;$optionsNp=null;}
    $marks=(int)$get('marks');if($marks<=0)$marks=match($type){'MCQ','Very Short'=>1,'Long'=>8,'Reasoning','Difference Between'=>4,default=>5};
    return ['question_text'=>$q,'question_text_np'=>$np,'type'=>$type,'options'=>$options,'options_np'=>$optionsNp,'correct_option'=>$correct,'marks'=>$marks,'answer'=>questionImportContent($get('answer'),$mode),'answer_np'=>questionImportContent($get('answer_np'),$mode)];
}

function questionImportLabel(string $value):string {
    return curriculumNameKey(str_replace('&',' and ',curriculumName($value)));
}

/** Known curriculum labels only; optional and compulsory subjects remain distinct. */
function questionImportSubjectKey(string $value):string {
    $key=questionImportLabel(subjectCanonicalName($value));
    $groups=[
        'mathematics'=>['mathematics','math','maths','compulsory mathematics','compulsory math','compulsory maths','गणित','अनिवार्य गणित'],
        'optional mathematics'=>['optional mathematics','optional math','optional maths','ऐच्छिक गणित'],
        'science'=>['science','science and technology','compulsory science','विज्ञान','विज्ञान तथा प्रविधि','विज्ञान र प्रविधि'],
        'english'=>['english','compulsory english','अंग्रेजी','अङ्ग्रेजी','अनिवार्य अंग्रेजी','अनिवार्य अङ्ग्रेजी'],
        'nepali'=>['nepali','compulsory nepali','नेपाली','अनिवार्य नेपाली'],
        'social studies'=>['social studies','social','सामाजिक अध्ययन','सामाजिक शिक्षा'],
    ];
    foreach($groups as $canonical=>$aliases)if(in_array($key,$aliases,true))return $canonical;
    return $key;
}

/** Match only subjects already permitted for this actor and this exact class. */
function questionImportSubjectId(array $subjects,string $value):int {
    $label=questionImportLabel($value);$matches=[];
    foreach($subjects as $subject){
        foreach(['name_en','name_np','code'] as $field){
            $candidate=questionImportLabel((string)($subject[$field]??''));
            if($candidate!==''&&$candidate===$label){$matches[(int)$subject['id']]=true;break;}
        }
    }
    if(!$matches){
        $key=questionImportSubjectKey($value);
        foreach($subjects as $subject)foreach(['name_en','name_np'] as $field){
            $candidate=(string)($subject[$field]??'');
            if($candidate!==''&&questionImportSubjectKey($candidate)===$key){$matches[(int)$subject['id']]=true;break;}
        }
    }
    if(count($matches)===1)return (int)array_key_first($matches);
    $label=mb_substr(curriculumName($value),0,100,'UTF-8');
    if($matches)throw new InvalidArgumentException('Subject "'.$label.'" matches multiple assigned subjects. Use its subject code.');
    throw new InvalidArgumentException('Subject "'.$label.'" is not assigned or available for this class. Use a listed subject name or code.');
}
