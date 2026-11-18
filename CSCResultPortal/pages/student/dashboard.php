<?php
// ============================================================
// CSCResultPortal - Student Dashboard (FIXED)
// File: pages/student/dashboard.php
// Purpose: Main landing page for students after login.
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

// ============================================================
// SECTION 2: FETCH STUDENT PROFILE (with safety fallback)
// ============================================================
$studentProfile = null;
try {
    $studentProfile = dbFetchOne($pdo,
        "SELECT sp.level, sp.cgpa, sp.total_units, sp.degree_class,
                sp.entry_year, sp.graduation_year,
                u.full_name, u.matric_number, u.email
         FROM student_profiles sp
         JOIN users u ON sp.user_id = u.id
         WHERE sp.user_id = ?",
        [$studentId]
    );
} catch (PDOException $e) {
    $studentProfile = null;
}

// Safety fallback if profile is missing
if (!is_array($studentProfile)) {
    $studentProfile = [
        'level'           => 'N/A',
        'cgpa'            => 0.00,
        'total_units'     => 0,
        'degree_class'    => 'Not Set',
        'entry_year'      => date('Y'),
        'graduation_year' => null,
        'full_name'       => $_SESSION['full_name'] ?? 'Student',
        'matric_number'   => $_SESSION['matric_number'] ?? 'N/A',
        'email'           => $_SESSION['email'] ?? '',
    ];
}

// ============================================================
// SECTION 3: FETCH CURRENT SEMESTER
// ============================================================
$currentSemester = null;
try {
    $currentSemester = dbFetchOne($pdo,
        "SELECT s.id AS semester_id, s.semester_name,
                ac.session_name, ac.id AS session_id
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE s.is_current = 1 AND ac.is_current = 1
         LIMIT 1"
    );
} catch (PDOException $e) {
    $currentSemester = null;
}

// ============================================================
// SECTION 4: FETCH RESULT STATISTICS
// ============================================================
$registeredCourses = 0;
$approvedResults   = 0;
$failedCourses     = 0;
$pendingCarryovers = 0;

try {
    if (is_array($currentSemester)) {
        $semId = (int) $currentSemester['semester_id'];

        $registeredCourses = dbCount($pdo,
            "SELECT COUNT(*) FROM student_course_registrations
             WHERE student_id = ? AND semester_id = ? AND status = 'approved'",
            [$studentId, $semId]
        );

        $approvedResults = dbCount($pdo,
            "SELECT COUNT(*) FROM results
             WHERE student_id = ? AND semester_id = ?
             AND status = 'final_approved'",
            [$studentId, $semId]
        );

        $failedCourses = dbCount($pdo,
            "SELECT COUNT(*) FROM results
             WHERE student_id = ? AND grade = 'F'
             AND status = 'final_approved'",
            [$studentId]
        );

        $pendingCarryovers = dbCount($pdo,
            "SELECT COUNT(*) FROM carryover_registrations
             WHERE student_id = ? AND status = 'pending'",
            [$studentId]
        );
    }
} catch (PDOException $e) {
    // Silent - counts stay at 0
}

// ============================================================
// SECTION 5: FETCH RECENT RESULTS
// ============================================================
$recentResults = [];
try {
    $recentResults = dbFetchAll($pdo,
        "SELECT r.total_score, r.grade, r.grade_point, r.credit_units,
                c.course_code, c.course_title,
                s.semester_name, ac.session_name
         FROM results r
         JOIN courses c ON r.course_id = c.id
         JOIN semesters s ON r.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE r.student_id = ? AND r.status = 'final_approved'
         ORDER BY r.final_approved_at DESC
         LIMIT 5",
        [$studentId]
    );
} catch (PDOException $e) {
    $recentResults = [];
}

// ============================================================
// SECTION 6: FETCH RECENT NOTIFICATIONS
// ============================================================
$recentNotifications = [];
try {
    $recentNotifications = dbFetchAll($pdo,
        "SELECT id, title, message, type, is_read, created_at
         FROM notifications
         WHERE recipient_id = ?
         ORDER BY created_at DESC
         LIMIT 5",
        [$studentId]
    );
} catch (PDOException $e) {
    $recentNotifications = [];
}

// ============================================================
// SECTION 7: FETCH GPA TREND
// ============================================================
$gpaTrend = [];
try {
    $gpaTrend = dbFetchAll($pdo,
        "SELECT sg.gpa, sg.cgpa, s.semester_name, ac.session_name
         FROM semester_gpa sg
         JOIN semesters s ON sg.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE sg.student_id = ?
         ORDER BY s.id DESC
         LIMIT 4",
        [$studentId]
    );
    $gpaTrend = array_reverse($gpaTrend);
} catch (PDOException $e) {
    $gpaTrend = [];
}

