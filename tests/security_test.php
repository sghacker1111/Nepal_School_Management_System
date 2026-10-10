<?php
require_once __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();
require_once __DIR__ . '/../includes/auth.php';
Auth::startSession();
$failures = [];
function checkSecurity($condition, $message) { global $failures; if (!$condition) $failures[] = $message; }
unset($_SESSION[CSRF_TOKEN_NAME]);
checkSecurity(!Auth::verifyCSRFToken(''), 'Missing CSRF token must never authenticate a request');
$token = Auth::generateCSRFToken();
checkSecurity(Auth::verifyCSRFToken($token), 'The current CSRF token must validate');
checkSecurity(!Auth::verifyCSRFToken('incorrect'), 'An incorrect CSRF token must fail');
$_SESSION['role'] = 'teacher';
$_SESSION['school_id'] = 1;
$_SESSION['school_subscription_type'] = 'exam_generator';
$db->exec("UPDATE schools SET subscription_type='exam_generator' WHERE id=1");
checkSecurity(Auth::hasAccess('classes'), 'Paper-only schools need class selectors');
checkSecurity(Auth::hasAccess('subjects'), 'Paper-only schools need subject selectors');
checkSecurity(!Auth::hasAccess('results'), 'Paper-only subscriptions must not expose results');
if ($failures) { fwrite(STDERR, implode("\n", $failures)."\n"); exit(1); }
echo "Security checks passed\n";
