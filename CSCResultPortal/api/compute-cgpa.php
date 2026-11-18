<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hod') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $semester_id = (int)($_POST['semester_id'] ?? 0);
    if ($semester_id < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid semester.']);
        exit;
    }

    // ── Get all students who have approved results this semester ──
    $stmt = $pdo->prepare("
        SELECT DISTINCT sr.student_id 
        FROM student_results sr
        JOIN result_approvals ra ON sr.result_id = ra.result_id
        WHERE sr.semester_id = ? 
          AND ra.status = 'hod_approved'
    ");
    $stmt->execute([$semester_id]);
    $students = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($students)) {
        echo json_encode(['success' => false, 'message' => 'No approved results found for this semester.']);
        exit;
    }

    $computed = 0;
    $errors = [];

    $pdo->beginTransaction();

    foreach ($students as $student_id) {

        // ── Semester GPA ──
        $stmt = $pdo->prepare("
            SELECT SUM(c.credit_units * sr.grade_point) AS total_qp,
                   SUM(c.credit_units) AS total_units
            FROM student_results sr
            JOIN courses c ON sr.course_id = c.course_id
            JOIN result_approvals ra ON sr.result_id = ra.result_id
            WHERE sr.student_id = ? 
              AND sr.semester_id = ?
              AND ra.status = 'hod_approved'
              AND sr.grade IS NOT NULL
        ");
        $stmt->execute([$student_id, $semester_id]);
        $sem = $stmt->fetch();

        $sem_qp    = (float)($sem['total_qp'] ?? 0);
        $sem_units = (int)($sem['total_units'] ?? 0);

        // ★ BUG FIX: Prevent division by zero ★
        if ($sem_units === 0) {
            $sem_gpa = 0.00;
        } else {
            $sem_gpa = round($sem_qp / $sem_units, 2);
        }

        // ── Cumulative CGPA (all semesters) ──
        $stmt = $pdo->prepare("
            SELECT SUM(c.credit_units * sr.grade_point) AS cum_qp,
                   SUM(c.credit_units) AS cum_units
            FROM student_results sr
            JOIN courses c ON sr.course_id = c.course_id
            JOIN result_approvals ra ON sr.result_id = ra.result_id
            WHERE sr.student_id = ?
              AND ra.status = 'hod_approved'
              AND sr.grade IS NOT NULL
        ");
        $stmt->execute([$student_id]);
        $cum = $stmt->fetch();

        $cum_qp    = (float)($cum['cum_qp'] ?? 0);
        $cum_units = (int)($cum['cum_units'] ?? 0);

        // ★ BUG FIX: Prevent division by zero ★
        if ($cum_units === 0) {
            $cgpa = 0.00;
        } else {
            $cgpa = round($cum_qp / $cum_units, 2);
        }

        // ── Degree Classification ──
        if ($cgpa >= 4.50)      $degree_class = 'First Class Honours';
        elseif ($cgpa >= 3.50)  $degree_class = 'Second Class Upper Division';
        elseif ($cgpa >= 2.40)  $degree_class = 'Second Class Lower Division';
        elseif ($cgpa >= 1.50)  $degree_class = 'Third Class';
        elseif ($cgpa >= 1.00)  $degree_class = 'Pass';
        else                    $degree_class = 'Fail';

        // ── Upsert CGPA Record ──
        $stmt = $pdo->prepare("
            INSERT INTO cgpa_records 
                (student_id, semester_id, semester_gpa, cumulative_cgpa, total_credit_units, total_quality_points, degree_class)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                semester_gpa = VALUES(semester_gpa),
                cumulative_cgpa = VALUES(cumulative_cgpa),
                total_credit_units = VALUES(total_credit_units),
                total_quality_points = VALUES(total_quality_points),
                degree_class = VALUES(degree_class)
        ");
        $stmt->execute([$student_id, $semester_id, $sem_gpa, $cgpa, $cum_units, $cum_qp, $degree_class]);

        // ── Auto-Flag Carryovers (Grade F) ──
        $stmt = $pdo->prepare("
            SELECT sr.course_id 
            FROM student_results sr
            JOIN result_approvals ra ON sr.result_id = ra.result_id
            WHERE sr.student_id = ? 
              AND sr.semester_id = ?
              AND sr.grade = 'F'
              AND ra.status = 'hod_approved'
        ");
        $stmt->execute([$student_id, $semester_id]);
        $carryovers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($carryovers as $course_id) {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO carryover_courses (student_id, course_id, semester_id, status)
                VALUES (?, ?, ?, 'pending')
            ");
            $stmt->execute([$student_id, $course_id, $semester_id]);
        }

        $computed++;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "CGPA computed successfully for {$computed} student(s).",
        'count' => $computed
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("CGPA Computation Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'CGPA computation failed: ' . $e->getMessage()]);
}