<?php
// ============================================================
// CSCResultPortal - PDF Transcript Export
// File: pages/shared/transcript-pdf.php
// Purpose: Generate a printable PDF transcript for a student.
// Access: Advisors and HOD only (students cannot self-print)
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !in_array($_SESSION['role'] ?? '', ['advisor', 'hod'])
) {
    header('Location: ' . APP_URL . '/index.php');
    exit();
}

$studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;

if ($studentId <= 0) {
    die("Invalid student ID.");
}

// Fetch student
$student = dbFetchOne($pdo,
    "SELECT u.full_name, u.matric_number, u.email,
            sp.level, sp.cgpa, sp.total_units, sp.entry_year, sp.degree_class
     FROM users u
     LEFT JOIN student_profiles sp ON u.id = sp.user_id
     WHERE u.id = ? AND u.role = 'student'",
    [$studentId]
);

if (!is_array($student)) {
    die("Student not found.");
}

// Fetch all approved results
$results = dbFetchAll($pdo,
    "SELECT r.total_score, r.grade, r.grade_point, r.credit_units, r.quality_point,
            r.is_carryover, c.course_code, c.course_title,
            s.semester_name, ac.session_name, s.id AS sem_id
     FROM results r
     JOIN courses c ON r.course_id = c.id
     JOIN semesters s ON r.semester_id = s.id
     JOIN academic_sessions ac ON s.session_id = ac.id
     WHERE r.student_id = ? AND r.status = 'final_approved'
     ORDER BY s.id ASC, c.course_code ASC",
    [$studentId]
);

// Group by semester
$resultsBySemester = [];
foreach ($results as $r) {
    $key = ($r['session_name'] ?? '') . ' - ' . ($r['semester_name'] ?? '');
    if (!isset($resultsBySemester[$key])) {
        $resultsBySemester[$key] = ['courses' => [], 'total_units' => 0, 'total_qp' => 0];
    }
    $resultsBySemester[$key]['courses'][] = $r;
    $resultsBySemester[$key]['total_units'] += (int) ($r['credit_units'] ?? 0);
    $resultsBySemester[$key]['total_qp']    += (float) ($r['quality_point'] ?? 0);
}

// Audit log
writeAuditLog($pdo, (int) $_SESSION['user_id'], 'print_transcript_pdf', 'users', $studentId,
    null, ['student_matric' => $student['matric_number']]);

