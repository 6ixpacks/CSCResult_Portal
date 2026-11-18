<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../mail/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
// Level this advisor is locked to (null = all levels). Results are scoped by COURSE level.
$advLevel    = getAdvisorLevel($pdo, $advisorId);
$levelSql    = $advLevel ? " AND c.level = ?" : "";
$levelParams = $advLevel ? [$advLevel] : [];

// Handle actions
$actionMessage = '';
$actionSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action       = $_POST['action'];
    $assignmentId = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
    $flagReason   = trim($_POST['flag_reason'] ?? '');

    if ($assignmentId <= 0) {
        $actionMessage = 'Invalid course assignment reference.';
    } elseif ($action === 'flag' && empty($flagReason)) {
        $actionMessage = 'Please provide a reason for flagging this result.';
    } else {
        try {
            $pdo->beginTransaction();

                        $assignment = dbFetchOne($pdo,
                "SELECT ca.*, c.course_code, c.course_title,
                        u.id AS lecturer_id, u.full_name AS lecturer_name, u.email AS lecturer_email,
                        s.semester_name, ac.session_name
                 FROM course_assignments ca
                 JOIN courses c ON ca.course_id = c.id
                 JOIN users u ON ca.lecturer_id = u.id
                 JOIN semesters s ON ca.semester_id = s.id
                 JOIN academic_sessions ac ON s.session_id = ac.id
                 WHERE ca.id = ?" . $levelSql,
                array_merge([$assignmentId], $levelParams)
            );

            if (!is_array($assignment)) {
                                throw new Exception('Course assignment not found, or it is not for your level.');
            }

            $hod = dbFetchOne($pdo,
                "SELECT id, full_name, email FROM users WHERE role = 'hod' AND is_active = 1 LIMIT 1"
            );

            $advisor = dbFetchOne($pdo,
                "SELECT full_name FROM users WHERE id = ?",
                [$advisorId]
            );

            if ($action === 'flag') {
                $submittedResults = dbFetchAll($pdo,
                    "SELECT id FROM results WHERE assignment_id = ? AND status = 'submitted'",
                    [$assignmentId]
                );

                foreach ($submittedResults as $res) {
                    dbExecute($pdo,
                        "UPDATE results SET status = 'flagged', flagged_at = NOW(), flagged_by = ?
                         WHERE id = ?",
                        [$advisorId, $res['id']]
                    );

                    dbInsert($pdo,
                        "INSERT INTO result_flags
                            (result_id, assignment_id, flagged_by, flag_reason)
                         VALUES (?, ?, ?, ?)",
                        [$res['id'], $assignmentId, $advisorId, $flagReason]
                    );
                }

                dbExecute($pdo,
                    "UPDATE course_assignments SET scores_submitted = 0 WHERE id = ?",
                    [$assignmentId]
                );

                sendResultFlaggedEmail(
                    (int) $assignment['lecturer_id'],
                    $assignment['lecturer_email'],
                    $assignment['lecturer_name'],
                    $advisor['full_name'] ?? 'Course Advisor',
                    $assignment['course_code'],
                    $assignment['course_title'],
                    $flagReason,
                    null,
                    $advisorId
                );

                writeAuditLog($pdo, $advisorId, 'flag_result', 'results', $assignmentId,
                    null, ['reason' => $flagReason, 'course' => $assignment['course_code']]
                );

                $pdo->commit();
                $actionSuccess = true;
                $actionMessage = "Results for {$assignment['course_code']} have been flagged back to {$assignment['lecturer_name']}.";

            } elseif ($action === 'forward') {
                $updated = dbExecute($pdo,
                    "UPDATE results SET status = 'forwarded', forwarded_at = NOW(), forwarded_by = ?
                     WHERE assignment_id = ? AND status = 'submitted'",
                    [$advisorId, $assignmentId]
                );

                if ($updated === 0) {
                    throw new Exception('No submitted results found to forward.');
                }

                if (is_array($hod)) {
                    sendResultForwardedToHODEmail(
                        (int) $hod['id'],
                        $hod['email'],
                        $hod['full_name'],
                        $advisor['full_name'] ?? 'Course Advisor',
                        $assignment['course_code'],
                        $assignment['course_title'],
                        $assignment['semester_name'],
                        $assignment['session_name'],
                        null,
                        $advisorId
                    );
                }

                writeAuditLog($pdo, $advisorId, 'forward_result', 'results', $assignmentId,
                    null, ['course' => $assignment['course_code']]
                );

                $pdo->commit();
                $actionSuccess = true;
                $actionMessage = "Results for {$assignment['course_code']} successfully forwarded to the HOD.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $actionMessage = 'Error: ' . $e->getMessage();
            logDatabaseError('Advisor review error: ' . $e->getMessage());
        }
    }
}

