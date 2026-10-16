<?php
define('APP_URL','https://school.example/erp');define('APP_NAME','School ERP');
require_once __DIR__.'/../includes/app_response.php';
function expectApp(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$_SERVER=['HTTP_HX_REQUEST'=>'true','HTTP_HX_TARGET'=>'other','SCRIPT_NAME'=>'/erp/modules/students/index.php','REQUEST_URI'=>'/erp/students?class_id=1'];
expectApp(!schoolIsAppRequest(),'An arbitrary HTMX target is not an app request');
$_SERVER['HTTP_HX_TARGET']='main-content';expectApp(schoolIsAppRequest(),'Exact app target is recognized');
$_SESSION=['user_id'=>1,'role'=>'school_admin','school_id'=>2,'csrf_token'=>'unit-token'];
$html=schoolPageConfig('students-index',['title'=>'"><script>alert(1)</script>','testValue'=>'<img src=x onerror=alert(1)>']);
expectApp(!str_contains($html,'<script>')&&!str_contains($html,'<img'),'Configuration cannot escape its attribute');
preg_match('/data-erp-config="([^"]*)"/',$html,$match);$data=json_decode(html_entity_decode($match[1],ENT_QUOTES,'UTF-8'),true,512,JSON_THROW_ON_ERROR);
expectApp($data['canonicalUrl']==='https://school.example/erp/students?class_id=1','Canonical metadata preserves filter');
expectApp($data['routeId']==='students-index'&&$data['pageId']==='students-index','Trusted route and page identity');
expectApp($data['csrfToken']==='unit-token','CSRF refresh is included');
expectApp($data['testValue']==='<img src=x onerror=alert(1)>','Inert page data round trips');
$version=$data['contextVersion'];$_SESSION['school_id']=3;
preg_match('/data-erp-config="([^"]*)"/',schoolPageConfig('students-index'),$match);$new=json_decode(html_entity_decode($match[1],ENT_QUOTES,'UTF-8'),true);
expectApp($version!==$new['contextVersion'],'School changes invalidate prior page context');
foreach(['https://evil.example/students','//evil.example/students',"/students\r\nX-Evil: yes"] as $bad){
    try{schoolAppLocation($bad);throw new RuntimeException('Unsafe redirect accepted');}catch(InvalidArgumentException $expected){}
}
expectApp(schoolAppLocation('/erp/modules/students/index.php')==='https://school.example/erp/students','Location resolves registered same-origin controller');
expectApp(schoolPageAssetIds(['/assets/css/attendance.css?v=3'])===['attendance-css'],'Styles use a finite public asset ID');
try{schoolPageAssetIds(['https://evil.test/assets/css/attendance.css']);throw new RuntimeException('External style accepted');}catch(InvalidArgumentException $expected){}
echo "App response unit passed: inert metadata, exact target, canonical redirects and context isolation.\n";
