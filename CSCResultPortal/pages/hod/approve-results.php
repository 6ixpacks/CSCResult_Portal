<?php
// ============================================================
// CSCResultPortal - HOD Approve Results (TRANSACTION SAFE FIX)
// File: pages/hod/approve-results.php
// Purpose: HOD reviews forwarded results and grants:
//          1. Individual course approval (hod_approved)
//          2. Final Departmental Approval (final_approved)
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../mail/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access Control
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'hod'
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
$actionMessage = '';
$actionSuccess = false;

// ============================================================
// SECTION 1: HANDLE HOD APPROVAL ACTIONS (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hod_action'])) {
    $action       = $_POST['hod_action']; // 'hod_approve' or 'final_approve'
    $assignmentId = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
    $semesterId   = isset($_POST['semester_id'])   ? (int) $_POST['semester_id']   : 0;

    $startedTransaction = false;

    try {
        // Safe Transaction Start: Only begin if no active transaction exists
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        // ----------------------------------------------------
        // ACTION A: INDIVIDUAL COURSE HOD APPROVAL
        // ----------------------------------------------------
        if ($action === 'hod_approve' && $assignmentId > 0) {
            $updated = dbExecute($pdo,
                "UPDATE results
                 SET status = 'hod_approved', hod_approved_at = NOW(), hod_approved_by = ?
                 WHERE assignment_id = ? AND status = 'forwarded'",
                [$hodId, $assignmentId]
            );

            if ($updated === 0) {
                throw new Exception('No forwarded results found for this course.');
            }

            $courseInfo = dbFetchOne($pdo,
                "SELECT c.course_code, c.course_title, s.semester_name, ac.session_name
                 FROM course_assignments ca
                 JOIN courses c ON ca.course_id = c.id
                 JOIN semesters s ON ca.semester_id = s.id
                 JOIN academic_sessions ac ON s.session_id = ac.id
                 WHERE ca.id = ?",
                [$assignmentId]
            );

            writeAuditLog($pdo, $hodId, 'approve_result', 'results', $assignmentId,
                null, ['course_code' => $courseInfo['course_code'] ?? '']
            );

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
                $startedTransaction = false;
            }

            // Send confirmation email after DB commit
            if (is_array($courseInfo)) {
                $hodUser = dbFetchOne($pdo, "SELECT full_name, email FROM users WHERE id = ?", [$hodId]);
                if (is_array($hodUser)) {
                    @sendHODApprovalConfirmationEmail(
                        $hodId,
                        $hodUser['email'],
                        $hodUser['full_name'],
                        $courseInfo['course_code'],
                        $courseInfo['course_title'],
                        $courseInfo['semester_name'],
                        $courseInfo['session_name'],
                        null
                    );
                }
            }

            $actionSuccess = true;
            $actionMessage = "Course " . htmlspecialchars($courseInfo['course_code'] ?? '') . " results approved. They are ready for final departmental release after Senate verification.";

        // ----------------------------------------------------
        // ACTION B: FINAL DEPARTMENTAL APPROVAL (SEMESTER-WIDE)
        // ----------------------------------------------------
        } elseif ($action === 'final_approve' && $semesterId > 0) {
            set_time_limit(120); // Prevent PHP timeout

            // 1. Promote all HOD-approved results in this semester to final_approved
            $updated = dbExecute($pdo,
                "UPDATE results
                 SET status = 'final_approved', final_approved_at = NOW(), final_approved_by = ?
                 WHERE semester_id = ? AND status = 'hod_approved'",
                [$hodId, $semesterId]
            );

            if ($updated === 0) {
                throw new Exception('No HOD-approved results found in this semester awaiting final approval.');
            }

            // 2. Fetch all affected students
            $studentsInSemester = dbFetchAll($pdo,
                "SELECT DISTINCT student_id FROM results
                 WHERE semester_id = ? AND status = 'final_approved'",
                [$semesterId]
            );

            // 3. Compute semester GPA & CGPA for each student
            foreach ($studentsInSemester as $stu) {
                $studentId = (int) $stu['student_id'];

                // Semester Totals
                $semRows = dbFetchAll($pdo,
                    "SELECT grade_point, credit_units, quality_point
                     FROM results
                     WHERE student_id = ? AND semester_id = ? AND status = 'final_approved'",
                    [$studentId, $semesterId]
                );

                $semUnits = 0;
                $semQP    = 0.0;
                foreach ($semRows as $r) {
                    $semUnits += (int) ($r['credit_units'] ?? 0);
                    $semQP    += (float) ($r['quality_point'] ?? 0);
                }
                $semGPA = $semUnits > 0 ? round($semQP / $semUnits, 2) : 0.00;

                // Cumulative Totals
                $allRows = dbFetchAll($pdo,
                    "SELECT grade_point, credit_units
                     FROM results
                     WHERE student_id = ? AND status = 'final_approved'",
                    [$studentId]
                );

                $cumUnits = 0;
                $cumQP    = 0.0;
                foreach ($allRows as $r) {
                    $cu = (int) ($r['credit_units'] ?? 0);
                    $gp = (float) ($r['grade_point'] ?? 0);
                    $cumUnits += $cu;
                    $cumQP    += ($gp * $cu);
                }
                $cgpa = $cumUnits > 0 ? round($cumQP / $cumUnits, 2) : 0.00;

                // Upsert semester_gpa
                $existing = dbFetchOne($pdo,
                    "SELECT id FROM semester_gpa WHERE student_id = ? AND semester_id = ?",
                    [$studentId, $semesterId]
                );

                if (is_array($existing)) {
                    dbExecute($pdo,
                        "UPDATE semester_gpa
                         SET gpa = ?, cgpa = ?, total_units = ?, total_quality_points = ?
                         WHERE student_id = ? AND semester_id = ?",
                        [$semGPA, $cgpa, $semUnits, $semQP, $studentId, $semesterId]
                    );
                } else {
                    dbInsert($pdo,
                        "INSERT INTO semester_gpa
                            (student_id, semester_id, gpa, cgpa, total_units, total_quality_points)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$studentId, $semesterId, $semGPA, $cgpa, $semUnits, $semQP]
                    );
                }

                // Update student profile
                dbExecute($pdo,
                    "UPDATE student_profiles
                     SET cgpa = ?, total_units = ?, degree_class = ?
                     WHERE user_id = ?",
                    [$cgpa, $cumUnits, getDegreeClass($cgpa), $studentId]
                );
            }

            // 4. In-App Notifications for Students
            $semInfo = dbFetchOne($pdo,
                "SELECT s.semester_name, ac.session_name
                 FROM semesters s JOIN academic_sessions ac ON s.session_id = ac.id
                 WHERE s.id = ?",
                [$semesterId]
            );

            $semesterName = $semInfo['semester_name'] ?? 'Semester';
            $sessionName  = $semInfo['session_name'] ?? '';

            foreach ($studentsInSemester as $stu) {
                dbInsert($pdo,
                    "INSERT INTO notifications
                        (recipient_id, sender_id, title, message, type, related_id, related_type, is_read)
                     VALUES (?, ?, ?, ?, 'final_approved', ?, 'semester', 0)",
                    [
                        (int) $stu['student_id'],
                        $hodId,
                        'Final Results Published',
                        "Your results for {$semesterName}, {$sessionName} have been published. Log in to view your grades.",
                        $semesterId
                    ]
                );
            }

            writeAuditLog($pdo, $hodId, 'final_approve', 'results', $semesterId, null, [
                'updated_results' => $updated,
                'students_count'  => count($studentsInSemester)
            ]);

            // Commit DB Work BEFORE Emailing
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
                $startedTransaction = false;
            }

            // 5. Send Email Notifications (Safe / Non-blocking)
            try {
                if (function_exists('sendBulkFinalApprovalEmails')) {
                    $recipients = dbFetchAll($pdo,
                        "SELECT id, full_name, email, role FROM users 
                         WHERE is_active = 1 AND role IN ('student', 'lecturer', 'advisor')"
                    );
                    @sendBulkFinalApprovalEmails($recipients, $semesterName, $sessionName, $hodId);
                }
            } catch (Throwable $mailEx) {
                logDatabaseError('Mail notice error on final approve: ' . $mailEx->getMessage());
            }

            $actionSuccess = true;
            $actionMessage = "Final departmental approval granted for {$semesterName}, {$sessionName}! "
                           . "{$updated} result record(s) published for " . count($studentsInSemester) . " student(s).";
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $actionSuccess = false;
        $actionMessage = 'Approval failed: ' . htmlspecialchars($e->getMessage());
        logDatabaseError('HOD Approval error: ' . $e->getMessage());
    }
}

