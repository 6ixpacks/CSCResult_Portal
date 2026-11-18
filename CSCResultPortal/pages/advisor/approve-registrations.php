<?php
// ============================================================
// CSCResultPortal - Advisor: Approve Course Registrations
// File: pages/advisor/approve-registrations.php
// Purpose: Advisor reviews students' pending course
//          registrations for the current semester and
//          approves or rejects them (via api/approve-registration.php).
// ============================================================

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- Access control ----------
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'advisor'
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

// CSRF token (sent to the API with every action)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// This advisor's level (NULL = no restriction)
$advLevel = null;
try {
    $lvRow    = dbFetchOne($pdo, "SELECT advisor_level FROM users WHERE id = ?", [(int) $_SESSION['user_id']]);
    $advLevel = is_array($lvRow) ? $lvRow['advisor_level'] : null;
} catch (PDOException $e) {
    $advLevel = null;
}
$levelSql    = $advLevel ? " AND sp.level = ?" : "";
$levelParams = $advLevel ? [$advLevel] : [];

// ---------- Current semester ----------
// NOTE: topbar.php overwrites $currentSemester, so we use $term.
$term = null;
try {
    $term = dbFetchOne($pdo,
        "SELECT s.id AS semester_id, s.semester_name, ac.session_name
         FROM semesters s
         JOIN academic_sessions ac ON s.session_id = ac.id
         WHERE s.is_current = 1 AND ac.is_current = 1
         LIMIT 1"
    );
} catch (PDOException $e) {
    $term = null;
}

// ---------- Pending queue + recently reviewed ----------
$pendingRows = [];
$recentRows  = [];

if (is_array($term)) {
    try {
        $pendingRows = dbFetchAll($pdo,
            "SELECT scr.id AS reg_id, scr.registration_date,
                    u.full_name, u.matric_number, sp.level,
                    c.course_code, c.course_title, c.credit_units
             FROM student_course_registrations scr
             JOIN users u   ON scr.student_id = u.id
             JOIN courses c ON scr.course_id  = c.id
             JOIN student_profiles sp ON sp.user_id = u.id
             WHERE scr.semester_id = ? AND scr.status = 'pending'" . $levelSql . "
             ORDER BY u.matric_number ASC, c.course_code ASC",
            array_merge([(int) $term['semester_id']], $levelParams)
        );

        $recentRows = dbFetchAll($pdo,
            "SELECT scr.status, scr.remarks, scr.reviewed_at,
                    u.full_name, u.matric_number,
                    c.course_code
             FROM student_course_registrations scr
             JOIN users u   ON scr.student_id = u.id
             JOIN courses c ON scr.course_id  = c.id
             JOIN student_profiles sp ON sp.user_id = u.id
             WHERE scr.semester_id = ?
               AND scr.status IN ('approved','rejected')
               AND scr.reviewed_at IS NOT NULL" . $levelSql . "
             ORDER BY scr.reviewed_at DESC
             LIMIT 15",
            array_merge([(int) $term['semester_id']], $levelParams)
        );
    } catch (PDOException $e) {
        logDatabaseError('Approve registrations page error: ' . $e->getMessage());
        $pendingRows = [];
        $recentRows  = [];
    }
}

