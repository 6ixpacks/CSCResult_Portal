<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// METHOD CHECK
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.',
        'code'    => 405
    ]);
    exit();
}

// ============================================================
// AUTHENTICATION CHECK
// ============================================================
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id'])
) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in.',
        'code'    => 401
    ]);
    exit();
}

$userId = (int) $_SESSION['user_id'];

// ============================================================
// PARSE JSON INPUT
// ============================================================
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?? [];

$notificationId = isset($data['notification_id']) ? (int) $data['notification_id'] : 0;
$markAll        = isset($data['mark_all']) && $data['mark_all'] === true;

try {
    if ($markAll) {
        // ============================================================
        // MARK ALL AS READ
        // ============================================================
        $affected = dbExecute($pdo,
            "UPDATE notifications 
             SET is_read = 1 
             WHERE recipient_id = ? AND is_read = 0",
            [$userId]
        );

        echo json_encode([
            'success'      => true,
            'message'      => "Marked {$affected} notification(s) as read.",
            'marked_count' => $affected,
            'code'         => 200
        ]);

    } elseif ($notificationId > 0) {
        // ============================================================
        // MARK SINGLE NOTIFICATION
        // ============================================================
        // Verify the notification belongs to this user
        $notification = dbFetchOne($pdo,
            "SELECT id, is_read FROM notifications 
             WHERE id = ? AND recipient_id = ?",
            [$notificationId, $userId]
        );

        if (!is_array($notification)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Notification not found or access denied.',
                'code'    => 404
            ]);
            exit();
        }

        if ((int) $notification['is_read'] === 1) {
            echo json_encode([
                'success' => true,
                'message' => 'Notification already read.',
                'code'    => 200
            ]);
            exit();
        }

        dbExecute($pdo,
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ?",
            [$notificationId, $userId]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Notification marked as read.',
            'code'    => 200
        ]);

    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Provide either notification_id or mark_all=true.',
            'code'    => 400
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    logDatabaseError('Mark notification read API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update notification.',
        'code'    => 500
    ]);
}