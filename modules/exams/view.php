<?php
/**
 * Exam Management - View / Configure Page
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/exam_service.php';
require_once __DIR__ . '/../../includes/exam_mapping_service.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();
$error = '';
$success = '';
$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch exam details
$school_id = Auth::getSchoolId();
$stmt = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
$stmt->execute([$exam_id, $school_id]);
$exam = $stmt->fetch();

if (!$exam) {
    redirect(schoolRouteUrl('modules/exams/index.php'), 'Exam not found', 'error');
}

$automaticExam=($exam['creation_mode']??'manual')==='automatic';
$configurationFrozen = $exam['is_locked'] || $exam['is_published'] || $automaticExam;

// Fetch school details for signatures
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

// Handle Signature Uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_signatures']) && Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])) {
    if ($exam['is_locked'] || $exam['is_published']) {
        $error = "The grading configuration is frozen because this exam is locked or published. Reopen it first.";
    } else {
        $sig_dir = UPLOAD_DIR;
        if (!file_exists($sig_dir)) {
            mkdir($sig_dir, 0777, true);
        }
        
        $updated_cols = [];
        $updated_params = [];
        
        // Handle Class Teacher Signature
        if (isset($_FILES['class_teacher_sig']) && $_FILES['class_teacher_sig']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['class_teacher_sig']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                $filename = 'class_teacher_' . $school_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['class_teacher_sig']['tmp_name'], $sig_dir . $filename)) {
                    $updated_cols[] = "class_teacher_signature = ?";
                    $updated_params[] = $filename;
                }
            } else {
                $error = "Only PNG, JPG, and JPEG files are allowed for signatures.";
            }
        }
        
        // Handle Exam Coordinator Signature
        if (isset($_FILES['exam_coordinator_sig']) && $_FILES['exam_coordinator_sig']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['exam_coordinator_sig']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                $filename = 'exam_coordinator_' . $school_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['exam_coordinator_sig']['tmp_name'], $sig_dir . $filename)) {
                    $updated_cols[] = "exam_coordinator_signature = ?";
                    $updated_params[] = $filename;
                }
            } else {
                $error = "Only PNG, JPG, and JPEG files are allowed for signatures.";
            }
        }
        
        if (empty($error) && !empty($updated_cols)) {
            $updated_params[] = $school_id;
            $sql = "UPDATE schools SET " . implode(', ', $updated_cols) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($updated_params);
            $success = "Signatures uploaded successfully!";
            
            // Reload school details
            $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
            $stmt->execute([$school_id]);
            $school = $stmt->fetch();
        }
    }
}

// Fetch edit mapping if requested
$edit_mapping = null;
$edit_mapping_id = isset($_GET['edit_mapping_id']) ? (int)$_GET['edit_mapping_id'] : 0;
if ($edit_mapping_id > 0) {
    $stmt = $db->prepare("SELECT * FROM exam_subjects WHERE id = ? AND exam_id = ?");
    $stmt->execute([$edit_mapping_id, $exam_id]);
    $edit_mapping = $stmt->fetch();
}

// Handle Subject Mapping Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['map_subject']) && Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])) {
    if ($configurationFrozen) {
        $error = "The grading configuration is frozen because this exam is locked or published. Reopen it first.";
    } else {
        $class_id = (int)$_POST['class_id'];
        $subject_id = (int)$_POST['subject_id'];
        $theory_full = (float)$_POST['theory_full'];
        $theory_pass = (float)$_POST['theory_pass'];
        $practical_full = ($exam['type'] === 'unit') ? 0 : ($exam['has_internal'] ? (float)($_POST['practical_full'] ?? 0) : 0);
        $practical_pass = ($exam['type'] === 'unit') ? 0 : ($exam['has_internal'] ? (float)($_POST['practical_pass'] ?? 0) : 0);
        $credit_hours = ($exam['type'] === 'unit' || $_POST['credit_hours'] === '') ? null : (float)$_POST['credit_hours'];
        $internal_credit_hours = ($exam['type'] === 'unit' || $_POST['internal_credit_hours'] === '') ? null : (float)$_POST['internal_credit_hours'];
        $external_credit_hours = ($exam['type'] === 'unit' || $_POST['external_credit_hours'] === '') ? null : (float)$_POST['external_credit_hours'];
        $optionalQuery=$db->prepare('SELECT * FROM class_subjects WHERE class_id=? AND subject_id=? AND school_id=? ORDER BY id LIMIT 1');
        $optionalQuery->execute([$class_id,$subject_id,$school_id]);$catalogScheme=$optionalQuery->fetch()?:[];$is_optional=(int)($catalogScheme['is_optional']??0);
        $assessmentMethod=$catalogScheme['assessment_method']??null;$curriculumVersion=$catalogScheme['curriculum_version']??null;
        if($assessmentMethod==='THEMATIC_CONTINUOUS'){$theory_full=100;$theory_pass=35;$practical_full=0;$practical_pass=0;$credit_hours=(float)$catalogScheme['credit_hours'];$internal_credit_hours=null;$external_credit_hours=null;}
        $form_edit_id = (int)($_POST['edit_mapping_id'] ?? 0);
        
        if (empty($class_id) || empty($subject_id)) {
            $error = 'Both Class and Subject must be selected';
        } else {
            try {
                $config=compact('class_id','subject_id','theory_full','theory_pass','practical_full','practical_pass','credit_hours','internal_credit_hours','external_credit_hours','is_optional');
                $config['assessment_method']=$assessmentMethod;$config['curriculum_version']=$curriculumVersion;
                if(!$form_edit_id)$config['internal_components']=$catalogScheme['internal_components']??null;
                $savedId=saveExamMapping($db,(int)$school_id,$exam_id,$config,$form_edit_id,(int)Auth::getUserId(),$_POST);
                Auth::logActivity(Auth::getUserId(),$form_edit_id?'Updated Exam Subject Configuration':'Mapped Exam Subject','exam_subjects',$savedId);
                redirect(schoolRouteUrl('modules/exams/view.php') . "?id=$exam_id",$form_edit_id?'Subject configuration updated; saved results recalculated successfully.':'Subject mapped successfully!');
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                error_log($e->getMessage());$error='The subject configuration could not be saved. Please try again.';
            }
        }
    }
}

// Handle Bulk Initialization from Class Mappings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initialize_defaults']) && Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])) {
    if($automaticExam||!empty($exam['template_snapshot'])){
        try{$inserted=initializeExamTemplateSubjects($db,(int)$school_id,$exam_id,(int)($_POST['init_class_id']??0));$success="Initialized $inserted subject configurations from saved template defaults successfully!";}
        catch(InvalidArgumentException $e){$error=$e->getMessage();}catch(Throwable $e){error_log($e->getMessage());$error='Subject defaults could not be initialized. Existing records are unchanged.';}
    } elseif ($configurationFrozen) {
        $error = "The grading configuration is frozen because this exam is locked or published. Reopen it first.";
    } else {
        $class_id = (int)$_POST['init_class_id'];
        if (empty($class_id)) {
            $error = 'Class must be selected to initialize.';
        } else {
            try {
                // Fetch subjects mapped to this class in class_subjects (only active mappings and non-hidden subjects)
                $stmt = $db->prepare("
                    SELECT cs.*, s.name_en as subject_name, s.full_marks as s_full, s.pass_marks as s_pass, s.credit_hours as s_credit,
                           s.th_credit_hours as s_th_credit, s.in_credit_hours as s_in_credit,
                           s.th_full_marks as s_th_full, s.th_pass_marks as s_th_pass,
                           s.in_full_marks as s_in_full, s.in_pass_marks as s_in_pass
                    FROM class_subjects cs
                    JOIN subjects s ON cs.subject_id = s.id
                    WHERE cs.school_id = ? AND cs.class_id = ? AND cs.is_active=1 AND (s.is_hidden = 0 OR s.is_hidden IS NULL)
                ");
                $stmt->execute([$school_id,$class_id]);
                $class_subs = $stmt->fetchAll();
                
                // Get class name
                $stmt_c = $db->prepare("SELECT name FROM classes WHERE id = ?");
                $stmt_c->execute([$class_id]);
                $class_name = $stmt_c->fetchColumn();

                $inserted = 0;
                foreach ($class_subs as $cs) {
                    $chk = $db->prepare("SELECT id FROM exam_subjects WHERE exam_id = ? AND subject_id = ? AND class_id = ?");
                    $chk->execute([$exam_id, $cs['subject_id'], $class_id]);
                    if (!$chk->fetch()) {
                        if ($exam['type'] === 'unit') {
                            $theory_full = ($cs['th_full_marks'] !== null && $cs['th_full_marks'] !== '') ? (float)$cs['th_full_marks'] : (($cs['s_th_full'] !== null && $cs['s_th_full'] !== '') ? (float)$cs['s_th_full'] : ($cs['s_full'] ?: 100));
                            $theory_pass = ($cs['th_pass_marks'] !== null && $cs['th_pass_marks'] !== '') ? (float)$cs['th_pass_marks'] : (($cs['s_th_pass'] !== null && $cs['s_th_pass'] !== '') ? (float)$cs['s_th_pass'] : ($cs['s_pass'] ?: 40));
                            $practical_full = 0;
                            $practical_pass = 0;
                            $credit_hours = null;
                            $int_ch = null;
                            $ext_ch = null;
                        } else {
                            // Extract Theory (TH) and Internal (IN) credit hours
                            $ext_ch = ($cs['th_credit_hours'] !== null && $cs['th_credit_hours'] !== '') ? (float)$cs['th_credit_hours'] : (($cs['s_th_credit'] !== null && $cs['s_th_credit'] !== '') ? (float)$cs['s_th_credit'] : null);
                            $int_ch = ($cs['in_credit_hours'] !== null && $cs['in_credit_hours'] !== '') ? (float)$cs['in_credit_hours'] : (($cs['s_in_credit'] !== null && $cs['s_in_credit'] !== '') ? (float)$cs['s_in_credit'] : null);
                            $credit_hours = ($cs['credit_hours'] !== null && $cs['credit_hours'] !== '') ? (float)$cs['credit_hours'] : (($ext_ch !== null && $int_ch !== null) ? ($ext_ch + $int_ch) : ($cs['s_credit'] ?: 4.0));

                            // Extract Theory (TH) marks
                            $theory_full = ($cs['th_full_marks'] !== null && $cs['th_full_marks'] !== '') ? (float)$cs['th_full_marks'] : (($cs['s_th_full'] !== null && $cs['s_th_full'] !== '') ? (float)$cs['s_th_full'] : ($cs['s_full'] ?: 75));
                            $theory_pass = ($cs['th_pass_marks'] !== null && $cs['th_pass_marks'] !== '') ? (float)$cs['th_pass_marks'] : (($cs['s_th_pass'] !== null && $cs['s_th_pass'] !== '') ? (float)$cs['s_th_pass'] : ($cs['s_pass'] ? round($cs['s_pass'] * 0.75, 1) : 26.25));

                            // Extract Internal (IN) marks
                            if ($exam['has_internal']) {
                                $practical_full = ($cs['in_full_marks'] !== null && $cs['in_full_marks'] !== '') ? (float)$cs['in_full_marks'] : (($cs['s_in_full'] !== null && $cs['s_in_full'] !== '') ? (float)$cs['s_in_full'] : 25);
                                $practical_pass = ($cs['in_pass_marks'] !== null && $cs['in_pass_marks'] !== '') ? (float)$cs['in_pass_marks'] : (($cs['s_in_pass'] !== null && $cs['s_in_pass'] !== '') ? (float)$cs['s_in_pass'] : 10);
                            } else {
                                $practical_full = 0;
                                $practical_pass = 0;
                            }
                        }
                        
                        if(($cs['assessment_method']??'')==='THEMATIC_CONTINUOUS'){$theory_full=100;$theory_pass=35;$practical_full=0;$practical_pass=0;$credit_hours=(float)$cs['credit_hours'];$int_ch=null;$ext_ch=null;}
                        $ins = $db->prepare("INSERT INTO exam_subjects (exam_id, subject_id, class_id, theory_full, theory_pass, practical_full, practical_pass, credit_hours, internal_credit_hours, external_credit_hours, is_optional, assessment_method, curriculum_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$exam_id, $cs['subject_id'], $class_id, $theory_full, $theory_pass, $practical_full, $practical_pass, $credit_hours, $int_ch, $ext_ch, (int)$cs['is_optional'], $cs['assessment_method'], $cs['curriculum_version']]);
                        $db->prepare('UPDATE exam_subjects SET internal_components=? WHERE id=?')->execute([$cs['internal_components']??null,$db->lastInsertId()]);
                        $inserted++;
                    }
                }
                
                Auth::logActivity(Auth::getUserId(), "Initialized $inserted Subjects for Class ID $class_id in Exam ID $exam_id", 'exam_subjects');
                $success = "Initialized $inserted subject configurations successfully!";
            } catch (PDOException $e) {
                $error = schoolPublicError($e);
            }
        }
    }
}

// Handle deleting mapping
if (isset($_GET['delete_mapping']) && Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])) {
    if ($configurationFrozen) {
        redirect(schoolRouteUrl('modules/exams/view.php') . "?id=$exam_id", "Reopen this locked or published exam before changing subjects.", 'error');
    } else {
        $mapping_id = (int)$_GET['delete_mapping'];
        try {
            $chk_marks = $db->prepare("
                SELECT COUNT(*) FROM marks m
                JOIN exam_subjects es ON es.exam_id = m.exam_id AND es.subject_id = m.subject_id
                JOIN students st ON st.id=m.student_id AND st.class_id=es.class_id
                WHERE es.id = ? AND es.exam_id=?
            ");
            $chk_marks->execute([$mapping_id,$exam_id]);
            if ($chk_marks->fetchColumn() > 0) {
                redirect(schoolRouteUrl('modules/exams/view.php') . "?id=$exam_id", "Cannot remove subject configuration: student marks have already been recorded for this subject.", 'error');
            } else {
                $db->prepare("DELETE FROM exam_subjects WHERE id = ? AND exam_id = ?")->execute([$mapping_id, $exam_id]);
                Auth::logActivity(Auth::getUserId(), 'Removed Exam Subject Configuration', 'exam_subjects', $mapping_id);
                redirect(schoolRouteUrl('modules/exams/view.php') . "?id=$exam_id", "Subject configuration removed");
            }
        } catch (PDOException $e) {
            redirect(schoolRouteUrl('modules/exams/view.php') . "?id=$exam_id", "Error: " . $e->getMessage(), 'error');
        }
    }
}

// Fetch classes
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ?) AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Fetch subjects
$subjects=schoolOfferedSubjects($db,(int)$school_id);

// Fetch class-subject mappings for dynamic dropdown filtering
$stmt_cs_map = $db->prepare("
    SELECT cs.class_id, s.id as subject_id, s.name_en, s.code,
           cs.th_credit_hours, cs.in_credit_hours, cs.th_full_marks, cs.th_pass_marks, cs.in_full_marks, cs.in_pass_marks,
           s.th_credit_hours as s_th_cr, s.in_credit_hours as s_in_cr, s.th_full_marks as s_th_fm, s.th_pass_marks as s_th_pm, s.in_full_marks as s_in_fm, s.in_pass_marks as s_in_pm
    FROM class_subjects cs
    JOIN subjects s ON cs.subject_id = s.id
    JOIN classes c ON cs.class_id = c.id
    WHERE (cs.school_id = ?) AND c.is_active = 1 AND cs.is_active=1 AND (s.is_hidden = 0 OR s.is_hidden IS NULL)
");
$stmt_cs_map->execute([$school_id]);
$class_subject_mappings = $stmt_cs_map->fetchAll(PDO::FETCH_ASSOC);

$class_subjects_json = [];
foreach ($class_subject_mappings as $mapping) {
    $class_subjects_json[$mapping['class_id']][] = [
        'id' => $mapping['subject_id'],
        'name' => ($mapping['code'] ? '[' . $mapping['code'] . '] ' : '') . $mapping['name_en'],
        'th_credit' => ($mapping['th_credit_hours'] !== null && $mapping['th_credit_hours'] !== '') ? $mapping['th_credit_hours'] : $mapping['s_th_cr'],
        'in_credit' => ($mapping['in_credit_hours'] !== null && $mapping['in_credit_hours'] !== '') ? $mapping['in_credit_hours'] : $mapping['s_in_cr'],
        'th_full' => ($mapping['th_full_marks'] !== null && $mapping['th_full_marks'] !== '') ? $mapping['th_full_marks'] : ($mapping['s_th_fm'] ?: 75),
        'th_pass' => ($mapping['th_pass_marks'] !== null && $mapping['th_pass_marks'] !== '') ? $mapping['th_pass_marks'] : ($mapping['s_th_pm'] ?: 26.25),
        'in_full' => ($mapping['in_full_marks'] !== null && $mapping['in_full_marks'] !== '') ? $mapping['in_full_marks'] : ($mapping['s_in_fm'] ?: 25),
        'in_pass' => ($mapping['in_pass_marks'] !== null && $mapping['in_pass_marks'] !== '') ? $mapping['in_pass_marks'] : ($mapping['s_in_pm'] ?: 10),
    ];
}

// Fetch mapped subjects grouped by Class
$mapped_subjects = $db->prepare("
    SELECT es.*, c.name as class_name, sub.name_en as subject_name, sub.code as subject_code, sub.credit_hours as default_credit
    FROM exam_subjects es
    JOIN classes c ON es.class_id = c.id
    JOIN subjects sub ON es.subject_id = sub.id
    WHERE es.exam_id = ?
    ORDER BY c.name, sub.name_en
");
$mapped_subjects->execute([$exam_id]);
$mappings = schoolExamOfferingRows($db,(int)$school_id,$exam,$mapped_subjects->fetchAll());

// Group mappings by class
$grouped_mappings = [];
foreach ($mappings as $m) {
    $grouped_mappings[$m['class_name']][] = $m;
}


$pageTitle = 'Configure Exam: ' . $exam['name'];
$activePage = 'exams';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2><i class="fas fa-cog text-primary"></i> Configure Exam Subjects</h2>
        <h5 class="text-muted"><?= sanitize($exam['name']) ?></h5>
    </div>
    <a href="<?= schoolRouteUrl('modules/exams/index.php') ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success mb-4"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if($automaticExam): ?><div class="alert alert-info">Automatic exam · saved default revision <?= (int)$exam['template_revision'] ?>. Name, type, result system and subject / TH / IN settings are read-only. Marks, schedules, symbols, printing and lock/reopen remain available.</div>
<?php if(!$exam['is_locked']&&!$exam['is_published']&&Auth::hasRole(['super_admin','school_admin','exam_officer'])): ?><form method="POST" class="card card-body mb-4"><label for="templateInitClass" class="form-label">Load saved defaults for an additional class</label><select id="templateInitClass" name="init_class_id" class="form-select mb-2" required><option value="">Choose class</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= sanitize($c['name']) ?></option><?php endforeach; ?></select><button name="initialize_defaults" value="1" class="btn btn-outline-primary">Auto-initialize Subjects from saved template</button></form><?php endif; ?><?php endif; ?>

<div class="row">
    <!-- Configuration & Utility Panel -->
    <div class="col-md-4 mb-4">
        <!-- Add/Edit Subject Configuration Card -->
        <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer']) && !$configurationFrozen): ?>
        <div class="card mb-4 shadow-sm border-start border-primary border-3">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-0"><i class="fas <?= $edit_mapping ? 'fa-edit' : 'fa-plus' ?>"></i> <?= $edit_mapping ? 'Edit Subject Details' : 'Add Subject Configuration' ?></h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="map_subject" value="1">
                    <?php if ($edit_mapping): ?>
                    <input type="hidden" name="edit_mapping_id" value="<?= $edit_mapping['id'] ?>">
                    <input type="hidden" name="mapping_revision" value="<?= examMappingRevision($edit_mapping) ?>">
                    <div class="alert alert-warning">Changing limits or credits can change saved grades and GPA. Entered marks will not be rescaled or deleted; incompatible limits will be rejected. Changes are recorded in the audit history.</div>
                    <label class="form-check mb-3"><input type="checkbox" class="form-check-input" name="confirm_grade_change" value="1" required> I reviewed the impact and confirm this modification.</label>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="class_id" class="form-label">Select Class *</label>
                        <select class="form-select" id="class_id" name="class_id" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($edit_mapping && $edit_mapping['class_id'] == $c['id']) ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="subject_id" class="form-label">Select Subject *</label>
                        <select class="form-select" id="subject_id" name="subject_id" required>
                            <option value="">Choose Class First</option>
                        </select>
                    </div>

                    <?php if ($exam['type'] === 'unit'): ?>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-success font-weight-bold">Full Marks *</label>
                            <input type="number" name="theory_full" class="form-control" value="<?= $edit_mapping ? $edit_mapping['theory_full'] : 100 ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-success font-weight-bold">Pass Marks *</label>
                            <input type="number" name="theory_pass" class="form-control" value="<?= $edit_mapping ? $edit_mapping['theory_pass'] : 40 ?>" required>
                        </div>
                    </div>
                    <input type="hidden" name="practical_full" value="0">
                    <input type="hidden" name="practical_pass" value="0">
                    <input type="hidden" name="credit_hours" value="">
                    <input type="hidden" name="internal_credit_hours" value="">
                    <input type="hidden" name="external_credit_hours" value="">
                    <?php else: ?>
                    <div class="mb-3">
                        <label for="credit_hours" class="form-label text-info font-weight-bold">Total Credit Hours</label>
                        <input type="number" step="any" name="credit_hours" id="credit_hours" class="form-control" placeholder="Default subject credit hours" value="<?= $edit_mapping ? sanitize($edit_mapping['credit_hours'] ?? '') : '' ?>">
                        <div class="form-text text-muted">Leave blank to use the subject's default credit hours from the subjects tab.</div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="internal_credit_hours" class="form-label text-info font-weight-bold">Internal Credit Hours</label>
                            <input type="number" step="any" name="internal_credit_hours" id="internal_credit_hours" class="form-control" value="<?= $edit_mapping ? sanitize($edit_mapping['internal_credit_hours'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-info font-weight-bold">External Credit Hours</label>
                            <input type="number" step="any" name="external_credit_hours" id="external_credit_hours" class="form-control" value="<?= $edit_mapping ? sanitize($edit_mapping['external_credit_hours'] ?? '') : '' ?>">
                        </div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-success">Theory Full *</label>
                            <input type="number" step="any" name="theory_full" id="form_theory_full" class="form-control" value="<?= $edit_mapping ? $edit_mapping['theory_full'] : 75 ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-success">Theory Pass *</label>
                            <input type="number" step="any" name="theory_pass" id="form_theory_pass" class="form-control" value="<?= $edit_mapping ? $edit_mapping['theory_pass'] : 30 ?>" required>
                        </div>
                    </div>
                    
                    <?php if ($exam['has_internal']): ?>
                    <div class="row g-2 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-warning">Internal (IN) Full *</label>
                            <input type="number" step="any" name="practical_full" id="form_practical_full" class="form-control" value="<?= $edit_mapping ? $edit_mapping['practical_full'] : 25 ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-warning">Internal (IN) Pass *</label>
                            <input type="number" step="any" name="practical_pass" id="form_practical_pass" class="form-control" value="<?= $edit_mapping ? $edit_mapping['practical_pass'] : 10 ?>" required>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-secondary py-2 small mb-3">
                        <i class="fas fa-info-circle"></i> Internal evaluation component is disabled for this exam.
                    </div>
                    <input type="hidden" name="practical_full" value="0">
                    <input type="hidden" name="practical_pass" value="0">
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save"></i> <?= $edit_mapping ? 'Update Configuration' : 'Map Subject' ?>
                    </button>
                    
                    <?php if ($edit_mapping): ?>
                    <a href="<?= schoolRouteUrl('modules/exams/view.php') ?>?id=<?= $exam_id ?>" class="btn btn-secondary w-100 mt-2">
                        <i class="fas fa-times"></i> Cancel Edit
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-success text-white py-3">
                <h5 class="mb-0"><i class="fas fa-magic"></i> Auto-initialize Subjects</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <p class="text-muted small">Select a class to automatically load all subjects mapped to it in class configurations, including visible optional subjects, using each subject’s configured TH/IN marks and credits. Existing mappings are kept.</p>
                    <div class="mb-3">
                        <label for="init_class_id" class="form-label">Select Class *</label>
                        <select class="form-select" id="init_class_id" name="init_class_id" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="initialize_defaults" class="btn btn-success w-100">
                        <i class="fas fa-sync"></i> Initialize Class Subjects
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <a class="btn btn-outline-primary w-100 mb-3" href="<?= schoolRouteUrl('modules/exams/symbols.php') ?>?exam_id=<?= $exam_id ?>">Manage Exam Symbol Numbers</a>
        <!-- Print Admit Cards Card -->
        <div class="card mb-4 shadow-sm border-start border-info border-3">
            <div class="card-header bg-info bg-opacity-10 text-info-emphasis py-3">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-print"></i> Print Admit Cards</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">Select a class, then choose all students or search and select individual students. Prints up to 4 cards per A4 page.</p>
                <div class="mb-3">
                    <label for="print_class_id" class="form-label font-weight-bold">Select Class *</label>
                    <select class="form-select" id="print_class_id" required>
                        <option value="">Choose Class</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" data-erp-click="exams-view-action-0" data-erp-click-args="<?= schoolPageActionArgs("exams-view",[]) ?>" class="btn btn-info text-white w-100 fw-bold">
                    <i class="fas fa-id-card"></i> Generate & Print Cards
                </button>
            </div>
        </div>

        <!-- Signature Uploads Card -->
        <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])): ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="mb-0"><i class="fas fa-signature"></i> Signature Uploads</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="upload_signatures" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Class Teacher Signature</label>
                        <?php if (!empty($school['class_teacher_signature'])): ?>
                        <div class="mb-2 text-center bg-light p-2 border rounded">
                            <img src="<?= APP_URL ?>/uploads/<?= $school['class_teacher_signature'] ?>" alt="Class Teacher Signature" class="img-fluid" style="max-height: 40px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="class_teacher_sig" class="form-control" accept="image/png, image/jpeg, image/jpg">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Exam Coordinator Signature</label>
                        <?php if (!empty($school['exam_coordinator_signature'])): ?>
                        <div class="mb-2 text-center bg-light p-2 border rounded">
                            <img src="<?= APP_URL ?>/uploads/<?= $school['exam_coordinator_signature'] ?>" alt="Exam Coordinator Signature" class="img-fluid" style="max-height: 40px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="exam_coordinator_sig" class="form-control" accept="image/png, image/jpeg, image/jpg">
                    </div>

                    <button type="submit" class="btn btn-dark w-100">
                        <i class="fas fa-upload"></i> Upload Signatures
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Table of Configured Subjects -->
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-success"><i class="fas fa-clipboard-list"></i> Configured Subjects Grid</h5>
            </div>
            <div class="card-body">
                <?php if (count($grouped_mappings) > 0): ?>
                    <?php foreach ($grouped_mappings as $class_name => $class_mappings): ?>
                    <div class="mb-4">
                        <h6 class="text-primary bg-primary bg-opacity-10 p-2 rounded mb-3">
                            <i class="fas fa-school"></i> <?= sanitize($class_name) ?>
                        </h6>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Subject</th>
                                        <?php if ($exam['type'] === 'unit'): ?>
                                        <th class="text-center">Full Marks</th>
                                        <th class="text-center">Pass Marks</th>
                                        <?php else: ?>
                                        <th class="text-center">Credit Hours</th>
                                        <th class="text-center">Theory (Full/Pass)</th>
                                        <th class="text-center">Internal (Full/Pass)</th>
                                        <th class="text-center">Total Full Marks</th>
                                        <?php endif; ?>
                                        <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer']) && !$configurationFrozen): ?>
                                        <th class="text-end">Action</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($class_mappings as $m): ?>
                                    <tr>
                                        <td>
                                            <strong><?= sanitize($m['subject_name']) ?></strong> 
                                            <?php if(!$configurationFrozen&&!empty($exam['has_internal'])&&(float)$m['practical_full']>0): ?><a class="d-block small" href="<?= schoolRouteUrl('modules/subjects/internal.php') ?>?exam=<?= (int)$exam_id ?>&mapping=<?= (int)$m['id'] ?>">Edit IN fields & allocation</a><?php endif; ?>
                                            <span class="text-muted font-monospace small">(<?= sanitize($m['subject_code']) ?>)</span>
                                        </td>
                                        <?php if ($exam['type'] === 'unit'): ?>
                                        <td class="text-center font-monospace text-success">
                                            <strong><?= $m['theory_full'] ?></strong>
                                        </td>
                                        <td class="text-center font-monospace text-danger">
                                            <strong><?= $m['theory_pass'] ?></strong>
                                        </td>
                                        <?php else: ?>
                                        <td class="text-center font-monospace">
                                            <?= sanitize($m['credit_hours'] ?? $m['default_credit'] ?? 'N/A') ?><br>
                                            <small class="text-muted">
                                                (IN: <?= sanitize($m['internal_credit_hours'] ?? '-') ?>, EX: <?= sanitize($m['external_credit_hours'] ?? '-') ?>)
                                            </small>
                                        </td>
                                        <td class="text-center text-success">
                                            <?= $m['theory_full'] ?> / <?= $m['theory_pass'] ?>
                                        </td>
                                        <td class="text-center text-warning">
                                            <?= $m['practical_full'] ?> / <?= $m['practical_pass'] ?>
                                        </td>
                                        <td class="text-center font-monospace">
                                            <strong><?= $m['theory_full'] + $m['practical_full'] ?></strong>
                                        </td>
                                        <?php endif; ?>
                                        <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer']) && !$configurationFrozen): ?>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="<?= schoolRouteUrl('modules/exams/view.php') ?>?id=<?= $exam_id ?>&edit_mapping_id=<?= $m['id'] ?>"
                                                   class="btn btn-sm btn-outline-warning" title="Edit Configuration">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="<?= schoolRouteUrl('modules/exams/view.php') ?>?id=<?= $exam_id ?>&delete_mapping=<?= $m['id'] ?>"
                                                   class="btn btn-sm btn-outline-danger" title="Remove Subject">
                                                    <i class="fas fa-times-circle"></i> Remove
                                                </a>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-exclamation-triangle fa-3x text-muted mb-3"></i>
                        <p class="text-muted mb-0">No subjects mapped to this exam yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php schoolPageModule("exams-view");schoolPageValue("exams-view-value-0",(string)(schoolRouteUrl('modules/exams/admit_cards.php')));
schoolPageValue("exams-view-value-1",(string)($exam_id));
schoolPageValue("exams-view-value-2",(string)(json_encode($class_subjects_json,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)));
schoolPageValue("exams-view-value-3",(string)($edit_mapping ? json_encode($edit_mapping) : 'null')); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