$cgpa = (float) ($student['cgpa'] ?? 0);
$degreeClass = getDegreeClass($cgpa);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transcript - <?= htmlspecialchars($student['matric_number'] ?? '') ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Times New Roman', Times, serif; background: #f0f0f0; padding: 20px; color: #000; }

        .print-controls {
            max-width: 900px; margin: 0 auto 20px; background: #fff; padding: 15px 25px;
            border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex; justify-content: space-between; align-items: center;
        }
        .print-controls h2 { color: #006400; font-size: 18px; }
        .btn {
            padding: 10px 18px; border: none; border-radius: 6px; font-size: 13px;
            font-weight: 600; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-print { background: #006400; color: #fff; }
        .btn-back { background: #6c757d; color: #fff; margin-right: 10px; }

        .transcript {
            background: #fff; max-width: 900px; margin: 0 auto;
            padding: 50px 60px; min-height: 1200px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .header { text-align: center; border-bottom: 3px double #000; padding-bottom: 20px; margin-bottom: 25px; }
        .university { font-size: 24px; font-weight: 700; letter-spacing: 2px; margin-bottom: 8px; }
        .dept { font-size: 16px; font-weight: 600; margin-bottom: 5px; }
        .title { font-size: 20px; font-weight: 700; text-decoration: underline; letter-spacing: 3px; margin-top: 12px; }

        .student-info { background: #f9f9f9; border: 1px solid #ddd; padding: 15px 20px; margin-bottom: 25px; }
        .student-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; font-size: 13px; }
        .info-item strong { font-weight: 700; }

        .semester-block { margin-bottom: 25px; page-break-inside: avoid; }
        .semester-title {
            background: #006400; color: #fff; padding: 8px 15px;
            font-size: 14px; font-weight: 700; letter-spacing: 1px;
        }

        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #333; padding: 6px 10px; text-align: left; }
        th { background: #f0f0f0; font-weight: 700; text-transform: uppercase; font-size: 11px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .fw-bold { font-weight: 700; }

        .semester-summary { background: #fff8cc; font-weight: 700; }

        .final-summary {
            border: 2px solid #006400; padding: 15px 20px; margin: 30px 0;
            background: #f4fff4;
        }
        .final-summary h3 { color: #006400; text-align: center; margin-bottom: 12px; font-size: 16px; }
        .final-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; text-align: center; }
        .final-item .label { font-size: 11px; color: #666; text-transform: uppercase; }
        .final-item .value { font-size: 18px; font-weight: 700; color: #006400; margin-top: 4px; }

        .signatures {
            display: flex; justify-content: space-between;
            margin-top: 50px; padding-top: 30px;
        }
        .signature-block { width: 40%; text-align: center; }
        .signature-line { border-bottom: 1px solid #000; height: 50px; margin-bottom: 5px; }
        .signature-label { font-size: 12px; font-weight: 600; }

        .footer-note {
            margin-top: 30px; padding-top: 15px; border-top: 1px dashed #999;
            font-size: 10px; color: #666; text-align: center;
        }

        @media print {
            body { background: #fff; padding: 0; margin: 0; }
            .print-controls { display: none !important; }
            .transcript { box-shadow: none; margin: 0; padding: 15mm; max-width: 100%; min-height: auto; }
            @page { size: A4; margin: 10mm; }
        }
    </style>
</head>
<body>

    <div class="print-controls">
        <h2>📄 Official Academic Transcript</h2>
        <div>
            <a href="javascript:history.back()" class="btn btn-back">← Back</a>
            <button onclick="window.print()" class="btn btn-print">🖨️ Print / Save as PDF</button>
        </div>
    </div>

    <div class="transcript">

        <div class="header">
            <div class="university">FEDERAL UNIVERSITY OF TECHNOLOGY, OWERRI</div>
            <div class="dept">School of Electrical Systems &amp; Information Technology</div>
            <div class="dept">Department of Computer Science</div>
            <div class="title">OFFICIAL ACADEMIC TRANSCRIPT</div>
        </div>

        <div class="student-info">
            <div class="student-info-grid">
                <div class="info-item"><strong>Name:</strong> <?= htmlspecialchars($student['full_name'] ?? '') ?></div>
                <div class="info-item"><strong>Matric Number:</strong> <?= htmlspecialchars($student['matric_number'] ?? '') ?></div>
                <div class="info-item"><strong>Programme:</strong> B.Sc. Computer Science</div>
                <div class="info-item"><strong>Current Level:</strong> <?= htmlspecialchars($student['level'] ?? 'N/A') ?>L</div>
                <div class="info-item"><strong>Year of Entry:</strong> <?= htmlspecialchars($student['entry_year'] ?? 'N/A') ?></div>
                <div class="info-item"><strong>Date Printed:</strong> <?= date('d F Y') ?></div>
            </div>
        </div>

        <?php if (empty($resultsBySemester)): ?>
            <p style="text-align:center;padding:40px;color:#999;">No approved results available.</p>
        <?php else: ?>
            <?php foreach ($resultsBySemester as $semLabel => $semData): 
                $semGpa = $semData['total_units'] > 0 
                    ? round($semData['total_qp'] / $semData['total_units'], 2) 
                    : 0.00;
            ?>
                <div class="semester-block">
                    <div class="semester-title"><?= htmlspecialchars($semLabel) ?></div>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:15%">Course Code</th>
                                <th>Course Title</th>
                                <th class="text-center" style="width:8%">Units</th>
                                <th class="text-center" style="width:8%">Score</th>
                                <th class="text-center" style="width:8%">Grade</th>
                                <th class="text-center" style="width:8%">Point</th>
                                <th class="text-center" style="width:10%">Quality Pts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($semData['courses'] as $c): ?>
                                <tr>
                                    <td class="fw-bold">
                                        <?= htmlspecialchars($c['course_code'] ?? '') ?>
                                        <?php if (!empty($c['is_carryover'])): ?>
                                            <sup style="color:#dc3545;">CO</sup>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($c['course_title'] ?? '') ?></td>
                                    <td class="text-center"><?= (int) ($c['credit_units'] ?? 0) ?></td>
                                    <td class="text-center"><?= number_format((float) ($c['total_score'] ?? 0), 1) ?></td>
                                    <td class="text-center fw-bold"><?= htmlspecialchars($c['grade'] ?? '') ?></td>
                                    <td class="text-center"><?= number_format((float) ($c['grade_point'] ?? 0), 1) ?></td>
                                    <td class="text-center"><?= number_format((float) ($c['quality_point'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="semester-summary">
                                <td colspan="2" class="text-right">SEMESTER TOTAL:</td>
                                <td class="text-center"><?= $semData['total_units'] ?></td>
                                <td colspan="2" class="text-right">GPA:</td>
                                <td class="text-center" colspan="2"><?= number_format($semGpa, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <div class="final-summary">
                <h3>CUMULATIVE ACADEMIC RECORD</h3>
                <div class="final-grid">
                    <div class="final-item">
                        <div class="label">Total Units</div>
                        <div class="value"><?= (int) ($student['total_units'] ?? 0) ?></div>
                    </div>
                    <div class="final-item">
                        <div class="label">Cumulative GPA</div>
                        <div class="value"><?= number_format($cgpa, 2) ?> / 5.00</div>
                    </div>
                    <div class="final-item">
                        <div class="label">Classification</div>
                        <div class="value" style="font-size:13px;"><?= htmlspecialchars($degreeClass) ?></div>
                    </div>
                    <div class="final-item">
                        <div class="label">Semesters</div>
                        <div class="value"><?= count($resultsBySemester) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="signatures">
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-label">Head of Department<br>Computer Science</div>
            </div>
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="signature-label">Dean of School<br>SEET</div>
            </div>
        </div>

        <div class="footer-note">
            This transcript was generated by CSCResultPortal on <?= date('d/m/Y H:i:s') ?><br>
            Verified by: <?= htmlspecialchars($_SESSION['full_name'] ?? 'Staff') ?> — <?= ucfirst($_SESSION['role']) ?>
        </div>

    </div>

</body>
</html>