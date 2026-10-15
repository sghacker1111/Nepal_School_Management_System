<?php
/** Shared HTTP authorization and request integrity checks. */
function denySchoolRequest(string $message, int $status = 403): never {
    http_response_code($status);
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false, 'message'=>$message]);
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Error found</title><main role="alert" style="font:16px system-ui;max-width:650px;margin:10vh auto;padding:24px"><h1>Error found</h1><p>' . htmlspecialchars($message) . '</p><a href="' . htmlspecialchars(APP_URL) . '">Return to dashboard</a></main>';
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
    if($role==='student')return $module==='auth'||($module==='learning'&&$file==='practice_mcqs.php');
    if (in_array($role, ['super_admin','school_admin'], true)) return true;
    if ($module === 'auth') return true;
    if ($module === 'accounting') {
        if($role==='cashier')return in_array($file,['payments.php','invoice.php','student.php'],true);
        if($role==='accountant')return !in_array($file,['settings.php','journals.php','credit.php','attachment.php'],true);
        return false;
    }
    if ($role === 'exam_officer') return in_array($module, ['exams','results','learning'], true) || ($module === 'subjects' && $file === 'internal.php');
    if ($role === 'teacher') return in_array($module, ['attendance','learning'], true) || ($module === 'results' && $file === 'marks_entry.php');
    if ($role === 'principal') return in_array($module, ['attendance','learning'], true);
    return false;
}
function guardSchoolRequest(): void {
    require_once __DIR__.'/nepali_dates.php';
    try{schoolNormalizeDateFields($_GET);schoolNormalizeDateFields($_POST);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    preg_match('~/modules/([^/]+)/([^/]+)$~', $script, $parts);
    $module = $parts[1] ?? '';
    $file = $parts[2] ?? '';
    $role = Auth::getRole();
    if($role==='student'){
        $studentPage=$module==='learning'&&in_array($file,['index.php','practice_mcqs.php','exam_questions.php','exam_mcqs.php'],true);
        $studentLanding=$module===''&&basename($script)==='index.php';
        if(!$studentPage&&!$studentLanding&&$module!=='auth')denySchoolRequest('Student accounts can access Learning Hub, Practice MCQs and released Exam Questions only.');
        if($module!=='auth'){
            require_once __DIR__.'/student_learning.php';
            try{studentLearningRecord(getDB(),(int)Auth::getSchoolId(),(int)Auth::getUserId());}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage());}
        }
    }
    if($role!=='super_admin'&&$module!=='auth'){
        require_once __DIR__.'/packages.php';$subscriptionState=schoolSubscriptionState(Auth::getSubscription());
        if($subscriptionState!=='active')denySchoolRequest('School subscription is '.str_replace('_',' ',$subscriptionState).'. Contact the system administrator to activate or renew the account.');
    }
    if(in_array($role,['accountant','cashier','auditor'],true)&&!in_array($module,['accounting','auth'],true)&&$module!=='')denySchoolRequest('Your finance role can access Accounting & Billing only.');
    $write = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $mutation = schoolMutationQuery($_GET);
    if ($module === 'super_admin' && $role !== 'super_admin') denySchoolRequest('System administrator access is required.');
    $accessModule=$module==='learning'?(['questions.php'=>'questions','exam_questions.php'=>'exam_questions','exam_mcqs.php'=>'exam_questions','exam_paper_generator.php'=>'exam_paper_generator','exam_specification_grids.php'=>'exam_specification_grids','paper_generator.php'=>'paper_generator','question_grid.php'=>'paper_generator','specification_grids.php'=>'specification_grids','curriculum.php'=>'curriculum','ocr_scanner.php'=>'ocr_scanner'][$file]??'learning'):$module;
    if ($module && !in_array($module, ['auth','super_admin'], true) && !Auth::hasAccess($accessModule)) denySchoolRequest('This module is not included in your school package.');
    if($module==='learning'&&in_array($file,['questions.php','exam_questions.php','exam_mcqs.php'],true)&&($write||$mutation)&&!Auth::canAddQuestions())denySchoolRequest('Your package allows question viewing, not adding or editing questions.');
    if($module==='exams'&&isset($_GET['reopen'])&& !in_array($role,['super_admin','school_admin'],true))denySchoolRequest('Only a school administrator may reopen marks.');
    if ($role !== 'super_admin' && !(int)Auth::getSchoolId()) denySchoolRequest('Your account has no school assignment. Contact an administrator.');
    if (($write || $mutation) && !schoolRoleCanWrite($role, $module, $file)) denySchoolRequest('Your role does not allow changes in this section.');
    if($write&&$module==='accounting'){
        require_once __DIR__.'/accounting_service.php';$action=accountingRouteAction($file,$_POST);
        if(!$action||!accountingActionAllowed((string)$role,$action,Auth::isReadOnly()))denySchoolRequest('Your role does not allow this financial action.');
    }
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
        try { validateSchoolReferences(getDB(), (int)(Auth::getSchoolId() ?? 0), $module, [], $_GET); validateTeacherLearningRequest(getDB(),(int)(Auth::getSchoolId()??0),$module,$file,[], $_GET); validateTeacherResultRequest(getDB(),(int)(Auth::getSchoolId()??0),$module,$file,[],$_GET); }
        catch (InvalidArgumentException $e) { denySchoolRequest($e->getMessage(), 404); }
        return;
    }
    if (!Auth::verifyCSRFToken($_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) denySchoolRequest('Your security token has expired. Reload the page and try again.', 419);
    if($module==='learning'){require_once __DIR__.'/teaching_scope.php';try{learningSingleAction($_POST,$_GET);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}}
    if($module==='learning'&&in_array($file,['questions.php','exam_questions.php','exam_mcqs.php'],true)){
        require_once __DIR__.'/learning_content_service.php';$actor=learningActor(getDB(),(int)Auth::getUserId(),(int)Auth::getSchoolId(),Auth::isReadOnly());
        if(array_key_exists('school_id',$_POST)||array_key_exists('created_by_user_id',$_POST)||array_key_exists('bank_kind',$_POST)||isset($_GET['make_universal']))denySchoolRequest('Content school, bank and creator cannot be changed.',403);
        $action=$_POST['action']??(isset($_GET['delete'])?'archive':'');
        if(!is_string($action))denySchoolRequest('Choose one valid question action.',422);
        if(in_array($action,['edit','set_visibility','archive','delete','restore'],true)){
            try{$r=learningContentRecord(getDB(),$actor,'questions',learningId($_POST['question_id']??$_GET['delete']??null),$file!=='questions.php'?'exam_bank':'bank',true);}catch(InvalidArgumentException $e){denySchoolRequest('Content not found.',404);}
            if(!learningCanEdit(getDB(),$actor,$r))denySchoolRequest('Only the creator may change this content, while assigned to its subject.',403);
        }
        if($action==='add'&&!learningCanCreate(getDB(),$actor,(int)($_POST['class_id']??0),(int)($_POST['subject_id']??0)))denySchoolRequest('Create questions only for your assigned class subjects.',403);
    }
    if($module==='learning'&&$file==='curriculum.php'&&$role!=='super_admin')denySchoolRequest('Only super admin may add or change chapters and topics. The school curriculum is read-only.',403);
    if($module==='learning'&&$file==='exam_specification_grids.php'){
        require_once __DIR__.'/exam_paper_service.php';$actor=learningActor(getDB(),(int)Auth::getUserId(),(int)Auth::getSchoolId(),Auth::isReadOnly());
        if(array_key_exists('school_id',$_POST)||array_key_exists('created_by_user_id',$_POST))denySchoolRequest('Grid school and creator cannot be changed.',403);
        if(!empty($_POST['grid_id'])){
            try{$r=examGridRecord(getDB(),$actor,learningId($_POST['grid_id']),true);}catch(InvalidArgumentException $e){denySchoolRequest('Exam grid not found.',404);}
            if(!examGridCanEdit(getDB(),$actor,$r))denySchoolRequest('Only the exam grid creator may change it.',403);
        }
    }
    if($module==='learning'&&$file==='index.php'&&(isset($_POST['action_note'])||isset($_GET['delete_note']))){
        require_once __DIR__.'/learning_content_service.php';$actor=learningActor(getDB(),(int)Auth::getUserId(),(int)Auth::getSchoolId(),Auth::isReadOnly());
        if(isset($_POST['school_id'])||isset($_POST['created_by_user_id']))denySchoolRequest('Content school and creator cannot be changed.',403);
        $action=$_POST['action_note']??'archive';if(!is_string($action))denySchoolRequest('Choose one note action.',422);
        if(in_array($action,['edit','set_visibility','archive','restore'],true)){
            try{$r=learningContentRecord(getDB(),$actor,'learning_notes',learningId($_POST['note_id']??$_GET['delete_note']??null),'study',true);}catch(InvalidArgumentException $e){denySchoolRequest('Content not found.',404);}
            if(!learningCanEdit(getDB(),$actor,$r))denySchoolRequest('Only the creator may change this note, while assigned to its subject.',403);
        }
        if($action==='add'&&!learningCanCreate(getDB(),$actor,(int)($_POST['class_id']??0),(int)($_POST['subject_id']??0)))denySchoolRequest('Create notes only for your assigned class subjects.',403);
    }
    // Automatic settings are immutable even if a forged request re-enables hidden controls.
    $automaticSettingsWrite=($module==='exams'&&($file==='edit.php'||($file==='view.php'&&(isset($_POST['map_subject'])||isset($_GET['delete_mapping'])))))||($module==='subjects'&&$file==='internal.php'&&!empty($_GET['exam']));
    if($automaticSettingsWrite){
        $examId=(int)($_GET['id']??$_GET['exam']??0);
        $q=getDB()->prepare('SELECT creation_mode FROM exams WHERE id=? AND school_id=?');$q->execute([$examId,(int)Auth::getSchoolId()]);
        if($q->fetchColumn()==='automatic')denySchoolRequest('Automatic exam settings are read-only. Create a manual exam to customize defaults.');
    }
    foreach ($_POST as $key=>$value) {
        if (str_ends_with($key, '_drive_path') && !empty($value)) denySchoolRequest('Use the file upload button. Reading a server file path is not supported.', 422);
    }
    foreach ($_FILES as $fileKey=>$upload) {
        if (is_array($upload['error'] ?? null)) denySchoolRequest('Upload one file per field.',422);
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) > MAX_FILE_SIZE) denySchoolRequest('The upload failed or exceeds the 5 MB limit.', 422);
        if (preg_match('/photo|logo|signature|sig$/i', $fileKey)) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            if (!in_array($mime, ['image/jpeg','image/png'], true) || !@getimagesize($upload['tmp_name'])) denySchoolRequest('Photos and signatures must be valid PNG or JPEG images.', 422);
        }
    }
    require_once __DIR__ . '/school_validation.php';
    try { validateSchoolReferences(getDB(), (int)(Auth::getSchoolId() ?? 0), $module, $_POST, $_GET); validateTeacherLearningRequest(getDB(),(int)(Auth::getSchoolId()??0),$module,$file,$_POST,$_GET); validateTeacherResultRequest(getDB(),(int)(Auth::getSchoolId()??0),$module,$file,$_POST,$_GET); }
    catch (InvalidArgumentException $e) { denySchoolRequest($e->getMessage(), 422); }
}
