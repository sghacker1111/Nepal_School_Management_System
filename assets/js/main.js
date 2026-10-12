/**
 * Main JavaScript File
 * Nepal School ERP
 */

document.addEventListener('DOMContentLoaded', function() {
    // Keep automatic GET filters from submitting an old section with a new class.
    document.addEventListener('change', event => {
        if (!['class','class_id'].includes(event.target.name)) return;
        const form=event.target.closest('form');
        if (!form || form.method.toLowerCase()!=='get') return;
        const section=form.querySelector('[name="section"], [name="section_id"]');
        if(section)section.value='';
        const subject=form.querySelector('[name="subject"], [name="subject_id"]');
        if(subject)subject.value='';
    }, true);
    // Older forms used visible labels without associations. Link them for keyboards and readers.
    document.querySelectorAll('label.form-label:not([for])').forEach((label,index)=>{
        const control=label.parentElement.querySelector('input:not([type="hidden"]),select,textarea');
        if(control){if(!control.id)control.id='erp-field-'+index;label.htmlFor=control.id;}
    });
    document.querySelectorAll('input[name$="_drive_path"]').forEach(input=>{input.disabled=true;input.placeholder='Use the file upload button above';});
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialize popovers
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Auto-enable bootstrap validation on forms with 'needs-validation'
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
});

// Confirm delete with SweetAlert2
function confirmDelete(url, message = 'Are you sure?') {
    Swal.fire({
        title: 'Confirm Action',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, proceed!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.postAction(url);
        }
    });
}

// Show success/error message
function showMessage(message, type = 'success') {
    const iconMap = {
        'success': 'success',
        'error': 'error',
        'warning': 'warning',
        'info': 'info'
    };
    
    Swal.fire({
        icon: iconMap[type] || 'info',
        title: message,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
}

// Export table to Excel
function exportTableToExcel(tableId, filename = '') {
    let downloadLink;
    const dataType = 'application/vnd.ms-excel';
    const tableSelect = document.getElementById(tableId);
    const tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
    
    filename = filename ? filename + '.xls' : 'excel_data.xls';
    
    downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    
    if (navigator.msSaveOrOpenBlob) {
        var blob = new Blob(['\ufeff', tableHTML], {
            type: dataType
        });
        navigator.msSaveOrOpenBlob(blob, filename);
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = filename;
        downloadLink.click();
    }
}

// Print specific element
function printElement(elementId) {
    const element = document.getElementById(elementId);
    const printWindow = window.open('', '', 'height=600,width=800');
    
    printWindow.document.write('<html><head><title>Print</title>');
    printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
    printWindow.document.write('</head><body>');
    printWindow.document.write(element.innerHTML);
    printWindow.document.write('</body></html>');
    
    printWindow.document.close();
    printWindow.print();
}

// Auto-dismiss alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Form validation helper
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return false;
    }
    return true;
}

// Loading overlay
function showLoading(message = 'Loading...') {
    const overlay = document.createElement('div');
    overlay.className = 'spinner-overlay';
    overlay.id = 'loadingOverlay';
    overlay.innerHTML = `
        <div class="text-center">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3">${message}</p>
        </div>
    `;
    document.body.appendChild(overlay);
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.remove();
    }
}

// AJAX helper
async function ajaxRequest(url, method = 'GET', data = null) {
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    
    if (data) {
        options.body = JSON.stringify(data);
    }
    
    try {
        const response = await fetch(url, options);
        const result = await response.json();
        return result;
    } catch (error) {
        console.error('AJAX Error:', error);
        throw error;
    }
}

// Nepali number converter
function convertToNepaliNumber(num) {
    const nepaliDigits = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];
    return num.toString().split('').map(digit => {
        return nepaliDigits[digit] || digit;
    }).join('');
}

// Date formatter
function formatDate(dateString, format = 'DD MMM YYYY') {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    
    const day = date.getDate();
    const month = months[date.getMonth()];
    const year = date.getFullYear();
    
    return `${day} ${month} ${year}`;
}

// Initialize DataTables with default settings
if (window.jQuery && typeof $.fn.DataTable !== 'undefined') {
    $.extend(true, $.fn.dataTable.defaults, {
        responsive: true,
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        }
    });
}

