<?php
/**
 * OCR Question Paper Scanner - Main Page
 */
require_once __DIR__ . '/../../config/config.php';
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

<!-- TinyMCE CDN for MS Word formatting and Full-screen editor -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>

<!-- Tesseract OCR CDN -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

<!-- PDF.js CDN for PDF rendering client-side -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
<script>
    // Configure PDF.js worker path
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
</script>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h2 class="fw-bold text-primary mb-1"><i class="fas fa-camera-retro me-2"></i> OCR Question Paper Scanner</h2>
            <p class="text-muted mb-0">Scan printed/handwritten papers, images, or PDF documents into editable text format using advanced AI OCR.</p>
        </div>
        <a href="<?= APP_URL ?>/index.php" class="btn btn-outline-secondary">
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
                        <button type="button" class="btn btn-primary btn-sm px-3" onclick="document.getElementById('fileInput').click();">
                            Browse Files
                        </button>
                        <input type="file" id="fileInput" class="d-none" multiple accept="image/png, image/jpeg, image/jpg, application/pdf">
                    </div>

                    <!-- File List -->
                    <div id="fileListWrapper" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small text-muted">Selected Files (<span id="selectedFileCount">0</span>)</span>
                            <button type="button" class="btn btn-link text-danger p-0 text-decoration-none small" onclick="clearSelectedFiles()"><i class="fas fa-trash-alt me-1"></i> Clear All</button>
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
                    <button type="button" id="btnStartScan" class="btn btn-success btn-lg w-100 shadow-sm fw-bold disabled" onclick="startOcrProcess()">
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
                        <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="formatAsQuestions()">
                            <i class="fas fa-magic me-1"></i> Auto-Format Questions
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm px-3" onclick="copyEditorText()">
                            <i class="fas fa-copy me-1"></i> Copy All
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3" onclick="printEditorText()">
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

