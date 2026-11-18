<?php
// ============================================================
// CSCResultPortal - Student My Results (FIXED)
// File: pages/student/my-results.php
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $_SESSION['role'] !== 'student'
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

$studentId = (int) $_SESSION['user_id'];

// Fetch student profile with fallback
$studentProfile = null;
try {
    $studentProfile = dbFetchOne($pdo,
        "SELECT sp.cgpa FROM student_profiles sp WHERE sp.user_id = ?",
        [$studentId]
    );
} catch (PDOException $e) {}
if (!is_array($studentProfile)) {
    $studentProfile = ['cgpa' => 0.00];
}

// Fetch all sessions where student has approved results
$allSessions = [];
try {
    $allSessions = dbFetchAll($pdo,
        "SELECT DISTINCT ac.id AS session_id, ac.session_name,
                s.id AS semester_id, s.semester_name
         FROM student_course_registrations scr
         JOIN semesters s ON scr.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE scr.student_id = ?
         ORDER BY ac.id DESC, s.id ASC",
        [$studentId]
    );
} catch (PDOException $e) {
    $allSessions = [];
}

// Determine filter semester
$filterSemesterId = isset($_GET['semester_id']) ? (int) $_GET['semester_id'] : 0;

if ($filterSemesterId === 0) {
    try {
        $currentSem = dbFetchOne($pdo,
            "SELECT s.id FROM semesters s
             JOIN academic_sessions ac ON s.session_id = ac.id
             WHERE s.is_current = 1 AND ac.is_current = 1
             LIMIT 1"
        );
        if (is_array($currentSem)) {
            $filterSemesterId = (int) $currentSem['id'];
        }
    } catch (PDOException $e) {}
}

// Fetch results
$results      = [];
$semesterInfo = null;
$semesterGPA  = null;

if ($filterSemesterId > 0) {
    try {
        $semesterInfo = dbFetchOne($pdo,
            "SELECT s.semester_name, ac.session_name
             FROM semesters s
             JOIN academic_sessions ac ON s.session_id = ac.id
             WHERE s.id = ?",
            [$filterSemesterId]
        );

        $results = dbFetchAll($pdo,
            "SELECT r.id, r.ca_score, r.exam_score, r.total_score,
                    r.grade, r.grade_point, r.credit_units, r.quality_point,
                    r.is_carryover, r.attempt_number,
                    c.course_code, c.course_title,
                    ca.ca_max, ca.exam_max,
                    lu.full_name AS lecturer_name
             FROM results r
             JOIN courses c ON r.course_id = c.id
             JOIN course_assignments ca ON r.assignment_id = ca.id
             JOIN users lu ON ca.lecturer_id = lu.id
             WHERE r.student_id = ?
             AND r.semester_id = ?
             AND r.status = 'final_approved'
             ORDER BY c.course_code ASC",
            [$studentId, $filterSemesterId]
        );

        $semesterGPA = dbFetchOne($pdo,
            "SELECT gpa, cgpa, total_units, total_quality_points
             FROM semester_gpa
             WHERE student_id = ? AND semester_id = ?",
            [$studentId, $filterSemesterId]
        );
    } catch (PDOException $e) {
        $results = [];
    }
}

// Compute totals
$totalUnits         = 0;
$totalQualityPoints = 0.0;
$semGPA             = 0.00;

if (count($results) > 0) {
    foreach ($results as $r) {
        $totalUnits         += (int) ($r['credit_units'] ?? 0);
        $totalQualityPoints += (float) ($r['quality_point'] ?? 0);
    }
    $semGPA = $totalUnits > 0 ? round($totalQualityPoints / $totalUnits, 2) : 0.00;
}

if (is_array($semesterGPA)) {
    $semGPA = (float) ($semesterGPA['gpa'] ?? 0);
}