// Fetch all submitted assignments
$allSubmissions = [];
try {
        $allSubmissions = dbFetchAll($pdo,
        "SELECT DISTINCT ca.id AS assignment_id,
                c.course_code, c.course_title, c.level,
                u.full_name AS lecturer_name,
                s.semester_name, ac.session_name,
                ca.submitted_at, ca.ca_max, ca.exam_max,
                (SELECT COUNT(*) FROM results r2
                 WHERE r2.assignment_id = ca.id AND r2.status = 'submitted') AS submitted_count,
                (SELECT COUNT(*) FROM results r3
                 WHERE r3.assignment_id = ca.id AND r3.grade = 'F'
                 AND r3.status = 'submitted') AS fail_count
         FROM results r
         JOIN course_assignments ca ON r.assignment_id = ca.id
         JOIN courses c ON ca.course_id = c.id
         JOIN users u ON ca.lecturer_id = u.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE r.status = 'submitted'" . $levelSql . "
         ORDER BY ca.submitted_at DESC",
        $levelParams
    );
} catch (PDOException $e) {
    $allSubmissions = [];
}

// Selected assignment preview
$selectedAssignment = null;
$assignmentResults  = [];
$selectedId = isset($_GET['assignment_id']) ? (int) $_GET['assignment_id'] : 0;

if ($selectedId > 0) {
    try {
               $selectedAssignment = dbFetchOne($pdo,
            "SELECT ca.*, c.course_code, c.course_title, c.credit_units, c.level,
                    u.id AS lecturer_id, u.full_name AS lecturer_name,
                    s.semester_name, ac.session_name
             FROM course_assignments ca
             JOIN courses c ON ca.course_id = c.id
             JOIN users u ON ca.lecturer_id = u.id
             JOIN semesters s ON ca.semester_id = s.id
             JOIN academic_sessions ac ON s.session_id = ac.id
             WHERE ca.id = ?" . $levelSql,
            array_merge([$selectedId], $levelParams)
        );

        if (is_array($selectedAssignment)) {
            $assignmentResults = dbFetchAll($pdo,
                "SELECT r.id AS result_id, r.ca_score, r.exam_score, r.total_score,
                        r.grade, r.grade_point, r.quality_point, r.credit_units, r.status,
                        u.full_name AS student_name, u.matric_number,
                        sp.level AS student_level
                 FROM results r
                 JOIN users u ON r.student_id = u.id
                 LEFT JOIN student_profiles sp ON u.id = sp.user_id
                 WHERE r.assignment_id = ?
                 AND r.status = 'submitted'
                 ORDER BY u.matric_number ASC",
                [$selectedId]
            );
        }
    } catch (PDOException $e) {
        $selectedAssignment = null;
        $assignmentResults  = [];
    }

    $totalUnits = 0;
    $totalQP    = 0.0;
    $failCount  = 0;
    $passCount  = 0;
    foreach ($assignmentResults as $ar) {
        $totalUnits += (int) ($ar['credit_units'] ?? 0);
        $totalQP    += (float) ($ar['quality_point'] ?? 0);
        if (($ar['grade'] ?? '') === 'F') { $failCount++; } else { $passCount++; }
    }
}

