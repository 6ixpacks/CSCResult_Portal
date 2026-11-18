<?php
// ============================================================
// CSCResultPortal - HOD Dashboard (FIXED)
// File: pages/hod/dashboard.php
// Purpose: Main overview for the Head of Department.
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// SECTION 1: ACCESS CONTROL & SESSION TIMEOUT
// ============================================================
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

$hodId = (int) $_SESSION['user_id'];

// ============================================================
// SECTION 2: FETCH CURRENT SEMESTER & SESSION
// ============================================================
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
// SECTION 3: FETCH PIPELINE STATISTICS
// ============================================================
$pipelineStats = [
    'draft'          => 0,
    'submitted'      => 0,
    'flagged'        => 0,
    'forwarded'      => 0,
    'hod_approved'   => 0,
    'final_approved' => 0,
];

try {
    foreach (array_keys($pipelineStats) as $status) {
        $pipelineStats[$status] = dbCount($pdo,
            "SELECT COUNT(DISTINCT assignment_id) FROM results WHERE status = ?",
            [$status]
        );
    }
} catch (PDOException $e) {}

// ============================================================
// SECTION 4: FETCH DEPARTMENTAL COUNTS
// ============================================================
$totalStudents  = 0;
$totalLecturers = 0;
$totalAdvisors  = 0;
$totalCourses   = 0;

try {
    $totalStudents  = dbCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student'  AND is_active = 1");
    $totalLecturers = dbCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'lecturer' AND is_active = 1");
    $totalAdvisors  = dbCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'advisor'  AND is_active = 1");
    $totalCourses   = dbCount($pdo, "SELECT COUNT(*) FROM courses WHERE is_active = 1");
} catch (PDOException $e) {}

// ============================================================
// SECTION 5: FETCH RESULTS FORWARDED TO HOD
// ============================================================
$forwardedResults = [];
try {
    $forwardedResults = dbFetchAll($pdo,
        "SELECT DISTINCT ca.id AS assignment_id,
                c.course_code, c.course_title, c.level,
                u.full_name AS lecturer_name,
                s.semester_name, ac.session_name,
                (SELECT COUNT(*) FROM results r2
                 WHERE r2.assignment_id = ca.id AND r2.status = 'forwarded') AS student_count,
                (SELECT COUNT(*) FROM results r3
                 WHERE r3.assignment_id = ca.id AND r3.grade = 'F'
                 AND r3.status = 'forwarded') AS fail_count,
                (SELECT MAX(r4.forwarded_at) FROM results r4
                 WHERE r4.assignment_id = ca.id) AS forwarded_at
         FROM results r
         JOIN course_assignments ca ON r.assignment_id = ca.id
         JOIN courses c ON ca.course_id = c.id
         JOIN users u ON ca.lecturer_id = u.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE r.status = 'forwarded'
         ORDER BY forwarded_at DESC
         LIMIT 10"
    );
} catch (PDOException $e) {
    $forwardedResults = [];
}

// ============================================================
// SECTION 6: FETCH RECENT BATCH UPLOADS
// ============================================================
$recentUploads = [];
try {
    $recentUploads = dbFetchAll($pdo,
        "SELECT bu.id, bu.file_name, bu.status, bu.total_rows,
                bu.successful_rows, bu.failed_rows, bu.uploaded_at,
                s.semester_name, ac.session_name
         FROM batch_uploads bu
         JOIN semesters s ON bu.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         ORDER BY bu.uploaded_at DESC
         LIMIT 5"
    );
} catch (PDOException $e) {
    $recentUploads = [];
}

// ============================================================
// SECTION 7: FETCH RECENT AUDIT LOG
// ============================================================
$recentActivity = [];
try {
    $recentActivity = dbFetchAll($pdo,
        "SELECT al.action, al.target_table, al.created_at,
                u.full_name, u.role
         FROM audit_log al
         LEFT JOIN users u ON al.user_id = u.id
         ORDER BY al.created_at DESC
         LIMIT 8"
    );
} catch (PDOException $e) {
    $recentActivity = [];
}

