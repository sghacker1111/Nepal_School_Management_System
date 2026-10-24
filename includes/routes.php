<?php
/** Finite public route map. Client input can select a route, never a PHP file. */
function schoolRoutes(): array {
    static $routes=null;
    if($routes!==null)return $routes;
    $groups=[
        'accounting'=>['create_bill','credit','expenses','fees','generate','index','invoice','invoices','journals','payments','receipt','reports','search','settings','student','subscriptions'],
        'attendance'=>['index'],
        'auth'=>['change_password','login','logout','profile','verify_password'],
        'classes'=>['add','edit','index','routine','subjects'],
        'exams'=>['add','admit_cards','edit','index','symbols','view'],
        'learning'=>['curriculum','exam_mcqs','exam_paper_generator','exam_questions','exam_specification_grids','index','ocr_scanner','paper_generator','practice_mcqs','question_corrections','question_grid','questions','specification_grids'],
        'reports'=>['index'],
        'results'=>['index','ledger','marks_entry','print_bulk','report_card'],
        'settings'=>['cdc','school','users','years'],
        'students'=>['add','admit_card','edit','import_export','index','view'],
        'subjects'=>['add','edit','index','internal'],
        'super_admin'=>['exam_templates','index','offerings','question_corrections','schools','subjects','switch_school','users'],
        'teachers'=>['add','edit','import_export','index'],
    ];
    $overrides=[
        'auth/login'=>'/login','auth/logout'=>'/logout',
        'learning/questions'=>'/learning/question-bank','learning/exam_questions'=>'/exam-questions',
        'learning/exam_mcqs'=>'/exam-mcqs','learning/exam_paper_generator'=>'/exam-paper-generator',
        'learning/exam_specification_grids'=>'/exam-paper-grids',
    ];
    $kinds=[
        'auth/login'=>'auth','auth/logout'=>'auth','auth/verify_password'=>'json',
        'super_admin/switch_school'=>'auth','accounting/search'=>'json',
        'students/admit_card'=>'print','results/report_card'=>'print',
    ];
    $mixed=['students/import_export','teachers/import_export','exams/admit_cards','results/print_bulk',
        'settings/users','learning/curriculum','learning/questions','learning/exam_questions','learning/exam_mcqs',
        'learning/ocr_scanner','learning/practice_mcqs','learning/index','learning/paper_generator',
        'learning/exam_paper_generator','learning/exam_specification_grids','learning/specification_grids',
        'super_admin/subjects','super_admin/exam_templates','accounting/invoice','accounting/receipt','accounting/credit'];
    $routes=[['id'=>'dashboard','path'=>'/','controller'=>'index.php','kind'=>'page','pageId'=>'dashboard','enhanced'=>true]];
    foreach($groups as $group=>$files){
        $prefix=match($group){'auth'=>'account','super_admin'=>'admin',default=>$group};
        foreach($files as $file){
            $key=$group.'/'.$file;$id=str_replace('_','-',$group.'-'.$file);
            $path=$overrides[$key]??('/'.$prefix.($file==='index'?'':'/'.str_replace('_','-',$file)));
            $routes[]=['id'=>$id,'path'=>$path,'controller'=>'modules/'.$key.'.php','kind'=>$kinds[$key]??(in_array($key,$mixed,true)?'mixed':'page'),'pageId'=>$id,'enhanced'=>schoolPageAdapterReady($id)];
        }
    }
    return $routes;
}

function schoolPageAdapterReady(string $id):bool {
    if(in_array($id,['error','confirm-action','dashboard','auth-profile','auth-change-password'],true))return true;
    if(in_array($id,['attendance-index','results-index','results-ledger','results-marks-entry','results-print-bulk','reports-index'],true))return true;
    if(str_starts_with($id,'learning-')||$id==='super-admin-question-corrections')return true;
    if(str_starts_with($id,'accounting-')&&$id!=='accounting-search')return true;
    if(in_array($id,['students-admit-card','exams-admit-cards','super-admin-switch-school','super-admin-question-corrections'],true))return false;
    return (bool)preg_match('/^(students|teachers|classes|subjects|exams|settings|super-admin)-/',$id);
}

function schoolRouteBasePath(): string {
    return rtrim((string)(parse_url(defined('APP_URL')?APP_URL:'http://localhost',PHP_URL_PATH)??''),'/');
}

function schoolSafeRoutePath(string $path): bool {
    // Public paths are an ASCII allowlist. Encoded separators/dot segments and
    // repeated decoding are unnecessary and dangerous for this registry.
    return $path!==''&&$path[0]==='/'&&!str_starts_with($path,'//')
        &&!preg_match('~[\\\\%\x00-\x20\x7f]|//|(?:^|/)\.{1,2}(?:/|$)~',$path);
}

