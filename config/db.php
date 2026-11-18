<?php


// ============================================================
// SECTION 1: DATABASE CONFIGURATION & ENVIRONMENT OVERRIDES
// ============================================================

// Pull from Railway environment variables if available, otherwise fall back to local XAMPP defaults
define('DB_HOST',    getenv('MYSQLHOST')    ?: getenv('DB_HOST')    ?: 'localhost');       
define('DB_PORT',    getenv('MYSQLPORT')    ?: getenv('DB_PORT')    ?: '3306');           
define('DB_NAME',    getenv('MYSQLDATABASE')?: getenv('DB_NAME')    ?: 'cscresultportal');
define('DB_USER',    getenv('MYSQLUSER')    ?: getenv('DB_USER')    ?: 'root');           
define('DB_PASS',    getenv('MYSQLPASSWORD')?: getenv('DB_PASS')    ?: '');               
define('DB_CHARSET', 'utf8mb4');        // Character set (supports all Unicode)


// ============================================================
// SECTION 2: APPLICATION CONSTANTS
// Global constants used throughout the entire system.
// Define them here once so changing them is easy.
// ============================================================

// Application identity
define('APP_NAME',       'CSCResultPortal');
define('APP_UNIVERSITY', 'Federal University of Technology Owerri');
define('APP_DEPARTMENT', 'Department of Computer Science');
define('APP_VERSION',    '1.0.0');

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$hostName = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('APP_URL', getenv('APP_URL') ?: "$protocol://$hostName");

// File upload paths (for batch uploads by HOD)
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('UPLOAD_URL',  APP_URL . '/uploads/');

// Session name (custom name for security)
define('SESSION_NAME', 'CSCPORTAL_SESSION');

// Session timeout in seconds (30 minutes)
define('SESSION_TIMEOUT', 1800);

// Password hashing cost (bcrypt)
define('HASH_COST', 12);

/// Grading scale boundaries (FUTO A-B-C-D-E-F system)
define('GRADE_A_MIN', 70);   // A = 70-100 → 5.0
define('GRADE_B_MIN', 60);   // B = 60-69  → 4.0
define('GRADE_C_MIN', 50);   // C = 50-59  → 3.0
define('GRADE_D_MIN', 45);   // D = 45-49  → 2.0
define('GRADE_E_MIN', 40);   // E = 40-44  → 1.0
                              // F = 0-39   → 0.0
// Degree class boundaries (Nigerian 5.0 CGPA scale)
define('FIRST_CLASS_MIN',        4.50);
define('SECOND_CLASS_UPPER_MIN', 3.50);
define('SECOND_CLASS_LOWER_MIN', 2.40);
define('THIRD_CLASS_MIN',        1.50);
define('PASS_MIN',               1.00);


// ============================================================
// SECTION 3: PDO CONNECTION
// ============================================================

/**
 * Creates and returns a PDO database connection.
 * 
 * @return PDO
 * @throws PDOException if connection fails
 */
function createDatabaseConnection(): PDO
{
    // Build the DSN (Data Source Name) string with charset directly included
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    // PDO driver options
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;

    } catch (PDOException $e) {
        // Log the real error to a private log file
        logDatabaseError($e->getMessage());

        // Show a safe error page and stop execution
        showDatabaseErrorPage();
        exit();
    }
}


// ============================================================
// SECTION 4: ERROR HANDLING HELPERS
// ============================================================

