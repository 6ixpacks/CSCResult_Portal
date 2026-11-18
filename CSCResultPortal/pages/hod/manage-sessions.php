<?php

// Purpose: Full control over academic sessions and semesters.
//          Create, activate, set dates, and manage transitions.
// ============================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ====ACCESS CONTROL==========
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $_SESSION['role'] !== 'hod'
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

// ============================HANDLE FORM ACTIONS============================

$formMessage = '';
$formSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // ==============ACTION 1: CREATE NEW SESSION==============
    if ($formAction === 'create_session') {
        $sessionName = trim($_POST['session_name'] ?? '');
        $createBothSemesters = isset($_POST['create_semesters']) ? 1 : 0;
        $firstStart  = trim($_POST['first_start']  ?? '');
        $firstEnd    = trim($_POST['first_end']    ?? '');
        $secondStart = trim($_POST['second_start'] ?? '');
        $secondEnd   = trim($_POST['second_end']   ?? '');

        // Validate session name format (e.g., 2024/2025)
        if (!preg_match('/^\d{4}\/\d{4}$/', $sessionName)) {
            $formMessage = 'Session name must be in format YYYY/YYYY (e.g., 2024/2025).';
        } else {
            $years = explode('/', $sessionName);
            if ((int)$years[1] !== (int)$years[0] + 1) {
                $formMessage = 'Session years must be consecutive (e.g., 2024/2025).';
            } else {
                try {
                    $existing = dbFetchOne($pdo, "SELECT id FROM academic_sessions WHERE session_name = ?", [$sessionName]);
                    
                    if (is_array($existing)) {
                        $formMessage = "Session '{$sessionName}' already exists.";
                    } else {
                        $pdo->beginTransaction();
                        
                        $sessionId = dbInsert($pdo,
                            "INSERT INTO academic_sessions (session_name, is_current) VALUES (?, 0)",
                            [$sessionName]
                        );

                        // Create both semesters automatically if requested
                        if ($createBothSemesters) {
                            dbInsert($pdo,
                                "INSERT INTO semesters (session_id, semester_name, start_date, end_date, is_current) 
                                 VALUES (?, 'First Semester', ?, ?, 0)",
                                [$sessionId, 
                                 !empty($firstStart) ? $firstStart : null, 
                                 !empty($firstEnd)   ? $firstEnd   : null]
                            );

                            dbInsert($pdo,
                                "INSERT INTO semesters (session_id, semester_name, start_date, end_date, is_current) 
                                 VALUES (?, 'Second Semester', ?, ?, 0)",
                                [$sessionId, 
                                 !empty($secondStart) ? $secondStart : null, 
                                 !empty($secondEnd)   ? $secondEnd   : null]
                            );
                        }

                        writeAuditLog($pdo, $hodId, 'create_session', 'academic_sessions', $sessionId,
                            null, ['session_name' => $sessionName]);

                        $pdo->commit();
                        $formSuccess = true;
                        $formMessage = "Session '{$sessionName}' created successfully" 
                                    . ($createBothSemesters ? ' with both semesters.' : '.');
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $formMessage = 'Database error creating session.';
                    logDatabaseError('Create session error: ' . $e->getMessage());
                }
            }
        }
    }

    // ==============ACTION 2: ACTIVATE SESSION (set as current)==============
    if ($formAction === 'activate_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        
        if ($sessionId > 0) {
            try {
                $pdo->beginTransaction();
                
                // Deactivate all sessions and semesters first
                dbExecute($pdo, "UPDATE academic_sessions SET is_current = 0");
                dbExecute($pdo, "UPDATE semesters SET is_current = 0");
                
                // Activate the chosen session
                dbExecute($pdo, "UPDATE academic_sessions SET is_current = 1 WHERE id = ?", [$sessionId]);
                
                $sessionInfo = dbFetchOne($pdo, "SELECT session_name FROM academic_sessions WHERE id = ?", [$sessionId]);
                
                writeAuditLog($pdo, $hodId, 'activate_session', 'academic_sessions', $sessionId,
                    null, ['session_name' => $sessionInfo['session_name'] ?? '']);
                
                $pdo->commit();
                $formSuccess = true;
                $formMessage = "Session '{$sessionInfo['session_name']}' is now the active session. " 
                            . "Remember to also activate a semester within it.";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $formMessage = 'Error activating session.';
                logDatabaseError('Activate session error: ' . $e->getMessage());
            }
        }
    }

    // ==============ACTION 3: ACTIVATE SEMESTER (set as current)==============
    if ($formAction === 'activate_semester') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        
        if ($semesterId > 0) {
            try {
                $pdo->beginTransaction();
                
                // Get semester info
                $semInfo = dbFetchOne($pdo,
                    "SELECT s.semester_name, s.session_id, ac.session_name
                     FROM semesters s
                     JOIN academic_sessions ac ON s.session_id = ac.id
                     WHERE s.id = ?",
                    [$semesterId]
                );
                
                if (!is_array($semInfo)) {
                    throw new Exception('Semester not found.');
                }
                
                // Deactivate all other semesters
                dbExecute($pdo, "UPDATE semesters SET is_current = 0");
                
                // Ensure the parent session is also active
                dbExecute($pdo, "UPDATE academic_sessions SET is_current = 0");
                dbExecute($pdo, "UPDATE academic_sessions SET is_current = 1 WHERE id = ?", [$semInfo['session_id']]);
                
                // Activate this semester
                dbExecute($pdo, "UPDATE semesters SET is_current = 1 WHERE id = ?", [$semesterId]);
                
                writeAuditLog($pdo, $hodId, 'activate_semester', 'semesters', $semesterId,
                    null, ['semester' => $semInfo['semester_name'] . ' - ' . $semInfo['session_name']]);
                
                $pdo->commit();
                $formSuccess = true;
                $formMessage = "'{$semInfo['session_name']} - {$semInfo['semester_name']}' is now the ACTIVE semester. "
                            . "Lecturers can now enter scores for this period.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $formMessage = 'Error: ' . $e->getMessage();
                logDatabaseError('Activate semester error: ' . $e->getMessage());
            }
        }
    }

    // ==============
    // ACTION 4: ADD SEMESTER TO EXISTING SESSION
    // ==============
    if ($formAction === 'add_semester') {
        $sessionId    = (int) ($_POST['session_id'] ?? 0);
        $semesterName = $_POST['semester_name'] ?? '';
        $startDate    = trim($_POST['start_date'] ?? '');
        $endDate      = trim($_POST['end_date']   ?? '');

        if (!in_array($semesterName, ['First Semester', 'Second Semester'])) {
            $formMessage = 'Invalid semester name.';
        } elseif ($sessionId <= 0) {
            $formMessage = 'Invalid session.';
        } else {
            try {
                // Check if this semester already exists in that session
                $exists = dbFetchOne($pdo,
                    "SELECT id FROM semesters WHERE session_id = ? AND semester_name = ?",
                    [$sessionId, $semesterName]
                );

                if (is_array($exists)) {
                    $formMessage = "'{$semesterName}' already exists in this session.";
                } else {
                    dbInsert($pdo,
                        "INSERT INTO semesters (session_id, semester_name, start_date, end_date, is_current) 
                         VALUES (?, ?, ?, ?, 0)",
                        [$sessionId, $semesterName, 
                         !empty($startDate) ? $startDate : null, 
                         !empty($endDate)   ? $endDate   : null]
                    );

                    writeAuditLog($pdo, $hodId, 'create_semester', 'semesters', null,
                        null, ['session_id' => $sessionId, 'semester' => $semesterName]);

                    $formSuccess = true;
                    $formMessage = "'{$semesterName}' added successfully.";
                }
            } catch (PDOException $e) {
                $formMessage = 'Database error adding semester.';
                logDatabaseError('Add semester error: ' . $e->getMessage());
            }
        }
    }

    // ==============
    // ACTION 5: UPDATE SEMESTER DATES
    // ==============
    if ($formAction === 'update_semester_dates') {
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $startDate  = trim($_POST['start_date'] ?? '');
        $endDate    = trim($_POST['end_date']   ?? '');

        if ($semesterId > 0) {
            try {
                dbExecute($pdo,
                    "UPDATE semesters SET start_date = ?, end_date = ? WHERE id = ?",
                    [!empty($startDate) ? $startDate : null, 
                     !empty($endDate)   ? $endDate   : null, 
                     $semesterId]
                );

                writeAuditLog($pdo, $hodId, 'update_semester_dates', 'semesters', $semesterId);
                
                $formSuccess = true;
                $formMessage = "Semester dates updated successfully.";
            } catch (PDOException $e) {
                $formMessage = 'Error updating semester dates.';
            }
        }
    }

    // ==============
    // ACTION 6: END/DEACTIVATE CURRENT SEMESTER
    // ==============
    if ($formAction === 'end_current_semester') {
        try {
            $currentSem = dbFetchOne($pdo,
                "SELECT s.id, s.semester_name, ac.session_name
                 FROM semesters s
                 JOIN academic_sessions ac ON s.session_id = ac.id
                 WHERE s.is_current = 1 LIMIT 1"
            );

            if (is_array($currentSem)) {
                // Check for pending workflow items
                $pendingCount = dbCount($pdo,
                    "SELECT COUNT(*) FROM results r
                     WHERE r.semester_id = ? AND r.status IN ('draft', 'submitted', 'flagged', 'forwarded', 'hod_approved')",
                    [$currentSem['id']]
                );

                if ($pendingCount > 0 && !isset($_POST['force_end'])) {
                    $formMessage = "Cannot end semester: {$pendingCount} result(s) are still in the workflow. "
                                . "Complete final approvals first, or use 'Force End' to override.";
                } else {
                    dbExecute($pdo, "UPDATE semesters SET is_current = 0 WHERE id = ?", [$currentSem['id']]);
                    
                    writeAuditLog($pdo, $hodId, 'end_semester', 'semesters', $currentSem['id'],
                        null, ['semester' => $currentSem['semester_name'] . ' - ' . $currentSem['session_name'],
                               'forced' => isset($_POST['force_end']) ? 'yes' : 'no']);
                    
                    $formSuccess = true;
                    $formMessage = "'{$currentSem['session_name']} - {$currentSem['semester_name']}' has been ended. "
                                . "Activate the next semester when ready.";
                }
            } else {
                $formMessage = 'No active semester to end.';
            }
        } catch (PDOException $e) {
            $formMessage = 'Error ending semester.';
        }
    }
}

