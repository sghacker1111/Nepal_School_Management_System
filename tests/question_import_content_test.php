<?php
require __DIR__.'/../includes/question_import.php';
$checks=0;
function contentCheck(bool $condition,string $message):void {global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
function contentReject(callable $callback,string $message):void {try{$callback();}catch(InvalidArgumentException){contentCheck(true,$message);return;}contentCheck(false,$message);}
$normal=questionImportContent("नेपाल: x < 2 & y > 1\n<strong>literal</strong>",'normal');
contentCheck(str_contains($normal,'नेपाल')&&!str_contains($normal,'\\text{नेपाल}'),'Nepali prose remains prose');
contentCheck(str_contains($normal,'&lt;strong&gt;literal&lt;/strong&gt;')&&!str_contains($normal,'<strong>'),'Normal mode treats HTML literally');
contentCheck(str_contains($normal,'<br>')||str_contains($normal,'<br />'),'Normal mode preserves line breaks');
$source=questionImportContent('<p><strong>नेपाल</strong> \\(\\frac{1}{2}+\\frac{1}{2}=1\\)</p><script>alert(1)</script><img src="javascript:alert(2)" onerror="boom()">','html');
contentCheck(str_contains($source,'<strong>नेपाल</strong>')&&str_contains($source,'\\frac{1}{2}'),'HTML and LaTeX preserved');
contentCheck(!str_contains($source,'alert(')&&!str_contains($source,'onerror')&&!str_contains($source,'<script'),'Source mode is sanitized');
foreach(['\\(नेपाल + १\\)','\\[नेपाल + १\\]','$$नेपाल + १$$','$नेपाल + १$'] as $math){
    $converted=questionImportContent('साधारण '.$math,'html');
    contentCheck(str_contains($converted,'\\text{नेपाल}')&&str_contains($converted,'\\text{१}')&&!str_contains($converted,'\\text{साधारण}'),'Wrap Nepali only in delimited math');
    contentCheck(fixNepaliInLatex($converted)===$converted,'Repeated math handling is idempotent');
}
contentCheck(str_contains(questionImportContent('<span class="math-tex" data-tex="x^2" data-mode="block"><b>old</b> extra</span>','html'),'\\[x^2\\]'),'Editor math source uses portable TeX');
contentCheck(str_contains(questionImportContent('<script type="math/tex; mode=display">x^2</script>','html'),'\\[x^2\\]'),'Legacy MathJax source is text, not script');
$row=['question_text'=>'<p>Choose \\(2^2\\)</p>','question_text_np'=>'<p>उत्तर छान्नुहोस्।</p>','type'=>'MCQ','option_a'=>'<b>1</b>','option_b'=>'<b>4</b>','option_c'=>'<b>8</b>','option_d'=>'<b>16</b>','correct_option'=>'B','answer'=>'<ol><li>\\(2^2=4\\)</li></ol>'];
$get=static fn($key)=>(string)($row[$key]??'');$saved=questionImportRowContent($get,'html');
contentCheck($saved['type']==='MCQ'&&$saved['correct_option']==='B'&&json_decode($saved['options'],true)['B']==='<b>4</b>','MCQ source options preserved');
contentCheck(str_contains($saved['answer'],'<ol>')&&str_contains($saved['answer'],'\\(2^2=4\\)'),'Model answer source preserved');
$autoRow=[...$row,'type'=>''];contentCheck(questionImportRowContent(fn($key)=>(string)($autoRow[$key]??''),'html')['type']==='MCQ','English-only options auto-detect MCQ without a type column');
foreach(['','E','True'] as $bad){$badRow=[...$row,'correct_option'=>$bad];contentReject(fn()=>questionImportRowContent(fn($key)=>(string)($badRow[$key]??''),'html'),'Invalid answer keys rejected instead of guessed');}
$badRow=[...$row,'option_d'=>'<script>not an option</script>'];contentReject(fn()=>questionImportRowContent(fn($key)=>(string)($badRow[$key]??''),'html'),'Sanitized-empty option rejected');
contentReject(fn()=>questionImportFormat([]),'Array mode rejected');contentReject(fn()=>questionImportFormat('unknown'),'Unknown mode rejected');
foreach(['Very Short','Short','Long','Reasoning','Difference Between'] as $type){$row=['question_text'=>'Question','type'=>$type];contentCheck(questionImportRowContent(fn($key)=>(string)($row[$key]??''),'normal')['type']===$type,'Subjective type preserved');}
echo "Question import content checks passed: $checks\n";
