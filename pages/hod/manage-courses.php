<?php
// ============================================================
// CSCResultPortal - HOD Manage Courses & Assignments
// File: pages/hod/manage-courses.php
// Purpose:
//  1) Add / edit courses
//  2) Assign courses to lecturers for a semester
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
$formMessage = '';
$formSuccess = false;

// ============================================================
// HANDLE ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? '';

    // ----------------------------
    // ADD COURSE
    // ----------------------------
    if ($action === 'add_course') {
        $code      = strtoupper(trim($_POST['course_code'] ?? ''));
        $title     = trim($_POST['course_title'] ?? '');
        $units     = (int) ($_POST['credit_units'] ?? 3);
        $level     = $_POST['level'] ?? '100';
        $semester  = $_POST['semester'] ?? 'First Semester';
        $elective  = isset($_POST['is_elective']) ? 1 : 0;

        if ($code === '' || $title === '') {
            $formMessage = 'Course code and title are required.';
        } elseif (!in_array($level, ['100','200','300','400','500'], true)) {
            $formMessage = 'Invalid level selected.';
        } elseif (!in_array($semester, ['First Semester','Second Semester'], true)) {
            $formMessage = 'Invalid semester selected.';
        } elseif ($units < 1 || $units > 6) {
            $formMessage = 'Credit units must be between 1 and 6.';
        } else {
            try {
                $exists = dbFetchOne($pdo, "SELECT id FROM courses WHERE course_code = ?", [$code]);
                if (is_array($exists)) {
                    $formMessage = "Course {$code} already exists.";
                } else {
                    $newId = dbInsert($pdo,
                        "INSERT INTO courses
                            (course_code, course_title, credit_units, level, semester, is_elective, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, 1)",
                        [$code, $title, $units, $level, $semester, $elective]
                    );

                    writeAuditLog($pdo, $hodId, 'create_course', 'courses', $newId, null, [
                        'course_code' => $code,
                        'title' => $title
                    ]);

                    $formSuccess = true;
                    $formMessage = "Course {$code} added successfully.";
                }
            } catch (PDOException $e) {
                $formMessage = 'Database error while adding course.';
                logDatabaseError('Add course error: ' . $e->getMessage());
            }
        }
    }

    // ----------------------------
    // TOGGLE COURSE ACTIVE
    // ----------------------------
    if ($action === 'toggle_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $newStatus = (int) ($_POST['new_status'] ?? 1);
        if ($courseId > 0) {
            try {
                dbExecute($pdo, "UPDATE courses SET is_active = ? WHERE id = ?", [$newStatus, $courseId]);
                writeAuditLog($pdo, $hodId, $newStatus ? 'activate_course' : 'deactivate_course', 'courses', $courseId);
                $formSuccess = true;
                $formMessage = 'Course status updated.';
            } catch (PDOException $e) {
                $formMessage = 'Could not update course status.';
            }
        }
    }

    // ----------------------------
    // ASSIGN COURSE TO LECTURER
    // ----------------------------
    if ($action === 'assign_course') {
        $courseId    = (int) ($_POST['course_id'] ?? 0);
        $lecturerId  = (int) ($_POST['lecturer_id'] ?? 0);
        $semesterId  = (int) ($_POST['semester_id'] ?? 0);
        $caMax       = (int) ($_POST['ca_max'] ?? 30);
        $labMax      = (int) ($_POST['lab_max'] ?? 0);
        $examMax     = (int) ($_POST['exam_max'] ?? 70);
        $hasLab      = $labMax > 0 ? 1 : 0;

        if ($courseId <= 0 || $lecturerId <= 0 || $semesterId <= 0) {
            $formMessage = 'Please select course, lecturer and semester.';
        } elseif (($caMax + $labMax + $examMax) !== 100) {
            $formMessage = 'TEST + LAB + EXAM must equal exactly 100.';
        } else {
            try {
                // Ensure lecturer exists and is active
                $lecturer = dbFetchOne($pdo,
                    "SELECT id, full_name FROM users WHERE id = ? AND role = 'lecturer' AND is_active = 1",
                    [$lecturerId]
                );
                if (!is_array($lecturer)) {
                    $formMessage = 'Selected lecturer is invalid or inactive.';
                } else {
                    $existing = dbFetchOne($pdo,
                        "SELECT id, lecturer_id FROM course_assignments
                         WHERE course_id = ? AND semester_id = ?",
                        [$courseId, $semesterId]
                    );

                    if (is_array($existing)) {
                        // Reassign safely (does not delete previous results)
                        dbExecute($pdo,
                            "UPDATE course_assignments
                             SET lecturer_id = ?, ca_max = ?, lab_max = ?, exam_max = ?, has_lab = ?
                             WHERE id = ?",
                            [$lecturerId, $caMax, $labMax, $examMax, $hasLab, $existing['id']]
                        );
                        $assignId = (int) $existing['id'];
                        $formMessage = 'Course reassigned to ' . $lecturer['full_name'] . ' successfully.';
                    } else {
                        $assignId = dbInsert($pdo,
                            "INSERT INTO course_assignments
                                (course_id, lecturer_id, semester_id, ca_max, lab_max, exam_max, has_lab, scores_submitted)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 0)",
                            [$courseId, $lecturerId, $semesterId, $caMax, $labMax, $examMax, $hasLab]
                        );
                        $formMessage = 'Course assigned to ' . $lecturer['full_name'] . ' successfully.';
                    }

                    writeAuditLog($pdo, $hodId, 'assign_course', 'course_assignments', $assignId, null, [
                        'course_id' => $courseId,
                        'lecturer_id' => $lecturerId,
                        'semester_id' => $semesterId
                    ]);

                    $formSuccess = true;
                }
            } catch (PDOException $e) {
                $formMessage = 'Database error while assigning course.';
                logDatabaseError('Assign course error: ' . $e->getMessage());
            }
        }
    }
}