// ============================================================
// SECTION 2: FETCH DATA FOR UI
// ============================================================
$forwardedAssignments = [];
try {
    $forwardedAssignments = dbFetchAll($pdo,
        "SELECT DISTINCT ca.id AS assignment_id,
                c.course_code, c.course_title, c.level,
                u.full_name AS lecturer_name,
                s.id AS semester_id, s.semester_name,
                ac.session_name,
                (SELECT COUNT(*) FROM results r2
                 WHERE r2.assignment_id = ca.id AND r2.status = 'forwarded') AS student_count,
                (SELECT COUNT(*) FROM results r3
                 WHERE r3.assignment_id = ca.id AND r3.grade = 'F'
                 AND r3.status = 'forwarded') AS fail_count
         FROM results r
         JOIN course_assignments ca ON r.assignment_id = ca.id
         JOIN courses c ON ca.course_id = c.id
         JOIN users u ON ca.lecturer_id = u.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE r.status = 'forwarded'
         ORDER BY s.id DESC, c.level ASC, c.course_code ASC"
    );
} catch (PDOException $e) {
    $forwardedAssignments = [];
}

$hodApprovedBySemester = [];
try {
    $hodApprovedBySemester = dbFetchAll($pdo,
        "SELECT DISTINCT s.id AS semester_id, s.semester_name, ac.session_name,
                COUNT(DISTINCT ca.id) AS course_count,
                COUNT(DISTINCT r.student_id) AS student_count
         FROM results r
         JOIN course_assignments ca ON r.assignment_id = ca.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE r.status = 'hod_approved'
         GROUP BY s.id, s.semester_name, ac.session_name
         ORDER BY s.id DESC"
    );
} catch (PDOException $e) {
    $hodApprovedBySemester = [];
}

