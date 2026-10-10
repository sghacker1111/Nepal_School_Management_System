<?php
$token = Auth::generateCSRFToken();
$csrfField = '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.htmlspecialchars($token, ENT_QUOTES, 'UTF-8').'">';
$content = preg_replace_callback('/<form\\b[^>]*\\bmethod\\s*=\\s*["\\\']?post["\\\']?[^>]*>/i', static fn($m) => $m[0].$csrfField, $content ?? '');
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
if ($layoutRole === 'super_admin') {
    $groups['System'] = [
        ['super_admin_dashboard','Control center','shield-halved','/modules/super_admin/index.php',''],
        ['super_admin_schools','Schools','school','/modules/super_admin/schools.php',''],
        ['super_admin_users','User management','users-gear','/modules/super_admin/users.php',''],
        ['super_admin_subjects','CDC catalog','book-open','/modules/super_admin/subjects.php',''],
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
    ['questions','Question bank','database','/modules/learning/questions.php','learning'],
    ['paper_generator','Paper generator','file-pen','/modules/learning/paper_generator.php','learning'],
    ['specification_grids','Specification grids','table-cells','/modules/learning/specification_grids.php','learning'],
    ['curriculum','Curriculum & topics','sitemap','/modules/learning/curriculum.php','learning'],
    ['learning','Learning hub','book-open-reader','/modules/learning/index.php','learning'],
    ['ocr_scanner','Paper scanner','camera','/modules/learning/ocr_scanner.php','learning'],
];
if ($layoutSchoolId && in_array($layoutRole,$adminRoles,true)) {
    $groups['Administration'] = [
        ['users','Staff accounts','users-gear','/modules/settings/users.php','settings'],
        ['school_settings','School settings','sliders','/modules/settings/school.php','settings'],
        ['settings','Academic years','calendar','/modules/settings/years.php','settings'],
        ['cdc_grading','CDC 2083 grading','graduation-cap','/modules/settings/cdc.php','settings'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($token) ?>">
<title><?= sanitize($pageTitle ?? 'Dashboard') ?> · <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/workspace.css">
<script>window.APP_URL=<?= json_encode(APP_URL,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;window.CSRF_TOKEN=<?= json_encode($token) ?>;</script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= APP_URL ?>/assets/js/request-security.js"></script>
</head>
<body>
<a class="visually-hidden-focusable skip-link" href="#main-content">Skip to content</a>
<header class="top-navbar">
    <button class="btn btn-light d-md-none" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open navigation"><i class="fa-solid fa-bars"></i></button>
    <div class="workspace-heading"><span class="workspace-product">NEPAL SCHOOL ERP</span><strong><?= sanitize($layoutSchool ?: 'System administration') ?></strong></div>
    <div class="ms-auto d-flex align-items-center gap-2">
        <?php if ($layoutYear): ?><span class="year-chip d-none d-sm-inline" title="Current academic year"><?= sanitize($layoutYear['year_bs']) ?> BS · <?= sanitize($layoutYear['year_ad']) ?> AD</span><?php endif; ?>
        <?php if ($layoutRole==='super_admin'): ?>
        <div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">School preview</button><ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/super_admin/switch_school.php?school_id=0">System administration</a></li>
        <?php foreach (getDB()->query('SELECT id,name_en FROM schools ORDER BY name_en') as $s): ?><li><a class="dropdown-item" href="<?= APP_URL ?>/modules/super_admin/switch_school.php?school_id=<?= (int)$s['id'] ?>"><?= sanitize($s['name_en']) ?></a></li><?php endforeach; ?>
        </ul></div><?php endif; ?>
        <div class="dropdown"><button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown"><i class="fa-regular fa-circle-user"></i> <span class="d-none d-lg-inline"><?= sanitize($_SESSION['full_name'] ?? 'Account') ?></span></button>
        <ul class="dropdown-menu dropdown-menu-end"><li class="dropdown-header"><?= sanitize(ucwords(str_replace('_',' ',$layoutRole))) ?></li><li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/profile.php">My profile</a></li><li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/change_password.php">Change password</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/logout.php">Sign out</a></li></ul></div>
    </div>
</header>
<nav class="offcanvas-md offcanvas-start sidebar" id="sidebar" tabindex="-1" aria-label="Main navigation">
    <div class="offcanvas-header"><h5>Navigation</h5><button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close navigation"></button></div>
    <div class="sidebar-search"><label class="visually-hidden" for="navigationSearch">Find a section</label><input id="navigationSearch" type="search" placeholder="Find a section…" autocomplete="off"></div>
    <?php foreach ($groups as $group=>$items): ?><section class="nav-group"><h2><?= $group ?></h2><ul class="nav flex-column">
    <?php foreach ($items as [$key,$label,$icon,$path,$module]):
        if ($module && !Auth::hasAccess($module)) continue;
        if ($layoutRole==='super_admin' && !$layoutSchoolId && in_array($key,['routine','exams','results','reports'],true)) continue;
        if ($layoutRole==='teacher' && in_array($key,['teachers','exams','reports'],true)) continue;
        if ($layoutRole==='exam_officer' && in_array($key,['teachers','attendance','reports'],true)) continue;
        if ($layoutRole==='accountant' && $key!=='dashboard') continue;
    ?><li class="nav-item"><a class="nav-link <?= ($activePage??'')===$key?'active':'' ?>" <?= ($activePage??'')===$key?'aria-current="page"':'' ?> href="<?= APP_URL.$path ?>"><i class="fa-solid fa-<?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span></a></li><?php endforeach; ?>
    </ul></section><?php endforeach; ?>
</nav>
<main class="content-wrapper" id="main-content">
    <?php if (Auth::isReadOnly()): ?><div class="alert alert-warning no-print">School preview is read-only. Records cannot be changed from this session.</div><?php endif; ?>
    <?= displayFlashMessage() ?>
    <?= $content ?>
</main>
<script src="<?= APP_URL ?>/assets/js/nepali-date-converter.js"></script>
<script src="<?= APP_URL ?>/assets/js/school-dates.js"></script>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script src="<?= APP_URL ?>/assets/js/notifications.js"></script>
<script>
document.getElementById('navigationSearch').addEventListener('input', function() {
    const query=this.value.trim().toLowerCase();
    document.querySelectorAll('.nav-group').forEach(group=>{
        let count=0; group.querySelectorAll('.nav-item').forEach(item=>{item.hidden=!item.textContent.toLowerCase().includes(query);if(!item.hidden)count++;});
        group.hidden=count===0;
    });
});
<?php if(Auth::isReadOnly()): ?>document.querySelectorAll('form[method="POST" i] input, form[method="POST" i] select, form[method="POST" i] textarea, form[method="POST" i] button').forEach(el=>el.disabled=true);<?php endif; ?>
</script>
</body></html>
