<?php
// ============================================================
// CSCResultPortal - Student Carryover Registration (FIXED)
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../mail/mailer.php';

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

// ============================================================
// FETCH CURRENT SEMESTER FIRST (with safety check)
// ============================================================
$currentSemester = null;
try {
    $carryovers = [];
try {
    $whereParts = [];
    if ($activeTab !== 'all') {
        $whereParts[] = "cr.status = '{$activeTab}'";   // $activeTab is whitelisted above
    }
    if ($advLevel) {
        $whereParts[] = "sp.level = ?";
    }
    $whereClause = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    $carryovers  = dbFetchAll($pdo,
        "SELECT cr.id, cr.status, cr.reason, cr.advisor_note, cr.created_at, cr.reviewed_at,
                u.full_name AS student_name, u.matric_number,
                c.course_code, c.course_title, c.credit_units,
                s.semester_name, ac.session_name,
                rv.full_name AS reviewed_by_name
         FROM carryover_registrations cr
         JOIN users u ON cr.student_id = u.id
         JOIN student_profiles sp ON sp.user_id = u.id
         JOIN courses c ON cr.course_id = c.id
         JOIN semesters s ON cr.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         LEFT JOIN users rv ON cr.reviewed_by = rv.id
         {$whereClause}
         ORDER BY cr.created_at DESC",
        $levelParams
    );
} catch (PDOException $e) {
    $carryovers = [];
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$formMessage = '';
$formSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_carryover'])) {

    $selectedCourses = isset($_POST['course_ids']) ? array_map('intval', $_POST['course_ids']) : [];
    $reason          = trim($_POST['reason'] ?? '');
    $semesterId      = isset($_POST['semester_id']) ? (int) $_POST['semester_id'] : 0;

    if (empty($selectedCourses)) {
        $formMessage = 'Please select at least one failed course.';
    } elseif ($semesterId === 0) {
        $formMessage = 'Could not determine the current semester.';
    } else {
        try {
            $pdo->beginTransaction();
            $successCount = 0;

            foreach ($selectedCourses as $courseId) {
                $failedResult = dbFetchOne($pdo,
                    "SELECT id FROM results
                     WHERE student_id = ? AND course_id = ?
                     AND grade = 'F' AND status = 'final_approved'
                     ORDER BY id DESC LIMIT 1",
                    [$studentId, $courseId]
                );

                if (!is_array($failedResult)) continue;

                $existing = dbFetchOne($pdo,
                    "SELECT id FROM carryover_registrations
                     WHERE student_id = ? AND course_id = ? AND semester_id = ?",
                    [$studentId, $courseId, $semesterId]
                );

                if (is_array($existing)) continue;

                dbInsert($pdo,
                    "INSERT INTO carryover_registrations
                        (student_id, course_id, semester_id, original_result_id, reason, status)
                     VALUES (?, ?, ?, ?, ?, 'pending')",
                    [$studentId, $courseId, $semesterId, $failedResult['id'], !empty($reason) ? $reason : null]
                );

                $successCount++;
            }

            $pdo->commit();

            if ($successCount > 0) {
                // Notify advisor
                $advisor = dbFetchOne($pdo,
                    "SELECT id, full_name, email FROM users
                     WHERE role = 'advisor' AND is_active = 1 LIMIT 1"
                );

                $student = dbFetchOne($pdo,
                    "SELECT full_name, matric_number FROM users WHERE id = ?",
                    [$studentId]
                );

                if (is_array($advisor) && is_array($student)) {
                    $firstCourse = dbFetchOne($pdo,
                        "SELECT course_code, course_title FROM courses WHERE id = ?",
                        [$selectedCourses[0]]
                    );
                    if (is_array($firstCourse)) {
                        sendCarryoverSubmissionEmail(
                            (int) $advisor['id'],
                            $advisor['email'],
                            $advisor['full_name'],
                            $student['full_name'],
                            $student['matric_number'] ?? '',
                            $firstCourse['course_code'],
                            count($selectedCourses) > 1
                                ? $firstCourse['course_title'] . ' (and ' . (count($selectedCourses) - 1) . ' more)'
                                : $firstCourse['course_title'],
                            null,
                            $studentId
                        );
                    }
                }

                writeAuditLog($pdo, $studentId, 'carryover_registration_submitted',
                    'carryover_registrations', null, null,
                    ['courses_count' => $successCount]
                );

                $formSuccess = true;
                $formMessage = $successCount . ' carryover course(s) registered successfully.';
            } else {
                $pdo->rollBack();
                $formMessage = 'No new carryover registrations were processed.';
            }

        } catch (PDOException $e) {
            $pdo->rollBack();
            $formMessage = 'A system error occurred. Please try again later.';
            logDatabaseError('Carryover submission error: ' . $e->getMessage());
        }
    }
}

// ============================================================
// FETCH FAILED COURSES
// ============================================================
$failedCourses = [];
try {
    $currentSemId = is_array($currentSemester) ? (int) ($currentSemester['semester_id'] ?? 0) : 0;
    
    $failedCourses = dbFetchAll($pdo,
        "SELECT r.id AS result_id, r.total_score, r.grade, r.grade_point,
                c.id AS course_id, c.course_code, c.course_title, c.credit_units,
                s.semester_name AS failed_semester,
                ac.session_name AS failed_session
         FROM results r
         JOIN courses c ON r.course_id = c.id
         JOIN semesters s ON r.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE r.student_id = ?
         AND r.grade = 'F'
         AND r.status = 'final_approved'
         AND c.id NOT IN (
             SELECT cr.course_id FROM carryover_registrations cr
             WHERE cr.student_id = ?
             AND cr.semester_id = ?
             AND cr.status IN ('pending', 'approved')
         )
         ORDER BY ac.id DESC, c.course_code ASC",
        [$studentId, $studentId, $currentSemId]
    );
} catch (PDOException $e) {
    $failedCourses = [];
}

// ============================================================
// FETCH EXISTING CARRYOVERS
// ============================================================
$existingCarryovers = [];
try {
    $existingCarryovers = dbFetchAll($pdo,
        "SELECT cr.id, cr.status, cr.reason, cr.advisor_note, cr.created_at,
                c.course_code, c.course_title, c.credit_units,
                s.semester_name, ac.session_name
         FROM carryover_registrations cr
         JOIN courses c ON cr.course_id = c.id
         JOIN semesters s ON cr.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE cr.student_id = ?
         ORDER BY cr.created_at DESC",
        [$studentId]
    );
} catch (PDOException $e) {
    $existingCarryovers = [];
}

$pageTitle      = 'Carryover Registration';
$pageBreadcrumb = 'Student / Carryover Registration';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carryover Registration &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/student.css">
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
                    <i class="fa-solid fa-rotate me-2 text-green"></i>Carryover Registration
                </h1>
                <p class="page-header-subtitle">
                    Register to repeat failed courses. The Course Advisor will review your submission.
                </p>
                <hr class="page-header-divider">
            </div>

            <?php if (!empty($formMessage)): ?>
                <div class="portal-alert <?= $formSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $formSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($formMessage) ?></div>
                </div>
            <?php endif; ?>

            <?php if (is_array($currentSemester)): ?>
                <div class="portal-alert info mb-4">
                    <i class="fa-solid fa-calendar-alt"></i>
                    <div>
                        Carryover registration is open for:
                        <strong>
                            <?= htmlspecialchars($currentSemester['session_name'] ?? 'N/A') ?>
                            &mdash;
                            <?= htmlspecialchars($currentSemester['semester_name'] ?? 'N/A') ?>
                        </strong>
                    </div>
                </div>
            <?php else: ?>
                <div class="portal-alert warning mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        No active semester is currently set. Please contact the HOD office.
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="content-card mb-4">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-circle-xmark"></i>
                                Failed Courses Available for Carryover
                            </div>
                            <?php if (count($failedCourses) > 0): ?>
                                <span class="status-badge flagged"><?= count($failedCourses) ?> course(s)</span>
                            <?php endif; ?>
                        </div>
                        <div class="content-card-body">
                            <?php if (count($failedCourses) === 0): ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-check-circle" style="color:#006400;"></i>
                                    <p>No failed courses available for carryover registration.</p>
                                </div>
                            <?php elseif (!is_array($currentSemester)): ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    <p>Cannot register carryover — no active semester set.</p>
                                </div>
                            <?php else: ?>
                                <form method="POST" action="" id="carryoverForm">
                                    <input type="hidden" name="semester_id" value="<?= (int) ($currentSemester['semester_id'] ?? 0) ?>">

                                    <div class="carryover-course-list">
                                        <?php foreach ($failedCourses as $course): ?>
                                            <div class="carryover-course-item">
                                                <div class="form-check" style="margin:0;">
                                                    <input class="form-check-input carryover-check" type="checkbox"
                                                           name="course_ids[]" value="<?= (int) ($course['course_id'] ?? 0) ?>"
                                                           id="course_<?= (int) ($course['course_id'] ?? 0) ?>">
                                                </div>
                                                <label class="carryover-course-label" for="course_<?= (int) ($course['course_id'] ?? 0) ?>">
                                                    <div class="carryover-course-code">
                                                        <?= htmlspecialchars($course['course_code'] ?? '') ?>
                                                        <span class="carryover-units"><?= (int) ($course['credit_units'] ?? 0) ?> units</span>
                                                    </div>
                                                    <div class="carryover-course-title">
                                                        <?= htmlspecialchars($course['course_title'] ?? '') ?>
                                                    </div>
                                                    <div class="carryover-course-meta">
                                                        <span class="grade-badge grade-f me-2">F</span>
                                                        Score: <?= number_format((float) ($course['total_score'] ?? 0), 1) ?>
                                                        &bull;
                                                        <?= htmlspecialchars($course['failed_session'] ?? '') ?>
                                                        &mdash;
                                                        <?= htmlspecialchars($course['failed_semester'] ?? '') ?>
                                                    </div>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="d-flex align-items-center gap-2 mt-3 mb-3">
                                        <button type="button" class="btn-portal-outline btn-portal-sm" id="selectAllBtn">
                                            <i class="fa-solid fa-check-double"></i> Select All
                                        </button>
                                        <button type="button" class="btn-portal-outline btn-portal-sm" id="clearAllBtn">
                                            <i class="fa-solid fa-xmark"></i> Clear All
                                        </button>
                                        <span class="text-muted-sm" id="selectedCount">0 selected</span>
                                    </div>

                                    <div class="mb-3">
                                        <label class="portal-form-label" for="reason">Reason / Note (Optional)</label>
                                        <textarea class="portal-form-control" id="reason" name="reason"
                                                  rows="3" maxlength="500"
                                                  placeholder="Briefly explain your situation (optional)..."></textarea>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" name="submit_carryover" class="btn-portal-primary" id="submitCarryoverBtn">
                                            <i class="fa-solid fa-paper-plane me-2"></i>
                                            Submit Carryover Registration
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                Submission History
                            </div>
                            <?php if (count($existingCarryovers) > 0): ?>
                                <span class="status-badge submitted"><?= count($existingCarryovers) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="content-card-body" style="padding:0;">
                            <?php if (count($existingCarryovers) === 0): ?>
                                <div class="table-empty-state" style="padding:30px;">
                                    <i class="fa-solid fa-inbox"></i>
                                    <p>No carryover submissions yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="carryover-history-list">
                                    <?php foreach ($existingCarryovers as $co): ?>
                                        <div class="carryover-history-item">
                                            <div class="carryover-history-header">
                                                <span class="carryover-history-code">
                                                    <?= htmlspecialchars($co['course_code'] ?? '') ?>
                                                </span>
                                                <span class="status-badge <?= htmlspecialchars($co['status'] ?? 'pending') ?>">
                                                    <?= ucfirst(htmlspecialchars($co['status'] ?? 'pending')) ?>
                                                </span>
                                            </div>
                                            <div class="carryover-history-title">
                                                <?= htmlspecialchars($co['course_title'] ?? '') ?>
                                            </div>
                                            <div class="carryover-history-meta">
                                                <i class="fa-solid fa-calendar fa-xs me-1"></i>
                                                <?= htmlspecialchars($co['session_name'] ?? '') ?>
                                                &mdash;
                                                <?= htmlspecialchars($co['semester_name'] ?? '') ?>
                                            </div>
                                            <?php if (!empty($co['advisor_note'])): ?>
                                                <div class="carryover-advisor-note">
                                                    <i class="fa-solid fa-comment fa-xs me-1"></i>
                                                    <em>Advisor: <?= htmlspecialchars($co['advisor_note']) ?></em>
                                                </div>
                                            <?php endif; ?>
                                            <div class="carryover-history-date">
                                                Submitted: <?= date('d M Y', strtotime($co['created_at'] ?? 'now')) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
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
<script src="<?= APP_URL ?>/js/student.js"></script>
</body>
</html>