$selectedAssignment = null;
$previewResults     = [];
$selectedId         = isset($_GET['assignment_id']) ? (int) $_GET['assignment_id'] : 0;

if ($selectedId > 0) {
    try {
        $selectedAssignment = dbFetchOne($pdo,
            "SELECT ca.*, c.course_code, c.course_title, c.credit_units, c.level,
                    u.full_name AS lecturer_name,
                    s.id AS semester_id, s.semester_name, ac.session_name
             FROM course_assignments ca
             JOIN courses c ON ca.course_id = c.id
             JOIN users u ON ca.lecturer_id = u.id
             JOIN semesters s ON ca.semester_id = s.id
             JOIN academic_sessions ac ON s.session_id = ac.id
             WHERE ca.id = ?",
            [$selectedId]
        );

        if (is_array($selectedAssignment)) {
            $previewResults = dbFetchAll($pdo,
                "SELECT r.ca_score, r.lab_score, r.exam_score, r.total_score, r.grade, r.grade_point,
                        r.credit_units, r.quality_point,
                        u.full_name AS student_name, u.matric_number,
                        sp.level AS student_level
                 FROM results r
                 JOIN users u ON r.student_id = u.id
                 LEFT JOIN student_profiles sp ON u.id = sp.user_id
                 WHERE r.assignment_id = ? AND r.status IN ('forwarded', 'hod_approved')
                 ORDER BY u.matric_number ASC",
                [$selectedId]
            );
        }
    } catch (PDOException $e) {
        $selectedAssignment = null;
        $previewResults     = [];
    }
}

