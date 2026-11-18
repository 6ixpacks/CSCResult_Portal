<?php
session_start();
header('Content-Type: application/json');

// Increase execution time for batch operations
set_time_limit(120);

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

    $pdo->beginTransaction();

    // ── Step 1: Update all advisor-approved results to HOD Approved ──
    $stmt = $pdo->prepare("
        UPDATE result_approvals ra
        JOIN student_results sr ON ra.result_id = sr.result_id
        SET ra.status = 'hod_approved',
            ra.approved_by = ?,
            ra.approval_level = 'hod',
            ra.timestamp = NOW()
        WHERE sr.semester_id = ?
          AND ra.status = 'advisor_approved'
    ");
    $stmt->execute([$_SESSION['user_id'], $semester_id]);
    $approved_count = $stmt->rowCount();

    if ($approved_count === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'No results pending final approval for this semester.']);
        exit;
    }

    // ── Step 2: Trigger CGPA Computation (inline, safe) ──
    // Call the same logic from compute-cgpa.php
    // (Include the CGPA block from Fix A above, or use require_once)
    
    // For simplicity, we'll do a lightweight version here:
    $stmt = $pdo->prepare("
        SELECT DISTINCT sr.student_id 
        FROM student_results sr
        JOIN result_approvals ra ON sr.result_id = ra.result_id
        WHERE sr.semester_id = ? AND ra.status = 'hod_approved'
    ");
    $stmt->execute([$semester_id]);
    $students = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($students as $student_id) {
        $stmt = $pdo->prepare("
            SELECT SUM(c.credit_units * sr.grade_point) AS cum_qp,
                   SUM(c.credit_units) AS cum_units
            FROM student_results sr
            JOIN courses c ON sr.course_id = c.course_id
            JOIN result_approvals ra ON sr.result_id = ra.result_id
            WHERE sr.student_id = ? AND ra.status = 'hod_approved' AND sr.grade IS NOT NULL
        ");
        $stmt->execute([$student_id]);
        $cum = $stmt->fetch();

        $cum_units = (int)($cum['cum_units'] ?? 0);
        $cgpa = $cum_units > 0 ? round((float)$cum['cum_qp'] / $cum_units, 2) : 0.00;

        if ($cgpa >= 4.50)      $dc = 'First Class Honours';
        elseif ($cgpa >= 3.50)  $dc = 'Second Class Upper Division';
        elseif ($cgpa >= 2.40)  $dc = 'Second Class Lower Division';
        elseif ($cgpa >= 1.50)  $dc = 'Third Class';
        elseif ($cgpa >= 1.00)  $dc = 'Pass';
        else                    $dc = 'Fail';

        $stmt = $pdo->prepare("
            INSERT INTO cgpa_records (student_id, semester_id, semester_gpa, cumulative_cgpa, total_credit_units, total_quality_points, degree_class)
            VALUES (?, ?, 0, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE cumulative_cgpa=VALUES(cumulative_cgpa), degree_class=VALUES(degree_class)
        ");
        $stmt->execute([$student_id, $semester_id, $cgpa, $cum_units, (float)$cum['cum_qp'], $dc]);
    }

    $pdo->commit();

    // ── Step 3: Email Notifications (NON-BLOCKING) ──
    // ★ BUG FIX: Do NOT let email failures block the response ★
    $emails_sent = 0;
    $email_errors = 0;

    try {
        // Only attempt email if PHPMailer is configured
        if (file_exists(__DIR__ . '/../config/mail.php')) {
            require_once __DIR__ . '/../config/mail.php';

            // Limit to first 50 emails to prevent timeout
            // In production, use a cron job or queue for bulk emails
            $email_students = array_slice($students, 0, 50);

            foreach ($email_students as $student_id) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT s.full_name, u.username 
                        FROM students s 
                        JOIN users u ON s.user_id = u.user_id 
                        WHERE s.student_id = ?
                    ");
                    $stmt->execute([$student_id]);
                    $student = $stmt->fetch();

                    if ($student && filter_var($student['username'], FILTER_VALIDATE_EMAIL)) {
                        $mail->clearAddresses();
                        $mail->addAddress($student['username'], $student['full_name']);
                        $mail->Subject = 'Your Results Have Been Published — FUTO CSC';
                        $mail->Body = "Dear {$student['full_name']},\n\nYour results for the current semester have been published. Log in to the CSC Result Portal to view your grades and CGPA.\n\nRegards,\nHOD, Computer Science, FUTO.";
                        $mail->send();
                        $emails_sent++;
                    }
                } catch (Exception $emailErr) {
                    $email_errors++;
                    error_log("Email failed for student {$student_id}: " . $emailErr->getMessage());
                    // ★ Continue loop — do NOT abort ★
                }
            }
        }
    } catch (Exception $mailSetupErr) {
        error_log("Mail setup failed: " . $mailSetupErr->getMessage());
        // ★ Email failure does NOT block the approval ★
    }

    echo json_encode([
        'success' => true,
        'message' => "Final approval granted. {$approved_count} result(s) published. CGPA computed for " . count($students) . " student(s). Emails: {$emails_sent} sent, {$email_errors} failed.",
        'approved_count' => $approved_count,
        'cgpa_count' => count($students)
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Publish Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Publication failed: ' . $e->getMessage()]);
}