$pageTitle      = 'My Results';
$pageBreadcrumb = 'Student / My Results';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Results &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/student.css">
</head>
<body>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="portal-wrapper">
    <?php require_once __DIR__ . '/../../includes/student-sidebar.php'; ?>
    <div class="portal-main">
        <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

        <main class="portal-content">
            <div class="page-header">
                <h1 class="page-header-title">
                    <i class="fa-solid fa-file-lines me-2 text-green"></i>My Results
                </h1>
                <p class="page-header-subtitle">
                    View your approved academic results. Results are read-only.
                </p>
                <hr class="page-header-divider">
            </div>

            <div class="portal-alert info mb-4">
                <i class="fa-solid fa-lock"></i>
                <div>
                    <strong>Read-only view.</strong>
                    If you notice a discrepancy, contact the Course Advisor or HOD office.
                </div>
            </div>

            <!-- Filter -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-filter"></i>
                        Filter by Semester
                    </div>
                </div>
                <div class="content-card-body">
                    <form method="GET" action="" id="semesterFilterForm">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="portal-form-label">Select Semester</label>
                                <select name="semester_id" class="portal-form-control portal-select" onchange="this.form.submit()">
                                    <option value="">-- All Semesters --</option>
                                    <?php foreach ($allSessions as $sem): ?>
                                        <option value="<?= (int) ($sem['semester_id'] ?? 0) ?>"
                                            <?= $filterSemesterId === (int) ($sem['semester_id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sem['session_name'] ?? '') ?>
                                            &mdash;
                                            <?= htmlspecialchars($sem['semester_name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <a href="my-results.php" class="btn-portal-outline btn-portal-sm">
                                    <i class="fa-solid fa-rotate-left"></i>
                                    Reset Filter
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Results Table -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-table"></i>
                        <?php if (is_array($semesterInfo)): ?>
                            Results &mdash;
                            <?= htmlspecialchars($semesterInfo['session_name'] ?? '') ?>
                            &mdash;
                            <?= htmlspecialchars($semesterInfo['semester_name'] ?? '') ?>
                        <?php else: ?>
                            Results
                        <?php endif; ?>
                    </div>
                    <?php if (count($results) > 0): ?>
                        <span class="status-badge final_approved">
                            <?= count($results) ?> course(s)
                        </span>
                    <?php endif; ?>
                </div>

                <div style="overflow-x:auto;">
                    <?php if (count($results) > 0): ?>
                        <table class="portal-table" id="resultsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Course Code</th>
                                    <th>Course Title</th>
                                    <th>Units</th>
                                    <th>CA (<?= htmlspecialchars((string) ($results[0]['ca_max'] ?? '--')) ?>)</th>
                                    <th>Exam (<?= htmlspecialchars((string) ($results[0]['exam_max'] ?? '--')) ?>)</th>
                                    <th>Total</th>
                                    <th>Grade</th>
                                    <th>Grade Point</th>
                                    <th>Quality Point</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $index => $result): ?>
                                    <tr>
                                        <td class="text-muted-sm"><?= $index + 1 ?></td>
                                        <td>
                                            <span class="fw-600">
                                                <?= htmlspecialchars($result['course_code'] ?? '') ?>
                                            </span>
                                            <?php if (!empty($result['is_carryover'])): ?>
                                                <span class="status-badge flagged ms-1" style="font-size:9px; padding:2px 6px;">
                                                    CO
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($result['course_title'] ?? '') ?></div>
                                            <div class="text-muted-sm">
                                                <?= htmlspecialchars($result['lecturer_name'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td class="text-center"><?= (int) ($result['credit_units'] ?? 0) ?></td>
                                        <td class="text-center">
                                            <?= isset($result['ca_score']) && $result['ca_score'] !== null
                                                ? number_format((float) $result['ca_score'], 1)
                                                : '--' ?>
                                        </td>
                                        <td class="text-center">
                                            <?= isset($result['exam_score']) && $result['exam_score'] !== null
                                                ? number_format((float) $result['exam_score'], 1)
                                                : '--' ?>
                                        </td>
                                        <td class="text-center fw-700">
                                            <?= isset($result['total_score']) && $result['total_score'] !== null
                                                ? number_format((float) $result['total_score'], 1)
                                                : '--' ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="grade-badge grade-<?= strtolower($result['grade'] ?? 'f') ?>">
                                                <?= htmlspecialchars($result['grade'] ?? '') ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?= number_format((float) ($result['grade_point'] ?? 0), 1) ?>
                                        </td>
                                        <td class="text-center">
                                            <?= number_format((float) ($result['quality_point'] ?? 0), 2) ?>
                                        </td>
                                        <td>
                                            <span class="status-badge final_approved">
                                                <i class="fa-solid fa-check fa-xs"></i>
                                                Approved
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="table-empty-state">
                            <i class="fa-solid fa-file-circle-xmark"></i>
                            <p>No approved results found for the selected semester.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($results) > 0): ?>
                    <div class="content-card-footer">
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <div class="result-summary-item">
                                    <div class="result-summary-label">Total Units</div>
                                    <div class="result-summary-value"><?= $totalUnits ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="result-summary-item">
                                    <div class="result-summary-label">Total Quality Points</div>
                                    <div class="result-summary-value">
                                        <?= number_format($totalQualityPoints, 2) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="result-summary-item">
                                    <div class="result-summary-label">Semester GPA</div>
                                    <div class="result-summary-value text-green">
                                        <?= number_format($semGPA, 2) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="result-summary-item">
                                    <div class="result-summary-label">CGPA (Cumulative)</div>
                                    <div class="result-summary-value text-green">
                                        <?= number_format(
                                            is_array($semesterGPA)
                                                ? (float) ($semesterGPA['cgpa'] ?? 0)
                                                : (float) ($studentProfile['cgpa'] ?? 0),
                                            2
                                        ) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/student.js"></script>
</body>
</html>