<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hod') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = Database::getConnection();

    $staff_id    = (int)($_POST['staff_id'] ?? 0);
    $course_id   = (int)($_POST['course_id'] ?? 0);
    $semester_id = (int)($_POST['semester_id'] ?? 0);

    // ── Validate ──
    if ($staff_id < 1 || $course_id < 1 || $semester_id < 1) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    // ── Verify staff exists and is a lecturer ──
    $stmt = $pdo->prepare("SELECT staff_id, full_name FROM staff WHERE staff_id = ?");
    $stmt->execute([$staff_id]);
    $staff = $stmt->fetch();
    if (!$staff) {
        echo json_encode(['success' => false, 'message' => 'Staff member not found.']);
        exit;
    }

    // ── Verify course exists ──
    $stmt = $pdo->prepare("SELECT course_id, course_code, course_title FROM courses WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();
    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found.']);
        exit;
    }

    // ── Check for duplicate assignment ──
    $stmt = $pdo->prepare("
        SELECT assignment_id FROM course_assignments 
        WHERE staff_id = ? AND course_id = ? AND semester_id = ?
    ");
    $stmt->execute([$staff_id, $course_id, $semester_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'This lecturer is already assigned to this course for the selected semester.']);
        exit;
    }

    // ── Insert Assignment ──
    $stmt = $pdo->prepare("
        INSERT INTO course_assignments (staff_id, course_id, semester_id)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$staff_id, $course_id, $semester_id]);

    echo json_encode([
        'success' => true,
        'message' => "{$staff['full_name']} assigned to {$course['course_code']} ({$course['course_title']})."
    ]);

} catch (PDOException $e) {
    error_log("Assign Course Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}