function schoolResolveRoute(string $path,string $basePath=''): ?array {
    if(!schoolSafeRoutePath($path))return null;
    $path=parse_url($path,PHP_URL_PATH);if(!is_string($path))return null;
    $basePath=rtrim($basePath,'/');
    if($basePath!==''){
        if($path===$basePath)$path='/';
        elseif(str_starts_with($path,$basePath.'/'))$path=substr($path,strlen($basePath));
        else return null;
    }
    $path=$path==='/'?'/':rtrim($path,'/');
    foreach(schoolRoutes() as $route)if($route['path']===$path)return $route;
    return null;
}

function schoolRouteForController(string $controller): ?array {
    $controller=ltrim($controller,'/');
    foreach(schoolRoutes() as $route)if($route['controller']===$controller)return $route;
    return null;
}

function schoolRouteUrl(string $controller,array $query=[]): string {
    $route=schoolRouteForController($controller);
    if(!$route)throw new InvalidArgumentException('Unknown application route.');
    $base=defined('APP_URL')?rtrim(APP_URL,'/'):'http://localhost';
    return $base.($route['path']==='/'?'/':$route['path']).($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
}

function schoolControllerPath(): string {
    if(defined('ERP_RESOLVED_CONTROLLER'))return schoolRouteForController(ERP_RESOLVED_CONTROLLER)?'/'.ERP_RESOLVED_CONTROLLER:'';
    $script=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'');$base=schoolRouteBasePath();
    if($base!==''&&str_starts_with($script,$base.'/'))$script=substr($script,strlen($base));
    $route=schoolRouteForController($script);
    return $route?'/'.$route['controller']:'';
}

/** Convert only registered same-origin page/controller URLs; leave other assets alone. */
function schoolCanonicalUrl(string $url): ?string {
    if($url===''||$url[0]==='#'||preg_match('/[\x00-\x20\x7f\\\\]/',$url)||str_starts_with($url,'//'))return null;
    $parts=parse_url($url);if($parts===false)return null;
    $app=parse_url(defined('APP_URL')?APP_URL:'http://localhost');
    if(isset($parts['user'])||isset($parts['pass']))return null;
    if(isset($parts['scheme'])||isset($parts['host'])){
        if(strtolower($parts['scheme']??'')!==strtolower($app['scheme']??'')
            ||strtolower($parts['host']??'')!==strtolower($app['host']??'')
            ||($parts['port']??null)!==($app['port']??null))return null;
    }
    $path=$parts['path']??'';$base=schoolRouteBasePath();
    if($path==='')$controller=ltrim(schoolControllerPath(),'/');
    elseif($path[0]!=='/'){
        $controller=str_starts_with($path,'modules/')||$path==='index.php'&&schoolControllerPath()==='/index.php'
            ?$path:trim(dirname(ltrim(schoolControllerPath(),'/')),'/.').'/'.$path;
        $controller=ltrim($controller,'/');
    }else{
        if(!schoolSafeRoutePath($path))return null;
        $route=schoolResolveRoute($path,$base);
        if($route)$controller=$route['controller'];
        else{
            if($base!==''&&!str_starts_with($path,$base.'/'))return null;
            $controller=ltrim(substr($path,strlen($base)),'/');
        }
    }
    if(!schoolRouteForController($controller))return null;
    $canonical=schoolRouteUrl($controller);
    return $canonical.(isset($parts['query'])?'?'.$parts['query']:'').(isset($parts['fragment'])?'#'.$parts['fragment']:'');
}

/** Canonicalize safe document navigation only, after authentication/guards. */
function schoolRedirectLegacyDocument(): void {
    if(defined('ERP_RESOLVED_CONTROLLER')||($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
        ||($_SERVER['HTTP_HX_REQUEST']??'')==='true'
        ||!str_contains($_SERVER['HTTP_ACCEPT']??'','text/html')
        ||str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')
        ||strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest')return;
    $route=schoolRouteForController(schoolControllerPath());
    // Mixed modes may return downloads or JSON; page links are already clean.
    if(!$route||$route['kind']!=='page'||!empty($_GET['action'])||schoolMutationQuery($_GET))return;
    $request=$_SERVER['REQUEST_URI']??'';
    if(!str_contains(parse_url($request,PHP_URL_PATH)??'','.php'))return;
    $canonical=schoolCanonicalUrl($request);
    if($canonical){header('Location: '.$canonical,true,302);exit;}
}
