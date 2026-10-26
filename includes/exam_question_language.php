<?php
/** Presentation only: never rewrite imported bilingual content or question IDs. */
require_once __DIR__.'/question_import.php';
require_once __DIR__.'/question_parts.php';

/** Put the label in the text paragraph, independent of flex/grid stylesheet order. */
function examQuestionPartLabelHtml(string $body,string $label,bool $hanging=false):string {
    if($label==='')return $body;
    $prefix='<span class="exam-part-label">'.sanitize($label).'</span>'.($hanging?'':' ');
    if(preg_match('/^(\s*(?:<(?:div|blockquote)\b[^>]*>\s*)*<(?:p|h[1-6])\b[^>]*>)/i',$body))return preg_replace_callback('/^(\s*(?:<(?:div|blockquote)\b[^>]*>\s*)*<(?:p|h[1-6])\b[^>]*>)/i',static fn($m)=>$m[1].$prefix,$body,1);
    if(preg_match('/^\s*<(div|blockquote)\b[^>]*>/i',$body))return preg_replace_callback('/^(\s*<(?:div|blockquote)\b[^>]*>)/i',static fn($m)=>$m[1].$prefix,$body,1);
    if(!preg_match('/^\s*<(?:table|pre|ol|ul)\b/i',$body))return '<p>'.$prefix.$body.'</p>';
    return $prefix.$body;
}

function examQuestionPartSuffixHtml(string $body,string $suffix):string {
    $annotation='<span class="exam-part-score">'.sanitize($suffix).'</span>';
    // Keep the annotation at the end of the last English paragraph, even when
    // the part contains several paragraphs, diagrams or a table.
    if(preg_match('/<\/p>\s*$/i',$body))return preg_replace('/<\/p>(\s*)$/i',' '.$annotation.'</p>$1',$body,1);
    return $body.' '.$annotation;
}
function examStructuredQuestionRich(array $structure,string $language):string {
    $width=max(3,max(array_map(static fn($part)=>mb_strlen($part['label'])+1.5,$structure['parts'])));
    $out='<div class="exam-bilingual-content exam-structured-content" style="--exam-label-width:'.$width.'rem">';
    $rows=[['label'=>'','en'=>$structure['statement_en'],'np'=>$structure['statement_np']],...$structure['parts']];$shown=false;
    foreach($rows as $row){
        $en=$language==='ne'?'':$row['en'];$ne=$language==='en'?'':$row['np'];
        if(!questionImportHasContent($en)&&!questionImportHasContent($ne))continue;$shown=true;
        $out.='<div class="exam-bilingual-row">';
        $out.='<div class="exam-language-pair'.($row['label']!==''?' exam-subquestion-pair':'').'">';
        $suffix=$row['label']===''?'':questionPartsSuffix($row,$structure['display']);
        $label=$row['label']===''?'':'('.$row['label'].')';
        $bodies=['ne'=>$ne,'en'=>$en];$order=$language==='both_en'?['en','ne']:['ne','en'];
        $visible=array_values(array_filter($order,static fn($key)=>questionImportHasContent($bodies[$key])));
        foreach($visible as $index=>$key){
            $body=$index===0?examQuestionPartLabelHtml($bodies[$key],$label,true):$bodies[$key];
            // English receives the annotation even when it is displayed first.
            if($suffix!==''&&($key==='en'||!questionImportHasContent($en)))$body=examQuestionPartSuffixHtml($body,$suffix);
            $out.='<div class="exam-language-'.$key.($key==='ne'?' font-nepali':'').'" lang="'.$key.'">'.$body.'</div>';
        }
        $out.='</div></div>';
    }
    if(!$shown)$out.='<div class="exam-language-missing">'.($language==='en'?'English':'Nepali').' version not provided.</div>';
    return $out.'</div>';
}

function examQuestionLanguage(mixed $value='both'):string {
    if(!is_string($value)||!array_key_exists($value,examQuestionLanguages()))throw new InvalidArgumentException('Choose a valid question language and order.');
    return $value;
}
function examQuestionLanguages():array {return ['both'=>'Nepali then English','both_en'=>'English then Nepali','en'=>'English only','ne'=>'Nepali only'];}

/** Totals remain available to allocation, but are not repeated beside a statement. */
function examQuestionHasParts(array $question):bool {return questionPartsRead($question['question_text']??'',$question['question_text_np']??'')!==null;}
function examQuestionAnswerMarks(array $question):string {
    $structure=questionPartsRead($question['question_text']??'',$question['question_text_np']??'');
    if(!$structure){$units=$question['marks_units']??(int)round((float)($question['marks']??0)*100);return '<p class="exam-answer-marks">Marks: '.sanitize(questionPartNumber($units)).'</p>';}
    $out='<div class="exam-answer-marks" aria-label="Subquestion marking guide">';
    foreach($structure['parts'] as $part)$out.='<span class="exam-answer-part-mark">('.sanitize($part['label']).') '.sanitize(questionPartsSuffix($part,$structure['display']==='cognitive'?'both':$structure['display'])).'</span> ';
    return $out.'</div>';
}

