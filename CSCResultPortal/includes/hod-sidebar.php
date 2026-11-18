<?php
// ============================================================
// CSCResultPortal - HOD Sidebar Navigation (FIXED)
// File: includes/hod-sidebar.php
// Purpose: Left sidebar for HOD role with logout button.
//          6 navigation items + logout footer.
// ============================================================

$currentPage = basename($_SERVER['PHP_SELF']);

function hodNavClass(string $page, string $current): string {
    return ($page === $current) ? 'sidebar-nav-item active' : 'sidebar-nav-item';
}

// ============================================================
// FETCH RESULTS FORWARDED TO HOD (awaiting HOD approval)
// ============================================================
$awaitingApprovalCount = 0;
try {
    $awaitingApprovalCount = dbCount($pdo,
        "SELECT COUNT(DISTINCT assignment_id)
         FROM results
         WHERE status = 'forwarded'",
        []
    );
} catch (PDOException $e) {
    $awaitingApprovalCount = 0;
}

// ============================================================
// FETCH RECENT BATCH UPLOAD STATUS
// ============================================================
$lastUpload = null;
try {
    $lastUpload = dbFetchOne($pdo,
        "SELECT status, uploaded_at, total_rows, successful_rows
         FROM batch_uploads
         WHERE uploaded_by = ?
         ORDER BY uploaded_at DESC
         LIMIT 1",
        [$_SESSION['user_id']]
    );
} catch (PDOException $e) {
    $lastUpload = null;
}

// ============================================================
// FETCH TOTAL STAFF COUNTS
// ============================================================
$lecturerCount = 0;
$advisorCount  = 0;
try {
    $lecturerCount = dbCount($pdo,
        "SELECT COUNT(*) FROM users WHERE role = 'lecturer' AND is_active = 1",
        []
    );
    $advisorCount = dbCount($pdo,
        "SELECT COUNT(*) FROM users WHERE role = 'advisor' AND is_active = 1",
        []
    );
} catch (PDOException $e) {
    $lecturerCount = 0;
    $advisorCount  = 0;
}

// Build initials
$fullName  = $_SESSION['full_name'] ?? 'HOD';
$nameParts = explode(' ', trim($fullName));
$initials  = '';
foreach ($nameParts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if (empty($initials)) { $initials = 'H'; }
?>

<!-- ============================================================
     HOD SIDEBAR (WITH FOOTER LOGOUT BUTTON)
     ============================================================ -->
<aside class="portal-sidebar" id="portalSidebar" role="navigation" aria-label="HOD navigation">

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

        <div class="sidebar-section-label">HOD Menu</div>

        <!-- 1. Overview + Pipeline -->
        <a
            href="<?= APP_URL ?>/pages/hod/dashboard.php"
            class="<?= hodNavClass('dashboard.php', $currentPage) ?>"
            title="Overview & Pipeline"
        >
            <i class="fa-solid fa-gauge-high nav-icon"></i>
            <span>Overview &amp; Pipeline</span>
        </a>

        <!-- 2. Approve Results -->
        <a
            href="<?= APP_URL ?>/pages/hod/approve-results.php"
            class="<?= hodNavClass('approve-results.php', $currentPage) ?>"
            title="Approve Results"
        >
            <i class="fa-solid fa-clipboard-check nav-icon"></i>
            <span>Approve Results</span>
            <?php if ($awaitingApprovalCount > 0): ?>
                <span class="sidebar-badge"><?= $awaitingApprovalCount ?></span>
            <?php endif; ?>
        </a>

        <!-- 3. Manage Staff -->
        <a
            href="<?= APP_URL ?>/pages/hod/manage-staff.php"
            class="<?= hodNavClass('manage-staff.php', $currentPage) ?>"
            title="Manage Staff"
        >
            <i class="fa-solid fa-users-gear nav-icon"></i>
            <span>Manage Staff</span>
        </a>

        <!-- Manage Courses -->
<a
    href="<?= APP_URL ?>/pages/hod/manage-courses.php"
    class="<?= hodNavClass('manage-courses.php', $currentPage) ?>"
    title="Manage Courses"
>
    <i class="fa-solid fa-book nav-icon"></i>
    <span>Manage Courses</span>
</a>

        <!-- 4. Batch Upload -->
        <a
            href="<?= APP_URL ?>/pages/hod/batch-upload.php"
            class="<?= hodNavClass('batch-upload.php', $currentPage) ?>"
            title="Batch Upload"
        >
            <i class="fa-solid fa-file-arrow-up nav-icon"></i>
            <span>Batch Upload</span>
        </a>

        <!-- 5. Analytics -->
        <a
            href="<?= APP_URL ?>/pages/hod/analytics.php"
            class="<?= hodNavClass('analytics.php', $currentPage) ?>"
            title="Analytics"
        >
            <i class="fa-solid fa-chart-bar nav-icon"></i>
            <span>Analytics</span>
        </a>

        <!-- 6. Sessions & Semesters -->
        <a
            href="<?= APP_URL ?>/pages/hod/manage-sessions.php"
            class="<?= hodNavClass('manage-sessions.php', $currentPage) ?>"
            title="Sessions & Semesters"
        >
            <i class="fa-solid fa-calendar-days nav-icon"></i>
            <span>Sessions &amp; Semesters</span>
        </a>

        <!-- Department Stats Block -->
        <div class="sidebar-section-label" style="margin-top:16px;">Department Stats</div>
        <div class="sidebar-info-block">
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-chalkboard-teacher"></i> Lecturers
                </span>
                <span class="sidebar-info-value"><?= $lecturerCount ?></span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-user-tie"></i> Advisors
                </span>
                <span class="sidebar-info-value"><?= $advisorCount ?></span>
            </div>
            <div class="sidebar-info-row">
                <span class="sidebar-info-label">
                    <i class="fa-solid fa-hourglass-half"></i> Awaiting
                </span>
                <span class="sidebar-info-value"
                    style="color:<?= $awaitingApprovalCount > 0 ? '#FFD700' : 'inherit' ?>">
                    <?= $awaitingApprovalCount ?>
                </span>
            </div>
        </div>


    </nav>

    <!-- ============================================================
         SIDEBAR FOOTER: USER INFO + LOGOUT BUTTON
         ============================================================ -->
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
                <div class="sidebar-user-role">Head of Department</div>
            </div>
        </div>
        <a href="<?= APP_URL ?>/auth/logout.php" class="sidebar-logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Sign Out</span>
        </a>
    </div>

</aside>
<!-- /.portal-sidebar (hod) -->