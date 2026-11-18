<?php
// ============================================================
// CSCResultPortal - Verify Login Code (OTP)
// File: auth/verify-otp.php
// Purpose: Second step of admin login. The admin has already
//          entered a correct password and been emailed a 6-digit
//          code; this page checks that code and only then creates
//          the real logged-in session.
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/otp.php';
require_once __DIR__ . '/../mail/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function backToLogin(string $flashMessage = ''): void
{
    if ($flashMessage !== '') {
        $_SESSION['login_error'] = $flashMessage;   // shown by index.php
    }
    header('Location: ' . APP_URL . '/index.php');
    exit();
}

// ---------- Guards ----------
if (!empty($_SESSION['logged_in'])) {
    backToLogin();                                   // already logged in
}

$pending = $_SESSION['otp_pending'] ?? null;
if (!is_array($pending) || empty($pending['user_id'])) {
    backToLogin();                                   // never passed the password step
}

// The "waiting for code" state itself expires
if ((time() - (int) ($pending['started_at'] ?? 0)) > (OTP_EXPIRY_MINUTES + 5) * 60) {
    unset($_SESSION['otp_pending'], $_SESSION['otp_csrf']);
    backToLogin('Your verification session expired. Please log in again.');
}

$uid      = (int) $pending['user_id'];
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$user = null;
try {
    $user = dbFetchOne($pdo,
        "SELECT id, full_name, email, role, matric_number, staff_id, is_active
         FROM users WHERE id = ? LIMIT 1",
        [$uid]
    );
} catch (PDOException $e) {
    $user = null;
}

if (!is_array($user) || (int) $user['is_active'] !== 1 || !otpRequiredForRole($user['role'])) {
    unset($_SESSION['otp_pending'], $_SESSION['otp_csrf']);
    backToLogin('Please log in again.');
}

// CSRF token for the forms on this page
if (empty($_SESSION['otp_csrf'])) {
    $_SESSION['otp_csrf'] = bin2hex(random_bytes(32));
}

$message     = '';
$messageType = 'danger';   // danger | success | warning
$codeIsDead  = false;

// ============================================================
// HANDLE FORM ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['otp_csrf'], $_POST['csrf'] ?? '')) {
        $message = 'Security check failed. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ---------- Cancel: go back to login ----------
        if ($action === 'cancel') {
            unset($_SESSION['otp_pending'], $_SESSION['otp_csrf']);
            backToLogin();
        }

        // ---------- Verify the typed code ----------
        if ($action === 'verify') {
            $result = verifyOtp($pdo, $uid, $_POST['code'] ?? '');

            if ($result['ok']) {

                $role = strtolower($user['role']);
                $destinations = [
                    'lecturer' => 'pages/lecturer/dashboard.php',
                    'advisor'  => 'pages/advisor/dashboard.php',
                    'hod'      => 'pages/hod/dashboard.php',
                ];

                if (!isset($destinations[$role])) {
                    unset($_SESSION['otp_pending'], $_SESSION['otp_csrf']);
                    backToLogin('Invalid user role assigned. Contact the system administrator.');
                }

                // Create the REAL session (same fields auth/login.php sets)
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['user_id']       = (int) $user['id'];
                $_SESSION['full_name']     = $user['full_name'];
                $_SESSION['email']         = $user['email'];
                $_SESSION['role']          = $role;
                $_SESSION['matric_number'] = $user['matric_number'];
                $_SESSION['staff_id']      = $user['staff_id'];
                $_SESSION['logged_in']     = true;
                $_SESSION['last_activity'] = time();

                writeAuditLog($pdo, (int) $user['id'], 'otp_verified', 'users', (int) $user['id'],
                    null, ['ip' => $clientIP]);
                writeAuditLog($pdo, (int) $user['id'], 'login_success', 'users', (int) $user['id'],
                    null, ['role' => $role, 'ip' => $clientIP, 'method' => 'password+otp']);

                header('Location: ' . APP_URL . '/' . $destinations[$role]);
                exit();
            }

            // Wrong / expired code
            $message    = $result['error'];
            $codeIsDead = !empty($result['dead']);
            writeAuditLog($pdo, $uid, 'otp_failed', 'users', $uid, null, ['ip' => $clientIP]);
        }

        // ---------- Resend a new code ----------
        if ($action === 'resend') {
            $otp = issueOtp($pdo, $uid, $clientIP);

            if ($otp['ok']) {
                $sent = sendLoginOtpEmail($user['email'], $user['full_name'], $otp['code'], OTP_EXPIRY_MINUTES);

                if ($sent) {
                    $_SESSION['otp_pending']['started_at'] = time();
                    writeAuditLog($pdo, $uid, 'otp_resent', 'users', $uid, null, ['ip' => $clientIP]);
                    $message     = 'A new code has been sent to your email.';
                    $messageType = 'success';
                } else {
                    discardOtp($pdo, $uid);
                    $message = 'We could not send the email. Please try again shortly.';
                }
            } else {
                $message     = $otp['error'] ?? 'Could not send a new code.';
                $messageType = 'warning';
            }
        }
    }
}

// ============================================================
// TIMERS SHOWN ON THE PAGE
// ============================================================
$secondsLeft = 0;   // until the current code expires
$resendWait  = 0;   // until "Resend" is allowed again
try {
    $t = dbFetchOne($pdo,
        "SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS left_s
         FROM login_otps WHERE user_id = ? AND is_used = 0
         ORDER BY id DESC LIMIT 1",
        [$uid]
    );
    $secondsLeft = is_array($t) ? max(0, (int) $t['left_s']) : 0;

    $l = dbFetchOne($pdo,
        "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
         FROM login_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1",
        [$uid]
    );
    $resendWait = is_array($l) ? max(0, OTP_RESEND_SECONDS - (int) $l['age']) : 0;
} catch (PDOException $e) {
    // timers are cosmetic; ignore errors
}

