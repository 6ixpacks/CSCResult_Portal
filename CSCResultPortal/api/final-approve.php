<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../mail/mailer.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Longer timeout for bulk operations
set_time_limit(300);

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
    echo json_encode(['success' => false, 'message' => 'Only HOD can grant final approval.', 'code' => 401]);
    exit();
}

$hodId = (int) $_SESSION['user_id'];

$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?? [];

$semesterId = isset($data['semester_id']) ? (int) $data['semester_id'] : 0;

if ($semesterId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid semester ID.', 'code' => 400]);
    exit();
}

try {
    $pdo->beginTransaction();

    // Update all HOD-approved results to final approved
    $updated = dbExecute($pdo,
        "UPDATE results 
         SET status = 'final_approved', final_approved_at = NOW(), final_approved_by = ?
         WHERE semester_id = ? AND status = 'hod_approved'",
        [$hodId, $semesterId]
    );

    if ($updated === 0) {
        throw new Exception('No HOD-approved results found for final approval.');
    }

    // ============================================================
    // COMPUTE AND STORE SEMESTER GPA + CGPA FOR EACH STUDENT
    // ============================================================
    $studentsInSemester = dbFetchAll($pdo,
        "SELECT DISTINCT student_id FROM results 
         WHERE semester_id = ? AND status = 'final_approved'",
        [$semesterId]
    );

    $processedStudents = 0;
    foreach ($studentsInSemester as $stu) {
        $stuResults = dbFetchAll($pdo,
            "SELECT grade_point, credit_units, quality_point 
             FROM results 
             WHERE student_id = ? AND semester_id = ? AND status = 'final_approved'",
            [$stu['student_id'], $semesterId]
        );

        if (empty($stuResults)) continue;

        // Semester GPA
        $semTotalUnits = 0;
        $semTotalQP    = 0.0;
        foreach ($stuResults as $sr) {
            $semTotalUnits += (int) ($sr['credit_units'] ?? 0);
            $semTotalQP    += (float) ($sr['quality_point'] ?? 0);
        }
        $semGPA = $semTotalUnits > 0 ? round($semTotalQP / $semTotalUnits, 2) : 0.00;

        // Cumulative GPA (all approved results)
        $allResults = dbFetchAll($pdo,
            "SELECT grade_point, credit_units FROM results 
             WHERE student_id = ? AND status = 'final_approved'",
            [$stu['student_id']]
        );

        $cumUnits = 0;
        $cumQP    = 0.0;
        foreach ($allResults as $ar) {
            $cumUnits += (int) ($ar['credit_units'] ?? 0);
            $cumQP    += (float) ($ar['grade_point'] ?? 0) * (int) ($ar['credit_units'] ?? 0);
        }
        $cgpa = $cumUnits > 0 ? round($cumQP / $cumUnits, 2) : 0.00;

        // Upsert semester_gpa
        $existingGPA = dbFetchOne($pdo,
            "SELECT id FROM semester_gpa WHERE student_id = ? AND semester_id = ?",
            [$stu['student_id'], $semesterId]
        );

        if (is_array($existingGPA)) {
            dbExecute($pdo,
                "UPDATE semester_gpa 
                 SET gpa=?, cgpa=?, total_units=?, total_quality_points=? 
                 WHERE student_id=? AND semester_id=?",
                [$semGPA, $cgpa, $semTotalUnits, $semTotalQP, $stu['student_id'], $semesterId]
            );
        } else {
            dbInsert($pdo,
                "INSERT INTO semester_gpa 
                    (student_id, semester_id, gpa, cgpa, total_units, total_quality_points) 
                 VALUES (?,?,?,?,?,?)",
                [$stu['student_id'], $semesterId, $semGPA, $cgpa, $semTotalUnits, $semTotalQP]
            );
        }

        // Update student profile cumulative data
        dbExecute($pdo,
            "UPDATE student_profiles 
             SET cgpa=?, total_units=?, degree_class=? 
             WHERE user_id=?",
            [$cgpa, $cumUnits, getDegreeClass($cgpa), $stu['student_id']]
        );

        $processedStudents++;
    }

    // ============================================================
    // NOTIFY ALL STUDENTS AND STAFF BY EMAIL
    // ============================================================
    $semInfo = dbFetchOne($pdo,
        "SELECT s.semester_name, ac.session_name 
         FROM semesters s 
         JOIN academic_sessions ac ON s.session_id = ac.id 
         WHERE s.id = ?",
        [$semesterId]
    );

    $emailResult = ['sent' => 0, 'failed' => 0];
    if (is_array($semInfo)) {
        $allRecipients = dbFetchAll($pdo,
            "SELECT id, full_name, email, role FROM users 
             WHERE is_active = 1 AND role != 'hod'"
        );

        if (!empty($allRecipients)) {
            $emailResult = sendBulkFinalApprovalEmails(
                $allRecipients,
                $semInfo['semester_name'],
                $semInfo['session_name'],
                $hodId
            );
        }
    }

    writeAuditLog($pdo, $hodId, 'final_approve', 'results', $semesterId,
        null, [
            'semester_id'        => $semesterId,
            'results_approved'   => $updated,
            'students_processed' => $processedStudents,
            'emails_sent'        => $emailResult['sent'] ?? 0,
            'api'                => true
        ]);

    $pdo->commit();

    echo json_encode([
        'success'            => true,
        'message'            => "Final departmental approval granted successfully!",
        'results_approved'   => $updated,
        'students_processed' => $processedStudents,
        'emails_sent'        => $emailResult['sent'] ?? 0,
        'emails_failed'      => $emailResult['failed'] ?? 0,
        'semester'           => $semInfo['semester_name'] ?? '',
        'session'            => $semInfo['session_name'] ?? '',
        'code'               => 200
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    logDatabaseError('Final approve API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'code'    => 500
    ]);
}