/** Explicit conversion draft; legacy stored bodies are never changed by a read. */
function examQuestionPartsDraft(array $form):array {
    if(($form['question_layout']??'')==='structured')return ['display'=>$form['part_display']??'both','math_rule'=>$form['mathematics_rule']??'strict','statement_en'=>$form['statement_en']??'','statement_np'=>$form['statement_np']??'','parts'=>$form['subquestions']??[]];
    $stored=questionPartsRead($form['question_text']??'',$form['question_text_np']??'');if($stored)return $stored;
    $en=examRichParagraphs($form['question_text']??'');$ne=examRichParagraphs($form['question_text_np']??'');$pairs=examBilingualPairs($en,$ne);$parts=[];$statementEn='';$statementNp='';
    $hasLabels=(bool)array_filter([...array_column($en,'label'),...array_column($ne,'label')]);
    foreach($pairs as $i=>[$e,$n]){$label=$e['label']??$n['label']??'';if($label==='')$label=$n['label']??'';
        if(($hasLabels&&$label===''&&!$parts)||(!$hasLabels&&$i===0)){$statementEn.='<p>'.($e['html']??'').'</p>';$statementNp.='<p>'.($n['html']??'').'</p>';continue;}
        if($hasLabels&&$label===''&&$parts){$last=count($parts)-1;$parts[$last]['en'].='<p>'.($e['html']??'').'</p>';$parts[$last]['np'].='<p>'.($n['html']??'').'</p>';continue;}
        $parts[]=['label'=>trim($label," ().\t\r\n")?:questionPartLabel(count($parts)),'en'=>$e['html']??'','np'=>$n['html']??''];
    }
    if(!$parts)$parts=array_fill(0,3,['en'=>'','np'=>'']);$units=100*count($parts);try{if(isset($form['marks']))$units=questionPartUnits((string)$form['marks']);}catch(InvalidArgumentException){}
    foreach($parts as $i=>&$part){$part+=['label'=>questionPartLabel($i),'marks'=>questionPartNumber(max(1,intdiv($units,count($parts))+($i<$units%count($parts)?1:0))),'levels'=>[$i===0?'K':($i===count($parts)-1?'HA':'U')]];}unset($part);
    return ['display'=>'both','statement_en'=>$statementEn,'statement_np'=>$statementNp,'parts'=>$parts];
}