// ============================================================
// SECTION 8: DEGREE CLASS
// ============================================================
$cgpa        = (float) ($studentProfile['cgpa'] ?? 0.00);
$degreeClass = getDegreeClass($cgpa);

$pageTitle      = 'Student Dashboard';
$pageBreadcrumb = 'Student / Dashboard';
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
    <link rel="stylesheet" href="<?= APP_URL ?>/css/student.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="portal-wrapper">
    <?php require_once __DIR__ . '/../../includes/student-sidebar.php'; ?>

    <div class="portal-main">
        <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

        <main class="portal-content">

            <!-- Welcome Banner -->
            <div class="student-welcome-banner">
                <div class="welcome-banner-left">
                    <div class="welcome-avatar">
                        <?php
                        $fullNameForAvatar = $studentProfile['full_name'] ?? 'Student';
                        $nameParts = explode(' ', trim($fullNameForAvatar));
                        $initials  = '';
                        foreach ($nameParts as $p) {
                            if (!empty($p)) { $initials .= strtoupper($p[0]); }
                            if (strlen($initials) >= 2) break;
                        }
                        if (empty($initials)) { $initials = 'S'; }
                        echo htmlspecialchars($initials);
                        ?>
                    </div>
                    <div class="welcome-text">
                        <h2 class="welcome-title">
                            Welcome back, <?= htmlspecialchars(
                                explode(' ', $studentProfile['full_name'] ?? 'Student')[0]
                            ) ?>!
                        </h2>
                        <p class="welcome-subtitle">
                            <i class="fa-solid fa-id-card me-1"></i>
                            <?= htmlspecialchars($studentProfile['matric_number'] ?? 'N/A') ?>
                            &nbsp;&bull;&nbsp;
                            <i class="fa-solid fa-layer-group me-1"></i>
                            <?= htmlspecialchars($studentProfile['level'] ?? 'N/A') ?> Level
                            <?php if (is_array($currentSemester)): ?>
                                &nbsp;&bull;&nbsp;
                                <i class="fa-solid fa-calendar me-1"></i>
                                <?= htmlspecialchars($currentSemester['session_name'] ?? 'N/A') ?>
                                &mdash;
                                <?= htmlspecialchars($currentSemester['semester_name'] ?? 'N/A') ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="welcome-banner-right d-none d-md-flex">
                    <div class="welcome-cgpa-box">
                        <div class="cgpa-number">
                            <?= number_format($cgpa, 2) ?>
                        </div>
                        <div class="cgpa-label">Current CGPA</div>
                        <div class="cgpa-scale">out of 5.00</div>
                    </div>
                </div>
            </div>

            <!-- Stat Cards Row -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon green">
                            <i class="fa-solid fa-book-open"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $registeredCourses ?></div>
                            <div class="stat-card-label">Registered Courses</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon gold">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $approvedResults ?></div>
                            <div class="stat-card-label">Results Available</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon danger">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $failedCourses ?></div>
                            <div class="stat-card-label">Failed Courses</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon info">
                            <i class="fa-solid fa-rotate"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $pendingCarryovers ?></div>
                            <div class="stat-card-label">Pending Carryovers</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="row g-4">
                <div class="col-lg-8">

                    <?php if ($approvedResults === 0 && $registeredCourses > 0): ?>
                        <div class="portal-alert info mb-4">
                            <i class="fa-solid fa-circle-info"></i>
                            <div>
                                <strong>Results not yet available.</strong>
                                Your results for this semester are being processed.
                                You will be notified when they are published.
                            </div>
                        </div>
                    <?php elseif ($approvedResults > 0): ?>
                        <div class="portal-alert success mb-4">
                            <i class="fa-solid fa-circle-check"></i>
                            <div>
                                <strong><?= $approvedResults ?> result(s) available.</strong>
                                <a href="<?= APP_URL ?>/pages/student/my-results.php"
                                   class="fw-600 text-green ms-1">
                                    View Results &rarr;
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($failedCourses > 0): ?>
                        <div class="portal-alert warning mb-4">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div>
                                <strong>You have <?= $failedCourses ?> failed course(s).</strong>
                                <a href="<?= APP_URL ?>/pages/student/carryover-registration.php"
                                   class="fw-600 ms-1" style="color:#856404;">
                                    Register Carryover &rarr;
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- GPA Trend Chart -->
                    <div class="content-card mb-4">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-chart-line"></i>
                                GPA Trend
                            </div>
                            <a href="<?= APP_URL ?>/pages/student/cgpa-overview.php"
                               class="btn-portal-outline btn-portal-sm">
                                Full Report
                            </a>
                        </div>
                        <div class="content-card-body">
                            <?php if (count($gpaTrend) > 0): ?>
                                <canvas id="gpaTrendChart" height="100"></canvas>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-chart-line"></i>
                                    <p>GPA trend will appear here after your first semester results are approved.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Results Table -->
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-file-lines"></i>
                                Recent Results
                            </div>
                            <a href="<?= APP_URL ?>/pages/student/my-results.php"
                               class="btn-portal-outline btn-portal-sm">
                                View All
                            </a>
                        </div>
                        <div style="overflow-x:auto;">
                            <?php if (count($recentResults) > 0): ?>
                                <table class="portal-table">
                                    <thead>
                                        <tr>
                                            <th>Course</th>
                                            <th>Score</th>
                                            <th>Grade</th>
                                            <th>Points</th>
                                            <th>Session</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentResults as $result): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-600">
                                                        <?= htmlspecialchars($result['course_code'] ?? '') ?>
                                                    </div>
                                                    <div class="text-muted-sm">
                                                        <?= htmlspecialchars($result['course_title'] ?? '') ?>
                                                    </div>
                                                </td>
                                                <td class="fw-600">
                                                    <?= number_format((float) ($result['total_score'] ?? 0), 1) ?>
                                                </td>
                                                <td>
                                                    <span class="grade-badge grade-<?= strtolower($result['grade'] ?? 'f') ?>">
                                                        <?= htmlspecialchars($result['grade'] ?? '') ?>
                                                    </span>
                                                </td>
                                                <td><?= number_format((float) ($result['grade_point'] ?? 0), 1) ?></td>
                                                <td class="text-muted-sm">
                                                    <?= htmlspecialchars($result['session_name'] ?? '') ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-file-circle-xmark"></i>
                                    <p>No approved results available yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Degree Class Card -->
                    <div class="content-card mb-4">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-graduation-cap"></i>
                                Academic Standing
                            </div>
                        </div>
                        <div class="content-card-body">
                            <div class="cgpa-progress-wrapper">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-600" style="font-size:13px;">CGPA</span>
                                    <span class="fw-700 text-green">
                                        <?= number_format($cgpa, 2) ?> / 5.00
                                    </span>
                                </div>
                                <div class="progress" style="height:10px; border-radius:50px;">
                                    <div class="progress-bar" role="progressbar"
                                        style="width: <?= min(($cgpa / 5.0) * 100, 100) ?>%;
                                               background: linear-gradient(90deg, #006400, #FFD700);
                                               border-radius:50px;">
                                    </div>
                                </div>
                            </div>

                            <div class="degree-class-block mt-3">
                                <div class="degree-class-label">Current Classification</div>
                                <div class="degree-class-value">
                                    <span style="color:#006400; font-weight:700; font-size:14px;">
                                        <?= htmlspecialchars($degreeClass) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="academic-info-list mt-3">
                                <div class="academic-info-row">
                                    <span><i class="fa-solid fa-calendar-alt me-2 text-green"></i>Entry Year</span>
                                    <span class="fw-600">
                                        <?= htmlspecialchars((string) ($studentProfile['entry_year'] ?? 'N/A')) ?>
                                    </span>
                                </div>
                                <div class="academic-info-row">
                                    <span><i class="fa-solid fa-layer-group me-2 text-green"></i>Current Level</span>
                                    <span class="fw-600">
                                        <?= htmlspecialchars((string) ($studentProfile['level'] ?? 'N/A')) ?> Level
                                    </span>
                                </div>
                                <div class="academic-info-row">
                                    <span><i class="fa-solid fa-star me-2 text-gold"></i>Total Units</span>
                                    <span class="fw-600">
                                        <?= (int) ($studentProfile['total_units'] ?? 0) ?> units
                                    </span>
                                </div>
                            </div>
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
                                    <p>No notifications yet.</p>
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

<?php if (count($gpaTrend) > 0): ?>
<script>
(function() {
    const labels = <?= json_encode(array_map(function($row) {
        return ($row['semester_name'] ?? '') . ' ' . substr($row['session_name'] ?? '', 0, 4);
    }, $gpaTrend)) ?>;

    const gpaData  = <?= json_encode(array_column($gpaTrend, 'gpa')) ?>;
    const cgpaData = <?= json_encode(array_column($gpaTrend, 'cgpa')) ?>;

    const ctx = document.getElementById('gpaTrendChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Semester GPA',
                    data: gpaData,
                    borderColor: '#006400',
                    backgroundColor: 'rgba(0,100,0,0.08)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#006400',
                    pointRadius: 5,
                    tension: 0.35,
                    fill: true
                },
                {
                    label: 'CGPA',
                    data: cgpaData,
                    borderColor: '#FFD700',
                    backgroundColor: 'rgba(255,215,0,0.06)',
                    borderWidth: 2,
                    pointBackgroundColor: '#FFD700',
                    pointRadius: 4,
                    tension: 0.35,
                    fill: false,
                    borderDash: [5, 4]
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: true, position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                y: { min: 0, max: 5, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { ticks: { font: { size: 11 } }, grid: { display: false } }
            }
        }
    });
})();
</script>
<?php endif; ?>

<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/student.js"></script>
</body>
</html>