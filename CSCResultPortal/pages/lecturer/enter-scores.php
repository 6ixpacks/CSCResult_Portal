<?php
// ============================================================
// CSCResultPortal - Lecturer Enter Scores (TEST + LAB + EXAM)
// File: pages/lecturer/enter-scores.php
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../mail/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $_SESSION['role'] !== 'lecturer'
) {
    header('Location: ' . APP_URL . '/index.php');
    exit();
}

if (
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT
) {
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/index.php?expired=1');
    exit();
}
$_SESSION['last_activity'] = time();

$lecturerId = (int) $_SESSION['user_id'];

// Validate assignment
$assignmentId = isset($_GET['assignment_id']) ? (int) $_GET['assignment_id'] : 0;
$assignment   = null;

if ($assignmentId > 0) {
    try {
        $assignment = dbFetchOne($pdo,
            "SELECT ca.*, c.course_code, c.course_title, c.credit_units, c.level,
                    s.semester_name, ac.session_name
             FROM course_assignments ca
             JOIN courses c ON ca.course_id = c.id
             JOIN semesters s ON ca.semester_id = s.id
             JOIN academic_sessions ac ON s.session_id = ac.id
             WHERE ca.id = ? AND ca.lecturer_id = ?",
            [$assignmentId, $lecturerId]
        );
    } catch (PDOException $e) {
        $assignment = null;
    }
}

$myAssignments = [];
if (!is_array($assignment)) {
    try {
        $myAssignments = dbFetchAll($pdo,
            "SELECT ca.id AS assignment_id, c.course_code, c.course_title, c.level,
                    s.semester_name, ac.session_name
             FROM course_assignments ca
             JOIN courses c ON ca.course_id = c.id
             JOIN semesters s ON ca.semester_id = s.id
             JOIN academic_sessions ac ON s.session_id = ac.id
             WHERE ca.lecturer_id = ?
             ORDER BY ac.id DESC, s.id DESC, c.course_code ASC",
            [$lecturerId]
        );
    } catch (PDOException $e) {
        $myAssignments = [];
    }
}

// ============================================================
// HANDLE CA/LAB/EXAM SPLIT UPDATE
// ============================================================
$formMessage = '';
$formSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_split']) && is_array($assignment)) {
    $caMax   = (int) ($_POST['ca_max']   ?? 30);
    $labMax  = (int) ($_POST['lab_max']  ?? 0);
    $examMax = (int) ($_POST['exam_max'] ?? 70);
    $hasLab  = ($labMax > 0) ? 1 : 0;

    if (($caMax + $labMax + $examMax) !== 100) {
        $formMessage = 'Error: TEST + LAB + EXAM must add up to exactly 100. Current total: ' . ($caMax + $labMax + $examMax);
    } else {
        try {
            dbExecute($pdo,
                "UPDATE course_assignments 
                 SET ca_max = ?, lab_max = ?, exam_max = ?, has_lab = ? 
                 WHERE id = ? AND lecturer_id = ?",
                [$caMax, $labMax, $examMax, $hasLab, $assignment['id'], $lecturerId]
            );
            $assignment['ca_max']   = $caMax;
            $assignment['lab_max']  = $labMax;
            $assignment['exam_max'] = $examMax;
            $assignment['has_lab']  = $hasLab;
            $formSuccess = true;
            
            if ($hasLab) {
                $formMessage = "Grading split updated: TEST {$caMax}% + LAB {$labMax}% + EXAM {$examMax}% = 100%";
            } else {
                $formMessage = "Grading split updated: TEST {$caMax}% + EXAM {$examMax}% = 100% (No LAB)";
            }
            
            writeAuditLog($pdo, $lecturerId, 'update_course_split', 'course_assignments', $assignment['id'], 
                null, ['ca_max' => $caMax, 'lab_max' => $labMax, 'exam_max' => $examMax]);
        } catch (PDOException $e) {
            $formMessage = 'Database error updating grading split.';
            logDatabaseError('Split update error: ' . $e->getMessage());
        }
    }
}

