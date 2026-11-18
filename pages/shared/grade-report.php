<?php
// ============================================================
// CSCResultPortal - Official Grade Report
// File: pages/shared/grade-report.php
// Layout follows the FUTO "Official Grade Report" sheet:
//   S/No | Candidate's Name | Reg. No | Program | Test | Lab |
//   Exam | Total | Grade | Remark
// One A4 page per 40 students; every page repeats the header,
// signature area and grading key, and carries a page number.
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !in_array($_SESSION['role'] ?? '', ['lecturer', 'advisor', 'hod'])
) {
    header('Location: ' . APP_URL . '/index.php');
    exit();
}

$userId   = (int) $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// ---------- Settings you may want to change ----------
$schoolName     = 'SICT';              // School of Student / School Offering Course
$departmentName = 'COMPUTER SCIENCE';
$programCode    = 'CSC';
$rowsPerPage    = 40;                  // students per A4 page

$assignmentId = isset($_GET['assignment_id']) ? (int) $_GET['assignment_id'] : 0;

if ($assignmentId <= 0) {
    die("Invalid course assignment. <a href='" . APP_URL . "'>Go back</a>");
}

$assignment = null;
try {
    $assignment = dbFetchOne($pdo,
        "SELECT ca.id, ca.course_id, ca.lecturer_id, ca.semester_id,
                ca.ca_max, ca.lab_max, ca.exam_max, ca.has_lab,
                c.course_code, c.course_title, c.credit_units, c.level,
                u.full_name AS lecturer_name, u.staff_id,
                s.semester_name, ac.session_name
         FROM course_assignments ca
         JOIN courses c ON ca.course_id = c.id
         JOIN users u ON ca.lecturer_id = u.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE ca.id = ?",
        [$assignmentId]
    );
} catch (PDOException $e) {
    $assignment = null;
}

if (!is_array($assignment)) {
    die("Course assignment not found.");
}

if ($userRole === 'lecturer' && (int) $assignment['lecturer_id'] !== $userId) {
    die("Access denied. You can only print grade reports for your own courses.");
}

// Level-locked advisors can only print reports for their own level's courses
if ($userRole === 'advisor') {
    $advLevel = getAdvisorLevel($pdo, $userId);
    if ($advLevel && (string) $assignment['level'] !== $advLevel) {
        die("Access denied. This course is not for your level.");
    }
}

// ---------- Students (approved registrations only) ----------
$results = [];
try {
    $results = dbFetchAll($pdo,
        "SELECT r.id AS result_id, r.ca_score, r.lab_score, r.exam_score, r.total_score,
                r.grade, r.grade_point, r.status,
                u.matric_number, u.full_name AS student_name
         FROM student_course_registrations scr
         JOIN users u ON scr.student_id = u.id
         LEFT JOIN results r ON r.student_id = u.id AND r.assignment_id = ?
         WHERE scr.course_id = ? AND scr.semester_id = ? AND scr.status = 'approved'
         ORDER BY u.matric_number ASC",
        [$assignmentId, $assignment['course_id'], $assignment['semester_id']]
    );
} catch (PDOException $e) {
    $results = [];
}

// ---------- Display values ----------
$semesterLabel = (($assignment['semester_name'] ?? '') === 'First Semester') ? 'Harmattan' : 'Rain';
$hasLab        = (int) ($assignment['has_lab'] ?? 0) === 1;

// "CSC513" -> "CSC 513"
$courseCode = trim($assignment['course_code'] ?? '');
$courseCode = preg_replace('/^([A-Za-z]{2,4})\s*(\d{3}.*)$/', '$1 $2', $courseCode);

$pages      = array_chunk($results, $rowsPerPage);
if (empty($pages)) {
    $pages = [[]];
}

