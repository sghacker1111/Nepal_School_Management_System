<?php
/** Portable structured questions live with their rich text, including exports and correction snapshots. */
require_once __DIR__.'/question_import.php';

const QUESTION_PART_LEVELS=['K'=>'Knowledge','U'=>'Understanding','A'=>'Application','HA'=>'Higher Ability','KU'=>'Knowledge and Understanding'];

function questionPartUnits(mixed $value):int {
    if(!is_scalar($value)||!preg_match('/^\d{1,4}(?:\.\d{1,2})?$/',(string)$value))throw new InvalidArgumentException('Each subquestion needs positive marks with at most two decimals.');
    $v=explode('.',(string)$value);$units=(int)$v[0]*100+(int)str_pad($v[1]??'',2,'0');
    if($units<1||$units>100000)throw new InvalidArgumentException('Subquestion marks must be between 0.01 and 1,000.');return $units;
}
function questionPartNumber(int $units):string {return rtrim(rtrim(number_format($units/100,2,'.',''),'0'),'.');}
function questionPartLabel(int $index):string {$label='';do{$label=chr(97+$index%26).$label;$index=intdiv($index,26)-1;}while($index>=0);return $label;}
function questionPartBody(mixed $raw):string {
    if(!is_string($raw)||strlen($raw)>60000)throw new InvalidArgumentException('A statement or subquestion is too long.');
    $html=questionImportContent($raw,'html');
    $doc=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="part-body">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);
    foreach((new DOMXPath($doc))->query('//*') as $node)foreach(iterator_to_array($node->attributes) as $attr)if(str_starts_with($attr->name,'data-question-')||str_starts_with($attr->name,'data-part-'))$node->removeAttribute($attr->name);
    $out='';foreach($doc->getElementById('part-body')->childNodes as $node)$out.=$doc->saveHTML($node);return $out;
}
function questionPartsNormalize(array $input,bool $mathematics=false,bool $allowImportedStructure=false):array {
    $mathRule=$input['math_rule']??'strict';$validRules=['strict','cdc'];if($allowImportedStructure)$validRules[]='import';if(!is_string($mathRule)||!in_array($mathRule,$validRules,true))throw new InvalidArgumentException('Choose a valid mathematics structure rule.');
    $display=$input['display']??'both';if(!is_string($display)||!in_array($display,['both','marks','cognitive'],true))throw new InvalidArgumentException('Choose marks and cognitive levels, marks only, or cognitive levels only.');
    $en=questionPartBody($input['statement_en']??'');$ne=questionPartBody($input['statement_np']??'');
    if(!questionImportHasContent($en)&&!questionImportHasContent($ne))throw new InvalidArgumentException('Enter the question statement in English or Nepali.');
    $raw=$input['parts']??null;if(!is_array($raw)||!array_is_list($raw)||count($raw)<1||count($raw)>40)throw new InvalidArgumentException('Add 1–40 subquestions.');
    $parts=[];$total=0;$labels=[];
    foreach($raw as $i=>$row){
        if(!is_array($row))throw new InvalidArgumentException('Enter valid subquestions.');
        $label=$row['label']??questionPartLabel($i);if(!is_string($label))throw new InvalidArgumentException('Enter a valid subquestion label.');$label=trim($label," ().\t\r\n");
        if(!preg_match('/^[\p{L}\p{N}]{1,8}$/u',$label)||isset($labels[mb_strtolower($label)]))throw new InvalidArgumentException('Use unique subquestion labels of 1–8 letters or digits.');$labels[mb_strtolower($label)]=true;
        $english=questionPartBody($row['en']??'');$nepali=questionPartBody($row['np']??'');
        if(!questionImportHasContent($english)&&!questionImportHasContent($nepali))throw new InvalidArgumentException('Subquestion ('.$label.') needs English or Nepali text.');
        $units=questionPartUnits($row['marks']??null);$total+=$units;
        $level=$row['level']??($row['levels'][0]??null);if(!is_string($level)||!isset(QUESTION_PART_LEVELS[$level])||(isset($row['levels'])&&(!is_array($row['levels'])||count($row['levels'])!==1)))throw new InvalidArgumentException('Choose one cognitive category: K, U, A, HA or KU. KU is independent of K and U.');
        $parts[]=['label'=>$label,'en'=>$english,'np'=>$nepali,'marks'=>questionPartNumber($units),'levels'=>[$level]];
    }
    if($total>100000)throw new InvalidArgumentException('The total question marks cannot exceed 1,000.');
    if($mathematics&&$mathRule==='strict'){
        if(count($parts)<2||!in_array('K',$parts[0]['levels'],true)||!in_array('HA',$parts[count($parts)-1]['levels'],true))throw new InvalidArgumentException('Mathematics needs at least two subquestions: the first must include K and the last must include HA.');
        if(count($parts)===3&&!array_intersect(['U','A','KU'],$parts[1]['levels']))throw new InvalidArgumentException('For three mathematics subquestions, the middle one must be U, A or KU.');
    }
    return ['display'=>$display,'statement_en'=>$en,'statement_np'=>$ne,'parts'=>$parts,'total_units'=>$total]+(in_array($mathRule,['cdc','import'],true)?['math_rule'=>$mathRule]:[]);
}
function questionPartsEncode(array $structure,string $language):string {
    $mathRule=$structure['math_rule']??'strict';$mathMarker=in_array($mathRule,['cdc','import'],true)?' data-question-math="'.$mathRule.'"':'';
    $out='<div data-question-structure="1" data-part-display="'.$structure['display'].'"'.$mathMarker.'><div data-question-role="statement">'.$structure[$language==='en'?'statement_en':'statement_np'].'</div>';
    foreach($structure['parts'] as $part){
        $out.='<div data-question-role="part" data-part-label="'.sanitize($part['label']).'" data-part-marks="'.$part['marks'].'" data-part-levels="'.implode(',',$part['levels']).'"'.'>'.$part[$language==='en'?'en':'np'].'</div>';
    }
    $out.='</div>';if(strlen($out)>60000)throw new InvalidArgumentException('The combined question text is too long.');return $out;
}
/** Parse only direct structural children; inner rich blocks remain a single subquestion. */
function questionPartsReadLanguage(string $html):?array {
    if(!str_contains($html,'data-question-structure'))return null;
    $doc=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="parts-root">'.safeRichHtml(questionImportMathSource($html)).'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);
    $root=$doc->getElementById('parts-root');$nodes=[];foreach($root->childNodes as $node){if($node instanceof DOMText&&trim($node->textContent)==='')continue;$nodes[]=$node;}
    if(count($nodes)!==1||!$nodes[0] instanceof DOMElement||$nodes[0]->getAttribute('data-question-structure')!=='1')throw new InvalidArgumentException('Invalid structured question. Use the subquestion editor.');
    $container=$nodes[0];$mathRule=$container->getAttribute('data-question-math')?:'strict';if(!in_array($mathRule,['strict','cdc','import'],true))throw new InvalidArgumentException('Invalid mathematics structure rule.');$out=['display'=>$container->getAttribute('data-part-display'),'math_rule'=>$mathRule,'statement'=>'','parts'=>[]];$statement=false;
    foreach($container->childNodes as $node){
        if($node instanceof DOMText&&trim($node->textContent)==='')continue;
        if(!$node instanceof DOMElement)throw new InvalidArgumentException('Invalid question part.');
        $body='';foreach($node->childNodes as $child)$body.=$doc->saveHTML($child);
        if($node->getAttribute('data-question-role')==='statement'&&!$statement&&!$out['parts']){$out['statement']=$body;$statement=true;continue;}
        if($node->getAttribute('data-question-role')!=='part'||!$statement)throw new InvalidArgumentException('Invalid subquestion order.');
        $out['parts'][]=['label'=>$node->getAttribute('data-part-label'),'marks'=>$node->getAttribute('data-part-marks'),'levels'=>explode(',',$node->getAttribute('data-part-levels')),'body'=>$body];
    }
    return $out;
}
function questionPartsRead(string $english,string $nepali,bool $mathematics=false):?array {
    $en=questionPartsReadLanguage($english);$ne=questionPartsReadLanguage($nepali);if(!$en&&!$ne)return null;
    if(!$en||!$ne||$en['display']!==$ne['display']||$en['math_rule']!==$ne['math_rule']||count($en['parts'])!==count($ne['parts']))throw new InvalidArgumentException('Both language structures must use matching subquestions.');
    $parts=[];foreach($en['parts'] as $i=>$part){$np=$ne['parts'][$i];foreach(['label','marks','levels'] as $key)if($part[$key]!==$np[$key])throw new InvalidArgumentException('Subquestion marks, labels and cognitive levels must match across languages.');$parts[]=$part+['en'=>$part['body'],'np'=>$np['body']];}
    return questionPartsNormalize(['display'=>$en['display'],'math_rule'=>$en['math_rule'],'statement_en'=>$en['statement'],'statement_np'=>$ne['statement'],'parts'=>$parts],$mathematics,true);
}
function questionPartsSuffix(array $part,string $display):string {return '('.($display==='cognitive'?'':$part['marks']).($display==='marks'?'':implode('',$part['levels'])).')';}
function questionCognitiveItems(array $question):array {
    $structure=questionPartsRead($question['question_text']??'',$question['question_text_np']??'');$counts=[];
    if(!$structure)return [(string)$question['cognitive_domain']=>1];
    foreach($structure['parts'] as $part){$category=QUESTION_PART_LEVELS[$part['levels'][0]];$counts[$category]=($counts[$category]??0)+1;}return $counts;
}
function questionCognitiveSummary(array $question):string {
    $structure=questionPartsRead($question['question_text']??'',$question['question_text_np']??'');
    if(!$structure)return (string)($question['cognitive_domain']??'');
    return implode(' / ',array_values(array_unique(array_merge(...array_column($structure['parts'],'levels')))));
}
/** Every subquestion contributes its marks once to its independent category. */
function questionCognitiveUnits(array $question):array {
    $structure=questionPartsRead($question['question_text']??'',$question['question_text_np']??'');
    if(!$structure)return [(string)$question['cognitive_domain']=>questionPartUnits((string)$question['marks'])];
    $totals=array_fill_keys(array_values(QUESTION_PART_LEVELS),0);
    foreach($structure['parts'] as $part)$totals[QUESTION_PART_LEVELS[$part['levels'][0]]]+=questionPartUnits($part['marks']);
    return $totals;
}
