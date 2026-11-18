<?php
// ============================================================
// CSCResultPortal - Lecturer Sidebar Navigation
// File: includes/lecturer-sidebar.php
// Purpose: Left sidebar for lecturer role only.
//          Shows 4 navigation items:
//          1. Dashboard (My Courses overview)
//          2. My Courses
//          3. Enter Scores
//          4. Returned Results (flagged by advisor)
//
// REQUIRES: config/db.php included | valid lecturer session
// ============================================================

$currentPage = basename($_SERVER['PHP_SELF']);

function lecturerNavClass(string $page, string $current): string {
    return ($page === $current) ? 'sidebar-nav-item active' : 'sidebar-nav-item';
}

// ============================================================
// FETCH RETURNED RESULTS COUNT (flagged by advisor)
// Used for badge on Returned Results nav item
// ============================================================
$returnedCount = 0;
try {
    // Count course assignments where at least one result is flagged
    // and the assignment belongs to this lecturer
    $returnedCount = dbCount($pdo,
        "SELECT COUNT(DISTINCT r.assignment_id)
         FROM results r
         JOIN course_assignments ca ON r.assignment_id = ca.id
         WHERE ca.lecturer_id = ? AND r.status = 'flagged'",
        [$_SESSION['user_id']]
    );
} catch (PDOException $e) {
    $returnedCount = 0;
}

// ============================================================
// FETCH SUBMITTED BUT PENDING REVIEW COUNT
// Courses the lecturer submitted — waiting for advisor
// ============================================================
$pendingReviewCount = 0;
try {
    $pendingReviewCount = dbCount($pdo,
        "SELECT COUNT(*)
         FROM course_assignments
         WHERE lecturer_id = ? AND scores_submitted = 1",
        [$_SESSION['user_id']]
    );
} catch (PDOException $e) {
    $pendingReviewCount = 0;
}

// Build initials
$fullName  = $_SESSION['full_name'] ?? 'Lecturer';
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
     LECTURER SIDEBAR
     ============================================================ -->
<aside class="portal-sidebar" id="portalSidebar" role="navigation" aria-label="Lecturer navigation">

    <!-- Brand -->
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

    <!-- Navigation -->
    <nav class="sidebar-nav">

        <div class="sidebar-section-label">Lecturer Menu</div>

        <!-- 1. Dashboard -->
        <a
            href="<?= APP_URL ?>/pages/lecturer/dashboard.php"
            class="<?= lecturerNavClass('dashboard.php', $currentPage) ?>"
            title="Dashboard"
        >
            <i class="fa-solid fa-gauge-high nav-icon"></i>
            <span>Dashboard</span>
        </a>

        <!-- 2. My Courses -->
        <a
            href="<?= APP_URL ?>/pages/lecturer/my-courses.php"
            class="<?= lecturerNavClass('my-courses.php', $currentPage) ?>"
            title="My Courses"
        >
            <i class="fa-solid fa-book-open nav-icon"></i>
            <span>My Courses</span>
        </a>

        <!-- 3. Enter Scores -->
        <a
            href="<?= APP_URL ?>/pages/lecturer/enter-scores.php"
            class="<?= lecturerNavClass('enter-scores.php', $currentPage) ?>"
            title="Enter Scores"
        >
            <i class="fa-solid fa-pen-to-square nav-icon"></i>
            <span>Enter Scores</span>
        </a>

        <!-- 4. Returned Results -->
        <a
            href="<?= APP_URL ?>/pages/lecturer/returned-results.php"
            class="<?= lecturerNavClass('returned-results.php', $currentPage) ?>"
            title="Returned Results"
        >
            <i class="fa-solid fa-flag nav-icon"></i>
            <span>Returned Results</span>
            <?php if ($returnedCount > 0): ?>
                <span class="sidebar-badge"><?= $returnedCount ?></span>
            <?php endif; ?>
        </a>

        <!-- Quick Stats Block -->
        <div class="sidebar-section-label" style="margin-top:16px;">Quick Stats</div>
        <div class="sidebar-info-block">
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-clock-rotate-left"></i> Pending Review
                </span>
                <span class="sidebar-info-value">
                    <?= $pendingReviewCount ?>
                </span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-flag"></i> Flagged
                </span>
                <span class="sidebar-info-value" style="color:<?= $returnedCount > 0 ? '#dc3545' : 'inherit' ?>">
                    <?= $returnedCount ?>
                </span>
            </div>
        </div>

    </nav>

    <!-- Footer -->
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
                <div class="sidebar-user-role">Lecturer</div>
            </div>
        </div>
        <a href="<?= APP_URL ?>/auth/logout.php" class="sidebar-logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Sign Out</span>
        </a>
    </div>

</aside>
<!-- /.portal-sidebar (lecturer) -->