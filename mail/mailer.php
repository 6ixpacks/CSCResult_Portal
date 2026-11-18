<?php
// ============================================================
// CSCResultPortal - PHPMailer Configuration and Mail Helper
// File: mail/mailer.php
// Purpose: Handles all email sending operations for the system
//          using PHPMailer.
// ============================================================

// Include database/config file for app constants and DB access
require_once __DIR__ . '/../config/db.php';

// ============================================================
// SECTION 1: LOAD PHPMailer CLASSES
// These files must exist inside:
// CSCResultPortal/mail/src/
// ============================================================
require_once __DIR__ . '/src/Exception.php';
require_once __DIR__ . '/src/PHPMailer.php';
require_once __DIR__ . '/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


// ============================================================
// SECTION 2: EMAIL CONFIGURATION
// Replace these values with your real SMTP credentials.
// For Gmail, use App Password, not normal Gmail password.
// ============================================================

define('MAIL_HOST',       'sandbox.smtp.mailtrap.io');           // SMTP server
define('MAIL_PORT',       2525);                        // TLS port
define('MAIL_USERNAME',   '9f426bc7697181');     // SMTP username
define('MAIL_PASSWORD',   '800d26365bc326');        // SMTP app password
define('MAIL_ENCRYPTION', 'tls');                      // tls or ssl
define('MAIL_FROM_EMAIL', 'noreply@cscresultportal.futo.edu.ng');     // Sender email
define('MAIL_FROM_NAME',  'CSCResultPortal');          // Sender name

// Mail debug mode
// 0 = off, 2 = verbose SMTP debug
define('MAIL_DEBUG', 0);


// ============================================================
// SECTION 3: CREATE MAILER INSTANCE
// Returns a pre-configured PHPMailer object.
// ============================================================

/**
 * Creates and configures a PHPMailer instance.
 *
 * @return PHPMailer
 * @throws Exception
 */
function createMailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    // Use SMTP
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = MAIL_ENCRYPTION;
    $mail->Port       = MAIL_PORT;
    $mail->SMTPDebug  = MAIL_DEBUG;

    // Character encoding
    $mail->CharSet = 'UTF-8';

    // Sender
    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);

    // Email format
    $mail->isHTML(true);

    return $mail;
}


// ============================================================
// SECTION 4: LOG MAIL ERRORS
// Stores email errors in a private log file.
// ============================================================

/**
 * Logs mail sending errors to logs/mail_errors.log
 *
 * @param string $errorMessage
 * @return void
 */
function logMailError(string $errorMessage): void
{
    $logFile = __DIR__ . '/../logs/mail_errors.log';
    $logDir  = dirname($logFile);

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $logEntry  = "[{$timestamp}] MAIL ERROR: {$errorMessage}" . PHP_EOL;

    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}


// ============================================================
// SECTION 5: GENERIC EMAIL SENDER
// Sends any email and optionally saves an in-app notification.
// ============================================================

/**
 * Sends an email using PHPMailer.
 *
 * @param string   $toEmail      Recipient email
 * @param string   $toName       Recipient name
 * @param string   $subject      Email subject
 * @param string   $htmlBody     HTML body content
 * @param string   $plainBody    Plain text fallback
 * @param int|null $recipientId  Optional user ID for notification
 * @param int|null $senderId     Optional sender user ID
 * @param string   $type         Notification type
 * @param string   $title        In-app notification title
 * @param string   $message      In-app notification message
 * @param int|null $relatedId    Related record ID
 * @param string|null $relatedType Related type (result, carryover)
 * @return bool
 */
