<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../mail/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access Control
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $_SESSION['role'] !== 'advisor'
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

$advisorId = (int) $_SESSION['user_id'];
// Level this advisor is locked to (null = all levels)
$advLevel    = getAdvisorLevel($pdo, $advisorId);
$levelSql    = $advLevel ? " AND sp.level = ?" : "";
$levelParams = $advLevel ? [$advLevel] : [];

// ============================================================
// SECTION 1: HANDLE APPROVE / REJECT ACTION
// ============================================================
$actionMessage = '';
$actionSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['carryover_action'])) {
    $carryoverId   = isset($_POST['carryover_id']) ? (int) $_POST['carryover_id'] : 0;
    $newStatus     = $_POST['carryover_action']; // 'approved' or 'rejected'
    $advisorNote   = trim($_POST['advisor_note'] ?? '');

    if ($carryoverId <= 0 || !in_array($newStatus, ['approved', 'rejected'])) {
        $actionMessage = 'Invalid carryover action request.';
    } else {
        try {
            // Verify carryover exists and is pending
                        $carryover = dbFetchOne($pdo,
                "SELECT cr.*, u.full_name AS student_name, u.email AS student_email,
                        c.course_code, c.course_title
                 FROM carryover_registrations cr
                 JOIN users u ON cr.student_id = u.id
                 JOIN courses c ON cr.course_id = c.id
                 JOIN student_profiles sp ON sp.user_id = cr.student_id
                 WHERE cr.id = ? AND cr.status = 'pending'" . $levelSql,
                array_merge([$carryoverId], $levelParams)
            );

            if (!$carryover) {
                $actionMessage = 'Carryover request not found or already processed.';
            } else {
                // Update status
                dbExecute($pdo,
                    "UPDATE carryover_registrations
                     SET status = ?, advisor_note = ?, reviewed_by = ?, reviewed_at = NOW()
                     WHERE id = ?",
                    [$newStatus, $advisorNote, $advisorId, $carryoverId]
                );

                // Email student
                sendCarryoverProcessedEmail(
                    (int) $carryover['student_id'],
                    $carryover['student_email'],
                    $carryover['student_name'],
                    $carryover['course_code'],
                    $carryover['course_title'],
                    $newStatus,
                    $advisorNote,
                    $carryoverId,
                    $advisorId
                );

                // Audit log
                writeAuditLog($pdo, $advisorId, "carryover_{$newStatus}", 'carryover_registrations',
                    $carryoverId, ['status' => 'pending'], ['status' => $newStatus]
                );

                $actionSuccess = true;
                $actionMessage = "Carryover request for {$carryover['course_code']} has been {$newStatus}. Student notified by email.";
            }
        } catch (PDOException $e) {
            $actionMessage = 'Database error processing carryover.';
            logDatabaseError('Carryover action error: ' . $e->getMessage());
        }
    }
}

// ============================================================
// SECTION 2: FETCH CARRYOVER REGISTRATIONS
// Filter by status tab
// ============================================================
$activeTab = $_GET['tab'] ?? 'pending';
if (!in_array($activeTab, ['pending', 'approved', 'rejected', 'all'])) {
    $activeTab = 'pending';
}

$carryovers = [];
try {
    $whereClause = ($activeTab !== 'all') ? "WHERE cr.status = '{$activeTab}'" : '';
    $carryovers  = dbFetchAll($pdo,
        "SELECT cr.id, cr.status, cr.reason, cr.advisor_note, cr.created_at, cr.reviewed_at,
                u.full_name AS student_name, u.matric_number,
                c.course_code, c.course_title, c.credit_units,
                s.semester_name, ac.session_name,
                rv.full_name AS reviewed_by_name
         FROM carryover_registrations cr
         JOIN users u ON cr.student_id = u.id
         JOIN courses c ON cr.course_id = c.id
         JOIN semesters s ON cr.semester_id = s.id
         JOIN academic_sessions ac ON s.session_id = ac.id
         LEFT JOIN users rv ON cr.reviewed_by = rv.id
         {$whereClause}
         ORDER BY cr.created_at DESC"
    );
} catch (PDOException $e) {
    $carryovers = [];
}

// Tab counts
$tabCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
try {
        foreach (['pending', 'approved', 'rejected'] as $st) {
        $tabCounts[$st] = dbCount($pdo,
            "SELECT COUNT(*) FROM carryover_registrations cr
             JOIN student_profiles sp ON sp.user_id = cr.student_id
             WHERE cr.status = ?" . $levelSql,
            array_merge([$st], $levelParams)
        );
    }    $tabCounts['all'] = array_sum([$tabCounts['pending'], $tabCounts['approved'], $tabCounts['rejected']]);
} catch (PDOException $e) {}

