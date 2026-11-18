<?php
// ============================================================
// CSCResultPortal - Student Course Registration
// File: pages/student/register-courses.php
// Purpose: Student selects courses for the current semester.
//          Each registration starts as 'pending' and must be
//          approved by the Course Advisor before the lecturer
//          can enter scores for that student.
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- Access control (same as your other student pages) ----------
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'student'
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

// Maximum credit units a student may register per semester.
// Change this number to match your department's rule.
$maxUnits = 24;

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------- Current semester ----------
// NOTE: topbar.php overwrites $currentSemester with a string,
// so we use $term here to avoid a clash.
$term = null;
try {
    $term = dbFetchOne($pdo,
        "SELECT s.id AS semester_id, s.semester_name, ac.session_name
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE s.is_current = 1 AND ac.is_current = 1
         LIMIT 1"
    );
} catch (PDOException $e) {
    $term = null;
}

// ---------- Student level ----------
$studentLevel = null;
try {
    $prof = dbFetchOne($pdo,
        "SELECT level FROM student_profiles WHERE user_id = ?",
        [$studentId]
    );
    $studentLevel = is_array($prof) ? $prof['level'] : null;
} catch (PDOException $e) {
    $studentLevel = null;
}

// ---------- Loader: courses offered + this student's registration state ----------
$loadCourses = function () use ($pdo, $studentId, $term, $studentLevel): array {
    if (!is_array($term) || $studentLevel === null) {
        return [];
    }
    try {
        return dbFetchAll($pdo,
            "SELECT c.id, c.course_code, c.course_title, c.credit_units, c.is_elective,
                    scr.id     AS reg_id,
                    scr.status AS reg_status,
                    scr.remarks
             FROM courses c
             LEFT JOIN student_course_registrations scr
                    ON scr.course_id   = c.id
                   AND scr.student_id  = ?
                   AND scr.semester_id = ?
             WHERE c.is_active = 1
               AND c.level     = ?
               AND c.semester  = ?
             ORDER BY c.is_elective ASC, c.course_code ASC",
            [$studentId, (int) $term['semester_id'], $studentLevel, $term['semester_name']]
        );
    } catch (PDOException $e) {
        return [];
    }
};

$courses = $loadCourses();