function logDatabaseError(string $errorMessage): void
{
    $logFile = __DIR__ . '/../logs/db_errors.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp  = date('Y-m-d H:i:s');
    $logEntry   = "[{$timestamp}] DATABASE ERROR: {$errorMessage}" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

function showDatabaseErrorPage(): void
{
    http_response_code(503);
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Unavailable - CSCResultPortal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .error-box {
            background: #fff;
            border-top: 5px solid #006400;
            border-radius: 8px;
            padding: 50px 40px;
            text-align: center;
            max-width: 480px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .error-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        h1 {
            color: #006400;
            font-size: 22px;
            margin-bottom: 12px;
        }
        p {
            color: #555;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 10px;
        }
        .badge {
            display: inline-block;
            background: #FFD700;
            color: #333;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <div class="error-box">
        <div class="error-icon">⚠️</div>
        <h1>System Temporarily Unavailable</h1>
        <p>
            The CSCResultPortal is currently unable to connect to 
            the database. Please try again in a few moments.
        </p>
        <p>
            If this problem persists, please contact the 
            system administrator.
        </p>
        <span class="badge">CSCResultPortal &mdash; FUTO CSC</span>
    </div>
</body>
</html>';
}


// ============================================================
// SECTION 5: HELPER DATABASE FUNCTIONS
// ============================================================

function dbFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function dbFetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function dbExecute(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function dbInsert(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}

function dbCount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}


// ============================================================
// SECTION 6: GRADE COMPUTATION FUNCTIONS
// ============================================================

function computeGrade(float $totalScore): string
{
    if ($totalScore >= GRADE_A_MIN) return 'A';  
    if ($totalScore >= GRADE_B_MIN) return 'B';  
    if ($totalScore >= GRADE_C_MIN) return 'C';  
    if ($totalScore >= GRADE_D_MIN) return 'D';  
    if ($totalScore >= GRADE_E_MIN) return 'E';  
    return 'F';                                  
}

function computeGradePoint(string $grade): float
{
    $gradePoints = [
        'A' => 5.0,
        'B' => 4.0,
        'C' => 3.0,
        'D' => 2.0,
        'E' => 1.0,  
        'F' => 0.0,  
    ];

    return $gradePoints[strtoupper($grade)] ?? 0.0;
}

function computeQualityPoint(float $gradePoint, int $creditUnits): float
{
    return $gradePoint * $creditUnits;
}

function computeGPA(array $courses): float
{
    $totalQualityPoints = 0.0;
    $totalCreditUnits   = 0;

    foreach ($courses as $course) {
        $totalQualityPoints += $course['grade_point'] * $course['credit_units'];
        $totalCreditUnits   += $course['credit_units'];
    }

    if ($totalCreditUnits === 0) {
        return 0.00;
    }

    return round($totalQualityPoints / $totalCreditUnits, 2);
}

function getDegreeClass(float $cgpa): string
{
    if ($cgpa >= FIRST_CLASS_MIN)        return 'First Class Honours';
    if ($cgpa >= SECOND_CLASS_UPPER_MIN) return 'Second Class Honours (Upper Division)';
    if ($cgpa >= SECOND_CLASS_LOWER_MIN) return 'Second Class Honours (Lower Division)';
    if ($cgpa >= THIRD_CLASS_MIN)        return 'Third Class Honours';
    if ($cgpa >= PASS_MIN)               return 'Pass';
    return 'Fail';
}


// ============================================================
// SECTION 7: AUDIT LOG HELPER
// ============================================================

function writeAuditLog(
    PDO     $pdo,
    ?int    $userId,
    string  $action,
    ?string $table    = null,
    ?int    $targetId = null,
    ?array  $oldValue = null,
    ?array  $newValue = null
): void {
    $ipAddress = $_SERVER['REMOTE_ADDR']     ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    if ($userAgent && strlen($userAgent) > 500) {
        $userAgent = substr($userAgent, 0, 500);
    }

    $sql = "INSERT INTO audit_log 
                (user_id, action, target_table, target_id, 
                 old_value, new_value, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $userId,
        $action,
        $table,
        $targetId,
        $oldValue ? json_encode($oldValue) : null,
        $newValue ? json_encode($newValue) : null,
        $ipAddress,
        $userAgent,
    ];

    try {
        dbExecute($pdo, $sql, $params);
    } catch (PDOException $e) {
        logDatabaseError('Audit log failed: ' . $e->getMessage());
    }
}


// ============================================================
// SECTION 8: ESTABLISH THE CONNECTION
// ============================================================

$pdo = createDatabaseConnection();


// ============================================================
// SECTION 9: SESSION CONFIGURATION
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,           
        'path'     => '/',         
        'domain'   => '',          
        'secure'   => false,       
        'httponly' => true,        
        'samesite' => 'Strict',    
    ]);
}


