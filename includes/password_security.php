<?php
/** Shared re-authentication limit across tabs, sessions and sensitive actions. */
function schoolVerifySensitivePassword(PDO $db, int $userId, mixed $password): bool {
    if ($userId < 1 || !is_string($password) || $password === '' || strlen($password)>1024) return false;
    $key = hash('sha256', 'reauth|'.$userId);
    $cutoff = time()-900;
    $db->prepare('DELETE FROM login_attempts WHERE identity_key=? AND attempted_at<?')->execute([$key,$cutoff]);
    $q = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identity_key=?');$q->execute([$key]);
    if ((int)$q->fetchColumn() >= 10) return false;
    $q = $db->prepare('SELECT password FROM users WHERE id=? AND status=1');$q->execute([$userId]);
    $hash = $q->fetchColumn();
    if ($hash && password_verify($password, $hash)) {
        $db->prepare('DELETE FROM login_attempts WHERE identity_key=?')->execute([$key]);
        return true;
    }
    $db->prepare('INSERT INTO login_attempts(identity_key,attempted_at) VALUES(?,?)')->execute([$key,time()]);
    return false;
}
