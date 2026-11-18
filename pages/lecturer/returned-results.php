<?php
// ============================================================
// CSCResultPortal - Lecturer Returned Results
// File: pages/lecturer/returned-results.php
// Purpose: Lists all results flagged back by the Course Advisor
//          for correction. Shows detailed flag reasons and gives
//          direct access to update and resubmit scores.
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access Control
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
// SECTION 1: FETCH FLAGGED RESULTS FOR THIS LECTURER
// Security check: Only pulls courses assigned to THIS lecturer.
// ============================================================
$flaggedResults = [];
try {
    $flaggedResults = dbFetchAll($pdo,
        "SELECT r.id AS result_id, r.ca_score, r.exam_score, r.total_score, r.grade, r.flagged_at,
                c.course_code, c.course_title,
                u.full_name AS student_name, u.matric_number,
                ca.id AS assignment_id, ca.ca_max, ca.exam_max,
                s.semester_name, ac.session_name,
                adv.full_name AS advisor_name,
                rf.flag_reason, rf.created_at AS flag_time
         FROM results r
         JOIN course_assignments ca ON r.assignment_id = ca.id
         JOIN courses c ON r.course_id = c.id
         JOIN users u ON r.student_id = u.id
         JOIN semesters s ON r.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         LEFT JOIN result_flags rf ON rf.result_id = r.id AND rf.is_resolved = 0
         LEFT JOIN users adv ON rf.flagged_by = adv.id
         WHERE ca.lecturer_id = ? AND r.status = 'flagged'
         ORDER BY rf.created_at DESC",
        [$lecturerId]
    );
} catch (PDOException $e) {
    $flaggedResults = [];
}

$pageTitle      = 'Returned Results';
$pageBreadcrumb = 'Lecturer / Returned Results';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returned Results &mdash; CSCResultPortal</title>
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
                    <i class="fa-solid fa-flag me-2 text-danger"></i>Returned Results (Flagged)
                </h1>
                <p class="page-header-subtitle">
                    Student results returned by the Course Advisor for correction. Review the notes and resubmit.
                </p>
                <hr class="page-header-divider">
            </div>

            <!-- Flagged Notice Banner -->
            <div class="portal-alert warning mb-4">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>Advisor Feedback:</strong>
                    When you resubmit scores via the Enter Scores interface, flagged items are automatically resolved and sent back to the Course Advisor for review.
                </div>
            </div>

            <div class="content-card">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-list-check"></i> Flagged Student Records
                    </div>
                    <?php if (count($flaggedResults) > 0): ?>
                        <span class="status-badge flagged">
                            <?= count($flaggedResults) ?> item(s)
                        </span>
                    <?php endif; ?>
                </div>

                <div style="overflow-x:auto;">
                    <?php if (count($flaggedResults) > 0): ?>
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Course</th>
                                    <th>Student Details</th>
                                    <th class="text-center">CA / Exam / Total</th>
                                    <th>Flag Reason & Advisor Note</th>
                                    <th>Flagged On</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($flaggedResults as $index => $row): ?>
                                    <tr class="table-danger-subtle">
                                        <td class="text-muted-sm"><?= $index + 1 ?></td>
                                        <td>
                                            <div class="fw-700 text-green"><?= htmlspecialchars($row['course_code']) ?></div>
                                            <div class="text-muted-sm"><?= htmlspecialchars($row['course_title']) ?></div>
                                            <div class="text-muted-sm"><?= htmlspecialchars($row['session_name']) ?> &mdash; <?= htmlspecialchars($row['semester_name']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-700"><?= htmlspecialchars($row['matric_number']) ?></div>
                                            <div><?= htmlspecialchars($row['student_name']) ?></div>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-600"><?= $row['ca_score'] !== null ? number_format((float) $row['ca_score'], 1) : '--' ?></span> /
                                            <span class="fw-600"><?= $row['exam_score'] !== null ? number_format((float) $row['exam_score'], 1) : '--' ?></span> =
                                            <span class="fw-700 text-danger"><?= $row['total_score'] !== null ? number_format((float) $row['total_score'], 1) : '--' ?></span>
                                            <?php if ($row['grade']): ?>
                                                <div class="mt-1">
                                                    <span class="grade-badge grade-<?= strtolower($row['grade']) ?>">
                                                        <?= htmlspecialchars($row['grade']) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="flag-reason-box">
                                                <i class="fa-solid fa-flag text-danger me-1"></i>
                                                <strong><?= htmlspecialchars($row['advisor_name'] ?? 'Course Advisor') ?>:</strong>
                                                <p class="mb-0 mt-1"><?= htmlspecialchars($row['flag_reason'] ?? 'No reason provided') ?></p>
                                            </div>
                                        </td>
                                        <td class="text-muted-sm">
                                            <?= $row['flag_time'] ? date('d M Y, g:ia', strtotime($row['flag_time'])) : '--' ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= APP_URL ?>/pages/lecturer/enter-scores.php?assignment_id=<?= (int) $row['assignment_id'] ?>" 
                                               class="btn-portal-danger btn-portal-sm">
                                               <i class="fa-solid fa-pen-to-square"></i> Correct Score
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="table-empty-state">
                            <i class="fa-solid fa-check-circle text-green" style="font-size:36px;"></i>
                            <p class="mt-2">No flagged results! All your submitted scores are currently processed or approved.</p>
                        </div>
                    <?php endif; ?>
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