<?php
require __DIR__.'/test_support.php';$fixture=erpTestFixture();$db=$fixture['db'];$ids=$fixture['fixture']['ids'];
$ids['other_admin']=$fixture['fixture']['otherUser'];$ids['admin']=(int)$db->query("SELECT id FROM users WHERE username='admin'")->fetchColumn();
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/user_management.php';
$id=(int)$ids['teacher'];$password='TestPassword123!';
for($i=1;$i<=4;$i++){erpCheck(!schoolAuthenticateAccount($db,$id,'wrong'),'Reject failure '.$i);erpCheck(!schoolAccountLocked(schoolAccountState($db,$id)),'First four failures leave retry available');}
erpCheck((bool)schoolAuthenticateAccount($db,$id,$password),'Success clears failures');erpEqual(schoolAccountState($db,$id)['failures'],0,'Failure count reset');
for($i=0;$i<5;$i++)schoolAuthenticateAccount($db,$id,'wrong');
$state=schoolAccountState($db,$id);erpCheck($state['until']>=time()+1798&&$state['until']<=time()+1800,'Thirty minute suspension');
for($i=0;$i<6;$i++)erpCheck(!schoolAuthenticateAccount($db,$id,$i%2?'wrong':$password),'Suspension denies all passwords');
erpEqual(schoolAccountState($db,$id),$state,'Attempts during wait do not use second-stage retries');
$state['until']=time()-1;schoolSaveAccountState($db,$id,$state);
erpCheck(!schoolAuthenticateAccount($db,$id,'wrong'),'First post-wait failure');erpCheck(!schoolAccountState($db,$id)['blocked'],'One post-wait failure is allowed');
schoolAuthenticateAccount($db,$id,'wrong');erpCheck(schoolAccountState($db,$id)['blocked'],'Second post-wait failure permanently locks');
erpCheck(!schoolAuthenticateAccount($db,$id,$password),'Correct password cannot bypass permanent lock');
erpReject(fn()=>schoolUnlockAccount($db,$ids['other_admin'],$id,$password),'Other school cannot unlock');
erpReject(fn()=>schoolUnlockAccount($db,$ids['school_admin'],$id,'wrong'),'Unlock requires actor current password');
schoolUnlockAccount($db,$ids['school_admin'],$id,$password);erpCheck((bool)schoolAuthenticateAccount($db,$id,$password),'Own school admin can unlock teacher');
$admin=(int)$ids['school_admin'];schoolSaveAccountState($db,$admin,['failures'=>2,'retry'=>true,'until'=>0,'blocked'=>true,'epoch'=>1]);
erpReject(fn()=>schoolUnlockAccount($db,$admin,$admin,$password),'No self unlock');
erpReject(fn()=>schoolUnlockAccount($db,$ids['other_admin'],$admin,$password),'No school-admin unlock of peer');
erpReject(fn()=>saveSchoolUser($db,$ids['admin'],'super_admin',0,['user_id'=>$admin,'school_id'=>1,'username'=>'school_admin','full_name'=>'Test School Admin','role'=>'teacher','status'=>1,'password'=>$password]),'Cannot downgrade locked administrator to bypass unlock hierarchy');
schoolUnlockAccount($db,$ids['admin'],$admin,$password);erpCheck((bool)schoolAuthenticateAccount($db,$admin,$password),'Main admin unlocks school administrator');
$root=(int)$ids['admin'];$codes=schoolGenerateBackupCodes($db,$root,$password);erpEqual(count($codes),10,'Ten backup codes');erpEqual(count(array_unique($codes)),10,'Unique random backup codes');
foreach(schoolBackupCodes($db,$root) as $hash){erpEqual(strlen($hash),64,'Only SHA256 stored');erpCheck(!in_array($hash,$codes,true),'No plaintext codes stored');}
erpReject(fn()=>schoolGenerateBackupCodes($db,$admin,$password),'School administrator cannot generate main-admin recovery codes');
erpCheck(!schoolRecoverAdministrator($db,'admin',$codes[0],str_repeat('a',73)),'Reject truncated password risks');
erpCheck(!schoolRecoverAdministrator($db,'school_admin',$codes[0],$password),'Recovery cannot target school accounts');
schoolSaveAccountState($db,$root,['failures'=>2,'retry'=>true,'until'=>time()+1800,'blocked'=>true,'epoch'=>4]);
erpReject(fn()=>schoolUnlockAccount($db,$admin,$root,$password),'No admin unlock for main admin');
erpCheck(schoolRecoverAdministrator($db,'admin',$codes[0],'RecoveredPassword123!'),'Backup code resets locked main admin');
erpEqual(count(schoolBackupCodes($db,$root)),9,'Code consumed');erpCheck(!schoolRecoverAdministrator($db,'admin',$codes[0],'AnotherPassword123!'),'No replay');
erpCheck(!schoolAuthenticateAccount($db,$root,$password),'Old password rejected');erpCheck((bool)schoolAuthenticateAccount($db,$root,'RecoveredPassword123!'),'New password accepted');
$next=schoolGenerateBackupCodes($db,$root,'RecoveredPassword123!');erpCheck(!schoolRecoverAdministrator($db,'admin',$codes[1],'AnotherPassword123!'),'Regeneration invalidates old unused codes');
$audit=implode(' ',array_column($db->query('SELECT action FROM activity_logs')->fetchAll(),'action'));foreach([...$codes,...$next] as $code)erpCheck(!str_contains($audit,$code),'Audit never logs recovery secret');
echo 'Account security passed: '.$GLOBALS['erp_checks'].' checks.'.PHP_EOL;
