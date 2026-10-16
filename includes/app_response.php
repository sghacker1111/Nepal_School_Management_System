<?php
/** Transport only. These helpers never authorize a route or write school data. */
require_once __DIR__.'/routes.php';

function schoolIsAppRequest(): bool {
    return ($_SERVER['HTTP_HX_REQUEST']??'')==='true'
        &&($_SERVER['HTTP_HX_TARGET']??'')==='main-content';
}

function schoolAppLocation(string $url): string {
    $canonical=schoolCanonicalUrl($url);
    if($canonical===null)throw new InvalidArgumentException('Choose a registered same-origin destination.');
    return $canonical;
}

function schoolPageConfig(string $pageId,array $config=[]): string {
    if(!preg_match('/^[a-z][a-z0-9-]{0,100}$/D',$pageId))throw new InvalidArgumentException('Invalid page identity.');
    $route=schoolRouteForController(schoolControllerPath());
    $session=$_SESSION??[];
    $context=[(int)($session['user_id']??0),(string)($session['role']??''),(int)($session['school_id']??0),(int)($session['viewing_school_id']??0)];
    $metadata=array_replace($config,[
        'routeId'=>$route['id']??'error','pageId'=>$pageId,
        'title'=>(string)($config['title']??(defined('APP_NAME')?APP_NAME:'School ERP')),
        'canonicalUrl'=>schoolCanonicalUrl($_SERVER['REQUEST_URI']??'')??schoolRouteUrl('index.php'),
        'activePage'=>(string)($config['activePage']??''),
        'contextVersion'=>hash('sha256',json_encode($context,JSON_THROW_ON_ERROR)),
        'csrfToken'=>(string)($session[defined('CSRF_TOKEN_NAME')?CSRF_TOKEN_NAME:'csrf_token']??''),
        'assets'=>array_values($config['assets']??[]),
    ]);
    $json=json_encode($metadata,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    return '<div hidden data-erp-config="'.htmlspecialchars($json,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"></div>';
}

function schoolAppHeaders(): void {
    header('Content-Type: text/html; charset=UTF-8');header('X-ERP-Fragment: app-page');
    header('Cache-Control: no-store, private');header('Vary: HX-Request, HX-Target, Accept');
}

function schoolAppRedirect(string $url): never {
    $canonical=schoolAppLocation($url);
    if(schoolIsAppRequest()){
        header('Cache-Control: no-store, private');header('Vary: HX-Request, HX-Target, Accept');
        header('HX-Location: '.json_encode(['path'=>$canonical,'target'=>'#main-content','swap'=>'innerHTML'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        http_response_code(204);
    }else header('Location: '.$canonical);
    exit;
}

function schoolAppUnauthenticated(): never {
    if(schoolIsAppRequest()){
        schoolAppHeaders();header('HX-Redirect: '.schoolRouteUrl('modules/auth/login.php'));
        http_response_code(401);echo '<p role="alert">Your session has ended. Please sign in again.</p>';exit;
    }
    header('Location: '.schoolRouteUrl('modules/auth/login.php'));exit;
}

function schoolAppError(string $message,int $status): never {
    schoolAppHeaders();http_response_code($status);
    echo schoolPageConfig('error',['title'=>'Request could not be completed','status'=>$status]);
    echo '<section class="alert alert-danger" role="alert"><h1>Request could not be completed</h1><p>'.htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p><a href="'.htmlspecialchars(schoolRouteUrl('index.php'),ENT_QUOTES,'UTF-8').'">Return to dashboard</a></section>';
    exit;
}
