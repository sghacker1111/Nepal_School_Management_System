<?php
/** Synthetic fixtures only. No cleanup helpers belong in production classes. */
function erpTestFixture(): array {
    if(getenv('ERP_TEST_MYSQL')==='1'){
        putenv('ERP_RUNTIME=test');
        require_once __DIR__.'/../config/bootstrap.php';require_once __DIR__.'/../includes/database.php';
        require_once __DIR__.'/../includes/accounting_schema.php';
        $db=getDB();ob_start();require __DIR__.'/http_fixture.php';$output=ob_get_clean();
        $fixture=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        return ['db'=>$db,'fixture'=>$fixture,'directory'=>null];
    }
    $directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-test-'.bin2hex(random_bytes(12));
    if(!mkdir($directory,0700))throw new RuntimeException('Cannot create disposable fixture.');
    putenv('ERP_RUNTIME=test');putenv('ERP_TEST_SQLITE=1');putenv('ERP_DB_TYPE=sqlite');putenv('ERP_DB_PATH='.$directory.DIRECTORY_SEPARATOR.'test.sqlite');
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/http_fixture.php');
    exec($command,$output,$status);
    if($status!==0)throw new RuntimeException('Fixture setup failed.');
    $fixture=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
    require_once __DIR__.'/../config/bootstrap.php';require_once __DIR__.'/../includes/database.php';
    register_shutdown_function(function()use($directory){
        $parent=realpath(sys_get_temp_dir());$resolved=realpath($directory);
        if(!$resolved||dirname($resolved)!==$parent||!preg_match('/^erp-test-[a-f0-9]{24}$/',basename($resolved)))return;
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isLink())unlink($file->getPathname());elseif($file->isDir())rmdir($file->getPathname());else @unlink($file->getPathname());}
        @rmdir($resolved);
    });
    return ['db'=>getDB(),'fixture'=>$fixture,'directory'=>$directory];
}
function erpCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);$GLOBALS['erp_checks']=($GLOBALS['erp_checks']??0)+1;}
function erpEqual(mixed $actual,mixed $expected,string $label):void {erpCheck($actual===$expected,$label.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
function erpReject(callable $action,string $label):void {try{$action();}catch(InvalidArgumentException $e){erpCheck(true,$label);return;}throw new RuntimeException($label.' was accepted');}
