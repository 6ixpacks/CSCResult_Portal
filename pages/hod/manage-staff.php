<?php
// ============================================================
// CSCResultPortal - HOD Manage Staff & Transfer Ownership
// File: pages/hod/manage-staff.php
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'hod'
) {
    header('Location: ' . APP_URL . '/index.php');
    exit();
}

if (
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT
) {
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/index.php?expired=1');
    exit();
}
$_SESSION['last_activity'] = time();

$hodId = (int) $_SESSION['user_id'];

$formMessage = '';
$formSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['form_action'] ?? '';

    // --------------------------------------------------------
    // 1. CREATE STAFF ACCOUNT
    // --------------------------------------------------------
    if ($postAction === 'create_staff') {
        $fullName     = trim($_POST['full_name']  ?? '');
        $email        = trim($_POST['email']      ?? '');
        $staffId      = trim($_POST['staff_id']   ?? '');
        $phone        = trim($_POST['phone']      ?? '');
        $role         = $_POST['role']            ?? '';
        $password     = $_POST['password']        ?? '';
        $password2    = $_POST['password2']       ?? '';
        $advisorLevel = $_POST['advisor_level']   ?? '';
        $validLevels  = ['100', '200', '300', '400', '500'];

        if (empty($fullName) || empty($email) || empty($staffId) || empty($role) || empty($password)) {
            $formMessage = 'All required fields must be filled.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formMessage = 'Please enter a valid email address.';
        } elseif (!in_array($role, ['lecturer', 'advisor'])) {
            $formMessage = 'Invalid role selected.';
        } elseif ($role === 'advisor' && !in_array($advisorLevel, $validLevels, true)) {
            $formMessage = 'Please select the level this advisor will be responsible for.';
        } elseif (strlen($password) < 8) {
            $formMessage = 'Password must be at least 8 characters long.';
        } elseif ($password !== $password2) {
            $formMessage = 'Passwords do not match.';
        } else {
            try {
                $existingEmail   = dbFetchOne($pdo, "SELECT id FROM users WHERE email = ?", [$email]);
                $existingStaffId = dbFetchOne($pdo, "SELECT id FROM users WHERE staff_id = ?", [$staffId]);

                // Only one ACTIVE advisor per level
                $levelTaken = null;
                if ($role === 'advisor') {
                    $levelTaken = dbFetchOne($pdo,
                        "SELECT full_name FROM users
                         WHERE role = 'advisor' AND is_active = 1 AND advisor_level = ?",
                        [$advisorLevel]
                    );
                }

                if (is_array($existingEmail)) {
                    $formMessage = 'An account with this email address already exists.';
                } elseif (is_array($existingStaffId)) {
                    $formMessage = 'This Staff ID is already assigned to another account.';
                } elseif (is_array($levelTaken)) {
                    $formMessage = "{$advisorLevel} Level already has an active advisor ({$levelTaken['full_name']}). Deactivate or reassign them first.";
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
                    $levelValue     = ($role === 'advisor') ? $advisorLevel : null;

                    $newUserId = dbInsert($pdo,
                        "INSERT INTO users (full_name, email, password_hash, role, staff_id, advisor_level, phone, is_active, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)",
                        [$fullName, $email, $hashedPassword, $role, $staffId, $levelValue, !empty($phone) ? $phone : null, $hodId]
                    );

                    writeAuditLog($pdo, $hodId, 'create_staff', 'users', $newUserId, null,
                        ['role' => $role, 'email' => $email, 'advisor_level' => $levelValue]);

                    $formSuccess = true;
                    $formMessage = ucfirst($role) . " account for {$fullName} created successfully.";
                }
            } catch (PDOException $e) {
                $formMessage = 'Database error creating account.';
                logDatabaseError('Create staff error: ' . $e->getMessage());
            }
        }
    }

    // --------------------------------------------------------
    // 2. EDIT STAFF ACCOUNT & DESIGNATE ROLE
    // --------------------------------------------------------
        if ($postAction === 'edit_staff') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $fullName     = trim($_POST['full_name']  ?? '');
        $email        = trim($_POST['email']      ?? '');
        $staffId      = trim($_POST['staff_id']   ?? '');
        $phone        = trim($_POST['phone']      ?? '');
        $role         = $_POST['role']            ?? '';
        $advisorLevel = $_POST['advisor_level']   ?? '';
        $validLevels  = ['100', '200', '300', '400', '500'];

        if ($targetUserId <= 0) {
            $formMessage = 'Invalid staff selection.';
        } elseif (empty($fullName) || empty($email) || empty($staffId) || empty($role)) {
            $formMessage = 'All required fields must be filled.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formMessage = 'Please enter a valid email address.';
        } elseif (!in_array($role, ['lecturer', 'advisor'])) {
            $formMessage = 'Invalid role selected.';
        } elseif ($role === 'advisor' && !in_array($advisorLevel, $validLevels, true)) {
            $formMessage = 'Please select the level this advisor will be responsible for.';
        } else {
            try {
                $dupEmail = dbFetchOne($pdo, "SELECT id FROM users WHERE email = ? AND id != ?", [$email, $targetUserId]);
                $dupStaff = dbFetchOne($pdo, "SELECT id FROM users WHERE staff_id = ? AND id != ?", [$staffId, $targetUserId]);

                // Only one ACTIVE advisor per level (ignore this person themselves)
                $levelTaken = null;
                $target = dbFetchOne($pdo, "SELECT is_active FROM users WHERE id = ?", [$targetUserId]);
                if ($role === 'advisor' && is_array($target) && (int) $target['is_active'] === 1) {
                    $levelTaken = dbFetchOne($pdo,
                        "SELECT full_name FROM users
                         WHERE role = 'advisor' AND is_active = 1 AND advisor_level = ? AND id != ?",
                        [$advisorLevel, $targetUserId]
                    );
                }

                if (is_array($dupEmail)) {
                    $formMessage = 'Email address is already in use by another user.';
                } elseif (is_array($dupStaff)) {
                    $formMessage = 'Staff ID is already in use by another user.';
                } elseif (is_array($levelTaken)) {
                    $formMessage = "{$advisorLevel} Level already has an active advisor ({$levelTaken['full_name']}).";
                } else {
                    $levelValue = ($role === 'advisor') ? $advisorLevel : null;

                    dbExecute($pdo,
                        "UPDATE users 
                         SET full_name = ?, email = ?, staff_id = ?, phone = ?, role = ?, advisor_level = ?
                         WHERE id = ? AND role != 'hod'",
                        [$fullName, $email, $staffId, !empty($phone) ? $phone : null, $role, $levelValue, $targetUserId]
                    );

                    writeAuditLog($pdo, $hodId, 'edit_staff', 'users', $targetUserId, null,
                        ['role' => $role, 'email' => $email, 'advisor_level' => $levelValue]);

                    $formSuccess = true;
                    $formMessage = "Staff details for {$fullName} updated successfully.";
                }
            } catch (PDOException $e) {
                $formMessage = 'Database error updating staff.';
                logDatabaseError('Edit staff error: ' . $e->getMessage());
            }
        }
    }
    // --------------------------------------------------------
    // 3. TRANSFER HOD OWNERSHIP / HANDOVER
    // --------------------------------------------------------
    if ($postAction === 'transfer_hod_ownership') {
        $successorId     = (int) ($_POST['successor_id'] ?? 0);
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($successorId <= 0) {
            $formMessage = 'Please select a valid staff member to receive HOD ownership.';
        } elseif (empty($confirmPassword)) {
            $formMessage = 'Password confirmation is required to execute HOD handover.';
        } else {
            try {
                // Verify current HOD password
                $currentHodUser = dbFetchOne($pdo, "SELECT password_hash, full_name FROM users WHERE id = ?", [$hodId]);

                if (!is_array($currentHodUser) || !password_verify($confirmPassword, $currentHodUser['password_hash'])) {
                    $formMessage = 'Incorrect password! HOD ownership transfer denied.';
                } else {
                    // Fetch successor info
                    $successor = dbFetchOne($pdo, "SELECT id, full_name, email FROM users WHERE id = ? AND is_active = 1", [$successorId]);

                    if (!is_array($successor)) {
                        $formMessage = 'Selected successor account is invalid or inactive.';
                    } else {
                        $pdo->beginTransaction();

                        // Step A: Convert current HOD to Lecturer
                        dbExecute($pdo, "UPDATE users SET role = 'lecturer' WHERE id = ?", [$hodId]);

                        // Step B: Promote Successor to HOD
                        dbExecute($pdo, "UPDATE users SET role = 'hod' WHERE id = ?", [$successorId]);

                        // Step C: Log Immutable Audit Trail
                        writeAuditLog($pdo, $hodId, 'hod_ownership_transferred', 'users', $successorId, 
                            ['previous_hod' => $currentHodUser['full_name']], 
                            ['new_hod' => $successor['full_name'], 'new_hod_email' => $successor['email']]
                        );

                        $pdo->commit();

                        // Logout Old HOD and redirect with notice
                        session_unset();
                        session_destroy();
                        header('Location: ' . APP_URL . '/index.php?logged_out=1&msg=' . urlencode("HOD Ownership successfully transferred to {$successor['full_name']}. Please log in with your new credentials."));
                        exit();
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $formMessage = 'Database error during HOD handover.';
                logDatabaseError('HOD transfer error: ' . $e->getMessage());
            }
        }
    }

    // --------------------------------------------------------
    // 4. TOGGLE STATUS & RESET PASSWORD
    // --------------------------------------------------------
        if ($postAction === 'toggle_status') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $newStatus    = (int) ($_POST['new_status'] ?? 1);

        if ($targetUserId > 0 && $targetUserId !== $hodId) {
            try {
                $blocked = false;

                // Don't reactivate an advisor if someone else now holds their level
                if ($newStatus === 1) {
                    $target = dbFetchOne($pdo, "SELECT role, advisor_level FROM users WHERE id = ?", [$targetUserId]);
                    if (is_array($target) && $target['role'] === 'advisor' && !empty($target['advisor_level'])) {
                        $clash = dbFetchOne($pdo,
                            "SELECT full_name FROM users
                             WHERE role = 'advisor' AND is_active = 1 AND advisor_level = ? AND id != ?",
                            [$target['advisor_level'], $targetUserId]
                        );
                        if (is_array($clash)) {
                            $blocked = true;
                            $formMessage = "{$target['advisor_level']} Level already has an active advisor ({$clash['full_name']}). Change one of their levels first.";
                        }
                    }
                }

                if (!$blocked) {
                    dbExecute($pdo, "UPDATE users SET is_active = ? WHERE id = ? AND role != 'hod'", [$newStatus, $targetUserId]);
                    writeAuditLog($pdo, $hodId, $newStatus ? 'activate_staff' : 'deactivate_staff', 'users', $targetUserId);
                    $formSuccess = true;
                    $formMessage = 'Staff account status updated successfully.';
                }
            } catch (PDOException $e) {}
        }
    }

    if ($postAction === 'reset_password') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $newPassword  = $_POST['new_password'] ?? '';

        if ($targetUserId > 0 && strlen($newPassword) >= 8) {
            try {
                $hashed = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
                dbExecute($pdo, "UPDATE users SET password_hash = ? WHERE id = ? AND role != 'hod'", [$hashed, $targetUserId]);
                writeAuditLog($pdo, $hodId, 'reset_staff_password', 'users', $targetUserId);
                $formSuccess = true;
                $formMessage = 'Staff password reset successfully.';
            } catch (PDOException $e) {}
        }
    }
}

