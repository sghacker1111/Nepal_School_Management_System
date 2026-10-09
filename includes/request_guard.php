<?php
/** Shared HTTP authorization and request integrity checks. */
function denySchoolRequest(string $message, int $status = 403): never {
    http_response_code($status);
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false, 'message'=>$message]);
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Request could not be completed</title><main style="font:16px system-ui;max-width:650px;margin:10vh auto;padding:24px"><h1>Request could not be completed</h1><p>' . htmlspecialchars($message) . '</p><a href="' . htmlspecialchars(APP_URL) . '">Return to dashboard</a></main>';
    }
    exit;
}
function schoolMutationQuery(array $query): bool {
    foreach (array_keys($query) as $key) {
        if (preg_match('/^(delete(?:_.*)?|toggle_(?:status|hide|hidden|map_status)|publish|lock|reopen|make_universal|(?:view_|hub_)?perm_action)$/', $key)) return true;
    }
    return in_array($query['action'] ?? '', ['request_permission','request_view_permission','request_hub_permission'], true);
}
function schoolRoleCanWrite(string $role, string $module, string $file): bool {
    if (in_array($role, ['super_admin','school_admin'], true)) return true;
    if ($module === 'auth') return true;
    if ($role === 'exam_officer') return in_array($module, ['exams','results','learning'], true);
    if ($role === 'teacher') return in_array($module, ['attendance','learning'], true) || ($module === 'results' && $file === 'marks_entry.php');
    if ($role === 'principal') return in_array($module, ['attendance','learning'], true);
    return false;
}
function guardSchoolRequest(): void {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    preg_match('~/modules/([^/]+)/([^/]+)$~', $script, $parts);
    $module = $parts[1] ?? '';
    $file = $parts[2] ?? '';
    $role = Auth::getRole();
    $write = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $mutation = schoolMutationQuery($_GET);
    if ($module === 'super_admin' && $role !== 'super_admin') denySchoolRequest('System administrator access is required.');
    if ($module && !in_array($module, ['auth','super_admin'], true) && !Auth::hasAccess($module)) denySchoolRequest('This module is not included in your school package.');
    if ($role !== 'super_admin' && !(int)Auth::getSchoolId()) denySchoolRequest('Your account has no school assignment. Contact an administrator.');
    if (($write || $mutation) && !schoolRoleCanWrite($role, $module, $file)) denySchoolRequest('Your role does not allow changes in this section.');
    if (($write || $mutation) && Auth::isReadOnly() && $module !== 'auth') denySchoolRequest('School preview is read-only. Sign in as a school administrator to make changes.');
    if (!$write && $mutation) {
        // Old bookmarked action links remain usable, but a GET never changes records.
        $url = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars(Auth::generateCSRFToken(), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>Confirm action</title><main style="font:16px system-ui;max-width:650px;margin:10vh auto;padding:24px"><h1>Confirm this change</h1><p>This action changes school records. Continue only if you intended it.</p><form method="post" action="'.$url.'"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.$token.'"><button type="submit">Confirm action</button> <a href="'.htmlspecialchars(APP_URL).'">Cancel</a></form></main>';
        exit;
    }
    if (!$write) {
        require_once __DIR__ . '/school_validation.php';
        try { validateSchoolReferences(getDB(), (int)(Auth::getSchoolId() ?? 0), $module, [], $_GET); }
        catch (InvalidArgumentException $e) { denySchoolRequest($e->getMessage(), 404); }
        return;
    }
    if (!Auth::verifyCSRFToken($_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) denySchoolRequest('Your security token has expired. Reload the page and try again.', 419);
    foreach ($_POST as $key=>$value) {
        if (str_ends_with($key, '_drive_path') && !empty($value)) denySchoolRequest('Use the file upload button. Reading a server file path is not supported.', 422);
    }
    foreach ($_FILES as $fileKey=>$upload) {
        if (is_array($upload['error'] ?? null)) continue;
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) > MAX_FILE_SIZE) denySchoolRequest('The upload failed or exceeds the 5 MB limit.', 422);
        if (preg_match('/photo|logo|signature|sig$/i', $fileKey)) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            if (!in_array($mime, ['image/jpeg','image/png'], true) || !@getimagesize($upload['tmp_name'])) denySchoolRequest('Photos and signatures must be valid PNG or JPEG images.', 422);
        }
    }
    require_once __DIR__ . '/school_validation.php';
    try { validateSchoolReferences(getDB(), (int)(Auth::getSchoolId() ?? 0), $module, $_POST, $_GET); }
    catch (InvalidArgumentException $e) { denySchoolRequest($e->getMessage(), 422); }
}
