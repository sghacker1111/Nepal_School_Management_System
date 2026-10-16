<?php
require_once __DIR__.'/frontend.php';
$token = Auth::generateCSRFToken();
$content = schoolProtectForms($content ?? '', $token);
$layoutSchoolId = Auth::getSchoolId();
$layoutSchool = null;
$layoutYear = null;
if ($layoutSchoolId) {
    $q = getDB()->prepare('SELECT name_en FROM schools WHERE id=?'); $q->execute([$layoutSchoolId]); $layoutSchool=$q->fetchColumn();
    $q = getDB()->prepare('SELECT year_bs, year_ad FROM academic_years WHERE school_id=? AND is_active=1'); $q->execute([$layoutSchoolId]); $layoutYear=$q->fetch();
}
$layoutRole = Auth::getRole();
$adminRoles = ['school_admin','super_admin'];
$groups = [];
$correctionCounts=['pending'=>0,'unread'=>0];
if(in_array($layoutRole,['super_admin','school_admin','teacher'],true)){
    require_once __DIR__.'/question_correction_service.php';
    $correctionCounts=questionCorrectionCounts(getDB(),learningActor(getDB(),(int)Auth::getUserId(),(int)$layoutSchoolId,Auth::isReadOnly()));
}
if ($layoutRole === 'super_admin') {
    $groups['System'] = [
        ['super_admin_dashboard','Control center','shield-halved','/modules/super_admin/index.php',''],
        ['super_admin_schools','Schools','school','/modules/super_admin/schools.php',''],
        ['super_admin_users','User management','users-gear','/modules/super_admin/users.php',''],
        ['super_admin_subjects','CDC catalog','book-open','/modules/super_admin/subjects.php',''],
        ['super_admin_exams','Default exams','file-lines','/modules/super_admin/exam_templates.php',''],
        ['super_admin_corrections','Correction Questions ('.$correctionCounts['pending'].')','comment-medical','/modules/super_admin/question_corrections.php',''],
        ['cdc_grading','CDC 2083 grading','graduation-cap','/modules/settings/cdc.php',''],
    ];
}
if ($layoutRole !== 'super_admin' || $layoutSchoolId) {
    $groups['School'] = [
        ['dashboard','Dashboard','house','/index.php',''],
        ['students','Students','user-graduate','/modules/students/index.php','students'],
        ['teachers','Teachers','chalkboard-user','/modules/teachers/index.php','teachers'],
        ['attendance','Attendance','calendar-check','/modules/attendance/index.php','attendance'],
    ];
}
$groups['Academics'] = [
    ['classes','Classes & sections','layer-group','/modules/classes/index.php','classes'],
    ['subjects','Subjects','book','/modules/subjects/index.php','subjects'],
    ['routine','Weekly routine','calendar-days','/modules/classes/routine.php','classes'],
    ['exams','Exams','file-lines','/modules/exams/index.php','exams'],
    ['results','Marks & results','chart-simple','/modules/results/index.php','results'],
    ['reports','Reports','chart-pie','/modules/reports/index.php','reports'],
];
$groups['Learning'] = [
    ['questions','Question bank','database','/modules/learning/questions.php','questions'],
    ['paper_generator','Paper generator','file-pen','/modules/learning/paper_generator.php','paper_generator'],
    ['specification_grids','Specification grids','table-cells','/modules/learning/specification_grids.php','specification_grids'],
    ['curriculum','Curriculum & topics','sitemap','/modules/learning/curriculum.php','curriculum'],
    ['learning','Learning hub','book-open-reader','/modules/learning/index.php','learning'],
    ['practice_mcqs','Practice MCQs','circle-check','/modules/learning/practice_mcqs.php','learning'],
    ['ocr_scanner','Paper scanner','camera','/modules/learning/ocr_scanner.php','ocr_scanner'],
];
if(in_array($layoutRole,['super_admin','school_admin','teacher'],true))$groups['Exam papers']=[
    ['exam_questions','Exam Questions','file-circle-question','/modules/learning/exam_questions.php','exam_questions'],
    ['exam_mcqs','Multiple Choice Questions','list-check','/modules/learning/exam_mcqs.php','exam_questions'],
    ['exam_paper_generator','Exam Paper Generator','file-pen','/modules/learning/exam_paper_generator.php','exam_paper_generator'],
    ['exam_specification_grids','Exam Paper Grids','table-cells','/modules/learning/exam_specification_grids.php','exam_specification_grids'],
];
if($layoutSchoolId&&in_array($layoutRole,['school_admin','teacher'],true))$groups['Learning'][]=['question_corrections','My correction requests ('.$correctionCounts['unread'].' unread)','comment-dots','/modules/learning/question_corrections.php',''];
if($layoutSchoolId&&Auth::hasAccess('accounting')&&in_array($layoutRole,['super_admin','school_admin','accountant','cashier','auditor'],true))$groups['Accounting & Billing']=[['accounting','Accounting overview','coins','/modules/accounting/index.php','accounting'],['accounting_fees','Fee management','tags','/modules/accounting/fees.php','accounting'],['accounting_bill','Create manual bill','file-invoice','/modules/accounting/create_bill.php','accounting'],['accounting_invoices','Invoices & dues','file-lines','/modules/accounting/invoices.php','accounting'],['accounting_generate','Generate fee bills','calendar','/modules/accounting/generate.php','accounting'],['accounting_payment','Collect payment','money-bill','/modules/accounting/payments.php','accounting'],['accounting_student','Student accounts','user-graduate','/modules/accounting/student.php','accounting'],['accounting_subscription','Subscriptions','repeat','/modules/accounting/subscriptions.php','accounting'],['accounting_expenses','Expenses','receipt','/modules/accounting/expenses.php','accounting'],['accounting_journals','Accounts & journals','book','/modules/accounting/journals.php','accounting'],['accounting_reports','Financial reports','chart-pie','/modules/accounting/reports.php','accounting'],['accounting_settings','Settings & audit','sliders','/modules/accounting/settings.php','accounting']];
if ($layoutSchoolId && in_array($layoutRole,$adminRoles,true)) {
    $groups['Administration'] = [
        ['users','User accounts','users-gear','/modules/settings/users.php','settings'],
        ['school_settings','School settings','sliders','/modules/settings/school.php','settings'],
        ['settings','Academic years','calendar','/modules/settings/years.php','settings'],
        ['cdc_grading','CDC 2083 grading','graduation-cap','/modules/settings/cdc.php','settings'],
    ];
}
if($layoutRole==='student')$groups=['Learning'=>[
    ['learning','Learning hub','book-open-reader','/modules/learning/index.php','learning'],
    ['practice_mcqs','Practice MCQs','circle-check','/modules/learning/practice_mcqs.php','learning'],
    ['exam_questions','Released Exam Questions','file-circle-question','/modules/learning/exam_questions.php','exam_questions'],
    ['exam_mcqs','Released Multiple Choice Questions','list-check','/modules/learning/exam_mcqs.php','exam_questions'],
]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($token) ?>">
<title><?= sanitize($pageTitle ?? 'Dashboard') ?> · <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= schoolAsset('app.css') ?>">
<link rel="stylesheet" href="<?= schoolAsset('icons.css') ?>">
<link rel="stylesheet" href="<?= schoolAsset('vendor.css') ?>">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/workspace.css">
<?php foreach($pageStyles??[] as $stylesheet): ?><link rel="stylesheet" href="<?= htmlspecialchars(APP_URL.$stylesheet,ENT_QUOTES,'UTF-8') ?>"><?php endforeach; ?>
<script>window.APP_URL=<?= json_encode(APP_URL,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;window.CSRF_TOKEN=<?= json_encode($token) ?>;</script>
<script src="<?= schoolAsset('vendor.js') ?>"></script>
<script src="<?= schoolAsset('ui-components.js') ?>"></script>
<script src="<?= APP_URL ?>/assets/js/request-security.js"></script>
<script defer src="<?= schoolAsset('workspace.js') ?>"></script>
</head>
<body x-data="workspace" @keydown.escape.window="closeAll" hx-history="false">
<div id="erpProgress" class="erp-progress" aria-hidden="true"></div>
<p id="erpUpdateStatus" class="visually-hidden" role="status" aria-live="polite"></p>
<a class="visually-hidden-focusable skip-link" href="#main-content">Skip to content</a>
<header class="top-navbar">
    <button type="button" class="btn btn-light d-md-none" @click="toggleNavigation" :aria-expanded="navigationOpen" aria-controls="sidebar" aria-label="Open navigation"><i class="fa-solid fa-bars"></i></button>
    <div class="workspace-heading"><span class="workspace-product">NEPAL SCHOOL ERP</span><strong><?= sanitize($layoutSchool ?: 'System administration') ?></strong></div>
    <div class="ms-auto d-flex align-items-center gap-2">
        <?php if ($layoutYear): ?><span class="year-chip d-none d-sm-inline" title="Current academic year"><?= sanitize($layoutYear['year_bs']) ?> BS · <?= sanitize($layoutYear['year_ad']) ?> AD</span><?php endif; ?>
        <?php if ($layoutRole==='super_admin'): ?>
        <div class="dropdown" @click.outside="closeSchools"><button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" @click="toggleSchools" :aria-expanded="schoolsOpen" aria-controls="schoolPreviewMenu">School preview</button><ul id="schoolPreviewMenu" class="dropdown-menu dropdown-menu-end" :class="{ 'show': schoolsOpen }" x-cloak>
        <li><a class="dropdown-item" href="<?= schoolRouteUrl('modules/super_admin/switch_school.php') ?>?school_id=0">System administration</a></li>
        <?php foreach (getDB()->query('SELECT id,name_en FROM schools ORDER BY name_en') as $s): ?><li><a class="dropdown-item" href="<?= schoolRouteUrl('modules/super_admin/switch_school.php') ?>?school_id=<?= (int)$s['id'] ?>"><?= sanitize($s['name_en']) ?></a></li><?php endforeach; ?>
        </ul></div><?php endif; ?>
        <div class="dropdown" @click.outside="closeAccount"><button type="button" class="btn btn-light dropdown-toggle" @click="toggleAccount" :aria-expanded="accountOpen" aria-controls="accountMenu" aria-label="Account menu"><i class="fa-regular fa-circle-user"></i> <span class="d-none d-lg-inline"><?= sanitize($_SESSION['full_name'] ?? 'Account') ?></span></button>
        <ul id="accountMenu" class="dropdown-menu dropdown-menu-end" :class="{ 'show': accountOpen }" x-cloak><li class="dropdown-header"><?= sanitize(ucwords(str_replace('_',' ',$layoutRole))) ?></li><li><a class="dropdown-item" href="<?= schoolRouteUrl('modules/auth/profile.php') ?>">My profile</a></li><li><a class="dropdown-item" href="<?= schoolRouteUrl('modules/auth/change_password.php') ?>">Change password</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="<?= schoolRouteUrl('modules/auth/logout.php') ?>">Sign out</a></li></ul></div>
    </div>
</header>
<div class="erp-navigation-overlay d-md-none" x-show="navigationOpen" x-cloak @click="closeNavigation" aria-hidden="true"></div>
<nav class="sidebar erp-sidebar" :class="{ 'is-open': navigationOpen }" id="sidebar" tabindex="-1" aria-label="Main navigation">
    <div class="erp-nav-close"><h5>Navigation</h5><button type="button" class="btn-close btn-close-white" @click="closeNavigation" aria-label="Close navigation"></button></div>
    <div class="sidebar-search"><label class="visually-hidden" for="navigationSearch">Find a section</label><input id="navigationSearch" type="search" placeholder="Find a section…" autocomplete="off" x-model="search" @input.debounce.120ms="filterNavigation"></div>
    <?php foreach ($groups as $group=>$items): ?><section class="nav-group"><?php if($group==='Accounting & Billing'): ?><h2><button type="button" class="btn btn-sm text-white p-0" data-ui-toggle="collapse" data-ui-target="#accountingNav" aria-expanded="<?= str_starts_with($activePage??'','accounting')?'true':'false' ?>">Accounting & Billing <i class="fa-solid fa-chevron-down"></i></button></h2><?php else: ?><h2><?= $group ?></h2><?php endif; ?><ul <?= $group==='Accounting & Billing'?'id="accountingNav" data-active="'.(str_starts_with($activePage??'','accounting')?'true':'false').'"':'' ?> class="nav flex-column <?= $group==='Accounting & Billing'?'collapse '.(str_starts_with($activePage??'','accounting')?'show':''):'' ?>">
    <?php foreach ($items as [$key,$label,$icon,$path,$module]):
        if ($module && !Auth::hasAccess($module)) continue;
        if ($layoutRole==='super_admin' && !$layoutSchoolId && in_array($key,['routine','exams','results','reports'],true)) continue;
        if ($layoutRole==='teacher' && in_array($key,['teachers','exams','reports'],true)) continue;
        if ($layoutRole==='exam_officer' && in_array($key,['teachers','attendance','reports'],true)) continue;
        if (in_array($layoutRole,['accountant','cashier','auditor'],true) && $key!=='dashboard' && $module!=='accounting') continue;
        if(in_array($layoutRole,['cashier','auditor'],true)&&in_array($key,['accounting_bill','accounting_generate','accounting_subscription'],true))continue;
        if($layoutRole==='auditor'&&$key==='accounting_payment')continue;
    ?><li class="nav-item"><a class="nav-link <?= ($activePage??'')===$key?'active':'' ?>" <?= ($activePage??'')===$key?'aria-current="page"':'' ?> href="<?= sanitize(schoolCanonicalUrl(APP_URL.$path)??APP_URL.$path) ?>"><i class="fa-solid fa-<?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span></a></li><?php endforeach; ?>
    </ul></section><?php endforeach; ?>
</nav>
<main class="content-wrapper tw:transition-colors" id="main-content">
    <?php if (Auth::isReadOnly()): ?><div class="alert alert-warning no-print">School preview is read-only. Records cannot be changed from this session.</div><?php endif; ?>
    <?= displayFlashMessage() ?>
    <?= $content ?>
</main>
<script src="<?= APP_URL ?>/assets/js/nepali-date-converter.js"></script>
<script src="<?= APP_URL ?>/assets/js/school-dates.js"></script>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script src="<?= APP_URL ?>/assets/js/notifications.js"></script>
<script>
<?php if(Auth::isReadOnly()): ?>document.querySelectorAll('form[method="POST" i] input, form[method="POST" i] select, form[method="POST" i] textarea, form[method="POST" i] button').forEach(el=>el.disabled=true);<?php endif; ?>
</script>
</body></html>
