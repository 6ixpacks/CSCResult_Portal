<?php

require_once __DIR__ . '/../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$advisorId = (int) $_SESSION['user_id'];

// Level this advisor is locked to (null = all levels)
$advLevel    = getAdvisorLevel($pdo, $advisorId);
$levelSql    = $advLevel ? " AND sp.level = ?" : "";
$levelParams = $advLevel ? [$advLevel] : [];

// Level this advisor is locked to (null = all levels)
$advLevel = getAdvisorLevel($pdo, $advisorId);

$formMessage = '';
$formSuccess = false;

// ============================================================
// SECTION 1: HANDLE SINGLE STUDENT MANUAL ADDITION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'single_add') {
    $fullName = trim($_POST['full_name'] ?? '');
    $matric   = strtoupper(trim($_POST['matric_number'] ?? ''));
    $email    = trim($_POST['email'] ?? '');
    $level    = $_POST['level'] ?? '100';
        if ($advLevel) { $level = $advLevel; }   // locked advisors can only add their own level
    $year     = (int) ($_POST['entry_year'] ?? date('Y'));

    if (empty($fullName) || empty($matric) || empty($email)) {
        $formMessage = 'All required fields must be filled.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formMessage = 'Invalid email address.';
    } else {
        try {
            $existing = dbFetchOne($pdo, "SELECT id FROM users WHERE email = ? OR matric_number = ?", [$email, $matric]);
            if (is_array($existing)) {
                $formMessage = 'A student with this Email or Matriculation Number already exists.';
            } else {
                $pdo->beginTransaction();
                $defaultHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => HASH_COST]);

                $userId = dbInsert($pdo,
                    "INSERT INTO users (full_name, email, password_hash, role, matric_number, is_active, created_by)
                     VALUES (?, ?, ?, 'student', ?, 1, ?)",
                    [$fullName, $email, $defaultHash, $matric, $advisorId]
                );

                dbInsert($pdo,
                    "INSERT INTO student_profiles (user_id, level, entry_year, cgpa, total_units)
                     VALUES (?, ?, ?, 0.00, 0)",
                    [$userId, $level, $year]
                );

                writeAuditLog($pdo, $advisorId, 'add_student_manual', 'users', $userId, null, ['matric' => $matric]);

                $pdo->commit();
                $formSuccess = true;
                $formMessage = "Student {$fullName} ({$matric}) added successfully! Default password is 'password'.";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $formMessage = 'Database error adding student.';
            logDatabaseError('Add student error: ' . $e->getMessage());
        }
    }
}

// ============================================================
// SECTION 2: HANDLE BULK CSV UPLOAD FOR STUDENTS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'bulk_csv') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $formMessage = 'Please select a valid CSV file.';
    } else {
        $file    = $_FILES['csv_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($fileExt, ['csv', 'txt'])) {
            $formMessage = 'Only CSV files are accepted.';
        } else {
            $handle  = fopen($file['tmp_name'], 'r');
            $headers = fgetcsv($handle);
            
            $headers = array_map(function($h) {
                return strtolower(trim(str_replace(['"', "'", ' '], '', $h)));
            }, $headers ?? []);

            $total    = 0;
            $inserted = 0;
            $skipped  = 0;
            $defaultHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => HASH_COST]);

            $pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 3) continue;
                $total++;

                $fullName = trim($row[0] ?? '');
                $matric   = strtoupper(trim($row[1] ?? ''));
                $email    = trim($row[2] ?? '');
                                $level    = trim($row[3] ?? '');
                if ($advLevel) {
                    if ($level === '') {
                        $level = $advLevel;          // no level column: use the advisor's level
                    } elseif ($level !== $advLevel) {
                        $skipped++;                  // row belongs to another level
                        continue;
                    }
                } elseif ($level === '') {
                    $level = '100';
                }
                $year     = (int) (trim($row[4] ?? date('Y')));

                if (empty($fullName) || empty($matric) || empty($email)) {
                    $skipped++;
                    continue;
                }

                // Check duplicate
                $dup = dbFetchOne($pdo, "SELECT id FROM users WHERE email = ? OR matric_number = ?", [$email, $matric]);
                if (is_array($dup)) {
                    $skipped++;
                    continue;
                }

                try {
                    $uId = dbInsert($pdo,
                        "INSERT INTO users (full_name, email, password_hash, role, matric_number, is_active, created_by)
                         VALUES (?, ?, ?, 'student', ?, 1, ?)",
                        [$fullName, $email, $defaultHash, $matric, $advisorId]
                    );

                    dbInsert($pdo,
                        "INSERT INTO student_profiles (user_id, level, entry_year, cgpa, total_units)
                         VALUES (?, ?, ?, 0.00, 0)",
                        [$uId, in_array($level, ['100','200','300','400','500']) ? $level : '100', $year > 2000 ? $year : (int)date('Y')]
                    );

                    $inserted++;
                } catch (PDOException $e) {
                    $skipped++;
                }
            }

            fclose($handle);
            $pdo->commit();

            writeAuditLog($pdo, $advisorId, 'bulk_students_csv', 'users', null, null, ['inserted' => $inserted, 'skipped' => $skipped]);

            $formSuccess = true;
            $formMessage = "Bulk upload complete: {$inserted} new student(s) added successfully. {$skipped} skipped (duplicates or missing info).";
        }
    }
}

