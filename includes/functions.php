<?php
/**
 * Common Functions Helper
 */

/**
 * Sanitize input data
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect with message
 */
function redirect($url, $message = '', $type = 'success') {
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
    header("Location: $url");
    exit;
}

/**
 * Display flash message
 */
function displayFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        
        $alertClass = [
            'success' => 'alert-success',
            'error' => 'alert-danger',
            'warning' => 'alert-warning',
            'info' => 'alert-info'
        ][$type] ?? 'alert-info';
        
        return "<div class='alert {$alertClass} alert-dismissible fade show' role='alert'>
                    {$message}
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
    }
    return '';
}

/**
 * Generate unique student ID
 */
function generateStudentId($classId, $academicYearId) {
    $db = getDB();
    $year = date('Y');
    $prefix = 'STU/' . $year . '/';
    
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(student_id, '/', -1) AS UNSIGNED)) as max_id FROM students WHERE academic_year_id = ?");
    $stmt->execute([$academicYearId]);
    $result = $stmt->fetch();
    
    $nextId = ($result['max_id'] ?? 0) + 1;
    return $prefix . str_pad($nextId, 5, '0', STR_PAD_LEFT);
}

/**
 * Upload file
 */
function uploadFile($file, $subDir = '') {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        return false;
    }
    
    // Check extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return false;
    }
    
    // Create directory if not exists
    $uploadPath = UPLOAD_DIR . ($subDir ? $subDir . '/' : '');
    if (!file_exists($uploadPath)) {
        mkdir($uploadPath, 0777, true);
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $destination = $uploadPath . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $filename;
    }
    
    return false;
}

/**
 * Calculate GPA from percentage
 */
function calculateGPA($percentage, $classId = null) {
    $db = getDB();
    
    // Get grading scale (could be class-specific in future)
    $stmt = $db->prepare("SELECT grade_point FROM grading_scales WHERE ? BETWEEN min_percentage AND max_percentage LIMIT 1");
    $stmt->execute([$percentage]);
    $result = $stmt->fetch();
    
    return $result ? $result['grade_point'] : 0;
}

/**
 * Calculate Grade from percentage
 */
function calculateGrade($percentage, $classId = null) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT grade FROM grading_scales WHERE ? BETWEEN min_percentage AND max_percentage LIMIT 1");
    $stmt->execute([$percentage]);
    $result = $stmt->fetch();
    
    return $result ? $result['grade'] : 'F';
}

/**
 * Calculate subject final letter grade from final GPA (Table 14)
 */
function calculateSubjectFinalGrade($gp) {
    $gp = (float)$gp;
    if ($gp >= 3.61) return 'A+';
    if ($gp >= 3.21) return 'A';
    if ($gp >= 2.81) return 'B+';
    if ($gp >= 2.41) return 'B';
    if ($gp >= 2.01) return 'C+';
    if ($gp >= 1.61) return 'C';
    if ($gp >= 1.60) return 'D';
    return 'NG';
}

/**
 * Get remarks/descriptor based on grade (Table 5)
 */
function getGradeRemarks($grade) {
    $remarks = [
        'A+' => 'Outstanding',
        'A'  => 'Excellent',
        'B+' => 'Very Good',
        'B'  => 'Good',
        'C+' => 'Satisfactory',
        'C'  => 'Acceptable',
        'D'  => 'Basic',
        'NG' => 'Not Graded'
    ];
    return $remarks[$grade] ?? 'Not Graded';
}

/**
 * Convert number to Nepali digits
 */
function convertToNepaliDigits($number) {
    $nepaliDigits = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];
    $englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    
    return str_replace($englishDigits, $nepaliDigits, (string)$number);
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'd M Y') {
    if (!$date) return '';
    return date($format, strtotime($date));
}

/**
 * Get academic year label
 */
function getAcademicYearLabel($yearBS, $yearAD) {
    return $yearBS . ' / ' . $yearAD;
}

/**
 * Check if string contains Nepali characters
 */
function isNepali($string) {
    return preg_match('/[\x{900}-\x{97F}]/u', $string);
}

/**
 * Ensure Nepali / Devanagari text inside LaTeX expressions is wrapped in \text{...}
 * so MathJax renders complex ligatures, matras, and conjuncts properly without breaking.
 */
function fixNepaliInLatex($tex) {
    if (empty($tex) || !is_string($tex)) return $tex;
    if (!preg_match('/[\x{0900}-\x{097F}]/u', $tex)) return $tex;
    
    return preg_replace_callback(
        '/(\\\\(?:text|mbox|mathrm|mathbf|mathit)\{[^{}]*\})|(\\\\[a-zA-Z]+)|([\x{0900}-\x{097F}]+(?:[ \t\x{00A0}]+[\x{0900}-\x{097F}]+)*)/u',
        function($matches) {
            if (!empty($matches[1])) return $matches[1];
            if (!empty($matches[2])) return $matches[2];
            if (!empty($matches[3])) return '\\text{' . $matches[3] . '}';
            return $matches[0];
        },
        $tex
    );
}

/**
 * Debug helper
 */
function dd($data) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
    die;
}

/**
 * Class level type helper (returns '1-3', '4-8', or '9-12')
 */
function getClassType($className) {
    if (preg_match('/Class\s+(\d+)/i', $className, $matches)) {
        $num = (int)$matches[1];
        if ($num >= 1 && $num <= 3) return '1-3';
        if ($num >= 4 && $num <= 8) return '4-8';
        if ($num >= 9) return '9-12';
    }
    if (in_array(strtolower($className), ['nursery', 'lkg', 'ukg'])) {
        return '1-3';
    }
    return '4-8';
}
