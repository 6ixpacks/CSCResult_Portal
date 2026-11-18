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
    ($_SESSION['role'] ?? '') !== 'student'
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Only students can register carryovers.', 'code' => 401]);
    exit();
}

$studentId = (int) $_SESSION['user_id'];

$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?? [];

$courseIds = isset($data['course_ids']) && is_array($data['course_ids']) 
    ? array_map('intval', $data['course_ids']) 
    : [];
$reason = trim($data['reason'] ?? '');

if (empty($courseIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No courses selected.', 'code' => 400]);
    exit();
}

try {
    // Get current semester
    $currentSem = dbFetchOne($pdo,
        "SELECT s.id, s.semester_name, ac.session_name
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE s.is_current = 1 AND ac.is_current = 1 LIMIT 1"
    );

    if (!is_array($currentSem)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'No active semester. Contact HOD office.',
            'code'    => 400
        ]);
        exit();
    }

    $semesterId = (int) $currentSem['id'];

    $pdo->beginTransaction();

    $successCount = 0;
    $skippedCount = 0;
    $errors       = [];

    foreach ($courseIds as $courseId) {
        // Verify student actually failed this course
        $failedResult = dbFetchOne($pdo,
            "SELECT id FROM results 
             WHERE student_id = ? AND course_id = ? 
             AND grade = 'F' AND status = 'final_approved' 
             ORDER BY id DESC LIMIT 1",
            [$studentId, $courseId]
        );

        if (!is_array($failedResult)) {
            $errors[] = "Course ID {$courseId}: No failed record found.";
            $skippedCount++;
            continue;
        }

        // Check for duplicate registration
        $existing = dbFetchOne($pdo,
            "SELECT id FROM carryover_registrations 
             WHERE student_id = ? AND course_id = ? AND semester_id = ?",
            [$studentId, $courseId, $semesterId]
        );

        if (is_array($existing)) {
            $errors[] = "Course ID {$courseId}: Already registered for carryover.";
            $skippedCount++;
            continue;
        }

        // Insert
        dbInsert($pdo,
            "INSERT INTO carryover_registrations 
                (student_id, course_id, semester_id, original_result_id, reason, status) 
             VALUES (?,?,?,?,?, 'pending')",
            [$studentId, $courseId, $semesterId, $failedResult['id'], 
             !empty($reason) ? $reason : null]
        );

        $successCount++;
    }

    if ($successCount === 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success'       => false,
            'message'       => 'No carryovers registered.',
            'errors'        => $errors,
            'skipped_count' => $skippedCount,
            'code'          => 400
        ]);
        exit();
    }

    // Notify advisor
        // Notify the advisor for this student's level (or an advisor with no level set)
    $advisor = dbFetchOne($pdo,
        "SELECT u.id, u.full_name, u.email
         FROM users u
         WHERE u.role = 'advisor' AND u.is_active = 1
           AND (u.advisor_level = (SELECT level FROM student_profiles WHERE user_id = ?)
                OR u.advisor_level IS NULL)
         ORDER BY u.advisor_level IS NULL ASC
         LIMIT 1",
        [$studentId]
    );

    $student = dbFetchOne($pdo,
        "SELECT full_name, matric_number FROM users WHERE id = ?",
        [$studentId]
    );

    if (is_array($advisor) && is_array($student)) {
        $firstCourse = dbFetchOne($pdo,
            "SELECT course_code, course_title FROM courses WHERE id = ?",
            [$courseIds[0]]
        );

        if (is_array($firstCourse)) {
            sendCarryoverSubmissionEmail(
                (int) $advisor['id'],
                $advisor['email'],
                $advisor['full_name'],
                $student['full_name'],
                $student['matric_number'] ?? '',
                $firstCourse['course_code'],
                $successCount > 1 
                    ? $firstCourse['course_title'] . ' (and ' . ($successCount - 1) . ' more)' 
                    : $firstCourse['course_title'],
                null,
                $studentId
            );
        }
    }

    writeAuditLog($pdo, $studentId, 'carryover_registration_submitted', 
        'carryover_registrations', null, null,
        ['courses_count' => $successCount, 'api' => true]);

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'message'       => "{$successCount} carryover course(s) registered successfully. Advisor notified.",
        'success_count' => $successCount,
        'skipped_count' => $skippedCount,
        'errors'        => $errors,
        'code'          => 200
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    logDatabaseError('Register carryover API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred.',
        'code'    => 500
    ]);
}