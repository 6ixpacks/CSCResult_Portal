<?php
// ============================================================
// CSCResultPortal - HOD Analytics
// File: pages/hod/analytics.php
// Purpose: Departmental performance analytics for the HOD.
//          Shows grade distributions, CGPA trends, pass rates,
//          level-by-level stats, and carryover analysis.
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access Control
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $_SESSION['role'] !== 'hod'
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

// ============================================================
// SECTION 1: OVERALL GRADE DISTRIBUTION
// ============================================================
$gradeDistribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
try {
    $gradeRows = dbFetchAll($pdo,
        "SELECT grade, COUNT(*) AS cnt FROM results
         WHERE status = 'final_approved' GROUP BY grade"
    );
    foreach ($gradeRows as $g) {
        $gradeDistribution[strtoupper($g['grade'])] = (int) $g['cnt'];
    }
} catch (PDOException $e) {}

$totalGrades = array_sum($gradeDistribution);
$passCount   = $gradeDistribution['A'] + $gradeDistribution['B'] + $gradeDistribution['C'] + $gradeDistribution['D'];
$failCount   = $gradeDistribution['F'];
$passRate    = $totalGrades > 0 ? round(($passCount / $totalGrades) * 100, 1) : 0;

// ============================================================
// SECTION 2: DEGREE CLASS DISTRIBUTION
// ============================================================
$degreeClassDist = [
    'First Class'       => 0,
    '2nd Upper'         => 0,
    '2nd Lower'         => 0,
    'Third Class'       => 0,
    'Pass'              => 0,
    'Fail'              => 0,
];
try {
    $cgpaRows = dbFetchAll($pdo,
        "SELECT cgpa FROM student_profiles WHERE cgpa > 0"
    );
    foreach ($cgpaRows as $cr) {
        $c = (float) $cr['cgpa'];
        if ($c >= 4.50)      $degreeClassDist['First Class']++;
        elseif ($c >= 3.50)  $degreeClassDist['2nd Upper']++;
        elseif ($c >= 2.40)  $degreeClassDist['2nd Lower']++;
        elseif ($c >= 1.50)  $degreeClassDist['Third Class']++;
        elseif ($c >= 1.00)  $degreeClassDist['Pass']++;
        else                 $degreeClassDist['Fail']++;
    }
} catch (PDOException $e) {}

// ============================================================
// SECTION 3: LEVEL-BY-LEVEL STATISTICS
// ============================================================
$levelStats = [];
try {
    $levelRows = dbFetchAll($pdo,
        "SELECT sp.level,
                COUNT(DISTINCT u.id) AS student_count,
                AVG(sp.cgpa) AS avg_cgpa,
                MAX(sp.cgpa) AS max_cgpa,
                MIN(CASE WHEN sp.cgpa > 0 THEN sp.cgpa ELSE NULL END) AS min_cgpa
         FROM student_profiles sp
         JOIN users u ON sp.user_id = u.id
         WHERE u.is_active = 1
         GROUP BY sp.level
         ORDER BY sp.level ASC"
    );
    foreach ($levelRows as $row) {
        $levelStats[$row['level']] = $row;
    }
} catch (PDOException $e) {}

// ============================================================
// SECTION 4: COURSE-BY-COURSE PASS RATE (Top 10 lowest)
// ============================================================
$coursePassRates = [];
try {
    $coursePassRates = dbFetchAll($pdo,
        "SELECT c.course_code, c.course_title, c.level,
                COUNT(*) AS total,
                SUM(CASE WHEN r.grade != 'F' THEN 1 ELSE 0 END) AS passed,
                SUM(CASE WHEN r.grade  = 'F' THEN 1 ELSE 0 END) AS failed,
                ROUND(AVG(r.total_score), 1) AS avg_score
         FROM results r
         JOIN courses c ON r.course_id = c.id
         WHERE r.status = 'final_approved'
         GROUP BY c.id, c.course_code, c.course_title, c.level
         HAVING total >= 5
         ORDER BY (passed / total) ASC
         LIMIT 10"
    );
} catch (PDOException $e) {}

