<?php
require __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();require __DIR__.'/../includes/accounting_service.php';
$ac=new SchoolAccounting($db,1,$f['ids']['school_admin']);
$sample=['customer_name'=>'Example customer','bill_date'=>'2026-10-01','due_date'=>'2026-10-31','items'=>[['particular'=>'Monthly Tuition Fee','quantity'=>'1','rate'=>'2500.00'],['particular'=>'Examination Fee','quantity'=>'1','rate'=>'500.00']]];
erpEqual($ac->previewInvoice($sample)['total_minor'],300000,'Requested Rs. 3000 bill matches browser');
$in=['customer_name'=>'Exact money customer','bill_date'=>'2026-10-01','due_date'=>'2026-10-31','items'=>[['particular'=>'Large A','rate'=>'5000000000.00'],['particular'=>'Large B','rate'=>'5000000000.00']],'bill_discount'=>'5000000000.00'];
erpEqual($ac->previewInvoice($in)['total_minor'],500000000000,'Large discount allocation does not overflow');
erpEqual(acDecimalUnits('2500.00'),250000,'Decimal paisa');erpEqual(acDecimalUnits('10000000000.00'),1000000000000,'Exact upper amount accepted');
erpEqual(acMulDiv(1999,2500,1000),4998,'Fractional quantity half-up');
erpEqual(acMulDiv(1000000000000,1000000000000,1000000000000),1000000000000,'Large ratio without intermediate overflow');
foreach(['1e3','-1','1.001','999999999999999999999999999.00',1.5] as $bad)erpReject(fn()=>acDecimalUnits($bad),'Invalid decimal '.var_export($bad,true));
erpReject(fn()=>acMulDiv(1,1,0),'Zero denominator');erpReject(fn()=>acMulDiv(1000000000000,2,1),'Result overflow');erpReject(fn()=>acCheckedAdd(AC_MAX_MINOR,1),'Sum overflow');
erpEqual(acCheckedAdd(-AC_MAX_MINOR,1),-999999999999,'Signed report amount');erpReject(fn()=>acAmount(PHP_INT_MIN),'Format overflow rejected safely');
$in['items']=[['particular'=>'A','rate'=>'0.01'],['particular'=>'B','rate'=>'0.01'],['particular'=>'C','rate'=>'0.01']];$in['bill_discount']='0.01';
$p=$ac->previewInvoice($in);erpEqual($p['total_minor'],2,'Three penny lines minus one penny');erpEqual(array_sum(array_column($p['items'],'discount_minor')),1,'Distributed discount exact');
$ac->settings['tax_registered']=1;$in['bill_discount']='0';$in['items']=[['particular'=>'Fractional taxed fee','quantity'=>'1.999','rate'=>'25.00','tax_percent'=>'13.00']];
$p=$ac->previewInvoice($in);erpEqual($p['subtotal_minor'],4998,'Fractional line amount');erpEqual($p['tax_minor'],650,'Configured tax half-up');erpEqual($p['total_minor'],5648,'Fractional taxed total');
echo 'Accounting money passed: '.$GLOBALS['erp_checks']." checks\n";
