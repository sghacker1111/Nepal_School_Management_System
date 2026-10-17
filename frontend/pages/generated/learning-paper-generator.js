// Mechanically extracted from modules/learning/paper_generator.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;

// Live Calculation of custom Grid Marks
function calculateLiveMarks() {
    let total = 0;
    const rows = document.querySelectorAll('.grid-row');
    rows.forEach(row => {
        const countInput = row.querySelector('.grid-count');
        const marksInput = row.querySelector('.grid-marks');
        const subtotalSpan = row.querySelector('.grid-subtotal');

        const count = parseInt(countInput.value) || 0;
        const marks = parseInt(marksInput.value) || 0;
        const subtotal = count * marks;

        subtotalSpan.textContent = subtotal;
        total += subtotal;
    });

    const marksBadge = document.getElementById('lblLiveMarks');
    if (marksBadge) {
        marksBadge.textContent = total;
    }
}

// Live Calculation of manual questions count
function updateManualCount() {
    const checked = document.querySelectorAll('.manual-check:checked').length;
    const badge = document.getElementById('lblManualCount');
    if (badge) {
        badge.textContent = checked;
    }
}

// Client Side Word Document Export
function exportToWord() {
    const headerHTML = "<html xmlns:o='urn:schemas-microsoft-com:office:office' "+
            "xmlns:w='urn:schemas-microsoft-com:office:word' "+
            "xmlns='http://www.w3.org/TR/REC-html40'>"+
            "<head><title>Export HTML to Word</title><meta charset='utf-8'><style>"+
            "body {font-family:'Times New Roman',serif;font-size:12pt;}"+
            ".school-name-print {font-size:16pt;font-weight:bold;text-transform:uppercase;}"+
            ".paper-title {font-size:14pt;font-weight:bold;text-transform:uppercase;}"+
            ".metadata-table {width:100%;border-bottom:1px solid #000;margin-bottom:15px;}"+
            ".question-group-title {font-weight:bold;margin-top:15px;margin-bottom:5px;text-transform:uppercase;border-bottom:1px solid #000;}"+
            ".question-item {margin-bottom:10px;}"+
            ".question-marks {float:right;font-weight:bold;}"+
            ".mcq-options-grid {margin-left:20px;}"+
            "</style></head><body>";
    const footerHTML = "</body></html>";
    const paperClone=document.getElementById('printArea').cloneNode(true);paperClone.querySelectorAll('[data-exam-language-view][hidden]').forEach(node=>node.remove());
    const sourceHTML = headerHTML + paperClone.innerHTML + footerHTML;

    const source = 'data:application/vnd.ms-word;charset=utf-8,' + encodeURIComponent(sourceHTML);
    const fileDownload = document.createElement("a");
    document.body.appendChild(fileDownload);
    fileDownload.href = source;
    fileDownload.download = 'exam_paper.doc';
    fileDownload.click();
    document.body.removeChild(fileDownload);
}

// Client-Side OCR scanner using Tesseract.js (safely check if button exists)
const btnRunOCR = document.getElementById('btnRunOCR');
if (btnRunOCR) {
    btnRunOCR.addEventListener('click', function(e) {
        e.preventDefault();
        const fileInput = document.getElementById('ocrImageFile');
        const statusDiv = document.getElementById('ocrStatus');
        const progressDiv = document.getElementById('ocrProgress');
        const progressBar = document.getElementById('ocrProgressBar');
        const outputArea = document.getElementById('ocrOutputText');

        if (fileInput.files.length === 0) {
            alert('Please select an image file first!');
            return;
        }

        const file = fileInput.files[0];
        statusDiv.style.display = 'block';
        progressDiv.style.display = 'flex';
        statusDiv.textContent = 'Initializing Tesseract OCR Engine...';
        progressBar.style.width = '5%';
        progressBar.textContent = '5%';

        Tesseract.recognize(
            file,
            'eng+nep', // Supports both English and Nepali OCR extraction!
            {
                logger: m => {
                    if (m.status === 'recognizing text') {
                        const pct = Math.round(m.progress * 100);
                        progressBar.style.width = pct + '%';
                        progressBar.textContent = pct + '%';
                        statusDiv.textContent = 'Extracting Text: ' + pct + '%';
                    }
                }
            }
        ).then(({ data: { text } }) => {
            outputArea.value = text;
            statusDiv.textContent = 'Extraction complete!';
            progressBar.style.width = '100%';
            progressBar.textContent = 'Done!';

            // Render equations if MathJax was found in OCR output (optional)
            if (window.MathJax && window.MathJax.typesetPromise) {
                MathJax.typesetPromise().then(() => {
                    if (typeof fixDevanagariInMathJax === 'function') {
                        fixDevanagariInMathJax();
                    }
                });
            }
        }).catch(err => {
            console.error(err);
            statusDiv.textContent = 'OCR Error: ' + err.message;
            progressBar.classList.add('bg-danger');
        });
    });
}

