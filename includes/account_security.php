<?php
/** Persistent account policy. Existing app_meta storage avoids a live schema upgrade. */
function schoolAccountState(PDO $db,int $id):array {
    $q=$db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=?');$q->execute(['account_security:'.$id]);$raw=$q->fetchColumn();
    $state=$raw===false?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    return $state+['failures'=>0,'retry'=>false,'until'=>0,'blocked'=>false,'epoch'=>0];
}
function schoolSecurityPut(PDO $db,string $key,string $value):void {
    // Caller holds the user's row lock; values never exceed app_meta's 255 bytes.
    if(strlen($value)>255)throw new LogicException('Security state is too large.');
    $db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute([$key]);
    $db->prepare('INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?)')->execute([$key,$value]);
}
function schoolSaveAccountState(PDO $db,int $id,array $state):void {schoolSecurityPut($db,'account_security:'.$id,json_encode($state,JSON_THROW_ON_ERROR));}
function schoolAccountLocked(array $state):bool {return !empty($state['blocked'])||(int)$state['until']>time();}
function schoolSecurityTransaction(PDO $db,callable $action):mixed {
    if($db->inTransaction())throw new LogicException('Account security requires an independent transaction.');
    $db->beginTransaction();
    try{$result=$action();$db->commit();return $result;}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function schoolSecurityUser(PDO $db,int $id):array|false {
    // SQLite obtains its writer lock before reading. MySQL locks exactly this user.
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$db->prepare('UPDATE users SET id=id WHERE id=?')->execute([$id]);
    $q=$db->prepare('SELECT * FROM users WHERE id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);
}
function schoolSecurityAudit(PDO $db,int $actor,string $action,int $target):void {
    $db->prepare('INSERT INTO activity_logs(user_id,action,table_name,record_id,ip_address) VALUES(?,?,?,?,?)')->execute([$actor,$action,'users',$target,$_SERVER['REMOTE_ADDR']??'0.0.0.0']);
}
/** Five consecutive failed sign-ins -> 30 minutes; next two failures -> manual recovery. */
function schoolAuthenticateAccount(PDO $db,int $id,string $password):array|false {
    return schoolSecurityTransaction($db,function()use($db,$id,$password){
        $user=schoolSecurityUser($db,$id);if(!$user||!(int)$user['status'])return false;
        $state=schoolAccountState($db,$id);if(schoolAccountLocked($state))return false;
        if(password_verify($password,$user['password'])){
            $state=array_replace($state,['failures'=>0,'retry'=>false,'until'=>0,'blocked'=>false]);schoolSaveAccountState($db,$id,$state);return $user;
        }
        $state['failures']++;
        if($state['retry']&&$state['failures']>=2){$state['blocked']=true;$state['epoch']++;schoolSecurityAudit($db,$id,'Account locked after repeated failed sign-ins',$id);}
        elseif(!$state['retry']&&$state['failures']>=5){$state['retry']=true;$state['failures']=0;$state['until']=time()+1800;$state['epoch']++;schoolSecurityAudit($db,$id,'Account sign-in suspended for 30 minutes',$id);}
        schoolSaveAccountState($db,$id,$state);return false;
    });
}
function schoolCanUnlockAccount(array $actor,array $target):bool {
    if(!(int)($actor['status']??0)||(int)$actor['id']===(int)$target['id']||$target['role']==='super_admin')return false;
    if($actor['role']==='super_admin')return true;
    return $actor['role']==='school_admin'&&$target['role']!=='school_admin'&&(int)$actor['school_id']>0&&(int)$actor['school_id']===(int)$target['school_id'];
}
function schoolUnlockAccount(PDO $db,int $actorId,int $targetId,mixed $password):void {
    $q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$actorId]);$actor=$q->fetch();$q->execute([$targetId]);$target=$q->fetch();
    if(!$actor||!$target||!schoolCanUnlockAccount($actor,$target)||schoolAccountLocked(schoolAccountState($db,$actorId)))throw new InvalidArgumentException('Your role cannot unlock this account.');
    require_once __DIR__.'/password_security.php';
    if(!schoolVerifySensitivePassword($db,$actorId,$password))throw new InvalidArgumentException('Enter your current administrator password. Verification may be temporarily locked after repeated failures.');
    schoolSecurityTransaction($db,function()use($db,$actorId,$targetId,$password){
        $users=[];$ids=[$actorId,$targetId];sort($ids);foreach($ids as $id)$users[$id]=schoolSecurityUser($db,$id);
        if(!$users[$actorId]||!$users[$targetId]||!schoolCanUnlockAccount($users[$actorId],$users[$targetId])||schoolAccountLocked(schoolAccountState($db,$actorId))||!password_verify($password,$users[$actorId]['password']))throw new InvalidArgumentException('Your role or password changed. Reload before unlocking this account.');
        $state=schoolAccountState($db,$targetId);
        schoolSaveAccountState($db,$targetId,['failures'=>0,'retry'=>false,'until'=>0,'blocked'=>false,'epoch'=>$state['epoch']+1]);
        $db->prepare('DELETE FROM login_attempts WHERE identity_key IN (?,?)')->execute([hash('sha256','account|'.strtolower(trim($users[$targetId]['username']))),hash('sha256','reauth|'.$targetId)]);
        schoolSecurityAudit($db,$actorId,'Unlocked account after identity verification',$targetId);
    });
}
function schoolBackupCodes(PDO $db,int $id):array {
    $q=$db->prepare('SELECT meta_key,meta_value FROM app_meta WHERE meta_key LIKE ? ORDER BY meta_key');$q->execute(['account_recovery:'.$id.':%']);return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}