$pageTitle      = 'Carryover List';
$pageBreadcrumb = 'Advisor / Carryover List';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carryover List &mdash; CSCResultPortal</title>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/futo-logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/css/advisor.css">
</head>
<body>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="portal-wrapper">
    <?php require_once __DIR__ . '/../../includes/advisor-sidebar.php'; ?>

    <div class="portal-main">
        <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

        <main class="portal-content">
            <div class="page-header">
                <h1 class="page-header-title">
                    <i class="fa-solid fa-rotate me-2 text-green"></i>
                    Carryover Registrations
                </h1>
                <p class="page-header-subtitle">
                    Review and process student carryover requests. Students are notified by email on approval or rejection.
                </p>
                <hr class="page-header-divider">
            </div>

            <!-- Action Feedback -->
            <?php if (!empty($actionMessage)): ?>
                <div class="portal-alert <?= $actionSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $actionSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($actionMessage) ?></div>
                </div>
            <?php endif; ?>

            <!-- Status Tabs -->
            <div class="content-card mb-4">
                <div class="content-card-body" style="padding:12px 24px;">
                    <div class="carryover-tabs">
                        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $tab => $label): ?>
                            <a href="carryover-list.php?tab=<?= $tab ?>"
                               class="carryover-tab <?= $activeTab === $tab ? 'active' : '' ?>">
                                <?= $label ?>
                                <span class="carryover-tab-count"><?= $tabCounts[$tab] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Carryover Table -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <i class="fa-solid fa-list-check"></i>
                        <?= ucfirst($activeTab) ?> Carryover Requests
                    </div>
                    <span class="status-badge submitted"><?= count($carryovers) ?> record(s)</span>
                </div>
                <div style="overflow-x:auto;">
                    <?php if (count($carryovers) > 0): ?>
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student Details</th>
                                    <th>Course</th>
                                    <th>Session / Semester</th>
                                    <th>Student Reason</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <?php if ($activeTab === 'pending' || $activeTab === 'all'): ?>
                                        <th class="text-end">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($carryovers as $idx => $co): ?>
                                    <tr>
                                        <td class="text-muted-sm"><?= $idx + 1 ?></td>
                                        <td>
                                            <div class="fw-700"><?= htmlspecialchars($co['matric_number']) ?></div>
                                            <div><?= htmlspecialchars($co['student_name']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-700 text-green"><?= htmlspecialchars($co['course_code']) ?></div>
                                            <div class="text-muted-sm"><?= htmlspecialchars($co['course_title']) ?></div>
                                            <div class="text-muted-sm"><?= (int) $co['credit_units'] ?> units</div>
                                        </td>
                                        <td class="text-muted-sm">
                                            <?= htmlspecialchars($co['session_name']) ?><br>
                                            <?= htmlspecialchars($co['semester_name']) ?>
                                        </td>
                                        <td>
                                            <div class="carryover-reason-text">
                                                <?= !empty($co['reason'])
                                                    ? htmlspecialchars($co['reason'])
                                                    : '<em class="text-muted">No reason provided</em>' ?>
                                            </div>
                                            <?php if (!empty($co['advisor_note'])): ?>
                                                <div class="advisor-note-display mt-1">
                                                    <i class="fa-solid fa-comment fa-xs me-1 text-green"></i>
                                                    <em><?= htmlspecialchars($co['advisor_note']) ?></em>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= htmlspecialchars($co['status']) ?>">
                                                <?= ucfirst(htmlspecialchars($co['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-muted-sm">
                                            <?= date('d M Y', strtotime($co['created_at'])) ?>
                                        </td>
                                        <?php if ($activeTab === 'pending' || ($activeTab === 'all' && $co['status'] === 'pending')): ?>
                                            <td class="text-end">
                                                <button class="btn-portal-primary btn-portal-sm mb-1"
                                                        onclick="openCarryoverModal(<?= $co['id'] ?>, '<?= htmlspecialchars(addslashes($co['course_code'])) ?>', '<?= htmlspecialchars(addslashes($co['student_name'])) ?>')">
                                                    <i class="fa-solid fa-pen"></i> Process
                                                </button>
                                            </td>
                                        <?php elseif ($activeTab !== 'pending'): ?>
                                            <td></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="table-empty-state">
                            <i class="fa-solid fa-rotate"></i>
                            <p>No <?= $activeTab ?> carryover requests found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Carryover Process Modal -->
<div class="modal fade" id="carryoverModal" tabindex="-1" aria-labelledby="carryoverModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#006400;">
                <h5 class="modal-title text-white" id="carryoverModalLabel">
                    <i class="fa-solid fa-rotate me-2"></i>Process Carryover Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="carryover-list.php?tab=<?= $activeTab ?>">
                <div class="modal-body">
                    <input type="hidden" name="carryover_id" id="modalCarryoverId">
                    <p id="modalDescription" class="text-muted mb-3"></p>
                    <div class="mb-3">
                        <label class="portal-form-label">Advisor Note (Optional)</label>
                        <textarea name="advisor_note" class="portal-form-control" rows="3"
                                  placeholder="Add a note for the student..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="carryover_action" value="rejected" class="btn-portal-danger">
                        <i class="fa-solid fa-circle-xmark"></i> Reject
                    </button>
                    <button type="submit" name="carryover_action" value="approved" class="btn-portal-primary">
                        <i class="fa-solid fa-circle-check"></i> Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/advisor.js"></script>
</body>
</html>