// Save Current Grid Layout via AJAX
function saveGridLayout() {
    const gridName = prompt("Enter a name for this customized grid layout (e.g., 'First Term Standard Grid'):");
    if (!gridName) return;

    const classId = ""+(config.values["learning-paper-generator-value-0"]??'')+"";
    const subjectId = ""+(config.values["learning-paper-generator-value-1"]??'')+"";

    if (!classId || !subjectId) {
        alert("Please select Class and Subject first.");
        return;
    }

    // Build grid data JSON
    const gridData = {};
    const rows = document.querySelectorAll('.grid-row');
    rows.forEach(row => {
        const countInput = row.querySelector('.grid-count');
        const marksInput = row.querySelector('.grid-marks');
        const type = countInput.name.replace('count_', '').replace(/_/g, ' ');

        gridData[type] = {
            count: parseInt(countInput.value) || 0,
            marks: parseInt(marksInput.value) || 0
        };
    });

    $.ajax({
        url: window.location.pathname + window.location.search,
        type: 'POST',
        data: {
            action_save_grid: 1,
            grid_name: gridName,
            class_id: classId,
            subject_id: subjectId,
            grid_data: JSON.stringify(gridData)
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert("Grid layout saved successfully!");
                // Append to load grid dropdown
                const select = document.getElementById('loadGridSelect');
                const opt = document.createElement('option');
                opt.value = JSON.stringify(gridData);
                opt.textContent = response.name;
                opt.selected = true;
                select.appendChild(opt);
            } else {
                alert("Error saving grid: " + response.message);
            }
        },
        error: function() {
            alert("An error occurred while saving the grid.");
        }
    });
}

// Load Selected Grid Layout Config
function loadGridLayout(selectEl) {
    const val = selectEl.value;
    if (!val) return;

    try {
        const gridData = JSON.parse(val);
        for (const type in gridData) {
            const safeType = type.replace(/ /g, '_');
            const countInput = document.querySelector('input[name="count_' + safeType + '"]');
            const marksInput = document.querySelector('input[name="marks_' + safeType + '"]');

            if (countInput) countInput.value = gridData[type].count;
            if (marksInput) marksInput.value = gridData[type].marks;
        }
        calculateLiveMarks();
    } catch (e) {
        console.error("Failed to parse grid data:", e);
    }
}

$(document).ready(function() {
    calculateLiveMarks();
    updateManualCount();

    // Fetch chapters for chapter selector dropdown
    const classId = ""+(config.values["learning-paper-generator-value-2"]??'')+"";
    const subjectId = ""+(config.values["learning-paper-generator-value-3"]??'')+"";
    const selectedChapter = JSON.parse(config.values["learning-paper-generator-value-4"]||'null');

    if (classId && subjectId) {
        $.ajax({
            url: JSON.parse(config.values["learning-paper-generator-value-5"]||'null'),
            data: {
                action_get_chapters: 1,
                class_id: classId,
                subject_id: subjectId
            },
            dataType: 'json',
            success: function(chapters) {
                const select = document.getElementById('chapterFilterSelect');
                if (select) {
                    renderPaperChapters(select,chapters,selectedChapter);
                }
            }
        });
    }
});


scope.bindActions({"learning-paper-generator-action-0":function(event,args){document.getElementById('paramForm').submit();},
"learning-paper-generator-action-1":function(event,args){document.getElementById('paramForm').submit();},
"learning-paper-generator-action-2":function(event,args){document.getElementById('paramForm').submit();},
"learning-paper-generator-action-3":function(event,args){document.getElementById('paramForm').submit();},
"learning-paper-generator-action-4":function(event,args){document.getElementById('paramForm').submit();},
"learning-paper-generator-action-5":function(event,args){document.getElementById('paramForm').submit();},
"learning-paper-generator-action-6":function(event,args){document.getElementById('paramForm').submit();},
"learning-paper-generator-action-7":function(event,args){loadGridLayout(this)},
"learning-paper-generator-action-8":function(event,args){saveGridLayout()},
"learning-paper-generator-action-9":function(event,args){calculateLiveMarks()},
"learning-paper-generator-action-10":function(event,args){calculateLiveMarks()},
"learning-paper-generator-action-11":function(event,args){updateManualCount()},
"learning-paper-generator-action-12":function(event,args){navigator.clipboard.writeText(document.getElementById('ocrOutputText').value); alert('OCR text copied to clipboard!');},
"learning-paper-generator-action-13":function(event,args){exportToWord()}});
}
