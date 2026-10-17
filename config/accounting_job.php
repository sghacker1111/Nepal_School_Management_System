<?php
/** Run from cron / Task Scheduler; never callable over HTTP. No fake financial seed data. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/../includes/database.php';require_once __DIR__.'/../includes/accounting_service.php';
$options=getopt('',['school:','user:','year:','month:','calendar:','subscriptions:','preview']);
try{
    $service=new SchoolAccounting(getDB(),(int)($options['school']??0),(int)($options['user']??0));
    if(isset($options['subscriptions'])){if(isset($options['preview']))throw new InvalidArgumentException('Subscription preview is available in the subscription list. Omit --preview to explicitly post due cycles.');$result=$service->generateSubscriptions($options['subscriptions']);}
    else{$input=['academic_year_id'=>(int)($options['year']??0),'billing_period'=>$options['month']??'','billing_calendar'=>$options['calendar']??'BS','confirm'=>1];$result=isset($options['preview'])?$service->recurringPreview($input):$service->generateRecurring($input);}
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;exit(!empty($result['errors'])?1:0);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