$alertClass = ['danger' => 'alert-danger', 'success' => 'alert-success', 'warning' => 'alert-warning'][$messageType] ?? 'alert-danger';
$alertIcon  = ['danger' => 'fa-circle-exclamation', 'success' => 'fa-circle-check', 'warning' => 'fa-triangle-exclamation'][$messageType] ?? 'fa-circle-exclamation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Verify Login &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/login.css">
    <style>
        .otp-input { text-align: center; font-size: 28px; font-weight: 700; letter-spacing: 12px; font-family: 'Courier New', monospace; padding: 12px 8px; }
        .otp-meta { text-align: center; font-size: 13px; color: #666; margin-bottom: 18px; }
        .otp-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; gap: 10px; flex-wrap: wrap; }
        .otp-link-btn { background: none; border: none; padding: 0; color: #006400; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: underline; }
        .otp-link-btn:disabled { color: #999; text-decoration: none; cursor: not-allowed; }
        .otp-expired { color: #b00020; font-weight: 600; }
    </style>
</head>
<body class="login-body">

<div class="login-wrapper">

    <!-- Left decorative panel (same as the login page) -->
    <div class="login-panel-left d-none d-lg-flex">
        <div class="login-panel-content">
            <img src="<?= APP_URL ?>/assets/futo-logo.png" alt="FUTO Logo" class="login-futo-logo"
                 onerror="this.style.display='none'">
            <h1 class="login-panel-title">CSCResultPortal</h1>
            <p class="login-panel-subtitle">Federal University of Technology Owerri</p>
            <p class="login-panel-dept">Department of Computer Science</p>
            <hr class="login-panel-divider">
            <ul class="login-feature-list">
                <li><i class="fa-solid fa-shield-halved"></i> Two-Step Verification</li>
                <li><i class="fa-solid fa-envelope-circle-check"></i> Code Sent to Your Email</li>
                <li><i class="fa-solid fa-clock"></i> Code Expires in <?= (int) OTP_EXPIRY_MINUTES ?> Minutes</li>
            </ul>
        </div>
    </div>

    <!-- Right panel -->
    <div class="login-panel-right">
        <div class="login-card">

            <div class="login-card-header">
                <h2 class="login-title">Verify It&rsquo;s You</h2>
                <p class="login-subtitle">
                    Enter the 6-digit code we sent to<br>
                    <strong><?= htmlspecialchars(maskEmail($user['email'])) ?></strong>
                </p>
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert <?= $alertClass ?> login-alert" role="alert">
                    <i class="fa-solid <?= $alertIcon ?> me-2"></i><?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="otpForm" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['otp_csrf']) ?>">
                <input type="hidden" name="action" value="verify">

                <div class="mb-3">
                    <input type="text" name="code" id="otpCode"
                           class="form-control login-input otp-input"
                           inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           autocomplete="one-time-code" placeholder="000000"
                           required autofocus>
                </div>

                <div class="otp-meta" id="expiryText"></div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn login-btn" id="otpBtn">
                        <i class="fa-solid fa-shield-halved me-2"></i>Verify &amp; Sign In
                    </button>
                </div>
            </form>

            <div class="otp-actions">
                <form method="POST" action="" class="m-0">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['otp_csrf']) ?>">
                    <input type="hidden" name="action" value="resend">
                    <button type="submit" class="otp-link-btn" id="resendBtn">Resend code</button>
                </form>

                <form method="POST" action="" class="m-0">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['otp_csrf']) ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="otp-link-btn">&larr; Back to login</button>
                </form>
            </div>

            <div class="login-footer-note mt-4">
                <i class="fa-solid fa-circle-info me-1"></i>
                Didn&rsquo;t get the email? Check your spam folder. Never share this code with anyone.
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    var secondsLeft = <?= (int) $secondsLeft ?>;
    var resendWait  = <?= (int) $resendWait ?>;
    var codeIsDead  = <?= $codeIsDead ? 'true' : 'false' ?>;

    var input      = document.getElementById('otpCode');
    var expiryText = document.getElementById('expiryText');
    var resendBtn  = document.getElementById('resendBtn');
    var form       = document.getElementById('otpForm');
    var otpBtn     = document.getElementById('otpBtn');

    // Digits only
    input.addEventListener('input', function () {
        input.value = input.value.replace(/\D/g, '').slice(0, 6);
    });

    // Stop double submits
    form.addEventListener('submit', function () {
        otpBtn.disabled = true;
    });

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function tick() {
        // Code expiry
        if (secondsLeft > 0 && !codeIsDead) {
            expiryText.innerHTML = 'Code expires in <strong>' +
                Math.floor(secondsLeft / 60) + ':' + pad(secondsLeft % 60) + '</strong>';
            secondsLeft--;
        } else {
            expiryText.innerHTML = '<span class="otp-expired">This code is no longer valid. Please request a new one.</span>';
        }

        // Resend cool-down
        if (resendWait > 0) {
            resendBtn.disabled = true;
            resendBtn.textContent = 'Resend code in ' + resendWait + 's';
            resendWait--;
        } else {
            resendBtn.disabled = false;
            resendBtn.textContent = 'Resend code';
        }
    }

    tick();
    setInterval(tick, 1000);
})();
</script>

</body>
</html>