// Fetch Staff Lists
$lecturers = [];
$advisors  = [];
$allStaff  = [];

try {
    $lecturers = dbFetchAll($pdo,
        "SELECT u.id, u.full_name, u.email, u.staff_id, u.phone, u.is_active, u.created_at,
                (SELECT COUNT(*) FROM course_assignments ca WHERE ca.lecturer_id = u.id) AS course_count
         FROM users u WHERE u.role = 'lecturer' ORDER BY u.is_active DESC, u.full_name ASC"
    );
        $advisors = dbFetchAll($pdo,
        "SELECT u.id, u.full_name, u.email, u.staff_id, u.phone, u.is_active, u.created_at, u.advisor_level
         FROM users u WHERE u.role = 'advisor'
         ORDER BY u.advisor_level IS NULL, u.advisor_level ASC, u.is_active DESC, u.full_name ASC"
    );
    $allStaff = dbFetchAll($pdo,
        "SELECT id, full_name, email, staff_id, role FROM users WHERE role IN ('lecturer', 'advisor') AND is_active = 1 ORDER BY full_name ASC"
    );
} catch (PDOException $e) {}

$pageTitle      = 'Manage Staff';
$pageBreadcrumb = 'HOD / Manage Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/hod.css">
</head>
<body>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="portal-wrapper">
    <?php require_once __DIR__ . '/../../includes/hod-sidebar.php'; ?>

    <div class="portal-main">
        <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

        <main class="portal-content">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h1 class="page-header-title">
                        <i class="fa-solid fa-users-gear me-2 text-green"></i>Manage Staff &amp; Leadership
                    </h1>
                    <p class="page-header-subtitle">
                        Create staff accounts, designate roles, or execute a safe HOD leadership handover.
                    </p>
                </div>
                <!-- Transfer HOD Ownership Button -->
                <button type="button" class="btn-portal-gold" data-bs-toggle="modal" data-bs-target="#transferHodModal">
                    <i class="fa-solid fa-crown me-1"></i> Transfer HOD Ownership
                </button>
            </div>
            <hr class="page-header-divider">

            <?php if (!empty($formMessage)): ?>
                <div class="portal-alert <?= $formSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $formSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($formMessage) ?></div>
                </div>
            <?php endif; ?>

            <div class="row g-4">

                <!-- Left Column: Create Account Form -->
                <div class="col-lg-4">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-user-plus"></i> Create Staff Account
                            </div>
                        </div>
                        <div class="content-card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="form_action" value="create_staff">

                                <div class="mb-3">
                                    <label class="portal-form-label">Role *</label>
                                    <select name="role" class="portal-form-control portal-select" required onchange="toggleAdvisorLevel(this.value)">
                                        <option value="">-- Select Role --</option>
                                        <option value="lecturer">Lecturer</option>
                                        <option value="advisor">Course Advisor</option>
                                    </select>
                                </div>
                                                                <div class="mb-3" id="advisorLevelGroup" style="display:none;">
                                    <label class="portal-form-label">Advisor for Level *</label>
                                    <select name="advisor_level" id="advisorLevelSelect" class="portal-form-control portal-select">
                                        <option value="">-- Select Level --</option>
                                        <option value="100">100 Level</option>
                                        <option value="200">200 Level</option>
                                        <option value="300">300 Level</option>
                                        <option value="400">400 Level</option>
                                        <option value="500">500 Level</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="portal-form-label">Full Name *</label>
                                    <input type="text" name="full_name" class="portal-form-control" required placeholder="e.g. Dr. Jane Okonkwo">
                                </div>
                                <div class="mb-3">
                                    <label class="portal-form-label">Email Address *</label>
                                    <input type="email" name="email" class="portal-form-control" required placeholder="e.g. j.okonkwo@futo.edu.ng">
                                </div>
                                <div class="mb-3">
                                    <label class="portal-form-label">Staff ID *</label>
                                    <input type="text" name="staff_id" class="portal-form-control" required placeholder="e.g. LEC/CSC/001">
                                </div>
                                <div class="mb-3">
                                    <label class="portal-form-label">Phone Number</label>
                                    <input type="tel" name="phone" class="portal-form-control" placeholder="e.g. 08012345678">
                                </div>
                                <div class="mb-3">
                                    <label class="portal-form-label">Password *</label>
                                    <input type="password" name="password" class="portal-form-control" required placeholder="Min. 8 characters">
                                </div>
                                <div class="mb-4">
                                    <label class="portal-form-label">Confirm Password *</label>
                                    <input type="password" name="password2" class="portal-form-control" required placeholder="Repeat password">
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn-portal-primary">
                                        <i class="fa-solid fa-user-plus"></i> Create Staff
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Staff Tables (Lecturers + Advisors) -->
                <div class="col-lg-8">

                    <!-- Lecturers Table -->
                    <div class="content-card mb-4">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-chalkboard-teacher"></i> Lecturers
                            </div>
                            <span class="status-badge submitted"><?= count($lecturers) ?></span>
                        </div>
                        <div style="overflow-x:auto;">
                            <?php if (count($lecturers) > 0): ?>
                                <table class="portal-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Staff ID / Email</th>
                                            <th class="text-center">Courses</th>
                                            <th>Status</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lecturers as $lec): ?>
                                            <tr>
                                                <td class="fw-600"><?= htmlspecialchars($lec['full_name']) ?></td>
                                                <td class="text-muted-sm">
                                                    <strong class="text-dark"><?= htmlspecialchars($lec['staff_id']) ?></strong><br>
                                                    <?= htmlspecialchars($lec['email']) ?>
                                                </td>
                                                <td class="text-center fw-600"><?= (int) $lec['course_count'] ?></td>
                                                <td>
                                                    <span class="status-badge <?= (int)$lec['is_active'] === 1 ? 'final_approved' : 'flagged' ?>">
                                                        <?= (int)$lec['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button class="btn-portal-primary btn-portal-sm" 
                                                                onclick="openEditStaffModal(<?= (int)$lec['id'] ?>, '<?= htmlspecialchars(addslashes($lec['full_name'])) ?>', '<?= htmlspecialchars(addslashes($lec['email'])) ?>', '<?= htmlspecialchars(addslashes($lec['staff_id'])) ?>', '<?= htmlspecialchars(addslashes($lec['phone'] ?? '')) ?>', 'lecturer')"
                                                                title="Edit Staff & Role">
                                                            <i class="fa-solid fa-pen"></i>
                                                        </button>
                                                        <form method="POST" action="" class="d-inline">
                                                            <input type="hidden" name="form_action" value="toggle_status">
                                                            <input type="hidden" name="target_user_id" value="<?= (int) $lec['id'] ?>">
                                                            <input type="hidden" name="new_status" value="<?= (int)$lec['is_active'] === 1 ? 0 : 1 ?>">
                                                            <button type="submit" class="btn-portal-sm <?= (int)$lec['is_active'] === 1 ? 'btn-portal-danger' : 'btn-portal-outline' ?>">
                                                                <i class="fa-solid <?= (int)$lec['is_active'] === 1 ? 'fa-ban' : 'fa-check' ?>"></i>
                                                            </button>
                                                        </form>
                                                        <button type="button" class="btn-portal-outline btn-portal-sm" onclick="openResetModal(<?= (int)$lec['id'] ?>, '<?= htmlspecialchars(addslashes($lec['full_name'])) ?>')">
                                                            <i class="fa-solid fa-key"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="table-empty-state"><p>No lecturers created yet.</p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Course Advisors Table -->
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-user-tie"></i> Designated Course Advisors
                            </div>
                            <span class="status-badge submitted"><?= count($advisors) ?></span>
                        </div>
                        <div style="overflow-x:auto;">
                            <?php if (count($advisors) > 0): ?>
                                <table class="portal-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Staff ID / Email</th>
                                                                                        <th>Level</th>
                                            <th>Status</th>
                                            <th class="text-end">Actions</th>
                                                                                            <td>
                                                    <?php if (!empty($adv['advisor_level'])): ?>
                                                        <span class="status-badge approved"><?= htmlspecialchars($adv['advisor_level']) ?>L</span>
                                                    <?php else: ?>
                                                        <span class="text-muted-sm">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($advisors as $adv): ?>
                                            <tr>
                                                <td class="fw-600"><?= htmlspecialchars($adv['full_name']) ?></td>
                                                <td class="text-muted-sm">
                                                    <strong class="text-dark"><?= htmlspecialchars($adv['staff_id']) ?></strong><br>
                                                    <?= htmlspecialchars($adv['email']) ?>
                                                </td>
                                                                                                <td>
                                                    <?php if (!empty($adv['advisor_level'])): ?>
                                                        <span class="status-badge approved"><?= htmlspecialchars($adv['advisor_level']) ?>L</span>
                                                    <?php else: ?>
                                                        <span class="text-muted-sm">Not set</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?= (int)$adv['is_active'] === 1 ? 'final_approved' : 'flagged' ?>">
                                                        <?= (int)$adv['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button class="btn-portal-primary btn-portal-sm" 
                                                                onclick="openEditStaffModal(<?= (int)$adv['id'] ?>, '<?= htmlspecialchars(addslashes($adv['full_name'])) ?>', '<?= htmlspecialchars(addslashes($adv['email'])) ?>', '<?= htmlspecialchars(addslashes($adv['staff_id'])) ?>', '<?= htmlspecialchars(addslashes($adv['phone'] ?? '')) ?>', 'advisor', '<?= htmlspecialchars($adv['advisor_level'] ?? '') ?>')"
                                                            <i class="fa-solid fa-pen"></i>
                                                        </button>
                                                        <form method="POST" action="" class="d-inline">
                                                            <input type="hidden" name="form_action" value="toggle_status">
                                                            <input type="hidden" name="target_user_id" value="<?= (int) $adv['id'] ?>">
                                                            <input type="hidden" name="new_status" value="<?= (int)$adv['is_active'] === 1 ? 0 : 1 ?>">
                                                            <button type="submit" class="btn-portal-sm <?= (int)$adv['is_active'] === 1 ? 'btn-portal-danger' : 'btn-portal-outline' ?>">
                                                                <i class="fa-solid <?= (int)$adv['is_active'] === 1 ? 'fa-ban' : 'fa-check' ?>"></i>
                                                            </button>
                                                        </form>
                                                        <button type="button" class="btn-portal-outline btn-portal-sm" onclick="openResetModal(<?= (int)$adv['id'] ?>, '<?= htmlspecialchars(addslashes($adv['full_name'])) ?>')">
                                                            <i class="fa-solid fa-key"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="table-empty-state"><p>No course advisors assigned yet.</p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /.col-lg-8 -->

            </div><!-- /.row -->

        </main>
    </div>
</div>

<!-- ============================================================
     TRANSFER HOD OWNERSHIP MODAL (HANDOVER)
     ============================================================ -->
<div class="modal fade" id="transferHodModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#006400;">
                <h5 class="modal-title text-white"><i class="fa-solid fa-crown me-2"></i>Transfer HOD Ownership (Handover)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="transfer_hod_ownership">
                <div class="modal-body">
                    <div class="alert alert-warning" style="font-size:12px; line-height:1.5;">
                        <i class="fa-solid fa-shield-halved me-1"></i>
                        <strong>Safe Transfer Guarantee:</strong> Transferring ownership will <strong>NOT</strong> damage or delete any past results, approvals, or audit logs. All past approvals remain permanently linked to your name in the database.
                    </div>

                    <div class="mb-3">
                        <label class="portal-form-label">Select Successor (New HOD) *</label>
                        <select name="successor_id" class="portal-form-control portal-select" required>
                            <option value="">-- Select Active Staff Member --</option>
                            <?php foreach ($allStaff as $s): ?>
                                <option value="<?= (int) $s['id'] ?>">
                                    <?= htmlspecialchars($s['full_name']) ?> (<?= htmlspecialchars($s['staff_id']) ?> &mdash; <?= ucfirst($s['role']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="portal-form-label">Confirm Your HOD Password *</label>
                        <input type="password" name="confirm_password" class="portal-form-control" required placeholder="Enter your current password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-portal-gold" onclick="return confirm('ARE YOU ABSOLUTELY SURE?\n\nYou will step down as HOD and become a Lecturer. The selected successor will become the new HOD.')">
                        <i class="fa-solid fa-crown me-1"></i> Confirm &amp; Handover Ownership
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT STAFF MODAL -->
<div class="modal fade" id="editStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#006400;">
                <h5 class="modal-title text-white"><i class="fa-solid fa-user-pen me-2"></i>Edit Staff &amp; Designate Role</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="edit_staff">
                <input type="hidden" name="target_user_id" id="editStaffId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="portal-form-label">Full Name *</label>
                        <input type="text" name="full_name" id="editFullName" class="portal-form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="portal-form-label">Email Address *</label>
                        <input type="email" name="email" id="editEmail" class="portal-form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="portal-form-label">Staff ID *</label>
                        <input type="text" name="staff_id" id="editStaffCode" class="portal-form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="portal-form-label">Phone Number</label>
                        <input type="tel" name="phone" id="editPhone" class="portal-form-control">
                    </div>
                    <div class="mb-3">
                        <label class="portal-form-label">Designated Role *</label>
                        <select name="role" id="editRole" class="portal-form-control portal-select" required onchange="toggleEditLevel(this.value)">
                            <option value="lecturer">Lecturer</option>
                            <option value="advisor">Course Advisor</option>
                        </select>
                        <small class="text-muted">Changing this will grant/revoke Course Advisor privileges.</small>
                    </div>
                                        <div class="mb-3" id="editLevelGroup" style="display:none;">
                        <label class="portal-form-label">Advisor for Level *</label>
                        <select name="advisor_level" id="editAdvisorLevel" class="portal-form-control portal-select">
                            <option value="">-- Select Level --</option>
                            <option value="100">100 Level</option>
                            <option value="200">200 Level</option>
                            <option value="300">300 Level</option>
                            <option value="400">400 Level</option>
                            <option value="500">500 Level</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-portal-primary"><i class="fa-solid fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#006400;">
                <h5 class="modal-title text-white"><i class="fa-solid fa-key me-2"></i>Reset Staff Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="reset_password">
                <input type="hidden" name="target_user_id" id="resetTargetUserId">
                <div class="modal-body">
                    <p class="text-muted" id="resetModalDesc"></p>
                    <div class="mb-3">
                        <label class="portal-form-label">New Password *</label>
                        <input type="password" name="new_password" class="portal-form-control" required minlength="8" placeholder="Min. 8 characters">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-portal-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script>
function toggleEditLevel(role) {
    const grp = document.getElementById('editLevelGroup');
    const sel = document.getElementById('editAdvisorLevel');
    const isAdvisor = (role === 'advisor');
    grp.style.display = isAdvisor ? 'block' : 'none';
    sel.required = isAdvisor;
    if (!isAdvisor) sel.value = '';
}

function openEditStaffModal(id, name, email, staffId, phone, role, level) {
    document.getElementById('editStaffId').value   = id;
    document.getElementById('editFullName').value  = name;
    document.getElementById('editEmail').value     = email;
    document.getElementById('editStaffCode').value = staffId;
    document.getElementById('editPhone').value     = phone;
    document.getElementById('editRole').value      = role;
    document.getElementById('editAdvisorLevel').value = level || '';
    toggleEditLevel(role);

    new bootstrap.Modal(document.getElementById('editStaffModal')).show();
}

function openResetModal(userId, userName) {
    document.getElementById('resetTargetUserId').value = userId;
    document.getElementById('resetModalDesc').innerHTML = `Resetting password for <strong>${userName}</strong>.`;
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

function toggleAdvisorLevel(role) {
    const grp = document.getElementById('advisorLevelGroup');
    const sel = document.getElementById('advisorLevelSelect');
    const isAdvisor = (role === 'advisor');
    grp.style.display = isAdvisor ? 'block' : 'none';
    sel.required = isAdvisor;
    if (!isAdvisor) sel.value = '';
}
</script>
</body>
</html>