<?php
require_once __DIR__.'/report_issue.php';
/** Printing defaults are deliberately independent of the saved exam scheme. */
function progressPrintIssueDate(array $exam, array $input): array {
    return reportIssueDate($exam,array_replace(['display_calendar'=>'BS','date_format'=>'ymd','issue_calendar'=>'BS'],$input));
}
/** Legacy BS-only birth records obey the same calendar selection as issue dates. */
function progressPrintBirthDate(array $student, array $issue): string {
    $date=$student['dob_ad']??null;
    if(!$date&&!empty($student['dob_bs'])){
        try{$date=schoolDateToAd($student['dob_bs'],'BS');}
        catch(InvalidArgumentException $e){return 'Date unavailable';}
    }
    return schoolDateLabel($date,$issue['display'],$issue['format']);
}
