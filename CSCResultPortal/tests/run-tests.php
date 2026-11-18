<?php
// ============================================================
// CSCResultPortal - Automated Test Script
// File: tests/run-tests.php
//
// Runs the portal's REAL functions and checks the REAL database
// (read-only). Nothing is changed: the only database writes are
// inside a transaction that is rolled back (OTP section).
//
// HOW TO RUN (XAMPP running):
//   http://localhost/CSCResultPortal/tests/run-tests.php
// Then use "Print / Save as PDF" as your evidence.
//
// DELETE the tests/ folder before putting the system online.
// ============================================================

// Local machine only
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This test page can only be run from the local machine.');
}

require_once __DIR__ . '/../config/db.php';

$otpFile = __DIR__ . '/../config/otp.php';
if (is_file($otpFile)) {
    require_once $otpFile;
}

$tests = [];   // every test result
$info  = [];   // measurements and extra information


// ------------------------------------------------------------
// Small helpers
// ------------------------------------------------------------
function show($v): string
{
    if (is_bool($v))  { return $v ? 'true' : 'false'; }
    if (is_float($v)) { return number_format($v, 2, '.', ''); }
    if ($v === null)  { return 'null'; }
    if (is_array($v)) { return json_encode($v); }
    return (string) $v;
}

function check(string $group, string $desc, $expected, $actual): void
{
    global $tests;
    if (is_float($expected) || is_float($actual)) {
        $ok = abs((float) $expected - (float) $actual) < 0.0001;
    } else {
        $ok = ($expected === $actual);
    }
    $tests[] = [
        'group'    => $group,
        'desc'     => $desc,
        'expected' => show($expected),
        'actual'   => show($actual),
        'status'   => $ok ? 'PASS' : 'FAIL',
    ];
}

function skipTest(string $group, string $desc, string $why): void
{
    global $tests;
    $tests[] = ['group' => $group, 'desc' => $desc, 'expected' => '-', 'actual' => $why, 'status' => 'SKIP'];
}

/** Builds a course list: [[units, 'A'], ...] -> [['credit_units'=>..,'grade_point'=>..], ...] */
function courses(array $pairs): array
{
    return array_map(fn($p) => ['credit_units' => $p[0], 'grade_point' => computeGradePoint($p[1])], $pairs);
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}


// ============================================================
// 1. GRADE COMPUTATION  (computeGrade)
// ============================================================
$g = '1. Grade computation (FUTO 5.0 scale)';
foreach ([
    [100, 'A'], [70, 'A'], [69.99, 'B'], [69, 'B'], [60, 'B'], [59.99, 'C'], [59, 'C'], [50, 'C'],
    [49, 'D'], [45, 'D'], [44.5, 'E'], [44, 'E'], [40, 'E'], [39.99, 'F'], [39, 'F'], [0, 'F'],
] as [$score, $grade]) {
    check($g, "Total score = {$score}", $grade, computeGrade((float) $score));
}

// ============================================================
// 2. GRADE POINTS AND QUALITY POINTS
// ============================================================
$g = '2. Grade points and quality points';
foreach ([['A', 5.0], ['B', 4.0], ['C', 3.0], ['D', 2.0], ['E', 1.0], ['F', 0.0], ['a', 5.0], ['Z', 0.0]] as [$l, $p]) {
    check($g, "Grade point for grade '{$l}'", $p, computeGradePoint($l));
}
check($g, 'Quality point: grade A (5.0) x 3 units', 15.0, computeQualityPoint(5.0, 3));
check($g, 'Quality point: grade B (4.0) x 2 units', 8.0,  computeQualityPoint(4.0, 2));
check($g, 'Quality point: grade F (0.0) x 3 units', 0.0,  computeQualityPoint(0.0, 3));

// ============================================================
// 3. GPA AND CGPA
// ============================================================
$g = '3. GPA and CGPA computation';
check($g, 'All A grades, 24 units',               5.0,  computeGPA(courses(array_fill(0, 8, [3, 'A']))));
check($g, 'One 3-unit A and one 3-unit B',        4.5,  computeGPA(courses([[3, 'A'], [3, 'B']])));
check($g, 'All C grades, 12 units',               3.0,  computeGPA(courses(array_fill(0, 4, [3, 'C']))));
check($g, 'All D grades, 12 units',               2.0,  computeGPA(courses(array_fill(0, 4, [3, 'D']))));
check($g, 'All E grades, 12 units',               1.0,  computeGPA(courses(array_fill(0, 4, [3, 'E']))));
check($g, 'All F grades, 12 units',               0.0,  computeGPA(courses(array_fill(0, 4, [3, 'F']))));
check($g, 'Mixed: A(3) B(3) C(2) D(2) E(3) = 40 QP / 13 units', 3.08,
      computeGPA(courses([[3, 'A'], [3, 'B'], [2, 'C'], [2, 'D'], [3, 'E']])));
