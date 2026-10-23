<?php
require_once __DIR__.'/app_response.php';
function schoolRequestIsHttps():bool {
    return (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')
        ||(getenv('ERP_TRUST_PROXY')==='1'&&($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
}
function schoolPublicError(Throwable $error):string {
    error_log(get_class($error).': '.$error->getMessage());
    return $error instanceof InvalidArgumentException?$error->getMessage():'The operation could not be completed. Please try again or contact an administrator.';
}
if(PHP_SAPI!=='cli'){
    $allowedHosts=array_filter(array_map('trim',explode(',',getenv('ERP_ALLOWED_HOSTS')?:'')));
    if($allowedHosts&&!in_array(strtolower($_SERVER['HTTP_HOST']??''),array_map('strtolower',$allowedHosts),true)){http_response_code(400);exit('Invalid host.');}
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
    header("Content-Security-Policy: base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store, private');
    set_exception_handler(static function(Throwable $error):void {
        error_log(get_class($error).': '.$error->getMessage());
        if($error instanceof SchoolDatabaseException){
            if(schoolIsAppRequest())schoolAppError($error->getMessage(),503);
            http_response_code(503);header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><title>MySQL unavailable</title><main><h1>MySQL unavailable</h1><p>'.htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8').'</p></main>';return;
        }
        if(schoolIsAppRequest())schoolAppError('The operation could not be completed. Please try again or contact an administrator.',500);
        http_response_code(500);header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><title>Request could not be completed</title><main><h1>Request could not be completed</h1><p>No technical details are exposed. Contact an administrator if this continues.</p></main>';
    });
}
