<?php

// Include required database & helper functions
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/otp.php';
require_once __DIR__ . '/../mail/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// RATE LIMITING CONSTANTS
// ============================================================
define('MAX_LOGIN_ATTEMPTS', 5);           // 5 attempts allowed
define('LOCKOUT_DURATION',   15 * 60);     // 15 minutes lockout
define('ATTEMPT_WINDOW',     15 * 60);     // Count attempts in last 15 minutes

// ============================================================
// REQUEST METHOD & RESPONSE HEADERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit();
}

$contentType = isset($_SERVER['CONTENT_TYPE']) ? trim($_SERVER['CONTENT_TYPE']) : '';
$isJsonRequest = strpos($contentType, 'application/json') !== false;

if ($isJsonRequest) {
    header('Content-Type: application/json; charset=UTF-8');
    $rawInput = file_get_contents('php://input');
    $postData = json_decode($rawInput, true) ?? [];
} else {
    $postData = $_POST;
}

function sendResponse(bool $success, string $message, ?string $redirectUrl = null, ?int $lockoutMinutes = null): void {
    global $isJsonRequest;

    if ($isJsonRequest) {
        $response = [
            'success'      => $success,
            'message'      => $message,
            'redirect_url' => $redirectUrl
        ];
        if ($lockoutMinutes !== null) {
            $response['lockout_minutes'] = $lockoutMinutes;
        }
        echo json_encode($response);
        exit();
    } else {
        if ($success && $redirectUrl) {
            header("Location: {$redirectUrl}");
            exit();
        } else {
            $_SESSION['login_error'] = $message;
            header('Location: ../index.php');
            exit();
        }
    }
}

// ============================================================
// GET CLIENT IP AND USER AGENT
// ============================================================
$clientIP  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (strlen($userAgent) > 500) {
    $userAgent = substr($userAgent, 0, 500);
}

// ============================================================
// RATE LIMITING FUNCTIONS
// ============================================================

/**
 * Check if this email/IP is currently locked out.
 */
function isLockedOut(PDO $pdo, string $email, string $ip): array {
    try {
        // Clean up expired lockouts
        dbExecute($pdo, "DELETE FROM login_lockouts WHERE locked_until < NOW()");

        // Check active lockout
        $lockout = dbFetchOne($pdo,
            "SELECT locked_until, reason 
             FROM login_lockouts 
             WHERE (email = ? OR ip_address = ?) 
             AND locked_until > NOW() 
             ORDER BY locked_until DESC LIMIT 1",
            [$email, $ip]
        );

        if (is_array($lockout)) {
            $remainingSeconds = strtotime($lockout['locked_until']) - time();
            $remainingMinutes = max(1, ceil($remainingSeconds / 60));
            return [
                'locked'         => true,
                'minutes'        => $remainingMinutes,
                'reason'         => $lockout['reason']
            ];
        }
    } catch (PDOException $e) {
        logDatabaseError('Lockout check error: ' . $e->getMessage());
    }

    return ['locked' => false];
}

/**
 * Count failed login attempts in the last ATTEMPT_WINDOW seconds.
 */