$pageTitle      = 'Approve Registrations';
$pageBreadcrumb = 'Advisor / Approve Registrations';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Registrations &mdash; CSCResultPortal</title>
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
                    <i class="fa-solid fa-user-check me-2 text-green"></i>Approve Course Registrations
                </h1>
                <p class="page-header-subtitle">
                    Students can only have scores entered for courses you approve here.
                </p>
                <hr class="page-header-divider">
            </div>

            <div id="regAlert"></div>

            <?php if (!is_array($term)): ?>
                <div class="portal-alert warning mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>No active semester is set. Please contact the HOD office.</div>
                </div>
            <?php else: ?>

                <div class="portal-alert info mb-4">
                    <i class="fa-solid fa-calendar-alt"></i>
                    <div>
                        Showing registrations for
                        <strong><?= htmlspecialchars($term['session_name']) ?> &mdash;
                        <?= htmlspecialchars($term['semester_name']) ?></strong>
                                                <?php if ($advLevel): ?>
                            &bull; <strong><?= htmlspecialchars($advLevel) ?> Level</strong> students only
                        <?php else: ?>
                            &bull; <em>All levels (no level assigned to you yet)</em>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pending queue -->
                <div class="content-card mb-4">
                    <div class="content-card-header">
                        <div class="content-card-title">
                            <i class="fa-solid fa-hourglass-half"></i> Pending Queue
                            <span class="status-badge pending ms-2" id="pendingCount"><?= count($pendingRows) ?></span>
                        </div>
                        <?php if (!empty($pendingRows)): ?>
                            <button type="button" class="btn-portal-primary btn-portal-sm" id="bulkApproveBtn">
                                <i class="fa-solid fa-check-double"></i> Approve All
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="content-card-body">
                        <?php if (empty($pendingRows)): ?>
                            <div class="table-empty-state">
                                <i class="fa-solid fa-circle-check" style="color:#006400;"></i>
                                <p>All clear. No pending registrations.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="portal-table" id="pendingTable">
                                    <thead>
                                        <tr>
                                            <th>Matric No.</th>
                                            <th>Student</th>
                                            <th>Level</th>
                                            <th>Course</th>
                                            <th class="text-center">Units</th>
                                            <th>Submitted</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($pendingRows as $r): ?>
                                        <tr id="row-<?= (int) $r['reg_id'] ?>">
                                            <td><strong><?= htmlspecialchars($r['matric_number'] ?? '-') ?></strong></td>
                                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                                            <td><?= htmlspecialchars($r['level'] ?? '-') ?>L</td>
                                            <td>
                                                <strong><?= htmlspecialchars($r['course_code']) ?></strong>
                                                <div class="text-muted-sm"><?= htmlspecialchars($r['course_title']) ?></div>
                                            </td>
                                            <td class="text-center"><?= (int) $r['credit_units'] ?></td>
                                            <td class="text-muted-sm">
                                                <?= date('d M Y', strtotime($r['registration_date'])) ?>
                                            </td>
                                            <td class="text-end" style="white-space:nowrap;">
                                                <button type="button" class="btn-portal-primary btn-portal-sm"
                                                        onclick="reviewReg(<?= (int) $r['reg_id'] ?>, 'approved')"
                                                        title="Approve">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                                <button type="button" class="btn-portal-danger btn-portal-sm"
                                                        onclick="openReject(<?= (int) $r['reg_id'] ?>, '<?= htmlspecialchars($r['course_code'], ENT_QUOTES) ?>')"
                                                        title="Reject">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recently reviewed -->
                <?php if (!empty($recentRows)): ?>
                <div class="content-card mb-4">
                    <div class="content-card-header">
                        <div class="content-card-title">
                            <i class="fa-solid fa-clock-rotate-left"></i> Recently Reviewed
                        </div>
                    </div>
                    <div class="content-card-body">
                        <div class="table-responsive">
                            <table class="portal-table">
                                <thead>
                                    <tr>
                                        <th>Matric No.</th>
                                        <th>Student</th>
                                        <th>Course</th>
                                        <th>Decision</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentRows as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['matric_number'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                                        <td><?= htmlspecialchars($r['course_code']) ?></td>
                                        <td>
                                            <span class="status-badge <?= htmlspecialchars($r['status']) ?>">
                                                <?= ucfirst(htmlspecialchars($r['status'])) ?>
                                            </span>
                                            <?php if (!empty($r['remarks'])): ?>
                                                <div class="text-muted-sm"><?= htmlspecialchars($r['remarks']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted-sm">
                                            <?= date('d M Y, H:i', strtotime($r['reviewed_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject registration &mdash; <span id="rejectCourse"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="rejectReason" class="portal-form-label">Reason (the student will see this)</label>
                <textarea id="rejectReason" class="portal-form-control" rows="3" maxlength="255"
                          placeholder="e.g. This course is not for your level"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-portal-outline btn-portal-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-portal-danger btn-portal-sm" id="confirmRejectBtn">
                    <i class="fa-solid fa-xmark"></i> Reject
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const API   = window.APP_URL + '/api/approve-registration.php';
    const TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
    const alertBox = document.getElementById('regAlert');
    const modalEl  = document.getElementById('rejectModal');
    const modal    = modalEl ? new bootstrap.Modal(modalEl) : null;
    let rejectId   = 0;

    function showAlert(msg, ok) {
        alertBox.innerHTML =
            '<div class="portal-alert ' + (ok ? 'success' : 'danger') + ' mb-4">' +
            '<i class="fa-solid ' + (ok ? 'fa-circle-check' : 'fa-circle-exclamation') + '"></i>' +
            '<div></div></div>';
        alertBox.querySelector('div > div').textContent = msg;
    }

    async function send(fields) {
        const fd = new FormData();
        fd.append('csrf_token', TOKEN);
        Object.keys(fields).forEach(k => fd.append(k, fields[k]));
        try {
            const res = await fetch(API, { method: 'POST', body: fd });
            return await res.json();
        } catch (e) {
            return { success: false, message: 'Network error. Please try again.' };
        }
    }

    function afterRowRemoved() {
        const left = document.querySelectorAll('#pendingTable tbody tr').length;
        const badge = document.getElementById('pendingCount');
        if (badge) badge.textContent = left;
        if (left === 0) location.reload(); // shows the "all clear" state + recent list
    }

    window.reviewReg = async function (id, action, remarks) {
        const d = await send({ registration_id: id, action: action, remarks: remarks || '' });
        showAlert(d.message, d.success);
        if (d.success) {
            const row = document.getElementById('row-' + id);
            if (row) row.remove();
            afterRowRemoved();
        }
        return d.success;
    };

    window.openReject = function (id, code) {
        rejectId = id;
        document.getElementById('rejectCourse').textContent = code;
        document.getElementById('rejectReason').value = '';
        modal.show();
    };

    const confirmBtn = document.getElementById('confirmRejectBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', async () => {
            const reason = document.getElementById('rejectReason').value.trim();
            if (!reason) { alert('Please give a reason.'); return; }
            confirmBtn.disabled = true;
            const ok = await window.reviewReg(rejectId, 'rejected', reason);
            confirmBtn.disabled = false;
            if (ok) modal.hide();
        });
    }

    const bulkBtn = document.getElementById('bulkApproveBtn');
    if (bulkBtn) {
        bulkBtn.addEventListener('click', async () => {
            if (!confirm('Approve ALL pending registrations for this semester?')) return;
            bulkBtn.disabled = true;
            const d = await send({ bulk: '1' });
            if (d.success) { location.reload(); }
            else { showAlert(d.message, false); bulkBtn.disabled = false; }
        });
    }
})();
</script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script src="<?= APP_URL ?>/js/advisor.js"></script>
</body>
</html>
