<?php
// ============================================================
// CSCResultPortal - Advisor Student Search & Transcript
// File: pages/advisor/student-search.php
// Purpose: Course Advisor searches for students, views their
//          complete academic records, and prints transcripts
//          on their behalf. ONLY advisors can print transcripts.
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access Control
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
// SECTION 1: HANDLE SEARCH QUERY
// Searches by name or matric number.
// ============================================================
$searchQuery    = trim($_GET['q'] ?? '');
$searchResults  = [];

if (!empty($searchQuery)) {
    try {
                $searchResults = dbFetchAll($pdo,
            "SELECT u.id, u.full_name, u.matric_number, u.email,
                    sp.level, sp.cgpa, sp.total_units, sp.degree_class, sp.entry_year
             FROM users u
             JOIN student_profiles sp ON u.id = sp.user_id
             WHERE u.role = 'student' AND u.is_active = 1
             AND (
                 u.full_name LIKE ?
                 OR u.matric_number LIKE ?
             )" . $levelSql . "
             ORDER BY u.matric_number ASC
             LIMIT 20",
            array_merge(["%{$searchQuery}%", "%{$searchQuery}%"], $levelParams)
        );
    } catch (PDOException $e) {
        $searchResults = [];
    }
}

// ============================================================
// SECTION 2: LOAD SPECIFIC STUDENT FULL RECORD
// When advisor clicks on a student from the search results.
// ============================================================
$selectedStudent    = null;
$studentResults     = [];
$studentGPAHistory  = [];
$studentCarryovers  = [];

$selectedStudentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;

if ($selectedStudentId > 0) {
    try {
        // Fetch student profile
                $selectedStudent = dbFetchOne($pdo,
            "SELECT u.id, u.full_name, u.matric_number, u.email,
                    sp.level, sp.cgpa, sp.total_units, sp.degree_class,
                    sp.entry_year, sp.graduation_year
             FROM users u
             JOIN student_profiles sp ON u.id = sp.user_id
             WHERE u.id = ? AND u.role = 'student'" . $levelSql,
            array_merge([$selectedStudentId], $levelParams)
        );

        if ($selectedStudent) {
            // Full approved result history
            $studentResults = dbFetchAll($pdo,
                "SELECT r.ca_score, r.exam_score, r.total_score, r.grade,
                        r.grade_point, r.credit_units, r.quality_point, r.is_carryover,
                        c.course_code, c.course_title,
                        s.semester_name, ac.session_name,
                        lu.full_name AS lecturer_name
                 FROM results r
                 JOIN courses c ON r.course_id = c.id
                 JOIN semesters s ON r.semester_id = s.id
                 JOIN academic_sessions ac ON s.session_id = ac.id
                 JOIN course_assignments ca ON r.assignment_id = ca.id
                 JOIN users lu ON ca.lecturer_id = lu.id
                 WHERE r.student_id = ? AND r.status = 'final_approved'
                 ORDER BY s.id ASC, c.course_code ASC",
                [$selectedStudentId]
            );

            // GPA history per semester
            $studentGPAHistory = dbFetchAll($pdo,
                "SELECT sg.gpa, sg.cgpa, sg.total_units, sg.total_quality_points,
                        s.semester_name, ac.session_name
                 FROM semester_gpa sg
                 JOIN semesters s ON sg.semester_id = s.id
                 JOIN academic_sessions ac ON s.session_id = ac.id
                 WHERE sg.student_id = ?
                 ORDER BY s.id ASC",
                [$selectedStudentId]
            );

            // Carryover history
            $studentCarryovers = dbFetchAll($pdo,
                "SELECT cr.status, cr.created_at, c.course_code, c.course_title
                 FROM carryover_registrations cr
                 JOIN courses c ON cr.course_id = c.id
                 WHERE cr.student_id = ?
                 ORDER BY cr.created_at DESC",
                [$selectedStudentId]
            );
        }
    } catch (PDOException $e) {
        $selectedStudent = null;
    }

    // Organise results by semester for transcript layout
    $resultsBySemester = [];
    foreach ($studentResults as $row) {
        $key = $row['session_name'] . ' - ' . $row['semester_name'];
        if (!isset($resultsBySemester[$key])) {
            $resultsBySemester[$key] = ['courses' => []];
        }
        $resultsBySemester[$key]['courses'][] = $row;
    }

    // Audit log: advisor viewed student transcript
    if ($selectedStudent) {
        writeAuditLog($pdo, $advisorId, 'view_student_transcript',
            'users', $selectedStudentId, null,
            ['student_matric' => $selectedStudent['matric_number']]
        );
    }
}

