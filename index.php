<?php
// ============================================================
// CSCResultPortal - Login Page
// File: index.php
// Purpose: The system entry point. Serves the login form.
//          No landing page — this IS the first page users see.
//          If user is already logged in, redirect to dashboard.
// ============================================================

require_once __DIR__ . '/config/db.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// SECTION 1: REDIRECT IF ALREADY LOGGED IN
// If user has a valid active session, skip the login page
// and send them directly to their role-based dashboard.
// ============================================================
if (
    isset($_SESSION['logged_in']) &&
    $_SESSION['logged_in'] === true &&
    isset($_SESSION['role'])
) {
    // Check session timeout
    if (
        isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) < SESSION_TIMEOUT
    ) {
        // Session is still valid — redirect to correct dashboard
        $role = strtolower($_SESSION['role']);
        $dashboards = [
            'student'  => 'pages/student/dashboard.php',
            'lecturer' => 'pages/lecturer/dashboard.php',
            'advisor'  => 'pages/advisor/dashboard.php',
            'hod'      => 'pages/hod/dashboard.php',
        ];

        if (isset($dashboards[$role])) {
            header('Location: ' . $dashboards[$role]);
            exit();
        }
    } else {
        // Session timed out — clear it before showing login
        $_SESSION = [];
        session_destroy();
    }
}

// ============================================================
// SECTION 2: READ FLASH MESSAGES
// Messages passed via session or URL query string
// ============================================================

// Error from login attempt (set in auth/login.php for non-AJAX)
$loginError = '';
if (isset($_SESSION['login_error'])) {
    $loginError = $_SESSION['login_error'];
    unset($_SESSION['login_error']); // Clear after reading (flash)
}

// Logout success message from URL parameter
$loggedOut = isset($_GET['logged_out']) && $_GET['logged_out'] == '1';

