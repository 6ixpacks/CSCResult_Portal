<?php

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.', 'code' => 401]);
    exit();
}

$userId = (int) $_SESSION['user_id'];
$role   = $_SESSION['role'] ?? '';

// ============================================================
// PARSE PARAMETERS
// ============================================================
$studentIdParam    = isset($_GET['student_id'])    ? (int) $_GET['student_id']    : 0;
$semesterIdParam   = isset($_GET['semester_id'])   ? (int) $_GET['semester_id']   : 0;
$assignmentIdParam = isset($_GET['assignment_id']) ? (int) $_GET['assignment_id'] : 0;
$page              = isset($_GET['page'])          ? max(1, (int) $_GET['page']) : 1;
$perPage           = isset($_GET['per_page'])      ? max(1, min(100, (int) $_GET['per_page'])) : 50;

try {
    // ============================================================
    // ACCESS CONTROL BY ROLE
    // ============================================================
    if ($role === 'student') {
        // Students can only see their OWN final approved results
        $targetStudentId = $userId;
        $statusFilter    = "AND r.status = 'final_approved'";
    } else {
        // Staff can query broader
        $targetStudentId = $studentIdParam > 0 ? $studentIdParam : 0;
        $statusFilter    = ''; // See all statuses
    }

    // Build dynamic WHERE clause
    $conditions = [];
    $params     = [];

    if ($targetStudentId > 0) {
        $conditions[] = "r.student_id = ?";
        $params[]     = $targetStudentId;
    }

    if ($semesterIdParam > 0) {
        $conditions[] = "r.semester_id = ?";
        $params[]     = $semesterIdParam;
    }

    if ($assignmentIdParam > 0) {
        // Lecturers can only query their own assignments
        if ($role === 'lecturer') {
            $ownership = dbCount($pdo,
                "SELECT COUNT(*) FROM course_assignments 
                 WHERE id = ? AND lecturer_id = ?",
                [$assignmentIdParam, $userId]
            );
            if ($ownership === 0) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied to this course assignment.',
                    'code'    => 403
                ]);
                exit();
            }
        }
        $conditions[] = "r.assignment_id = ?";
        $params[]     = $assignmentIdParam;
    }

    // Lecturers can only see their own courses
    if ($role === 'lecturer' && $assignmentIdParam === 0) {
        $conditions[] = "r.assignment_id IN (SELECT id FROM course_assignments WHERE lecturer_id = ?)";
        $params[]     = $userId;
    }

    $whereClause = !empty($conditions) 
        ? 'WHERE ' . implode(' AND ', $conditions) . ' ' . $statusFilter 
        : ($statusFilter ? 'WHERE 1=1 ' . $statusFilter : '');

    // ============================================================
    // COUNT TOTAL RESULTS
    // ============================================================
    $countSql = "SELECT COUNT(*) FROM results r {$whereClause}";
    $totalRows = dbCount($pdo, $countSql, $params);

    $pagination = paginate($totalRows, $page, $perPage);

    // ============================================================
    // FETCH RESULTS WITH FULL DETAILS
    // ============================================================
    $sql = "SELECT r.id, r.ca_score, r.lab_score, r.exam_score, r.total_score, 
                   r.grade, r.grade_point, r.quality_point, r.credit_units,
                   r.status, r.is_carryover, r.attempt_number,
                   u.matric_number, u.full_name AS student_name,
                   c.course_code, c.course_title,
                   s.semester_name, ac.session_name,
                   lu.full_name AS lecturer_name
            FROM results r
            JOIN users u ON r.student_id = u.id
            JOIN courses c ON r.course_id = c.id
            JOIN semesters s ON r.semester_id = s.id
            JOIN academic_sessions ac ON s.session_id = ac.id
            JOIN course_assignments ca ON r.assignment_id = ca.id
            JOIN users lu ON ca.lecturer_id = lu.id
            {$whereClause}
            ORDER BY s.id DESC, c.course_code ASC
            LIMIT " . (int) $pagination['per_page'] . " OFFSET " . (int) $pagination['offset'];

    $results = dbFetchAll($pdo, $sql, $params);

    echo json_encode([
        'success'    => true,
        'results'    => $results,
        'pagination' => [
            'current_page' => $pagination['current_page'],
            'total_pages'  => $pagination['total_pages'],
            'total_rows'   => $pagination['total_rows'],
            'per_page'     => $pagination['per_page'],
            'has_next'     => $pagination['has_next'],
            'has_prev'     => $pagination['has_prev']
        ],
        'filters' => [
            'student_id'    => $targetStudentId,
            'semester_id'   => $semesterIdParam,
            'assignment_id' => $assignmentIdParam
        ],
        'code' => 200
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    logDatabaseError('Get results API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch results.',
        'code'    => 500
    ]);
}