check($g, 'No courses (0 credit units)',          0.0,  computeGPA([]));

$sem1 = [[3, 'A'], [3, 'B'], [3, 'B'], [3, 'C'], [3, 'C']];   // 57 QP, 15 units
$sem2 = [[3, 'A'], [3, 'A'], [3, 'B'], [3, 'D']];             // 48 QP, 12 units
check($g, 'CGPA over two semesters: 105 QP / 27 units', 3.89, computeGPA(courses(array_merge($sem1, $sem2))));

// Full pipeline: raw score -> grade -> grade point -> GPA
$pipeline = [[3, 85], [3, 65], [2, 55], [2, 47], [3, 42]];
$asCourses = array_map(
    fn($p) => ['credit_units' => $p[0], 'grade_point' => computeGradePoint(computeGrade((float) $p[1]))],
    $pipeline
);
check($g, 'Pipeline: scores 85/65/55/47/42 -> grades -> GPA', 3.08, computeGPA($asCourses));

// ============================================================
// 4. DEGREE CLASSIFICATION
// ============================================================
$g = '4. Degree classification';
foreach ([
    [5.00, 'First Class Honours'],                     [4.50, 'First Class Honours'],
    [4.49, 'Second Class Honours (Upper Division)'],   [3.50, 'Second Class Honours (Upper Division)'],
    [3.49, 'Second Class Honours (Lower Division)'],   [2.40, 'Second Class Honours (Lower Division)'],
    [2.39, 'Third Class Honours'],                     [1.50, 'Third Class Honours'],
    [1.49, 'Pass'],                                    [1.00, 'Pass'],
    [0.99, 'Fail'],                                    [0.00, 'Fail'],
] as [$cgpa, $class]) {
    check($g, 'CGPA = ' . number_format($cgpa, 2), $class, getDegreeClass($cgpa));
}

// ============================================================
// 5. PAGINATION
// ============================================================
$g = '5. Pagination (50 records per page)';
check($g, '0 rows -> 1 page',                1,   paginate(0)['total_pages']);
check($g, '50 rows -> 1 page',               1,   paginate(50)['total_pages']);
check($g, '51 rows -> 2 pages',              2,   paginate(51)['total_pages']);
check($g, '500 rows -> 10 pages',            10,  paginate(500)['total_pages']);
check($g, '501 rows -> 11 pages',            11,  paginate(501)['total_pages']);
check($g, 'Page 3 of 500 rows starts at offset 100', 100, paginate(500, 3)['offset']);
check($g, 'Page 99 of 100 rows is limited to the last page (2)', 2, paginate(100, 99)['current_page']);

// ============================================================
// 6. SECURITY CONFIGURATION
// ============================================================
$g = '6. Security configuration';
check($g, 'bcrypt cost factor (HASH_COST)', 12,   HASH_COST);
check($g, 'Session timeout in seconds',     1800, SESSION_TIMEOUT);

$t0 = microtime(true);
$h1 = password_hash('Test#Pass123', PASSWORD_BCRYPT, ['cost' => HASH_COST]);
$info['bcrypt_ms'] = (int) round((microtime(true) - $t0) * 1000);
$h2 = password_hash('Test#Pass123', PASSWORD_BCRYPT, ['cost' => HASH_COST]);

check($g, 'Password hash uses bcrypt with cost 12',     '$2y$12$', substr($h1, 0, 7));
check($g, 'Correct password verifies',                  true,  password_verify('Test#Pass123', $h1));
check($g, 'Wrong password is rejected',                 false, password_verify('Wrong#Pass123', $h1));
check($g, 'Same password gives a different hash (salt)', true,  $h1 !== $h2);

foreach (['config', 'includes', 'mail', 'logs', 'uploads', 'database'] as $dir) {
    check($g, ".htaccess protection present in /{$dir}", true, is_file(__DIR__ . "/../{$dir}/.htaccess"));
}

