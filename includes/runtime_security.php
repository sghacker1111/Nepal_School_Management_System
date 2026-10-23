<?php
/** Upstream security baseline published 21 October 2026. No public diagnostics. */
function schoolRuntimeSecurity(?string $version = null): array {
    $version = $version ?? PHP_VERSION;
    $fixed = ['8.2'=>'8.2.35', '8.3'=>'8.3.36', '8.4'=>'8.4.27', '8.5'=>'8.5.12'];
    $branch = preg_match('/^(8\.[2-5])\./', $version, $match) ? $match[1] : '';
    $minimum = $fixed[$branch] ?? null;
    $patched = $minimum !== null && preg_match('/^\d+\.\d+\.\d+(?:[-+].*)?$/D', $version)
        && version_compare($version, $minimum, '>=');
    return ['version'=>$version, 'patched'=>(bool)$patched, 'minimum'=>$minimum,
        'advisory'=>'https://www.php.net/archive/2026.php#2026-10-21-5'];
}
