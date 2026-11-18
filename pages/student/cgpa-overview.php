<?php
// ============================================================
// CSCResultPortal - Student CGPA Overview (FIXED)
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

// Fetch profile with fallback
$studentProfile = null;
try {
    $studentProfile = dbFetchOne($pdo,
        "SELECT sp.*, u.full_name, u.matric_number
         FROM student_profiles sp
         JOIN users u ON sp.user_id = u.id
         WHERE sp.user_id = ?",
        [$studentId]
    );
} catch (PDOException $e) {}
if (!is_array($studentProfile)) {
    $studentProfile = [
        'cgpa'         => 0.00,
        'total_units'  => 0,
        'degree_class' => 'Not Set',
        'level'        => 'N/A',
        'full_name'    => $_SESSION['full_name'] ?? 'Student',
        'matric_number'=> $_SESSION['matric_number'] ?? 'N/A',
    ];
}

// Fetch semester GPAs
$semesterGPAs = [];
try {
    $semesterGPAs = dbFetchAll($pdo,
        "SELECT sg.gpa, sg.cgpa, sg.total_units, sg.total_quality_points,
                s.id AS semester_id, s.semester_name,
                ac.session_name
         FROM semester_gpa sg
         JOIN semesters s ON sg.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE sg.student_id = ?
         ORDER BY s.id ASC",
        [$studentId]
    );
} catch (PDOException $e) {
    $semesterGPAs = [];
}

// Fetch all approved results
$resultsBySemester = [];
try {
    $allResults = dbFetchAll($pdo,
        "SELECT r.total_score, r.grade, r.grade_point,
                r.credit_units, r.quality_point,
                c.course_code, c.course_title,
                s.id AS semester_id, s.semester_name,
                ac.session_name
         FROM results r
         JOIN courses c ON r.course_id = c.id
         JOIN semesters s ON r.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE r.student_id = ? AND r.status = 'final_approved'
         ORDER BY s.id ASC, c.course_code ASC",
        [$studentId]
    );

    foreach ($allResults as $row) {
        $sid = $row['semester_id'] ?? 0;
        if (!isset($resultsBySemester[$sid])) {
            $resultsBySemester[$sid] = [
                'semester_name' => $row['semester_name'] ?? '',
                'session_name'  => $row['session_name'] ?? '',
                'courses'       => []
            ];
        }
        $resultsBySemester[$sid]['courses'][] = $row;
    }
} catch (PDOException $e) {
    $resultsBySemester = [];
}

// Build chart data
$chartLabels = [];
$chartGPA    = [];
$chartCGPA   = [];
foreach ($semesterGPAs as $sg) {
    $chartLabels[] = ($sg['semester_name'] ?? '') . "\n" . ($sg['session_name'] ?? '');
    $chartGPA[]    = round((float) ($sg['gpa'] ?? 0),  2);
    $chartCGPA[]   = round((float) ($sg['cgpa'] ?? 0), 2);
}

$currentCGPA = (float) ($studentProfile['cgpa'] ?? 0.00);
$degreeClass = getDegreeClass($currentCGPA);

// Grade distribution
$gradeDistribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
foreach ($resultsBySemester as $semData) {
    foreach ($semData['courses'] as $course) {
        $g = strtoupper($course['grade'] ?? '');
        if (isset($gradeDistribution[$g])) {
            $gradeDistribution[$g]++;
        }
    }
}

