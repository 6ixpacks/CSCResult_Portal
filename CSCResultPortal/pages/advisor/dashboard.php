<?php

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
    $_SESSION['role'] !== 'advisor'
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

$advisorId = (int) $_SESSION['user_id'];
// Level this advisor is locked to (null = all levels)
$advLevel    = getAdvisorLevel($pdo, $advisorId);
$levelSql    = $advLevel ? " AND sp.level = ?" : "";
$levelParams = $advLevel ? [$advLevel] : [];

// ============================================================
// SECTION 2: FETCH CURRENT SEMESTER
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
$statsAwaitingReview   = 0;
$statsForwarded        = 0;
$statsFlagged          = 0;
$statsHodApproved      = 0;
$statsFinalApproved    = 0;
$statsPendingCarryover = 0;

try {
    // Counts distinct course submissions in a given status, limited to this advisor's level
    $countByStatus = function (string $status) use ($pdo, $advLevel): int {
        $sql = "SELECT COUNT(DISTINCT r.assignment_id) FROM results r
                JOIN course_assignments ca ON r.assignment_id = ca.id
                JOIN courses c ON ca.course_id = c.id
                WHERE r.status = ?";
        $params = [$status];
        if ($advLevel) {
            $sql    .= " AND c.level = ?";
            $params[] = $advLevel;
        }
        return dbCount($pdo, $sql, $params);
    };

    $statsAwaitingReview = $countByStatus('submitted');
    $statsForwarded      = $countByStatus('forwarded');
    $statsFlagged        = $countByStatus('flagged');
    $statsHodApproved    = $countByStatus('hod_approved');
    $statsFinalApproved  = $countByStatus('final_approved');

    $statsPendingCarryover = dbCount($pdo,
        "SELECT COUNT(*) FROM carryover_registrations cr
         JOIN student_profiles sp ON sp.user_id = cr.student_id
         WHERE cr.status = 'pending'" . $levelSql,
        $levelParams
    );
} catch (PDOException $e) {}

// ============================================================
// SECTION 4: FETCH RECENT SUBMITTED RESULTS
// ============================================================
$recentSubmissions = [];
try {
        $recentSubmissions = dbFetchAll($pdo,
        "SELECT DISTINCT ca.id AS assignment_id,
                c.course_code, c.course_title, c.level,
                u.full_name AS lecturer_name,
                s.semester_name, ac.session_name,
                ca.submitted_at,
                (SELECT COUNT(*) FROM results r2
                 WHERE r2.assignment_id = ca.id AND r2.status = 'submitted') AS student_count
         FROM results r
         JOIN course_assignments ca ON r.assignment_id = ca.id
         JOIN courses c ON ca.course_id = c.id
         JOIN users u ON ca.lecturer_id = u.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE r.status = 'submitted'" . ($advLevel ? " AND c.level = ?" : "") . "
         ORDER BY ca.submitted_at DESC
         LIMIT 8",
        $levelParams
    );
} catch (PDOException $e) {
    $recentSubmissions = [];
}

// ============================================================
// SECTION 5: FETCH RECENT CARRYOVER SUBMISSIONS
// ============================================================
$recentCarryovers = [];
try {
        $recentCarryovers = dbFetchAll($pdo,
        "SELECT cr.id, cr.status, cr.created_at,
                u.full_name AS student_name, u.matric_number,
                c.course_code, c.course_title
         FROM carryover_registrations cr
         JOIN users u ON cr.student_id = u.id
         JOIN student_profiles sp ON sp.user_id = u.id
         JOIN courses c ON cr.course_id = c.id
         WHERE cr.status = 'pending'" . $levelSql . "
         ORDER BY cr.created_at DESC
         LIMIT 5",
        $levelParams
    );
} catch (PDOException $e) {
    $recentCarryovers = [];
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
        [$advisorId]
    );
} catch (PDOException $e) {
    $recentNotifications = [];
}

