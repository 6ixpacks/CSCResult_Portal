// ============================================================
// CSCResultPortal - Student JavaScript
// File: js/student.js
// Purpose: Handles all interactive behavior on student pages.
//          Carryover checkbox management, form validation,
//          result table filtering, and UI helpers.
// ============================================================

'use strict';

// ============================================================
// SECTION 1: CARRYOVER REGISTRATION PAGE
// Handles checkbox selection, count display, form submit guard
// ============================================================
(function initCarryoverPage() {
    const selectAllBtn    = document.getElementById('selectAllBtn');
    const clearAllBtn     = document.getElementById('clearAllBtn');
    const selectedCount   = document.getElementById('selectedCount');
    const submitBtn       = document.getElementById('submitCarryoverBtn');
    const carryoverForm   = document.getElementById('carryoverForm');
    const checkboxes      = document.querySelectorAll('.carryover-check');

    if (!carryoverForm || checkboxes.length === 0) return;

    // Update selected count display
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.carryover-check:checked').length;
        if (selectedCount) {
            selectedCount.textContent = `${checked} course${checked !== 1 ? 's' : ''} selected`;
        }
    }

    // Attach change listener to each checkbox
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    // Select all
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => { cb.checked = true; });
            updateSelectedCount();
        });
    }

    // Clear all
    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => { cb.checked = false; });
            updateSelectedCount();
        });
    }

    // Form submit validation
    if (carryoverForm) {
        carryoverForm.addEventListener('submit', (e) => {
            const checked = document.querySelectorAll('.carryover-check:checked').length;
            if (checked === 0) {
                e.preventDefault();
                alert('Please select at least one course to register for carryover.');
                return;
            }

            // Disable submit button to prevent double submission
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `
                    <span class="spinner-border spinner-border-sm me-2"
                          role="status" aria-hidden="true"></span>
                    Submitting...
                `;
            }
        });
    }

    // Init count on page load
    updateSelectedCount();

})();


// ============================================================
// SECTION 2: RESULTS TABLE — SEARCH/FILTER (My Results page)
// ============================================================
(function initResultsFilter() {
    const searchInput = document.getElementById('resultSearchInput');
    const table       = document.getElementById('resultsTable');

    if (!searchInput || !table) return;

    searchInput.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        const rows  = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });
})();


// ============================================================
// SECTION 3: AUTO-HIGHLIGHT ACTIVE SEMESTER IN FILTER
// ============================================================
(function initSemesterFilter() {
    const filterForm = document.getElementById('semesterFilterForm');
    if (!filterForm) return;

    const select = filterForm.querySelector('select[name="semester_id"]');
    if (select) {
        // Add subtle highlight to current option
        const selected = select.options[select.selectedIndex];
        if (selected && selected.value) {
            select.style.borderColor = '#006400';
        }
    }
})();


// ============================================================
// SECTION 4: GRADE ROW HIGHLIGHT
// Highlights failed (grade F) rows in result tables
// ============================================================
(function highlightFailedGrades() {
    const gradeBadges = document.querySelectorAll('.grade-badge.grade-f');
    gradeBadges.forEach(badge => {
        const row = badge.closest('tr');
        if (row) {
            row.style.background = 'rgba(220,53,69,0.04)';
        }
    });
})();


// ============================================================
// SECTION 5: CARRYOVER COURSE ITEM CLICK-TO-SELECT
// Clicking anywhere on the course item toggles the checkbox
// ============================================================
(function initCarryoverItemClick() {
    const items = document.querySelectorAll('.carryover-course-item');
    items.forEach(item => {
        item.addEventListener('click', function (e) {
            // Don't double-trigger when clicking directly on checkbox or label
            if (e.target.type === 'checkbox') return;
            if (e.target.tagName === 'LABEL') return;

            const cb = this.querySelector('.carryover-check');
            if (cb) {
                cb.checked = !cb.checked;
                // Trigger change event for count update
                cb.dispatchEvent(new Event('change'));
            }
        });
    });
})();


// ============================================================
// SECTION 6: CGPA OVERVIEW PAGE — SEMESTER ACCORDION
// Click on semester block header to expand/collapse table
// ============================================================
(function initSemesterAccordion() {
    const headers = document.querySelectorAll('.semester-block-header');
    if (headers.length === 0) return;

    headers.forEach(header => {
        // Add toggle icon
        const toggleIcon = document.createElement('span');
        toggleIcon.innerHTML = '<i class="fa-solid fa-chevron-up ms-2" style="font-size:12px; color:#006400;"></i>';
        header.appendChild(toggleIcon);
        header.style.cursor = 'pointer';

        const block = header.closest('.semester-block');
        const table = block ? block.querySelector('table') : null;

        if (!table) return;

        header.addEventListener('click', () => {
            const isVisible = table.style.display !== 'none';
            table.style.display = isVisible ? 'none' : '';

            const icon = toggleIcon.querySelector('i');
            if (icon) {
                icon.className = isVisible
                    ? 'fa-solid fa-chevron-down ms-2'
                    : 'fa-solid fa-chevron-up ms-2';
                icon.style.color = '#006400';
                icon.style.fontSize = '12px';
            }
        });
    });
})();


// ============================================================
// SECTION 7: PRINT RESULTS (only triggered from advisor role)
// Students themselves cannot print transcripts.
// This placeholder is here only for completeness.
// ============================================================
window.printResultsTable = function () {
    console.warn('Students cannot print transcripts. Contact the Course Advisor.');
};