// ============================================================
// HANDLE SCORE SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_action']) && is_array($assignment)) {
    $action  = $_POST['save_action'];
    $scores  = $_POST['scores'] ?? [];
    $caMax   = (int) ($assignment['ca_max']   ?? 30);
    $labMax  = (int) ($assignment['lab_max']  ?? 0);
    $examMax = (int) ($assignment['exam_max'] ?? 70);
    $hasLab  = (int) ($assignment['has_lab']  ?? 0) === 1;

    try {
        $pdo->beginTransaction();
        $processedCount = 0;

        foreach ($scores as $studentId => $row) {
            $studentId = (int) $studentId;
            $caScore   = (isset($row['ca']) && $row['ca'] !== '' && is_numeric($row['ca']))     ? (float) $row['ca']   : null;
            $labScore  = (isset($row['lab']) && $row['lab'] !== '' && is_numeric($row['lab']))  ? (float) $row['lab']  : null;
            $examScore = (isset($row['exam']) && $row['exam'] !== '' && is_numeric($row['exam'])) ? (float) $row['exam'] : null;

            // Validate boundaries
            if ($caScore !== null && ($caScore < 0 || $caScore > $caMax)) continue;
            if ($hasLab && $labScore !== null && ($labScore < 0 || $labScore > $labMax)) continue;
            if ($examScore !== null && ($examScore < 0 || $examScore > $examMax)) continue;

            $totalScore = null;
            $grade      = null;
            $gradePoint = null;
            $qualPoint  = null;

            // If has lab, all three required. Else only CA + Exam needed.
            $canCompute = $hasLab
                ? ($caScore !== null && $labScore !== null && $examScore !== null)
                : ($caScore !== null && $examScore !== null);

            if ($canCompute) {
                $totalScore = $caScore + ($labScore ?? 0) + $examScore;
                $grade      = computeGrade($totalScore);
                $gradePoint = computeGradePoint($grade);
                $qualPoint  = computeQualityPoint($gradePoint, (int) ($assignment['credit_units'] ?? 0));
            }

            $targetStatus = ($action === 'submit_scores') ? 'submitted' : 'draft';

            $existing = dbFetchOne($pdo,
                "SELECT id, status FROM results 
                 WHERE student_id = ? AND course_id = ? AND semester_id = ? AND assignment_id = ?",
                [$studentId, $assignment['course_id'], $assignment['semester_id'], $assignment['id']]
            );

            if (is_array($existing)) {
                if (in_array($existing['status'], ['hod_approved', 'final_approved'])) {
                    continue;
                }

                if ($existing['status'] === 'flagged' && $action === 'submit_scores') {
                    dbExecute($pdo,
                        "UPDATE result_flags SET is_resolved = 1, resolved_at = NOW() 
                         WHERE result_id = ? AND is_resolved = 0",
                        [$existing['id']]
                    );
                }

                dbExecute($pdo,
                    "UPDATE results 
                     SET ca_score = ?, lab_score = ?, exam_score = ?, total_score = ?, 
                         grade = ?, grade_point = ?, quality_point = ?, 
                         status = ?, submitted_at = NOW()
                     WHERE id = ?",
                    [$caScore, $labScore, $examScore, $totalScore, $grade, $gradePoint, $qualPoint, $targetStatus, $existing['id']]
                );
            } else {
                dbInsert($pdo,
                    "INSERT INTO results 
                        (student_id, course_id, semester_id, assignment_id, 
                         ca_score, lab_score, exam_score, total_score, grade, grade_point, 
                         credit_units, quality_point, status, submitted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                    [$studentId, $assignment['course_id'], $assignment['semester_id'], $assignment['id'], 
                     $caScore, $labScore, $examScore, $totalScore, $grade, $gradePoint, 
                     (int) ($assignment['credit_units'] ?? 0), $qualPoint, $targetStatus]
                );
            }
            $processedCount++;
        }

        if ($action === 'submit_scores') {
            dbExecute($pdo,
                "UPDATE course_assignments SET scores_submitted = 1, submitted_at = NOW() WHERE id = ?",
                [$assignment['id']]
            );
            $assignment['scores_submitted'] = 1;

            $advisor  = dbFetchOne($pdo, "SELECT id, full_name, email FROM users WHERE role = 'advisor' AND is_active = 1 LIMIT 1");
            $lecturer = dbFetchOne($pdo, "SELECT full_name FROM users WHERE id = ?", [$lecturerId]);

            if (is_array($advisor) && is_array($lecturer)) {
                sendLecturerSubmissionEmail(
                    (int) $advisor['id'],
                    $advisor['email'],
                    $advisor['full_name'],
                    $lecturer['full_name'],
                    $assignment['course_code'],
                    $assignment['course_title'],
                    $assignment['semester_name'],
                    $assignment['session_name'],
                    $assignment['id'],
                    $lecturerId
                );
            }

            writeAuditLog($pdo, $lecturerId, 'submit_scores', 'course_assignments', $assignment['id'], null, ['count' => $processedCount]);
            $formSuccess = true;
            $formMessage = "Scores successfully submitted to Course Advisor for {$processedCount} student(s).";
        } else {
            writeAuditLog($pdo, $lecturerId, 'save_scores_draft', 'course_assignments', $assignment['id'], null, ['count' => $processedCount]);
            $formSuccess = true;
            $formMessage = "Draft scores saved for {$processedCount} student(s).";
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $formMessage = 'A database error occurred while saving scores.';
        logDatabaseError('Score entry error: ' . $e->getMessage());
    }
}

// ============================================================
// FETCH STUDENTS WITH PAGINATION (50 per page)
// ============================================================
$students     = [];
$pagination   = null;
$currentPage  = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$perPage      = 50;

if (is_array($assignment)) {
    try {
        // Count only APPROVED registrations
        $totalStudents = dbCount($pdo,
            "SELECT COUNT(*) FROM student_course_registrations
             WHERE course_id = ? AND semester_id = ? AND status = 'approved'",
            [$assignment['course_id'], $assignment['semester_id']]
        );

        $pagination = paginate($totalStudents, $currentPage, $perPage);

        $students = dbFetchAll($pdo,
            "SELECT u.id AS student_id, u.full_name, u.matric_number,
                    r.ca_score, r.lab_score, r.exam_score, r.total_score, r.grade, r.status,
                    r.id AS result_id,
                    (SELECT rf.flag_reason FROM result_flags rf 
                     WHERE rf.result_id = r.id AND rf.is_resolved = 0 
                     ORDER BY rf.created_at DESC LIMIT 1) AS flag_reason
             FROM student_course_registrations scr
             JOIN users u ON scr.student_id = u.id
             LEFT JOIN results r ON r.student_id = u.id AND r.assignment_id = ?
             WHERE scr.course_id = ? AND scr.semester_id = ?
               AND scr.status = 'approved'
             ORDER BY u.matric_number ASC
             LIMIT " . (int)$pagination['per_page'] . " OFFSET " . (int)$pagination['offset'],
            [$assignment['id'], $assignment['course_id'], $assignment['semester_id']]
        );
    } catch (PDOException $e) {
        $students = [];
    }
}

$hasLab = is_array($assignment) && (int) ($assignment['has_lab'] ?? 0) === 1;

$pageTitle      = 'Enter Scores';
$pageBreadcrumb = 'Lecturer / Enter Scores';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Scores &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/lecturer.css">
</head>
<body>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="portal-wrapper">
    <?php require_once __DIR__ . '/../../includes/lecturer-sidebar.php'; ?>
    <div class="portal-main">
        <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

        <main class="portal-content">
            <div class="page-header">
                <h1 class="page-header-title">
                    <i class="fa-solid fa-pen-to-square me-2 text-green"></i>Enter Student Scores
                </h1>
                <p class="page-header-subtitle">
                    Input TEST (CA), LAB (if applicable), and EXAM scores for registered students.
                </p>
                <hr class="page-header-divider">
            </div>

            <?php if (!empty($formMessage)): ?>
                <div class="portal-alert <?= $formSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $formSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($formMessage) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!is_array($assignment)): ?>
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="content-card-title">
                            <i class="fa-solid fa-hand-pointer"></i> Select Course Assignment
                        </div>
                    </div>
                    <div class="content-card-body">
                        <?php if (count($myAssignments) > 0): ?>
                            <p class="text-muted mb-3">Please choose a course below to begin entering or updating student scores:</p>
                            <div class="list-group">
                                <?php foreach ($myAssignments as $ma): ?>
                                    <a href="enter-scores.php?assignment_id=<?= (int) ($ma['assignment_id'] ?? 0) ?>" 
                                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                                        <div>
                                            <span class="fw-700 text-green me-2"><?= htmlspecialchars($ma['course_code'] ?? '') ?></span>
                                            <span><?= htmlspecialchars($ma['course_title'] ?? '') ?></span>
                                            <small class="text-muted d-block mt-1">
                                                <?= htmlspecialchars($ma['session_name'] ?? '') ?> &mdash; <?= htmlspecialchars($ma['semester_name'] ?? '') ?> (<?= htmlspecialchars($ma['level'] ?? '') ?>L)
                                            </small>
                                        </div>
                                        <span class="btn-portal-outline btn-portal-sm">
                                            Select Course &rarr;
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-empty-state">
                                <i class="fa-solid fa-book-open"></i>
                                <p>You currently have no course assignments.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                
                <div class="row g-4 mb-4">
                    <div class="col-lg-6">
                        <div class="content-card h-100">
                            <div class="content-card-header">
                                <div class="content-card-title">
                                    <i class="fa-solid fa-book"></i> Course Details
                                </div>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($assignment['level'] ?? '') ?>L</span>
                            </div>
                            <div class="content-card-body">
                                <h4 class="fw-700 text-green mb-1">
                                    <?= htmlspecialchars($assignment['course_code'] ?? '') ?> &mdash; <?= htmlspecialchars($assignment['course_title'] ?? '') ?>
                                </h4>
                                <p class="text-muted mb-3">
                                    <?= htmlspecialchars($assignment['session_name'] ?? '') ?> &mdash; <?= htmlspecialchars($assignment['semester_name'] ?? '') ?> 
                                    (<?= (int) ($assignment['credit_units'] ?? 0) ?> Units)
                                </p>
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <span class="text-muted-sm">Students: <strong><?= count($students) ?></strong></span>
                                    <span class="text-muted-sm">&bull;</span>
                                    <span class="text-muted-sm">
                                        Split: 
                                        <strong class="text-green">
                                            TEST <?= (int) ($assignment['ca_max'] ?? 0) ?>%
                                            <?php if ($hasLab): ?>
                                                + LAB <?= (int) ($assignment['lab_max'] ?? 0) ?>%
                                            <?php endif; ?>
                                            + EXAM <?= (int) ($assignment['exam_max'] ?? 0) ?>%
                                        </strong>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="content-card h-100">
                            <div class="content-card-header">
                                <div class="content-card-title">
                                    <i class="fa-solid fa-sliders"></i> Customize Score Split
                                </div>
                            </div>
                            <div class="content-card-body">
                                <form method="POST" action="enter-scores.php?assignment_id=<?= (int) ($assignment['id'] ?? 0) ?>">
                                    <input type="hidden" name="update_split" value="1">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-4">
                                            <label class="portal-form-label">TEST</label>
                                            <input type="number" name="ca_max" id="caMaxInput" 
                                                   class="portal-form-control split-input" 
                                                   value="<?= (int) ($assignment['ca_max'] ?? 30) ?>" 
                                                   min="0" max="100" required>
                                        </div>
                                        <div class="col-4">
                                            <label class="portal-form-label">LAB</label>
                                            <input type="number" name="lab_max" id="labMaxInput" 
                                                   class="portal-form-control split-input" 
                                                   value="<?= (int) ($assignment['lab_max'] ?? 0) ?>" 
                                                   min="0" max="100" required>
                                        </div>
                                        <div class="col-4">
                                            <label class="portal-form-label">EXAM</label>
                                            <input type="number" name="exam_max" id="examMaxInput" 
                                                   class="portal-form-control split-input" 
                                                   value="<?= (int) ($assignment['exam_max'] ?? 70) ?>" 
                                                   min="0" max="100" required>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-muted-sm text-center">
                                        Total: <strong id="splitTotal">100</strong> / 100
                                        <span id="splitError" class="text-danger d-none">
                                            (must equal 100)
                                        </span>
                                    </div>
                                    <div class="text-muted-sm mt-1" style="font-size:11px;">
                                        <i class="fa-solid fa-info-circle"></i> Set LAB to 0 if course has no lab component.
                                    </div>
                                    <div class="d-grid mt-3">
                                        <button type="submit" class="btn-portal-outline btn-portal-sm">
                                            <i class="fa-solid fa-arrows-rotate"></i> Update Score Split
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="content-card-title">
                            <i class="fa-solid fa-table"></i> Registered Student Scores
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <input type="text" id="studentSearchInput" class="portal-form-control" 
                                   placeholder="Search student or matric..." style="max-width: 240px; font-size:13px;">
                            <a href="<?= APP_URL ?>/pages/shared/grade-report.php?assignment_id=<?= (int) ($assignment['id'] ?? 0) ?>" 
                               target="_blank"
                               class="btn-portal-gold btn-portal-sm">
                                <i class="fa-solid fa-print"></i> Print for Senate
                            </a>
                        </div>
                    </div>

                    <?php if (count($students) > 0): ?>
                        <form method="POST" action="enter-scores.php?assignment_id=<?= (int) ($assignment['id'] ?? 0) ?>" id="scoreEntryForm">
                            <div style="overflow-x:auto;">
                                <table class="portal-table score-entry-table" id="studentScoresTable">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;">#</th>
                                            <th>Matric Number</th>
                                            <th>Student Name</th>
                                            <th class="text-center" style="width:110px;">
                                                TEST<br>
                                                <small style="font-weight:400;">(Max <?= (int) ($assignment['ca_max'] ?? 30) ?>)</small>
                                            </th>
                                            <?php if ($hasLab): ?>
                                                <th class="text-center" style="width:110px;">
                                                    LAB<br>
                                                    <small style="font-weight:400;">(Max <?= (int) ($assignment['lab_max'] ?? 0) ?>)</small>
                                                </th>
                                            <?php endif; ?>
                                            <th class="text-center" style="width:110px;">
                                                EXAM<br>
                                                <small style="font-weight:400;">(Max <?= (int) ($assignment['exam_max'] ?? 70) ?>)</small>
                                            </th>
                                            <th class="text-center" style="width:80px;">Total</th>
                                            <th class="text-center" style="width:70px;">Grade</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $s): ?>
                                            <?php
                                            $isLocked  = in_array($s['status'] ?? '', ['hod_approved', 'final_approved']);
                                            $isFlagged = ($s['status'] ?? '') === 'flagged';
                                            ?>
                                            <tr class="<?= $isFlagged ? 'table-danger-subtle' : '' ?>">
                                                <td class="text-muted-sm"><?= $index + 1 ?></td>
                                                <td class="fw-700"><?= htmlspecialchars($s['matric_number'] ?? '') ?></td>
                                                <td>
                                                    <?= htmlspecialchars($s['full_name'] ?? '') ?>
                                                    <?php if ($isFlagged && !empty($s['flag_reason'])): ?>
                                                        <div class="text-danger small mt-1">
                                                            <i class="fa-solid fa-flag me-1"></i><strong>Advisor Note:</strong> <?= htmlspecialchars($s['flag_reason']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <input type="number" 
                                                           name="scores[<?= (int) ($s['student_id'] ?? 0) ?>][ca]" 
                                                           class="portal-form-control score-input ca-input text-center" 
                                                           value="<?= isset($s['ca_score']) && $s['ca_score'] !== null ? htmlspecialchars((string) $s['ca_score']) : '' ?>" 
                                                           min="0" max="<?= (int) ($assignment['ca_max'] ?? 30) ?>" step="0.5" 
                                                           data-max="<?= (int) ($assignment['ca_max'] ?? 30) ?>"
                                                           <?= $isLocked ? 'disabled' : '' ?>>
                                                </td>
                                                <?php if ($hasLab): ?>
                                                    <td class="text-center">
                                                        <input type="number" 
                                                               name="scores[<?= (int) ($s['student_id'] ?? 0) ?>][lab]" 
                                                               class="portal-form-control score-input lab-input text-center" 
                                                               value="<?= isset($s['lab_score']) && $s['lab_score'] !== null ? htmlspecialchars((string) $s['lab_score']) : '' ?>" 
                                                               min="0" max="<?= (int) ($assignment['lab_max'] ?? 0) ?>" step="0.5" 
                                                               data-max="<?= (int) ($assignment['lab_max'] ?? 0) ?>"
                                                               <?= $isLocked ? 'disabled' : '' ?>>
                                                    </td>
                                                <?php else: ?>
                                                    <input type="hidden" name="scores[<?= (int) ($s['student_id'] ?? 0) ?>][lab]" value="">
                                                <?php endif; ?>
                                                <td class="text-center">
                                                    <input type="number" 
                                                           name="scores[<?= (int) ($s['student_id'] ?? 0) ?>][exam]" 
                                                           class="portal-form-control score-input exam-input text-center" 
                                                           value="<?= isset($s['exam_score']) && $s['exam_score'] !== null ? htmlspecialchars((string) $s['exam_score']) : '' ?>" 
                                                           min="0" max="<?= (int) ($assignment['exam_max'] ?? 70) ?>" step="0.5" 
                                                           data-max="<?= (int) ($assignment['exam_max'] ?? 70) ?>"
                                                           <?= $isLocked ? 'disabled' : '' ?>>
                                                </td>
                                                <td class="text-center fw-700 total-cell">
                                                    <?= isset($s['total_score']) && $s['total_score'] !== null ? number_format((float) $s['total_score'], 1) : '--' ?>
                                                </td>
                                                <td class="text-center grade-cell">
                                                    <?php if (!empty($s['grade'])): ?>
                                                        <span class="grade-badge grade-<?= strtolower($s['grade']) ?>">
                                                            <?= htmlspecialchars($s['grade']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        --
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($isFlagged): ?>
                                                        <span class="status-badge flagged">Flagged</span>
                                                    <?php elseif (($s['status'] ?? '') === 'submitted'): ?>
                                                        <span class="status-badge submitted">Review</span>
                                                    <?php elseif ($isLocked): ?>
                                                        <span class="status-badge final_approved">Approved</span>
                                                    <?php else: ?>
                                                        <span class="status-badge draft">Draft</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if ($pagination && $pagination['total_pages'] > 1): ?>
    <?= renderPagination(
        $pagination,
        APP_URL . '/pages/lecturer/enter-scores.php',
        ['assignment_id' => $assignmentId]
    ) ?>
<?php endif; ?>
                            </div>

                            <div class="content-card-footer d-flex justify-content-between align-items-center flex-wrap gap-3">
                                <div class="text-muted-sm">
                                    <i class="fa-solid fa-info-circle me-1"></i> 
                                    <?php if ($hasLab): ?>
                                        TEST + LAB + EXAM = TOTAL. All three required for grading.
                                    <?php else: ?>
                                        TEST + EXAM = TOTAL. Both required for grading.
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <a href="<?= APP_URL ?>/pages/shared/grade-report.php?assignment_id=<?= (int) ($assignment['id'] ?? 0) ?>" 
                                       target="_blank"
                                       class="btn-portal-gold">
                                        <i class="fa-solid fa-print"></i> Print Report
                                    </a>
                                    <button type="submit" name="save_action" value="save_draft" class="btn-portal-outline">
                                        <i class="fa-solid fa-floppy-disk"></i> Save as Draft
                                    </button>
                                    <button type="submit" name="save_action" value="submit_scores" class="btn-portal-primary">
                                        <i class="fa-solid fa-paper-plane"></i> Submit to Advisor
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="table-empty-state">
                            <i class="fa-solid fa-user-slash"></i>
                            <p>No students are currently registered for this course.</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/lecturer.js"></script>

<script>
// Live TEST + LAB + EXAM = TOTAL calculation
(function() {
    const table = document.getElementById('studentScoresTable');
    if (!table) return;

    const hasLab = <?= $hasLab ? 'true' : 'false' ?>;

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const caInput   = row.querySelector('.ca-input');
        const labInput  = row.querySelector('.lab-input');
        const examInput = row.querySelector('.exam-input');
        const totalCell = row.querySelector('.total-cell');
        const gradeCell = row.querySelector('.grade-cell');

        if (!caInput || !examInput) return;

        function updateTotals() {
            const caVal   = caInput.value.trim();
            const labVal  = labInput ? labInput.value.trim() : '';
            const examVal = examInput.value.trim();

            const caNum   = caVal !== ''   ? parseFloat(caVal)   : null;
            const labNum  = labVal !== ''  ? parseFloat(labVal)  : null;
            const examNum = examVal !== '' ? parseFloat(examVal) : null;

            // Validation
            [caInput, labInput, examInput].forEach(input => {
                if (!input) return;
                const val = parseFloat(input.value);
                const max = parseFloat(input.dataset.max) || 0;
                if (!isNaN(val) && (val < 0 || val > max)) {
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            const canCompute = hasLab
                ? (caNum !== null && labNum !== null && examNum !== null)
                : (caNum !== null && examNum !== null);

            if (canCompute) {
                const total = (caNum + (labNum || 0) + examNum).toFixed(1);
                let grade = 'F';
                const t = parseFloat(total);
                if (t >= 70) grade = 'A';
                else if (t >= 60) grade = 'B';
                else if (t >= 50) grade = 'C';
                else if (t >= 45) grade = 'D';

                totalCell.textContent = total;
                gradeCell.innerHTML = `<span class="grade-badge grade-${grade.toLowerCase()}">${grade}</span>`;
            } else {
                totalCell.textContent = '--';
                gradeCell.innerHTML = '--';
            }
        }

        caInput.addEventListener('input', updateTotals);
        if (labInput) labInput.addEventListener('input', updateTotals);
        examInput.addEventListener('input', updateTotals);
    });

    // Split validation live
    const splitInputs = document.querySelectorAll('.split-input');
    const splitTotal  = document.getElementById('splitTotal');
    const splitError  = document.getElementById('splitError');
    
    function updateSplitTotal() {
        let total = 0;
        splitInputs.forEach(inp => {
            total += parseInt(inp.value) || 0;
        });
        if (splitTotal) splitTotal.textContent = total;
        if (splitError) {
            if (total !== 100) {
                splitError.classList.remove('d-none');
                splitTotal.style.color = '#dc3545';
            } else {
                splitError.classList.add('d-none');
                splitTotal.style.color = '#006400';
            }
        }
    }
    
    splitInputs.forEach(inp => inp.addEventListener('input', updateSplitTotal));
    updateSplitTotal();

    // Student search filter
    const searchInput = document.getElementById('studentSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }
})();
</script>
</body>
</html>