// ============================================================
// SECTION 3: FETCH REGISTERED STUDENTS LIST WITH PAGINATION
// ============================================================
$searchQuery = trim($_GET['q'] ?? '');
$levelFilter = $_GET['level'] ?? '';
if ($advLevel) { $levelFilter = $advLevel; }

$where = ["u.role = 'student'"];
$params = [];

if (!empty($searchQuery)) {
    $where[] = "(u.full_name LIKE ? OR u.matric_number LIKE ?)";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
}

if (in_array($levelFilter, ['100','200','300','400','500'])) {
    $where[] = "sp.level = ?";
    $params[] = $levelFilter;
}

$whereClause = "WHERE " . implode(" AND ", $where);

$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 30;

$totalStudents = dbCount($pdo, "SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON u.id = sp.user_id {$whereClause}", $params);
$pagination    = paginate($totalStudents, $page, $perPage);

$studentsList = dbFetchAll($pdo,
    "SELECT u.id, u.full_name, u.matric_number, u.email, u.is_active,
            sp.level, sp.cgpa, sp.entry_year
     FROM users u
     LEFT JOIN student_profiles sp ON u.id = sp.user_id
     {$whereClause}
     ORDER BY sp.level ASC, u.matric_number ASC
     LIMIT " . (int)$pagination['per_page'] . " OFFSET " . (int)$pagination['offset'],
    $params
);