// ============================================================
// 7. DATABASE STRUCTURE (read-only)
// ============================================================
$g = '7. Database structure';
try {
    $info['db_version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $info['db_name']    = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    $tables = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM), 0);
    $views  = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM), 0);

    $info['table_count'] = count($tables);
    $info['view_count']  = count($views);
    $info['fk_count']    = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    )->fetchColumn();

    foreach ($tables as $t) {
        $info['rows'][$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    }

    foreach ([
        'users', 'student_profiles', 'academic_sessions', 'semesters', 'courses', 'course_assignments',
        'student_course_registrations', 'results', 'result_flags', 'semester_gpa', 'carryover_registrations',
        'notifications', 'batch_uploads', 'audit_log', 'password_resets', 'login_attempts', 'login_lockouts',
    ] as $t) {
        check($g, "Table `{$t}` exists", true, in_array($t, $tables, true));
    }
    foreach (['v_results_full', 'v_student_cgpa', 'v_pending_results'] as $v) {
        check($g, "View `{$v}` exists", true, in_array($v, $views, true));
    }

    check($g, 'Course registration has an approval status column', true, columnExists($pdo, 'student_course_registrations', 'status'));
    check($g, 'Advisors can be locked to a level (users.advisor_level)', true, columnExists($pdo, 'users', 'advisor_level'));
    check($g, 'Lab score is stored (results.lab_score)',          true, columnExists($pdo, 'results', 'lab_score'));
    check($g, 'Admin login-code table exists (login_otps)',       true, in_array('login_otps', $tables, true));
} catch (Throwable $e) {
    skipTest($g, 'Database structure checks', 'Could not run: ' . $e->getMessage());
}

// ============================================================
// 8. DATA INTEGRITY ON THE REAL DATA (read-only)
// ============================================================
$g = '8. Data integrity (live database)';

try {   // 8a. stored grades agree with the grading engine
    $rows = $pdo->query(
        "SELECT total_score, grade, grade_point FROM results
         WHERE total_score IS NOT NULL AND grade IS NOT NULL"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        skipTest($g, 'Stored grades match the grading engine', 'No scored results in the database yet');
    } else {
        $bad = 0;
        foreach ($rows as $r) {
            $calc = computeGrade((float) $r['total_score']);
            if ($calc !== $r['grade'] || abs(computeGradePoint($calc) - (float) $r['grade_point']) > 0.0001) {
                $bad++;
            }
        }
        check($g, 'Stored grade and grade point agree with the grading engine (' . count($rows) . ' result rows checked); mismatches',
              0, $bad);
    }
} catch (Throwable $e) {
    skipTest($g, 'Stored grades match the grading engine', 'Could not run: ' . $e->getMessage());
}

try {   // 8b. total = TEST + LAB + EXAM
    $bad = (int) $pdo->query(
        "SELECT COUNT(*) FROM results
         WHERE total_score IS NOT NULL
           AND ABS(total_score - (COALESCE(ca_score,0) + COALESCE(lab_score,0) + COALESCE(exam_score,0))) > 0.01"
    )->fetchColumn();
    check($g, 'Total score equals TEST + LAB + EXAM in every row; mismatches', 0, $bad);
} catch (Throwable $e) {
    skipTest($g, 'Total score equals TEST + LAB + EXAM', 'Could not run: ' . $e->getMessage());
}

try {   // 8c. final approval produced GPA records
    $bad = (int) $pdo->query(
        "SELECT COUNT(*) FROM results r
         WHERE r.status = 'final_approved'
           AND NOT EXISTS (SELECT 1 FROM semester_gpa g
                           WHERE g.student_id = r.student_id AND g.semester_id = r.semester_id)"
    )->fetchColumn();
    check($g, 'Every final-approved result has a GPA/CGPA record; missing', 0, $bad);
} catch (Throwable $e) {
    skipTest($g, 'Final-approved results have GPA records', 'Could not run: ' . $e->getMessage());
}

try {   // 8d. one active advisor per level
    $bad = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
             SELECT advisor_level FROM users
             WHERE role = 'advisor' AND is_active = 1 AND advisor_level IS NOT NULL
             GROUP BY advisor_level HAVING COUNT(*) > 1
         ) x"
    )->fetchColumn();
    check($g, 'No level has more than one active advisor; levels in conflict', 0, $bad);
} catch (Throwable $e) {
    skipTest($g, 'One active advisor per level', 'Could not run: ' . $e->getMessage());
}

// ============================================================
// 9. ADMIN EMAIL-CODE LOGIN (OTP)
// ============================================================
$g = '9. Admin email-code login (OTP)';