// ============================================================
// FETCH DATA FOR UI
// ============================================================
$courses = [];
$lecturers = [];
$semesters = [];
$assignments = [];

try {
    $courses = dbFetchAll($pdo,
        "SELECT * FROM courses ORDER BY level ASC, course_code ASC"
    );

    $lecturers = dbFetchAll($pdo,
        "SELECT id, full_name, staff_id FROM users
         WHERE role = 'lecturer' AND is_active = 1
         ORDER BY full_name ASC"
    );

    $semesters = dbFetchAll($pdo,
        "SELECT s.id, s.semester_name, s.is_current, ac.session_name
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         ORDER BY ac.id DESC, s.id DESC"
    );

    $assignments = dbFetchAll($pdo,
        "SELECT ca.id, ca.ca_max, ca.lab_max, ca.exam_max, ca.has_lab, ca.scores_submitted,
                c.course_code, c.course_title, c.level,
                u.full_name AS lecturer_name, u.staff_id,
                s.semester_name, ac.session_name
         FROM course_assignments ca
         JOIN courses c ON ca.course_id = c.id
         JOIN users u ON ca.lecturer_id = u.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         ORDER BY ac.id DESC, s.id DESC, c.course_code ASC"
    );
} catch (PDOException $e) {
    $formMessage = 'Could not load course management data.';
}

