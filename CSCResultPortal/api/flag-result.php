<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../mail/mailer.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// METHOD & AUTH
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.', 'code' => 405]);
    exit();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'advisor'
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Only Course Advisors can flag results.', 'code' => 401]);
    exit();
}

$advisorId = (int) $_SESSION['user_id'];

// ============================================================
// PARSE INPUT
// ============================================================
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?? [];

$assignmentId = isset($data['assignment_id']) ? (int) $data['assignment_id'] : 0;
$flagReason   = trim($data['flag_reason'] ?? '');

if ($assignmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid assignment ID.', 'code' => 400]);
    exit();
}

if (empty($flagReason) || strlen($flagReason) < 10) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Flag reason is required (minimum 10 characters).',
        'code'    => 400
    ]);
    exit();
}

try {
    $pdo->beginTransaction();

    // ============================================================
    // FETCH ASSIGNMENT + LECTURER
    // ============================================================
        $advLevel   = getAdvisorLevel($pdo, $advisorId);
    $assignment = dbFetchOne($pdo,
        "SELECT ca.*, c.course_code, c.course_title,
                u.id AS lecturer_id, u.full_name AS lecturer_name, u.email AS lecturer_email
         FROM course_assignments ca
         JOIN courses c ON ca.course_id = c.id
         JOIN users u ON ca.lecturer_id = u.id
         WHERE ca.id = ?" . ($advLevel ? " AND c.level = ?" : ""),
        $advLevel ? [$assignmentId, $advLevel] : [$assignmentId]
    );

    if (!is_array($assignment)) {
                throw new Exception('Course assignment not found, or it is not for your level.');
    }

    // ============================================================
    // FLAG ALL SUBMITTED RESULTS
    // ============================================================
    $submittedResults = dbFetchAll($pdo,
        "SELECT id FROM results WHERE assignment_id = ? AND status = 'submitted'",
        [$assignmentId]
    );

    if (empty($submittedResults)) {
        throw new Exception('No submitted results found to flag.');
    }

    $flaggedCount = 0;
    foreach ($submittedResults as $res) {
        // Update status
        dbExecute($pdo,
            "UPDATE results 
             SET status = 'flagged', flagged_at = NOW(), flagged_by = ?
             WHERE id = ?",
            [$advisorId, $res['id']]
        );

        // Insert flag record
        dbInsert($pdo,
            "INSERT INTO result_flags (result_id, assignment_id, flagged_by, flag_reason) 
             VALUES (?, ?, ?, ?)",
            [$res['id'], $assignmentId, $advisorId, $flagReason]
        );

        $flaggedCount++;
    }

    // Reset assignment status so lecturer can re-enter
    dbExecute($pdo,
        "UPDATE course_assignments SET scores_submitted = 0 WHERE id = ?",
        [$assignmentId]
    );

    // ============================================================
    // EMAIL LECTURER
    // ============================================================
    $advisor = dbFetchOne($pdo, "SELECT full_name FROM users WHERE id = ?", [$advisorId]);

    sendResultFlaggedEmail(
        (int) $assignment['lecturer_id'],
        $assignment['lecturer_email'],
        $assignment['lecturer_name'],
        $advisor['full_name'] ?? 'Course Advisor',
        $assignment['course_code'],
        $assignment['course_title'],
        $flagReason,
        null,
        $advisorId
    );

    writeAuditLog($pdo, $advisorId, 'flag_result', 'results', $assignmentId,
        null, ['reason' => $flagReason, 'course' => $assignment['course_code'], 'flagged_count' => $flaggedCount, 'api' => true]);

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'message'       => "Successfully flagged {$flaggedCount} result(s) back to {$assignment['lecturer_name']}.",
        'flagged_count' => $flaggedCount,
        'course_code'   => $assignment['course_code'],
        'code'          => 200
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    logDatabaseError('Flag result API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'code'    => 500
    ]);
}