// ============================================================
// SECTION 10: PAGINATION HELPER
// ============================================================

function paginate(int $totalRows, int $currentPage = 1, int $perPage = 50): array {
    $currentPage = max(1, $currentPage);
    $totalPages  = max(1, (int) ceil($totalRows / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset      = ($currentPage - 1) * $perPage;

    return [
        'total_rows'   => $totalRows,
        'total_pages'  => $totalPages,
        'current_page' => $currentPage,
        'per_page'     => $perPage,
        'offset'       => $offset,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $totalPages,
        'prev_page'    => max(1, $currentPage - 1),
        'next_page'    => min($totalPages, $currentPage + 1),
        'first_row'    => $offset + 1,
        'last_row'     => min($offset + $perPage, $totalRows)
    ];
}

function renderPagination(array $pagination, string $baseUrl, array $extraParams = []): string {
    if ($pagination['total_pages'] <= 1) return '';

    $html = '<div class="pagination-wrapper" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#f4f6f9;border-top:1px solid #e0e0e0;flex-wrap:wrap;gap:12px;">';
    
    $html .= '<div style="font-size:13px;color:#6c757d;">';
    $html .= 'Showing <strong>' . $pagination['first_row'] . '</strong> to <strong>' 
           . $pagination['last_row'] . '</strong> of <strong>' 
           . $pagination['total_rows'] . '</strong> records';
    $html .= '</div>';
    
    $html .= '<div class="pagination-buttons" style="display:flex;gap:4px;">';
    
    $buildLink = function($page) use ($baseUrl, $extraParams) {
        $params = array_merge($extraParams, ['page' => $page]);
        $separator = strpos($baseUrl, '?') === false ? '?' : '&';
        return $baseUrl . $separator . http_build_query($params);
    };

    if ($pagination['has_prev']) {
        $html .= '<a href="' . $buildLink($pagination['prev_page']) . '" class="page-btn" style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px;">« Prev</a>';
    }

    $start = max(1, $pagination['current_page'] - 2);
    $end   = min($pagination['total_pages'], $pagination['current_page'] + 2);

    if ($start > 1) {
        $html .= '<a href="' . $buildLink(1) . '" class="page-btn" style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px;">1</a>';
        if ($start > 2) $html .= '<span style="padding:6px 4px;color:#999;">...</span>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $pagination['current_page'];
        $style = $active 
            ? 'padding:6px 12px;background:#006400;color:#fff;border:1px solid #006400;border-radius:4px;font-weight:700;font-size:13px;'
            : 'padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px;';
        
        if ($active) {
            $html .= '<span style="' . $style . '">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $buildLink($i) . '" style="' . $style . '">' . $i . '</a>';
        }
    }

    if ($end < $pagination['total_pages']) {
        if ($end < $pagination['total_pages'] - 1) $html .= '<span style="padding:6px 4px;color:#999;">...</span>';
        $html .= '<a href="' . $buildLink($pagination['total_pages']) . '" class="page-btn" style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px;">' . $pagination['total_pages'] . '</a>';
    }

    if ($pagination['has_next']) {
        $html .= '<a href="' . $buildLink($pagination['next_page']) . '" class="page-btn" style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px;">Next »</a>';
    }
    
    $html .= '</div></div>';
    return $html;
}

if (!function_exists('getAdvisorLevel')) {
    function getAdvisorLevel(PDO $pdo, int $userId): ?string
    {
        try {
            $row = dbFetchOne($pdo,
                "SELECT advisor_level FROM users WHERE id = ? AND role = 'advisor'",
                [$userId]
            );
            return (is_array($row) && !empty($row['advisor_level'])) ? $row['advisor_level'] : null;
        } catch (PDOException $e) {
            return null;
        }
    }
}
