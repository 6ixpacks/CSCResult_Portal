<?php
// ============================================================
// CSCResultPortal - Lecturer Dashboard (FIXED)
// File: pages/lecturer/dashboard.php
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// SECTION 1: ACCESS CONTROL
// ============================================================
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

// ============================================================
// SECTION 2: FETCH LECTURER DETAILS & CURRENT SEMESTER
// ============================================================
$lecturerProfile = null;
try {
    $lecturerProfile = dbFetchOne($pdo,
        "SELECT id, full_name, email, staff_id, phone 
         FROM users 
         WHERE id = ? AND role = 'lecturer'",
        [$lecturerId]
    );
} catch (PDOException $e) {
    $lecturerProfile = null;
}

// Fallback if lecturer profile missing
if (!is_array($lecturerProfile)) {
    $lecturerProfile = [
        'id'        => $lecturerId,
        'full_name' => $_SESSION['full_name'] ?? 'Lecturer',
        'email'     => $_SESSION['email'] ?? '',
        'staff_id'  => $_SESSION['staff_id'] ?? 'N/A',
        'phone'     => '',
    ];
}

$currentSemester = null;
try {
    $currentSemester = dbFetchOne($pdo,
        "SELECT s.id AS semester_id, s.semester_name, 
                ac.id AS session_id, ac.session_name
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE s.is_current = 1 AND ac.is_current = 1
         LIMIT 1"
    );
} catch (PDOException $e) {
    $currentSemester = null;
}

// ============================================================
// SECTION 3: FETCH ASSIGNED COURSES & STUDENT COUNTS
// ============================================================
$assignedCourses = [];
$totalCourses    = 0;
$totalStudents   = 0;
$submittedCount  = 0;
$flaggedCount    = 0;

try {
    if (is_array($currentSemester)) {
        $semId = (int) ($currentSemester['semester_id'] ?? 0);

        $assignedCourses = dbFetchAll($pdo,
            "SELECT ca.id AS assignment_id, ca.ca_max, ca.exam_max, ca.scores_submitted, ca.submitted_at,
                    c.id AS course_id, c.course_code, c.course_title, c.credit_units, c.level,
                    (SELECT COUNT(*) FROM student_course_registrations scr 
                     WHERE scr.course_id = c.id AND scr.semester_id = ? AND scr.status = 'approved') AS student_count,
                    (SELECT COUNT(*) FROM results r 
                     WHERE r.assignment_id = ca.id AND r.status = 'flagged') AS flagged_count,
                    (SELECT COUNT(*) FROM results r 
                     WHERE r.assignment_id = ca.id AND r.status != 'draft') AS processed_count
             FROM course_assignments ca
             JOIN courses c ON ca.course_id = c.id
             WHERE ca.lecturer_id = ? AND ca.semester_id = ?
             ORDER BY c.level ASC, c.course_code ASC",
            [$semId, $lecturerId, $semId]
        );

        $totalCourses = count($assignedCourses);

        foreach ($assignedCourses as $ac) {
            $totalStudents += (int) ($ac['student_count'] ?? 0);
            if ((int) ($ac['scores_submitted'] ?? 0) === 1) {
                $submittedCount++;
            }
            if ((int) ($ac['flagged_count'] ?? 0) > 0) {
                $flaggedCount++;
            }
        }
    }
} catch (PDOException $e) {
    $assignedCourses = [];
}

// ============================================================
// SECTION 4: FETCH RECENT NOTIFICATIONS
// ============================================================
$recentNotifications = [];
try {
    $recentNotifications = dbFetchAll($pdo,
        "SELECT id, title, message, type, is_read, created_at
         FROM notifications
         WHERE recipient_id = ?
         ORDER BY created_at DESC
         LIMIT 5",
        [$lecturerId]
    );
} catch (PDOException $e) {
    $recentNotifications = [];
}

