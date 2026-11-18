<?php
session_start();
header('Content-Type: application/json');

// ── Guard: HOD Only ──
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hod') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST method required.']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = Database::getConnection();

    // ── Collect & Sanitise Input ──
    $course_code   = strtoupper(trim($_POST['course_code'] ?? ''));
    $course_title  = trim($_POST['course_title'] ?? '');
    $credit_units  = (int)($_POST['credit_units'] ?? 0);
    $test_weight   = (float)($_POST['test_weight'] ?? 0);
    $lab_weight    = (float)($_POST['lab_weight'] ?? 0);
    $exam_weight   = (float)($_POST['exam_weight'] ?? 0);

    // ── Validation ──
    $errors = [];

    if (empty($course_code)) {
        $errors[] = 'Course code is required (e.g., CSC 401).';
    } elseif (!preg_match('/^[A-Z]{3}\s?\d{3}$/', $course_code)) {
        $errors[] = 'Course code must follow the format: CSC 401.';
    }

    if (empty($course_title)) {
        $errors[] = 'Course title is required.';
    } elseif (strlen($course_title) > 150) {
        $errors[] = 'Course title must not exceed 150 characters.';
    }

    if ($credit_units < 1 || $credit_units > 6) {
        $errors[] = 'Credit units must be between 1 and 6.';
    }

    $total_weight = $test_weight + $lab_weight + $exam_weight;
    if (abs($total_weight - 100) > 0.01) {
        $errors[] = "Component weights must total exactly 100%. Current total: {$total_weight}%.";
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // ── Check for Duplicate Course Code ──
    $stmt = $pdo->prepare("SELECT course_id FROM courses WHERE course_code = ?");
    $stmt->execute([$course_code]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => "Course '{$course_code}' already exists."]);
        exit;
    }

    // ── Insert New Course ──
    $stmt = $pdo->prepare("
        INSERT INTO courses (course_code, course_title, credit_units, test_weight, lab_weight, exam_weight)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$course_code, $course_title, $credit_units, $test_weight, $lab_weight, $exam_weight]);

    echo json_encode([
        'success' => true,
        'message' => "Course '{$course_code} — {$course_title}' added successfully.",
        'course_id' => $pdo->lastInsertId()
    ]);

} catch (PDOException $e) {
    error_log("Add Course Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}