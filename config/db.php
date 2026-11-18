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
// Used in any file that needs to compute grades
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
// We use PDO (PHP Data Objects) for database access because:
// - It supports prepared statements (prevents SQL injection)
// - It works with multiple database types if needed later
// - It throws proper exceptions we can catch and handle
// ============================================================

/**
 * Creates and returns a PDO database connection.
 * 
 * This function is called once and the result is stored
 * in $pdo which is then used everywhere in the system.
 * 
 * PDO OPTIONS EXPLAINED:
 * - ERRMODE_EXCEPTION     → throw exception on any DB error
 * - DEFAULT_FETCH_MODE    → return rows as associative arrays
 * - EMULATE_PREPARES      → false means real prepared statements
 * - ATTR_STRINGIFY_FETCHES→ false means keep proper data types
 * 
 * @return PDO
 * @throws PDOException if connection fails
 */
function createDatabaseConnection(): PDO
{
    // Build the DSN (Data Source Name) string
    // Format: mysql:host=HOST;port=PORT;dbname=DBNAME;charset=CHARSET
    // Build the DSN (Data Source Name) string
    // Format: mysql:host=HOST;port=PORT;dbname=DBNAME;charset=CHARSET
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
        // Throw PDOException on any error — never silently fail
        
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Return rows as ['column_name' => 'value'] arrays
        
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

    // PDO driver options (removed PDO::MYSQL_ATTR_INIT_COMMAND to avoid undefined constant errors)
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        // Throw PDOException on any error — never silently fail
        
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Return rows as ['column_name' => 'value'] arrays
        
        PDO::ATTR_EMULATE_PREPARES   => false,
        // Use real prepared statements in MySQL driver
        
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        // Keep integers as int, decimals as float etc.
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

/**
 * Logs database connection errors to a private log file.
 * The log file is stored OUTSIDE the web root for security.
 * 
 * @param string $errorMessage The raw PDO error message
 * @return void
 */
function logDatabaseError(string $errorMessage): void
{
    // Log file path — stored in the project root logs folder
    $logFile = __DIR__ . '/../logs/db_errors.log';

    // Create the logs directory if it does not exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Format the log entry with timestamp
    $timestamp  = date('Y-m-d H:i:s');
    $logEntry   = "[{$timestamp}] DATABASE ERROR: {$errorMessage}" . PHP_EOL;

    // Append to log file
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}


/**
 * Displays a clean, user-friendly error page when the
 * database connection fails. Does not expose any technical
 * details to the user.
 * 
 * @return void
 */
function showDatabaseErrorPage(): void
{
    // Send proper HTTP status code
    http_response_code(503);

    // Output a clean error page using our brand colors
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
// These utility functions wrap common PDO operations.
// They make database calls shorter and safer across the system.
// ============================================================

/**
 * Executes a SELECT query and returns ALL matching rows.
 * 
 * USAGE EXAMPLE:
 *   $users = dbFetchAll($pdo, 
 *       "SELECT * FROM users WHERE role = ?", 
 *       ['student']
 *   );
 * 
 * @param PDO    $pdo    The database connection
 * @param string $sql    SQL query with ? placeholders
 * @param array  $params Values to bind to ? placeholders
 * @return array         Array of rows (each row is assoc array)
 */
function dbFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}


/**
 * Executes a SELECT query and returns ONE row only.
 * Returns null if no row is found.
 * 
 * USAGE EXAMPLE:
 *   $user = dbFetchOne($pdo,
 *       "SELECT * FROM users WHERE email = ?",
 *       ['student@futo.edu.ng']
 *   );
 * 
 * @param PDO    $pdo    The database connection
 * @param string $sql    SQL query with ? placeholders
 * @param array  $params Values to bind to placeholders
 * @return array|null    Single row as assoc array, or null
 */
function dbFetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}


/**
 * Executes an INSERT, UPDATE, or DELETE query.
 * Returns the number of rows affected.
 * 
 * USAGE EXAMPLE:
 *   $affected = dbExecute($pdo,
 *       "UPDATE users SET is_active = ? WHERE id = ?",
 *       [0, 5]
 *   );
 * 
 * @param PDO    $pdo    The database connection
 * @param string $sql    SQL query with ? placeholders
 * @param array  $params Values to bind to placeholders
 * @return int           Number of rows affected
 */