<script>
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    let selectedFilesList = [];

    // Initialize TinyMCE
    document.addEventListener('DOMContentLoaded', function() {
        tinymce.init({
            selector: '#ocrEditor',
            plugins: 'lists link image table fullscreen code wordcount searchreplace',
            toolbar: 'undo redo | formatselect | bold italic underline | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table link image | code fullscreen',
            height: 550,
            menubar: true,
            branding: false,
            content_style: 'body { font-family:Inter,Helvetica,Arial,sans-serif; font-size:14px }'
        });
    });

    // Prevent default drag behaviors
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    // Highlight drop zone when item is dragged over it
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('bg-light', 'border-success'), false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('bg-light', 'border-success'), false);
    });

    // Handle dropped files
    dropZone.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        addFilesToList(files);
    }

    fileInput.addEventListener('change', function() {
        addFilesToList(this.files);
    });

    function addFilesToList(files) {
        for (let i = 0; i < files.length; i++) {
            // Prevent duplicate files (check by name and size)
            const duplicate = selectedFilesList.some(f => f.name === files[i].name && f.size === files[i].size);
            if (!duplicate) {
                selectedFilesList.push(files[i]);
            }
        }
        renderFileList();
    }

    function renderFileList() {
        const fileList = document.getElementById('fileList');
        const fileListWrapper = document.getElementById('fileListWrapper');
        const selectedFileCount = document.getElementById('selectedFileCount');
        const btnStartScan = document.getElementById('btnStartScan');

        fileList.innerHTML = '';
        if (selectedFilesList.length === 0) {
            fileListWrapper.style.display = 'none';
            btnStartScan.classList.add('disabled');
            return;
        }

        fileListWrapper.style.display = 'block';
        btnStartScan.classList.remove('disabled');
        selectedFileCount.textContent = selectedFilesList.length;

        selectedFilesList.forEach((file, index) => {
            const sizeInKB = (file.size / 1024).toFixed(1);
            const isPDF = file.type === 'application/pdf' || file.name.endsWith('.pdf');
            const icon = isPDF ? 'fa-file-pdf text-danger' : 'fa-file-image text-info';

            const item = document.createElement('div');
            item.className = 'list-group-item d-flex justify-content-between align-items-center border-0 px-0 py-2 bg-transparent';
            item.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${icon} fa-lg me-2"></i>
                    <div>
                        <div class="fw-semibold text-truncate small" style="max-width: 220px;" title="${file.name}">${file.name}</div>
                        <span class="text-muted" style="font-size: 0.75rem;">${sizeInKB} KB</span>
                    </div>
                </div>
                <button type="button" class="btn btn-link text-danger p-0 text-decoration-none" onclick="removeFile(${index})">
                    <i class="fas fa-times-circle"></i>
                </button>
            `;
            fileList.appendChild(item);
        });
    }

    function removeFile(index) {
        selectedFilesList.splice(index, 1);
        renderFileList();
    }

    function clearSelectedFiles() {
        selectedFilesList = [];
        renderFileList();
    }

    // Convert PDF to images using pdf.js
    async function convertPdfToImages(file) {
        const arrayBuffer = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
        const numPages = pdf.numPages;
        const images = [];

        for (let pageNum = 1; pageNum <= numPages; pageNum++) {
            const page = await pdf.getPage(pageNum);
            const viewport = page.getViewport({ scale: 2.0 }); // High scale for superior OCR detection
            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            await page.render({ canvasContext: context, viewport: viewport }).promise;
            images.push({
                source: canvas.toDataURL('image/jpeg', 0.95),
                label: `${file.name} - Page ${pageNum} of ${numPages}`
            });
        }
        return images;
    }

    // Handle sequential OCR extraction
    async function startOcrProcess() {
        if (selectedFilesList.length === 0) return;

        const progressPanel = document.getElementById('ocrProgressPanel');
        const currentFileText = document.getElementById('ocrCurrentFile');
        const currentActionText = document.getElementById('ocrCurrentAction');
        const progressBar = document.getElementById('ocrProgressBar');
        const progressCounter = document.getElementById('ocrProgressCounter');
        const btnStartScan = document.getElementById('btnStartScan');

        btnStartScan.classList.add('disabled');
        progressPanel.style.display = 'block';
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';
        
        let allOcrQueue = [];

        // 1. Prepare files queue
        currentFileText.textContent = 'Preparing files...';
        currentActionText.textContent = 'Converting PDF pages and loading images...';

        for (let file of selectedFilesList) {
            const isPDF = file.type === 'application/pdf' || file.name.endsWith('.pdf');
            if (isPDF) {
                currentFileText.textContent = `Reading PDF: ${file.name}`;
                try {
                    const pdfImages = await convertPdfToImages(file);
                    allOcrQueue.push(...pdfImages);
                } catch (err) {
                    console.error('PDF parsing error', err);
                    alert(`Error parsing PDF file "${file.name}": ` + err.message);
                }
            } else {
                allOcrQueue.push({
                    source: file,
                    label: file.name
                });
            }
        }

        const totalPages = allOcrQueue.length;
        if (totalPages === 0) {
            progressPanel.style.display = 'none';
            btnStartScan.classList.remove('disabled');
            return;
        }

        let combinedText = '';

        // 2. Loop OCR sequentially
        for (let i = 0; i < totalPages; i++) {
            const item = allOcrQueue[i];
            currentFileText.textContent = `Scanning: ${item.label}`;
            progressCounter.textContent = `Processed ${i} of ${totalPages} items`;

            try {
                const lang = document.getElementById('ocrLanguage').value;
                const result = await Tesseract.recognize(
                    item.source,
                    lang,
                    {
                        logger: m => {
                           if (m.status === 'recognizing text') {
                               const pagePct = Math.round(m.progress * 100);
                               const overallPct = Math.round(((i + m.progress) / totalPages) * 100);
                               
                               progressBar.style.width = overallPct + '%';
                               progressBar.textContent = overallPct + '%';
                               currentActionText.textContent = `OCR extraction: ${pagePct}% completed for current item`;
                           }
                        }
                    }
                );
                combinedText += `\n\n=== EXTRACTED FROM: ${item.label} ===\n\n` + result.data.text;
            } catch (error) {
                console.error('OCR Error on item ' + item.label, error);
                combinedText += `\n\n=== ERROR EXTRACTING FROM: ${item.label} ===\n\nError: ${error.message}`;
            }
        }

        // 3. Populate Text Editor
        progressBar.style.width = '100%';
        progressBar.textContent = 'Done!';
        currentFileText.textContent = 'Scan Complete!';
        currentActionText.textContent = 'Rendering HTML layout...';

        setTimeout(() => {
            progressPanel.style.display = 'none';
            btnStartScan.classList.remove('disabled');
            
            // Format plain text to HTML paragraphs safely
            const htmlContent = combinedText.trim().replace(/\n/g, '<br>');
            if (window.tinymce && tinymce.get('ocrEditor')) {
                tinymce.get('ocrEditor').setContent(htmlContent);
            } else {
                document.getElementById('ocrEditor').value = combinedText;
            }
            clearSelectedFiles();
        }, 1000);
    }

    // Auto-Format text lines into standard Question & Answer list structures
    function formatAsQuestions() {
        if (!window.tinymce || !tinymce.get('ocrEditor')) return;
        
        let content = tinymce.get('ocrEditor').getContent();
        
        // Create an offscreen DOM parser
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = content;
        
        const rawText = tempDiv.innerText || tempDiv.textContent;
        const lines = rawText.split('\n');
        let formattedHtml = '';
        
        lines.forEach(line => {
            line = line.trim();
            if (line === '') return;
            
            // Check matches for Question formats like: Q1., Q.1, Question 1., 1.
            const questionMatch = line.match(/^(Question\s*\d+|Q\d+|Q\.\d+|\d+)\.?\s+(.*)$/i);
            // Check matches for sub-questions like: a) or (a)
            const subQuestionMatch = line.match(/^(\([a-z]\)|[a-z]\))\s+(.*)$/i);
            
            if (questionMatch) {
                formattedHtml += `<p style="margin-top: 15px; margin-bottom: 5px;"><strong>${questionMatch[1]}.</strong> ${questionMatch[2]}</p>`;
            } else if (subQuestionMatch) {
                formattedHtml += `<p style="margin-left: 30px; margin-top: 3px; margin-bottom: 3px;"><strong>${subQuestionMatch[1]}</strong> ${subQuestionMatch[2]}</p>`;
            } else {
                formattedHtml += `<p style="margin-bottom: 5px;">${line}</p>`;
            }
        });
        
        tinymce.get('ocrEditor').setContent(formattedHtml);
    }

    function copyEditorText() {
        if (window.tinymce && tinymce.get('ocrEditor')) {
            const content = tinymce.get('ocrEditor').getContent({ format: 'text' });
            navigator.clipboard.writeText(content).then(() => {
                alert('Copied raw text to clipboard!');
            });
        }
    }

    function printEditorText() {
        if (window.tinymce && tinymce.get('ocrEditor')) {
            // Focus and trigger standard print window on the editor's iframe
            const editorWindow = tinymce.get('ocrEditor').getWin();
            editorWindow.print();
        }
    }
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
