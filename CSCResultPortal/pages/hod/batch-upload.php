<?php
// ============================================================
// CSCResultPortal - HOD Batch Upload
// File: pages/hod/batch-upload.php
// Purpose: HOD uploads CSV/Excel files to bulk-import student
//          scores. Parses the file, validates each row, inserts
//          results, and logs success/failure statistics.
// Expected CSV columns:
//   matric_number, course_code, ca_score, exam_score, semester_id
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access Control
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $_SESSION['role'] !== 'hod'
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

// ============================================================
// SECTION 1: HANDLE FILE UPLOAD (POST)
// ============================================================
$uploadMessage = '';
$uploadSuccess = false;
$uploadReport  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_action'])) {
    $semesterId = isset($_POST['semester_id']) ? (int) $_POST['semester_id'] : 0;

    if ($semesterId === 0) {
        $uploadMessage = 'Please select a target semester before uploading.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadMessage = 'No file uploaded or file upload error. Please try again.';
    } else {
        $file     = $_FILES['csv_file'];
        $fileName = $file['name'];
        $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($fileExt, ['csv', 'txt'])) {
            $uploadMessage = 'Invalid file type. Only CSV (.csv) files are accepted.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $uploadMessage = 'File too large. Maximum allowed size is 5MB.';
        } else {
            // Save file to uploads directory
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0755, true);
            }
            $savedName = 'batch_' . date('Ymd_His') . '_' . $hodId . '.' . $fileExt;
            $savedPath = UPLOAD_PATH . $savedName;

            if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
                $uploadMessage = 'Failed to save uploaded file. Check server permissions.';
            } else {
                // Create batch upload log record
                $batchId = dbInsert($pdo,
                    "INSERT INTO batch_uploads
                        (uploaded_by, semester_id, file_name, file_path, total_rows, status)
                     VALUES (?, ?, ?, ?, 0, 'processing')",
                    [$hodId, $semesterId, $fileName, $savedPath]
                );

                // Parse CSV
                $handle       = fopen($savedPath, 'r');
                $headers      = fgetcsv($handle); // Skip header row

                // Normalize headers
                $headers = array_map(function($h) {
                    return strtolower(trim(str_replace(['"', "'", ' '], '', $h)));
                }, $headers ?? []);

                $totalRows   = 0;
                $successRows = 0;
                $failedRows  = 0;
                $errorLog    = [];

                $pdo->beginTransaction();

                while (($row = fgetcsv($handle)) !== false) {
                    $totalRows++;

                    if (count($row) < 4) {
                        $failedRows++;
                        $errorLog[] = "Row {$totalRows}: Insufficient columns.";
                        continue;
                    }

                    // Map columns
                    $data = [];
                    foreach ($headers as $idx => $header) {
                        $data[$header] = trim($row[$idx] ?? '');
                    }

                    $matricNumber = $data['matric_number'] ?? $data['matric'] ?? ($row[0] ?? '');
                    $courseCode   = strtoupper(trim($data['course_code'] ?? $row[1] ?? ''));
                    $caScore      = $data['ca_score']   ?? $row[2] ?? '';
                    $examScore    = $data['exam_score'] ?? $row[3] ?? '';

                    // Validate required fields
                    if (empty($matricNumber) || empty($courseCode)) {
                        $failedRows++;
                        $errorLog[] = "Row {$totalRows}: Missing matric number or course code.";
                        continue;
                    }

                    if (!is_numeric($caScore) || !is_numeric($examScore)) {
                        $failedRows++;
                        $errorLog[] = "Row {$totalRows} ({$matricNumber}): Invalid score format.";
                        continue;
                    }

                    $caScore   = (float) $caScore;
                    $examScore = (float) $examScore;

                    // Fetch student
                    $student = dbFetchOne($pdo,
                        "SELECT id FROM users WHERE matric_number = ? AND role = 'student'",
                        [$matricNumber]
                    );
                    if (!$student) {
                        $failedRows++;
                        $errorLog[] = "Row {$totalRows}: Student '{$matricNumber}' not found.";
                        continue;
                    }

                    // Fetch course
                    $course = dbFetchOne($pdo,
                        "SELECT id FROM courses WHERE course_code = ?",
                        [$courseCode]
                    );
                    if (!$course) {
                        $failedRows++;
                        $errorLog[] = "Row {$totalRows}: Course '{$courseCode}' not found.";
                        continue;
                    }

                    // Fetch course assignment for this semester
                    $assignment = dbFetchOne($pdo,
                        "SELECT ca.id, ca.ca_max, ca.exam_max, c.credit_units
                         FROM course_assignments ca
                         JOIN courses c ON ca.course_id = c.id
                         WHERE ca.course_id = ? AND ca.semester_id = ?",
                        [$course['id'], $semesterId]
                    );
                    if (!$assignment) {
                        $failedRows++;
                        $errorLog[] = "Row {$totalRows}: No assignment found for {$courseCode} in selected semester.";
                        continue;
                    }

                    // Boundary check
                    if ($caScore < 0 || $caScore > $assignment['ca_max'] ||
                        $examScore < 0 || $examScore > $assignment['exam_max']) {
                        $failedRows++;
                        $errorLog[] = "Row {$totalRows} ({$matricNumber}, {$courseCode}): Score out of range.";
                        continue;
                    }

                    // Compute grade
                    $totalScore = $caScore + $examScore;
                    $grade      = computeGrade($totalScore);
                    $gradePoint = computeGradePoint($grade);
                    $qualPoint  = computeQualityPoint($gradePoint, (int) $assignment['credit_units']);

                    // Check existing result
                    $existing = dbFetchOne($pdo,
                        "SELECT id, status FROM results
                         WHERE student_id = ? AND course_id = ? AND semester_id = ?",
                        [$student['id'], $course['id'], $semesterId]
                    );

                    try {
                        if ($existing) {
                            if (in_array($existing['status'], ['final_approved', 'hod_approved'])) {
                                $failedRows++;
                                $errorLog[] = "Row {$totalRows} ({$matricNumber}, {$courseCode}): Result is already approved and cannot be overwritten.";
                                continue;
                            }
                            dbExecute($pdo,
                                "UPDATE results
                                 SET ca_score=?, exam_score=?, total_score=?, grade=?, grade_point=?,
                                     quality_point=?, status='submitted', submitted_at=NOW()
                                 WHERE id=?",
                                [$caScore, $examScore, $totalScore, $grade, $gradePoint,
                                 $qualPoint, $existing['id']]
                            );
                        } else {
                            dbInsert($pdo,
                                "INSERT INTO results
                                    (student_id, course_id, semester_id, assignment_id, ca_score,
                                     exam_score, total_score, grade, grade_point, credit_units,
                                     quality_point, status, submitted_at)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,'submitted', NOW())",
                                [$student['id'], $course['id'], $semesterId, $assignment['id'],
                                 $caScore, $examScore, $totalScore, $grade, $gradePoint,
                                 (int) $assignment['credit_units'], $qualPoint]
                            );
                        }
                        $successRows++;
                    } catch (PDOException $rowErr) {
                        $failedRows++;
                        $errorLog[] = "Row {$totalRows} ({$matricNumber}): DB error - " . $rowErr->getMessage();
                    }
                }

                fclose($handle);
                $pdo->commit();

                // Update batch_upload record
                dbExecute($pdo,
                    "UPDATE batch_uploads
                     SET total_rows=?, successful_rows=?, failed_rows=?, error_log=?, status='completed'
                     WHERE id=?",
                    [$totalRows, $successRows, $failedRows,
                     !empty($errorLog) ? implode("\n", $errorLog) : null, $batchId]
                );

                writeAuditLog($pdo, $hodId, 'batch_upload', 'batch_uploads', $batchId, null,
                    ['total' => $totalRows, 'success' => $successRows, 'failed' => $failedRows]
                );

                $uploadSuccess = $failedRows === 0;
                $uploadMessage = "Upload complete. {$successRows} rows imported successfully. {$failedRows} rows failed.";
                $uploadReport  = [
                    'total'   => $totalRows,
                    'success' => $successRows,
                    'failed'  => $failedRows,
                    'errors'  => $errorLog,
                ];
            }
        }
    }
}

