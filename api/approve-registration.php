<?php
// ============================================================
// CSCResultPortal - Approve / Reject Course Registration API
// File: api/approve-registration.php
// Method: POST (form fields)
// Access: Advisor only
//
// Fields:
//   csrf_token       required
//   action           'approved' | 'rejected'   (single mode)
//   registration_id  int                       (single mode)
//   remarks          string (required when rejecting)
//   bulk             '1'  -> approve ALL pending registrations
//                           for the current semester
// ============================================================

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function jsonOut(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

// ---------- Method ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// ---------- Access control ----------
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'advisor'
) {
    jsonOut(['success' => false, 'message' => 'Only advisors can do this.'], 401);
}

// ---------- CSRF ----------
if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    jsonOut(['success' => false, 'message' => 'Security check failed. Refresh the page.'], 403);
}

$advisorId = (int) $_SESSION['user_id'];
$isBulk    = ($_POST['bulk'] ?? '') === '1';
// This advisor's level (NULL = no restriction)
$advLevel = null;
try {
    $lv       = dbFetchOne($pdo, "SELECT advisor_level FROM users WHERE id = ?", [$advisorId]);
    $advLevel = is_array($lv) ? $lv['advisor_level'] : null;
} catch (PDOException $e) {
    jsonOut(['success' => false, 'message' => 'A database error occurred.'], 500);
}
$levelSql    = $advLevel ? " AND sp.level = ?" : "";
$levelParams = $advLevel ? [$advLevel] : [];

try {
    // ---------- Current semester ----------
    $term = dbFetchOne($pdo,
        "SELECT s.id AS semester_id
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE s.is_current = 1 AND ac.is_current = 1
         LIMIT 1"
    );
    if (!is_array($term)) {
        jsonOut(['success' => false, 'message' => 'No active semester is set.'], 400);
    }
    $semesterId = (int) $term['semester_id'];

    // ============================================================
    // BULK APPROVE
    // ============================================================
    if ($isBulk) {
                $pending = dbFetchAll($pdo,
            "SELECT scr.id, scr.student_id
             FROM student_course_registrations scr
             JOIN student_profiles sp ON sp.user_id = scr.student_id
             WHERE scr.semester_id = ? AND scr.status = 'pending'" . $levelSql,
            array_merge([$semesterId], $levelParams)
        );

        if (empty($pending)) {
            jsonOut(['success' => false, 'message' => 'There are no pending registrations.'], 400);
        }

        $pdo->beginTransaction();

                $ids = array_map(fn($p) => (int) $p['id'], $pending);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

                $ids = array_map(fn($p) => (int) $p['id'], $pending);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

                $ids = array_map(fn($p) => (int) $p['id'], $pending);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $updated = dbExecute($pdo,
            "UPDATE student_course_registrations
             SET status = 'approved', remarks = NULL,
                 reviewed_by = ?, reviewed_at = NOW()
             WHERE id IN ($ph) AND status = 'pending'",
            array_merge([$advisorId], $ids)
        );

        // One notification per student
        $perStudent = [];
        foreach ($pending as $p) {
            $sid = (int) $p['student_id'];
            $perStudent[$sid] = ($perStudent[$sid] ?? 0) + 1;
        }
        foreach ($perStudent as $sid => $n) {
            dbInsert($pdo,
                "INSERT INTO notifications
                    (recipient_id, sender_id, title, message, type, related_type)
                 VALUES (?, ?, ?, ?, 'general', 'course_registration')",
                [
                    $sid,
                    $advisorId,
                    'Course registration approved',
                    "Your Course Advisor approved {$n} of your course registration(s).",
                ]
            );
        }

        writeAuditLog($pdo, $advisorId, 'course_registration_bulk_approved',
            'student_course_registrations', null, null,
            ['approved_count' => $updated, 'semester_id' => $semesterId]);

        $pdo->commit();

        jsonOut([
            'success' => true,
            'message' => "{$updated} registration(s) approved.",
            'count'   => $updated,
        ]);
    }

    // ============================================================
    // SINGLE APPROVE / REJECT
    // ============================================================
    $action    = $_POST['action'] ?? '';
    $regId     = (int) ($_POST['registration_id'] ?? 0);
    $remarks   = trim($_POST['remarks'] ?? '');

    if (!in_array($action, ['approved', 'rejected'], true) || $regId <= 0) {
        jsonOut(['success' => false, 'message' => 'Invalid request.'], 400);
    }
    if ($action === 'rejected' && $remarks === '') {
        jsonOut(['success' => false, 'message' => 'Please give a reason for rejecting.'], 400);
    }
    if (strlen($remarks) > 255) {
        $remarks = substr($remarks, 0, 255);
    }

    $reg = dbFetchOne($pdo,
        "SELECT scr.id, scr.student_id, c.course_code
         FROM student_course_registrations scr
         JOIN courses c ON scr.course_id = c.id
         WHERE scr.id = ? AND scr.semester_id = ? AND scr.status = 'pending'",
        [$regId, $semesterId]
    );

    if (!is_array($reg)) {
        jsonOut([
            'success' => false,
            'message' => 'This registration was not found or has already been reviewed.',
        ], 404);
    }

    $pdo->beginTransaction();

    dbExecute($pdo,
        "UPDATE student_course_registrations
         SET status = ?, remarks = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ? AND status = 'pending'",
        [$action, $action === 'rejected' ? $remarks : null, $advisorId, $regId]
    );

    $title = $action === 'approved'
        ? 'Course registration approved'
        : 'Course registration rejected';
    $msg = $action === 'approved'
        ? "Your registration for {$reg['course_code']} was approved."
        : "Your registration for {$reg['course_code']} was rejected. Reason: {$remarks}";

    dbInsert($pdo,
        "INSERT INTO notifications
            (recipient_id, sender_id, title, message, type, related_id, related_type)
         VALUES (?, ?, ?, ?, 'general', ?, 'course_registration')",
        [(int) $reg['student_id'], $advisorId, $title, $msg, $regId]
    );

    writeAuditLog($pdo, $advisorId, 'course_registration_' . $action,
        'student_course_registrations', $regId, ['status' => 'pending'],
        ['status' => $action, 'remarks' => $remarks]);

    $pdo->commit();

    jsonOut([
        'success' => true,
        'message' => $action === 'approved' ? 'Registration approved.' : 'Registration rejected.',
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logDatabaseError('Approve registration API error: ' . $e->getMessage());
    jsonOut(['success' => false, 'message' => 'A database error occurred.'], 500);
}