if (!function_exists('issueOtp')) {
    skipTest($g, 'OTP module', 'config/otp.php is not installed');
} else {
    check($g, "Email masking: john.doe@gmail.com", 'j******e@gmail.com', maskEmail('john.doe@gmail.com'));
    check($g, "Email masking: ab@x.com",           'a**@x.com',          maskEmail('ab@x.com'));
    check($g, 'Student login does not require a code', false, otpRequiredForRole('student'));
    check($g, 'HOD login requires a code (when OTP is enabled)', (bool) OTP_ENABLED, otpRequiredForRole('hod'));

    try {
        $uid = (int) $pdo->query(
            "SELECT id FROM users WHERE is_active = 1 AND role IN ('hod','advisor') ORDER BY id LIMIT 1"
        )->fetchColumn();

        if ($uid <= 0) {
            skipTest($g, 'OTP database tests', 'No active HOD or advisor account found');
        } else {
            $pdo->beginTransaction();            // everything below is rolled back
            dbExecute($pdo, "DELETE FROM login_otps WHERE user_id = ?", [$uid]);

            $a = issueOtp($pdo, $uid, '127.0.0.1');
            check($g, 'A 6-digit code is generated',
                  true, !empty($a['ok']) && preg_match('/^\d{6}$/', (string) ($a['code'] ?? '')) === 1);

            $b = issueOtp($pdo, $uid, '127.0.0.1');
            check($g, 'A second code inside the 60-second cool-down is refused', 'cooldown', $b['reason'] ?? '');

            $code  = (string) ($a['code'] ?? '');
            $wrong = ($code === '000000') ? '111111' : '000000';

            check($g, 'A wrong code is rejected',            false, verifyOtp($pdo, $uid, $wrong)['ok']);
            check($g, 'The correct code is accepted',        true,  verifyOtp($pdo, $uid, $code)['ok']);
            check($g, 'The same code cannot be used twice',  false, verifyOtp($pdo, $uid, $code)['ok']);

            // New code, then exhaust the allowed attempts
            dbExecute($pdo, "UPDATE login_otps SET created_at = (NOW() - INTERVAL 2 MINUTE) WHERE user_id = ?", [$uid]);
            $c     = issueOtp($pdo, $uid, '127.0.0.1');
            $code2 = (string) ($c['code'] ?? '');
            $wrong2 = ($code2 === '222222') ? '333333' : '222222';

            $dead = false;
            for ($i = 0; $i < OTP_MAX_ATTEMPTS; $i++) {
                $dead = !empty(verifyOtp($pdo, $uid, $wrong2)['dead']);
            }
            check($g, 'Code is cancelled after ' . OTP_MAX_ATTEMPTS . ' wrong attempts', true, $dead);
            check($g, 'The correct code no longer works after lock-out', false, verifyOtp($pdo, $uid, $code2)['ok']);

            // Expired code
            dbExecute($pdo, "UPDATE login_otps SET created_at = (NOW() - INTERVAL 2 MINUTE) WHERE user_id = ?", [$uid]);
            $d     = issueOtp($pdo, $uid, '127.0.0.1');
            $code3 = (string) ($d['code'] ?? '');
            dbExecute($pdo, "UPDATE login_otps SET expires_at = (NOW() - INTERVAL 1 MINUTE) WHERE user_id = ? AND is_used = 0", [$uid]);
            check($g, 'An expired code is rejected', false, verifyOtp($pdo, $uid, $code3)['ok']);

            $pdo->rollBack();                    // leave the database exactly as it was
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        skipTest($g, 'OTP database tests', 'Could not run: ' . $e->getMessage());
    }
}


// ============================================================
// RESULTS
// ============================================================
$total  = count($tests);
$passed = count(array_filter($tests, fn($t) => $t['status'] === 'PASS'));
$failed = count(array_filter($tests, fn($t) => $t['status'] === 'FAIL'));
$skipped = count(array_filter($tests, fn($t) => $t['status'] === 'SKIP'));
$ran    = $total - $skipped;
$rate   = $ran > 0 ? round($passed / $ran * 100, 1) : 0;

$grouped = [];
foreach ($tests as $i => $t) {
    $t['id'] = 'TC-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
    $grouped[$t['group']][] = $t;
}

// ---------- Plain-text output for the command line ----------
if (PHP_SAPI === 'cli') {
    foreach ($grouped as $name => $list) {
        echo "\n== {$name}\n";
        foreach ($list as $t) {
            printf("%-7s %-5s %s | expected: %s | actual: %s\n", $t['id'], $t['status'], $t['desc'], $t['expected'], $t['actual']);
        }
    }
    echo "\nTotal: {$total}  Passed: {$passed}  Failed: {$failed}  Skipped: {$skipped}  Pass rate: {$rate}%\n";
    exit($failed > 0 ? 1 : 0);
}

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CSCResultPortal - Test Report</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #222; margin: 0; padding: 24px; background: #f4f4f4; }
        .wrap { max-width: 1000px; margin: 0 auto; background: #fff; padding: 28px 32px; border-radius: 8px; }
        h1 { font-size: 20px; color: #006400; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 26px 0 8px; color: #006400; border-bottom: 2px solid #006400; padding-bottom: 4px; }
        .sub { color: #666; margin-bottom: 18px; }
        .summary { display: flex; gap: 12px; flex-wrap: wrap; margin: 16px 0; }
        .box { flex: 1; min-width: 130px; border: 1px solid #ddd; border-radius: 6px; padding: 10px 14px; text-align: center; }
        .box b { display: block; font-size: 22px; }
        .box.pass b { color: #006400; } .box.fail b { color: #b00020; } .box.skip b { color: #b36b00; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { border: 1px solid #999; padding: 4px 8px; text-align: left; font-size: 12px; }
        th { background: #e8f3e8; }
        td.st { text-align: center; font-weight: bold; white-space: nowrap; }
        .PASS { color: #006400; } .FAIL { color: #b00020; background: #fdecee; } .SKIP { color: #b36b00; }
        .env td:first-child { width: 220px; background: #fafafa; font-weight: bold; }
        .btn { background: #006400; color: #fff; border: 0; padding: 9px 16px; border-radius: 5px; cursor: pointer; font-size: 13px; }
        .note { background: #fff8e1; border: 1px solid #f0d58c; padding: 10px 14px; border-radius: 6px; margin-top: 18px; font-size: 12px; }
        @media print { body { background: #fff; padding: 0; } .wrap { padding: 0; } .noprint { display: none; } h2 { page-break-after: avoid; } tr { page-break-inside: avoid; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="noprint" style="float:right;"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
    <h1>CSCResultPortal &mdash; Automated Test Report</h1>
    <div class="sub">Department of Computer Science, Federal University of Technology Owerri</div>

    <div class="summary">
        <div class="box"><b><?= $total ?></b>Tests</div>
        <div class="box pass"><b><?= $passed ?></b>Passed</div>
        <div class="box fail"><b><?= $failed ?></b>Failed</div>
        <div class="box skip"><b><?= $skipped ?></b>Skipped</div>
        <div class="box"><b><?= $rate ?>%</b>Pass rate (of tests run)</div>
    </div>

    <h2>Test environment</h2>
    <table class="env">
        <tr><td>Date and time</td><td><?= h(date('d M Y, H:i:s')) ?></td></tr>
        <tr><td>PHP version</td><td><?= h(PHP_VERSION) ?></td></tr>
        <tr><td>Operating system</td><td><?= h(PHP_OS_FAMILY) ?></td></tr>
        <tr><td>Web server</td><td><?= h($_SERVER['SERVER_SOFTWARE'] ?? 'n/a') ?></td></tr>
        <tr><td>Database server</td><td><?= h($info['db_version'] ?? 'n/a') ?></td></tr>
        <tr><td>Database name</td><td><?= h($info['db_name'] ?? 'n/a') ?></td></tr>
        <tr><td>Tables / views / foreign keys</td><td><?= h(($info['table_count'] ?? '?') . ' / ' . ($info['view_count'] ?? '?') . ' / ' . ($info['fk_count'] ?? '?')) ?></td></tr>
        <tr><td>Time to hash one password (bcrypt, cost <?= (int) HASH_COST ?>)</td><td><?= h($info['bcrypt_ms'] ?? 'n/a') ?> ms</td></tr>
    </table>

    <?php foreach ($grouped as $name => $list): ?>
        <h2><?= h($name) ?></h2>
        <table>
            <thead><tr><th style="width:62px;">Test</th><th>Description</th><th style="width:20%;">Expected</th><th style="width:20%;">Actual</th><th style="width:62px;">Status</th></tr></thead>
            <tbody>
            <?php foreach ($list as $t): ?>
                <tr>
                    <td><?= h($t['id']) ?></td>
                    <td><?= h($t['desc']) ?></td>
                    <td><?= h($t['expected']) ?></td>
                    <td><?= h($t['actual']) ?></td>
                    <td class="st <?= h($t['status']) ?>"><?= h($t['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <?php if (!empty($info['rows'])): ?>
        <h2>Database tables and record counts</h2>
        <table>
            <thead><tr><th>#</th><th>Table</th><th>Records</th></tr></thead>
            <tbody>
            <?php $n = 0; foreach ($info['rows'] as $t => $c): $n++; ?>
                <tr><td><?= $n ?></td><td><?= h($t) ?></td><td><?= (int) $c ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="note noprint">
        <b>Remember:</b> a SKIP means the test could not run (for example, no data yet). Fix the cause and run again.
        Delete the <code>tests/</code> folder before the system is deployed online.
    </div>
</div>
</body>
</html>