// ============================================================
// SECTION 2: FETCH AVAILABLE SEMESTERS (for dropdown)
// ============================================================
$allSemesters = [];
try {
    $allSemesters = dbFetchAll($pdo,
        "SELECT s.id, s.semester_name, s.is_current, ac.session_name
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         ORDER BY ac.id DESC, s.id DESC"
    );
} catch (PDOException $e) {
    $allSemesters = [];
}

// ============================================================
// SECTION 3: FETCH RECENT UPLOADS
// ============================================================
$recentUploads = [];
try {
    $recentUploads = dbFetchAll($pdo,
        "SELECT bu.id, bu.file_name, bu.status, bu.total_rows,
                bu.successful_rows, bu.failed_rows, bu.uploaded_at,
                s.semester_name, ac.session_name
         FROM batch_uploads bu
         JOIN semesters s ON bu.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         ORDER BY bu.uploaded_at DESC
         LIMIT 10"
    );
} catch (PDOException $e) {
    $recentUploads = [];
}

$pageTitle      = 'Batch Upload';
$pageBreadcrumb = 'HOD / Batch Upload';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Upload &mdash; CSCResultPortal</title>
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
                    <i class="fa-solid fa-file-arrow-up me-2 text-green"></i>
                    Batch Score Upload
                </h1>
                <p class="page-header-subtitle">
                    Upload student scores in bulk using a CSV file. Results are processed automatically.
                </p>
                <hr class="page-header-divider">
            </div>

            <!-- Upload Result -->
            <?php if (!empty($uploadMessage)): ?>
                <div class="portal-alert <?= $uploadSuccess ? 'success' : ($uploadReport && $uploadReport['success'] > 0 ? 'warning' : 'danger') ?> mb-4">
                    <i class="fa-solid fa-file-arrow-up"></i>
                    <div><?= htmlspecialchars($uploadMessage) ?></div>
                </div>
            <?php endif; ?>

            <!-- Error Log -->
            <?php if ($uploadReport && !empty($uploadReport['errors'])): ?>
                <div class="content-card mb-4">
                    <div class="content-card-header">
                        <div class="content-card-title" style="color:#dc3545;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Upload Error Log (<?= count($uploadReport['errors']) ?> issues)
                        </div>
                    </div>
                    <div class="content-card-body">
                        <div class="upload-error-log">
                            <?php foreach ($uploadReport['errors'] as $err): ?>
                                <div class="upload-error-item">
                                    <i class="fa-solid fa-circle-xmark text-danger me-2"></i>
                                    <?= htmlspecialchars($err) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-4">

                <!-- Upload Form -->
                <div class="col-lg-5">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-upload"></i>
                                Upload CSV File
                            </div>
                        </div>
                        <div class="content-card-body">

                            <!-- CSV Format Guide -->
                            <div class="portal-alert info mb-4">
                                <i class="fa-solid fa-info-circle"></i>
                                <div>
                                    <strong>Required CSV Columns (Header Row):</strong><br>
                                    <code>matric_number, course_code, ca_score, exam_score</code>
                                    <br><small>One student result per row. First row must be the header.</small>
                                </div>
                            </div>

                            <!-- Sample Download -->
                            <div class="mb-4">
                                <a href="#" onclick="downloadSampleCSV(); return false;"
                                   class="btn-portal-outline btn-portal-sm">
                                    <i class="fa-solid fa-download"></i>
                                    Download Sample CSV
                                </a>
                            </div>

                            <form method="POST" action="" enctype="multipart/form-data" id="uploadForm">
                                <input type="hidden" name="upload_action" value="1">

                                <div class="mb-3">
                                    <label class="portal-form-label">Target Semester *</label>
                                    <select name="semester_id" class="portal-form-control portal-select" required>
                                        <option value="">-- Select Semester --</option>
                                        <?php foreach ($allSemesters as $sem): ?>
                                            <option value="<?= (int) $sem['id'] ?>">
                                                <?= htmlspecialchars($sem['session_name']) ?>
                                                &mdash;
                                                <?= htmlspecialchars($sem['semester_name']) ?>
                                                <?= ((int) $sem['is_current'] === 1) ? ' (Current)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-4">
                                    <label class="portal-form-label">CSV File * (Max 5MB)</label>
                                    <div class="upload-dropzone" id="uploadDropzone"
                                         onclick="document.getElementById('csvFile').click()">
                                        <i class="fa-solid fa-file-csv upload-dropzone-icon"></i>
                                        <div class="upload-dropzone-text">
                                            Click to select CSV file<br>
                                            <span class="text-muted-sm">or drag and drop here</span>
                                        </div>
                                        <div id="selectedFileName" class="upload-selected-file d-none">
                                            <i class="fa-solid fa-file-check text-green me-2"></i>
                                            <span id="fileNameDisplay"></span>
                                        </div>
                                    </div>
                                    <input type="file" name="csv_file" id="csvFile"
                                           accept=".csv,.txt" style="display:none;" required>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn-portal-primary" id="uploadBtn">
                                        <i class="fa-solid fa-cloud-arrow-up"></i>
                                        Upload &amp; Process
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Upload History -->
                <div class="col-lg-7">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                Upload History
                            </div>
                        </div>
                        <div style="overflow-x:auto;">
                            <?php if (count($recentUploads) > 0): ?>
                                <table class="portal-table">
                                    <thead>
                                        <tr>
                                            <th>File</th>
                                            <th>Semester</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-center">Passed</th>
                                            <th class="text-center">Failed</th>
                                            <th>Status</th>
                                            <th>Uploaded</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentUploads as $up): ?>
                                            <tr>
                                                <td class="fw-600 text-muted-sm" style="max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <?= htmlspecialchars($up['file_name']) ?>
                                                </td>
                                                <td class="text-muted-sm">
                                                    <?= htmlspecialchars($up['session_name']) ?>
                                                    <br><?= htmlspecialchars($up['semester_name']) ?>
                                                </td>
                                                <td class="text-center"><?= (int) $up['total_rows'] ?></td>
                                                <td class="text-center fw-700 text-green">
                                                    <?= (int) $up['successful_rows'] ?>
                                                </td>
                                                <td class="text-center fw-700 <?= (int) $up['failed_rows'] > 0 ? 'text-danger' : '' ?>">
                                                    <?= (int) $up['failed_rows'] ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?= $up['status'] === 'completed' ? 'final_approved' : ($up['status'] === 'failed' ? 'flagged' : 'submitted') ?>">
                                                        <?= ucfirst($up['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-muted-sm">
                                                    <?= date('d M Y, g:ia', strtotime($up['uploaded_at'])) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-file-arrow-up"></i>
                                    <p>No batch uploads yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div><!-- /.row -->

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/hod.js"></script>
</body>
</html>