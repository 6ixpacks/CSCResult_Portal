<?php
// ============================================================
// CSCResultPortal - Course Advisor Sidebar (UPDATED)
// File: includes/advisor-sidebar.php
// ============================================================

$currentPage = basename($_SERVER['PHP_SELF']);

function advisorNavClass(string $page, string $current): string {
    return ($page === $current) ? 'sidebar-nav-item active' : 'sidebar-nav-item';
}

$pendingReviewCount = 0;
try {
    $sbReviewLevel  = getAdvisorLevel($pdo, (int) $_SESSION['user_id']);
    $sbReviewSql    = "SELECT COUNT(DISTINCT r.assignment_id) FROM results r
                       JOIN course_assignments ca ON r.assignment_id = ca.id
                       JOIN courses c ON ca.course_id = c.id
                       WHERE r.status = 'submitted'";
    $sbReviewParams = [];
    if ($sbReviewLevel) {
        $sbReviewSql     .= " AND c.level = ?";
        $sbReviewParams[] = $sbReviewLevel;
    }
    $pendingReviewCount = dbCount($pdo, $sbReviewSql, $sbReviewParams);
} catch (PDOException $e) {
    $pendingReviewCount = 0;
}

$pendingCarryoverCount = 0;
try {
    $sbCarryLevel  = getAdvisorLevel($pdo, (int) $_SESSION['user_id']);
    $sbCarrySql    = "SELECT COUNT(*) FROM carryover_registrations cr
                      JOIN student_profiles sp ON sp.user_id = cr.student_id
                      WHERE cr.status = 'pending'";
    $sbCarryParams = [];
    if ($sbCarryLevel) {
        $sbCarrySql     .= " AND sp.level = ?";
        $sbCarryParams[] = $sbCarryLevel;
    }
    $pendingCarryoverCount = dbCount($pdo, $sbCarrySql, $sbCarryParams);
} catch (PDOException $e) {
    $pendingCarryoverCount = 0;
}
$pendingRegistrationCount = 0;
try {
    $sbRow   = dbFetchOne($pdo, "SELECT advisor_level FROM users WHERE id = ?", [(int) $_SESSION['user_id']]);
    $sbLevel = is_array($sbRow) ? $sbRow['advisor_level'] : null;

    $sbSql    = "SELECT COUNT(*) FROM student_course_registrations scr
                 JOIN student_profiles sp ON sp.user_id = scr.student_id
                 WHERE scr.status = 'pending'
                   AND scr.semester_id = (
                       SELECT s.id FROM semesters s
                       JOIN academic_sessions ac ON s.session_id = ac.id
                       WHERE s.is_current = 1 AND ac.is_current = 1
                       LIMIT 1
                   )";
    $sbParams = [];
    if ($sbLevel) {
        $sbSql     .= " AND sp.level = ?";
        $sbParams[] = $sbLevel;
    }
    $pendingRegistrationCount = dbCount($pdo, $sbSql, $sbParams);
} catch (PDOException $e) {
    $pendingRegistrationCount = 0;
}

$fullName  = $_SESSION['full_name'] ?? 'Advisor';
$nameParts = explode(' ', trim($fullName));
$initials  = '';
foreach ($nameParts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if (empty($initials)) { $initials = 'A'; }
?>

<aside class="portal-sidebar" id="portalSidebar" role="navigation" aria-label="Advisor navigation">

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

        <div class="sidebar-section-label">Advisor Menu</div>

        <!-- 1. Dashboard -->
        <a href="<?= APP_URL ?>/pages/advisor/dashboard.php" class="<?= advisorNavClass('dashboard.php', $currentPage) ?>" title="Dashboard">
            <i class="fa-solid fa-gauge-high nav-icon"></i>
            <span>Overview</span>
        </a>

        <!-- 2. Manage Students (NEW) -->
        <a href="<?= APP_URL ?>/pages/advisor/manage-students.php" class="<?= advisorNavClass('manage-students.php', $currentPage) ?>" title="Manage Students">
            <i class="fa-solid fa-user-plus nav-icon"></i>
            <span>Manage Students</span>
        </a>
                <!-- 2b. Approve Registrations -->
        <a href="<?= APP_URL ?>/pages/advisor/approve-registrations.php" class="<?= advisorNavClass('approve-registrations.php', $currentPage) ?>" title="Approve Registrations">
            <i class="fa-solid fa-user-check nav-icon"></i>
            <span>Approve Registrations</span>
            <?php if ($pendingRegistrationCount > 0): ?>
                <span class="sidebar-badge"><?= $pendingRegistrationCount ?></span>
            <?php endif; ?>
        </a>

        <!-- 3. Review Results -->
        <a href="<?= APP_URL ?>/pages/advisor/review-results.php" class="<?= advisorNavClass('review-results.php', $currentPage) ?>" title="Review Results">
            <i class="fa-solid fa-magnifying-glass-chart nav-icon"></i>
            <span>Review Results</span>
            <?php if ($pendingReviewCount > 0): ?>
                <span class="sidebar-badge"><?= $pendingReviewCount ?></span>
            <?php endif; ?>
        </a>

        <!-- 4. Student Search -->
        <a href="<?= APP_URL ?>/pages/advisor/student-search.php" class="<?= advisorNavClass('student-search.php', $currentPage) ?>" title="Student Search">
            <i class="fa-solid fa-users-viewfinder nav-icon"></i>
            <span>Student Search</span>
        </a>

        <!-- 5. Carryover List -->
        <a href="<?= APP_URL ?>/pages/advisor/carryover-list.php" class="<?= advisorNavClass('carryover-list.php', $currentPage) ?>" title="Carryover List">
            <i class="fa-solid fa-rotate nav-icon"></i>
            <span>Carryover List</span>
            <?php if ($pendingCarryoverCount > 0): ?>
                <span class="sidebar-badge"><?= $pendingCarryoverCount ?></span>
            <?php endif; ?>
        </a>

        <!-- Quick Stats Block -->
        <div class="sidebar-section-label" style="margin-top:16px;">Pipeline Stats</div>
        <div class="sidebar-info-block">
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-inbox"></i> Awaiting Review
                </span>
                <span class="sidebar-info-value" style="color:<?= $pendingReviewCount > 0 ? '#006400' : 'inherit' ?>">
                    <?= $pendingReviewCount ?>
                </span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-rotate"></i> Carryover Pending
                </span>
                <span class="sidebar-info-value" style="color:<?= $pendingCarryoverCount > 0 ? '#006400' : 'inherit' ?>">
                    <?= $pendingCarryoverCount ?>
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
                    <?= htmlspecialchars(strlen($fullName) > 18 ? substr($fullName, 0, 18) . '...' : $fullName) ?>
                </div>
                <div class="sidebar-user-role">Course Advisor</div>
            </div>
        </div>
        <a href="<?= APP_URL ?>/auth/logout.php" class="sidebar-logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Sign Out</span>
        </a>
    </div>

</aside>