function sendSystemEmail(
    string  $toEmail,
    string  $toName,
    string  $subject,
    string  $htmlBody,
    string  $plainBody = '',
    ?int    $recipientId = null,
    ?int    $senderId = null,
    string  $type = 'general',
    string  $title = '',
    string  $message = '',
    ?int    $relatedId = null,
    ?string $relatedType = null
): bool {
    global $pdo;

    try {
        $mail = createMailer();

        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = !empty($plainBody) ? $plainBody : strip_tags($htmlBody);

        $mail->send();

        // Save in-app notification if recipientId is provided
        if ($recipientId !== null) {
            $notificationTitle   = !empty($title) ? $title : $subject;
            $notificationMessage = !empty($message) ? $message : strip_tags($plainBody);

            $sql = "INSERT INTO notifications
                        (recipient_id, sender_id, title, message, type, related_id, related_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            dbExecute($pdo, $sql, [
                $recipientId,
                $senderId,
                $notificationTitle,
                $notificationMessage,
                $type,
                $relatedId,
                $relatedType
            ]);
        }

        return true;

    } catch (Exception $e) {
        logMailError("Failed to send email to {$toEmail}: " . $e->getMessage());
        return false;
    } catch (PDOException $e) {
        logMailError("Email sent but notification insert failed for {$toEmail}: " . $e->getMessage());
        return true;
    }
}


// ============================================================
// SECTION 6: EMAIL HTML TEMPLATE
// A reusable branded email layout using FUTO green and gold.
// ============================================================

/**
 * Wraps email message content in a branded HTML layout.
 *
 * @param string $heading
 * @param string $bodyContent
 * @return string
 */
function buildEmailTemplate(string $heading, string $bodyContent): string
{
    return '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($heading) . '</title>
    </head>
    <body style="margin:0; padding:0; background:#f4f6f9; font-family: Arial, Helvetica, sans-serif;">
        <div style="max-width:650px; margin:30px auto; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,0.08);">
            
            <div style="background:#006400; color:#ffffff; padding:20px 30px;">
                <h2 style="margin:0; font-size:24px;">CSCResultPortal</h2>
                <p style="margin:5px 0 0; font-size:14px;">Federal University of Technology Owerri - Computer Science Department</p>
            </div>

            <div style="padding:30px; color:#333333; line-height:1.7;">
                <h3 style="color:#006400; margin-top:0;">' . htmlspecialchars($heading) . '</h3>
                ' . $bodyContent . '
            </div>

            <div style="background:#FFD700; padding:15px 30px; font-size:13px; color:#333333;">
                This is an automated email from CSCResultPortal. Please do not reply directly to this message.
            </div>
        </div>
    </body>
    </html>';
}


// ============================================================
// SECTION 7: SPECIFIC SYSTEM EMAIL FUNCTIONS
// These match the exact email notifications required.
// ============================================================

/**
 * 1. Lecturer submits scores → Advisor gets email
 */
function sendLecturerSubmissionEmail(
    int    $advisorId,
    string $advisorEmail,
    string $advisorName,
    string $lecturerName,
    string $courseCode,
    string $courseTitle,
    string $semesterName,
    string $sessionName,
    ?int   $assignmentId = null,
    ?int   $senderId = null
): bool {
    $subject = 'Scores Submitted for Review - ' . $courseCode;

    $body = '
        <p>Dear ' . htmlspecialchars($advisorName) . ',</p>
        <p>
            <strong>' . htmlspecialchars($lecturerName) . '</strong> has submitted scores for the course
            <strong>' . htmlspecialchars($courseCode) . ' - ' . htmlspecialchars($courseTitle) . '</strong>
            for <strong>' . htmlspecialchars($semesterName) . '</strong>,
            <strong>' . htmlspecialchars($sessionName) . '</strong>.
        </p>
        <p>Please log in to review and process the result.</p>
        <p>
            <a href="' . APP_URL . '/auth/login.php" 
               style="display:inline-block; background:#006400; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:5px;">
               Login to Review
            </a>
        </p>
        <p>Regards,<br>CSCResultPortal</p>
    ';

    return sendSystemEmail(
        $advisorEmail,
        $advisorName,
        $subject,
        buildEmailTemplate('Scores Submitted for Review', $body),
        "Dear {$advisorName}, {$lecturerName} has submitted scores for {$courseCode} - {$courseTitle} ({$semesterName}, {$sessionName}). Please log in to review.",
        $advisorId,
        $senderId,
        'scores_submitted',
        'Scores Submitted for Review',
        "{$lecturerName} submitted scores for {$courseCode} - {$courseTitle}.",
        $assignmentId,
        'course_assignment'
    );
}


/**
 * 2. Advisor flags result → Lecturer gets email
 */
function sendResultFlaggedEmail(
    int    $lecturerId,
    string $lecturerEmail,
    string $lecturerName,
    string $advisorName,
    string $courseCode,
    string $courseTitle,
    string $reason,
    ?int   $resultId = null,
    ?int   $senderId = null
): bool {
    $subject = 'Result Flagged for Correction - ' . $courseCode;

    $body = '
        <p>Dear ' . htmlspecialchars($lecturerName) . ',</p>
        <p>
            The Course Advisor, <strong>' . htmlspecialchars($advisorName) . '</strong>, has flagged the submitted result for
            <strong>' . htmlspecialchars($courseCode) . ' - ' . htmlspecialchars($courseTitle) . '</strong>
            for correction.
        </p>
        <p><strong>Reason:</strong><br>' . nl2br(htmlspecialchars($reason)) . '</p>
        <p>Please log in, make the necessary corrections, and resubmit the scores.</p>
        <p>
            <a href="' . APP_URL . '/auth/login.php" 
               style="display:inline-block; background:#006400; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:5px;">
               Login to Correct
            </a>
        </p>
        <p>Regards,<br>CSCResultPortal</p>
    ';

    return sendSystemEmail(
        $lecturerEmail,
        $lecturerName,
        $subject,
        buildEmailTemplate('Result Flagged for Correction', $body),
        "Dear {$lecturerName}, your result for {$courseCode} - {$courseTitle} was flagged by {$advisorName}. Reason: {$reason}",
        $lecturerId,
        $senderId,
        'result_flagged',
        'Result Flagged',
        "Your submitted result for {$courseCode} was flagged for correction.",
        $resultId,
        'result'
    );
}


/**
 * 3. Advisor forwards result → HOD gets email
 */
function sendResultForwardedToHODEmail(
    int    $hodId,
    string $hodEmail,
    string $hodName,
    string $advisorName,
    string $courseCode,
    string $courseTitle,
    string $semesterName,
    string $sessionName,
    ?int   $resultId = null,
    ?int   $senderId = null
): bool {
    $subject = 'Result Forwarded to HOD - ' . $courseCode;

    $body = '
        <p>Dear ' . htmlspecialchars($hodName) . ',</p>
        <p>
            The Course Advisor, <strong>' . htmlspecialchars($advisorName) . '</strong>, has forwarded the reviewed result for
            <strong>' . htmlspecialchars($courseCode) . ' - ' . htmlspecialchars($courseTitle) . '</strong>
            to your office for approval.
        </p>
        <p>
            Session: <strong>' . htmlspecialchars($sessionName) . '</strong><br>
            Semester: <strong>' . htmlspecialchars($semesterName) . '</strong>
        </p>
        <p>Please log in to review and approve the result.</p>
        <p>
            <a href="' . APP_URL . '/auth/login.php" 
               style="display:inline-block; background:#006400; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:5px;">
               Login to Approve
            </a>
        </p>
        <p>Regards,<br>CSCResultPortal</p>
    ';

    return sendSystemEmail(
        $hodEmail,
        $hodName,
        $subject,
        buildEmailTemplate('Result Forwarded to HOD', $body),
        "Dear {$hodName}, {$advisorName} has forwarded {$courseCode} - {$courseTitle} for your approval.",
        $hodId,
        $senderId,
        'result_forwarded',
        'Result Forwarded to HOD',
        "{$advisorName} forwarded {$courseCode} - {$courseTitle} for approval.",
        $resultId,
        'result'
    );
}


/**
 * 4. HOD approves → HOD gets confirmation email
 */
function sendHODApprovalConfirmationEmail(
    int    $hodId,
    string $hodEmail,
    string $hodName,
    string $courseCode,
    string $courseTitle,
    string $semesterName,
    string $sessionName,
    ?int   $resultId = null
): bool {
    $subject = 'HOD Approval Confirmation - ' . $courseCode;

    $body = '
        <p>Dear ' . htmlspecialchars($hodName) . ',</p>
        <p>
            This is to confirm that you have successfully approved the result for
            <strong>' . htmlspecialchars($courseCode) . ' - ' . htmlspecialchars($courseTitle) . '</strong>.
        </p>
        <p>
            Session: <strong>' . htmlspecialchars($sessionName) . '</strong><br>
            Semester: <strong>' . htmlspecialchars($semesterName) . '</strong>
        </p>
        <p>
            The result can now proceed to the next departmental stage pending Senate approval outside the system.
        </p>
        <p>Regards,<br>CSCResultPortal</p>
    ';

    return sendSystemEmail(
        $hodEmail,
        $hodName,
        $subject,
        buildEmailTemplate('HOD Approval Confirmation', $body),
        "Dear {$hodName}, you successfully approved {$courseCode} - {$courseTitle}.",
        $hodId,
        $hodId,
        'hod_approved',
        'Result Approved',
        "You approved {$courseCode} - {$courseTitle}.",
        $resultId,
        'result'
    );
}


/**
 * 5. HOD final approval → Students and staff get email
 */
function sendFinalApprovalEmail(
    int    $recipientId,
    string $recipientEmail,
    string $recipientName,
    string $semesterName,
    string $sessionName,
    string $roleLabel,
    ?int   $senderId = null
): bool {
    $subject = 'Final Departmental Result Approval - ' . $sessionName;

    $body = '
        <p>Dear ' . htmlspecialchars($recipientName) . ',</p>
        <p>
            This is to notify you that the departmental results for
            <strong>' . htmlspecialchars($semesterName) . '</strong>,
            <strong>' . htmlspecialchars($sessionName) . '</strong>
            have received final departmental approval.
        </p>';

    if (strtolower($roleLabel) === 'student') {
        $body .= '
        <p>
            Student portal access for approved results is now available.
            You may now log in to view your results and register carryover courses where applicable.
        </p>';
    } else {
        $body .= '
        <p>
            The approved results have now been published in accordance with departmental workflow.
        </p>';
    }

    $body .= '
        <p>
            <a href="' . APP_URL . '/auth/login.php" 
               style="display:inline-block; background:#006400; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:5px;">
               Login to Portal
            </a>
        </p>
        <p>Regards,<br>CSCResultPortal</p>
    ';

    return sendSystemEmail(
        $recipientEmail,
        $recipientName,
        $subject,
        buildEmailTemplate('Final Departmental Approval', $body),
        "Dear {$recipientName}, results for {$semesterName}, {$sessionName} now have final departmental approval.",
        $recipientId,
        $senderId,
        'final_approved',
        'Final Departmental Approval',
        "Results for {$semesterName}, {$sessionName} have been finally approved.",
        null,
        'semester_publication'
    );
}


/**
 * 6. Student submits carryover → Advisor gets email
 */
function sendCarryoverSubmissionEmail(
    int    $advisorId,
    string $advisorEmail,
    string $advisorName,
    string $studentName,
    string $matricNumber,
    string $courseCode,
    string $courseTitle,
    ?int   $carryoverId = null,
    ?int   $senderId = null
): bool {
    $subject = 'New Carryover Registration Submitted - ' . $courseCode;

    $body = '
        <p>Dear ' . htmlspecialchars($advisorName) . ',</p>
        <p>
            A student has submitted a carryover registration request.
        </p>
        <p>
            <strong>Student:</strong> ' . htmlspecialchars($studentName) . '<br>
            <strong>Matric Number:</strong> ' . htmlspecialchars($matricNumber) . '<br>
            <strong>Course:</strong> ' . htmlspecialchars($courseCode) . ' - ' . htmlspecialchars($courseTitle) . '
        </p>
        <p>Please log in to review and process the carryover request.</p>
        <p>
            <a href="' . APP_URL . '/auth/login.php" 
               style="display:inline-block; background:#006400; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:5px;">
               Login to Review
            </a>
        </p>
        <p>Regards,<br>CSCResultPortal</p>
    ';

    return sendSystemEmail(
        $advisorEmail,
        $advisorName,
        $subject,
        buildEmailTemplate('New Carryover Registration', $body),
        "Dear {$advisorName}, {$studentName} ({$matricNumber}) submitted carryover for {$courseCode} - {$courseTitle}.",
        $advisorId,
        $senderId,
        'carryover_submitted',
        'New Carryover Registration',
        "{$studentName} submitted carryover for {$courseCode} - {$courseTitle}.",
        $carryoverId,
        'carryover'
    );
}


/**
 * 7. Advisor processes carryover → Student gets email
 */
function sendCarryoverProcessedEmail(
    int    $studentId,
    string $studentEmail,
    string $studentName,
    string $courseCode,
    string $courseTitle,
    string $status,
    string $advisorNote = '',
    ?int   $carryoverId = null,
    ?int   $senderId = null
): bool {
    $subject = 'Carryover Registration Update - ' . $courseCode;

    $statusText = ucfirst($status);

    $body = '
        <p>Dear ' . htmlspecialchars($studentName) . ',</p>
        <p>
            Your carryover registration request for
            <strong>' . htmlspecialchars($courseCode) . ' - ' . htmlspecialchars($courseTitle) . '</strong>
            has been <strong>' . htmlspecialchars($statusText) . '</strong>.
        </p>';

    if (!empty($advisorNote)) {
        $body .= '
        <p><strong>Advisor Note:</strong><br>' . nl2br(htmlspecialchars($advisorNote)) . '</p>';
    }

    $body .= '
        <p>Please log in to your portal for more details.</p>
        <p>
            <a href="' . APP_URL . '/auth/login.php" 
               style="display:inline-block; background:#006400; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:5px;">
               Login to Portal
            </a>
        </p>
        <p>Regards,<br>CSCResultPortal</p>
    ';

    return sendSystemEmail(
        $studentEmail,
        $studentName,
        $subject,
        buildEmailTemplate('Carryover Registration Update', $body),
        "Dear {$studentName}, your carryover request for {$courseCode} - {$courseTitle} has been {$status}.",
        $studentId,
        $senderId,
        'carryover_processed',
        'Carryover Registration Update',
        "Your carryover request for {$courseCode} - {$courseTitle} has been {$status}.",
        $carryoverId,
        'carryover'
    );
}


// ============================================================
// SECTION 8: BULK FINAL APPROVAL EMAIL HELPER
// Used when HOD gives final approval and all students/staff
// must be notified.
// ============================================================

/**
 * Sends final approval emails to multiple users.
 *
 * @param array    $recipients Array of users with id, email, full_name, role
 * @param string   $semesterName
 * @param string   $sessionName
 * @param int|null $senderId
 * @return array   Summary of sent/failed counts
 */
function sendBulkFinalApprovalEmails(
    array   $recipients,
    string  $semesterName,
    string  $sessionName,
    ?int    $senderId = null
): array {
    $sent   = 0;
    $failed = 0;

    foreach ($recipients as $recipient) {
        $success = sendFinalApprovalEmail(
            (int) $recipient['id'],
            $recipient['email'],
            $recipient['full_name'],
            $semesterName,
            $sessionName,
            $recipient['role'],
            $senderId
        );

        if ($success) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return [
        'sent'   => $sent,
        'failed' => $failed
    ];
}



// ============================================================
// SECTION: ONE-TIME LOGIN CODE (OTP) EMAIL
// ============================================================

/**
 * Sends the 6-digit login code to an admin.
 * recipientId is left out on purpose so the code is NEVER saved
 * as an in-app notification.
 */
function sendLoginOtpEmail(string $toEmail, string $toName, string $code, int $expiryMinutes): bool
{
    $subject = 'Your CSCResultPortal login code';

    $body = '
        <p>Dear ' . htmlspecialchars($toName) . ',</p>
        <p>Use this code to finish signing in:</p>
        <p style="text-align:center; margin:25px 0;">
            <span style="display:inline-block; font-family:monospace; font-size:34px; font-weight:bold;
                         letter-spacing:10px; color:#006400; background:#f1f8f1;
                         border:2px dashed #006400; padding:12px 24px; border-radius:8px;">'
                . htmlspecialchars($code) . '</span>
        </p>
        <p>This code expires in <strong>' . (int) $expiryMinutes . ' minutes</strong> and can only be used once.</p>
        <p style="color:#b00020;">
            <strong>Did not try to sign in?</strong> Someone may know your password.
            Change it right away and tell the HOD office. Never share this code with anyone.
        </p>
        <p>Regards,<br>CSCResultPortal</p>';

    $plain = "Your CSCResultPortal login code is {$code}. It expires in {$expiryMinutes} minutes "
           . "and can only be used once. If you did not try to sign in, change your password immediately.";

    return sendSystemEmail(
        $toEmail,
        $toName,
        $subject,
        buildEmailTemplate('Login verification code', $body),
        $plain
    );
}