$pageTitle      = 'Review Results';
$pageBreadcrumb = 'Advisor / Review Results';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Results &mdash; CSCResultPortal</title>
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
            <div class="page-header">
                <h1 class="page-header-title">
                    <i class="fa-solid fa-magnifying-glass-chart me-2 text-green"></i>
                    Review Results
                </h1>
                <p class="page-header-subtitle">
                    Review submitted lecturer scores. Flag back for correction or forward to HOD.
                </p>
                <hr class="page-header-divider">
            </div>

            <?php if (!empty($actionMessage)): ?>
                <div class="portal-alert <?= $actionSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $actionSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($actionMessage) ?></div>
                </div>
            <?php endif; ?>

            <div class="row g-4">

                <div class="col-lg-4">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-list"></i>
                                Awaiting Review
                            </div>
                            <?php if (count($allSubmissions) > 0): ?>
                                <span class="status-badge submitted">
                                    <?= count($allSubmissions) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="overflow-y:auto; max-height:600px;">
                            <?php if (count($allSubmissions) > 0): ?>
                                <div class="review-list">
                                    <?php foreach ($allSubmissions as $sub): ?>
                                        <?php $isActive = ($selectedId === (int) ($sub['assignment_id'] ?? 0)); ?>
                                        <a href="review-results.php?assignment_id=<?= (int) ($sub['assignment_id'] ?? 0) ?>"
                                           class="review-list-item <?= $isActive ? 'active' : '' ?>">
                                            <div class="review-list-code">
                                                <?= htmlspecialchars($sub['course_code'] ?? '') ?>
                                                <span class="badge bg-light text-dark border ms-1" style="font-size:10px;">
                                                    <?= htmlspecialchars($sub['level'] ?? '') ?>L
                                                </span>
                                            </div>
                                            <div class="review-list-title">
                                                <?= htmlspecialchars($sub['course_title'] ?? '') ?>
                                            </div>
                                            <div class="review-list-meta">
                                                <span><?= htmlspecialchars($sub['lecturer_name'] ?? '') ?></span>
                                                <span><?= (int) ($sub['submitted_count'] ?? 0) ?> students</span>
                                            </div>
                                            <?php if ((int) ($sub['fail_count'] ?? 0) > 0): ?>
                                                <div class="mt-1">
                                                    <span class="status-badge flagged" style="font-size:10px;">
                                                        <?= (int) $sub['fail_count'] ?> F grades
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="review-list-time">
                                                <i class="fa-regular fa-clock fa-xs me-1"></i>
                                                <?= !empty($sub['submitted_at'])
                                                    ? date('d M Y, g:ia', strtotime($sub['submitted_at']))
                                                    : '--' ?>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-check-circle text-green"></i>
                                    <p>No results awaiting review.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <?php if (is_array($selectedAssignment)): ?>

                        <div class="content-card mb-4">
                            <div class="content-card-body">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <h4 class="fw-700 text-green mb-1">
                                            <?= htmlspecialchars($selectedAssignment['course_code'] ?? '') ?>
                                            &mdash;
                                            <?= htmlspecialchars($selectedAssignment['course_title'] ?? '') ?>
                                        </h4>
                                        <p class="text-muted mb-1">
                                            Lecturer: <strong><?= htmlspecialchars($selectedAssignment['lecturer_name'] ?? '') ?></strong>
                                            &bull;
                                            <?= htmlspecialchars($selectedAssignment['session_name'] ?? '') ?>
                                            &mdash;
                                            <?= htmlspecialchars($selectedAssignment['semester_name'] ?? '') ?>
                                        </p>
                                        <p class="text-muted-sm">
                                            CA Split: <?= (int) ($selectedAssignment['ca_max'] ?? 0) ?>% /
                                            Exam Split: <?= (int) ($selectedAssignment['exam_max'] ?? 0) ?>% |
                                            <?= (int) ($selectedAssignment['credit_units'] ?? 0) ?> credit units
                                        </p>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <span class="status-badge submitted">
                                            <?= count($assignmentResults) ?> students
                                        </span>
                                        <?php if ($failCount > 0): ?>
                                            <span class="status-badge flagged">
                                                <?= $failCount ?> failed
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="content-card mb-4">
                            <div class="content-card-header">
                                <div class="content-card-title">
                                    <i class="fa-solid fa-table"></i>
                                    Score Sheet Preview
                                </div>
                                <a href="<?= APP_URL ?>/pages/shared/grade-report.php?assignment_id=<?= (int) $selectedId ?>" 
                                   target="_blank" 
                                   class="btn-portal-gold btn-portal-sm">
                                    <i class="fa-solid fa-print"></i> Print for Senate
                                </a>
                            </div>
                            <div style="overflow-x:auto; max-height:340px; overflow-y:auto;">
                                <?php if (count($assignmentResults) > 0): ?>
                                    <table class="portal-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Matric</th>
                                                <th>Student Name</th>
                                                <th class="text-center">CA</th>
                                                <th class="text-center">Exam</th>
                                                <th class="text-center">Total</th>
                                                <th class="text-center">Grade</th>
                                                <th class="text-center">Point</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($assignmentResults as $idx => $res): ?>
                                                <tr class="<?= ($res['grade'] ?? '') === 'F' ? 'table-danger-subtle' : '' ?>">
                                                    <td class="text-muted-sm"><?= $idx + 1 ?></td>
                                                    <td class="fw-700">
                                                        <?= htmlspecialchars($res['matric_number'] ?? '') ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($res['student_name'] ?? '') ?></td>
                                                    <td class="text-center">
                                                        <?= number_format((float) ($res['ca_score'] ?? 0), 1) ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?= number_format((float) ($res['exam_score'] ?? 0), 1) ?>
                                                    </td>
                                                    <td class="text-center fw-700">
                                                        <?= number_format((float) ($res['total_score'] ?? 0), 1) ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="grade-badge grade-<?= strtolower($res['grade'] ?? 'f') ?>">
                                                            <?= htmlspecialchars($res['grade'] ?? '') ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <?= number_format((float) ($res['grade_point'] ?? 0), 1) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="table-empty-state">
                                        <i class="fa-solid fa-table"></i>
                                        <p>No submitted scores available for preview.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (count($assignmentResults) > 0): ?>
                                <div class="content-card-footer">
                                    <div class="row g-2 text-center">
                                        <div class="col-3">
                                            <div class="text-muted-sm">Total Students</div>
                                            <div class="fw-700"><?= count($assignmentResults) ?></div>
                                        </div>
                                        <div class="col-3">
                                            <div class="text-muted-sm">Passed</div>
                                            <div class="fw-700 text-green"><?= $passCount ?></div>
                                        </div>
                                        <div class="col-3">
                                            <div class="text-muted-sm">Failed</div>
                                            <div class="fw-700 text-danger"><?= $failCount ?></div>
                                        </div>
                                        <div class="col-3">
                                            <div class="text-muted-sm">Pass Rate</div>
                                            <div class="fw-700">
                                                <?= count($assignmentResults) > 0
                                                    ? round(($passCount / count($assignmentResults)) * 100, 1) . '%'
                                                    : '0%' ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (count($assignmentResults) > 0): ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="content-card advisor-action-card flag-card">
                                        <div class="content-card-header">
                                            <div class="content-card-title" style="color:#dc3545;">
                                                <i class="fa-solid fa-flag"></i>
                                                Flag Back to Lecturer
                                            </div>
                                        </div>
                                        <div class="content-card-body">
                                            <p class="text-muted-sm mb-3">
                                                Flag these results back to the lecturer with a correction note.
                                            </p>
                                            <form method="POST" action="review-results.php?assignment_id=<?= $selectedId ?>">
                                                <input type="hidden" name="action" value="flag">
                                                <input type="hidden" name="assignment_id" value="<?= $selectedId ?>">
                                                <div class="mb-3">
                                                    <label class="portal-form-label">Reason for Flagging *</label>
                                                    <textarea name="flag_reason" class="portal-form-control"
                                                              rows="4" required
                                                              placeholder="Describe the specific issue..."></textarea>
                                                </div>
                                                <button type="submit" class="btn-portal-danger w-100" id="flagBtn"
                                                        onclick="return confirm('Flag these results back to the lecturer?')">
                                                    <i class="fa-solid fa-flag"></i> Flag Back
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="content-card advisor-action-card forward-card">
                                        <div class="content-card-header">
                                            <div class="content-card-title" style="color:#006400;">
                                                <i class="fa-solid fa-share-from-square"></i>
                                                Forward to HOD
                                            </div>
                                        </div>
                                        <div class="content-card-body">
                                            <p class="text-muted-sm mb-3">
                                                Scores look correct. Forward to the HOD for approval.
                                            </p>
                                            <div class="advisor-checklist mb-3">
                                                <div class="advisor-check-item">
                                                    <i class="fa-solid fa-square-check text-green"></i>
                                                    All scores entered and validated
                                                </div>
                                                <div class="advisor-check-item">
                                                    <i class="fa-solid fa-square-check text-green"></i>
                                                    Grades correctly assigned
                                                </div>
                                                <div class="advisor-check-item">
                                                    <i class="fa-solid fa-square-check text-green"></i>
                                                    CA + Exam totals verified
                                                </div>
                                            </div>
                                            <form method="POST" action="review-results.php?assignment_id=<?= $selectedId ?>">
                                                <input type="hidden" name="action" value="forward">
                                                <input type="hidden" name="assignment_id" value="<?= $selectedId ?>">
                                                <button type="submit" class="btn-portal-primary w-100" id="forwardBtn"
                                                        onclick="return confirm('Forward these results to the HOD for approval?')">
                                                    <i class="fa-solid fa-share-from-square"></i> Forward to HOD
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="content-card">
                            <div class="table-empty-state" style="padding: 60px 40px;">
                                <i class="fa-solid fa-magnifying-glass-chart"
                                   style="font-size:48px; color:#e0e0e0; margin-bottom:16px;"></i>
                                <p style="font-size:15px; color:#999;">
                                    Select a submitted course from the list on the left to preview and review the scores.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
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