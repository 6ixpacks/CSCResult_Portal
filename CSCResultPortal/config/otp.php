<?php
// ============================================================
// CSCResultPortal - Email OTP (two-step login) helpers
// File: config/otp.php
// Purpose: Create, store (hashed), limit and verify the 6-digit
//          codes emailed to admins when they log in.
// Nothing here sends email or touches the session; auth/login.php
// and auth/verify-otp.php use these functions.
// ============================================================

require_once __DIR__ . '/db.php';

// ============================================================
// SETTINGS - change these to suit your department
// ============================================================

// MASTER SWITCH. Set to false to turn OTP off for everyone
// (use this if email stops working and admins are locked out).
if (!defined('OTP_ENABLED')) {
    define('OTP_ENABLED', true);
}

// Roles that must enter a code. Add 'lecturer' here to include lecturers.
if (!defined('OTP_ROLES')) {
    define('OTP_ROLES', ['hod', 'advisor']);
}

if (!defined('OTP_EXPIRY_MINUTES'))     { define('OTP_EXPIRY_MINUTES', 10); }   // code lifetime
if (!defined('OTP_MAX_ATTEMPTS'))       { define('OTP_MAX_ATTEMPTS', 5); }      // wrong guesses allowed
if (!defined('OTP_RESEND_SECONDS'))     { define('OTP_RESEND_SECONDS', 60); }   // wait between emails
if (!defined('OTP_MAX_SENDS_PER_HOUR')) { define('OTP_MAX_SENDS_PER_HOUR', 5); }// emails per user per hour


/**
 * Does this role have to enter an emailed code?
 */
function otpRequiredForRole(string $role): bool
{
    return OTP_ENABLED && in_array(strtolower($role), OTP_ROLES, true);
}


/**
 * Hides most of an email address: john.doe@gmail.com -> j*****e@gmail.com
 */
function maskEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2 || $parts[0] === '') {
        return '***';
    }

    [$local, $domain] = $parts;
    $first = mb_substr($local, 0, 1);
    $last  = mb_strlen($local) > 2 ? mb_substr($local, -1) : '';
    $stars = max(2, mb_strlen($local) - 1 - mb_strlen($last));

    return $first . str_repeat('*', $stars) . $last . '@' . $domain;
}


/**
 * Creates a new code for a user and stores only its hash.
 * Any older unused codes for that user are cancelled.
 *
 * Returns:
 *   ['ok' => true,  'code' => '123456']
 *   ['ok' => false, 'reason' => 'cooldown'|'limit'|'db', 'error' => '...', 'wait' => seconds (cooldown only)]
 *
 * The plain code is returned ONCE so it can be emailed. It is never saved.
 */
function issueOtp(PDO $pdo, int $userId, string $ip = ''): array
{
    try {
        // Housekeeping: remove old rows
        dbExecute($pdo, "DELETE FROM login_otps WHERE created_at < (NOW() - INTERVAL 1 DAY)");

        // 1) Cool-down between emails
        $last = dbFetchOne($pdo,
            "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
             FROM login_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        if (is_array($last) && (int) $last['age'] < OTP_RESEND_SECONDS) {
            $wait = OTP_RESEND_SECONDS - (int) $last['age'];
            return [
                'ok'     => false,
                'reason' => 'cooldown',
                'wait'   => $wait,
                'error'  => "A code was just sent. Please wait {$wait} second(s) before requesting another.",
            ];
        }

        // 2) Hourly limit (stops someone flooding an admin's inbox)
        $sentLastHour = dbCount($pdo,
            "SELECT COUNT(*) FROM login_otps
             WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)",
            [$userId]
        );
        if ($sentLastHour >= OTP_MAX_SENDS_PER_HOUR) {
            return [
                'ok'     => false,
                'reason' => 'limit',
                'error'  => 'Too many codes requested. Please try again in about an hour.',
            ];
        }

        // 3) Cancel older unused codes
        dbExecute($pdo,
            "UPDATE login_otps SET is_used = 1 WHERE user_id = ? AND is_used = 0",
            [$userId]
        );

        // 4) New code (cryptographically secure), stored hashed.
        //    Expiry is calculated by MySQL so PHP/MySQL clock or timezone
        //    differences can never make a code expire early or late.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        dbInsert($pdo,
            "INSERT INTO login_otps (user_id, code_hash, expires_at, ip_address)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)",
            [$userId, password_hash($code, PASSWORD_DEFAULT), OTP_EXPIRY_MINUTES, $ip !== '' ? $ip : null]
        );

        return ['ok' => true, 'code' => $code];

    } catch (PDOException $e) {
        logDatabaseError('Issue OTP error: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'db', 'error' => 'A system error occurred. Please try again.'];
    }
}


