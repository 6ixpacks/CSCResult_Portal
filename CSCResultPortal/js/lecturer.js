// ============================================================
// CSCResultPortal - Lecturer JavaScript
// File: js/lecturer.js
// Purpose: Handles interactive features on lecturer pages:
//          - Live CA + Exam = Total calculation
//          - Nigerian 5.0 Scale letter grade preview
//          - Real-time boundary validation against CA/Exam max
//          - Search filtering for student lists
//          - Confirmation modal before submitting to advisor
// ============================================================

'use strict';

// ============================================================
// SECTION 1: NIGERIAN 5.0 GRADING SCALE HELPER
// ============================================================
/**
 * Converts a total score (0-100) into a Nigerian letter grade.
 * @param {number} totalScore
 * @returns {string} Grade letter (A, B, C, D, or F)
 */
function getNigerianGrade(totalScore) {
    if (totalScore >= 70) return 'A';
    if (totalScore >= 60) return 'B';
    if (totalScore >= 50) return 'C';
    if (totalScore >= 45) return 'D';
    return 'F';
}

/**
 * Returns HTML badge markup for a letter grade.
 * @param {string} grade 
 * @returns {string}
 */
function getGradeBadgeHtml(grade) {
    const gradeLower = grade.toLowerCase();
    return `<span class="grade-badge grade-${gradeLower}">${grade}</span>`;
}

// ============================================================
// SECTION 2: LIVE SCORE CALCULATION & VALIDATION (enter-scores.php)
// ============================================================
(function initScoreEntryCalculator() {
    const scoreTable = document.getElementById('studentScoresTable');
    if (!scoreTable) return;

    const rows = scoreTable.querySelectorAll('tbody tr');

    rows.forEach(row => {
        const caInput   = row.querySelector('.ca-input');
        const examInput = row.querySelector('.exam-input');
        const totalCell = row.querySelector('.total-cell');
        const gradeCell = row.querySelector('.grade-cell');

        if (!caInput || !examInput) return;

        const caMax   = parseFloat(caInput.dataset.max)   || 30;
        const examMax = parseFloat(examInput.dataset.max) || 70;

        function updateTotals() {
            const caVal   = caInput.value.trim();
            const examVal = examInput.value.trim();

            let caNum   = caVal !== ''   ? parseFloat(caVal)   : null;
            let examNum = examVal !== '' ? parseFloat(examVal) : null;

            // Validate CA Score boundary
            if (caNum !== null && (caNum < 0 || caNum > caMax)) {
                caInput.classList.add('is-invalid');
            } else {
                caInput.classList.remove('is-invalid');
            }

            // Validate Exam Score boundary
            if (examNum !== null && (examNum < 0 || examNum > examMax)) {
                examInput.classList.add('is-invalid');
            } else {
                examInput.classList.remove('is-invalid');
            }

            // Compute Total and Grade if both fields contain valid numbers
            if (
                caNum !== null && !isNaN(caNum) && caNum >= 0 && caNum <= caMax &&
                examNum !== null && !isNaN(examNum) && examNum >= 0 && examNum <= examMax
            ) {
                const total = (caNum + examNum).toFixed(1);
                const grade = getNigerianGrade(parseFloat(total));

                totalCell.textContent = total;
                gradeCell.innerHTML   = getGradeBadgeHtml(grade);
            } else {
                totalCell.textContent = '--';
                gradeCell.innerHTML   = '--';
            }
        }

        // Attach input event listeners for live feedback
        caInput.addEventListener('input', updateTotals);
        examInput.addEventListener('input', updateTotals);
    });
})();

// ============================================================
// SECTION 3: STUDENT SEARCH FILTER (enter-scores.php)
// ============================================================
(function initStudentSearchFilter() {
    const searchInput = document.getElementById('studentSearchInput');
    const table       = document.getElementById('studentScoresTable');

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
// SECTION 4: FORM SUBMISSION VALIDATION & CONFIRMATION
// ============================================================
(function initScoreSubmissionGuard() {
    const scoreForm = document.getElementById('scoreEntryForm');
    if (!scoreForm) return;

    scoreForm.addEventListener('submit', function (e) {
        // Find which button triggered the submit
        const submitter = e.submitter;
        if (!submitter) return;

        // Check if there are any invalid input fields (red border)
        const invalidInputs = scoreForm.querySelectorAll('.score-input.is-invalid');
        if (invalidInputs.length > 0) {
            e.preventDefault();
            alert('Error: Please correct invalid scores (highlighted in red) exceeding the maximum allowed limits before saving.');
            invalidInputs[0].focus();
            return;
        }

        // If clicking "Submit to Advisor", request confirmation
        if (submitter.value === 'submit_scores') {
            const confirmed = confirm(
                "Are you sure you want to SUBMIT these scores to the Course Advisor?\n\n" +
                "Once submitted, scores will enter the departmental review workflow and cannot be edited unless flagged back."
            );
            if (!confirmed) {
                e.preventDefault();
            }
        }
    });
})();