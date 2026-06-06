/**
 * Main JavaScript File
 * Nepal School ERP
 */

// Sidebar toggle functionality
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapse');
            sidebar.classList.toggle('show');
        });
    }
    
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
            window.location.href = url;
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
if (typeof $.fn.DataTable !== 'undefined') {
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

// Console welcome message
console.log('%c Nepal School ERP ', 'background: #2c3e50; color: #fff; font-size: 20px; padding: 10px;');
console.log('%c Welcome to the system! ', 'color: #3498db; font-size: 14px;');