// ============================================================
// SECTION 5: SEMESTER GPA TRENDS (average across all students)
// ============================================================
$semesterTrends = [];
try {
    $semesterTrends = dbFetchAll($pdo,
        "SELECT s.semester_name, ac.session_name,
                ROUND(AVG(sg.gpa),  2) AS avg_gpa,
                ROUND(AVG(sg.cgpa), 2) AS avg_cgpa,
                COUNT(DISTINCT sg.student_id) AS student_count
         FROM semester_gpa sg
         JOIN semesters s ON sg.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         GROUP BY s.id, s.semester_name, ac.session_name
         ORDER BY s.id ASC"
    );
} catch (PDOException $e) {}

// ============================================================
// SECTION 6: CARRYOVER STATS
// ============================================================
$carryoverStats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0];
try {
    $coRows = dbFetchAll($pdo,
        "SELECT status, COUNT(*) AS cnt FROM carryover_registrations GROUP BY status"
    );
    foreach ($coRows as $cr) {
        $carryoverStats[$cr['status']] = (int) $cr['cnt'];
    }
} catch (PDOException $e) {}

$pageTitle      = 'Analytics';
$pageBreadcrumb = 'HOD / Analytics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/hod.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="portal-wrapper">
    <?php require_once __DIR__ . '/../../includes/hod-sidebar.php'; ?>

    <div class="portal-main">
        <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

        <main class="portal-content">

            <div class="page-header">
                <h1 class="page-header-title">
                    <i class="fa-solid fa-chart-bar me-2 text-green"></i>
                    Departmental Analytics
                </h1>
                <p class="page-header-subtitle">
                    Full departmental performance overview: grade distributions, CGPA trends,
                    course pass rates, and carryover statistics.
                </p>
                <hr class="page-header-divider">
            </div>

            <!-- Overall Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon green">
                            <i class="fa-solid fa-file-lines"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $totalGrades ?></div>
                            <div class="stat-card-label">Total Results</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon gold">
                            <i class="fa-solid fa-percent"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $passRate ?>%</div>
                            <div class="stat-card-label">Overall Pass Rate</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon info">
                            <i class="fa-solid fa-trophy"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $gradeDistribution['A'] ?></div>
                            <div class="stat-card-label">Grade A Results</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon danger">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $failCount ?></div>
                            <div class="stat-card-label">Failed Results</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 1: Grade Distribution + Degree Class -->
            <div class="row g-4 mb-4">
                <!-- Grade Distribution Bar Chart -->
                <div class="col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-chart-bar"></i>
                                Grade Distribution
                            </div>
                        </div>
                        <div class="content-card-body">
                            <?php if ($totalGrades > 0): ?>
                                <canvas id="gradeBarChart" height="150"></canvas>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-chart-bar"></i>
                                    <p>No approved results for analysis yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Degree Class Doughnut Chart -->
                <div class="col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-graduation-cap"></i>
                                Degree Class Distribution
                            </div>
                        </div>
                        <div class="content-card-body d-flex flex-column align-items-center">
                            <?php $totalDegree = array_sum($degreeClassDist); ?>
                            <?php if ($totalDegree > 0): ?>
                                <canvas id="degreeClassChart" height="200" style="max-width:220px;"></canvas>
                                <div class="grade-dist-legend mt-3">
                                    <?php
                                    $dcColors = [
                                        'First Class' => '#006400',
                                        '2nd Upper'   => '#28a745',
                                        '2nd Lower'   => '#ffc107',
                                        'Third Class' => '#fd7e14',
                                        'Pass'        => '#17a2b8',
                                        'Fail'        => '#dc3545',
                                    ];
                                    foreach ($degreeClassDist as $cls => $cnt):
                                        if ($cnt === 0) continue;
                                    ?>
                                        <div class="grade-legend-item">
                                            <span class="grade-legend-dot"
                                                  style="background:<?= $dcColors[$cls] ?>"></span>
                                            <span><?= htmlspecialchars($cls) ?>: <strong><?= $cnt ?></strong></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                    <p>No CGPA data available yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2: GPA Trend + Carryover -->
            <div class="row g-4 mb-4">
                <!-- Departmental GPA Trend -->
                <div class="col-lg-8">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-chart-line"></i>
                                Departmental GPA Trend (Average)
                            </div>
                        </div>
                        <div class="content-card-body">
                            <?php if (count($semesterTrends) > 0): ?>
                                <canvas id="semGPATrendChart" height="120"></canvas>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-chart-line"></i>
                                    <p>No semester GPA data available yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Carryover Stats -->
                <div class="col-lg-4">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-rotate"></i>
                                Carryover Summary
                            </div>
                        </div>
                        <div class="content-card-body">
                            <?php foreach ($carryoverStats as $status => $count): ?>
                                <div class="analytics-stat-row">
                                    <span class="status-badge <?= $status ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                    <span class="fw-700 ms-auto"><?= $count ?></span>
                                    <div class="analytics-bar-bg">
                                        <?php $pct = array_sum($carryoverStats) > 0 ? ($count / array_sum($carryoverStats)) * 100 : 0; ?>
                                        <div class="analytics-bar-fill" style="width:<?= $pct ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Level Stats Table -->
            <?php if (!empty($levelStats)): ?>
                <div class="content-card mb-4">
                    <div class="content-card-header">
                        <div class="content-card-title">
                            <i class="fa-solid fa-layer-group"></i>
                            Level-by-Level Statistics
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>Level</th>
                                    <th class="text-center">Students</th>
                                    <th class="text-center">Avg. CGPA</th>
                                    <th class="text-center">Highest CGPA</th>
                                    <th class="text-center">Lowest CGPA</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($levelStats as $level => $stat): ?>
                                    <tr>
                                        <td class="fw-700">
                                            <span class="badge bg-light text-dark border">
                                                <?= htmlspecialchars($level) ?>L
                                            </span>
                                        </td>
                                        <td class="text-center fw-600"><?= (int) $stat['student_count'] ?></td>
                                        <td class="text-center fw-700 text-green">
                                            <?= number_format((float) $stat['avg_cgpa'], 2) ?>
                                        </td>
                                        <td class="text-center"><?= number_format((float) $stat['max_cgpa'], 2) ?></td>
                                        <td class="text-center"><?= number_format((float) $stat['min_cgpa'], 2) ?></td>
                                        <td>
                                            <?php $avgCGPA = (float) $stat['avg_cgpa']; ?>
                                            <div class="level-cgpa-bar">
                                                <div class="level-cgpa-fill"
                                                     style="width:<?= min(($avgCGPA / 5.0) * 100, 100) ?>%;"></div>
                                            </div>
                                            <small class="text-muted-sm">
                                                <?= htmlspecialchars(getDegreeClass($avgCGPA)) ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Course Pass Rate Table -->
            <?php if (!empty($coursePassRates)): ?>
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="content-card-title">
                            <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                            Courses with Lowest Pass Rates (Top 10)
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Course</th>
                                    <th>Level</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Passed</th>
                                    <th class="text-center">Failed</th>
                                    <th class="text-center">Avg. Score</th>
                                    <th>Pass Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coursePassRates as $idx => $course): ?>
                                    <?php
                                    $coursePassRate = $course['total'] > 0
                                        ? round(($course['passed'] / $course['total']) * 100, 1)
                                        : 0;
                                    ?>
                                    <tr>
                                        <td class="text-muted-sm"><?= $idx + 1 ?></td>
                                        <td>
                                            <div class="fw-700 text-green">
                                                <?= htmlspecialchars($course['course_code']) ?>
                                            </div>
                                            <div class="text-muted-sm">
                                                <?= htmlspecialchars($course['course_title']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?= htmlspecialchars($course['level']) ?>L
                                            </span>
                                        </td>
                                        <td class="text-center"><?= (int) $course['total'] ?></td>
                                        <td class="text-center fw-600 text-green">
                                            <?= (int) $course['passed'] ?>
                                        </td>
                                        <td class="text-center fw-600 text-danger">
                                            <?= (int) $course['failed'] ?>
                                        </td>
                                        <td class="text-center"><?= $course['avg_score'] ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="level-cgpa-bar flex-grow-1">
                                                    <div class="level-cgpa-fill"
                                                         style="width:<?= $coursePassRate ?>%;
                                                         background:<?= $coursePassRate < 50 ? '#dc3545' : ($coursePassRate < 70 ? '#ffc107' : '#006400') ?>">
                                                    </div>
                                                </div>
                                                <span class="fw-700" style="font-size:13px;">
                                                    <?= $coursePassRate ?>%
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Analytics Charts -->
<?php if ($totalGrades > 0): ?>
<script>
// Grade Distribution Bar Chart
(function(){
    const ctx = document.getElementById('gradeBarChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['A', 'B', 'C', 'D', 'F'],
            datasets: [{
                label: 'Number of Results',
                data: [
                    <?= $gradeDistribution['A'] ?>,
                    <?= $gradeDistribution['B'] ?>,
                    <?= $gradeDistribution['C'] ?>,
                    <?= $gradeDistribution['D'] ?>,
                    <?= $gradeDistribution['F'] ?>
                ],
                backgroundColor: ['#006400','#1a7abf','#d4a017','#d45d17','#dc3545'],
                borderRadius: 6,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            const total = <?= $totalGrades ?>;
                            const pct   = ((ctx.parsed.y / total) * 100).toFixed(1);
                            return ` ${ctx.parsed.y} results (${pct}%)`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: {
                    ticks: { font: { size: 13, weight: 'bold' } },
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($totalDegree > 0): ?>
<script>
// Degree Class Doughnut
(function(){
    const ctx = document.getElementById('degreeClassChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_keys($degreeClassDist)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($degreeClassDist)) ?>,
                backgroundColor: ['#006400','#28a745','#ffc107','#fd7e14','#17a2b8','#dc3545'],
                borderWidth: 2, borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ` ${ctx.label}: ${ctx.parsed} student(s)`;
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });
})();
</script>
<?php endif; ?>

<?php if (count($semesterTrends) > 0): ?>
<script>
// Departmental GPA Trend
(function(){
    const ctx = document.getElementById('semGPATrendChart');
    if (!ctx) return;
    const labels = <?= json_encode(array_map(function($s) {
        return $s['semester_name'] . ' ' . substr($s['session_name'], 0, 4);
    }, $semesterTrends)) ?>;
    const avgGPA  = <?= json_encode(array_column($semesterTrends, 'avg_gpa')) ?>;
    const avgCGPA = <?= json_encode(array_column($semesterTrends, 'avg_cgpa')) ?>;
    const counts  = <?= json_encode(array_column($semesterTrends, 'student_count')) ?>;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Avg. Semester GPA',
                    data: avgGPA,
                    borderColor: '#006400',
                    backgroundColor: 'rgba(0,100,0,0.07)',
                    borderWidth: 2.5,
                    pointRadius: 5,
                    tension: 0.35,
                    fill: true
                },
                {
                    label: 'Avg. CGPA',
                    data: avgCGPA,
                    borderColor: '#FFD700',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 4,
                    tension: 0.35,
                    borderDash: [5, 4]
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 12 } } },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                y: {
                    min: 0, max: 5,
                    ticks: { stepSize: 1, font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: {
                    ticks: { font: { size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/hod.js"></script>
</body>
</html>