function countRecentFailedAttempts(PDO $pdo, string $email, string $ip): int {
    try {
        return dbCount($pdo,
            "SELECT COUNT(*) FROM login_attempts 
             WHERE (email = ? OR ip_address = ?) 
             AND success = 0 
             AND attempted_at > (NOW() - INTERVAL ? SECOND)",
            [$email, $ip, ATTEMPT_WINDOW]
        );
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Log a login attempt (success or failure).
 */
function logLoginAttempt(PDO $pdo, string $email, string $ip, bool $success, string $userAgent): void {
    try {
        dbInsert($pdo,
            "INSERT INTO login_attempts (email, ip_address, success, user_agent) 
             VALUES (?, ?, ?, ?)",
            [$email, $ip, $success ? 1 : 0, $userAgent]
        );
    } catch (PDOException $e) {
        logDatabaseError('Login attempt log error: ' . $e->getMessage());
    }
}

/**
 * Create a lockout record.
 */
function createLockout(PDO $pdo, string $email, string $ip, string $reason): void {
    try {
        $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
        dbInsert($pdo,
            "INSERT INTO login_lockouts (email, ip_address, locked_until, reason) 
             VALUES (?, ?, ?, ?)",
            [$email, $ip, $lockUntil, $reason]
        );
    } catch (PDOException $e) {
        logDatabaseError('Create lockout error: ' . $e->getMessage());
    }
}

/**
 * Clear all failed attempts and lockouts for a user after successful login.
 */
function clearFailedAttempts(PDO $pdo, string $email, string $ip): void {
    try {
        dbExecute($pdo,
            "DELETE FROM login_lockouts WHERE email = ? OR ip_address = ?",
            [$email, $ip]
        );
    } catch (PDOException $e) {}
}

// ============================================================
// INPUT VALIDATION
// ============================================================
$email    = isset($postData['email']) ? trim($postData['email']) : '';
$password = isset($postData['password']) ? trim($postData['password']) : '';

if (empty($email) || empty($password)) {
    sendResponse(false, 'Please provide both email and password.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendResponse(false, 'Please enter a valid email address.');
}

// ============================================================
// CHECK IF LOCKED OUT
// ============================================================
$lockStatus = isLockedOut($pdo, $email, $clientIP);
if ($lockStatus['locked']) {
    sendResponse(
        false,
        "Too many failed login attempts. Please try again in {$lockStatus['minutes']} minute(s).",
        null,
        (int) $lockStatus['minutes']
    );
}

// ============================================================
// DATABASE USER LOOKUP
// ============================================================
try {
    $user = dbFetchOne($pdo,
        "SELECT id, full_name, email, password_hash, role, matric_number, staff_id, is_active 
         FROM users WHERE email = ? LIMIT 1",
        [$email]
    );

    if (!$user) {
        logLoginAttempt($pdo, $email, $clientIP, false, $userAgent);
        
        $failCount = countRecentFailedAttempts($pdo, $email, $clientIP);
        $remaining = MAX_LOGIN_ATTEMPTS - $failCount;
        
        if ($failCount >= MAX_LOGIN_ATTEMPTS) {
            createLockout($pdo, $email, $clientIP, 'Too many failed login attempts');
            sendResponse(false, "Too many failed attempts. Account locked for 15 minutes.", null, 15);
        }
        
        $msg = 'Invalid email address or password.';
        if ($remaining <= 2 && $remaining > 0) {
            $msg .= " ({$remaining} attempt(s) remaining)";
        }
        sendResponse(false, $msg);
    }

    if ((int) $user['is_active'] !== 1) {
        logLoginAttempt($pdo, $email, $clientIP, false, $userAgent);
        sendResponse(false, 'Your account has been disabled. Please contact the HOD office.');
    }

    // ============================================================
    // PASSWORD VERIFICATION
    // ============================================================
    if (!password_verify($password, $user['password_hash'])) {
        logLoginAttempt($pdo, $email, $clientIP, false, $userAgent);
        
        writeAuditLog($pdo, (int) $user['id'], 'login_failed', 'users', (int) $user['id'],
            null, ['reason' => 'Invalid password', 'ip' => $clientIP]);

        $failCount = countRecentFailedAttempts($pdo, $email, $clientIP);
        $remaining = MAX_LOGIN_ATTEMPTS - $failCount;
        
        if ($failCount >= MAX_LOGIN_ATTEMPTS) {
            createLockout($pdo, $email, $clientIP, 'Too many failed login attempts');
            sendResponse(false, "Too many failed attempts. Account locked for 15 minutes.", null, 15);
        }
        
        $msg = 'Invalid email address or password.';
        if ($remaining <= 2 && $remaining > 0) {
            $msg .= " ({$remaining} attempt(s) remaining)";
        }
        sendResponse(false, $msg);
    }

    // ============================================================
    // SUCCESSFUL LOGIN
    // ============================================================
    logLoginAttempt($pdo, $email, $clientIP, true, $userAgent);
    clearFailedAttempts($pdo, $email, $clientIP);
    
        // ============================================================
    // ADMIN TWO-STEP LOGIN: the password was correct, so email a code.
    // The user is NOT logged in until the code is checked on
    // auth/verify-otp.php. Only a "waiting for code" marker is saved.
    // ============================================================
    if (otpRequiredForRole($user['role'])) {
        $otpUserId = (int) $user['id'];

        $otp = issueOtp($pdo, $otpUserId, $clientIP);

        if ($otp['ok']) {
            $emailSent = sendLoginOtpEmail(
                $user['email'],
                $user['full_name'],
                $otp['code'],
                OTP_EXPIRY_MINUTES
            );

            if (!$emailSent) {
                discardOtp($pdo, $otpUserId);
                writeAuditLog($pdo, $otpUserId, 'otp_send_failed', 'users', $otpUserId,
                    null, ['ip' => $clientIP]);
                sendResponse(false, 'We could not send your verification code. Please try again shortly or contact the HOD office.');
            }

            writeAuditLog($pdo, $otpUserId, 'otp_sent', 'users', $otpUserId,
                null, ['ip' => $clientIP]);

        } elseif (($otp['reason'] ?? '') !== 'cooldown') {
            // Hourly limit reached or a system error
            sendResponse(false, $otp['error'] ?? 'Could not send a verification code.');
        }
        // If it was only the 60-second cool-down, a code was emailed moments ago,
        // so we simply continue to the code page.

        // Start a clean session holding only the "waiting for code" marker
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['otp_pending'] = [
            'user_id'    => $otpUserId,
            'started_at' => time(),
        ];

        sendResponse(true, 'A verification code has been sent to your email.', APP_URL . '/auth/verify-otp.php');
    }
    
    session_regenerate_id(true);

    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['role']          = strtolower($user['role']);
    $_SESSION['matric_number'] = $user['matric_number'];
    $_SESSION['staff_id']      = $user['staff_id'];
    $_SESSION['logged_in']     = true;
    $_SESSION['last_activity'] = time();

    $redirectUrl = '';
    switch ($_SESSION['role']) {
        case 'student':  $redirectUrl = 'pages/student/dashboard.php'; break;
        case 'lecturer': $redirectUrl = 'pages/lecturer/dashboard.php'; break;
        case 'advisor':  $redirectUrl = 'pages/advisor/dashboard.php'; break;
        case 'hod':      $redirectUrl = 'pages/hod/dashboard.php'; break;
        default:
            session_unset();
            session_destroy();
            sendResponse(false, 'Invalid user role assigned. Contact system administrator.');
    }

    writeAuditLog($pdo, (int) $user['id'], 'login_success', 'users', (int) $user['id'],
        null, ['role' => $_SESSION['role'], 'ip' => $clientIP]);

    sendResponse(true, 'Login successful! Redirecting to your dashboard...', $redirectUrl);

} catch (PDOException $e) {
    logDatabaseError("Login database error for email {$email}: " . $e->getMessage());
    sendResponse(false, 'A system error occurred. Please try again later.');
}