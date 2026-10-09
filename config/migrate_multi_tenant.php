<?php
// Compatibility entry point for the additive migration; never reset school ownership.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/config.php';
require_once __DIR__.'/../includes/database.php';
getDB();
echo "Additive school workflow migration completed. Existing school records were retained.\n";
