<?php
// ============================================================
// CSCResultPortal - Lecturer My Courses (FIXED + PRINT)
// File: pages/lecturer/my-courses.php
// ============================================================

require_once __DIR__ . '/../../config/db.php';

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

// Fetch semesters where lecturer has courses
$availableSemesters = [];
try {
    $availableSemesters = dbFetchAll($pdo,
        "SELECT DISTINCT s.id AS semester_id, s.semester_name, s.is_current,
                ac.id AS session_id, ac.session_name
         FROM course_assignments ca
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE ca.lecturer_id = ?
         ORDER BY ac.id DESC, s.id DESC",
        [$lecturerId]
    );
} catch (PDOException $e) {
    $availableSemesters = [];
}

// Determine filter
$filterSemesterId = isset($_GET['semester_id']) ? (int) $_GET['semester_id'] : 0;
if ($filterSemesterId === 0 && !empty($availableSemesters)) {
    foreach ($availableSemesters as $sem) {
        if ((int) ($sem['is_current'] ?? 0) === 1) {
            $filterSemesterId = (int) ($sem['semester_id'] ?? 0);
            break;
        }
    }
    if ($filterSemesterId === 0) {
        $filterSemesterId = (int) ($availableSemesters[0]['semester_id'] ?? 0);
    }
}

// Fetch courses
$courses = [];
try {
    if ($filterSemesterId > 0) {
        $courses = dbFetchAll($pdo,
            "SELECT ca.id AS assignment_id, ca.ca_max, ca.exam_max, ca.scores_submitted, ca.submitted_at,
                    c.id AS course_id, c.course_code, c.course_title, c.credit_units, c.level, c.is_elective,
                    s.semester_name, ac.session_name,
                    (SELECT COUNT(*) FROM student_course_registrations scr 
                     WHERE scr.course_id = c.id AND scr.semester_id = ca.semester_id AND scr.status = 'approved') AS student_count,
                    (SELECT COUNT(*) FROM results r 
                     WHERE r.assignment_id = ca.id AND r.status = 'flagged') AS flagged_count,
                    (SELECT COUNT(*) FROM results r 
                     WHERE r.assignment_id = ca.id AND r.total_score IS NOT NULL) AS entered_count
             FROM course_assignments ca
             JOIN courses c ON ca.course_id = c.id
             JOIN semesters s ON ca.semester_id = s.id
             JOIN academic_sessions ac ON s.session_id = ac.id
             WHERE ca.lecturer_id = ? AND ca.semester_id = ?
             ORDER BY c.level ASC, c.course_code ASC",
            [$lecturerId, $filterSemesterId]
        );
    }
} catch (PDOException $e) {
    $courses = [];
}

$pageTitle      = 'My Courses';
$pageBreadcrumb = 'Lecturer / My Courses';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses &mdash; CSCResultPortal</title>
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
                    <i class="fa-solid fa-book-open me-2 text-green"></i>My Assigned Courses
                </h1>
                <p class="page-header-subtitle">
                    View and manage your assigned departmental courses. Print official grade reports for Senate submission.
                </p>
                <hr class="page-header-divider">
            </div>

            <!-- Semester Filter -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-filter"></i> Filter by Academic Session &amp; Semester
                    </div>
                </div>
                <div class="content-card-body">
                    <form method="GET" action="">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="portal-form-label">Select Semester</label>
                                <select name="semester_id" class="portal-form-control portal-select" onchange="this.form.submit()">
                                    <?php foreach ($availableSemesters as $sem): ?>
                                        <option value="<?= (int) ($sem['semester_id'] ?? 0) ?>" 
                                            <?= $filterSemesterId === (int) ($sem['semester_id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sem['session_name'] ?? '') ?> &mdash; 
                                            <?= htmlspecialchars($sem['semester_name'] ?? '') ?>
                                            <?= ((int) ($sem['is_current'] ?? 0) === 1) ? ' (Current)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <a href="my-courses.php" class="btn-portal-outline btn-portal-sm">
                                    <i class="fa-solid fa-rotate-left"></i> Reset Filter
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Course Cards -->
            <div class="row g-4">
                <?php if (count($courses) > 0): ?>
                    <?php foreach ($courses as $course): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="content-card course-card h-100 d-flex flex-column">
                                <div class="course-card-header d-flex justify-content-between align-items-center">
                                    <span class="course-code-badge">
                                        <?= htmlspecialchars($course['course_code'] ?? '') ?>
                                    </span>
                                    <span class="badge bg-light text-dark border">
                                        <?= htmlspecialchars($course['level'] ?? '') ?>L
                                    </span>
                                </div>
                                <div class="content-card-body flex-grow-1">
                                    <h5 class="course-title-text mb-2">
                                        <?= htmlspecialchars($course['course_title'] ?? '') ?>
                                    </h5>
                                    <div class="course-meta-info mb-3">
                                        <span><i class="fa-solid fa-star text-gold me-1"></i><?= (int) ($course['credit_units'] ?? 0) ?> Units</span>
                                        <span class="ms-3"><i class="fa-solid fa-tag text-muted me-1"></i><?= ((int) ($course['is_elective'] ?? 0) === 1) ? 'Elective' : 'Compulsory' ?></span>
                                    </div>
                                    <hr class="my-2">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted-sm">Registered Students:</span>
                                        <span class="fw-700"><?= (int) ($course['student_count'] ?? 0) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted-sm">Scores Entered:</span>
                                        <span class="fw-700"><?= (int) ($course['entered_count'] ?? 0) ?> / <?= (int) ($course['student_count'] ?? 0) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted-sm">CA / Exam Split:</span>
                                        <span class="fw-600 text-green"><?= (int) ($course['ca_max'] ?? 0) ?>% / <?= (int) ($course['exam_max'] ?? 0) ?>%</span>
                                    </div>
                                </div>
                                <div class="content-card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <?php if ((int) ($course['flagged_count'] ?? 0) > 0): ?>
                                            <span class="status-badge flagged">
                                                <i class="fa-solid fa-flag fa-xs me-1"></i>Flagged (<?= (int) $course['flagged_count'] ?>)
                                            </span>
                                        <?php elseif ((int) ($course['scores_submitted'] ?? 0) === 1): ?>
                                            <span class="status-badge submitted">
                                                <i class="fa-solid fa-check fa-xs me-1"></i>Submitted
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge draft">
                                                <i class="fa-solid fa-pen fa-xs me-1"></i>Draft
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <a href="<?= APP_URL ?>/pages/shared/grade-report.php?assignment_id=<?= (int) ($course['assignment_id'] ?? 0) ?>" 
                                           target="_blank" 
                                           class="btn-portal-outline btn-portal-sm"
                                           title="Print Grade Report for Senate">
                                            <i class="fa-solid fa-print"></i>
                                        </a>
                                        <a href="<?= APP_URL ?>/pages/lecturer/enter-scores.php?assignment_id=<?= (int) ($course['assignment_id'] ?? 0) ?>" 
                                           class="btn-portal-primary btn-portal-sm">
                                            <i class="fa-solid fa-pen-to-square"></i> Enter Scores
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="content-card">
                            <div class="table-empty-state">
                                <i class="fa-solid fa-book-open"></i>
                                <p>No courses found for the selected academic semester.</p>
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
<script src="<?= APP_URL ?>/js/lecturer.js"></script>
</body>
</html>