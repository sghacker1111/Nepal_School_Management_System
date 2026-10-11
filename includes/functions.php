<?php
require_once __DIR__ . '/grading.php';
require_once __DIR__.'/print_signature.php';

/** Explicit CSV escaping for PHP 8.4+; neutralize spreadsheet formula cells. */
function writeCsvRow($stream, array $fields): int|false {
    $fields=array_map(static function($value) {
        $value=(string)($value??'');
        return preg_match('/^[\s]*[=+@-]/u',$value) ? "'".$value : $value;
    },$fields);
    return fputcsv($stream,$fields,',','"','');
}

function importDateValue(?string $value): ?string {
    if($value===null || trim($value)==='')return null;
    $value=trim($value);
    foreach(['!Y-m-d','!Y/m/d','!m/d/Y'] as $format) {
        $date=DateTimeImmutable::createFromFormat($format,$value);
        $errors=DateTimeImmutable::getLastErrors();
        if($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])))return $date->format('Y-m-d');
    }
    throw new InvalidArgumentException('Use YYYY-MM-DD for imported dates.');
}
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
    return htmlspecialchars(trim((string)($data ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
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
    if(!isset($_SESSION['flash_message'])&&isset($_SESSION['action_notice'])){$_SESSION['flash_message']=$_SESSION['action_notice'];$_SESSION['flash_type']='success';}
    unset($_SESSION['action_notice']);
    if (isset($_SESSION['flash_message'])) {
        $message = sanitize($_SESSION['flash_message']);
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
    return 'STU/' . (int)Auth::getSchoolId() . '/' . date('Y') . '/' . strtoupper(bin2hex(random_bytes(4)));
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
function calculateGPA($percentage, $classId = null) { return erpGradeForPercentage((float)$percentage)[1]; }

/**
 * Calculate Grade from percentage
 */
function calculateGrade($percentage, $classId = null) { return erpGradeForPercentage((float)$percentage)[0]; }

/**
 * Calculate subject final letter grade from final GPA (Table 14)
 */
function calculateSubjectFinalGrade($gp) { return erpFinalGrade((float)$gp); }

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
    $tex = safeRichHtml((string)$tex);
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

/** Allow formatted lesson content without executing stored scripts or event handlers. */
function safeRichHtml(string $html): string {
    if ($html==='') return '';
    $doc=new DOMDocument('1.0','UTF-8');
    $previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="erp-rich-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
    libxml_clear_errors();libxml_use_internal_errors($previous);
    $allowed=['div','span','p','br','strong','b','em','i','u','s','sub','sup','table','thead','tbody','tfoot','tr','td','th','ol','ul','li','blockquote','pre','code','h1','h2','h3','h4','h5','h6','img','a'];
    $clean=function(DOMNode $node) use (&$clean,$allowed){
        foreach(iterator_to_array($node->childNodes) as $child){
            if($child instanceof DOMElement){
                $tag=strtolower($child->tagName);
                if(in_array($tag,['script','style','iframe','object','embed','form','input','button','svg','math','meta','link'],true)){$node->removeChild($child);continue;}
                $clean($child);
                if(!in_array($tag,$allowed,true)){while($child->firstChild)$node->insertBefore($child->firstChild,$child);$node->removeChild($child);continue;}
                foreach(iterator_to_array($child->attributes) as $attribute){
                    $name=strtolower($attribute->name);$value=trim($attribute->value);
                    $ok=in_array($name,['title','alt','colspan','rowspan','lang'],true);
                    if($name==='class')$ok=(bool)preg_match('/^[\w\s-]*$/u',$value);
                    if(($tag==='a'&&$name==='href')||($tag==='img'&&$name==='src'))$ok=(bool)preg_match('~^(https?://|/(?!/)|\./|\.\./|data:image/(png|jpeg|gif|webp);base64,)~i',$value);
                    if(!$ok)$child->removeAttribute($attribute->name);
                }
                if($tag==='a')$child->setAttribute('rel','noopener noreferrer');
            }elseif($child instanceof DOMComment || $child instanceof DOMProcessingInstruction)$node->removeChild($child);
        }
    };
    $root=$doc->getElementById('erp-rich-root');$clean($doc);$output='';
    if($root)foreach($root->childNodes as $child)$output.=$doc->saveHTML($child);
    return $output;
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
