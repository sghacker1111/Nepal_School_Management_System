<?php
/** Clean public routes dispatch only to an explicitly registered controller. */
require_once __DIR__.'/config/bootstrap.php';
require_once __DIR__.'/includes/routes.php';
$requestPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$resolved=is_string($requestPath)?schoolResolveRoute($requestPath,schoolRouteBasePath()):null;
if(!$resolved){http_response_code(404);header('Content-Type: text/html; charset=UTF-8');echo '<!doctype html><title>Not found</title><h1>Page not found</h1>';exit;}
define('ERP_RESOLVED_CONTROLLER',$resolved['controller']);
$_SERVER['SCRIPT_FILENAME']=__DIR__.'/'.$resolved['controller'];
require __DIR__.'/'.$resolved['controller'];
