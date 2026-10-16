<?php
/** Presentation only: never rewrite imported bilingual content or question IDs. */
require_once __DIR__.'/question_import.php';

function examQuestionLanguage(mixed $value='both'):string {
    if(!is_string($value)||!in_array($value,['both','en','ne'],true))throw new InvalidArgumentException('Choose English, Nepali or both question languages.');
    return $value;
}
function examQuestionLanguages():array {return ['both'=>'Nepali + English','en'=>'English only','ne'=>'Nepali only'];}

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
    examQuestionLanguage($language);$en=examRichParagraphs($english);$ne=examRichParagraphs($nepali);
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
    if($language!=='both'){
        $parts=$language==='en'?$en:$ne;
        if(!$parts)return '<div class="exam-language-missing">'.($language==='en'?'English':'Nepali').' version not provided.</div>';
        $pairs=array_map(static fn($part)=>$language==='en'?[$part,null]:[null,$part],$parts);
    }else $pairs=examBilingualPairs($en,$ne);
    $out='<div class="exam-bilingual-content">';
    foreach($pairs as [$e,$n]){
        $label=$n['label']??$e['label']??'';if($label==='')$label=$e['label']??'';
        $out.='<div class="exam-bilingual-row">';
        if($label!=='')$out.='<span class="exam-part-label">'.sanitize($label).'</span> ';
        $out.='<div class="exam-language-pair">';
        if($n)$out.='<div class="exam-language-ne font-nepali" lang="ne">'.$n['html'].'</div>';
        if($e)$out.=($n?' ':'').'<div class="exam-language-en" lang="en">'.($n?'(':'').$e['html'].($n?')':'').'</div>';
        $out.='</div></div>';
    }
    return $out.'</div>';
}
function examQuestionRichVariants(string $english,string $nepali,string $language,bool $questionParts=false):string {
    $out='';foreach(examQuestionLanguages() as $key=>$label)$out.='<div data-exam-language-view="'.$key.'"'.($key===$language?'':' hidden').'>'.examQuestionRich($english,$nepali,$key,$questionParts).'</div>';
    return $out;
}
