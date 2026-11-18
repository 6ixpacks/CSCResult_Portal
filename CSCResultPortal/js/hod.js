// ============================================================
// CSCResultPortal - HOD JavaScript
// File: js/hod.js
// Purpose: Handles all interactive behavior on HOD pages:
//          - Password reset modal for manage-staff
//          - Password field visibility toggle
//          - CSV drag-and-drop upload on batch-upload
//          - Sample CSV file download generation
//          - Approval confirmation guards
//          - Create staff form live validation
// ============================================================

'use strict';

// ============================================================
// SECTION 1: RESET PASSWORD MODAL (manage-staff.php)
// ============================================================

/**
 * Opens the password reset modal for a specific staff user.
 * @param {number} userId   - Target user ID
 * @param {string} userName - Target user's full name
 */
function openResetModal(userId, userName) {
    const modal      = document.getElementById('resetPasswordModal');
    const userIdInput = document.getElementById('resetTargetUserId');
    const descEl     = document.getElementById('resetModalDesc');

    if (!modal || !userIdInput) return;

    userIdInput.value = userId;

    if (descEl) {
        descEl.innerHTML =
            `Resetting password for <strong>${escapeHtmlJs(userName)}</strong>. ` +
            `Enter a new password (minimum 8 characters).`;
    }

    // Clear previous password input
    const pwInput = modal.querySelector('input[name="new_password"]');
    if (pwInput) pwInput.value = '';

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}


// ============================================================
// SECTION 2: PASSWORD FIELD TOGGLE (manage-staff.php)
// Used for the create-staff form password fields.
// ============================================================

/**
 * Toggles a password field between text and password.
 * @param {string} fieldId  - ID of the input element
 * @param {string} iconId   - ID of the eye icon element
 */
function toggleFieldPassword(fieldId, iconId) {
    const field = document.getElementById(fieldId);
    const icon  = document.getElementById(iconId);
    if (!field || !icon) return;

    const isPassword = field.type === 'password';
    field.type = isPassword ? 'text' : 'password';
    icon.className = isPassword ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}


// ============================================================
// SECTION 3: CREATE STAFF FORM VALIDATION (manage-staff.php)
// Validates passwords match before submission.
// ============================================================
(function initCreateStaffForm() {
    const form    = document.getElementById('createStaffForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        const pw1 = form.querySelector('input[name="password"]')?.value  || '';
        const pw2 = form.querySelector('input[name="password2"]')?.value || '';
        const role = form.querySelector('select[name="role"]')?.value    || '';

        let hasError = false;

        if (pw1.length < 8) {
            alert('Password must be at least 8 characters.');
            hasError = true;
        } else if (pw1 !== pw2) {
            alert('Passwords do not match. Please re-enter.');
            hasError = true;
        } else if (!role) {
            alert('Please select a role for the new account.');
            hasError = true;
        }

        if (hasError) {
            e.preventDefault();
        }
    });
})();


// ============================================================
// SECTION 4: BATCH UPLOAD — FILE INPUT & DRAG-AND-DROP
// (batch-upload.php)
// ============================================================
(function initBatchUpload() {
    const dropzone        = document.getElementById('uploadDropzone');
    const fileInput       = document.getElementById('csvFile');
    const fileNameDisplay = document.getElementById('fileNameDisplay');
    const selectedFile    = document.getElementById('selectedFileName');
    const uploadForm      = document.getElementById('uploadForm');
    const uploadBtn       = document.getElementById('uploadBtn');

    if (!dropzone || !fileInput) return;

    // File selected via input
    fileInput.addEventListener('change', function () {
        updateFileDisplay(this.files[0]);
    });

    // Drag over
    dropzone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });

    dropzone.addEventListener('dragleave', function () {
        dropzone.classList.remove('dragover');
    });

    // Drop
    dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('dragover');

        const file = e.dataTransfer.files[0];
        if (file) {
            // Transfer file to the actual input
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            fileInput.files = dataTransfer.files;
            updateFileDisplay(file);
        }
    });

    // Display selected filename
    function updateFileDisplay(file) {
        if (!file) return;

        if (!file.name.toLowerCase().endsWith('.csv') &&
            !file.name.toLowerCase().endsWith('.txt')) {
            alert('Invalid file type. Please select a CSV file (.csv).');
            fileInput.value = '';
            if (selectedFile) selectedFile.classList.add('d-none');
            return;
        }

        if (fileNameDisplay) fileNameDisplay.textContent = file.name;
        if (selectedFile)    selectedFile.classList.remove('d-none');
    }

    // Upload form submit — show loading state
    if (uploadForm && uploadBtn) {
        uploadForm.addEventListener('submit', function () {
            if (!fileInput.files || fileInput.files.length === 0) return;
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = `
                <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                Processing Upload...
            `;
        });
    }
})();


// ============================================================
// SECTION 5: SAMPLE CSV DOWNLOAD (batch-upload.php)
// Generates and downloads a sample CSV file.
// ============================================================

/**
 * Creates and downloads a sample CSV file for batch upload.
 */
function downloadSampleCSV() {
    const csvContent = [
        'matric_number,course_code,ca_score,exam_score',
        'CSC/2020/001,CSC 301,25,55',
        'CSC/2020/002,CSC 301,28,60',
        'CSC/2020/003,CSC 301,20,45',
        'CSC/2020/004,CSC 301,30,65',
        'CSC/2020/005,CSC 301,15,35',
    ].join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.setAttribute('href',     url);
    link.setAttribute('download', 'CSCResultPortal_BatchUpload_Sample.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}


// ============================================================
// SECTION 6: ANALYTICS PAGE — ANIMATE PROGRESS BARS
// Animates level CGPA bars and pass-rate bars on page load.
// ============================================================
(function animateProgressBars() {
    const bars = document.querySelectorAll('.level-cgpa-fill, .analytics-bar-fill');
    if (bars.length === 0) return;

    // Set initial width to 0, then animate to target
    bars.forEach(bar => {
        const targetWidth = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = targetWidth;
        }, 200);
    });
})();


// ============================================================
// SECTION 7: HOD APPROVAL CONFIRMATION GUARDS
// Extra safety confirmation on critical approve actions.
// ============================================================
(function initApprovalGuards() {
    // Final approval buttons
    const finalApproveButtons = document.querySelectorAll('button[onclick*="Grant FINAL"]');
    finalApproveButtons.forEach(btn => {
        // Already handled by inline onclick — no extra listener needed
    });

    // Prevent double-click on all submit buttons
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function () {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.classList.contains('allow-multi-submit')) {
                setTimeout(() => {
                    submitBtn.disabled = true;
                }, 100);
            }
        });
    });
})();


// ============================================================
// SECTION 8: MANAGE STAFF SEARCH FILTER
// Filters staff table rows by search input.
// ============================================================
(function initStaffSearch() {
    const searchInput = document.getElementById('staffSearchInput');
    if (!searchInput) return;

    searchInput.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        const rows  = document.querySelectorAll('.portal-table tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });
})();


// ============================================================
// SECTION 9: UTILITY — HTML ESCAPE
// ============================================================

/**
 * Escapes HTML special characters to prevent XSS.
 * @param {string} str
 * @returns {string}
 */
function escapeHtmlJs(str) {
    if (!str) return '';
    return str
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#039;');
}