$pageTitle = 'Manage Courses';
$pageBreadcrumb = 'HOD / Manage Courses';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Courses &mdash; CSCResultPortal</title>
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
                    <i class="fa-solid fa-book me-2 text-green"></i>Manage Courses &amp; Assignments
                </h1>
                <p class="page-header-subtitle">
                    Add new courses and assign them to lecturers for specific academic semesters.
                </p>
                <hr class="page-header-divider">
            </div>

            <?php if (!empty($formMessage)): ?>
                <div class="portal-alert <?= $formSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $formSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($formMessage) ?></div>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <!-- ADD COURSE -->
                <div class="col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-plus"></i> Add New Course
                            </div>
                        </div>
                        <div class="content-card-body">
                            <form method="POST">
                                <input type="hidden" name="form_action" value="add_course">
                                <div class="mb-3">
                                    <label class="portal-form-label">Course Code *</label>
                                    <input type="text" name="course_code" class="portal-form-control" required placeholder="e.g. CSC 412">
                                </div>
                                <div class="mb-3">
                                    <label class="portal-form-label">Course Title *</label>
                                    <input type="text" name="course_title" class="portal-form-control" required placeholder="e.g. Cloud Computing">
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-4">
                                        <label class="portal-form-label">Units</label>
                                        <input type="number" name="credit_units" class="portal-form-control" value="3" min="1" max="6" required>
                                    </div>
                                    <div class="col-4">
                                        <label class="portal-form-label">Level</label>
                                        <select name="level" class="portal-form-control portal-select" required>
                                            <option value="100">100</option>
                                            <option value="200">200</option>
                                            <option value="300" selected>300</option>
                                            <option value="400">400</option>
                                            <option value="500">500</option>
                                        </select>
                                    </div>
                                    <div class="col-4">
                                        <label class="portal-form-label">Semester</label>
                                        <select name="semester" class="portal-form-control portal-select" required>
                                            <option value="First Semester">First</option>
                                            <option value="Second Semester" selected>Second</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="is_elective" id="isElective">
                                    <label class="form-check-label" for="isElective">Elective Course</label>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn-portal-primary">
                                        <i class="fa-solid fa-plus"></i> Add Course
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ASSIGN COURSE -->
                <div class="col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-user-check"></i> Assign Course to Lecturer
                            </div>
                        </div>
                        <div class="content-card-body">
                            <form method="POST">
                                <input type="hidden" name="form_action" value="assign_course">

                                <div class="mb-3">
                                    <label class="portal-form-label">Course *</label>
                                    <select name="course_id" class="portal-form-control portal-select" required>
                                        <option value="">-- Select Course --</option>
                                        <?php foreach ($courses as $c): ?>
                                            <?php if ((int)($c['is_active'] ?? 1) !== 1) continue; ?>
                                            <option value="<?= (int)$c['id'] ?>">
                                                <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_title']) ?> (<?= htmlspecialchars($c['level']) ?>L)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="portal-form-label">Lecturer *</label>
                                    <select name="lecturer_id" class="portal-form-control portal-select" required>
                                        <option value="">-- Select Lecturer --</option>
                                        <?php foreach ($lecturers as $l): ?>
                                            <option value="<?= (int)$l['id'] ?>">
                                                <?= htmlspecialchars($l['full_name']) ?> (<?= htmlspecialchars($l['staff_id'] ?? 'N/A') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="portal-form-label">Semester *</label>
                                    <select name="semester_id" class="portal-form-control portal-select" required>
                                        <option value="">-- Select Semester --</option>
                                        <?php foreach ($semesters as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>">
                                                <?= htmlspecialchars($s['session_name']) ?> — <?= htmlspecialchars($s['semester_name']) ?>
                                                <?= ((int)($s['is_current'] ?? 0) === 1) ? '(Current)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-4">
                                        <label class="portal-form-label">TEST %</label>
                                        <input type="number" name="ca_max" class="portal-form-control" value="30" min="0" max="100" required>
                                    </div>
                                    <div class="col-4">
                                        <label class="portal-form-label">LAB %</label>
                                        <input type="number" name="lab_max" class="portal-form-control" value="0" min="0" max="100" required>
                                    </div>
                                    <div class="col-4">
                                        <label class="portal-form-label">EXAM %</label>
                                        <input type="number" name="exam_max" class="portal-form-control" value="70" min="0" max="100" required>
                                    </div>
                                </div>
                                <div class="text-muted-sm mb-3">Must total exactly 100. Set LAB to 0 if course has no practical.</div>

                                <div class="d-grid">
                                    <button type="submit" class="btn-portal-gold">
                                        <i class="fa-solid fa-link"></i> Assign Course
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CURRENT ASSIGNMENTS -->
            <div class="content-card mb-4">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-list-check"></i> Current Course Assignments
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <?php if (count($assignments) > 0): ?>
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Lecturer</th>
                                    <th>Semester</th>
                                    <th>Split</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $a): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-700 text-green"><?= htmlspecialchars($a['course_code']) ?></div>
                                            <div class="text-muted-sm"><?= htmlspecialchars($a['course_title']) ?> (<?= htmlspecialchars($a['level']) ?>L)</div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($a['lecturer_name']) ?><br>
                                            <span class="text-muted-sm"><?= htmlspecialchars($a['staff_id'] ?? '') ?></span>
                                        </td>
                                        <td class="text-muted-sm">
                                            <?= htmlspecialchars($a['session_name']) ?><br>
                                            <?= htmlspecialchars($a['semester_name']) ?>
                                        </td>
                                        <td class="text-muted-sm">
                                            T<?= (int)$a['ca_max'] ?> /
                                            L<?= (int)$a['lab_max'] ?> /
                                            E<?= (int)$a['exam_max'] ?>
                                        </td>
                                        <td>
                                            <?php if ((int)$a['scores_submitted'] === 1): ?>
                                                <span class="status-badge submitted">Submitted</span>
                                            <?php else: ?>
                                                <span class="status-badge draft">Not Submitted</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="table-empty-state">
                            <p>No course assignments yet. Assign a course to a lecturer above.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ALL COURSES LIST -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-book-open"></i> All Courses
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Title</th>
                                <th>Level</th>
                                <th>Semester</th>
                                <th>Units</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $c): ?>
                                <tr>
                                    <td class="fw-700 text-green"><?= htmlspecialchars($c['course_code']) ?></td>
                                    <td><?= htmlspecialchars($c['course_title']) ?></td>
                                    <td><?= htmlspecialchars($c['level']) ?>L</td>
                                    <td><?= htmlspecialchars($c['semester']) ?></td>
                                    <td><?= (int)$c['credit_units'] ?></td>
                                    <td><?= ((int)$c['is_elective'] === 1) ? 'Elective' : 'Compulsory' ?></td>
                                    <td>
                                        <span class="status-badge <?= ((int)$c['is_active'] === 1) ? 'final_approved' : 'flagged' ?>">
                                            <?= ((int)$c['is_active'] === 1) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="form_action" value="toggle_course">
                                            <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= ((int)$c['is_active'] === 1) ? 0 : 1 ?>">
                                            <button class="btn-portal-sm <?= ((int)$c['is_active'] === 1) ? 'btn-portal-danger' : 'btn-portal-outline' ?>">
                                                <?= ((int)$c['is_active'] === 1) ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
</body>
</html>