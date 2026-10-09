<?php
require __DIR__.'/../includes/functions.php';
$html=safeRichHtml('<p>नेपाल <b>Math</b> \\(x^2\\)<script>alert(1)</script><img src="javascript:alert(1)" onerror="alert(2)"></p>');
if(!str_contains($html,'नेपाल')||!str_contains($html,'<b>Math</b>')||str_contains($html,'alert(')||str_contains($html,'onerror'))throw new RuntimeException('Rich content was lost or unsafe attributes survived');
echo "Rich lesson content checks passed\n";