function dbExecute(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}


/**
 * Executes an INSERT and returns the auto-increment ID
 * of the newly inserted row.
 * 
 * USAGE EXAMPLE:
 *   $newId = dbInsert($pdo,
 *       "INSERT INTO users (full_name, email) VALUES (?, ?)",
 *       ['John Doe', 'john@futo.edu.ng']
 *   );
 * 
 * @param PDO    $pdo    The database connection
 * @param string $sql    INSERT SQL query
 * @param array  $params Values to bind to placeholders
 * @return int           The new row's auto-increment ID
 */
function dbInsert(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}


/**
 * Counts rows matching a condition.
 * Returns the count as an integer.
 * 
 * USAGE EXAMPLE:
 *   $count = dbCount($pdo,
 *       "SELECT COUNT(*) FROM results WHERE status = ?",
 *       ['submitted']
 *   );
 * 
 * @param PDO    $pdo    The database connection
 * @param string $sql    SELECT COUNT(*) query
 * @param array  $params Values to bind to placeholders
 * @return int           The count result
 */
function dbCount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}


// ============================================================
// SECTION 6: GRADE COMPUTATION FUNCTIONS
// Centralised here so ALL parts of the system use the
// exact same grading logic. No inconsistencies possible.
// ============================================================

/**
 * Converts a total score (0-100) into a letter grade.
 * Uses the Nigerian 5.0 grading scale defined above.
 * 
 * @param float $totalScore The total score (CA + Exam)
 * @return string           Grade letter: A, B, C, D, or F
 */
/**
 * Converts a total score (0-100) into a FUTO letter grade (A-B-C-D-E-F).
 * 
 * @param float $totalScore The total score (TEST + LAB + Exam)
 * @return string           Grade letter: A, B, C, D, E, or F
 */
function computeGrade(float $totalScore): string
{
    if ($totalScore >= GRADE_A_MIN) return 'A';  // 70-100
    if ($totalScore >= GRADE_B_MIN) return 'B';  // 60-69
    if ($totalScore >= GRADE_C_MIN) return 'C';  // 50-59
    if ($totalScore >= GRADE_D_MIN) return 'D';  // 45-49
    if ($totalScore >= GRADE_E_MIN) return 'E';  // 40-44 (PASS)
    return 'F';                                  // 0-39  (FAIL)
}


/**
 * Converts a letter grade into a grade point (FUTO 5.0 scale with E).
 * 
 * @param string $grade  Letter grade (A, B, C, D, E, F)
 * @return float         Grade point (5.0, 4.0, 3.0, 2.0, 1.0, 0.0)
 */
function computeGradePoint(string $grade): float
{
    $gradePoints = [
        'A' => 5.0,
        'B' => 4.0,
        'C' => 3.0,
        'D' => 2.0,
        'E' => 1.0,  // E is a PASS at 1.0
        'F' => 0.0,  // F is a FAIL at 0.0
    ];

    return $gradePoints[strtoupper($grade)] ?? 0.0;
}


/**
 * Computes the quality point for a single course.
 * Quality Point = Grade Point × Credit Units
 * 
 * @param float $gradePoint   The grade point (0.0 to 5.0)
 * @param int   $creditUnits  The course credit units
 * @return float              Quality point for this course
 */
function computeQualityPoint(float $gradePoint, int $creditUnits): float
{
    return $gradePoint * $creditUnits;
}


/**
 * Computes GPA from an array of course results.
 * GPA = Total Quality Points / Total Credit Units
 * 
 * @param array $courses  Array of ['credit_units'=>int, 'grade_point'=>float]
 * @return float          GPA rounded to 2 decimal places
 */
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


/**
 * Determines the degree classification based on CGPA.
 * Uses the Nigerian university 5.0 scale boundaries.
 * 
 * @param float $cgpa   The cumulative GPA
 * @return string       Degree class label
 */
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
// Called from anywhere to write a record to audit_log.
// Keeps the full trail of who did what and when.
// ============================================================