/**
 * Removes the newest unused code for a user.
 * Used when the email could not be sent, so a failed send does not
 * use up the user's cool-down or hourly allowance.
 */
function discardOtp(PDO $pdo, int $userId): void
{
    try {
        dbExecute($pdo,
            "DELETE FROM login_otps WHERE user_id = ? AND is_used = 0 ORDER BY id DESC LIMIT 1",
            [$userId]
        );
    } catch (PDOException $e) {
        logDatabaseError('Discard OTP error: ' . $e->getMessage());
    }
}


/**
 * Checks a code typed by the user.
 *
 * Returns:
 *   ['ok' => true]
 *   ['ok' => false, 'error' => '...', 'dead' => bool]
 * 'dead' = true means this code can no longer be used (expired, too many
 * wrong tries, or none exists) and the user must request a new one.
 */
function verifyOtp(PDO $pdo, int $userId, string $input): array
{
    $input = preg_replace('/\D/', '', $input);   // keep digits only

    if (strlen($input) !== 6) {
        return ['ok' => false, 'dead' => false, 'error' => 'Please enter the 6-digit code.'];
    }

    try {
        $row = dbFetchOne($pdo,
            "SELECT id, code_hash, attempts, (expires_at > NOW()) AS still_valid
             FROM login_otps
             WHERE user_id = ? AND is_used = 0
             ORDER BY id DESC LIMIT 1",
            [$userId]
        );

        if (!is_array($row)) {
            return ['ok' => false, 'dead' => true, 'error' => 'No active code. Please request a new one.'];
        }

        $otpId = (int) $row['id'];

        if ((int) $row['still_valid'] !== 1) {
            dbExecute($pdo, "UPDATE login_otps SET is_used = 1 WHERE id = ?", [$otpId]);
            return ['ok' => false, 'dead' => true, 'error' => 'This code has expired. Please request a new one.'];
        }

        if ((int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
            dbExecute($pdo, "UPDATE login_otps SET is_used = 1 WHERE id = ?", [$otpId]);
            return ['ok' => false, 'dead' => true, 'error' => 'Too many wrong attempts. Please request a new code.'];
        }

        if (password_verify($input, $row['code_hash'])) {
            // Success: single use
            dbExecute($pdo, "UPDATE login_otps SET is_used = 1 WHERE id = ?", [$otpId]);
            return ['ok' => true];
        }

        // Wrong code: count it
        dbExecute($pdo, "UPDATE login_otps SET attempts = attempts + 1 WHERE id = ?", [$otpId]);
        $left = OTP_MAX_ATTEMPTS - ((int) $row['attempts'] + 1);

        if ($left <= 0) {
            dbExecute($pdo, "UPDATE login_otps SET is_used = 1 WHERE id = ?", [$otpId]);
            return ['ok' => false, 'dead' => true, 'error' => 'Too many wrong attempts. Please request a new code.'];
        }

        return ['ok' => false, 'dead' => false, 'error' => "Incorrect code. {$left} attempt(s) left."];

    } catch (PDOException $e) {
        logDatabaseError('Verify OTP error: ' . $e->getMessage());
        return ['ok' => false, 'dead' => false, 'error' => 'A system error occurred. Please try again.'];
    }
}
