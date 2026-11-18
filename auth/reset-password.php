<?php
// ============================================================
// CSCResultPortal - Reset Password Page
// File: auth/reset-password.php
// ============================================================

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$message = '';
$success = false;
$showForm = false;
$validReset = null;

if (empty($token)) {
    $message = 'Invalid or missing reset token.';
} else {
    try {
        $validReset = dbFetchOne($pdo,
            "SELECT pr.id, pr.user_id, pr.expires_at, pr.is_used, u.email, u.full_name
             FROM password_resets pr
             JOIN users u ON pr.user_id = u.id
             WHERE pr.token = ?",
            [$token]
        );

        if (!is_array($validReset)) {
            $message = 'Invalid reset token. Please request a new one.';
        } elseif ((int) $validReset['is_used'] === 1) {
            $message = 'This reset link has already been used. Please request a new one.';
        } elseif (strtotime($validReset['expires_at']) < time()) {
            $message = 'This reset link has expired. Please request a new one.';
        } else {
            $showForm = true;

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $newPassword     = $_POST['new_password']     ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (strlen($newPassword) < 8) {
                    $message = 'Password must be at least 8 characters long.';
                } elseif ($newPassword !== $confirmPassword) {
                    $message = 'Passwords do not match.';
                } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
                    $message = 'Password must contain at least one uppercase letter and one number.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => HASH_COST]);

                        dbExecute($pdo,
                            "UPDATE users SET password_hash = ? WHERE id = ?",
                            [$newHash, $validReset['user_id']]
                        );

                        dbExecute($pdo,
                            "UPDATE password_resets SET is_used = 1 WHERE id = ?",
                            [$validReset['id']]
                        );

                        // Clear any lockouts for this user
                        dbExecute($pdo,
                            "DELETE FROM login_lockouts WHERE email = ?",
                            [$validReset['email']]
                        );

                        writeAuditLog($pdo, (int) $validReset['user_id'], 'password_reset_completed',
                            'users', (int) $validReset['user_id']);

                        $pdo->commit();

                        $success = true;
                        $showForm = false;
                        $message = 'Password successfully reset. You can now log in with your new password.';

                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        $message = 'A system error occurred. Please try again.';
                        logDatabaseError('Password reset save error: ' . $e->getMessage());
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $message = 'A system error occurred. Please try again.';
        logDatabaseError('Reset token verification error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password &mdash; CSCResultPortal</title>
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
                <p class="login-panel-subtitle">Reset Your Password</p>
            </div>
        </div>

        <div class="login-panel-right">
            <div class="login-card">
                <div class="login-card-header">
                    <h2 class="login-title">
                        <?= $success ? 'Password Reset!' : 'Reset Password' ?>
                    </h2>
                    <p class="login-subtitle">
                        <?php if ($showForm && is_array($validReset)): ?>
                            Setting new password for <strong><?= htmlspecialchars($validReset['email']) ?></strong>
                        <?php elseif ($success): ?>
                            Your password has been updated
                        <?php else: ?>
                            Password reset verification
                        <?php endif; ?>
                    </p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $success ? 'success' : 'danger' ?> login-alert">
                        <i class="fa-solid <?= $success ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($showForm): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        
                        <div class="mb-3">
                            <label class="form-label login-label">
                                <i class="fa-solid fa-lock me-1"></i> New Password
                            </label>
                            <input type="password" class="portal-form-control" name="new_password" required
                                   minlength="8" placeholder="Min 8 characters, 1 uppercase, 1 number">
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label login-label">
                                <i class="fa-solid fa-lock me-1"></i> Confirm Password
                            </label>
                            <input type="password" class="portal-form-control" name="confirm_password" required
                                   minlength="8" placeholder="Retype new password">
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn login-btn">
                                <i class="fa-solid fa-key me-2"></i>
                                Set New Password
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-3">
                    <a href="<?= APP_URL ?>/index.php" class="login-forgot-link">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>