<?php
// Mechanical documentation derived from the same finite route registry.
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/routes.php';
$lines=['# Single-page route coverage','','All 78 existing controllers are explicitly registered. Enhanced routes have bundled scoped adapters and automated coverage. Interactive browser validation remains a release gate, deferred until all code changes are complete at the user request.','','| Canonical path | Controller | Kind | Lifecycle / verification |','| --- | --- | --- | --- |'];
foreach(schoolRoutes() as $route){
    $status=$route['enhanced']?'Scoped adapter; automated HTTP/DOM coverage; browser pending':(in_array($route['kind'],['auth','json','print'],true)?'Native boundary/document; not boosted':'Pending adapter and HTTP verification');
    $lines[]='| `'.$route['path'].'` | `'.$route['controller'].'` | '.$route['kind'].' | '.$status.' |';
}
file_put_contents(__DIR__.'/../docs/single-page-route-coverage.md',implode("\n",$lines)."\n");
