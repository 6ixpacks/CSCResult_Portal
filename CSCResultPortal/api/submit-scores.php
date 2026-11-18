<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../mail/mailer.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// METHOD & AUTH CHECKS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.', 'code' => 405]);
    exit();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'lecturer'
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.', 'code' => 401]);
    exit();
}

$lecturerId = (int) $_SESSION['user_id'];

// ============================================================
// PARSE INPUT
// ============================================================
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?? [];

$assignmentId = isset($data['assignment_id']) ? (int) $data['assignment_id'] : 0;
$action       = $data['action']  ?? 'save_draft';
$scoresInput  = $data['scores']  ?? [];

if (!in_array($action, ['save_draft', 'submit_scores'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.', 'code' => 400]);
    exit();
}

if ($assignmentId <= 0 || empty($scoresInput)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required data.', 'code' => 400]);
    exit();
}

try {
    // ============================================================
    // VERIFY OWNERSHIP OF ASSIGNMENT
    // ============================================================
    $assignment = dbFetchOne($pdo,
        "SELECT ca.*, c.course_code, c.course_title, c.credit_units,
                s.semester_name, ac.session_name
         FROM course_assignments ca
         JOIN courses c ON ca.course_id = c.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE ca.id = ? AND ca.lecturer_id = ?",
        [$assignmentId, $lecturerId]
    );

    if (!is_array($assignment)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not own this course assignment.',
            'code'    => 403
        ]);
        exit();
    }

    $caMax   = (int) ($assignment['ca_max']   ?? 30);
    $labMax  = (int) ($assignment['lab_max']  ?? 0);
    $examMax = (int) ($assignment['exam_max'] ?? 70);
    $hasLab  = (int) ($assignment['has_lab']  ?? 0) === 1;

    // ============================================================
    // PROCESS EACH STUDENT SCORE
    // ============================================================
    $pdo->beginTransaction();
    $processedCount = 0;
    $errors         = [];

    foreach ($scoresInput as $studentId => $scoreRow) {
        $studentId = (int) $studentId;
        if ($studentId <= 0) continue;

        // Verify student is registered
        $registered = dbCount($pdo,
            "SELECT COUNT(*) FROM student_course_registrations 
             WHERE student_id = ? AND course_id = ? AND semester_id = ?
               AND status = 'approved'",
            [$studentId, $assignment['course_id'], $assignment['semester_id']]
        );

        if ($registered === 0) {
            $errors[] = "Student ID {$studentId}: Registration not approved by the advisor.";
            continue;
        }

        // Extract scores
        $caScore   = (isset($scoreRow['ca'])   && $scoreRow['ca']   !== '' && is_numeric($scoreRow['ca']))   ? (float) $scoreRow['ca']   : null;
        $labScore  = (isset($scoreRow['lab'])  && $scoreRow['lab']  !== '' && is_numeric($scoreRow['lab']))  ? (float) $scoreRow['lab']  : null;
        $examScore = (isset($scoreRow['exam']) && $scoreRow['exam'] !== '' && is_numeric($scoreRow['exam'])) ? (float) $scoreRow['exam'] : null;

        // Range validation
        if ($caScore !== null && ($caScore < 0 || $caScore > $caMax)) {
            $errors[] = "Student ID {$studentId}: CA score out of range.";
            continue;
        }
        if ($hasLab && $labScore !== null && ($labScore < 0 || $labScore > $labMax)) {
            $errors[] = "Student ID {$studentId}: LAB score out of range.";
            continue;
        }
        if ($examScore !== null && ($examScore < 0 || $examScore > $examMax)) {
            $errors[] = "Student ID {$studentId}: Exam score out of range.";
            continue;
        }

        // Compute grade if all required scores are present
        $totalScore = null;
        $grade      = null;
        $gradePoint = null;
        $qualPoint  = null;

        $canCompute = $hasLab
            ? ($caScore !== null && $labScore !== null && $examScore !== null)
            : ($caScore !== null && $examScore !== null);

        if ($canCompute) {
            $totalScore = $caScore + ($labScore ?? 0) + $examScore;
            $grade      = computeGrade($totalScore);
            $gradePoint = computeGradePoint($grade);
            $qualPoint  = computeQualityPoint($gradePoint, (int) $assignment['credit_units']);
        }

        $targetStatus = ($action === 'submit_scores') ? 'submitted' : 'draft';

        // Check existing
        $existing = dbFetchOne($pdo,
            "SELECT id, status FROM results 
             WHERE student_id = ? AND course_id = ? AND semester_id = ? AND assignment_id = ?",
            [$studentId, $assignment['course_id'], $assignment['semester_id'], $assignment['id']]
        );

        if (is_array($existing)) {
            // Skip locked results
            if (in_array($existing['status'], ['hod_approved', 'final_approved'])) {
                $errors[] = "Student ID {$studentId}: Result already approved (cannot modify).";
                continue;
            }

            // Resolve flag if resubmitting
            if ($existing['status'] === 'flagged' && $action === 'submit_scores') {
                dbExecute($pdo,
                    "UPDATE result_flags SET is_resolved = 1, resolved_at = NOW() 
                     WHERE result_id = ? AND is_resolved = 0",
                    [$existing['id']]
                );
            }

            dbExecute($pdo,
                "UPDATE results 
                 SET ca_score=?, lab_score=?, exam_score=?, total_score=?, 
                     grade=?, grade_point=?, quality_point=?, status=?, submitted_at=NOW()
                 WHERE id=?",
                [$caScore, $labScore, $examScore, $totalScore, $grade, $gradePoint, 
                 $qualPoint, $targetStatus, $existing['id']]
            );
        } else {
            dbInsert($pdo,
                "INSERT INTO results 
                    (student_id, course_id, semester_id, assignment_id, 
                     ca_score, lab_score, exam_score, total_score, grade, grade_point, 
                     credit_units, quality_point, status, submitted_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())",
                [$studentId, $assignment['course_id'], $assignment['semester_id'], $assignment['id'],
                 $caScore, $labScore, $examScore, $totalScore, $grade, $gradePoint,
                 (int) $assignment['credit_units'], $qualPoint, $targetStatus]
            );
        }
        $processedCount++;
    }

    // ============================================================
    // POST-PROCESSING FOR SUBMIT ACTION
    // ============================================================
    if ($action === 'submit_scores' && $processedCount > 0) {
        dbExecute($pdo,
            "UPDATE course_assignments SET scores_submitted = 1, submitted_at = NOW() WHERE id = ?",
            [$assignment['id']]
        );

        // Notify advisor
        $advisor  = dbFetchOne($pdo, "SELECT id, full_name, email FROM users WHERE role = 'advisor' AND is_active = 1 LIMIT 1");
        $lecturer = dbFetchOne($pdo, "SELECT full_name FROM users WHERE id = ?", [$lecturerId]);

        if (is_array($advisor) && is_array($lecturer)) {
            sendLecturerSubmissionEmail(
                (int) $advisor['id'],
                $advisor['email'],
                $advisor['full_name'],
                $lecturer['full_name'],
                $assignment['course_code'],
                $assignment['course_title'],
                $assignment['semester_name'],
                $assignment['session_name'],
                $assignment['id'],
                $lecturerId
            );
        }

        writeAuditLog($pdo, $lecturerId, 'submit_scores', 'course_assignments', 
            $assignment['id'], null, ['count' => $processedCount, 'api' => true]);
    } else {
        writeAuditLog($pdo, $lecturerId, 'save_scores_draft', 'course_assignments', 
            $assignment['id'], null, ['count' => $processedCount, 'api' => true]);
    }

    $pdo->commit();

    echo json_encode([
        'success'         => true,
        'message'         => $action === 'submit_scores' 
            ? "Successfully submitted scores for {$processedCount} student(s)." 
            : "Draft saved for {$processedCount} student(s).",
        'processed_count' => $processedCount,
        'errors'          => $errors,
        'code'            => 200
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    logDatabaseError('Submit scores API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred.',
        'code'    => 500
    ]);
}