function schoolGenerateBackupCodes(PDO $db,int $id,mixed $password):array {
    $q=$db->prepare("SELECT id FROM users WHERE id=? AND role='super_admin' AND status=1");$q->execute([$id]);
    require_once __DIR__.'/password_security.php';
    if(!$q->fetchColumn()||schoolAccountLocked(schoolAccountState($db,$id))||!schoolVerifySensitivePassword($db,$id,$password))throw new InvalidArgumentException('Enter your current main administrator password to generate backup codes.');
    return schoolSecurityTransaction($db,function()use($db,$id,$password){
        $user=schoolSecurityUser($db,$id);if(!$user||$user['role']!=='super_admin'||!(int)$user['status']||schoolAccountLocked(schoolAccountState($db,$id))||!password_verify($password,$user['password']))throw new InvalidArgumentException('An active main administrator with the current password is required.');
        $db->prepare('DELETE FROM app_meta WHERE meta_key LIKE ?')->execute(['account_recovery:'.$id.':%']);$codes=[];
        for($i=0;$i<10;$i++){
            $code=strtoupper(bin2hex(random_bytes(16)));$codes[]=implode('-',str_split($code,4));
            schoolSecurityPut($db,'account_recovery:'.$id.':'.$i,hash('sha256',$code));
        }
        schoolSecurityAudit($db,$id,'Generated new one-use administrator backup codes',$id);return $codes;
    });
}
/** Recovery is independent of sign-in lockout; failed guesses have separate throttles. */
function schoolRecoverAdministrator(PDO $db,mixed $username,mixed $code,mixed $password):bool {
    if(!is_string($username)||strlen($username)>100||!is_string($code)||strlen($code)>100||!is_string($password)||strlen($password)<8||strlen($password)>72||str_contains($password,"\0"))return false;
    $key=hash('sha256','recovery-account|'.strtolower(trim($username)));$network=hash('sha256','recovery-network|'.($_SERVER['REMOTE_ADDR']??'local'));
    $db->prepare('DELETE FROM login_attempts WHERE attempted_at<?')->execute([time()-1800]);
    foreach([$key=>5,$network=>30] as $identity=>$limit){$q=$db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identity_key=?');$q->execute([$identity]);if((int)$q->fetchColumn()>=$limit)return false;}
    // Reserve the attempt before verification so failed requests remain rate-limited.
    foreach([$key,$network] as $identity)$db->prepare('INSERT INTO login_attempts(identity_key,attempted_at) VALUES(?,?)')->execute([$identity,time()]);
    $q=$db->prepare("SELECT id FROM users WHERE LOWER(username)=LOWER(?) AND role='super_admin' AND status=1");$q->execute([trim($username)]);$id=(int)$q->fetchColumn();if(!$id)return false;
    $normalized=strtoupper(str_replace(['-',' '], '', trim($code)));if(!preg_match('/^[A-F0-9]{32}$/D',$normalized))return false;
    return schoolSecurityTransaction($db,function()use($db,$id,$normalized,$password,$key){
        $user=schoolSecurityUser($db,$id);if(!$user||$user['role']!=='super_admin'||!(int)$user['status'])return false;
        // Recheck after serialization: concurrent guesses cannot all pass an old count.
        $attempts=$db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identity_key=? AND attempted_at>=?');$attempts->execute([$key,time()-1800]);if((int)$attempts->fetchColumn()>5)return false;
        $match=null;foreach(schoolBackupCodes($db,$id) as $storedKey=>$hash)if(hash_equals($hash,hash('sha256',$normalized)))$match=$storedKey;
        if($match===null)return false;
        $db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute([$match]);
        $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        $state=schoolAccountState($db,$id);schoolSaveAccountState($db,$id,['failures'=>0,'retry'=>false,'until'=>0,'blocked'=>false,'epoch'=>$state['epoch']+1]);
        $db->prepare('DELETE FROM login_attempts WHERE identity_key IN (?,?,?)')->execute([$key,hash('sha256','account|'.strtolower(trim($user['username']))),hash('sha256','reauth|'.$id)]);
        schoolSecurityAudit($db,$id,'Administrator password reset with one-use backup code',$id);return true;
    });
}