// ---------- Handle submission ----------
$formMessage = '';
$formSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_registration'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $formMessage = 'Security check failed. Please refresh the page and try again.';
    } elseif (!is_array($term) || $studentLevel === null) {
        $formMessage = 'Registration is not available right now.';
    } else {
        $selected = isset($_POST['course_ids']) && is_array($_POST['course_ids'])
            ? array_values(array_unique(array_map('intval', $_POST['course_ids'])))
            : [];

        $byId = [];
        foreach ($courses as $c) {
            $byId[(int) $c['id']] = $c;
        }

        // Units already pending/approved
        $currentUnits = 0;
        foreach ($courses as $c) {
            if (in_array($c['reg_status'], ['pending', 'approved'], true)) {
                $currentUnits += (int) $c['credit_units'];
            }
        }

        // Work out which selected courses are really new
        $toSubmit = [];
        $newUnits = 0;
        foreach ($selected as $cid) {
            if (!isset($byId[$cid])) {
                continue; // not offered to this level/semester
            }
            $c = $byId[$cid];
            if (in_array($c['reg_status'], ['pending', 'approved'], true)) {
                continue; // already registered
            }
            $toSubmit[] = $c;
            $newUnits  += (int) $c['credit_units'];
        }

        if (empty($toSubmit)) {
            $formMessage = 'Please select at least one course that you have not already registered.';
        } elseif (($currentUnits + $newUnits) > $maxUnits) {
            $formMessage = "You cannot exceed {$maxUnits} credit units. "
                         . "You already have {$currentUnits} and are adding {$newUnits}.";
        } else {
            try {
                $pdo->beginTransaction();
                $semId = (int) $term['semester_id'];

                foreach ($toSubmit as $c) {
                    if (!empty($c['reg_id'])) {
                        // Previously rejected -> resubmit as pending
                        dbExecute($pdo,
                            "UPDATE student_course_registrations
                             SET status = 'pending', remarks = NULL,
                                 reviewed_by = NULL, reviewed_at = NULL,
                                 registration_date = NOW()
                             WHERE id = ? AND student_id = ?",
                            [(int) $c['reg_id'], $studentId]
                        );
                    } else {
                        dbInsert($pdo,
                            "INSERT INTO student_course_registrations
                                (student_id, course_id, semester_id, is_carryover, status)
                             VALUES (?, ?, ?, 0, 'pending')",
                            [$studentId, (int) $c['id'], $semId]
                        );
                    }
                }

                // In-app notification to every active advisor
                                $advisors = dbFetchAll($pdo,
                    "SELECT id FROM users
                     WHERE role = 'advisor' AND is_active = 1
                       AND (advisor_level = ? OR advisor_level IS NULL)",
                    [$studentLevel]
                );
                $count   = count($toSubmit);
                $who     = $_SESSION['full_name'] ?? 'A student';
                foreach ($advisors as $adv) {
                    dbInsert($pdo,
                        "INSERT INTO notifications
                            (recipient_id, sender_id, title, message, type, related_type)
                         VALUES (?, ?, ?, ?, 'general', 'course_registration')",
                        [
                            (int) $adv['id'],
                            $studentId,
                            'New course registration',
                            "{$who} submitted {$count} course(s) for approval.",
                        ]
                    );
                }

                writeAuditLog($pdo, $studentId, 'course_registration_submitted',
                    'student_course_registrations', null, null,
                    ['courses_count' => $count, 'semester_id' => $semId]);

                $pdo->commit();

                $formSuccess = true;
                $formMessage = "{$count} course(s) submitted. Your Course Advisor will review them.";
                $courses     = $loadCourses(); // refresh statuses
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                logDatabaseError('Course registration error: ' . $e->getMessage());
                $formMessage = 'A database error occurred. Please try again.';
            }
        }
    }
}

// ---------- Summary numbers ----------
$unitsApproved = 0;
$unitsPending  = 0;
foreach ($courses as $c) {
    if ($c['reg_status'] === 'approved') {
        $unitsApproved += (int) $c['credit_units'];
    } elseif ($c['reg_status'] === 'pending') {
        $unitsPending += (int) $c['credit_units'];
    }
}
$unitsUsed = $unitsApproved + $unitsPending;

