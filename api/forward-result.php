<?php

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
    ($_SESSION['role'] ?? '') !== 'advisor'
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Only Course Advisors can forward results.', 'code' => 401]);
    exit();
}

$advisorId = (int) $_SESSION['user_id'];

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

    // Fetch assignment
        $advLevel   = getAdvisorLevel($pdo, $advisorId);
    $assignment = dbFetchOne($pdo,
        "SELECT ca.*, c.course_code, c.course_title,
                s.semester_name, ac.session_name
         FROM course_assignments ca
         JOIN courses c ON ca.course_id = c.id
         JOIN semesters s ON ca.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE ca.id = ?" . ($advLevel ? " AND c.level = ?" : ""),
        $advLevel ? [$assignmentId, $advLevel] : [$assignmentId]
    );

    if (!is_array($assignment)) {
                throw new Exception('Course assignment not found, or it is not for your level.');
    }

    // Update all submitted results to forwarded
    $updated = dbExecute($pdo,
        "UPDATE results 
         SET status = 'forwarded', forwarded_at = NOW(), forwarded_by = ?
         WHERE assignment_id = ? AND status = 'submitted'",
        [$advisorId, $assignmentId]
    );

    if ($updated === 0) {
        throw new Exception('No submitted results available to forward.');
    }

    // Email HOD
    $hod     = dbFetchOne($pdo, "SELECT id, full_name, email FROM users WHERE role = 'hod' AND is_active = 1 LIMIT 1");
    $advisor = dbFetchOne($pdo, "SELECT full_name FROM users WHERE id = ?", [$advisorId]);

    if (is_array($hod)) {
        sendResultForwardedToHODEmail(
            (int) $hod['id'],
            $hod['email'],
            $hod['full_name'],
            $advisor['full_name'] ?? 'Course Advisor',
            $assignment['course_code'],
            $assignment['course_title'],
            $assignment['semester_name'],
            $assignment['session_name'],
            null,
            $advisorId
        );
    }

    writeAuditLog($pdo, $advisorId, 'forward_result', 'results', $assignmentId,
        null, ['course' => $assignment['course_code'], 'forwarded_count' => $updated, 'api' => true]);

    $pdo->commit();

    echo json_encode([
        'success'         => true,
        'message'         => "Successfully forwarded {$updated} result(s) to the HOD.",
        'forwarded_count' => $updated,
        'course_code'     => $assignment['course_code'],
        'code'            => 200
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    logDatabaseError('Forward result API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'code'    => 500
    ]);
}