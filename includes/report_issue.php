<?php
require_once __DIR__.'/nepali_dates.php';
/** Issue dates are AD dates; automatic mode always uses Nepal's calendar day. */
function reportIssueDate(array $exam, array $input = []): array {
    $mode=$input['issue_date_mode']??$exam['issue_date_mode']??'auto';
    if(!is_string($mode)||!in_array($mode,['auto','manual'],true))throw new InvalidArgumentException('Choose automatic or manual issue date.');
    $today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d');
    $calendar=$input['issue_calendar']??'AD';$display=$input['display_calendar']??$exam['date_calendar']??'both';$format=$input['date_format']??$exam['date_format']??'long';
    if(!in_array($calendar,['AD','BS'],true)||!in_array($display,['AD','BS','both'],true)||!in_array($format,['ymd','dmy','long'],true))throw new InvalidArgumentException('Choose a valid calendar and date format.');
    $manual=$input['issue_date']??$exam['issue_date']??'';
    if(!array_key_exists('issue_date',$input)&&$manual&&$calendar==='BS')$manual=schoolAdToBs($manual);
    if(!is_string($manual))throw new InvalidArgumentException('Enter a valid issue date (AD).');
    if($mode==='manual') {
        if($manual==='')throw new InvalidArgumentException('Enter a valid manual issue date.');
        $manual=schoolDateToAd($manual,$calendar);
    }
    $date=$mode==='auto'?$today:$manual;
    return ['mode'=>$mode,'date'=>$date,'manual'=>$mode==='manual'?$manual:'','input_date'=>$calendar==='BS'?schoolAdToBs($date):$date,'calendar'=>$calendar,'display'=>$display,'format'=>$format,'today'=>$today,'label'=>schoolDateLabel($date,$display,$format)];
}
