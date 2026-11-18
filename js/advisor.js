// ============================================================
// CSCResultPortal - Advisor JavaScript
// File: js/advisor.js
// Purpose: Handles all interactive behavior on advisor pages:
//          - Carryover modal population
//          - Flag/forward confirmation dialogs
//          - Transcript print functionality
//          - Review panel interactions
//          - Student search live filtering
// ============================================================

'use strict';

// ============================================================
// SECTION 1: CARRYOVER PROCESSING MODAL
// Populates and opens the process modal for carryover items.
// ============================================================

/**
 * Opens the carryover processing modal for a specific request.
 * @param {number} carryoverId - The carryover registration ID
 * @param {string} courseCode  - Course code for display
 * @param {string} studentName - Student name for display
 */
function openCarryoverModal(carryoverId, courseCode, studentName) {
    const modal       = document.getElementById('carryoverModal');
    const idInput     = document.getElementById('modalCarryoverId');
    const description = document.getElementById('modalDescription');

    if (!modal || !idInput) return;

    // Set values in the modal
    idInput.value = carryoverId;

    if (description) {
        description.innerHTML =
            `Processing carryover for <strong>${escapeHtmlJs(studentName)}</strong> ` +
            `— Course: <strong class="text-green">${escapeHtmlJs(courseCode)}</strong>. ` +
            `Choose to approve or reject, and optionally add a note for the student.`;
    }

    // Open Bootstrap modal
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}


// ============================================================
// SECTION 2: TRANSCRIPT PRINT FUNCTION
// Reveals print-only headers and footers, then triggers print.
// Only the Course Advisor can use this function.
// ============================================================

/**
 * Prints the student transcript displayed on the page.
 * Reveals hidden transcript header/footer before printing.
 */
function printTranscript() {
    const printHeader = document.getElementById('transcriptPrintHeader');
    const printFooter = document.getElementById('transcriptPrintFooter');

    // Show print-only elements
    if (printHeader) printHeader.classList.remove('d-none');
    if (printFooter) printFooter.classList.remove('d-none');

    // Trigger browser print dialog
    window.print();

    // Re-hide print-only elements after print dialog closes
    setTimeout(() => {
        if (printHeader) printHeader.classList.add('d-none');
        if (printFooter) printFooter.classList.add('d-none');
    }, 1500);
}


// ============================================================
// SECTION 3: REVIEW RESULTS — FLAG FORM VALIDATION
// Ensures advisor has entered a reason before submitting flag.
// ============================================================
(function initFlagFormValidation() {
    const flagBtn = document.getElementById('flagBtn');
    if (!flagBtn) return;

    flagBtn.closest('form')?.addEventListener('submit', function (e) {
        const textarea = this.querySelector('textarea[name="flag_reason"]');
        if (!textarea) return;

        const reason = textarea.value.trim();
        if (reason.length < 10) {
            e.preventDefault();
            textarea.classList.add('is-invalid');

            // Show inline error
            let errMsg = textarea.nextElementSibling;
            if (!errMsg || !errMsg.classList.contains('invalid-feedback')) {
                errMsg = document.createElement('div');
                errMsg.className = 'invalid-feedback';
                textarea.parentNode.insertBefore(errMsg, textarea.nextSibling);
            }
            errMsg.textContent = 'Please provide a detailed reason (at least 10 characters).';
        }
    });

    // Clear validation on input
    const flagTextarea = document.querySelector('textarea[name="flag_reason"]');
    if (flagTextarea) {
        flagTextarea.addEventListener('input', function () {
            this.classList.remove('is-invalid');
        });
    }
})();


// ============================================================
// SECTION 4: FORWARD BUTTON CONFIRMATION
// Extra confirmation before forwarding to HOD.
// ============================================================
(function initForwardConfirmation() {
    const forwardBtn = document.getElementById('forwardBtn');
    if (!forwardBtn) return;

    forwardBtn.closest('form')?.addEventListener('submit', function (e) {
        const confirmed = confirm(
            'Are you sure you want to forward these results to the HOD?\n\n' +
            'Once forwarded, the results will be in the HOD\'s queue for approval. ' +
            'This action cannot be undone unless the HOD sends it back.'
        );
        if (!confirmed) {
            e.preventDefault();
        }
    });
})();


// ============================================================
// SECTION 5: PIPELINE STAGE TOOLTIPS
// Show count label on hover for pipeline stages on dashboard.
// ============================================================
(function initPipelineTooltips() {
    const stages = document.querySelectorAll('.pipeline-stage');
    stages.forEach(stage => {
        const label = stage.querySelector('.pipeline-stage-label')?.textContent.trim();
        const count = stage.querySelector('.pipeline-stage-count')?.textContent.trim();
        if (label && count) {
            stage.setAttribute('title', `${count} result(s) at "${label}" stage`);
        }
    });
})();


// ============================================================
// SECTION 6: REVIEW LIST SEARCH FILTER
// Filters the submissions list on the review-results page.
// ============================================================
(function initReviewListFilter() {
    const filterInput = document.getElementById('reviewListFilter');
    const reviewItems = document.querySelectorAll('.review-list-item');

    if (!filterInput || reviewItems.length === 0) return;

    filterInput.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        reviewItems.forEach(item => {
            const text = item.textContent.toLowerCase();
            item.style.display = text.includes(query) ? '' : 'none';
        });
    });
})();


// ============================================================
// SECTION 7: CARRYOVER TAB HIGHLIGHT
// Highlights the active tab with correct color on load.
// ============================================================
(function initCarryoverTabs() {
    const tabs = document.querySelectorAll('.carryover-tab');
    tabs.forEach(tab => {
        if (tab.classList.contains('active')) {
            const countBadge = tab.querySelector('.carryover-tab-count');
            if (countBadge) {
                countBadge.style.background = 'rgba(255,255,255,0.30)';
                countBadge.style.color = '#fff';
            }
        }
    });
})();


// ============================================================
// SECTION 8: UTILITY — HTML ESCAPE FOR JS STRINGS
// Prevents XSS when injecting user data into modal content.
// ============================================================

/**
 * Escapes HTML special characters in a JavaScript string.
 * @param {string} str
 * @returns {string}
 */
function escapeHtmlJs(str) {
    if (!str) return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}