<?php
// ============================================================
// CSCResultPortal - Student Sidebar Navigation
// File: includes/student-sidebar.php
// Purpose: Left sidebar for student role only.
//          Shows exactly 3 navigation items as specified:
//          1. My Results
//          2. CGPA Overview
//          3. Carryover Registration
//
// REQUIRES: config/db.php included | valid student session
// ============================================================

// Determine active nav item based on current script name
$currentPage = basename($_SERVER['PHP_SELF']);

// Helper to mark nav item as active
function studentNavClass(string $page, string $current): string {
    return ($page === $current) ? 'sidebar-nav-item active' : 'sidebar-nav-item';
}

// ============================================================
// FETCH STUDENT PROFILE FOR SIDEBAR FOOTER
// ============================================================
$studentProfile = null;
try {
    $studentProfile = dbFetchOne($pdo,
        "SELECT sp.level, sp.cgpa, u.matric_number
         FROM student_profiles sp
         JOIN users u ON sp.user_id = u.id
         WHERE sp.user_id = ?",
        [$_SESSION['user_id']]
    );
} catch (PDOException $e) {
    $studentProfile = null;
}

// ============================================================
// FETCH UNREAD CARRYOVER NOTIFICATIONS COUNT
// Used to show badge on carryover nav item
// ============================================================
$carryoverUpdates = 0;
try {
    $carryoverUpdates = dbCount($pdo,
        "SELECT COUNT(*) FROM notifications
         WHERE recipient_id = ? AND is_read = 0
         AND type = 'carryover_processed'",
        [$_SESSION['user_id']]
    );
} catch (PDOException $e) {
    $carryoverUpdates = 0;
}

// Build user initials for avatar
$fullName  = $_SESSION['full_name'] ?? 'Student';
$nameParts = explode(' ', trim($fullName));
$initials  = '';
foreach ($nameParts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
?>

<!-- ============================================================
     STUDENT SIDEBAR
     ============================================================ -->
<aside class="portal-sidebar" id="portalSidebar" role="navigation" aria-label="Student navigation">

    <!-- Brand / Logo -->
    <div class="sidebar-brand">
        <img
            src="<?= APP_URL ?>/assets/futo-logo.png"
            alt="FUTO Logo"
            class="sidebar-brand-logo"
            onerror="this.style.display='none'"
        >
        <div class="sidebar-brand-text">
            <div class="sidebar-brand-name">CSCResultPortal</div>
            <div class="sidebar-brand-dept">FUTO &mdash; Computer Science</div>
        </div>
    </div>

    <!-- Navigation Items -->
    <nav class="sidebar-nav">

        <div class="sidebar-section-label">Student Menu</div>


        <!-- 0. Register Courses -->
        <a
            href="<?= APP_URL ?>/pages/student/register-courses.php"
            class="<?= studentNavClass('register-courses.php', $currentPage) ?>"
            title="Register Courses"
        >
            <i class="fa-solid fa-book-medical nav-icon"></i>
            <span>Register Courses</span>
        </a>

        <!-- 1. My Results -->
        <a
            href="<?= APP_URL ?>/pages/student/my-results.php"
            class="<?= studentNavClass('my-results.php', $currentPage) ?>"
            title="My Results"
        >
            <i class="fa-solid fa-file-lines nav-icon"></i>
            <span>My Results</span>
        </a>

        <!-- 2. CGPA Overview -->
        <a
            href="<?= APP_URL ?>/pages/student/cgpa-overview.php"
            class="<?= studentNavClass('cgpa-overview.php', $currentPage) ?>"
            title="CGPA Overview"
        >
            <i class="fa-solid fa-chart-line nav-icon"></i>
            <span>CGPA Overview</span>
        </a>

        <!-- 3. Carryover Registration -->
        <a
            href="<?= APP_URL ?>/pages/student/carryover-registration.php"
            class="<?= studentNavClass('carryover-registration.php', $currentPage) ?>"
            title="Carryover Registration"
        >
            <i class="fa-solid fa-rotate nav-icon"></i>
            <span>Carryover Registration</span>
            <?php if ($carryoverUpdates > 0): ?>
                <span class="sidebar-badge"><?= $carryoverUpdates ?></span>
            <?php endif; ?>
        </a>

        <!-- Academic Info Block -->
        <?php if ($studentProfile): ?>
        <div class="sidebar-section-label" style="margin-top:16px;">Academic Info</div>
        <div class="sidebar-info-block">
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-id-card"></i> Matric
                </span>
                <span class="sidebar-info-value">
                    <?= htmlspecialchars($_SESSION['matric_number'] ?? 'N/A') ?>
                </span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-layer-group"></i> Level
                </span>
                <span class="sidebar-info-value">
                    <?= htmlspecialchars($studentProfile['level']) ?>L
                </span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-star"></i> CGPA
                </span>
                <span class="sidebar-info-value cgpa-highlight">
                    <?= number_format((float)$studentProfile['cgpa'], 2) ?>/5.00
                </span>
            </div>
        </div>
        <?php endif; ?>

    </nav>

    <!-- Sidebar Footer: User Info + Logout -->
    <div class="sidebar-footer">
        <div class="sidebar-user-info">
            <div class="sidebar-user-avatar">
                <?= htmlspecialchars($initials) ?>
            </div>
            <div>
                <div class="sidebar-user-name">
                    <?= htmlspecialchars(
                        strlen($fullName) > 18
                            ? substr($fullName, 0, 18) . '...'
                            : $fullName
                    ) ?>
                </div>
                <div class="sidebar-user-role">Student</div>
            </div>
        </div>
        <a href="<?= APP_URL ?>/auth/logout.php" class="sidebar-logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Sign Out</span>
        </a>
    </div>

</aside>
<!-- /.portal-sidebar (student) -->