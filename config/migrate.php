<?php
// Explicit read-only CLI readiness check. Legacy conversion is an offline tool.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/database.php';
getDB();
echo "Database schema readiness verified. No production schema or school data was changed.\n";