// ============================
// FETCH DATA FOR DISPLAY
// ============================

// Current active session and semester
$currentInfo = null;
try {
    $currentInfo = dbFetchOne($pdo,
        "SELECT ac.id AS session_id, ac.session_name,
                s.id AS semester_id, s.semester_name, s.start_date, s.end_date
         FROM academic_sessions ac
         LEFT JOIN semesters s ON s.session_id = ac.id AND s.is_current = 1
         WHERE ac.is_current = 1 LIMIT 1"
    );
} catch (PDOException $e) {}

// All sessions with their semesters
$allSessions = [];
try {
    $sessions = dbFetchAll($pdo,
        "SELECT id, session_name, is_current, created_at 
         FROM academic_sessions 
         ORDER BY session_name DESC"
    );

    foreach ($sessions as $sess) {
        $semesters = dbFetchAll($pdo,
            "SELECT s.id, s.semester_name, s.is_current, s.start_date, s.end_date,
                    (SELECT COUNT(*) FROM course_assignments WHERE semester_id = s.id) AS course_count,
                    (SELECT COUNT(*) FROM results WHERE semester_id = s.id) AS result_count,
                    (SELECT COUNT(*) FROM results WHERE semester_id = s.id AND status = 'final_approved') AS approved_count
             FROM semesters s
             WHERE s.session_id = ?
             ORDER BY s.semester_name ASC",
            [$sess['id']]
        );
        
        $sess['semesters'] = $semesters;
        $allSessions[] = $sess;
    }
} catch (PDOException $e) {
    $allSessions = [];
}

