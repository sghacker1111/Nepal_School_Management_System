<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Dashboard' ?> - <?= APP_NAME ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= APP_URL ?>/assets/css/style.css" rel="stylesheet">
    <script>window.APP_URL = "<?= APP_URL ?>";</script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            border-left: 3px solid transparent;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: #fff;
            background: rgba(255,255,255,0.1);
            border-left-color: #3498db;
        }
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        .top-navbar {
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .content-wrapper {
            background: #f8f9fa;
            min-height: calc(100vh - 56px);
        }
        .card {
            border: none;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
        }
        .stat-card {
            border-left: 4px solid;
        }
        .stat-card.primary { border-left-color: #3498db; }
        .stat-card.success { border-left-color: #2ecc71; }
        .stat-card.warning { border-left-color: #f39c12; }
        .stat-card.danger { border-left-color: #e74c3c; }
        
        @media print {
            .sidebar, .top-navbar, .no-print { display: none !important; }
            .content-wrapper { background: #fff; }
        }

        /* MathJax Devanagari / Nepali Script Font Support */
        mjx-utext, mjx-mtext, .MathJax mtext, .MathJax [class*="mtext"] {
            font-family: 'Noto Sans Devanagari', 'Segoe UI', 'Kalimati', sans-serif !important;
            font-style: normal !important;
        }

        <?php if (Auth::isReadOnly()): ?>
        /* Hide actions and write buttons in read-only school viewer mode */
        .btn-primary:not(#sidebarToggle):not(.btn-info):not(form[method="GET"] *),
        .btn-success:not(form[method="GET"] *),
        .btn-danger,
        .btn-warning:not(.dropdown-toggle),
        .btn-outline-primary,
        .btn-outline-success,
        .btn-outline-warning,
        .btn-outline-danger,
        a[href*="delete"],
        a[href*="add"],
        button[onclick*="showAddModal"],
        button[onclick*="edit"],
        button[onclick*="delete"],
        form[method="POST"] button[type="submit"] {
            display: none !important;
        }
        <?php endif; ?>
    </style>
    <?php if (Auth::isReadOnly()): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Disable all form controls inside POST forms
            document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(form) {
                form.querySelectorAll('input, select, textarea, button').forEach(function(el) {
                    el.disabled = true;
                });
            });
            // Also disable any independent inputs/buttons that might submit
            document.querySelectorAll('input[type="submit"], button[type="submit"]').forEach(function(el) {
                el.disabled = true;
            });
            // Stop form submissions if any slip through
            document.querySelectorAll('form').forEach(function(form) {
                if (form.getAttribute('method') && form.getAttribute('method').toUpperCase() === 'POST') {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        alert('Form submission disabled in read-only mode.');
                        return false;
                    });
                }
            });
        });
    </script>
    <?php endif; ?>
</head>
<body>
    <!-- Top Navbar -->
    <nav class="navbar top-navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <button class="btn btn-link no-print d-md-none" id="sidebarToggle" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar">
                <i class="fas fa-bars"></i>
            </button>
            
            <span class="navbar-brand mb-0 h1 ms-3 ms-md-0"><?= APP_NAME ?></span>
            
            <div class="ms-auto d-flex align-items-center">
                <?php
                $db = getDB();
                $school_id = Auth::getSchoolId();
                if ($school_id):
                    $stmt = $db->prepare("SELECT * FROM academic_years WHERE school_id = ? AND is_active = 1 LIMIT 1");
                    $stmt->execute([$school_id]);
                    $year = $stmt->fetch();
                ?>
                <div class="dropdown me-3">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-calendar"></i> 
                        <?= $year ? getAcademicYearLabel($year['year_bs'], $year['year_ad']) : '2081 / 2024' ?>
                    </button>
                    <ul class="dropdown-menu">
                        <?php
                        $stmt = $db->prepare("SELECT * FROM academic_years WHERE school_id = ? AND (is_hidden = 0 OR is_active = 1) ORDER BY year_ad DESC");
                        $stmt->execute([$school_id]);
                        while ($y = $stmt->fetch()):
                        ?>
                        <li><a class="dropdown-item" href="#"><?= getAcademicYearLabel($y['year_bs'], $y['year_ad']) ?></a></li>
                        <?php endwhile; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php
                if (Auth::getRole() === 'super_admin'):
                    $stmt_sch = $db->query("SELECT id, name_en FROM schools ORDER BY name_en");
                    $view_schools = $stmt_sch->fetchAll();
                    $viewing_school_id = $_SESSION['viewing_school_id'] ?? null;
                    
                    $active_school_name = 'System Admin';
                    foreach ($view_schools as $sch) {
                        if ($viewing_school_id == $sch['id']) {
                            $active_school_name = 'Viewing: ' . $sch['name_en'];
                            break;
                        }
                    }
                ?>
                <div class="dropdown me-3 no-print">
                    <button class="btn btn-sm btn-warning dropdown-toggle fw-bold text-dark" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-eye"></i> <?= sanitize($active_school_name) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item <?= !$viewing_school_id ? 'active fw-bold' : '' ?>" href="<?= APP_URL ?>/modules/super_admin/switch_school.php?school_id=0"><i class="fas fa-user-shield me-2"></i>System Admin Panel</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php foreach ($view_schools as $sch): ?>
                        <li><a class="dropdown-item <?= $viewing_school_id == $sch['id'] ? 'active fw-bold' : '' ?>" href="<?= APP_URL ?>/modules/super_admin/switch_school.php?school_id=<?= $sch['id'] ?>"><i class="fas fa-school me-2"></i><?= sanitize($sch['name_en']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle"></i> <?= sanitize($_SESSION['full_name'] ?? 'User') ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/profile.php"><i class="fas fa-user"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/change_password.php"><i class="fas fa-key"></i> Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="offcanvas-md offcanvas-start sidebar pt-5" id="sidebar" tabindex="-1">
                <div class="position-sticky">
                    <ul class="nav flex-column">
                        <?php 
                        $sub_type = Auth::getSubscriptionType();
                        $show_exam_gen = ($sub_type === 'full' || $sub_type === 'exam_generator');
                        $show_results = ($sub_type === 'full' || $sub_type === 'result_system');
                        ?>
                        <?php if (Auth::getRole() === 'super_admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'super_admin_dashboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/super_admin/index.php">
                                <i class="fas fa-user-shield"></i> Control Center
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'super_admin_schools' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/super_admin/schools.php">
                                <i class="fas fa-university"></i> Manage Schools
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'super_admin_users' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/super_admin/users.php">
                                <i class="fas fa-users-cog"></i> School Admins
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'super_admin_subjects' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/super_admin/subjects.php">
                                <i class="fas fa-book-open"></i> Subjects & Chapters
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'classes' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/classes/index.php">
                                <i class="fas fa-layer-group"></i> Class & Section
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'questions' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/questions.php">
                                <i class="fas fa-database"></i> Question Bank
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'paper_generator' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/paper_generator.php">
                                <i class="fas fa-file-invoice"></i> Exam Paper Gen
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'specification_grids' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/specification_grids.php">
                                <i class="fas fa-border-all"></i> Specification Grid
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'ocr_scanner' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/ocr_scanner.php">
                                <i class="fas fa-camera"></i> OCR Paper Scanner
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($activePage ?? '') == 'curriculum' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/curriculum.php">
                                <i class="fas fa-sitemap"></i> Curriculum & Topics
                            </a>
                        </li>
                        
                        <?php elseif ($sub_type === 'exam_generator'): ?>
                            <!-- Exam Generator Only Subscription: Show ONLY Question Bank, Exam Paper Gen & Specification Grid -->
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'questions' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/questions.php">
                                    <i class="fas fa-database"></i> Question Bank
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'paper_generator' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/paper_generator.php">
                                    <i class="fas fa-file-invoice"></i> Exam Paper Gen
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'specification_grids' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/specification_grids.php">
                                    <i class="fas fa-border-all"></i> Specification Grid
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'ocr_scanner' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/ocr_scanner.php">
                                    <i class="fas fa-camera"></i> OCR Paper Scanner
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'curriculum' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/curriculum.php">
                                    <i class="fas fa-sitemap"></i> Curriculum & Topics
                                </a>
                            </li>

                        <?php elseif (Auth::getRole() === 'teacher'): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'dashboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/index.php">
                                    <i class="fas fa-home"></i> Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'routine' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/classes/routine.php">
                                    <i class="fas fa-calendar-alt"></i> Weekly Routine
                                </a>
                            </li>
                            <?php if ($show_exam_gen): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'questions' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/questions.php">
                                    <i class="fas fa-database"></i> Question Bank
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'paper_generator' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/paper_generator.php">
                                    <i class="fas fa-file-invoice"></i> Exam Paper Gen
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'specification_grids' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/specification_grids.php">
                                    <i class="fas fa-border-all"></i> Specification Grid
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'ocr_scanner' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/ocr_scanner.php">
                                    <i class="fas fa-camera"></i> OCR Paper Scanner
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'curriculum' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/curriculum.php">
                                    <i class="fas fa-sitemap"></i> Curriculum & Topics
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'learning' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/index.php">
                                    <i class="fas fa-book-open"></i> Learning Hub
                                </a>
                            </li>
                            <?php endif; ?>
                            
                        <?php else: // school_admin or principal ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'dashboard' ? 'active' : '' ?>" href="<?= APP_URL ?>/index.php">
                                    <i class="fas fa-home"></i> Dashboard
                                </a>
                            </li>
                            <?php if ($show_results): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'students' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/students/index.php">
                                    <i class="fas fa-user-graduate"></i> Students
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'teachers' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/teachers/index.php">
                                    <i class="fas fa-chalkboard-teacher"></i> Teachers
                                </a>
                            </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'classes' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/classes/index.php">
                                    <i class="fas fa-school"></i> Classes
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'routine' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/classes/routine.php">
                                    <i class="fas fa-calendar-alt"></i> Weekly Routine
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'subjects' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/subjects/index.php">
                                    <i class="fas fa-book"></i> Subjects
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'exams' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/exams/index.php">
                                    <i class="fas fa-file-alt"></i> Exams
                                </a>
                            </li>
                            <?php if ($show_results): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'results' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/results/index.php">
                                    <i class="fas fa-poll"></i> Results
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'attendance' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/attendance/index.php">
                                    <i class="fas fa-calendar-check"></i> Attendance
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if ($show_exam_gen): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'questions' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/questions.php">
                                    <i class="fas fa-database"></i> Question Bank
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'paper_generator' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/paper_generator.php">
                                    <i class="fas fa-file-invoice"></i> Exam Paper Gen
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'specification_grids' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/specification_grids.php">
                                    <i class="fas fa-border-all"></i> Specification Grid
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'ocr_scanner' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/ocr_scanner.php">
                                    <i class="fas fa-camera"></i> OCR Paper Scanner
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'curriculum' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/curriculum.php">
                                    <i class="fas fa-sitemap"></i> Curriculum & Topics
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'learning' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/index.php">
                                    <i class="fas fa-book-open"></i> Learning Hub
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if ($show_results): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'reports' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/reports/index.php">
                                    <i class="fas fa-chart-bar"></i> Reports
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'school_settings' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/settings/school.php">
                                    <i class="fas fa-school"></i> School Settings
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($activePage ?? '') == 'settings' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/settings/years.php">
                                    <i class="fas fa-cog"></i> Settings / Years
                                </a>
                            </li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="content-wrapper px-3 px-md-4 pt-5" id="main-content">
                <div class="py-4">
                    <?php if (Auth::isReadOnly()): ?>
                    <div class="alert alert-warning border-start border-warning border-4 shadow-sm mb-4 d-flex align-items-center no-print">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning me-3"></i>
                        <div>
                            <h5 class="alert-heading mb-1 fw-bold">Read-Only Mode Active</h5>
                            <p class="mb-0 small text-dark">You are viewing this school as the System Administrator in <strong>read-only mode</strong>. Adding, editing, and deleting data is disabled.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?= displayFlashMessage() ?>
                    <?= $content ?? '' ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Custom JS -->
    <script src="<?= APP_URL ?>/assets/js/nepali-date-converter.js"></script>
    <script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
