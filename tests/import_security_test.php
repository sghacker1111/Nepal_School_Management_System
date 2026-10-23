<?php
require_once __DIR__.'/../includes/xlsx_helper.php';
require_once __DIR__.'/../includes/runtime_security.php';
$path=tempnam(sys_get_temp_dir(),'erp-import-security-');
$checks=0;
function rejectImport(callable $call):void {
    global $checks;
    try{$call();}catch(InvalidArgumentException $e){$checks++;return;}
    throw new RuntimeException('Unsafe import accepted.');
}
function workbook(string $path,string $sheet,?string $strings=null):void {
    $zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
    if($strings!==null)$zip->addFromString('xl/sharedStrings.xml',$strings);
    $zip->close();
}
try{
    $valid='<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>नेपाल \\(x^2\\)</t></is></c></row></sheetData></worksheet>';
    workbook($path,$valid);
    if(XlsxHelper::read($path)!==[['नेपाल \\(x^2\\)']])throw new RuntimeException('Valid bilingual/TeX XLSX changed.');
    $checks++;
    foreach(['1000000000','0','-1'] as $row){
        workbook($path,str_replace(['r="1"','r="A1"'],['r="'.$row.'"','r="A'.$row.'"'],$valid));
        rejectImport(fn()=>XlsxHelper::read($path,true));
    }
    workbook($path,str_replace('A1','XFD1',$valid));rejectImport(fn()=>XlsxHelper::read($path));
    workbook($path,str_replace('A1','A2',$valid));rejectImport(fn()=>XlsxHelper::read($path));
    workbook($path,'<!DOCTYPE worksheet [<!ENTITY x "entity">]>'.$valid);rejectImport(fn()=>XlsxHelper::read($path));
    workbook($path,$valid,'<!DOCTYPE sst [<!ENTITY x SYSTEM "file:///private">]><sst><si><t>&x;</t></si></sst>');
    rejectImport(fn()=>XlsxHelper::read($path));
    workbook($path,$valid,'<sst>'.str_repeat(' ',33554433).'</sst>');rejectImport(fn()=>XlsxHelper::read($path));
    file_put_contents($path,implode(',',array_fill(0,129,'column')));rejectImport(fn()=>XlsxHelper::readCsv($path));
    file_put_contents($path,str_repeat('x',100001));rejectImport(fn()=>XlsxHelper::readCsv($path));
    file_put_contents($path,str_repeat("row\n",10002));rejectImport(fn()=>XlsxHelper::readCsv($path));
    file_put_contents($path,str_repeat('x',5242881));clearstatcache(true,$path);rejectImport(fn()=>XlsxHelper::read($path));
    foreach(['8.2.34'=>false,'8.2.35'=>true,'8.3.35'=>false,'8.3.36'=>true,'8.4.26'=>false,'8.4.27'=>true,'8.5.1'=>false,'8.5.12'=>true,'8.1.99'=>false,'8.6.0RC1'=>false] as $version=>$expected){
        if(schoolRuntimeSecurity($version)['patched']!==$expected)throw new RuntimeException('Wrong security baseline: '.$version);
        $checks++;
    }
}finally{unlink($path);}
echo "Import/runtime security passed: $checks checks.\n";