// ============================================================
// SECTION 8: FETCH RECENT NOTIFICATIONS
// ============================================================
$recentNotifications = [];
try {
    $recentNotifications = dbFetchAll($pdo,
        "SELECT id, title, message, type, is_read, created_at
         FROM notifications
         WHERE recipient_id = ?
         ORDER BY created_at DESC
         LIMIT 5",
        [$hodId]
    );
} catch (PDOException $e) {
    $recentNotifications = [];
}

// Action labels for audit log display
$actionLabels = [
    'login_success'                    => 'Logged in',
    'login_failed'                     => 'Failed login attempt',
    'logout'                           => 'Logged out',
    'submit_scores'                    => 'Submitted scores',
    'save_scores_draft'                => 'Saved draft scores',
    'flag_result'                      => 'Flagged result',
    'forward_result'                   => 'Forwarded result',
    'approve_result'                   => 'Approved result',
    'final_approve'                    => 'Final approved results',
    'carryover_registration_submitted' => 'Submitted carryover',
    'carryover_approved'               => 'Approved carryover',
    'carryover_rejected'               => 'Rejected carryover',
    'view_student_transcript'          => 'Viewed transcript',
    'create_staff'                     => 'Created staff account',
    'batch_upload'                     => 'Batch uploaded scores',
    'activate_staff'                   => 'Activated staff account',
    'deactivate_staff'                 => 'Deactivated staff account',
    'reset_staff_password'             => 'Reset staff password',
    'update_course_split'              => 'Updated CA/Exam split',
];

// Total in pipeline (for chart)
$totalInPipeline = array_sum($pipelineStats);

