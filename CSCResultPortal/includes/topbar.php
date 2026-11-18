<?php
// ============================================================
// CSCResultPortal - Reusable Topbar (WITH TOPBAR LOGOUT BUTTON)
// File: includes/topbar.php
// ============================================================

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . APP_URL . '/index.php');
    exit();
}

// Fetch unread notification count
$unreadCount = 0;
try {
    $unreadCount = dbCount($pdo,
        "SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0",
        [$_SESSION['user_id']]
    );
} catch (PDOException $e) {
    $unreadCount = 0;
}

// Fetch current session & semester
$currentSession  = '';
$currentSemester = '';
try {
    $sessionData = dbFetchOne($pdo,
        "SELECT ac.session_name, s.semester_name
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE s.is_current = 1 AND ac.is_current = 1
         LIMIT 1"
    );
    if (is_array($sessionData)) {
        $currentSession  = $sessionData['session_name'] ?? '';
        $currentSemester = $sessionData['semester_name'] ?? '';
    }
} catch (PDOException $e) {}

// Build initials
$fullName  = $_SESSION['full_name'] ?? 'User';
$nameParts = explode(' ', trim($fullName));
$initials  = '';
foreach ($nameParts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if (empty($initials)) { $initials = 'U'; }

$pageTitle      = isset($pageTitle)      ? $pageTitle      : 'Dashboard';
$pageBreadcrumb = isset($pageBreadcrumb) ? $pageBreadcrumb : 'Dashboard';

$roleLabels = [
    'student'  => 'Student',
    'lecturer' => 'Lecturer',
    'advisor'  => 'Course Advisor',
    'hod'      => 'Head of Department',
];
$roleDisplay = $roleLabels[$_SESSION['role'] ?? ''] ?? ucfirst($_SESSION['role'] ?? '');
?>

<!-- TOPBAR HTML -->
<header class="portal-topbar" id="portalTopbar">

    <!-- LEFT: Hamburger + Page Title -->
    <div class="topbar-left">
        <button class="topbar-hamburger d-lg-none" id="sidebarToggleBtn" type="button" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div>
            <div class="topbar-page-title"><?= htmlspecialchars($pageTitle) ?></div>
            <div class="topbar-breadcrumb">
                <i class="fa-solid fa-house fa-xs me-1"></i>
                <?= htmlspecialchars($pageBreadcrumb) ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: Session Badge + Notifications + User Chip + LOGOUT BUTTON -->
    <div class="topbar-right">

        <?php if (!empty($currentSession)): ?>
            <div class="topbar-session-badge d-none d-md-flex align-items-center gap-1">
                <i class="fa-solid fa-calendar-alt fa-xs"></i>
                <?= htmlspecialchars($currentSession) ?> &mdash; <?= htmlspecialchars($currentSemester) ?>
            </div>
        <?php endif; ?>

        <!-- Notification Bell -->
        <div class="topbar-notif-wrapper" style="position:relative;">
            <button class="topbar-notif-btn" id="notifBellBtn" type="button" aria-label="Notifications" title="Notifications">
                <i class="fa-solid fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="topbar-notif-count"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                <?php endif; ?>
            </button>

            <!-- Dropdown -->
            <div class="notif-dropdown" id="notifDropdown" aria-hidden="true">
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-title"><i class="fa-solid fa-bell me-2"></i>Notifications</span>
                    <?php if ($unreadCount > 0): ?>
                        <button class="notif-mark-all-btn" id="markAllReadBtn" type="button">Mark all read</button>
                    <?php endif; ?>
                </div>
                <div class="notif-dropdown-body" id="notifList">
                    <div class="notif-loading">
                        <span class="spinner-border spinner-border-sm text-success me-2"></span>Loading notifications...
                    </div>
                </div>
            </div>
        </div>

        <!-- User Chip -->
        <div class="topbar-user-chip" id="topbarUserChip" title="Signed in as <?= htmlspecialchars($fullName) ?>">
            <div class="topbar-user-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="topbar-user-details d-none d-md-block">
                <div class="topbar-user-name"><?= htmlspecialchars(strlen($fullName) > 18 ? substr($fullName, 0, 18) . '...' : $fullName) ?></div>
                <div class="topbar-user-role"><?= htmlspecialchars($roleDisplay) ?></div>
            </div>
        </div>

        <!-- ALWAYS-VISIBLE TOPBAR LOGOUT BUTTON -->
        <a href="<?= APP_URL ?>/auth/logout.php" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1 ms-1" style="border-radius:20px; font-weight:600; font-size:12px; padding:5px 12px;" title="Sign Out of Portal">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="d-none d-sm-inline">Logout</span>
        </a>

    </div>

</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>