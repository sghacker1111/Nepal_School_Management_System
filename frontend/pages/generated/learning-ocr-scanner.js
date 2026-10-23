// Mechanically extracted from modules/learning/ocr_scanner.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;

    // The version-matched PDF worker is configured by the local module loader.


    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    let selectedFilesList = [];
    document.addEventListener('click',event=>{const button=event.target.closest('[data-ocr-remove]');if(button)removeFile(Number(button.dataset.ocrRemove));});

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
                        <div class="fw-semibold text-truncate small ocr-file-name" style="max-width: 220px;"></div>
                        <span class="text-muted" style="font-size: 0.75rem;">${sizeInKB} KB</span>
                    </div>
                </div>
                <button type="button" class="btn btn-link text-danger p-0 text-decoration-none" data-ocr-remove="${index}">
                    <i class="fas fa-times-circle"></i>
                </button>
            `;
            const filename=item.querySelector('.ocr-file-name');filename.textContent=file.name;filename.title=file.name;
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
            const escaped=document.createElement('div');escaped.textContent=combinedText.trim();
            const htmlContent = escaped.innerHTML.replace(/\n/g, '<br>');
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


scope.bindActions({"learning-ocr-scanner-action-0":function(event,args){document.getElementById('fileInput').click();},
"learning-ocr-scanner-action-1":function(event,args){clearSelectedFiles()},
"learning-ocr-scanner-action-2":function(event,args){startOcrProcess()},
"learning-ocr-scanner-action-3":function(event,args){formatAsQuestions()},
"learning-ocr-scanner-action-4":function(event,args){copyEditorText()},
"learning-ocr-scanner-action-5":function(event,args){printEditorText()}});
}