$pageTitle      = 'HOD Dashboard';
$pageBreadcrumb = 'HOD / Overview & Pipeline';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOD Dashboard &mdash; CSCResultPortal</title>
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

            <!-- HOD Welcome Banner -->
            <div class="hod-welcome-banner">
                <div class="welcome-banner-left">
                    <div class="hod-avatar">
                        <?php
                        $fullNameForAvatar = $_SESSION['full_name'] ?? 'HOD';
                        $nameParts = explode(' ', trim($fullNameForAvatar));
                        $initials  = '';
                        foreach ($nameParts as $p) {
                            if (!empty($p)) { $initials .= strtoupper($p[0]); }
                            if (strlen($initials) >= 2) break;
                        }
                        if (empty($initials)) { $initials = 'H'; }
                        echo htmlspecialchars($initials);
                        ?>
                    </div>
                    <div>
                        <h2 class="welcome-title">
                            HOD Control Panel
                        </h2>
                        <p class="welcome-subtitle">
                            <i class="fa-solid fa-university me-1"></i>
                            Federal University of Technology Owerri &mdash; Computer Science
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
                <div class="d-none d-md-flex gap-3">
                    <div class="hod-banner-chip">
                        <div class="chip-number"><?= $pipelineStats['forwarded'] ?></div>
                        <div class="chip-label">Awaiting HOD Approval</div>
                    </div>
                    <div class="hod-banner-chip chip-light">
                        <div class="chip-number"><?= $totalStudents ?></div>
                        <div class="chip-label">Total Students</div>
                    </div>
                </div>
            </div>

            <!-- Forwarded Alert -->
            <?php if ($pipelineStats['forwarded'] > 0): ?>
                <div class="portal-alert warning mb-4">
                    <i class="fa-solid fa-clipboard-check"></i>
                    <div>
                        <strong><?= $pipelineStats['forwarded'] ?> course result(s)</strong> have been
                        forwarded by the Course Advisor and are awaiting your approval.
                        <a href="<?= APP_URL ?>/pages/hod/approve-results.php"
                           class="fw-600 ms-2" style="color:#856404; text-decoration:underline;">
                            Go to Approve Results &rarr;
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============================================================
                 FULL PIPELINE VIEW
                 ============================================================ -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-diagram-next"></i>
                        Full Departmental Result Pipeline
                    </div>
                </div>
                <div class="content-card-body">
                    <div class="hod-pipeline">
                        <?php
                        $stages = [
                            ['status' => 'draft',          'label' => 'Draft',           'icon' => 'fa-pen',               'color' => '#6c757d'],
                            ['status' => 'submitted',      'label' => 'Submitted',        'icon' => 'fa-paper-plane',       'color' => '#17a2b8'],
                            ['status' => 'flagged',        'label' => 'Flagged',          'icon' => 'fa-flag',              'color' => '#dc3545'],
                            ['status' => 'forwarded',      'label' => 'Forwarded',        'icon' => 'fa-share-from-square', 'color' => '#ffc107'],
                            ['status' => 'hod_approved',   'label' => 'HOD Approved',     'icon' => 'fa-circle-check',      'color' => '#006400'],
                            ['status' => 'final_approved', 'label' => 'Final Approved',   'icon' => 'fa-graduation-cap',    'color' => '#FFD700'],
                        ];
                        $stageCount = count($stages);
                        foreach ($stages as $idx => $stage):
                            $count = $pipelineStats[$stage['status']] ?? 0;
                        ?>
                            <div class="hod-pipeline-stage <?= $count > 0 ? 'active' : '' ?>">
                                <div class="hod-stage-icon" style="--stage-color:<?= $stage['color'] ?>;">
                                    <i class="fa-solid <?= $stage['icon'] ?>"></i>
                                </div>
                                <div class="hod-stage-count"><?= $count ?></div>
                                <div class="hod-stage-label"><?= htmlspecialchars($stage['label']) ?></div>
                            </div>
                            <?php if ($idx < $stageCount - 1): ?>
                                <div class="hod-pipeline-connector">
                                    <div class="connector-line <?= $count > 0 ? 'active' : '' ?>"></div>
                                    <i class="fa-solid fa-chevron-right connector-arrow"></i>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Stat Cards Row -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon green">
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
                        <div class="stat-card-icon gold">
                            <i class="fa-solid fa-chalkboard-teacher"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $totalLecturers ?></div>
                            <div class="stat-card-label">Lecturers</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon info">
                            <i class="fa-solid fa-book"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $totalCourses ?></div>
                            <div class="stat-card-label">Active Courses</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon warning">
                            <i class="fa-solid fa-clipboard-check"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $pipelineStats['forwarded'] ?></div>
                            <div class="stat-card-label">Awaiting Approval</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Grid -->
            <div class="row g-4">

                <!-- Left: Forwarded Results Queue -->
                <div class="col-lg-8">
                    <div class="content-card mb-4">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-inbox"></i>
                                Results Forwarded for HOD Approval
                            </div>
                            <a href="<?= APP_URL ?>/pages/hod/approve-results.php"
                               class="btn-portal-primary btn-portal-sm">
                                <i class="fa-solid fa-clipboard-check"></i> Approve Results
                            </a>
                        </div>
                        <div style="overflow-x:auto;">
                            <?php if (count($forwardedResults) > 0): ?>
                                <table class="portal-table">
                                    <thead>
                                        <tr>
                                            <th>Course</th>
                                            <th>Lecturer</th>
                                            <th>Level</th>
                                            <th class="text-center">Students</th>
                                            <th class="text-center">Failures</th>
                                            <th>Forwarded</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($forwardedResults as $row): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-700 text-green">
                                                        <?= htmlspecialchars($row['course_code'] ?? '') ?>
                                                    </div>
                                                    <div class="text-muted-sm">
                                                        <?= htmlspecialchars($row['course_title'] ?? '') ?>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($row['lecturer_name'] ?? '') ?></td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <?= htmlspecialchars($row['level'] ?? '') ?>L
                                                    </span>
                                                </td>
                                                <td class="text-center fw-700">
                                                    <?= (int) ($row['student_count'] ?? 0) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ((int) ($row['fail_count'] ?? 0) > 0): ?>
                                                        <span class="fw-700 text-danger">
                                                            <?= (int) $row['fail_count'] ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted-sm">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-muted-sm">
                                                    <?= !empty($row['forwarded_at'])
                                                        ? date('d M Y', strtotime($row['forwarded_at']))
                                                        : '--' ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= APP_URL ?>/pages/hod/approve-results.php?assignment_id=<?= (int) ($row['assignment_id'] ?? 0) ?>"
                                                       class="btn-portal-gold btn-portal-sm">
                                                        <i class="fa-solid fa-circle-check"></i> Approve
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-check-circle text-green"></i>
                                    <p>No results are currently awaiting your approval.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent System Activity -->
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                Recent System Activity
                            </div>
                        </div>
                        <div class="content-card-body" style="padding:0;">
                            <?php if (count($recentActivity) > 0): ?>
                                <div class="activity-list">
                                    <?php foreach ($recentActivity as $log): ?>
                                        <?php
                                        // Safely resolve action label
                                        $actionKey   = isset($log['action']) ? (string) $log['action'] : '';
                                        $actionLabel = isset($actionLabels[$actionKey])
                                            ? $actionLabels[$actionKey]
                                            : $actionKey;
                                        ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <i class="fa-solid fa-circle-dot"></i>
                                            </div>
                                            <div class="activity-content">
                                                <span class="activity-user fw-600">
                                                    <?= htmlspecialchars($log['full_name'] ?? 'System') ?>
                                                </span>
                                                <span class="activity-action">
                                                    <?= htmlspecialchars($actionLabel) ?>
                                                </span>
                                                <?php if (!empty($log['target_table'])): ?>
                                                    <span class="text-muted-sm">
                                                        (<?= htmlspecialchars($log['target_table']) ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-time">
                                                <?= date('d M, g:ia', strtotime($log['created_at'] ?? 'now')) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-empty-state" style="padding:30px;">
                                    <i class="fa-solid fa-list"></i>
                                    <p>No recent system activity.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="col-lg-4">

                    <!-- Pipeline Doughnut Chart -->
                    <div class="content-card mb-4">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-chart-pie"></i>
                                Pipeline Distribution
                            </div>
                        </div>
                        <div class="content-card-body d-flex flex-column align-items-center">
                            <?php if ($totalInPipeline > 0): ?>
                                <canvas id="pipelineChart" height="200"
                                        style="max-width:200px;"></canvas>
                                <div class="pipeline-legend mt-3">
                                    <?php
                                    $legendItems = [
                                        'Draft'         => ['count' => $pipelineStats['draft'],          'color' => '#aaa'],
                                        'Submitted'     => ['count' => $pipelineStats['submitted'],      'color' => '#17a2b8'],
                                        'Flagged'       => ['count' => $pipelineStats['flagged'],        'color' => '#dc3545'],
                                        'Forwarded'     => ['count' => $pipelineStats['forwarded'],      'color' => '#ffc107'],
                                        'HOD Approved'  => ['count' => $pipelineStats['hod_approved'],  'color' => '#006400'],
                                        'Final Approved'=> ['count' => $pipelineStats['final_approved'],'color' => '#FFD700'],
                                    ];
                                    foreach ($legendItems as $label => $item):
                                        if ($item['count'] === 0) continue;
                                    ?>
                                        <div class="pipeline-legend-item">
                                            <span class="legend-dot"
                                                  style="background:<?= $item['color'] ?>"></span>
                                            <span class="legend-label">
                                                <?= htmlspecialchars($label) ?>: <strong><?= $item['count'] ?></strong>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-chart-pie"></i>
                                    <p>No result data in the pipeline yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Notifications -->
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
                                                    echo htmlspecialchars(strlen($msg) > 75 ? substr($msg, 0, 75) . '...' : $msg);
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
                                <div class="table-empty-state" style="padding:24px 16px;">
                                    <i class="fa-solid fa-bell-slash"></i>
                                    <p>No recent notifications.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /.col-lg-4 -->

            </div><!-- /.row -->

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($totalInPipeline > 0): ?>
<script>
(function() {
    const ctx = document.getElementById('pipelineChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Draft','Submitted','Flagged','Forwarded','HOD Approved','Final Approved'],
            datasets: [{
                data: [
                    <?= $pipelineStats['draft'] ?>,
                    <?= $pipelineStats['submitted'] ?>,
                    <?= $pipelineStats['flagged'] ?>,
                    <?= $pipelineStats['forwarded'] ?>,
                    <?= $pipelineStats['hod_approved'] ?>,
                    <?= $pipelineStats['final_approved'] ?>
                ],
                backgroundColor: ['#aaa','#17a2b8','#dc3545','#ffc107','#006400','#FFD700'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ` ${ctx.label}: ${ctx.parsed} course(s)`;
                        }
                    }
                }
            },
            cutout: '62%'
        }
    });
})();
</script>
<?php endif; ?>

<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/hod.js"></script>
</body>
</html>