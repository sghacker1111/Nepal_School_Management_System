<?php
require __DIR__.'/../includes/functions.php';
$html=safeRichHtml('<p>नेपाल <b>Math</b> \\(x^2\\)<script>alert(1)</script><img src="javascript:alert(1)" onerror="alert(2)"></p>');
if(!str_contains($html,'नेपाल')||!str_contains($html,'<b>Math</b>')||str_contains($html,'alert(')||str_contains($html,'onerror'))throw new RuntimeException('Rich content was lost or unsafe attributes survived');
require __DIR__.'/../includes/xlsx_helper.php';
$csvPath=tempnam(sys_get_temp_dir(),'erp-csv-');
$rows=[['Question'],['नेपाल "math" \\alpha'],['Line one' . "\n" . 'Line two']];
try{file_put_contents($csvPath,XlsxHelper::generateCsv($rows[0],array_slice($rows,1)));if(XlsxHelper::readCsv($csvPath)!==$rows)throw new RuntimeException('CSV round-trip lost bilingual text, quotes or newlines');}
finally{unlink($csvPath);}
echo "Rich lesson content checks passed\n";