$pageTitle      = 'Register Courses';
$pageBreadcrumb = 'Student / Register Courses';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Courses &mdash; CSCResultPortal</title>
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
                    <i class="fa-solid fa-book-medical me-2 text-green"></i>Register Courses
                </h1>
                <p class="page-header-subtitle">
                    Select your courses for the semester. Your Course Advisor must approve them
                    before your scores can be uploaded.
                </p>
                <hr class="page-header-divider">
            </div>

            <?php if (!empty($formMessage)): ?>
                <div class="portal-alert <?= $formSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $formSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($formMessage) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!is_array($term)): ?>
                <div class="portal-alert warning mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>No active semester is currently set. Please contact the HOD office.</div>
                </div>
            <?php elseif ($studentLevel === null): ?>
                <div class="portal-alert warning mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>Your academic profile (level) is not set up yet. Please contact your Course Advisor.</div>
                </div>
            <?php else: ?>
                <div class="portal-alert info mb-4">
                    <i class="fa-solid fa-calendar-alt"></i>
                    <div>
                        Registering for:
                        <strong>
                            <?= htmlspecialchars($term['session_name']) ?> &mdash;
                            <?= htmlspecialchars($term['semester_name']) ?>
                        </strong>
                        (<?= htmlspecialchars($studentLevel) ?> Level)
                        &bull; Units used: <strong><?= $unitsUsed ?></strong> / <?= $maxUnits ?>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="content-card-header">
                        <div class="content-card-title">
                            <i class="fa-solid fa-list-check"></i> Courses Offered
                        </div>
                        <span class="status-badge approved"><?= $unitsApproved ?> units approved</span>
                    </div>
                    <div class="content-card-body">
                        <?php if (empty($courses)): ?>
                            <div class="table-empty-state">
                                <i class="fa-solid fa-book"></i>
                                <p>No courses have been set up for your level this semester.</p>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="" id="regForm">
                                <input type="hidden" name="csrf_token"
                                       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                                <div class="table-responsive">
                                    <table class="portal-table">
                                        <thead>
                                            <tr>
                                                <th style="width:40px;"></th>
                                                <th>Code</th>
                                                <th>Title</th>
                                                <th class="text-center">Units</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($courses as $c):
                                            $st       = $c['reg_status'];
                                            $locked   = in_array($st, ['pending', 'approved'], true);
                                        ?>
                                            <tr>
                                                <td>
                                                    <input class="form-check-input reg-check" type="checkbox"
                                                           name="course_ids[]"
                                                           value="<?= (int) $c['id'] ?>"
                                                           data-units="<?= (int) $c['credit_units'] ?>"
                                                           id="c<?= (int) $c['id'] ?>"
                                                           <?= $locked ? 'checked disabled' : '' ?>>
                                                </td>
                                                <td><strong><?= htmlspecialchars($c['course_code']) ?></strong></td>
                                                <td>
                                                    <?= htmlspecialchars($c['course_title']) ?>
                                                    <?php if ((int) $c['is_elective'] === 1): ?>
                                                        <span class="text-muted-sm">(Elective)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?= (int) $c['credit_units'] ?></td>
                                                <td>
                                                    <?php if ($st === null): ?>
                                                        <span class="text-muted-sm">Not registered</span>
                                                    <?php else: ?>
                                                        <span class="status-badge <?= htmlspecialchars($st) ?>">
                                                            <?= ucfirst(htmlspecialchars($st)) ?>
                                                        </span>
                                                        <?php if ($st === 'rejected' && !empty($c['remarks'])): ?>
                                                            <div class="text-muted-sm mt-1">
                                                                Advisor: <?= htmlspecialchars($c['remarks']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex align-items-center gap-2 mt-3 mb-3 flex-wrap">
                                    <button type="button" class="btn-portal-outline btn-portal-sm" id="regSelectAll">
                                        <i class="fa-solid fa-check-double"></i> Select All
                                    </button>
                                    <button type="button" class="btn-portal-outline btn-portal-sm" id="regClearAll">
                                        <i class="fa-solid fa-xmark"></i> Clear
                                    </button>
                                    <span class="text-muted-sm" id="regSummary">0 new course(s) &bull; 0 units</span>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" name="submit_registration"
                                            class="btn-portal-primary" id="regSubmitBtn">
                                        <i class="fa-solid fa-paper-plane"></i> Submit for Advisor Approval
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
(function () {
    const MAX_UNITS = <?= (int) $maxUnits ?>;
    const USED      = <?= (int) $unitsUsed ?>;
    const boxes     = document.querySelectorAll('.reg-check:not(:disabled)');
    const summary   = document.getElementById('regSummary');
    const form      = document.getElementById('regForm');
    if (!form) return;

    function update() {
        let n = 0, u = 0;
        boxes.forEach(b => { if (b.checked) { n++; u += parseInt(b.dataset.units, 10); } });
        const over = (USED + u) > MAX_UNITS;
        summary.innerHTML = n + ' new course(s) &bull; ' + u + ' units' +
            (over ? ' <strong style="color:#c0392b;">(exceeds ' + MAX_UNITS + ' unit limit)</strong>' : '');
    }

    boxes.forEach(b => b.addEventListener('change', update));
    document.getElementById('regSelectAll').addEventListener('click', () => {
        boxes.forEach(b => b.checked = true); update();
    });
    document.getElementById('regClearAll').addEventListener('click', () => {
        boxes.forEach(b => b.checked = false); update();
    });

    form.addEventListener('submit', (e) => {
        let n = 0;
        boxes.forEach(b => { if (b.checked) n++; });
        if (n === 0) {
            e.preventDefault();
            alert('Please select at least one course to register.');
        }
    });
    update();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/student.js"></script>
</body>
</html>
