<?php
// ============================================================
// CSCResultPortal - Logout Handler
// File: auth/logout.php
// Purpose: Safely destroys the user session, clears all
//          session data, removes the session cookie, writes
//          an audit log entry, and redirects to login page.
// ============================================================

require_once __DIR__ . '/../config/db.php';

// ============================================================
// SECTION 1: START SESSION
// Must start session before we can read or destroy it.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// SECTION 2: CAPTURE SESSION DATA BEFORE CLEARING
// We need the user_id for the audit log entry.
// Once we clear the session, this data is gone.
// ============================================================
$loggedInUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$loggedInRole   = isset($_SESSION['role'])    ? $_SESSION['role']          : null;
$loggedInName   = isset($_SESSION['full_name']) ? $_SESSION['full_name']   : null;

// ============================================================
// SECTION 3: WRITE AUDIT LOG BEFORE DESTROYING SESSION
// Record who logged out and when in the immutable audit trail.
// This must happen before session is destroyed because
// writeAuditLog() needs a valid $pdo connection (available)
// but the user context comes from session data captured above.
// ============================================================
if ($loggedInUserId !== null) {
    try {
        writeAuditLog(
            $pdo,
            $loggedInUserId,
            'logout',
            'users',
            $loggedInUserId,
            ['role' => $loggedInRole, 'name' => $loggedInName],
            null
        );
    } catch (PDOException $e) {
        // Audit log failure must not block logout
        logDatabaseError("Logout audit log failed for user {$loggedInUserId}: " . $e->getMessage());
    }
}

// ============================================================
// SECTION 4: DESTROY THE SESSION COMPLETELY
// Step-by-step full session destruction:
//   1. Unset all session variables
//   2. Destroy the server-side session data
//   3. Expire the session cookie in the browser
// ============================================================

// Step 1: Clear all session variables from memory
$_SESSION = [];

// Step 2: Destroy the server-side session storage
session_destroy();

// Step 3: Expire the session cookie immediately in the browser
// This removes the PHPSESSID cookie (or our custom SESSION_NAME)
// by setting its expiry to a time in the past.
if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();
    setcookie(
        session_name(),              // Cookie name (our SESSION_NAME constant)
        '',                          // Empty value
        time() - 42000,             // Expiry time in the past (forces removal)
        $cookieParams['path'],       // Same path as when cookie was set
        $cookieParams['domain'],     // Same domain as when cookie was set
        $cookieParams['secure'],     // Same secure flag
        $cookieParams['httponly']    // Same httponly flag
    );
}

// ============================================================
// SECTION 5: PREVENT BROWSER CACHING OF AUTHENTICATED PAGES
// These headers ensure the browser does not cache pages
// that were visited while logged in. After logout, pressing
// the Back button should not show authenticated content.
// ============================================================
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

// ============================================================
// SECTION 6: REDIRECT TO LOGIN PAGE
// ============================================================
header('Location: ../index.php?logged_out=1');
exit();