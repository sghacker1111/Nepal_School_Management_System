<?php
/** Exact bounded integer paisa. No database or authentication dependency. */
const AC_MAX_MINOR = 1000000000000;
if(PHP_INT_SIZE<8)throw new RuntimeException('Accounting requires a 64-bit PHP runtime.');
function acCheckedAdd(int $a,int $b):int {
    if($a>AC_MAX_MINOR||$a < -AC_MAX_MINOR||$b>AC_MAX_MINOR||$b < -AC_MAX_MINOR)throw new InvalidArgumentException('Amount exceeds the supported limit.');
    $sum=$a+$b;
    if($sum>AC_MAX_MINOR||$sum < -AC_MAX_MINOR)throw new InvalidArgumentException('Total exceeds the supported limit.');
    return $sum;
}
function acDecimalUnits($value,int $scale=2):int {
    if($scale<0||$scale>3||(!is_string($value)&&!is_int($value)))throw new InvalidArgumentException('Enter amounts as decimal numbers, not scientific notation.');
    $value=trim((string)$value);
    if(strlen($value)>80||!preg_match($scale?'/^\\d+(?:\\.\\d{1,'.$scale.'})?$/':'/^\\d+$/',$value))throw new InvalidArgumentException('Enter a non-negative number with at most '.$scale.' decimal places.');
    $parts=explode('.',$value);
    $digits=ltrim($parts[0].str_pad($parts[1]??'',$scale,'0'),'0');$digits=$digits===''?'0':$digits;$max=(string)AC_MAX_MINOR;
    if(strlen($digits)>strlen($max)||(strlen($digits)===strlen($max)&&strcmp($digits,$max)>0))throw new InvalidArgumentException('Amount exceeds the supported limit.');
    return (int)$digits;
}
/** Binary quotient/remainder multiplication: a*b is never evaluated. */
function acMulDiv(int $a,int $b,int $denominator):int {
    if($a<0||$b<0||$denominator<=0||$a>AC_MAX_MINOR||$b>AC_MAX_MINOR||$denominator>AC_MAX_MINOR)throw new InvalidArgumentException('Invalid monetary calculation or unsupported amount.');
    $quotient=0;$remainder=0;$termQuotient=intdiv($a,$denominator);$termRemainder=$a%$denominator;
    while($b>0){
        if($b%2){$quotient=acCheckedAdd($quotient,$termQuotient);$remainder+=$termRemainder;if($remainder>=$denominator){$quotient=acCheckedAdd($quotient,1);$remainder-=$denominator;}}
        $b=intdiv($b,2);
        if($b>0){$termQuotient=acCheckedAdd($termQuotient,$termQuotient);$termRemainder+=$termRemainder;if($termRemainder>=$denominator){$termQuotient=acCheckedAdd($termQuotient,1);$termRemainder-=$denominator;}}
    }
    return $remainder>=intdiv($denominator,2)+$denominator%2?acCheckedAdd($quotient,1):$quotient;
}
function acRoundRatio(int $numerator,int $denominator):int {return acMulDiv($numerator,1,$denominator);}
function acAmount(int $minor):string {acCheckedAdd($minor,0);return ($minor<0?'-':'').intdiv(abs($minor),100).'.'.str_pad((string)(abs($minor)%100),2,'0',STR_PAD_LEFT);}
function acMoney(int $minor):string {$raw=acAmount($minor);[$whole,$fraction]=explode('.',$raw);return 'Rs. '.preg_replace('/\\B(?=(\\d{3})+(?!\\d))/',',',$whole).'.'.$fraction;}
function acWords(int $minor):string {
    acCheckedAdd($minor,0);
    $small=['zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];$tens=['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    $say=function(int $n)use(&$say,$small,$tens):string{if($n<20)return $small[$n];if($n<100)return $tens[intdiv($n,10)].($n%10?' '.$small[$n%10]:'');foreach([10000000=>'crore',100000=>'lakh',1000=>'thousand',100=>'hundred'] as $v=>$word)if($n>=$v)return $say(intdiv($n,$v)).' '.$word.($n%$v?' '.$say($n%$v):'');return '';};
    return ($minor<0?'Minus ':'').ucfirst($say(intdiv(abs($minor),100))).' Nepalese rupees'.($minor%100?' and '.$say(abs($minor)%100).' paisa':'').' only';
}
