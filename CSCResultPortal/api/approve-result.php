<?php
// ============================================================
// CSCResultPortal - HOD Approve Result API
// File: api/approve-result.php
// Purpose: AJAX endpoint for HOD to approve forwarded results.
//          Sets status to hod_approved.
// Method: POST
// Body:   { "assignment_id": 5 }
// Access: HOD only
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../mail/mailer.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.', 'code' => 405]);
    exit();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'hod'
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Only HOD can approve results.', 'code' => 401]);
    exit();
}

$hodId = (int) $_SESSION['user_id'];

$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?? [];

$assignmentId = isset($data['assignment_id']) ? (int) $data['assignment_id'] : 0;

if ($assignmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid assignment ID.', 'code' => 400]);
    exit();
}

try {
    $pdo->beginTransaction();

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

    // Send confirmation email to HOD
    if (is_array($courseInfo)) {
        $hodUser = dbFetchOne($pdo, "SELECT full_name, email FROM users WHERE id = ?", [$hodId]);
        if (is_array($hodUser)) {
            sendHODApprovalConfirmationEmail(
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

    writeAuditLog($pdo, $hodId, 'approve_result', 'results', $assignmentId,
        null, ['course_code' => $courseInfo['course_code'] ?? '', 'approved_count' => $updated, 'api' => true]);

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'message'        => "Successfully approved {$updated} result(s) for {$courseInfo['course_code']}.",
        'approved_count' => $updated,
        'course_code'    => $courseInfo['course_code'] ?? '',
        'code'           => 200
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    logDatabaseError('Approve result API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'code'    => 500
    ]);
}