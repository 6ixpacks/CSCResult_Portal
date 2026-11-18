<?php

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
// PARSE PARAMETERS
// ============================================================
$countOnly = isset($_GET['count_only']) && $_GET['count_only'] == '1';
$limit     = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
$limit     = max(1, min(50, $limit)); // Clamp between 1 and 50
$offset    = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

try {
    // ============================================================
    // COUNT-ONLY MODE (for polling)
    // ============================================================
    if ($countOnly) {
        $unreadCount = dbCount($pdo,
            "SELECT COUNT(*) FROM notifications 
             WHERE recipient_id = ? AND is_read = 0",
            [$userId]
        );

        echo json_encode([
            'success'      => true,
            'unread_count' => $unreadCount,
            'code'         => 200
        ]);
        exit();
    }

    // ============================================================
    // FETCH FULL NOTIFICATIONS
    // ============================================================
    $notifications = dbFetchAll($pdo,
        "SELECT id, sender_id, title, message, type, 
                related_id, related_type, is_read, created_at
         FROM notifications 
         WHERE recipient_id = ?
         ORDER BY created_at DESC
         LIMIT " . $limit . " OFFSET " . $offset,
        [$userId]
    );

    // Get unread count for the badge
    $unreadCount = dbCount($pdo,
        "SELECT COUNT(*) FROM notifications 
         WHERE recipient_id = ? AND is_read = 0",
        [$userId]
    );

    // Get total notifications
    $totalCount = dbCount($pdo,
        "SELECT COUNT(*) FROM notifications WHERE recipient_id = ?",
        [$userId]
    );

    echo json_encode([
        'success'       => true,
        'notifications' => $notifications,
        'unread_count'  => $unreadCount,
        'total_count'   => $totalCount,
        'limit'         => $limit,
        'offset'        => $offset,
        'code'          => 200
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    logDatabaseError('Get notifications API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch notifications.',
        'code'    => 500
    ]);
}