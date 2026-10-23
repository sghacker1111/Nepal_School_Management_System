<?php
// Compatibility entry point for the additive migration; never reset school ownership.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/migrate.php';