$pageTitle      = 'Manage Students';
$pageBreadcrumb = 'Advisor / Manage Students';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students &mdash; CSCResultPortal</title>
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
                    <i class="fa-solid fa-user-plus me-2 text-green"></i>Manage Students
                </h1>
                <p class="page-header-subtitle">
                    Register students manually or upload student lists in bulk via Excel/CSV.
                </p>
                <hr class="page-header-divider">
            </div>

            <?php if (!empty($formMessage)): ?>
                <div class="portal-alert <?= $formSuccess ? 'success' : 'danger' ?> mb-4">
                    <i class="fa-solid <?= $formSuccess ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                    <div><?= htmlspecialchars($formMessage) ?></div>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-4">

                <!-- Manual Student Addition -->
                <div class="col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-user-plus"></i> Add Single Student
                            </div>
                        </div>
                        <div class="content-card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action_type" value="single_add">

                                <div class="mb-3">
                                    <label class="portal-form-label">Full Name *</label>
                                    <input type="text" name="full_name" class="portal-form-control" required placeholder="e.g. Chinedu Okeke">
                                </div>
                                <div class="mb-3">
                                    <label class="portal-form-label">Matriculation Number *</label>
                                    <input type="text" name="matric_number" class="portal-form-control" required placeholder="e.g. CSC/2020/006">
                                </div>
                                <div class="mb-3">
                                    <label class="portal-form-label">Email Address *</label>
                                    <input type="email" name="email" class="portal-form-control" required placeholder="e.g. chinedu@student.futo.edu.ng">
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="portal-form-label">Level</label>
                                                                                <select name="level" class="portal-form-control portal-select">
                                            <?php foreach (['100','200','300','400','500'] as $lvl): ?>
                                                <?php if ($advLevel && $lvl !== $advLevel) continue; ?>
                                                <option value="<?= $lvl ?>" <?= ($advLevel ? $lvl === $advLevel : $lvl === '300') ? 'selected' : '' ?>><?= $lvl ?> Level</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="portal-form-label">Entry Year</label>
                                        <input type="number" name="entry_year" class="portal-form-control" value="<?= date('Y') - 3 ?>">
                                    </div>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn-portal-primary">
                                        <i class="fa-solid fa-plus me-1"></i> Register Student
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Bulk CSV Upload -->
                <div class="col-lg-6">
                    <div class="content-card h-100">
                        <div class="content-card-header">
                            <div class="content-card-title">
                                <i class="fa-solid fa-file-csv"></i> Bulk CSV Student Import
                            </div>
                            <button class="btn-portal-outline btn-portal-sm" onclick="downloadStudentSampleCSV()">
                                <i class="fa-solid fa-download"></i> Sample CSV
                            </button>
                        </div>
                        <div class="content-card-body">
                            <p class="text-muted-sm mb-3">
                                Upload a CSV or Excel file containing a student roster. Default password will be set to <code>password</code>.
                            </p>
                            <div class="portal-alert info mb-3" style="font-size:11px;">
                                <strong>Expected Columns:</strong><br>
                                <code>full_name, matric_number, email, level, entry_year</code>
                            </div>

                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action_type" value="bulk_csv">

                                <div class="mb-3">
                                    <label class="portal-form-label">Select CSV File</label>
                                    <input type="file" name="csv_file" class="portal-form-control" accept=".csv, .txt" required>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn-portal-gold">
                                        <i class="fa-solid fa-upload me-1"></i> Upload Student List
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div><!-- /.row -->

            <!-- Student Roster Table -->
            <div class="content-card">
                <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="content-card-title">
                        <i class="fa-solid fa-users"></i> Registered Student Roster
                    </div>
                    <form method="GET" action="" class="d-flex gap-2">
                        <?php if (!$advLevel): ?>
                        <select name="level" class="portal-form-control portal-select" style="max-width:130px; font-size:12px;" onchange="this.form.submit()">
                            <option value="">All Levels</option>
                            <?php foreach (['100','200','300','400','500'] as $lvl): ?>
                                <option value="<?= $lvl ?>" <?= $levelFilter === $lvl ? 'selected' : '' ?>><?= $lvl ?>L</option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" class="portal-form-control" placeholder="Search name or matric..." style="max-width:200px; font-size:12px;">
                        <button type="submit" class="btn-portal-primary btn-portal-sm"><i class="fa-solid fa-search"></i></button>
                    </form>
                </div>
                <div style="overflow-x:auto;">
                    <?php if (count($studentsList) > 0): ?>
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Matric Number</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Level</th>
                                    <th>Entry Year</th>
                                    <th>CGPA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studentsList as $idx => $st): ?>
                                    <tr>
                                        <td class="text-muted-sm"><?= $pagination['offset'] + $idx + 1 ?></td>
                                        <td class="fw-700 text-green"><?= htmlspecialchars($st['matric_number'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($st['full_name']) ?></td>
                                        <td class="text-muted-sm"><?= htmlspecialchars($st['email']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($st['level'] ?? '100') ?>L</span></td>
                                        <td><?= htmlspecialchars((string)($st['entry_year'] ?? 'N/A')) ?></td>
                                        <td class="fw-700"><?= number_format((float)($st['cgpa'] ?? 0), 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="table-empty-state">
                            <i class="fa-solid fa-user-slash"></i>
                            <p>No registered students found matching criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($pagination && $pagination['total_pages'] > 1): ?>
                    <?= renderPagination($pagination, APP_URL . '/pages/advisor/manage-students.php', ['q' => $searchQuery, 'level' => $levelFilter]) ?>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/js/notifications.js"></script>
<script>
function downloadStudentSampleCSV() {
    const csv = "full_name,matric_number,email,level,entry_year\n" +
                "Chinedu Okeke,CSC/2020/010,chinedu.okeke@student.futo.edu.ng,300,2020\n" +
                "Amina Bello,CSC/2020/011,amina.bello@student.futo.edu.ng,300,2020\n" +
                "Emeka Daniel,CSC/2021/001,emeka.daniel@student.futo.edu.ng,200,2021";
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = "FUTO_CSC_Student_Roster_Sample.csv";
    a.click();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>