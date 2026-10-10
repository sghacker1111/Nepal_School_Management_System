<?php
/** Uses the same month table as the bundled MIT nepali-date-converter. No offset guesses. */
function schoolCalendar():array{static $data;return $data??=json_decode(file_get_contents(__DIR__.'/../config/data/bs_calendar.json'),true,512,JSON_THROW_ON_ERROR);}
function schoolDateDigits(string $date):string{return str_replace(['०','१','२','३','४','५','६','७','८','९'],range(0,9),trim($date));}
function schoolDateToAd($date,string $calendar='AD'):?string{
    if($date===null||$date==='')return null;
    if(!is_string($date)||!in_array($calendar,['AD','BS'],true))throw new InvalidArgumentException('Choose a valid AD or BS date.');
    $date=schoolDateDigits($date);
    if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m))throw new InvalidArgumentException('Enter the date as YYYY-MM-DD.');
    if($calendar==='AD'){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Enter a valid AD calendar date.');return $date;}
    [$year,$month,$day]=[(int)$m[1],(int)$m[2],(int)$m[3]];$data=schoolCalendar();
    if(!isset($data['years'][$year])||$month<1||$month>12||$day<1||$day>$data['years'][$year][$month-1])throw new InvalidArgumentException('Enter a valid BS date within 2000–2090 BS.');
    $days=$day-1;
    foreach($data['years'] as $y=>$months){if((int)$y<$year)$days+=array_sum($months);else break;}
    for($i=0;$i<$month-1;$i++)$days+=$data['years'][$year][$i];
    return (new DateTimeImmutable($data['anchor_ad'],new DateTimeZone('UTC')))->modify('+'.$days.' days')->format('Y-m-d');
}
function schoolAdToBs(?string $date):?string{
    if(!$date)return null;$date=schoolDateToAd($date);$data=schoolCalendar();
    $anchor=new DateTimeImmutable($data['anchor_ad'],new DateTimeZone('UTC'));
    $days=(int)$anchor->diff(new DateTimeImmutable($date,new DateTimeZone('UTC')))->format('%r%a');
    if($days<0)throw new InvalidArgumentException('This AD date is outside the supported BS calendar.');
    foreach($data['years'] as $year=>$months)foreach($months as $i=>$length){if($days<$length)return sprintf('%04d-%02d-%02d',$year,$i+1,$days+1);$days-=$length;}
    throw new InvalidArgumentException('This AD date is outside the supported BS calendar.');
}
function schoolDateLabel(?string $date,string $calendar='both',string $format='long'):string{
    if(!$date)return '—';if(!in_array($calendar,['AD','BS','both'],true)||!in_array($format,['ymd','dmy','long'],true))throw new InvalidArgumentException('Choose a valid date display format.');
    $ad=schoolDateToAd($date);$adLabel=(new DateTimeImmutable($ad))->format(['ymd'=>'Y-m-d','dmy'=>'d-m-Y','long'=>'d M Y'][$format]).' AD';
    if($calendar==='AD')return $adLabel;
    try{$bs=schoolAdToBs($ad);}catch(InvalidArgumentException $e){return $calendar==='both'?$adLabel.' (BS unavailable)':'BS unavailable';}
    [$y,$m,$d]=array_map('intval',explode('-',$bs));
    $months=['बैशाख','जेठ','असार','श्रावण','भदौ','असोज','कार्तिक','मंसिर','पौष','माघ','फाल्गुन','चैत्र'];
    $label=match($format){'dmy'=>sprintf('%02d-%02d-%04d',$d,$m,$y),'long'=>$d.' '.$months[$m-1].' '.$y,default=>$bs};
    return $calendar==='BS'?$label.' BS':$label.' BS ('.$adLabel.')';
}
function examDateInputs(array $input,array $exam=[]):array{
    $calendar=$input['exam_date_calendar']??'AD';if(!in_array($calendar,['AD','BS'],true))throw new InvalidArgumentException('Choose AD or BS for exam dates.');
    $dates=[];foreach(['start_date','end_date'] as $field){$key=$calendar==='BS'?$field.'_bs':$field;$dates[$field]=array_key_exists($key,$input)?schoolDateToAd($input[$key],$calendar):($exam[$field]??null);}
    if($dates['start_date']&&$dates['end_date']&&$dates['end_date']<$dates['start_date'])throw new InvalidArgumentException('The exam end date must not be before its start date.');
    return $dates;
}
/** Optional generic AD/BS controls for existing date fields; canonical storage remains AD. */
function schoolNormalizeDateFields(array &$input):void{
    $fields=$input['calendar_fields']??[];
    if(!is_array($fields))throw new InvalidArgumentException('Choose a valid date calendar.');
    $allowed=['date','date_ad','dob_ad','join_date','perm_issue_date','temp_issue_date','start_date','end_date','bill_date','due_date','payment_date','expense_date','effective_from','effective_to','from','to','subscription_start','subscription_end'];
    foreach($fields as $field=>$calendar){
        if(!in_array($field,$allowed,true)||!in_array($calendar,['AD','BS'],true))throw new InvalidArgumentException('Choose AD or BS for a supported date field.');
        if(array_key_exists($field,$input))$input[$field]=schoolDateToAd($input[$field],$calendar)??'';
    }
}
