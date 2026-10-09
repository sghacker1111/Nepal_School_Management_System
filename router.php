<?php
// Router for PHP's local server. Use Apache .htaccess in production.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/');
if (str_contains($path,'..') || preg_match('~(?:^|/)(?:\.[^/]+|config|includes|scratch|tests|docs)(?:/|$)|\.(?:sqlite(?:-wal|-shm)?|db|sql|log|env|bak|bat|md)$|^/uploads/.*\.(?:php\d*|phtml|phar)$~i',$path)) {
    http_response_code(404);echo 'Not found';return true;
}
return false;
