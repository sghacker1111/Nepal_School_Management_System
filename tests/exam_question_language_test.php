<?php
require_once __DIR__.'/../includes/exam_question_language.php';
$checks=0;function langCheck(bool $value,string $message):void {global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
$en='<p><b>Consider a set \\(A\\).</b></p><p>(a) Find \\(n(A)\\).</p><p>(b) Explain the result.</p>';
$ne='<p><b>समुच्चय \\(A\\) लाई विचार गर्नुहोस्।</b></p><p>(a) \\(n(A)\\) पत्ता लगाउनुहोस्।</p><p>(b) नतिजा व्याख्या गर्नुहोस्।</p>';
$html=examQuestionRich($en,$ne);
langCheck(substr_count($html,'class="exam-bilingual-row"')===3,'Each statement/subpart has one row');
langCheck(strpos($html,'समुच्चय')<strpos($html,'Consider'),'Nepali precedes English');
langCheck(strpos($html,'Consider')<strpos($html,'पत्ता'),'Statement pair precedes part (a)');
langCheck(substr_count($html,'>(a)</span>')===1&&substr_count($html,'>(b)</span>')===1,'Subpart labels are not doubled');
langCheck(str_contains($html,'lang="en">(<b>Consider'),'English translation is in parentheses, preserving bold');
langCheck(substr_count($html,'\\(n(A)\\)')===2,'Both equations remain portable TeX');
$english=examQuestionRich($en,$ne,'en');$nepali=examQuestionRich($en,$ne,'ne');
langCheck(str_contains($english,'Consider')&&!str_contains($english,'समुच्चय'),'English only has no Nepali question');
langCheck(str_contains($nepali,'समुच्चय')&&!str_contains($nepali,'Consider'),'Nepali only has no English question');
langCheck(!str_contains($english,'lang="en">('),'Single language has no translation parentheses');
langCheck(count(examRichParagraphs('Statement<br>(a) First<br />(b) Second'))===3,'Normal import line breaks split paragraphs');
langCheck(count(examRichParagraphs('<div><p>Statement</p><p>(a) First</p></div>'))===2,'Nested wrappers do not create extra paragraphs');
$pieces=examRichParagraphs('<p><b>First<br>Second</b></p>');
langCheck($pieces[0]['html']==='<b>First</b>'&&$pieces[1]['html']==='<b>Second</b>','Inline formatting survives split line breaks');
$pieces=examRichParagraphs('<p>Statement</p><ol><li>First</li><li>Second</li></ol>');
langCheck(count($pieces)===3&&$pieces[1]['label']==='(1)'&&$pieces[2]['label']==='(2)','Ordered lists remain separately paired subparts');
$table='<table><tr><td>Value</td><td>2</td></tr></table>';
langCheck(str_contains(examQuestionRich($table,''),$table),'Tables are not split into cells');
langCheck(str_contains(examQuestionRich('<img src="/uploads/test.png" alt="Diagram">',''),'alt="Diagram"'),'Image-only paragraphs survive');
$missing=examQuestionRich($en,'<p>समुच्चय</p><p>(b) नतिजा</p>');
langCheck(substr_count($missing,'>(a)</span>')===1&&substr_count($missing,'>(b)</span>')===1,'Missing translations do not join (a) with (b)');
langCheck(str_contains(examQuestionRich($en,'','ne'),'Nepali version not provided.'),'Missing language is explicit, with no fallback into the other language');
langCheck(!str_contains(examQuestionRich('<script>alert(1)</script><p onclick="x()">Safe</p>',''),'script')&&!str_contains(examQuestionRich('<img src="javascript:alert(1)">',''),'javascript:'),'Stored active HTML is sanitized');
$legacy='<span class="math-tex" data-tex="\\frac{1}{2}" data-mode="block">old</span>';
langCheck(str_contains(examQuestionRich($legacy,''),'\\[\\frac{1}{2}\\]'),'Imported source widgets retain their TeX');
$variants=examQuestionRichVariants($en,$ne,'ne');
langCheck(str_contains($variants,'data-exam-language-view="ne">')&&str_contains($variants,'data-exam-language-view="both" hidden'),'Print alternatives preserve exact selected questions');
$auto=examQuestionRich('<p>Statement</p><p>First part</p><p>Second part</p>','<p>कथन</p><p>पहिलो भाग</p><p>दोस्रो भाग</p>','both',true);
langCheck(substr_count($auto,'>(a)</span>')===1&&substr_count($auto,'>(b)</span>')===1,'Unlabeled question paragraphs gain shared subpart labels');
langCheck(!str_contains(examQuestionRich('<p>Answer</p><p>Explanation</p>','','en'),'exam-part-label'),'Ordinary answer/option paragraphs are not automatically labeled');
langCheck(substr_count(examQuestionRich('<p>(A) First</p>','<p>(a) पहिलो</p>'),'class="exam-bilingual-row"')===1,'Part labels are case-insensitive');
langCheck(substr_count(examQuestionRich('<p>(1) First</p>','<p>(१) पहिलो</p>'),'class="exam-bilingual-row"')===1,'Equivalent ASCII/Devanagari numeral labels pair correctly');
foreach(['0.5'=>'०.५','1.5'=>'१.५','100.25'=>'१००.२५'] as $decimal=>$decimalNp){
    foreach(['both','en','ne'] as $language){
        $numeric=examQuestionRich('<p>'.$decimal.'</p>','<p>'.$decimalNp.'</p>',$language);
        langCheck(!str_contains($numeric,'exam-part-label')&&($language==='ne'||str_contains($numeric,$decimal))&&($language==='en'||str_contains($numeric,$decimalNp)),'Decimal option values must remain complete in every language');
    }
}
foreach(['xx',[],0] as $bad){try{examQuestionLanguage($bad);throw new RuntimeException('Invalid language accepted');}catch(InvalidArgumentException){$checks++;}}
echo "Exam question language passed: $checks assertions; paired paragraphs, language isolation, TeX, rich formatting, missing translations and safe print variants.\n";