$pageTitle      = 'Lecturer Dashboard';
$pageBreadcrumb = 'Lecturer / Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard &mdash; CSCResultPortal</title>
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

            <!-- Welcome Banner -->
            <div class="lecturer-welcome-banner">
                <div class="welcome-banner-left">
                    <div class="welcome-avatar">
                        <?php
                        $fullNameForAvatar = $lecturerProfile['full_name'] ?? 'Lecturer';
                        $nameParts = explode(' ', trim($fullNameForAvatar));
                        $initials  = '';
                        foreach ($nameParts as $p) {
                            if (!empty($p)) { $initials .= strtoupper($p[0]); }
                            if (strlen($initials) >= 2) break;
                        }
                        if (empty($initials)) { $initials = 'L'; }
                        echo htmlspecialchars($initials);
                        ?>
                    </div>
                    <div class="welcome-text">
                        <h2 class="welcome-title">
                            Welcome, <?= htmlspecialchars($lecturerProfile['full_name'] ?? 'Lecturer') ?>!
                        </h2>
                        <p class="welcome-subtitle">
                            <i class="fa-solid fa-id-badge me-1"></i>
                            Staff ID: <?= htmlspecialchars($lecturerProfile['staff_id'] ?? 'N/A') ?>
                            <?php if (is_array($currentSemester)): ?>
                                &nbsp;&bull;&nbsp;
                                <i class="fa-solid fa-calendar me-1"></i>
                                <?= htmlspecialchars($currentSemester['session_name'] ?? 'N/A') ?> &mdash;
                                <?= htmlspecialchars($currentSemester['semester_name'] ?? 'N/A') ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="welcome-banner-right d-none d-md-flex">
                    <div class="welcome-stat-chip">
                        <div class="stat-number"><?= $totalCourses ?></div>
                        <div class="stat-label">Assigned Courses</div>
                    </div>
                </div>
            </div>

            <!-- Flagged Results Alert -->
            <?php if ($flaggedCount > 0): ?>
                <div class="portal-alert danger mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong>Action Required:</strong>
                        You have <strong><?= $flaggedCount ?> course(s)</strong> with results flagged back by the Course Advisor.
                        <a href="<?= APP_URL ?>/pages/lecturer/returned-results.php" class="fw-600 text-danger ms-2 text-decoration-underline">
                            View Returned Results &rarr;
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stat Cards Row -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon green">
                            <i class="fa-solid fa-book"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $totalCourses ?></div>
                            <div class="stat-card-label">My Courses</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon gold">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $totalStudents ?></div>
                            <div class="stat-card-label">Total Students</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon info">
                            <i class="fa-solid fa-check-double"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $submittedCount ?></div>
                            <div class="stat-card-label">Submitted</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon danger">
                            <i class="fa-solid fa-flag"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $flaggedCount ?></div>
                            <div class="stat-card-label">Flagged Results</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Grid -->
            <div class="row g-4">

                <!-- Left: Assigned Courses -->
                <div class="col-lg-8">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-chalkboard-user"></i>
                                Assigned Courses &mdash; Current Semester
                            </div>
                            <a href="<?= APP_URL ?>/pages/lecturer/my-courses.php" class="btn-portal-outline btn-portal-sm">
                                View All
                            </a>
                        </div>
                        <div style="overflow-x:auto;">
                            <?php if (count($assignedCourses) > 0): ?>
                                <table class="portal-table">
                                    <thead>
                                        <tr>
                                            <th>Course</th>
                                            <th>Level</th>
                                            <th>Units</th>
                                            <th>Students</th>
                                            <th>CA / Exam</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assignedCourses as $course): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-700 text-green">
                                                        <?= htmlspecialchars($course['course_code'] ?? '') ?>
                                                    </div>
                                                    <div class="text-muted-sm">
                                                        <?= htmlspecialchars($course['course_title'] ?? '') ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <?= htmlspecialchars($course['level'] ?? '') ?>L
                                                    </span>
                                                </td>
                                                <td><?= (int) ($course['credit_units'] ?? 0) ?></td>
                                                <td>
                                                    <span class="fw-600"><?= (int) ($course['student_count'] ?? 0) ?></span>
                                                </td>
                                                <td class="text-muted-sm">
                                                    <?= (int) ($course['ca_max'] ?? 0) ?>% / <?= (int) ($course['exam_max'] ?? 0) ?>%
                                                </td>
                                                <td>
                                                    <?php if ((int) ($course['flagged_count'] ?? 0) > 0): ?>
                                                        <span class="status-badge flagged">
                                                            <i class="fa-solid fa-flag fa-xs me-1"></i>Flagged
                                                        </span>
                                                    <?php elseif ((int) ($course['scores_submitted'] ?? 0) === 1): ?>
                                                        <span class="status-badge submitted">
                                                            <i class="fa-solid fa-check fa-xs me-1"></i>Submitted
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="status-badge draft">
                                                            <i class="fa-solid fa-pen fa-xs me-1"></i>Draft / Pending
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= APP_URL ?>/pages/lecturer/enter-scores.php?assignment_id=<?= (int) ($course['assignment_id'] ?? 0) ?>" 
                                                       class="btn-portal-primary btn-portal-sm">
                                                       <i class="fa-solid fa-pen-to-square"></i> Scores
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-book-open"></i>
                                    <p>No courses have been assigned to you for the current semester.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Notifications & Guidelines -->
                <div class="col-lg-4">

                    <!-- Grading Guidelines -->
                    <div class="content-card mb-4">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-circle-info"></i>
                                Grading Guidelines
                            </div>
                        </div>
                        <div class="content-card-body">
                            <ul class="guideline-list">
                                <li>
                                    <i class="fa-solid fa-check text-green me-2"></i>
                                    <strong>CA + Exam = 100%:</strong> You can customize your CA/Exam split per course.
                                </li>
                                <li>
                                    <i class="fa-solid fa-check text-green me-2"></i>
                                    <strong>Nigerian 5.0 Scale:</strong> A=70+, B=60-69, C=50-59, D=45-49, F=0-44.
                                </li>
                                <li>
                                    <i class="fa-solid fa-check text-green me-2"></i>
                                    <strong>Save as Draft:</strong> You can save incrementally before final submission.
                                </li>
                                <li>
                                    <i class="fa-solid fa-check text-green me-2"></i>
                                    <strong>Review Workflow:</strong> Scores go through Advisor before reaching HOD.
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Notifications -->
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-bell"></i>
                                Notifications
                            </div>
                        </div>
                        <div class="content-card-body" style="padding:0;">
                            <?php if (count($recentNotifications) > 0): ?>
                                <div class="dashboard-notif-list">
                                    <?php foreach ($recentNotifications as $notif): ?>
                                        <div class="dashboard-notif-item <?= !empty($notif['is_read']) ? '' : 'unread' ?>">
                                            <div class="dashboard-notif-dot <?= !empty($notif['is_read']) ? 'read' : 'unread' ?>"></div>
                                            <div class="dashboard-notif-content">
                                                <div class="dashboard-notif-title">
                                                    <?= htmlspecialchars($notif['title'] ?? '') ?>
                                                </div>
                                                <div class="dashboard-notif-message">
                                                    <?php
                                                    $msg = $notif['message'] ?? '';
                                                    echo htmlspecialchars(strlen($msg) > 80 ? substr($msg, 0, 80) . '...' : $msg);
                                                    ?>
                                                </div>
                                                <div class="dashboard-notif-time">
                                                    <i class="fa-regular fa-clock fa-xs me-1"></i>
                                                    <?= date('d M Y, g:ia', strtotime($notif['created_at'] ?? 'now')) ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-empty-state" style="padding:30px 16px;">
                                    <i class="fa-solid fa-bell-slash"></i>
                                    <p>No recent notifications.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/lecturer.js"></script>
</body>
</html>