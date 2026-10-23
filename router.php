<?php
// Router for PHP's local server. The production rewrite uses the same registry.
$rawPath=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/';
$path=rawurldecode($rawPath);
if (str_contains($path,'..') || preg_match('~(?:^|/)(?:\.[^/]+|config|includes|scratch|tests|docs|backups|frontend|tools|node_modules|private-migration)(?:/|$)|^/package(?:-lock)?\.json$|(?:^|/)(?:migration|source|projected)-manifest[^/]*\.json$|(?:^|/)(?:migration-report[^/]*\.json|verification-error\.json|statements\.jsonl)$|\.(?:sqlite3?(?:-[a-z]+)?|db(?:-[a-z]+)?|sql(?:\.gz)?|log|env|bak|bat|md|py|ps1|sh|ini|toml|ya?ml)$|^/uploads/.*\.(?:php\d*|phtml|phar)$~i',$path)) {
    http_response_code(404);echo 'Not found';return true;
}
require_once __DIR__.'/includes/routes.php';
$docRoot=str_replace('\\','/',realpath($_SERVER['DOCUMENT_ROOT']??__DIR__)?:__DIR__);
$projectRoot=str_replace('\\','/',realpath(__DIR__));
$mount=str_starts_with($projectRoot,$docRoot.'/')?substr($projectRoot,strlen($docRoot)):'';
$localPath=$mount!==''&&str_starts_with($rawPath,$mount.'/')?substr($rawPath,strlen($mount)):$rawPath;
if($mount!==''&&$rawPath!==$mount&&!str_starts_with($rawPath,$mount.'/')){http_response_code(404);echo 'Not found';return true;}
if(preg_match('~\.php$~i',$localPath)){
    if(!schoolRouteForController(ltrim($localPath,'/'))){http_response_code(404);echo 'Not found';return true;}
    return false;
}
if(is_file(__DIR__.$localPath)&&!str_contains($localPath,'%'))return false;
require __DIR__.'/app.php';
return true;