$pageTitle      = 'Approve Results';
$pageBreadcrumb = 'HOD / Approve Results';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Results &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/hod.css">
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
                    <i class="fa-solid fa-clipboard-check me-2 text-green"></i>
                    Approve Results
                </h1>
                <p class="page-header-subtitle">
                    Review and approve forwarded course results. Grant final departmental release after Senate verification.
                </p>
                <hr class="page-header-divider">
            </div>

            <!-- Feedback Alert -->
            <?php if (!empty($actionMessage)): ?>
                <div class="portal-alert <?= $actionSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $actionSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= $actionMessage ?></div>
                </div>
            <?php endif; ?>

            <!-- Senate Note -->
            <div class="portal-alert info mb-4">
                <i class="fa-solid fa-info-circle"></i>
                <div>
                    <strong>Workflow Note:</strong>
                    After individual HOD approval, results proceed to Senate review <strong>outside this system</strong>.
                    Once verified, return here to grant <strong>Final Departmental Approval</strong> to publish results to students.
                </div>
            </div>

            <!-- ============================================================
                 FINAL APPROVAL SECTION (Semester-Wide)
                 ============================================================ -->
            <?php if (!empty($hodApprovedBySemester)): ?>
                <div class="content-card mb-4">
                    <div class="content-card-header">
                        <div class="content-card-title" style="color:#006400;">
                            <i class="fa-solid fa-graduation-cap"></i>
                            Grant Final Departmental Approval
                        </div>
                    </div>
                    <div class="content-card-body">
                        <p class="text-muted-sm mb-3">
                            The following semester(s) have all course results HOD-approved and are ready for final publication.
                        </p>
                        <?php foreach ($hodApprovedBySemester as $sem): ?>
                            <div class="final-approve-block">
                                <div class="final-approve-info">
                                    <div class="final-approve-title">
                                        <?= htmlspecialchars($sem['session_name'] ?? '') ?> &mdash; <?= htmlspecialchars($sem['semester_name'] ?? '') ?>
                                    </div>
                                    <div class="final-approve-meta">
                                        <?= (int) $sem['course_count'] ?> course(s) &bull; <?= (int) $sem['student_count'] ?> student(s)
                                    </div>
                                </div>
                                <form method="POST" action="approve-results.php" class="d-inline" onsubmit="this.querySelector('button').disabled=true;">
                                    <input type="hidden" name="hod_action"  value="final_approve">
                                    <input type="hidden" name="semester_id" value="<?= (int) $sem['semester_id'] ?>">
                                    <button type="submit" class="btn-portal-gold"
                                            onclick="return confirm('Grant FINAL departmental approval for <?= htmlspecialchars(addslashes($sem['semester_name'] ?? '')) ?>, <?= htmlspecialchars(addslashes($sem['session_name'] ?? '')) ?>?\n\nThis will publish grades to all students and calculate CGPAs.')">
                                        <i class="fa-solid fa-graduation-cap"></i>
                                        Grant Final Approval &amp; Publish
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============================================================
                 INDIVIDUAL COURSE APPROVAL QUEUE
                 ============================================================ -->
            <div class="row g-4">

                <!-- Left: Forwarded Assignments -->
                <div class="col-lg-4">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-inbox"></i> Forwarded by Advisor
                            </div>
                            <?php if (count($forwardedAssignments) > 0): ?>
                                <span class="status-badge forwarded"><?= count($forwardedAssignments) ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="max-height:540px; overflow-y:auto;">
                            <?php if (count($forwardedAssignments) > 0): ?>
                                <div class="review-list">
                                    <?php foreach ($forwardedAssignments as $fa): ?>
                                        <?php $isActive = ($selectedId === (int) $fa['assignment_id']); ?>
                                        <a href="approve-results.php?assignment_id=<?= (int) $fa['assignment_id'] ?>"
                                           class="review-list-item <?= $isActive ? 'active' : '' ?>">
                                            <div class="review-list-code">
                                                <?= htmlspecialchars($fa['course_code']) ?>
                                                <span class="badge bg-light text-dark border ms-1" style="font-size:10px;">
                                                    <?= htmlspecialchars($fa['level']) ?>L
                                                </span>
                                            </div>
                                            <div class="review-list-title">
                                                <?= htmlspecialchars($fa['course_title']) ?>
                                            </div>
                                            <div class="review-list-meta">
                                                <span><?= htmlspecialchars($fa['lecturer_name']) ?></span>
                                                <span><?= (int) $fa['student_count'] ?> students</span>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-check-circle text-green"></i>
                                    <p>No results awaiting course approval.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Preview and Approve -->
                <div class="col-lg-8">
                    <?php if (is_array($selectedAssignment)): ?>

                        <div class="content-card mb-4">
                            <div class="content-card-body">
                                <h4 class="fw-700 text-green mb-1">
                                    <?= htmlspecialchars($selectedAssignment['course_code']) ?> &mdash; <?= htmlspecialchars($selectedAssignment['course_title']) ?>
                                </h4>
                                <p class="text-muted mb-2">
                                    Lecturer: <strong><?= htmlspecialchars($selectedAssignment['lecturer_name']) ?></strong> &bull;
                                    <?= htmlspecialchars($selectedAssignment['session_name']) ?> &mdash; <?= htmlspecialchars($selectedAssignment['semester_name']) ?>
                                </p>
                            </div>
                        </div>

                        <div class="content-card mb-4">
                            <div class="content-card-header">
                                <div class="content-card-title">
                                    <i class="fa-solid fa-table"></i> Score Sheet Preview
                                </div>
                                <a href="<?= APP_URL ?>/pages/shared/grade-report.php?assignment_id=<?= (int) $selectedId ?>" 
                                   target="_blank" 
                                   class="btn-portal-gold btn-portal-sm">
                                    <i class="fa-solid fa-print"></i> Print for Senate
                                </a>
                            </div>
                            <div style="overflow-x:auto; max-height:320px; overflow-y:auto;">
                                <?php if (count($previewResults) > 0): ?>
                                    <table class="portal-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Matric</th>
                                                <th>Student Name</th>
                                                <th class="text-center">TEST</th>
                                                <th class="text-center">LAB</th>
                                                <th class="text-center">EXAM</th>
                                                <th class="text-center">Total</th>
                                                <th class="text-center">Grade</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($previewResults as $idx => $pr): ?>
                                                <tr>
                                                    <td class="text-muted-sm"><?= $idx + 1 ?></td>
                                                    <td class="fw-700"><?= htmlspecialchars($pr['matric_number']) ?></td>
                                                    <td><?= htmlspecialchars($pr['student_name']) ?></td>
                                                    <td class="text-center"><?= $pr['ca_score'] !== null ? number_format((float)$pr['ca_score'], 0) : '-' ?></td>
                                                    <td class="text-center"><?= $pr['lab_score'] !== null ? number_format((float)$pr['lab_score'], 0) : '-' ?></td>
                                                    <td class="text-center"><?= $pr['exam_score'] !== null ? number_format((float)$pr['exam_score'], 0) : '-' ?></td>
                                                    <td class="text-center fw-700"><?= $pr['total_score'] !== null ? number_format((float)$pr['total_score'], 0) : '-' ?></td>
                                                    <td class="text-center">
                                                        <span class="grade-badge grade-<?= strtolower($pr['grade'] ?? 'f') ?>">
                                                            <?= htmlspecialchars($pr['grade'] ?? '') ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="table-empty-state"><p>No scores to preview.</p></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (count($previewResults) > 0): ?>
                            <div class="hod-approve-action-card">
                                <div class="hod-approve-action-left">
                                    <i class="fa-solid fa-circle-info text-green me-2"></i>
                                    Approving marks this course result as <strong>HOD Approved</strong>.
                                </div>
                                <form method="POST" action="approve-results.php?assignment_id=<?= $selectedId ?>">
                                    <input type="hidden" name="hod_action"    value="hod_approve">
                                    <input type="hidden" name="assignment_id" value="<?= $selectedId ?>">
                                    <button type="submit" class="btn-portal-primary" onclick="return confirm('Approve results for this course?')">
                                        <i class="fa-solid fa-circle-check me-1"></i> Approve This Course Result
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="content-card">
                            <div class="table-empty-state" style="padding:60px 40px;">
                                <i class="fa-solid fa-clipboard-check" style="font-size:48px; color:#e0e0e0; margin-bottom:16px;"></i>
                                <p style="font-size:15px; color:#999;">Select a course from the list on the left to preview and approve.</p>
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
<script src="<?= APP_URL ?>/js/hod.js"></script>
</body>
</html>