$pageTitle      = 'Student Search';
$pageBreadcrumb = 'Advisor / Student Search';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Search &mdash; CSCResultPortal</title>
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
                    <i class="fa-solid fa-user-magnifying-glass me-2 text-green"></i>
                    Student Search & Transcript
                </h1>
                <p class="page-header-subtitle">
                    Search for students by name or matric number. Only the Course Advisor can view and print student transcripts.
                </p>
                <hr class="page-header-divider">
            </div>

            <!-- Search Bar -->
            <div class="content-card mb-4">
                <div class="content-card-body">
                    <form method="GET" action="" id="studentSearchForm">
                        <?php if ($selectedStudentId): ?>
                            <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                        <?php endif; ?>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-7">
                                <label class="portal-form-label">
                                    <i class="fa-solid fa-magnifying-glass me-1 text-green"></i>
                                    Search by Student Name or Matric Number
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fa-solid fa-search text-muted"></i>
                                    </span>
                                    <input type="text" name="q" id="searchInput"
                                           class="form-control portal-form-control border-start-0"
                                           placeholder="e.g. John Doe or CSC/2020/001"
                                           value="<?= htmlspecialchars($searchQuery) ?>"
                                           autofocus>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn-portal-primary w-100">
                                    <i class="fa-solid fa-magnifying-glass"></i> Search
                                </button>
                            </div>
                            <?php if (!empty($searchQuery)): ?>
                                <div class="col-md-2">
                                    <a href="student-search.php" class="btn-portal-outline w-100">
                                        <i class="fa-solid fa-xmark"></i> Clear
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-4">

                <!-- LEFT: Search Results -->
                <div class="col-lg-4">
                    <?php if (!empty($searchQuery)): ?>
                        <div class="content-card">
                            <div class="content-card-header">
                                <div class="content-card-title">
                                    <i class="fa-solid fa-list"></i>
                                    Search Results
                                </div>
                                <?php if (count($searchResults) > 0): ?>
                                    <span class="status-badge submitted">
                                        <?= count($searchResults) ?> found
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (count($searchResults) > 0): ?>
                                <div class="student-search-list">
                                    <?php foreach ($searchResults as $st): ?>
                                        <?php $isSelected = ($selectedStudentId === (int) $st['id']); ?>
                                        <a href="student-search.php?q=<?= urlencode($searchQuery) ?>&student_id=<?= (int) $st['id'] ?>"
                                           class="student-search-item <?= $isSelected ? 'active' : '' ?>">
                                            <div class="student-avatar-sm">
                                                <?php
                                                $sNP = explode(' ', trim($st['full_name']));
                                                $sIN = '';
                                                foreach ($sNP as $p) { if (!empty($p)) { $sIN .= strtoupper($p[0]); } if (strlen($sIN) >= 2) break; }
                                                echo htmlspecialchars($sIN);
                                                ?>
                                            </div>
                                            <div class="student-search-info">
                                                <div class="student-search-name">
                                                    <?= htmlspecialchars($st['full_name']) ?>
                                                </div>
                                                <div class="student-search-matric">
                                                    <?= htmlspecialchars($st['matric_number']) ?>
                                                </div>
                                                <div class="student-search-meta">
                                                    Level <?= htmlspecialchars($st['level']) ?>
                                                    &bull;
                                                    CGPA: <strong><?= number_format((float) $st['cgpa'], 2) ?></strong>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-user-slash"></i>
                                    <p>No students found for "<?= htmlspecialchars($searchQuery) ?>".</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="content-card">
                            <div class="table-empty-state" style="padding:40px 20px;">
                                <i class="fa-solid fa-user-magnifying-glass" style="font-size:40px; color:#e0e0e0;"></i>
                                <p style="margin-top:12px; color:#aaa;">Enter a student name or matric number above to begin.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- RIGHT: Student Profile & Transcript -->
                <div class="col-lg-8">
                    <?php if ($selectedStudent): ?>

                        <!-- Student Profile Card -->
                        <div class="content-card mb-4">
                            <div class="content-card-body">
                                <div class="d-flex align-items-center gap-4 flex-wrap">
                                    <div class="transcript-avatar">
                                        <?php
                                        $tNP = explode(' ', trim($selectedStudent['full_name']));
                                        $tIN = '';
                                        foreach ($tNP as $p) { if (!empty($p)) { $tIN .= strtoupper($p[0]); } if (strlen($tIN) >= 2) break; }
                                        echo htmlspecialchars($tIN);
                                        ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h4 class="fw-700 mb-1">
                                            <?= htmlspecialchars($selectedStudent['full_name']) ?>
                                        </h4>
                                        <div class="row g-2" style="font-size:13px;">
                                            <div class="col-6">
                                                <span class="text-muted">Matric:</span>
                                                <strong class="ms-1">
                                                    <?= htmlspecialchars($selectedStudent['matric_number']) ?>
                                                </strong>
                                            </div>
                                            <div class="col-6">
                                                <span class="text-muted">Level:</span>
                                                <strong class="ms-1">
                                                    <?= htmlspecialchars($selectedStudent['level']) ?>L
                                                </strong>
                                            </div>
                                            <div class="col-6">
                                                <span class="text-muted">CGPA:</span>
                                                <strong class="ms-1 text-green">
                                                    <?= number_format((float) $selectedStudent['cgpa'], 2) ?> / 5.00
                                                </strong>
                                            </div>
                                            <div class="col-6">
                                                <span class="text-muted">Entry Year:</span>
                                                <strong class="ms-1">
                                                    <?= htmlspecialchars($selectedStudent['entry_year'] ?? 'N/A') ?>
                                                </strong>
                                            </div>
                                            <div class="col-12">
                                                <span class="text-muted">Classification:</span>
                                                <strong class="ms-1 text-green">
                                                    <?= htmlspecialchars(getDegreeClass((float) $selectedStudent['cgpa'])) ?>
                                                </strong>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Print Transcript Button (Advisor only) -->
                                    <div>
                                        <button onclick="printTranscript()" class="btn-portal-gold">
                                            <i class="fa-solid fa-print"></i>
                                            Print Transcript
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- GPA History Summary -->
                        <?php if (count($studentGPAHistory) > 0): ?>
                            <div class="content-card mb-4">
                                <div class="content-card-header">
                                    <div class="content-card-title">
                                        <i class="fa-solid fa-chart-line"></i>
                                        GPA History
                                    </div>
                                </div>
                                <div style="overflow-x:auto;">
                                    <table class="portal-table">
                                        <thead>
                                            <tr>
                                                <th>Session</th>
                                                <th>Semester</th>
                                                <th class="text-center">Units</th>
                                                <th class="text-center">Quality Pts</th>
                                                <th class="text-center">Sem. GPA</th>
                                                <th class="text-center">CGPA</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($studentGPAHistory as $gpa): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($gpa['session_name']) ?></td>
                                                    <td><?= htmlspecialchars($gpa['semester_name']) ?></td>
                                                    <td class="text-center"><?= (int) $gpa['total_units'] ?></td>
                                                    <td class="text-center"><?= number_format((float) $gpa['total_quality_points'], 2) ?></td>
                                                    <td class="text-center fw-700 text-green">
                                                        <?= number_format((float) $gpa['gpa'], 2) ?>
                                                    </td>
                                                    <td class="text-center fw-700">
                                                        <?= number_format((float) $gpa['cgpa'], 2) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Full Results by Semester -->
                        <?php if (!empty($resultsBySemester)): ?>
                            <div class="content-card" id="transcriptBody">
                                <div class="content-card-header">
                                    <div class="content-card-title">
                                        <i class="fa-solid fa-scroll"></i>
                                        Full Academic Transcript
                                    </div>
                                </div>
                                <div class="content-card-body">

                                    <!-- Transcript Header (for print) -->
                                    <div class="transcript-print-header d-none" id="transcriptPrintHeader">
                                        <h3 class="text-center fw-700">FEDERAL UNIVERSITY OF TECHNOLOGY OWERRI</h3>
                                        <h5 class="text-center">DEPARTMENT OF COMPUTER SCIENCE</h5>
                                        <h5 class="text-center">OFFICIAL ACADEMIC TRANSCRIPT</h5>
                                        <hr>
                                        <div class="row">
                                            <div class="col-6">Name: <strong><?= htmlspecialchars($selectedStudent['full_name']) ?></strong></div>
                                            <div class="col-6">Matric: <strong><?= htmlspecialchars($selectedStudent['matric_number']) ?></strong></div>
                                            <div class="col-6">Level: <strong><?= htmlspecialchars($selectedStudent['level']) ?>L</strong></div>
                                            <div class="col-6">CGPA: <strong><?= number_format((float) $selectedStudent['cgpa'], 2) ?>/5.00</strong></div>
                                            <div class="col-12">Classification: <strong><?= htmlspecialchars(getDegreeClass((float) $selectedStudent['cgpa'])) ?></strong></div>
                                        </div>
                                        <hr>
                                    </div>

                                    <?php foreach ($resultsBySemester as $semLabel => $semData): ?>
                                        <div class="semester-block mb-4">
                                            <div class="semester-block-header">
                                                <div class="semester-block-title">
                                                    <i class="fa-solid fa-calendar-alt me-2"></i>
                                                    <?= htmlspecialchars($semLabel) ?>
                                                </div>
                                            </div>
                                            <table class="portal-table">
                                                <thead>
                                                    <tr>
                                                        <th>Code</th>
                                                        <th>Course Title</th>
                                                        <th class="text-center">Units</th>
                                                        <th class="text-center">Total</th>
                                                        <th class="text-center">Grade</th>
                                                        <th class="text-center">GP</th>
                                                        <th class="text-center">QP</th>
                                                        <th>Type</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($semData['courses'] as $course): ?>
                                                        <tr>
                                                            <td class="fw-700">
                                                                <?= htmlspecialchars($course['course_code']) ?>
                                                            </td>
                                                            <td><?= htmlspecialchars($course['course_title']) ?></td>
                                                            <td class="text-center"><?= (int) $course['credit_units'] ?></td>
                                                            <td class="text-center fw-700">
                                                                <?= number_format((float) $course['total_score'], 1) ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <span class="grade-badge grade-<?= strtolower($course['grade']) ?>">
                                                                    <?= htmlspecialchars($course['grade']) ?>
                                                                </span>
                                                            </td>
                                                            <td class="text-center">
                                                                <?= number_format((float) $course['grade_point'], 1) ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <?= number_format((float) $course['quality_point'], 2) ?>
                                                            </td>
                                                            <td>
                                                                <span class="text-muted-sm">
                                                                    <?= (int) $course['is_carryover'] === 1 ? 'Carryover' : 'Normal' ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endforeach; ?>

                                    <!-- Transcript Footer (for print) -->
                                    <div class="transcript-print-footer d-none" id="transcriptPrintFooter">
                                        <hr>
                                        <div class="row mt-3">
                                            <div class="col-6">
                                                Printed by: <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong>
                                                (Course Advisor)
                                            </div>
                                            <div class="col-6 text-end">
                                                Date: <strong><?= date('d F Y') ?></strong>
                                            </div>
                                        </div>
                                        <p class="text-muted-sm mt-2">
                                            This transcript is issued by the CSCResultPortal — FUTO, Department of Computer Science.
                                        </p>
                                    </div>

                                </div>
                            </div>
                        <?php else: ?>
                            <div class="content-card">
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-file-circle-xmark"></i>
                                    <p>No approved results found for this student.</p>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($selectedStudentId > 0): ?>
                        <div class="content-card">
                            <div class="table-empty-state">
                                <i class="fa-solid fa-user-slash"></i>
                                <p>Student not found or access restricted.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="content-card">
                            <div class="table-empty-state" style="padding:60px 40px;">
                                <i class="fa-solid fa-user-tie" style="font-size:48px; color:#e0e0e0; margin-bottom:16px;"></i>
                                <p style="font-size:15px; color:#999;">
                                    Search for a student and click their name to view their full academic record and transcript.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- /.row -->

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/advisor.js"></script>
</body>
</html>