// Session timeout message
$sessionExpired = isset($_GET['expired']) && $_GET['expired'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ============================================================
         META & SEO
         ============================================================ -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CSCResultPortal - Federal University of Technology Owerri, Computer Science Department Result Processing System">
    <meta name="robots" content="noindex, nofollow">
    <title>Login &mdash; CSCResultPortal | FUTO Computer Science</title>

    <!-- ============================================================
         FAVICON
         ============================================================ -->
    <link rel="icon" type="image/png" href="assets/futo-logo.png">

    <!-- ============================================================
         EXTERNAL STYLES
         Bootstrap 5 for layout and components
         Font Awesome for icons
         ============================================================ -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
        crossorigin="anonymous"
    >
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        integrity="sha512-Avb2QiuDEEvB4bZJYdft2mNjVShBftLdPG8FJ0V7irTLQ8Uo0qcPxh4Plq7G5tGm0rU+1SPhVotteLpBERwOg=="
        crossorigin="anonymous"
    >

    <!-- ============================================================
         PROJECT STYLESHEETS
         global.css — shared across all pages
         login.css  — specific to the login page only
         ============================================================ -->
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body class="login-body">

    <!-- ============================================================
         MAIN LOGIN WRAPPER
         Full-screen centered layout
         ============================================================ -->
    <div class="login-wrapper">

        <!-- Left decorative panel (desktop only) -->
        <div class="login-panel-left d-none d-lg-flex">
            <div class="login-panel-content">
                <img
                    src="assets/futo-logo.png"
                    alt="FUTO Logo"
                    class="login-futo-logo"
                    onerror="this.style.display='none'"
                >
                <h1 class="login-panel-title">CSCResultPortal</h1>
                <p class="login-panel-subtitle">
                    Federal University of Technology Owerri
                </p>
                <p class="login-panel-dept">
                    Department of Computer Science
                </p>
                <hr class="login-panel-divider">
                <ul class="login-feature-list">
                    <li>
                        <i class="fa-solid fa-shield-halved"></i>
                        Secure Role-Based Access
                    </li>
                    <li>
                        <i class="fa-solid fa-chart-line"></i>
                        Real-Time Result Processing
                    </li>
                    <li>
                        <i class="fa-solid fa-arrows-spin"></i>
                        Full Workflow Management
                    </li>
                    <li>
                        <i class="fa-solid fa-graduation-cap"></i>
                        CGPA Tracking & Analytics
                    </li>
                </ul>
            </div>
        </div>

        <!-- Right login form panel -->
        <div class="login-panel-right">
            <div class="login-card">

                <!-- Logo (mobile only) -->
                <div class="login-logo-mobile d-flex d-lg-none">
                    <img
                        src="assets/futo-logo.png"
                        alt="FUTO Logo"
                        class="login-logo-img"
                        onerror="this.style.display='none'"
                    >
                </div>

                <!-- Card Header -->
                <div class="login-card-header">
                    <h2 class="login-title">Welcome Back</h2>
                    <p class="login-subtitle">Sign in to your portal account</p>
                </div>

                <!-- ============================================================
                     FLASH MESSAGES
                     Shown for logout, session expiry, or non-AJAX login errors
                     ============================================================ -->

                <?php if ($loggedOut): ?>
                    <div class="alert alert-success alert-dismissible fade show login-alert" role="alert">
                        <i class="fa-solid fa-circle-check me-2"></i>
                        You have been successfully logged out.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($sessionExpired): ?>
                    <div class="alert alert-warning alert-dismissible fade show login-alert" role="alert">
                        <i class="fa-solid fa-clock me-2"></i>
                        Your session expired due to inactivity. Please log in again.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($loginError)): ?>
                    <div class="alert alert-danger alert-dismissible fade show login-alert" role="alert">
                        <i class="fa-solid fa-circle-exclamation me-2"></i>
                        <?= htmlspecialchars($loginError) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- AJAX response message box (shown by login.js) -->
                <div
                    id="loginMessage"
                    class="alert login-alert d-none"
                    role="alert"
                    aria-live="polite"
                ></div>

                <!-- ============================================================
                     LOGIN FORM
                     Submitted via JavaScript fetch (AJAX)
                     Action points to auth/login.php
                     ============================================================ -->
                <form
                    id="loginForm"
                    action="auth/login.php"
                    method="POST"
                    novalidate
                    autocomplete="on"
                >
                    <!-- Email Field -->
                    <div class="mb-4">
                        <label for="email" class="form-label login-label">
                            <i class="fa-solid fa-envelope me-1"></i>
                            Email Address
                        </label>
                        <div class="input-group login-input-group">
                            <span class="input-group-text login-input-icon">
                                <i class="fa-solid fa-user"></i>
                            </span>
                            <input
                                type="email"
                                class="form-control login-input"
                                id="email"
                                name="email"
                                placeholder="you@futo.edu.ng"
                                required
                                autocomplete="email"
                                autofocus
                                maxlength="150"
                            >
                        </div>
                        <div class="invalid-feedback" id="emailError"></div>
                    </div>

                    <!-- Password Field -->
                    <div class="mb-4">
                        <label for="password" class="form-label login-label">
                            <i class="fa-solid fa-lock me-1"></i>
                            Password
                        </label>
                        <div class="input-group login-input-group">
                            <span class="input-group-text login-input-icon">
                                <i class="fa-solid fa-key"></i>
                            </span>
                            <input
                                type="password"
                                class="form-control login-input"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                required
                                autocomplete="current-password"
                                maxlength="255"
                            >
                            <!-- Toggle password visibility -->
                            <button
                                type="button"
                                class="input-group-text login-toggle-password"
                                id="togglePassword"
                                title="Show/hide password"
                                aria-label="Toggle password visibility"
                            >
                                <i class="fa-solid fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                        <div class="invalid-feedback" id="passwordError"></div>
                    </div>

                    <!-- Remember Me & Forgot Password Row -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input
                                class="form-check-input login-check"
                                type="checkbox"
                                id="rememberMe"
                                name="remember_me"
                            >
                            <label class="form-check-label login-check-label" for="rememberMe">
                                Remember me
                            </label>
                        </div>
                        <a href="auth/forgot-password.php" class="login-forgot-link" id="forgotPasswordLink">
                            Forgot password?
                        </a>
                    </div>

                    <!-- Submit Button -->
                    <div class="d-grid mb-3">
                        <button
                            type="submit"
                            class="btn login-btn"
                            id="loginBtn"
                        >
                            <span id="loginBtnText">
                                <i class="fa-solid fa-right-to-bracket me-2"></i>
                                Sign In
                            </span>
                            <span id="loginBtnSpinner" class="d-none">
                                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                Signing in...
                            </span>
                        </button>
                    </div>

                </form>

                <!-- Footer note -->
                <div class="login-footer-note">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Access is restricted to authorized CSC department members only.
                    Contact the HOD office for account assistance.
                </div>

            </div><!-- /.login-card -->

            <!-- Page bottom branding -->
            <div class="login-page-footer">
                <p>
                    &copy; <?= date('Y') ?> CSCResultPortal &mdash;
                    Federal University of Technology Owerri, Computer Science Department
                </p>
                <p class="login-version">Version <?= APP_VERSION ?></p>
            </div>
        </div><!-- /.login-panel-right -->

    </div><!-- /.login-wrapper -->


    <!-- ============================================================
         BOOTSTRAP 5 JS
         ============================================================ -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"
    ></script>

    <!-- ============================================================
         PROJECT JAVASCRIPT
         login.js handles AJAX form submission, validation,
         password toggle, and UI feedback
         ============================================================ -->
    <script src="js/login.js"></script>

</body>
</html>