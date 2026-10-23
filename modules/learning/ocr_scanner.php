<?php
/**
 * OCR Question Paper Scanner - Main Page
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('paper_generator');

$db = getDB();
$school_id = Auth::getSchoolId();
$is_admin = (Auth::getRole() === 'super_admin');

$pageTitle = 'OCR Question Paper Scanner';
$activePage = 'ocr_scanner';

ob_start();
?>

<!-- Locally packaged editor -->
<?php $pageAssets[]="tinymce"; ?>

<!-- Tesseract OCR CDN -->
<?php $pageAssets[]="tesseract"; ?>

<!-- Locally packaged PDF.js and matching worker -->
<?php $pageAssets[]="pdfjs"; ?>
<?php schoolPageModule("learning-ocr-scanner"); ?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h2 class="fw-bold text-primary mb-1"><i class="fas fa-camera-retro me-2"></i> OCR Question Paper Scanner</h2>
            <p class="text-muted mb-0">Scan printed/handwritten papers, images, or PDF documents into editable text format using advanced AI OCR.</p>
        </div>
        <a href="<?= schoolRouteUrl('index.php') ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- Scanner Section -->
    <div class="row g-4">
        <!-- Input Panel -->
        <div class="col-xl-4 col-lg-5 no-print">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="mb-0 fw-bold text-secondary"><i class="fas fa-file-upload me-2 text-primary"></i> Upload Documents</h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <!-- Drop Zone -->
                    <div id="dropZone" class="border-2 border-dashed border-primary rounded-3 p-4 text-center cursor-pointer mb-3" style="background: #f8fafc; transition: all 0.3s ease; border-style: dashed !important;">
                        <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-3"></i>
                        <h6 class="fw-bold mb-1">Drag & Drop Files Here</h6>
                        <p class="text-muted small mb-3">Supports JPG, PNG, or Multipage PDF files</p>
                        <button type="button" class="btn btn-primary btn-sm px-3" data-erp-click="learning-ocr-scanner-action-0" data-erp-click-args="<?= schoolPageActionArgs("learning-ocr-scanner",[]) ?>">
                            Browse Files
                        </button>
                        <input type="file" id="fileInput" class="d-none" multiple accept="image/png, image/jpeg, image/jpg, application/pdf">
                    </div>

                    <!-- File List -->
                    <div id="fileListWrapper" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small text-muted">Selected Files (<span id="selectedFileCount">0</span>)</span>
                            <button type="button" class="btn btn-link text-danger p-0 text-decoration-none small" data-erp-click="learning-ocr-scanner-action-1" data-erp-click-args="<?= schoolPageActionArgs("learning-ocr-scanner",[]) ?>"><i class="fas fa-trash-alt me-1"></i> Clear All</button>
                        </div>
                        <div id="fileList" class="list-group list-group-flush mb-4 overflow-auto" style="max-height: 220px; border-radius: 8px;">
                            <!-- Populated by JS -->
                        </div>
                    </div>

                    <!-- Language Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted small"><i class="fas fa-language me-1 text-primary"></i> OCR Scan Language</label>
                        <select id="ocrLanguage" class="form-select">
                            <option value="eng+nep">English & Nepali (Auto-detect)</option>
                            <option value="eng">English Only</option>
                            <option value="nep">Nepali Only</option>
                        </select>
                    </div>

                    <!-- Action Button -->
                    <button type="button" id="btnStartScan" class="btn btn-success btn-lg w-100 shadow-sm fw-bold disabled" data-erp-click="learning-ocr-scanner-action-2" data-erp-click-args="<?= schoolPageActionArgs("learning-ocr-scanner",[]) ?>">
                        <i class="fas fa-play-circle me-2"></i> Start Extraction
                    </button>
                </div>
            </div>
        </div>

        <!-- Output Panel / Text Editor -->
        <div class="col-xl-8 col-lg-7">
            <!-- Progress Overlay (inside Output Panel when scanning) -->
            <div id="ocrProgressPanel" class="card border-0 shadow-sm mb-4 no-print" style="display: none; border-radius: 12px; background: #fff;">
                <div class="card-body p-4 text-center">
                    <div class="spinner-border text-success mb-3" style="width: 3rem; height: 3rem;" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <h5 class="fw-bold mb-2" id="ocrCurrentFile">Preparing files...</h5>
                    <p class="text-muted small mb-3" id="ocrCurrentAction">Initializing OCR Engine...</p>
                    
                    <!-- Progress Bar -->
                    <div class="progress mb-2" style="height: 20px; border-radius: 10px;">
                        <div id="ocrProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>
                    <span class="text-muted small fw-bold" id="ocrProgressCounter">Processed 0 of 0 pages</span>
                </div>
            </div>

            <!-- Full Screen Text Editor Card -->
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background: #fff;">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center no-print">
                    <h5 class="mb-0 fw-bold text-secondary"><i class="fas fa-file-signature me-2 text-primary"></i> Editable Question Paper Editor</h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm px-3" data-erp-click="learning-ocr-scanner-action-3" data-erp-click-args="<?= schoolPageActionArgs("learning-ocr-scanner",[]) ?>">
                            <i class="fas fa-magic me-1"></i> Auto-Format Questions
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm px-3" data-erp-click="learning-ocr-scanner-action-4" data-erp-click-args="<?= schoolPageActionArgs("learning-ocr-scanner",[]) ?>">
                            <i class="fas fa-copy me-1"></i> Copy All
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3" data-erp-click="learning-ocr-scanner-action-5" data-erp-click-args="<?= schoolPageActionArgs("learning-ocr-scanner",[]) ?>">
                            <i class="fas fa-print me-1"></i> Print / Export
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <textarea id="ocrEditor" style="height: 520px; border: none;"></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<?php schoolPageModule("learning-ocr-scanner"); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