$pageTitle      = 'CGPA Overview';
$pageBreadcrumb = 'Student / CGPA Overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CGPA Overview &mdash; CSCResultPortal</title>
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

            <div class="page-header">
                <h1 class="page-header-title">
                    <i class="fa-solid fa-chart-line me-2 text-green"></i>CGPA Overview
                </h1>
                <p class="page-header-subtitle">
                    Full academic performance history, GPA trend, and degree class tracking.
                </p>
                <hr class="page-header-divider">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon green">
                            <i class="fa-solid fa-star"></i>
                        </div>
                        <div>
                            <div class="stat-card-value text-green"><?= number_format($currentCGPA, 2) ?></div>
                            <div class="stat-card-label">Current CGPA</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon gold">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= (int) ($studentProfile['total_units'] ?? 0) ?></div>
                            <div class="stat-card-label">Total Credit Units</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon info">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= count($semesterGPAs) ?></div>
                            <div class="stat-card-label">Semesters Completed</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon warning">
                            <i class="fa-solid fa-book"></i>
                        </div>
                        <div>
                            <div class="stat-card-value">
                                <?= array_sum(array_map('count', array_column($resultsBySemester, 'courses'))) ?>
                            </div>
                            <div class="stat-card-label">Total Courses</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="degree-class-banner mb-4">
                <div class="degree-class-banner-left">
                    <div class="degree-icon">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <div class="degree-class-heading">Current Degree Classification</div>
                        <div class="degree-class-text"><?= htmlspecialchars($degreeClass) ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-chart-line"></i>
                                GPA Trend by Semester
                            </div>
                        </div>
                        <div class="content-card-body">
                            <?php if (count($chartLabels) > 0): ?>
                                <canvas id="fullGPAChart" height="120"></canvas>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-chart-line"></i>
                                    <p>GPA trend will appear here after your first semester results are approved.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-chart-pie"></i>
                                Grade Distribution
                            </div>
                        </div>
                        <div class="content-card-body d-flex flex-column align-items-center">
                            <?php $totalGrades = array_sum($gradeDistribution); ?>
                            <?php if ($totalGrades > 0): ?>
                                <canvas id="gradeDistChart" height="200" style="max-width:200px;"></canvas>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-chart-pie"></i>
                                    <p>No grade data available yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Semester Breakdown -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-table-list"></i>
                        Semester-by-Semester Breakdown
                    </div>
                </div>
                <div class="content-card-body">

                    <?php if (count($resultsBySemester) === 0): ?>
                        <div class="table-empty-state">
                            <i class="fa-solid fa-file-circle-xmark"></i>
                            <p>No approved results found. Check back after your results are published.</p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($resultsBySemester as $semId => $semData): ?>
                        <?php
                        $semGPARecord = null;
                        foreach ($semesterGPAs as $sg) {
                            if ((int) ($sg['semester_id'] ?? 0) === (int) $semId) {
                                $semGPARecord = $sg;
                                break;
                            }
                        }
                        $semTotalUnits = 0;
                        $semTotalQP    = 0.0;
                        foreach ($semData['courses'] as $course) {
                            $semTotalUnits += (int) ($course['credit_units'] ?? 0);
                            $semTotalQP    += (float) ($course['quality_point'] ?? 0);
                        }
                        $semGPAVal = $semTotalUnits > 0 ? round($semTotalQP / $semTotalUnits, 2) : 0.00;
                        if (is_array($semGPARecord)) {
                            $semGPAVal = (float) ($semGPARecord['gpa'] ?? 0);
                        }
                        ?>

                        <div class="semester-block mb-4">
                            <div class="semester-block-header">
                                <div class="semester-block-title">
                                    <i class="fa-solid fa-calendar-alt me-2"></i>
                                    <?= htmlspecialchars($semData['session_name'] ?? '') ?>
                                    &mdash;
                                    <?= htmlspecialchars($semData['semester_name'] ?? '') ?>
                                </div>
                                <div class="semester-block-gpa">
                                    GPA: <strong class="text-green"><?= number_format($semGPAVal, 2) ?></strong>
                                    <?php if (is_array($semGPARecord)): ?>
                                        &nbsp;|&nbsp; CGPA: <strong class="text-green"><?= number_format((float) ($semGPARecord['cgpa'] ?? 0), 2) ?></strong>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="overflow-x:auto;">
                                <table class="portal-table">
                                    <thead>
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Title</th>
                                            <th>Units</th>
                                            <th>Score</th>
                                            <th>Grade</th>
                                            <th>Grade Pt</th>
                                            <th>Quality Pt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($semData['courses'] as $course): ?>
                                            <tr>
                                                <td class="fw-600"><?= htmlspecialchars($course['course_code'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($course['course_title'] ?? '') ?></td>
                                                <td class="text-center"><?= (int) ($course['credit_units'] ?? 0) ?></td>
                                                <td class="text-center fw-600">
                                                    <?= number_format((float) ($course['total_score'] ?? 0), 1) ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="grade-badge grade-<?= strtolower($course['grade'] ?? 'f') ?>">
                                                        <?= htmlspecialchars($course['grade'] ?? '') ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?= number_format((float) ($course['grade_point'] ?? 0), 1) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?= number_format((float) ($course['quality_point'] ?? 0), 2) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if (count($chartLabels) > 0): ?>
<script>
(function(){
    const labels   = <?= json_encode($chartLabels) ?>;
    const gpaData  = <?= json_encode($chartGPA) ?>;
    const cgpaData = <?= json_encode($chartCGPA) ?>;
    const ctx = document.getElementById('fullGPAChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Semester GPA', data: gpaData, borderColor: '#006400', backgroundColor: 'rgba(0,100,0,0.07)', borderWidth: 3, pointRadius: 6, tension: 0.35, fill: true },
                { label: 'CGPA', data: cgpaData, borderColor: '#FFD700', backgroundColor: 'transparent', borderWidth: 2.5, pointRadius: 5, tension: 0.35, borderDash: [6, 4] }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' }, tooltip: { mode: 'index', intersect: false } },
            scales: {
                y: { min: 0, max: 5, ticks: { stepSize: 1 }, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php if (array_sum($gradeDistribution) > 0): ?>
<script>
(function(){
    const ctx = document.getElementById('gradeDistChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['A', 'B', 'C', 'D', 'F'],
            datasets: [{
                data: [<?= $gradeDistribution['A'] ?>, <?= $gradeDistribution['B'] ?>, <?= $gradeDistribution['C'] ?>, <?= $gradeDistribution['D'] ?>, <?= $gradeDistribution['F'] ?>],
                backgroundColor: ['#006400', '#1a7abf', '#d4a017', '#d45d17', '#dc3545'],
                borderWidth: 2, borderColor: '#fff'
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, cutout: '65%' }
    });
})();
</script>
<?php endif; ?>

<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/student.js"></script>
</body>
</html>