/**
 * Writes a record to the audit_log table.
 * 
 * USAGE EXAMPLE:
 *   writeAuditLog($pdo, $userId, 'submit_scores', 'results', 42,
 *       ['status' => 'draft'],
 *       ['status' => 'submitted']
 *   );
 * 
 * @param PDO        $pdo         Database connection
 * @param int|null   $userId      ID of user performing action
 * @param string     $action      Action name e.g. "submit_scores"
 * @param string|null $table      Affected table name
 * @param int|null   $targetId    Affected row ID
 * @param array|null $oldValue    Previous state as array
 * @param array|null $newValue    New state as array
 * @return void
 */
function writeAuditLog(
    PDO     $pdo,
    ?int    $userId,
    string  $action,
    ?string $table    = null,
    ?int    $targetId = null,
    ?array  $oldValue = null,
    ?array  $newValue = null
): void {
    // Capture request metadata safely
    $ipAddress = $_SERVER['REMOTE_ADDR']     ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Truncate user agent if too long
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
        // Audit log failure should not break the main request
        // Log it silently
        logDatabaseError('Audit log failed: ' . $e->getMessage());
    }
}


// ============================================================
// SECTION 8: ESTABLISH THE CONNECTION
// This runs immediately when db.php is included.
// Every page that includes db.php gets the $pdo variable.
// ============================================================

$pdo = createDatabaseConnection();


// ============================================================
// SECTION 9: SESSION CONFIGURATION
// Configure sessions here since db.php is included on
// every page. Sessions are started in each page file
// individually, but settings must be set before start.
// ============================================================

// Only set session settings if session has not started yet
if (session_status() === PHP_SESSION_NONE) {
    
    // Set custom session name for security
    session_name(SESSION_NAME);

    // Session cookie settings
    session_set_cookie_params([
        'lifetime' => 0,           // Cookie expires when browser closes
        'path'     => '/',         // Available across entire site
        'domain'   => '',          // Current domain only
        'secure'   => false,       // Set true if using HTTPS
        'httponly' => true,        // Prevent JavaScript access (XSS protection)
        'samesite' => 'Strict',    // Prevent CSRF attacks
    ]);
}

// ============================================================
// SECTION 10: PAGINATION HELPER
// ============================================================

/**
 * Generates pagination data for a query.
 * 
 * @param int $totalRows    Total number of rows in result set
 * @param int $currentPage  Current page number (1-based)
 * @param int $perPage      Items per page (default 50)
 * @return array Pagination info: total_pages, offset, limit, has_prev, has_next
 */
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

/**
 * Renders a pagination control bar in HTML.
 */
function renderPagination(array $pagination, string $baseUrl, array $extraParams = []): string {
    if ($pagination['total_pages'] <= 1) return '';

    $html = '<div class="pagination-wrapper" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#f4f6f9;border-top:1px solid #e0e0e0;flex-wrap:wrap;gap:12px;">';
    
    // Info text
    $html .= '<div style="font-size:13px;color:#6c757d;">';
    $html .= 'Showing <strong>' . $pagination['first_row'] . '</strong> to <strong>' 
           . $pagination['last_row'] . '</strong> of <strong>' 
           . $pagination['total_rows'] . '</strong> records';
    $html .= '</div>';
    
    // Page buttons
    $html .= '<div class="pagination-buttons" style="display:flex;gap:4px;">';
    
    $buildLink = function($page) use ($baseUrl, $extraParams) {
        $params = array_merge($extraParams, ['page' => $page]);
        $separator = strpos($baseUrl, '?') === false ? '?' : '&';
        return $baseUrl . $separator . http_build_query($params);
    };

    // Prev button
    if ($pagination['has_prev']) {
        $html .= '<a href="' . $buildLink($pagination['prev_page']) . '" class="page-btn" style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px;">« Prev</a>';
    }

    // Page numbers (show max 5 around current)
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

    // Next button
    if ($pagination['has_next']) {
        $html .= '<a href="' . $buildLink($pagination['next_page']) . '" class="page-btn" style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;font-size:13px;">Next »</a>';
    }
    
    $html .= '</div></div>';
    return $html;
}


/**
 * Returns the level ('100'..'500') a course advisor is responsible for,
 * or null if no level is assigned (null = no restriction).
 */
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