try {
    writeAuditLog($pdo, $userId, 'print_grade_report', 'course_assignments',
        $assignmentId, null,
        ['course_code' => $assignment['course_code'], 'total_students' => count($results)]
    );
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Grade Report - <?= htmlspecialchars($assignment['course_code'] ?? '') ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; background: #e9e9e9; padding: 20px; color: #000; }

        /* ---------- On-screen toolbar ---------- */
        .print-controls { max-width: 210mm; margin: 0 auto 20px; background: #fff; padding: 15px 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .print-controls h2 { color: #006400; font-size: 18px; }
        .btn-group { display: flex; gap: 10px; }
        .btn { padding: 10px 18px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-print { background: #006400; color: #fff; }
        .btn-print:hover { background: #004d00; }
        .btn-back { background: #6c757d; color: #fff; }
        .btn-back:hover { background: #545b62; }

        /* ---------- One A4 sheet ---------- */
        .grade-page { position: relative; width: 210mm; height: 297mm; margin: 0 auto 20px; padding: 9mm 11mm 8mm; background: #fff; box-shadow: 0 4px 20px rgba(0,0,0,0.15); display: flex; flex-direction: column; }

        .university-name { text-align: center; font-size: 15px; font-weight: 700; letter-spacing: 0.3px; }
        .report-title { text-align: center; font-size: 12px; font-weight: 700; text-decoration: underline; margin: 1mm 0 5mm; }

        .meta { display: grid; grid-template-columns: 52% 48%; margin-bottom: 2.5mm; font-size: 11px; font-weight: 700; line-height: 1.6; }
        .meta .u { display: inline-block; min-width: 30mm; padding: 0 4px; border-bottom: 1px solid #000; text-align: center; }

        /* ---------- Table ---------- */
        .grade-table { width: 100%; border-collapse: collapse; table-layout: fixed; border: 1.5px solid #000; }
        .grade-table th, .grade-table td { border: 1px solid #000; text-align: center; padding: 0 3px; height: 4.5mm; line-height: 1; font-size: 10px; white-space: nowrap; overflow: hidden; }
        .grade-table th { height: 6mm; font-size: 9.5px; font-weight: 700; text-transform: uppercase; }
        .grade-table th.big { font-size: 11px; }
        .grade-table td.name { text-align: left; padding-left: 5px; font-weight: 600; text-overflow: ellipsis; }
        .grade-table td.sn { font-weight: 600; }
        .grade-table td.total { font-size: 12px; font-weight: 700; }
        .grade-table td.grade { font-size: 12px; font-weight: 700; }
        .grade-table td.remark { font-size: 11px; font-weight: 600; }

        /* ---------- Footer (stays at the bottom of every page) ---------- */
        .page-footer { margin-top: auto; }
        .sign-area { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 3mm; }
        .sign-col { width: 46%; }
        .sig-line { border-bottom: 1px solid #000; height: 9mm; }
        .sig-label { text-align: right; font-size: 10px; font-weight: 600; margin: 0.5mm 0 1mm; }
        .sign-col.left .sig-label { text-align: left; }

        .key-box { width: 58%; margin-left: auto; border: 1.5px solid #000; padding: 2.5mm 4mm; display: grid; grid-template-columns: 1fr 1fr; gap: 0 6mm; font-size: 10px; font-weight: 700; line-height: 1.7; }
        .page-no { text-align: right; font-size: 9px; font-style: italic; margin-top: 1.5mm; }

        /* ---------- Printing ---------- */
        @media print {
            body { background: #fff; padding: 0; }
            .print-controls { display: none !important; }
            .grade-page { margin: 0; box-shadow: none; height: 296mm; page-break-after: always; break-after: page; }
            .pages .grade-page:last-child { page-break-after: auto; break-after: auto; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>

    <div class="print-controls">
        <h2>&#128196; Official Grade Report</h2>
        <div class="btn-group">
            <a href="javascript:history.back()" class="btn btn-back">&larr; Back</a>
            <button onclick="window.print()" class="btn btn-print">&#128424; Print / Save as PDF</button>
        </div>
    </div>

    <div class="pages">
    <?php foreach ($pages as $pageIndex => $pageRows): ?>
        <section class="grade-page">

            <div class="university-name">FEDERAL UNIVERSITY OF TECHNOLOGY OWERRI</div>
            <div class="report-title">OFFICIAL GRADE REPORT</div>

            <div class="meta">
                <div>
                    <div>School of Student: <span class="u"><?= htmlspecialchars($schoolName) ?></span></div>
                    <div>Department: <span class="u"><?= htmlspecialchars($departmentName) ?></span></div>
                    <div>Title of Course: <?= htmlspecialchars($assignment['course_title'] ?? '') ?></div>
                    <div>School Offering Course: <?= htmlspecialchars($schoolName) ?></div>
                </div>
                <div>
                    <div>Semester: <?= htmlspecialchars($semesterLabel) ?></div>
                    <div>Session : <?= htmlspecialchars($assignment['session_name'] ?? '') ?></div>
                    <div>Course Code: <?= htmlspecialchars($courseCode) ?> &nbsp;Unit: <?= (int) ($assignment['credit_units'] ?? 0) ?></div>
                    <div>Date: <?= date('d/m/Y') ?></div>
                </div>
            </div>

            <table class="grade-table">
                <colgroup>
                    <col style="width:5%;">
                    <col style="width:32%;">
                    <col style="width:14%;">
                    <col style="width:8%;">
                    <col style="width:6%;">
                    <col style="width:6%;">
                    <col style="width:6.5%;">
                    <col style="width:7.5%;">
                    <col style="width:7%;">
                    <col style="width:8%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>S/No</th>
                        <th>Candidate's Name</th>
                        <th>Reg. No</th>
                        <th>Program</th>
                        <th>Test</th>
                        <th>Lab</th>
                        <th>Exam</th>
                        <th class="big">Total</th>
                        <th>Grade</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pageRows as $i => $r): ?>
                    <?php
                    $sn       = ($pageIndex * $rowsPerPage) + $i + 1;
                    $hasScore = !empty($r['grade']);
                    $isFail   = $hasScore && $r['grade'] === 'F';
                    ?>
                    <tr>
                        <td class="sn"><?= $sn ?></td>
                        <td class="name"><?= htmlspecialchars(mb_strtoupper($r['student_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($r['matric_number'] ?? '') ?></td>
                        <td><?= htmlspecialchars($programCode) ?></td>
                        <td><?= ($r['ca_score'] ?? null) !== null ? number_format((float) $r['ca_score'], 0) : '' ?></td>
                        <td><?= ($hasLab && ($r['lab_score'] ?? null) !== null) ? number_format((float) $r['lab_score'], 0) : '' ?></td>
                        <td><?= ($r['exam_score'] ?? null) !== null ? number_format((float) $r['exam_score'], 0) : '' ?></td>
                        <td class="total"><?= ($r['total_score'] ?? null) !== null ? number_format((float) $r['total_score'], 0) : '' ?></td>
                        <td class="grade"><?= htmlspecialchars($r['grade'] ?? '') ?></td>
                        <td class="remark"><?= $hasScore ? ($isFail ? 'FAIL' : 'PASS') : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="page-footer">
                <div class="sign-area">
                    <div class="sign-col left">
                        <div class="sig-line"></div>
                        <div class="sig-label">HOD</div>
                    </div>
                    <div class="sign-col">
                        <div class="sig-line"></div>
                        <div class="sig-label">Examiner(s)</div>
                        <div class="sig-line"></div>
                        <div class="sig-label">Dean</div>
                    </div>
                </div>

                <div class="key-box">
                    <div>
                        <div>70% - 100%: A</div>
                        <div>60% - 69%: B</div>
                        <div>50% - 59%: C</div>
                    </div>
                    <div>
                        <div>45% - 49%: D</div>
                        <div>40% - 44%: E</div>
                        <div>Below 40%: F</div>
                    </div>
                </div>

                <div class="page-no">Page <?= $pageIndex + 1 ?></div>
            </div>

        </section>
    <?php endforeach; ?>
    </div>

    <script>
        <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] == '1'): ?>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 500);
        });
        <?php endif; ?>
    </script>

</body>
</html>