/** Split rich paragraphs/line breaks without breaking formatting, TeX or tables. */
function examRichParagraphs(string $html):array {
    $html=safeRichHtml(questionImportMathSource($html));
    $doc=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="exam-language-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
    libxml_clear_errors();libxml_use_internal_errors($previous);
    $join=static function(array $left,array $right):array {$left[count($left)-1].=array_shift($right);return [...$left,...$right];};
    $split=function(DOMNode $node)use(&$split,$join,$doc):array {
        if($node instanceof DOMText)return [htmlspecialchars($node->textContent,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')];
        if(!$node instanceof DOMElement)return [''];
        $tag=strtolower($node->tagName);
        if($tag==='br')return ['',''];
        if(in_array($tag,['table','pre'],true))return ['', $doc->saveHTML($node), ''];
        if($tag==='img')return [$doc->saveHTML($node)];
        $pieces=[''];foreach($node->childNodes as $child)$pieces=$join($pieces,$split($child));
        if(in_array($tag,['p','div','h1','h2','h3','h4','h5','h6','blockquote','li','ol','ul'],true)){
            if($tag==='li'&&$node->parentNode instanceof DOMElement){
                $index=1;for($s=$node->previousSibling;$s;$s=$s->previousSibling)if($s instanceof DOMElement&&strtolower($s->tagName)==='li')$index++;
                $prefix=strtolower($node->parentNode->tagName)==='ol'?'('.$index.') ':'• ';
                $pieces[0]=$prefix.$pieces[0];
            }
            return ['',...$pieces,''];
        }
        $shell=$doc->saveHTML($node->cloneNode(false));$end='</'.$tag.'>';
        $open=str_ends_with($shell,$end)?substr($shell,0,-strlen($end)):$shell;
        return array_map(static fn($part)=>trim($part)===''?$part:$open.$part.$end,$pieces);
    };
    $root=$doc->getElementById('exam-language-root');$parts=[''];if($root)foreach($root->childNodes as $child)$parts=$join($parts,$split($child));
    $result=[];
    foreach($parts as $part){
        if(!questionImportHasContent($part))continue;
        $label='';$text=html_entity_decode(strip_tags($part),ENT_QUOTES|ENT_HTML5,'UTF-8');
        // A bare label needs a word boundary after its punctuation: 0.5 and
        // ०.५ are numeric content, not subpart labels to remove or re-pair.
        if(preg_match('/^\s*(\([a-zA-Z0-9\x{0966}-\x{096F}]{1,5}\)|[a-zA-Z0-9\x{0966}-\x{096F}]{1,3}[.)](?=\s|$))\s*/u',$text,$match)){
            $label=trim($match[1]);$cut=mb_strlen($match[0]);
            $fragment=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
            $fragment->loadHTML('<?xml encoding="UTF-8"><div id="part">'.$part.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
            libxml_clear_errors();libxml_use_internal_errors($previous);
            foreach((new DOMXPath($fragment))->query('//text()') as $node){if(!$cut)break;$length=mb_strlen($node->textContent);$node->textContent=mb_substr($node->textContent,min($cut,$length));$cut=max(0,$cut-$length);}
            $part='';foreach($fragment->getElementById('part')->childNodes as $child)$part.=$fragment->saveHTML($child);
        }
        $result[]=['label'=>$label,'html'=>$part];
    }
    return $result;
}

function examPartLabelKey(string $label):string {
    return strtr(mb_strtolower(trim($label," ().\t\r\n")),['०'=>'0','१'=>'1','२'=>'2','३'=>'3','४'=>'4','५'=>'5','६'=>'6','७'=>'7','८'=>'8','९'=>'9']);
}
/** Align matching subpart labels; do not pair (a) with (b) if a translation is missing. */
function examBilingualPairs(array $en,array $ne):array {
    $pairs=[];$i=0;$j=0;
    while($i<count($en)||$j<count($ne)){
        $e=$en[$i]??null;$n=$ne[$j]??null;
        if($e&&$n&&$e['label']!==''&&$n['label']!==''&&examPartLabelKey($e['label'])!==examPartLabelKey($n['label'])){
            $later=array_map('examPartLabelKey',array_column(array_slice($en,$i+1),'label'));
            if(in_array(examPartLabelKey($n['label']),$later,true)){$pairs[]=[$e,null];$i++;}else{$pairs[]=[null,$n];$j++;}
        }else{$pairs[]=[$e,$n];if($e)$i++;if($n)$j++;}
    }
    return $pairs;
}
function examQuestionRich(string $english,string $nepali,string $language='both',bool $questionParts=false):string {
    examQuestionLanguage($language);
    if($questionParts){$structure=questionPartsRead($english,$nepali);if($structure)return examStructuredQuestionRich($structure,$language);}
    $en=examRichParagraphs($english);$ne=examRichParagraphs($nepali);
    if($questionParts){
        // For questions, an unlabeled first paragraph is the statement and later
        // matching paragraphs are subparts. Answers/options keep ordinary prose.
        foreach(['en','ne'] as $key){
            $parts=$$key;$hasLabels=(bool)array_filter(array_column($parts,'label'));
            if(!$hasLabels&&count($parts)>1){
                $partIndex=0;
                foreach($parts as $i=>&$part)if($i>0&&!preg_match('/^\s*<(?:table|pre|img)\b/i',$part['html'])){
                    $n=$partIndex++;$label='';do{$label=chr(97+$n%26).$label;$n=intdiv($n,26)-1;}while($n>=0);
                    $part['label']='('.$label.')';
                }
                unset($part);$$key=$parts;
            }
        }
    }
    if(in_array($language,['en','ne'],true)){
        $parts=$language==='en'?$en:$ne;
        if(!$parts)return '<div class="exam-language-missing">'.($language==='en'?'English':'Nepali').' version not provided.</div>';
        $pairs=array_map(static fn($part)=>$language==='en'?[$part,null]:[null,$part],$parts);
    }else $pairs=examBilingualPairs($en,$ne);
    $out='<div class="exam-bilingual-content">';
    foreach($pairs as [$e,$n]){
        $label=$n['label']??$e['label']??'';if($label==='')$label=$e['label']??'';
        $out.='<div class="exam-bilingual-row">';
        $subquestion=$questionParts&&$label!=='';
        $out.='<div class="exam-language-pair'.($subquestion?' exam-subquestion-pair':'').'">';
        $languages=$language==='both_en'?['en'=>$e,'ne'=>$n]:['ne'=>$n,'en'=>$e];$first=true;
        foreach($languages as $key=>$part){if(!$part)continue;
            $body=$first?examQuestionPartLabelHtml($part['html'],$label,$subquestion):$part['html'];
            if(!$first&&!$subquestion)$body='('.$body.')';
            $out.=($first?'':' ').'<div class="exam-language-'.$key.($key==='ne'?' font-nepali':'').'" lang="'.$key.'">'.$body.'</div>';$first=false;
        }
        $out.='</div></div>';
    }
    return $out.'</div>';
}
function examQuestionRichVariants(string $english,string $nepali,string $language,bool $questionParts=false):string {
    $out='';foreach(examQuestionLanguages() as $key=>$label)$out.='<div data-exam-language-view="'.$key.'"'.($key===$language?'':' hidden').'>'.examQuestionRich($english,$nepali,$key,$questionParts).'</div>';
    return $out;
}