// ============================================================
// SECTION 7: BUILD WORKFLOW PIPELINE DATA
// ============================================================
$pipelineStages = [
    ['label' => 'Submitted',      'count' => $statsAwaitingReview, 'status' => 'submitted',      'icon' => 'fa-pen-to-square'],
    ['label' => 'Flagged Back',   'count' => $statsFlagged,        'status' => 'flagged',        'icon' => 'fa-flag'],
    ['label' => 'Forwarded',      'count' => $statsForwarded,      'status' => 'forwarded',      'icon' => 'fa-share-from-square'],
    ['label' => 'HOD Approved',   'count' => $statsHodApproved,    'status' => 'hod_approved',   'icon' => 'fa-circle-check'],
    ['label' => 'Final Approved', 'count' => $statsFinalApproved,  'status' => 'final_approved', 'icon' => 'fa-graduation-cap'],
];
$stageCount = count($pipelineStages);

$pageTitle      = 'Advisor Overview';
$pageBreadcrumb = 'Advisor / Overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overview &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/advisor.css">
</head>
<body>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="portal-wrapper">
    <?php require_once __DIR__ . '/../../includes/advisor-sidebar.php'; ?>

    <div class="portal-main">
        <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

        <main class="portal-content">

            <!-- Welcome Banner -->
            <div class="advisor-welcome-banner">
                <div class="welcome-banner-left">
                    <div class="welcome-avatar">
                        <?php
                        $fullNameForAvatar = $_SESSION['full_name'] ?? 'Advisor';
                        $nameParts = explode(' ', trim($fullNameForAvatar));
                        $initials  = '';
                        foreach ($nameParts as $p) {
                            if (!empty($p)) { $initials .= strtoupper($p[0]); }
                            if (strlen($initials) >= 2) break;
                        }
                        if (empty($initials)) { $initials = 'A'; }
                        echo htmlspecialchars($initials);
                        ?>
                    </div>
                    <div>
                        <h2 class="welcome-title">Course Advisor Overview</h2>
                        <p class="welcome-subtitle">
                            <i class="fa-solid fa-user-tie me-1"></i>
                            <?= htmlspecialchars($_SESSION['full_name'] ?? 'Advisor') ?>
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
                    <div class="welcome-stat-chip chip-gold">
                        <div class="chip-number"><?= $statsAwaitingReview ?></div>
                        <div class="chip-label">Awaiting Review</div>
                    </div>
                    <div class="welcome-stat-chip chip-white">
                        <div class="chip-number"><?= $statsPendingCarryover ?></div>
                        <div class="chip-label">Carryover Pending</div>
                    </div>
                </div>
            </div>

            <!-- Workflow Pipeline -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-diagram-next"></i>
                        Result Approval Pipeline
                    </div>
                </div>
                <div class="content-card-body">
                    <div class="pipeline-container">
                        <?php foreach ($pipelineStages as $idx => $stage): ?>
                            <div class="pipeline-stage <?= ($stage['count'] ?? 0) > 0 ? 'has-items' : '' ?>">
                                <div class="pipeline-stage-icon <?= htmlspecialchars($stage['status'] ?? '') ?>">
                                    <i class="fa-solid <?= htmlspecialchars($stage['icon'] ?? 'fa-circle') ?>"></i>
                                </div>
                                <div class="pipeline-stage-count"><?= (int) ($stage['count'] ?? 0) ?></div>
                                <div class="pipeline-stage-label"><?= htmlspecialchars($stage['label'] ?? '') ?></div>
                            </div>
                            <?php if ($idx < $stageCount - 1): ?>
                                <div class="pipeline-arrow">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon gold">
                            <i class="fa-solid fa-inbox"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $statsAwaitingReview ?></div>
                            <div class="stat-card-label">Awaiting Review</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon danger">
                            <i class="fa-solid fa-flag"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $statsFlagged ?></div>
                            <div class="stat-card-label">Flagged Back</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon info">
                            <i class="fa-solid fa-share-from-square"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $statsForwarded ?></div>
                            <div class="stat-card-label">Forwarded to HOD</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-icon green">
                            <i class="fa-solid fa-rotate"></i>
                        </div>
                        <div>
                            <div class="stat-card-value"><?= $statsPendingCarryover ?></div>
                            <div class="stat-card-label">Carryover Pending</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Grid -->
            <div class="row g-4">

                <!-- Left: Recent Submissions -->
                <div class="col-lg-8">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-inbox"></i>
                                Recent Score Submissions Awaiting Review
                            </div>
                            <a href="<?= APP_URL ?>/pages/advisor/review-results.php"
                               class="btn-portal-primary btn-portal-sm">
                                Review All
                            </a>
                        </div>
                        <div style="overflow-x:auto;">
                            <?php if (count($recentSubmissions) > 0): ?>
                                <table class="portal-table">
                                    <thead>
                                        <tr>
                                            <th>Course</th>
                                            <th>Lecturer</th>
                                            <th>Level</th>
                                            <th class="text-center">Students</th>
                                            <th>Session</th>
                                            <th>Submitted At</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentSubmissions as $sub): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-700 text-green">
                                                        <?= htmlspecialchars($sub['course_code'] ?? '') ?>
                                                    </div>
                                                    <div class="text-muted-sm">
                                                        <?= htmlspecialchars($sub['course_title'] ?? '') ?>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($sub['lecturer_name'] ?? '') ?></td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <?= htmlspecialchars($sub['level'] ?? '') ?>L
                                                    </span>
                                                </td>
                                                <td class="text-center fw-700">
                                                    <?= (int) ($sub['student_count'] ?? 0) ?>
                                                </td>
                                                <td class="text-muted-sm">
                                                    <?= htmlspecialchars($sub['session_name'] ?? '') ?>
                                                    <br><?= htmlspecialchars($sub['semester_name'] ?? '') ?>
                                                </td>
                                                <td class="text-muted-sm">
                                                    <?= !empty($sub['submitted_at'])
                                                        ? date('d M Y, g:ia', strtotime($sub['submitted_at']))
                                                        : '--' ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= APP_URL ?>/pages/advisor/review-results.php?assignment_id=<?= (int) ($sub['assignment_id'] ?? 0) ?>"
                                                       class="btn-portal-primary btn-portal-sm">
                                                        <i class="fa-solid fa-magnifying-glass"></i> Review
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-inbox"></i>
                                    <p>No score submissions currently awaiting your review.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="col-lg-4">

                    <!-- Pending Carryovers -->
                    <div class="content-card mb-4">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-rotate"></i>
                                Pending Carryovers
                            </div>
                            <a href="<?= APP_URL ?>/pages/advisor/carryover-list.php"
                               class="btn-portal-outline btn-portal-sm">
                                View All
                            </a>
                        </div>
                        <div class="content-card-body" style="padding:0;">
                            <?php if (count($recentCarryovers) > 0): ?>
                                <div class="advisor-carryover-list">
                                    <?php foreach ($recentCarryovers as $co): ?>
                                        <div class="advisor-carryover-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <div class="fw-700 text-green" style="font-size:13px;">
                                                        <?= htmlspecialchars($co['course_code'] ?? '') ?>
                                                    </div>
                                                    <div style="font-size:12px; color:#444;">
                                                        <?= htmlspecialchars($co['course_title'] ?? '') ?>
                                                    </div>
                                                    <div class="text-muted-sm mt-1">
                                                        <?= htmlspecialchars($co['student_name'] ?? '') ?>
                                                        (<?= htmlspecialchars($co['matric_number'] ?? '') ?>)
                                                    </div>
                                                </div>
                                                <span class="status-badge pending" style="font-size:10px;">
                                                    Pending
                                                </span>
                                            </div>
                                            <div class="text-muted-sm mt-1">
                                                <i class="fa-regular fa-clock fa-xs me-1"></i>
                                                <?= date('d M Y', strtotime($co['created_at'] ?? 'now')) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-empty-state" style="padding:24px 16px;">
                                    <i class="fa-solid fa-check-circle text-green"></i>
                                    <p>No pending carryover registrations.</p>
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

                </div>

            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/advisor.js"></script>
</body>
</html>