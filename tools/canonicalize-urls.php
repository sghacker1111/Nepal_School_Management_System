<?php
// One-time mechanical source migration. No database, configuration or upload IO.
require_once __DIR__.'/../includes/routes.php';
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);$files=[$root.'/index.php'];
foreach(['includes','modules'] as $dir)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()&&$file->getExtension()==='php'&&$file->getFilename()!=='routes.php')$files[]=$file->getPathname();
foreach($files as $file){
    $before=file_get_contents($file);$after=$before;
    foreach(schoolRoutes() as $route){
        $old='/'.$route['controller'];$controller=var_export($route['controller'],true);
        $after=preg_replace('~<\?=\s*APP_URL\s*\?>'.preg_quote($old,'~').'~','<?= schoolRouteUrl('.$controller.') ?>',$after);
        $after=preg_replace_callback('~APP_URL\s*\.\s*([\x27\x22])'.preg_quote($old,'~').'([^\x27\x22]*)\1~',static function($m)use($controller){return 'schoolRouteUrl('.$controller.')'.($m[2]!==''?' . '.$m[1].$m[2].$m[1]:'');},$after);
    }
    // The shared bank uses a registered controller selected by the current view.
    $after=str_replace('<?= APP_URL ?>/modules/learning/<?= $route ?>',"<?= schoolRouteUrl('modules/learning/'.\$route) ?>",$after);
    if($after!==$before){file_put_contents($file,$after);echo substr($file,strlen($root)+1)."\n";}
}
foreach(glob($root.'/assets/js/*.js') as $file){
    $before=file_get_contents($file);$after=$before;
    foreach(schoolRoutes() as $route)$after=str_replace('/'.$route['controller'],$route['path'],$after);
    if($after!==$before){file_put_contents($file,$after);echo substr($file,strlen($root)+1)."\n";}
}