// Count pending items in current semester
$pendingInCurrent = 0;
if (is_array($currentInfo) && !empty($currentInfo['semester_id'])) {
    try {
        $pendingInCurrent = dbCount($pdo,
            "SELECT COUNT(*) FROM results r
             WHERE r.semester_id = ? AND r.status IN ('draft', 'submitted', 'flagged', 'forwarded', 'hod_approved')",
            [$currentInfo['semester_id']]
        );
    } catch (PDOException $e) {}
}

$pageTitle      = 'Sessions & Semesters';
$pageBreadcrumb = 'HOD / Sessions & Semesters';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions &amp; Semesters &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/hod.css">
    <style>
        /* Session Management Specific Styles */
        .current-status-card {
            background: linear-gradient(135deg, #006400 0%, #008000 60%, #004d00 100%);
            border-radius: 12px;
            padding: 24px 28px;
            color: #fff;
            box-shadow: 0 4px 20px rgba(0,100,0,0.25);
        }
        .current-status-card .label {
            font-size: 12px;
            color: rgba(255,255,255,0.75);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .current-status-card .value {
            font-size: 22px;
            font-weight: 700;
            color: #FFD700;
            margin-top: 4px;
        }
        .session-card {
            border-left: 4px solid #ccc;
            transition: all 0.2s;
        }
        .session-card.active {
            border-left-color: #006400;
            background: #f4fff4;
        }
        .semester-row {
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 10px;
            background: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .semester-row.active {
            border-color: #006400;
            border-width: 2px;
            background: #f4fff4;
        }
        .semester-info {
            flex: 1;
            min-width: 200px;
        }
        .semester-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        .semester-badge.active {
            background: #006400;
            color: #fff;
        }
        .semester-badge.inactive {
            background: #e0e0e0;
            color: #666;
        }
        .quick-action-card {
            background: #fff8cc;
            border: 1px solid #FFD700;
            border-radius: 10px;
            padding: 20px;
        }
    </style>
</head>
<body>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="portal-wrapper">
    <?php require_once __DIR__ . '/../../includes/hod-sidebar.php'; ?>

    <div class="portal-main">
        <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

        <main class="portal-content">
            <div class="page-header">
                <h1 class="page-header-title">
                    <i class="fa-solid fa-calendar-days me-2 text-green"></i>
                    Sessions &amp; Semesters
                </h1>
                <p class="page-header-subtitle">
                    Create and manage academic sessions. Activate the current session and semester for the department.
                </p>
                <hr class="page-header-divider">
            </div>

            <!-- Form Message -->
            <?php if (!empty($formMessage)): ?>
                <div class="portal-alert <?= $formSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $formSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($formMessage) ?></div>
                </div>
            <?php endif; ?>

            <!-- ============================
                 CURRENT STATUS CARD
                 ============================ -->
            <div class="current-status-card mb-4">
                <div class="row g-4 align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <i class="fa-solid fa-calendar-check" style="font-size:32px;color:#FFD700;"></i>
                            <h3 style="margin:0;color:#fff;">Current Active Period</h3>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="label">Active Session</div>
                                <div class="value">
                                    <?= is_array($currentInfo) ? htmlspecialchars($currentInfo['session_name'] ?? 'NONE') : 'NONE SET' ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="label">Active Semester</div>
                                <div class="value">
                                    <?= (is_array($currentInfo) && !empty($currentInfo['semester_name'])) 
                                        ? htmlspecialchars($currentInfo['semester_name']) 
                                        : 'NONE SET' ?>
                                </div>
                            </div>
                            <?php if (is_array($currentInfo) && !empty($currentInfo['start_date'])): ?>
                                <div class="col-6">
                                    <div class="label">Start Date</div>
                                    <div class="value" style="font-size:16px;">
                                        <?= date('d F Y', strtotime($currentInfo['start_date'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (is_array($currentInfo) && !empty($currentInfo['end_date'])): ?>
                                <div class="col-6">
                                    <div class="label">End Date</div>
                                    <div class="value" style="font-size:16px;">
                                        <?= date('d F Y', strtotime($currentInfo['end_date'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <?php if (is_array($currentInfo) && !empty($currentInfo['semester_id'])): ?>
                            <form method="POST" action="" onsubmit="return confirm('End the current semester? Ensure all results are approved first.')">
                                <input type="hidden" name="form_action" value="end_current_semester">
                                <button type="submit" class="btn-portal-danger">
                                    <i class="fa-solid fa-flag-checkered"></i> End This Semester
                                </button>
                                <?php if ($pendingInCurrent > 0): ?>
                                    <div class="mt-2" style="font-size:11px;color:#FFD700;">
                                        ⚠️ <?= $pendingInCurrent ?> result(s) still in workflow
                                    </div>
                                <?php endif; ?>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-warning" style="background:rgba(255,215,0,0.15);border:1px solid rgba(255,215,0,0.5);color:#fff;">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                No active semester. Activate one below.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-4">

                <!-- LEFT: Sessions List -->
                <div class="col-lg-8">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-list"></i>
                                All Academic Sessions
                            </div>
                            <span class="status-badge submitted"><?= count($allSessions) ?> session(s)</span>
                        </div>
                        <div class="content-card-body">

                            <?php if (empty($allSessions)): ?>
                                <div class="table-empty-state">
                                    <i class="fa-solid fa-calendar-plus"></i>
                                    <p>No academic sessions created yet. Create your first session on the right.</p>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($allSessions as $sess): ?>
                                <div class="session-card content-card mb-3 <?= (int) $sess['is_current'] === 1 ? 'active' : '' ?>">
                                    <div class="content-card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                            <div>
                                                <h4 style="margin:0;color:#1a1a2e;">
                                                    <i class="fa-solid fa-calendar-alt me-2 text-green"></i>
                                                    <?= htmlspecialchars($sess['session_name']) ?>
                                                </h4>
                                                <small class="text-muted">Created: <?= date('d M Y', strtotime($sess['created_at'])) ?></small>
                                            </div>
                                            <div>
                                                <?php if ((int) $sess['is_current'] === 1): ?>
                                                    <span class="status-badge final_approved">
                                                        <i class="fa-solid fa-check-circle fa-xs"></i> ACTIVE SESSION
                                                    </span>
                                                <?php else: ?>
                                                    <form method="POST" action="" class="d-inline">
                                                        <input type="hidden" name="form_action" value="activate_session">
                                                        <input type="hidden" name="session_id" value="<?= (int) $sess['id'] ?>">
                                                        <button type="submit" class="btn-portal-outline btn-portal-sm"
                                                                onclick="return confirm('Activate <?= htmlspecialchars(addslashes($sess['session_name'])) ?> as the current session?')">
                                                            <i class="fa-solid fa-power-off"></i> Activate Session
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Semesters within this session -->
                                        <div class="mt-3">
                                            <div style="font-size:12px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">
                                                Semesters
                                            </div>
                                            
                                            <?php if (empty($sess['semesters'])): ?>
                                                <div class="text-muted-sm" style="padding:10px;background:#f8f9fa;border-radius:6px;">
                                                    No semesters. Add one below.
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($sess['semesters'] as $sem): ?>
                                                    <div class="semester-row <?= (int) $sem['is_current'] === 1 ? 'active' : '' ?>">
                                                        <div class="semester-info">
                                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                                <strong style="font-size:14px;">
                                                                    <?= htmlspecialchars($sem['semester_name']) ?>
                                                                </strong>
                                                                <?php if ((int) $sem['is_current'] === 1): ?>
                                                                    <span class="semester-badge active">
                                                                        <i class="fa-solid fa-circle-check"></i> CURRENT
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="semester-badge inactive">Inactive</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="text-muted-sm" style="font-size:12px;">
                                                                <?php if (!empty($sem['start_date']) && !empty($sem['end_date'])): ?>
                                                                    <i class="fa-solid fa-calendar-day"></i>
                                                                    <?= date('d M Y', strtotime($sem['start_date'])) ?>
                                                                    →
                                                                    <?= date('d M Y', strtotime($sem['end_date'])) ?>
                                                                <?php else: ?>
                                                                    <i class="fa-solid fa-calendar-xmark"></i> No dates set
                                                                <?php endif; ?>
                                                                &nbsp;•&nbsp;
                                                                <?= (int) ($sem['course_count'] ?? 0) ?> courses
                                                                &nbsp;•&nbsp;
                                                                <?= (int) ($sem['result_count'] ?? 0) ?> results
                                                                (<?= (int) ($sem['approved_count'] ?? 0) ?> approved)
                                                            </div>
                                                        </div>
                                                        <div class="d-flex gap-1">
                                                            <button type="button" 
                                                                    class="btn-portal-outline btn-portal-sm"
                                                                    title="Edit Dates"
                                                                    onclick="openEditModal(<?= (int) $sem['id'] ?>, '<?= htmlspecialchars(addslashes($sem['semester_name'])) ?>', '<?= htmlspecialchars($sem['start_date'] ?? '') ?>', '<?= htmlspecialchars($sem['end_date'] ?? '') ?>')">
                                                                <i class="fa-solid fa-pen"></i>
                                                            </button>
                                                            
                                                            <?php if ((int) $sem['is_current'] !== 1): ?>
                                                                <form method="POST" action="" class="d-inline">
                                                                    <input type="hidden" name="form_action" value="activate_semester">
                                                                    <input type="hidden" name="semester_id" value="<?= (int) $sem['id'] ?>">
                                                                    <button type="submit" class="btn-portal-primary btn-portal-sm"
                                                                            onclick="return confirm('Set <?= htmlspecialchars(addslashes($sem['semester_name'])) ?> of <?= htmlspecialchars(addslashes($sess['session_name'])) ?> as the ACTIVE semester?')">
                                                                        <i class="fa-solid fa-power-off"></i> Activate
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>

                                            <!-- Add missing semester -->
                                            <?php
                                            $hasFirst  = false;
                                            $hasSecond = false;
                                            foreach ($sess['semesters'] as $sem) {
                                                if ($sem['semester_name'] === 'First Semester')  $hasFirst = true;
                                                if ($sem['semester_name'] === 'Second Semester') $hasSecond = true;
                                            }
                                            ?>
                                            <?php if (!$hasFirst || !$hasSecond): ?>
                                                <div class="mt-2 text-end">
                                                    <button type="button" 
                                                            class="btn-portal-outline btn-portal-sm"
                                                            onclick="openAddSemesterModal(<?= (int) $sess['id'] ?>, '<?= htmlspecialchars(addslashes($sess['session_name'])) ?>', <?= $hasFirst ? 'true' : 'false' ?>, <?= $hasSecond ? 'true' : 'false' ?>)">
                                                        <i class="fa-solid fa-plus"></i> Add Missing Semester
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        </div>
                    </div>
                </div>

                <!-- RIGHT: Create Session Form -->
                <div class="col-lg-4">
                    <div class="content-card">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-plus-circle"></i>
                                Create New Session
                            </div>
                        </div>
                        <div class="content-card-body">
                            <form method="POST" action="" id="createSessionForm">
                                <input type="hidden" name="form_action" value="create_session">

                                <div class="mb-3">
                                    <label class="portal-form-label">Session Name *</label>
                                    <input type="text" name="session_name" class="portal-form-control" 
                                           required placeholder="e.g. 2024/2025" 
                                           pattern="^\d{4}/\d{4}$" title="Format: YYYY/YYYY">
                                    <div class="text-muted-sm mt-1" style="font-size:11px;">
                                        Format: YYYY/YYYY (e.g., 2024/2025)
                                    </div>
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" 
                                           id="createSemesters" name="create_semesters" value="1" checked>
                                    <label class="form-check-label" for="createSemesters">
                                        Auto-create both semesters
                                    </label>
                                </div>

                                <div id="semesterDates">
                                    <hr>
                                    <h6 style="color:#006400;font-size:13px;">Semester Dates (Optional)</h6>
                                    
                                    <div class="mb-3">
                                        <label class="portal-form-label" style="font-size:12px;">
                                            First Semester Start
                                        </label>
                                        <input type="date" name="first_start" class="portal-form-control">
                                    </div>
                                    <div class="mb-3">
                                        <label class="portal-form-label" style="font-size:12px;">
                                            First Semester End
                                        </label>
                                        <input type="date" name="first_end" class="portal-form-control">
                                    </div>
                                    <div class="mb-3">
                                        <label class="portal-form-label" style="font-size:12px;">
                                            Second Semester Start
                                        </label>
                                        <input type="date" name="second_start" class="portal-form-control">
                                    </div>
                                    <div class="mb-3">
                                        <label class="portal-form-label" style="font-size:12px;">
                                            Second Semester End
                                        </label>
                                        <input type="date" name="second_end" class="portal-form-control">
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn-portal-primary">
                                        <i class="fa-solid fa-plus"></i> Create Session
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Info Card -->
                    <div class="quick-action-card mt-4">
                        <h6 style="color:#856404;font-weight:700;">
                            <i class="fa-solid fa-lightbulb"></i> Workflow Tips
                        </h6>
                        <ul style="font-size:12.5px;padding-left:20px;margin:10px 0 0;color:#5a4a00;line-height:1.8;">
                            <li><strong>Start of new session:</strong> Create the session, then activate it and its first semester.</li>
                            <li><strong>End of semester:</strong> Ensure all results are final-approved before ending the semester.</li>
                            <li><strong>Switch semesters:</strong> End current semester, then activate the next one.</li>
                            <li><strong>Only one</strong> session and one semester can be active at a time.</li>
                        </ul>
                    </div>
                </div>

            </div>

        </main>
    </div>
</div>

<!-- ============================
     EDIT SEMESTER DATES MODAL
     ============================ -->
<div class="modal fade" id="editSemesterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#006400;">
                <h5 class="modal-title text-white">
                    <i class="fa-solid fa-calendar-days me-2"></i>
                    Edit Semester Dates
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="update_semester_dates">
                <input type="hidden" name="semester_id" id="editSemesterId">
                <div class="modal-body">
                    <p class="text-muted mb-3" id="editSemesterLabel"></p>
                    
                    <div class="mb-3">
                        <label class="portal-form-label">Start Date</label>
                        <input type="date" name="start_date" id="editStartDate" class="portal-form-control">
                    </div>
                    <div class="mb-3">
                        <label class="portal-form-label">End Date</label>
                        <input type="date" name="end_date" id="editEndDate" class="portal-form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-portal-primary">
                        <i class="fa-solid fa-save"></i> Save Dates
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================
     ADD SEMESTER MODAL
     ============================ -->
<div class="modal fade" id="addSemesterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#006400;">
                <h5 class="modal-title text-white">
                    <i class="fa-solid fa-plus me-2"></i>
                    Add Missing Semester
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="form_action" value="add_semester">
                <input type="hidden" name="session_id" id="addSemSessionId">
                <div class="modal-body">
                    <p class="text-muted mb-3" id="addSemLabel"></p>
                    
                    <div class="mb-3">
                        <label class="portal-form-label">Semester *</label>
                        <select name="semester_name" id="addSemSelect" class="portal-form-control portal-select" required>
                            <option value="First Semester" id="firstSemOption">First Semester</option>
                            <option value="Second Semester" id="secondSemOption">Second Semester</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="portal-form-label">Start Date</label>
                        <input type="date" name="start_date" class="portal-form-control">
                    </div>
                    <div class="mb-3">
                        <label class="portal-form-label">End Date</label>
                        <input type="date" name="end_date" class="portal-form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-portal-primary">
                        <i class="fa-solid fa-plus"></i> Add Semester
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>

<script>
// Toggle semester dates visibility
document.getElementById('createSemesters').addEventListener('change', function() {
    document.getElementById('semesterDates').style.display = this.checked ? 'block' : 'none';
});

// Open Edit Semester Modal
function openEditModal(semId, name, startDate, endDate) {
    document.getElementById('editSemesterId').value    = semId;
    document.getElementById('editSemesterLabel').textContent = 'Editing: ' + name;
    document.getElementById('editStartDate').value     = startDate;
    document.getElementById('editEndDate').value       = endDate;
    
    const modal = new bootstrap.Modal(document.getElementById('editSemesterModal'));
    modal.show();
}

// Open Add Semester Modal
function openAddSemesterModal(sessionId, sessionName, hasFirst, hasSecond) {
    document.getElementById('addSemSessionId').value = sessionId;
    document.getElementById('addSemLabel').textContent = 'Adding semester to: ' + sessionName;
    
    const firstOpt  = document.getElementById('firstSemOption');
    const secondOpt = document.getElementById('secondSemOption');
    
    firstOpt.disabled  = hasFirst;
    secondOpt.disabled = hasSecond;
    
    // Auto-select the missing one
    if (!hasFirst)       document.getElementById('addSemSelect').value = 'First Semester';
    else if (!hasSecond) document.getElementById('addSemSelect').value = 'Second Semester';
    
    const modal = new bootstrap.Modal(document.getElementById('addSemesterModal'));
    modal.show();
}
</script>
</body>
</html>