// Global Password Verification Helper
function verifyPasswordAndProceed(onSuccess, title = 'Security Verification', text = 'Please enter your administrator password to confirm:') {
    const appUrl = window.APP_URL || '';
    Swal.fire({
        title: title,
        text: text,
        input: 'password',
        inputAttributes: {
            autocapitalize: 'off',
            autocorrect: 'off'
        },
        placeholder: 'Enter your password',
        showCancelButton: true,
        confirmButtonText: 'Verify & Confirm',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: (password) => {
            if (!password) {
                Swal.showValidationMessage('Password is required');
                return false;
            }
            return fetch(`${appUrl}/modules/auth/verify_password.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ password: password })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response error');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    return password;
                } else {
                    Swal.showValidationMessage(data.message || 'Incorrect password');
                    return false;
                }
            })
            .catch(error => {
                Swal.showValidationMessage(`Request failed: ${error}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            onSuccess(result.value);
        }
    });
}

// Real-time Date of Birth AD <-> BS Conversion
document.addEventListener('DOMContentLoaded', function() {
    const nepaliDateClass = (window.NepaliDate && window.NepaliDate.default) || (window.NepaliDate);
    
    if (nepaliDateClass) {
        const convertADtoBS = function(adVal) {
            if (!adVal) return '';
            try {
                const parts = adVal.split('-');
                if (parts.length !== 3) return '';
                const year = parseInt(parts[0], 10);
                const month = parseInt(parts[1], 10) - 1;
                const day = parseInt(parts[2], 10);
                if (isNaN(year) || isNaN(month) || isNaN(day)) return '';
                const jsDate = new Date(year, month, day);
                const nepDate = new nepaliDateClass(jsDate);
                const bs = nepDate.getBS();
                const yy = bs.year;
                const mm = String(bs.month + 1).padStart(2, '0');
                const dd = String(bs.date).padStart(2, '0');
                return `${yy}-${mm}-${dd}`;
            } catch (e) {
                console.error('AD to BS conversion error:', e);
                return '';
            }
        };

        const convertBStoAD = function(bsVal) {
            if (!bsVal) return '';
            try {
                const parts = bsVal.split('-');
                if (parts.length !== 3) return '';
                const year = parseInt(parts[0], 10);
                const month = parseInt(parts[1], 10) - 1;
                const day = parseInt(parts[2], 10);
                if (isNaN(year) || isNaN(month) || isNaN(day)) return '';
                
                // UMD library bounds check
                if (year < 1970 || year > 2100 || month < 0 || month > 11 || day < 1 || day > 32) return '';
                
                const nepDate = new nepaliDateClass(year, month, day);
                const ad = nepDate.getAD();
                const yy = ad.year;
                const mm = String(ad.month + 1).padStart(2, '0');
                const dd = String(ad.date).padStart(2, '0');
                return `${yy}-${mm}-${dd}`;
            } catch (e) {
                console.error('BS to AD conversion error:', e);
                return '';
            }
        };

        document.body.addEventListener('input', function(e) {
            if (e.target && (e.target.name === 'dob_ad' || e.target.id === 'dob_ad')) {
                const form = e.target.closest('form');
                if (form) {
                    const bsInput = form.querySelector('[name="dob_bs"]') || form.querySelector('#dob_bs');
                    if (bsInput && document.activeElement === e.target) {
                        bsInput.value = convertADtoBS(e.target.value);
                    }
                }
            }
            if (e.target && (e.target.name === 'dob_bs' || e.target.id === 'dob_bs')) {
                const form = e.target.closest('form');
                if (form) {
                    const adInput = form.querySelector('[name="dob_ad"]') || form.querySelector('#dob_ad');
                    if (adInput && document.activeElement === e.target) {
                        const bsVal = e.target.value;
                        if (/^\d{4}-\d{2}-\d{2}$/.test(bsVal)) {
                            const converted = convertBStoAD(bsVal);
                            if (converted) {
                                adInput.value = converted;
                            }
                        }
                    }
                }
            }
        });

        // Auto-convert existing AD value to BS on page load if BS is empty
        document.querySelectorAll('form').forEach(form => {
            const adInput = form.querySelector('[name="dob_ad"]') || form.querySelector('#dob_ad');
            const bsInput = form.querySelector('[name="dob_bs"]') || form.querySelector('#dob_bs');
            if (adInput && bsInput && adInput.value && !bsInput.value) {
                bsInput.value = convertADtoBS(adInput.value);
            }
        });
    }
});

/**
 * MathJax 3 Devanagari / Nepali Ligature & Combining Matra Merger
 * 
 * MathJax CHTML output breaks individual Unicode characters into separate <mjx-utext> elements.
 * For Devanagari (Nepali Unicode), this splits consonants and combining vowel signs (matras like 
 * f-kar, e-kar, halanta) across different DOM nodes, causing the browser font engine to draw dotted 
 * circles (e.g. म ◌ि instead of मि).
 * 
 * This function merges adjacent Devanagari character nodes back into complete words so that 
 * the Kokila font engine renders ligatures, conjuncts, and matras flawlessly.
 */
function fixDevanagariInMathJax(root) {
    root = root || document;
    if (!root || !root.querySelectorAll) return;

    try {
        const containers = (root.matches && root.matches('mjx-container'))
            ? [root]
            : Array.from(root.querySelectorAll('mjx-container'));

        containers.forEach(container => {
            if (container.getAttribute('data-dev-fixed') === '1') return;

            // 1. Merge adjacent mjx-utext inside mjx-mtext (from \text{...} or \mathrm{...})
            container.querySelectorAll('mjx-mtext').forEach(mtext => {
                const utexts = Array.from(mtext.querySelectorAll('mjx-utext'));
                if (utexts.length > 1) {
                    let hasDev = false;
                    let str = '';
                    utexts.forEach(u => {
                        str += u.textContent;
                        if (/[\u0900-\u097F]/.test(u.textContent)) hasDev = true;
                    });
                    if (hasDev) {
                        utexts[0].textContent = str;
                        utexts[0].style.fontFamily = "'Kokila', 'Noto Sans Devanagari', 'Nirmala UI', 'Mangal', sans-serif";
                        utexts[0].style.fontStyle = 'normal';
                        for (let i = 1; i < utexts.length; i++) {
                            utexts[i].remove();
                        }
                    }
                } else if (utexts.length === 1 && /[\u0900-\u097F]/.test(utexts[0].textContent)) {
                    utexts[0].style.fontFamily = "'Kokila', 'Noto Sans Devanagari', 'Nirmala UI', 'Mangal', sans-serif";
                    utexts[0].style.fontStyle = 'normal';
                }
            });

            // 2. Merge consecutive mjx-mi / mjx-mo / mjx-mn elements with Devanagari characters (raw math mode)
            const groupingParents = container.querySelectorAll('mjx-mrow, mjx-num, mjx-den, mjx-math, mjx-over, mjx-under, mjx-script, mjx-root, mjx-sqrt, mjx-mtd');
            groupingParents.forEach(row => {
                const children = Array.from(row.children);
                let currentGroup = [];

                function mergeGroup(group) {
                    if (group.length === 0) return;
                    let fullText = group.map(el => el.textContent).join('');
                    if (/[\u0900-\u097F]/.test(fullText)) {
                        const first = group[0];
                        first.className = 'mjx-n';
                        const textNode = first.ownerDocument.createElement('mjx-utext');
                        textNode.style.cssText = "font-family: 'Kokila', 'Noto Sans Devanagari', 'Nirmala UI', 'Mangal', sans-serif !important; font-style: normal !important;";
                        textNode.textContent = fullText;
                        first.replaceChildren(textNode);
                        for (let i = 1; i < group.length; i++) {
                            group[i].remove();
                        }
                    }
                }

                children.forEach(child => {
                    if (child.tagName && ['MJX-MI', 'MJX-MO', 'MJX-MN', 'MJX-MS'].includes(child.tagName.toUpperCase()) && /[\u0900-\u097F]/.test(child.textContent)) {
                        currentGroup.push(child);
                    } else {
                        mergeGroup(currentGroup);
                        currentGroup = [];
                    }
                });
                mergeGroup(currentGroup);
            });

            container.setAttribute('data-dev-fixed', '1');
        });
    } catch (e) {
        console.error("MathJax Devanagari repair error:", e);
    }
}
window.fixDevanagariInMathJax = fixDevanagariInMathJax;

/**
 * MutationObserver to automatically detect newly inserted MathJax containers
 * and instantly fix Devanagari ligatures without needing manual event calls.
 */
function observeMathJaxDevanagari(targetDoc) {
    targetDoc = targetDoc || document;
    const Observer = targetDoc?.defaultView?.MutationObserver || (typeof MutationObserver === 'undefined' ? null : MutationObserver);
    if (!targetDoc || !targetDoc.body || !Observer) return null;

    // TinyMCE documents belong to a different window. Use that window's native
    // observer so its body is accepted as a Node in the correct realm.
    const observer = new Observer(mutations => {
        let needsFix = false;
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType === 1) {
                    if (node.tagName === 'MJX-CONTAINER' || (node.querySelector && node.querySelector('mjx-container'))) {
                        needsFix = true;
                        break;
                    }
                }
            }
            if (needsFix) break;
        }
        if (needsFix) {
            fixDevanagariInMathJax(targetDoc);
        }
    });

    observer.observe(targetDoc.body, { childList: true, subtree: true });
    return observer;
}
window.observeMathJaxDevanagari = observeMathJaxDevanagari;

// Auto-observe main document on load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => observeMathJaxDevanagari(document));
} else {
    observeMathJaxDevanagari(document);
}

// Console welcome message
console.log('%c Nepal School ERP ', 'background: #2c3e50; color: #fff; font-size: 20px; padding: 10px;');
console.log('%c Welcome to the system! ', 'color: #3498db; font-size: 14px;');
