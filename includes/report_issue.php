<?php
/** Issue dates are AD dates; automatic mode always uses Nepal's calendar day. */
function reportIssueDate(array $exam, array $input = []): array {
    $mode=$input['issue_date_mode']??$exam['issue_date_mode']??'auto';
    if(!is_string($mode)||!in_array($mode,['auto','manual'],true))throw new InvalidArgumentException('Choose automatic or manual issue date.');
    $today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d');
    $manual=$input['issue_date']??$exam['issue_date']??'';
    if(!is_string($manual))throw new InvalidArgumentException('Enter a valid issue date (AD).');
    if($mode==='manual') {
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$manual,new DateTimeZone('Asia/Kathmandu'));
        if(!$parsed||$parsed->format('Y-m-d')!==$manual)throw new InvalidArgumentException('Enter a valid manual issue date (AD).');
    }
    $date=$mode==='auto'?$today:$manual;
    return ['mode'=>$mode,'date'=>$date,'manual'=>$mode==='manual'?$manual:'','today'=>$today,'label'=>DateTimeImmutable::createFromFormat('!Y-m-d',$date)->format('d M Y').' AD'];
}
