<?php
// Catch missing/unsafe dispatch, alias collisions and permission identity confusion.
define('APP_URL','https://school.example.test/erp');
$routeFile=__DIR__.'/../includes/routes.php';
if(!is_file($routeFile))throw new RuntimeException('Route registry is missing.');
require_once $routeFile;
$checks=0;
function routeEqual(mixed $actual,mixed $expected,string $label):void{
    global $checks;$checks++;if($actual!==$expected)throw new RuntimeException($label.': '.var_export($actual,true).' != '.var_export($expected,true));
}
$routes=schoolRoutes();$controllers=array_column($routes,'controller');
$expected=['index.php'];foreach(glob(__DIR__.'/../modules/*/*.php') as $file)$expected[]='modules/'.basename(dirname($file)).'/'.basename($file);
sort($expected);$actual=$controllers;sort($actual);routeEqual($actual,$expected,'Every existing controller mapped exactly once');
routeEqual(count(array_unique(array_column($routes,'path'))),count($routes),'Canonical paths unique');
foreach($routes as $route){
    routeEqual(schoolResolveRoute($route['path'])['controller'],$route['controller'],'Canonical path dispatch');
    routeEqual(in_array($route['kind'],['page','mixed','json','print','auth'],true),true,'Explicit response kind');
    routeEqual($route['enhanced'],false,'Do not enable unfinished lifecycles');
}
foreach(['/' =>'index.php','/students'=>'modules/students/index.php','/results/marks-entry'=>'modules/results/marks_entry.php','/exam-questions'=>'modules/learning/exam_questions.php','/learning/question-bank'=>'modules/learning/questions.php','/login'=>'modules/auth/login.php'] as $path=>$controller)routeEqual(schoolResolveRoute($path)['controller'],$controller,'Public path '.$path);
routeEqual(schoolResolveRoute('/erp/students','/erp')['controller'],'modules/students/index.php','Subdirectory dispatch');
routeEqual(schoolResolveRoute('/students','/erp'),null,'Base path boundary');
foreach(['/../includes/auth.php','/%2e%2e/includes/auth.php','/%252e%252e/includes/auth.php','/students%2fedit','/students%5cedit','/students//edit','/students/./edit','https://evil.test/students','//evil.test/students','/missing','/includes/auth.php','/modules/missing/index.php',"/students\0"] as $bad)routeEqual(schoolResolveRoute($bad),null,'Reject '.$bad);
routeEqual(schoolRouteUrl('modules/students/index.php',['class'=>1,'ids'=>[2,3]]),'https://school.example.test/erp/students?class=1&ids%5B0%5D=2&ids%5B1%5D=3','Canonical query arrays');
routeEqual(schoolCanonicalUrl('https://school.example.test/erp/modules/exams/view.php?id=4#schedule'),'https://school.example.test/erp/exams/view?id=4#schedule','Preserve query/hash');
routeEqual(schoolCanonicalUrl('/erp/modules/learning/exam_mcqs.php?class_id=1'),'https://school.example.test/erp/exam-mcqs?class_id=1','Absolute local URL');
foreach(['https://evil.test/erp/students','https://school.example.test.evil/erp/students','javascript:alert(1)','data:text/html,a','//evil.test/students','/erp/includes/auth.php','/erp/../modules/students/index.php'] as $bad)routeEqual(schoolCanonicalUrl($bad),null,'Reject foreign/private canonical URL');
$_SERVER['SCRIPT_NAME']='/erp/modules/results/marks_entry.php';$_SERVER['HTTP_X_ERP_CONTROLLER']='modules/super_admin/users.php';$_GET['controller']='modules/super_admin/users.php';
routeEqual(schoolControllerPath(),'/modules/results/marks_entry.php','Browser cannot override guard identity');
routeEqual(schoolCanonicalUrl('index.php?exam=3'),'https://school.example.test/erp/results?exam=3','Relative legacy link uses target controller directory');
routeEqual(schoolCanonicalUrl('?exam=3'),'https://school.example.test/erp/results/marks-entry?exam=3','Current controller query link');
define('ERP_RESOLVED_CONTROLLER','modules/students/index.php');
routeEqual(schoolControllerPath(),'/modules/students/index.php','Server-resolved controller identity');
echo "Route registry passed: $checks checks.\n";
