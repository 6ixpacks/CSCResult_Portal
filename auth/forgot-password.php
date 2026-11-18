<?php
// ============================================================
// CSCResultPortal - Forgot Password Request
// File: auth/forgot-password.php
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../mail/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        try {
            $user = dbFetchOne($pdo,
                "SELECT id, full_name FROM users WHERE email = ? AND is_active = 1",
                [$email]
            );

            // Always show success message for security (prevents email enumeration)
            $success = true;
            $message = 'If an account with that email exists, a password reset link has been sent.';

            if (is_array($user)) {
                // Generate secure token
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                // Invalidate old tokens for this user
                dbExecute($pdo,
                    "UPDATE password_resets SET is_used = 1 WHERE user_id = ? AND is_used = 0",
                    [$user['id']]
                );

                // Insert new token
                dbInsert($pdo,
                    "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
                    [$user['id'], $token, $expires]
                );

                // Build reset link
                $resetLink = APP_URL . '/auth/reset-password.php?token=' . urlencode($token);

                // Send email
                $subject = 'Password Reset - CSCResultPortal';
                $htmlBody = buildEmailTemplate('Password Reset Request', '
                    <p>Dear ' . htmlspecialchars($user['full_name']) . ',</p>
                    <p>We received a request to reset your CSCResultPortal password.</p>
                    <p>Click the link below to reset your password. This link will expire in <strong>1 hour</strong>.</p>
                    <p style="text-align:center;margin:25px 0">
                        <a href="' . $resetLink . '" 
                           style="display:inline-block;background:#006400;color:#fff;padding:12px 28px;text-decoration:none;border-radius:6px;font-weight:600">
                            Reset My Password
                        </a>
                    </p>
                    <p style="font-size:12px;color:#666">
                        If the button does not work, copy and paste this link:<br>
                        <code style="background:#f4f4f4;padding:5px;border-radius:3px;font-size:11px;word-break:break-all">' . $resetLink . '</code>
                    </p>
                    <p style="font-size:12px;color:#dc3545">
                        <strong>Important:</strong> If you did not request this reset, ignore this email. 
                        Your password will remain unchanged.
                    </p>
                    <p>Regards,<br>CSCResultPortal</p>
                ');

                sendSystemEmail(
                    $email,
                    $user['full_name'],
                    $subject,
                    $htmlBody,
                    "Dear {$user['full_name']}, click this link to reset your password: {$resetLink} (expires in 1 hour)",
                    (int) $user['id'],
                    null,
                    'general',
                    'Password Reset Requested',
                    'A password reset link was sent to your email.',
                    null,
                    'password_reset'
                );

                writeAuditLog($pdo, (int) $user['id'], 'password_reset_requested', 'users', (int) $user['id']);
            }
        } catch (Exception $e) {
            logDatabaseError('Forgot password error: ' . $e->getMessage());
            $message = 'A system error occurred. Please try again.';
            $success = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/login.css">
</head>
<body class="login-body">
    <div class="login-wrapper">
        <div class="login-panel-left d-none d-lg-flex">
            <div class="login-panel-content">
                <img src="<?= APP_URL ?>/assets/futo-logo.png" alt="FUTO Logo" class="login-futo-logo" onerror="this.style.display='none'">
                <h1 class="login-panel-title">CSCResultPortal</h1>
                <p class="login-panel-subtitle">Federal University of Technology Owerri</p>
                <p class="login-panel-dept">Password Recovery</p>
            </div>
        </div>

        <div class="login-panel-right">
            <div class="login-card">
                <div class="login-card-header">
                    <h2 class="login-title">Forgot Password?</h2>
                    <p class="login-subtitle">Enter your email to receive a reset link</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $success ? 'success' : 'danger' ?> login-alert">
                        <i class="fa-solid <?= $success ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="mb-4">
                        <label class="form-label login-label">
                            <i class="fa-solid fa-envelope me-1"></i> Email Address
                        </label>
                        <div class="input-group login-input-group">
                            <span class="input-group-text login-input-icon">
                                <i class="fa-solid fa-user"></i>
                            </span>
                            <input type="email" class="form-control login-input" name="email" required
                                   placeholder="you@futo.edu.ng" autofocus>
                        </div>
                    </div>
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn login-btn">
                            <i class="fa-solid fa-paper-plane me-2"></i>
                            Send Reset Link
                        </button>
                    </div>
                </form>

                <div class="text-center mt-3">
                    <a href="<?= APP_URL ?>/index.php" class="login-forgot-link">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>

                <div class="login-footer-note">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Reset links expire after 1 hour. Check your spam folder if you don't see the email.
                </div>
            </div>
        </div>
    </div>
</body>
</html>