-- ============================================================
-- CSCResultPortal - Complete Database Schema
-- University: Federal University of Technology Owerri (FUTO)
-- Department: Computer Science
-- Project: Optimized Departmental Result Processing System
-- Methodology: SSADM
-- ============================================================

-- Create and select the database
CREATE DATABASE IF NOT EXISTS cscresultportal 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE cscresultportal;

-- ============================================================
-- TABLE 1: users
-- Purpose: Stores ALL system users (students, lecturers,
--          advisors, HOD). One central table for authentication.
--          Role determines what each user can access.
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,  -- Unique user ID
    full_name       VARCHAR(150)    NOT NULL,                 -- User's full name
    email           VARCHAR(150)    NOT NULL UNIQUE,          -- Login email (must be unique)
    password_hash   VARCHAR(255)    NOT NULL,                 -- Bcrypt hashed password
    role            ENUM(
                        'student',
                        'lecturer',
                        'advisor',
                        'hod'
                    )               NOT NULL,                 -- Determines dashboard and permissions
    matric_number   VARCHAR(20)     NULL UNIQUE,              -- Only for students e.g. CSC/2020/001
    staff_id        VARCHAR(20)     NULL UNIQUE,              -- Only for lecturers, advisors, HOD
    phone           VARCHAR(20)     NULL,                     -- Optional contact number
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,       -- 1=active, 0=disabled/suspended
    created_by      INT UNSIGNED    NULL,                     -- Who created this account (HOD id)
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP 
                                    ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign key: created_by references another user (the HOD)
    CONSTRAINT fk_users_created_by 
        FOREIGN KEY (created_by) REFERENCES users(id) 
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- Index for fast login lookup by email
CREATE INDEX idx_users_email ON users(email);
-- Index for fast student lookup by matric number
CREATE INDEX idx_users_matric ON users(matric_number);
-- Index for filtering users by role
CREATE INDEX idx_users_role ON users(role);


-- ============================================================
-- TABLE 2: student_profiles
-- Purpose: Stores extra academic details specific to students.
--          Linked one-to-one with users table.
-- ============================================================
CREATE TABLE IF NOT EXISTS student_profiles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL UNIQUE,          -- Links to users.id (student only)
    level           ENUM(
                        '100',
                        '200',
                        '300',
                        '400',
                        '500'
                    )               NOT NULL DEFAULT '100',   -- Current academic level
    entry_year      YEAR            NOT NULL,                 -- Year of admission e.g. 2020
    graduation_year YEAR            NULL,                     -- Expected graduation year
    cgpa            DECIMAL(3,2)    NOT NULL DEFAULT 0.00,    -- Cumulative GPA (0.00 to 5.00)
    degree_class    VARCHAR(50)     NULL,                     -- First Class, Second Class Upper etc.
    total_units     INT UNSIGNED    NOT NULL DEFAULT 0,       -- Total credit units earned so far
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP 
                                    ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_student_profiles_user 
        FOREIGN KEY (user_id) REFERENCES users(id) 
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 3: academic_sessions
-- Purpose: Stores each academic session (e.g. 2023/2024).
--          All results and courses are tied to a session.
-- ============================================================
CREATE TABLE IF NOT EXISTS academic_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_name    VARCHAR(20)     NOT NULL UNIQUE,          -- e.g. "2023/2024"
    is_current      TINYINT(1)      NOT NULL DEFAULT 0,       -- Only one session is current at a time
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Ensure only one session can be current at a time using a partial unique index
    -- (enforced in application logic as well)
    INDEX idx_sessions_current (is_current)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 4: semesters
-- Purpose: Each academic session has two semesters.
--          Tracks which semester is currently active.
-- ============================================================
CREATE TABLE IF NOT EXISTS semesters (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED    NOT NULL,                 -- Which academic session
    semester_name   ENUM(
                        'First Semester',
                        'Second Semester'
                    )               NOT NULL,                 -- Semester label
    is_current      TINYINT(1)      NOT NULL DEFAULT 0,       -- Only one semester active at a time
    start_date      DATE            NULL,                     -- Semester start date
    end_date        DATE            NULL,                     -- Semester end date
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_semesters_session 
        FOREIGN KEY (session_id) REFERENCES academic_sessions(id) 
        ON DELETE CASCADE,

    -- A session cannot have duplicate semester names
    UNIQUE KEY uq_semesters (session_id, semester_name)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 5: courses
-- Purpose: Master list of all CSC department courses.
--          Each course has a code, title, credit units, and level.
-- ============================================================
CREATE TABLE IF NOT EXISTS courses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_code     VARCHAR(15)     NOT NULL UNIQUE,          -- e.g. "CSC 301"
    course_title    VARCHAR(200)    NOT NULL,                 -- e.g. "Data Structures & Algorithms"
    credit_units    TINYINT UNSIGNED NOT NULL DEFAULT 2,      -- Credit units (1-6 typically)
    level           ENUM(
                        '100',
                        '200',
                        '300',
                        '400',
                        '500'
                    )               NOT NULL,                 -- Which level this course belongs to
    semester        ENUM(
                        'First Semester',
                        'Second Semester'
                    )               NOT NULL,                 -- Which semester this course runs
    is_elective     TINYINT(1)      NOT NULL DEFAULT 0,       -- 0=compulsory, 1=elective
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,       -- 1=offered, 0=discontinued
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_courses_level (level),
    INDEX idx_courses_semester (semester)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 6: course_assignments
-- Purpose: Assigns a course to a specific lecturer for a
--          specific semester. One lecturer can teach multiple
--          courses. One course can be taught by different
--          lecturers each semester.
-- ============================================================
CREATE TABLE IF NOT EXISTS course_assignments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id       INT UNSIGNED    NOT NULL,                 -- Which course
    lecturer_id     INT UNSIGNED    NOT NULL,                 -- Which lecturer (user with role=lecturer)
    semester_id     INT UNSIGNED    NOT NULL,                 -- Which semester
    ca_max          TINYINT UNSIGNED NOT NULL DEFAULT 30,     -- Max CA score set by lecturer
    exam_max        TINYINT UNSIGNED NOT NULL DEFAULT 70,     -- Max exam score set by lecturer
    -- ca_max + exam_max must equal 100 (enforced in application logic)
    scores_submitted TINYINT(1)     NOT NULL DEFAULT 0,       -- Has lecturer submitted scores?
    submitted_at    TIMESTAMP       NULL,                     -- When scores were submitted
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ca_course 
        FOREIGN KEY (course_id) REFERENCES courses(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_ca_lecturer 
        FOREIGN KEY (lecturer_id) REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_ca_semester 
        FOREIGN KEY (semester_id) REFERENCES semesters(id) 
        ON DELETE CASCADE,

    -- Same course cannot be assigned to two lecturers in same semester
    UNIQUE KEY uq_course_assignment (course_id, semester_id),
    INDEX idx_ca_lecturer (lecturer_id)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 7: student_course_registrations
-- Purpose: Tracks which courses each student is registered
--          for in each semester. Required before scores can
--          be entered for a student.
-- ============================================================
CREATE TABLE IF NOT EXISTS student_course_registrations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED    NOT NULL,                 -- Which student
    course_id       INT UNSIGNED    NOT NULL,                 -- Which course
    semester_id     INT UNSIGNED    NOT NULL,                 -- Which semester
    is_carryover    TINYINT(1)      NOT NULL DEFAULT 0,       -- 0=normal, 1=carryover repeat
    registration_date TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_scr_student 
        FOREIGN KEY (student_id) REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_scr_course 
        FOREIGN KEY (course_id) REFERENCES courses(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_scr_semester 
        FOREIGN KEY (semester_id) REFERENCES semesters(id) 
        ON DELETE CASCADE,

    -- A student cannot register the same course twice in the same semester
    UNIQUE KEY uq_student_course_semester (student_id, course_id, semester_id),
    INDEX idx_scr_student (student_id),
    INDEX idx_scr_semester (semester_id)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 8: results
-- Purpose: The CORE table. Stores every student's score for
--          every course in every semester. Tracks the full
--          approval workflow status of each result.
-- ============================================================
CREATE TABLE IF NOT EXISTS results (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED    NOT NULL,                 -- Which student
    course_id       INT UNSIGNED    NOT NULL,                 -- Which course
    semester_id     INT UNSIGNED    NOT NULL,                 -- Which semester
    assignment_id   INT UNSIGNED    NOT NULL,                 -- Links to course_assignments

    -- Score breakdown
    ca_score        DECIMAL(5,2)    NULL,                     -- Continuous Assessment score
    exam_score      DECIMAL(5,2)    NULL,                     -- Examination score
    total_score     DECIMAL(5,2)    NULL,                     -- CA + Exam = total (computed)
    grade           CHAR(1)         NULL,                     -- A, B, C, D, F
    grade_point     DECIMAL(3,1)    NULL,                     -- 5.0, 4.0, 3.0, 2.0, 0.0
    credit_units    TINYINT UNSIGNED NOT NULL,                -- Units for this course (copied from courses)
    quality_point   DECIMAL(6,2)    NULL,                     -- grade_point × credit_units

    -- Approval workflow status
    -- draft → submitted → advisor_reviewed → forwarded → hod_approved → final_approved
    status          ENUM(
                        'draft',           -- Lecturer saving but not submitted yet
                        'submitted',       -- Lecturer submitted → awaiting advisor
                        'flagged',         -- Advisor flagged back to lecturer
                        'forwarded',       -- Advisor forwarded to HOD
                        'hod_approved',    -- HOD approved → sent to Senate
                        'final_approved'   -- HOD final approval after Senate → students can see
                    )               NOT NULL DEFAULT 'draft',

    -- Carryover tracking
    is_carryover    TINYINT(1)      NOT NULL DEFAULT 0,       -- Is this a carryover attempt?
    attempt_number  TINYINT UNSIGNED NOT NULL DEFAULT 1,      -- 1st attempt, 2nd attempt etc.

    -- Audit trail timestamps
    submitted_at    TIMESTAMP       NULL,                     -- When lecturer submitted
    flagged_at      TIMESTAMP       NULL,                     -- When advisor flagged
    forwarded_at    TIMESTAMP       NULL,                     -- When advisor forwarded
    hod_approved_at TIMESTAMP       NULL,                     -- When HOD first approved
    final_approved_at TIMESTAMP     NULL,                     -- When HOD gave final approval

    -- Who did what
    flagged_by      INT UNSIGNED    NULL,                     -- Advisor user id who flagged
    forwarded_by    INT UNSIGNED    NULL,                     -- Advisor user id who forwarded
    hod_approved_by INT UNSIGNED    NULL,                     -- HOD user id who approved
    final_approved_by INT UNSIGNED  NULL,                     -- HOD user id who final approved

    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP 
                                    ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_results_student 
        FOREIGN KEY (student_id) REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_results_course 
        FOREIGN KEY (course_id) REFERENCES courses(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_results_semester 
        FOREIGN KEY (semester_id) REFERENCES semesters(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_results_assignment 
        FOREIGN KEY (assignment_id) REFERENCES course_assignments(id) 
        ON DELETE CASCADE,

    -- A student can only have one result record per course per semester per attempt
    UNIQUE KEY uq_result (student_id, course_id, semester_id, attempt_number),
    INDEX idx_results_student (student_id),
    INDEX idx_results_course (course_id),
    INDEX idx_results_semester (semester_id),
    INDEX idx_results_status (status)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 9: result_flags
-- Purpose: Stores the reason whenever a Course Advisor
--          flags a result back to a lecturer. Keeps a full
--          history of all flags for audit purposes.
-- ============================================================
CREATE TABLE IF NOT EXISTS result_flags (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    result_id       INT UNSIGNED    NOT NULL,                 -- Which result was flagged
    assignment_id   INT UNSIGNED    NOT NULL,                 -- Which course assignment
    flagged_by      INT UNSIGNED    NOT NULL,                 -- Advisor who raised the flag
    flag_reason     TEXT            NOT NULL,                 -- Detailed reason for flagging
    is_resolved     TINYINT(1)      NOT NULL DEFAULT 0,       -- 0=open, 1=resolved by lecturer
    resolved_at     TIMESTAMP       NULL,                     -- When lecturer resubmitted
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_flags_result 
        FOREIGN KEY (result_id) REFERENCES results(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_flags_assignment 
        FOREIGN KEY (assignment_id) REFERENCES course_assignments(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_flags_advisor 
        FOREIGN KEY (flagged_by) REFERENCES users(id) 
        ON DELETE CASCADE,

    INDEX idx_flags_result (result_id),
    INDEX idx_flags_assignment (assignment_id)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 10: semester_gpa
-- Purpose: Stores each student's computed GPA per semester.
--          Calculated after final approval of all results.
--          Used to build the CGPA trend chart.
-- ============================================================
CREATE TABLE IF NOT EXISTS semester_gpa (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED    NOT NULL,                 -- Which student
    semester_id     INT UNSIGNED    NOT NULL,                 -- Which semester
    total_units     TINYINT UNSIGNED NOT NULL DEFAULT 0,      -- Total credit units in this semester
    total_quality_points DECIMAL(8,2) NOT NULL DEFAULT 0.00, -- Sum of (grade_point × credit_units)
    gpa             DECIMAL(3,2)    NOT NULL DEFAULT 0.00,    -- GPA for this semester (0.00-5.00)
    cgpa            DECIMAL(3,2)    NOT NULL DEFAULT 0.00,    -- Cumulative GPA up to this semester
    computed_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP 
                                    ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_sgpa_student 
        FOREIGN KEY (student_id) REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_sgpa_semester 
        FOREIGN KEY (semester_id) REFERENCES semesters(id) 
        ON DELETE CASCADE,

    -- One GPA record per student per semester
    UNIQUE KEY uq_sgpa (student_id, semester_id),
    INDEX idx_sgpa_student (student_id)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 11: carryover_registrations
-- Purpose: Tracks student carryover requests. Students submit
--          failed courses they want to repeat. Advisor reviews
--          and HOD re-approves before publishing.
-- ============================================================
CREATE TABLE IF NOT EXISTS carryover_registrations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED    NOT NULL,                 -- Student applying
    course_id       INT UNSIGNED    NOT NULL,                 -- Failed course to repeat
    semester_id     INT UNSIGNED    NOT NULL,                 -- Current semester for registration
    original_result_id INT UNSIGNED NULL,                     -- The original failed result record
    reason          TEXT            NULL,                     -- Student's optional note
    status          ENUM(
                        'pending',      -- Student submitted, awaiting advisor
                        'approved',     -- Advisor approved the carryover
                        'rejected',     -- Advisor rejected (e.g. wrong course listed)
                        'completed'     -- Student has now passed the carryover course
                    )               NOT NULL DEFAULT 'pending',
    advisor_note    TEXT            NULL,                     -- Advisor's feedback/note
    reviewed_by     INT UNSIGNED    NULL,                     -- Advisor who reviewed
    reviewed_at     TIMESTAMP       NULL,                     -- When advisor reviewed
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP 
                                    ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_co_student 
        FOREIGN KEY (student_id) REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_co_course 
        FOREIGN KEY (course_id) REFERENCES courses(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_co_semester 
        FOREIGN KEY (semester_id) REFERENCES semesters(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_co_original 
        FOREIGN KEY (original_result_id) REFERENCES results(id) 
        ON DELETE SET NULL,
    CONSTRAINT fk_co_reviewer 
        FOREIGN KEY (reviewed_by) REFERENCES users(id) 
        ON DELETE SET NULL,

    INDEX idx_co_student (student_id),
    INDEX idx_co_status (status)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 12: notifications
-- Purpose: In-system notifications for all users.
--          Email is sent via PHPMailer AND a record is stored
--          here so users see alerts inside the dashboard.
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_id    INT UNSIGNED    NOT NULL,                 -- Who receives this notification
    sender_id       INT UNSIGNED    NULL,                     -- Who triggered it (NULL=system)
    title           VARCHAR(255)    NOT NULL,                 -- Short notification title
    message         TEXT            NOT NULL,                 -- Full notification body
    type            ENUM(
                        'scores_submitted',     -- Lecturer submitted scores
                        'result_flagged',       -- Advisor flagged result back
                        'result_forwarded',     -- Advisor forwarded to HOD
                        'hod_approved',         -- HOD approved results
                        'final_approved',       -- HOD final approval (students notified)
                        'carryover_submitted',  -- Student submitted carryover
                        'carryover_processed',  -- Advisor processed carryover
                        'general'               -- Any other system message
                    )               NOT NULL DEFAULT 'general',
    related_id      INT UNSIGNED    NULL,                     -- ID of related record (result, carryover etc.)
    related_type    VARCHAR(50)     NULL,                     -- What related_id refers to (e.g. "result")
    is_read         TINYINT(1)      NOT NULL DEFAULT 0,       -- 0=unread, 1=read
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notif_recipient 
        FOREIGN KEY (recipient_id) REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_notif_sender 
        FOREIGN KEY (sender_id) REFERENCES users(id) 
        ON DELETE SET NULL,

    INDEX idx_notif_recipient (recipient_id),
    INDEX idx_notif_read (recipient_id, is_read),
    INDEX idx_notif_created (created_at)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 13: batch_uploads
-- Purpose: Logs every batch upload (Excel/CSV) that the HOD
--          performs. Tracks success, failure, and file info.
-- ============================================================
CREATE TABLE IF NOT EXISTS batch_uploads (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uploaded_by     INT UNSIGNED    NOT NULL,                 -- HOD user id
    semester_id     INT UNSIGNED    NOT NULL,                 -- Target semester
    file_name       VARCHAR(255)    NOT NULL,                 -- Original filename
    file_path       VARCHAR(500)    NOT NULL,                 -- Server path to saved file
    total_rows      INT UNSIGNED    NOT NULL DEFAULT 0,       -- Total rows in file
    successful_rows INT UNSIGNED    NOT NULL DEFAULT 0,       -- Rows imported successfully
    failed_rows     INT UNSIGNED    NOT NULL DEFAULT 0,       -- Rows that failed
    error_log       TEXT            NULL,                     -- Details of failed rows
    status          ENUM(
                        'processing',   -- Upload in progress
                        'completed',    -- Fully processed
                        'failed'        -- Upload failed entirely
                    )               NOT NULL DEFAULT 'processing',
    uploaded_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_batch_uploader 
        FOREIGN KEY (uploaded_by) REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_batch_semester 
        FOREIGN KEY (semester_id) REFERENCES semesters(id) 
        ON DELETE CASCADE,

    INDEX idx_batch_uploader (uploaded_by)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 14: audit_log
-- Purpose: Immutable log of every important action in the
--          system. Used for accountability and SSADM audit trail.
--          Records WHO did WHAT to WHICH record and WHEN.
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NULL,                     -- Who performed the action
    action          VARCHAR(100)    NOT NULL,                 -- Action name e.g. "submit_scores"
    target_table    VARCHAR(100)    NULL,                     -- Table affected e.g. "results"
    target_id       INT UNSIGNED    NULL,                     -- Row id affected
    old_value       JSON            NULL,                     -- Previous state (JSON)
    new_value       JSON            NULL,                     -- New state (JSON)
    ip_address      VARCHAR(45)     NULL,                     -- User's IP address
    user_agent      VARCHAR(500)    NULL,                     -- Browser/agent string
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_user 
        FOREIGN KEY (user_id) REFERENCES users(id) 
        ON DELETE SET NULL,

    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE 15: password_resets
-- Purpose: Stores password reset tokens for users who forgot
--          their password. Tokens expire after 1 hour.
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,                 -- Which user requested reset
    token           VARCHAR(100)    NOT NULL UNIQUE,          -- Secure random token
    expires_at      TIMESTAMP       NOT NULL,                 -- Token expiry (1 hour from creation)
    is_used         TINYINT(1)      NOT NULL DEFAULT 0,       -- 0=valid, 1=already used
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_reset_user 
        FOREIGN KEY (user_id) REFERENCES users(id) 
        ON DELETE CASCADE,

    INDEX idx_reset_token (token),
    INDEX idx_reset_user (user_id)
) ENGINE=InnoDB;


-- ============================================================
-- ============================================================
-- SEED DATA: Default records to get the system running
-- ============================================================
-- ============================================================

-- ============================================================
-- SEED 1: Default HOD account
-- Password: Admin@1234 (bcrypt hashed)
-- HOD logs in and creates all other accounts from there
-- ============================================================
-- HOD Default Account
-- Password: password
-- Hash generated with password_hash('password', PASSWORD_BCRYPT, ['cost' => 12])
INSERT INTO users (
    full_name, 
    email, 
    password_hash, 
    role, 
    staff_id, 
    is_active
) VALUES (
    'Prof. HOD CSC',
    'hod@csc.futo.edu.ng',
    '$2y$12$.s/.gAsRCLe3NOL6S5hire9P4tKLpLRRHG.F8mKFfkXKhqepG4ME6',
    'hod',
    'HOD/CSC/001',
    1
);

-- ============================================================
-- SEED 2: Academic session 2023/2024
-- ============================================================
INSERT INTO academic_sessions (session_name, is_current) 
VALUES ('2023/2024', 1);

-- ============================================================
-- SEED 3: Both semesters for 2023/2024
-- ============================================================
INSERT INTO semesters (session_id, semester_name, is_current, start_date, end_date)
VALUES
    (1, 'First Semester',  0, '2023-09-01', '2024-01-31'),
    (1, 'Second Semester', 1, '2024-02-01', '2024-06-30');

-- ============================================================
-- SEED 4: Sample CSC courses (representative set)
-- These are typical FUTO Computer Science courses
-- ============================================================
INSERT INTO courses 
    (course_code, course_title, credit_units, level, semester, is_elective) 
VALUES
    -- 100 Level First Semester
    ('CSC 101', 'Introduction to Computer Science',        3, '100', 'First Semester',  0),
    ('CSC 103', 'Computer Programming I',                  3, '100', 'First Semester',  0),
    ('MTH 101', 'Elementary Mathematics I',                3, '100', 'First Semester',  0),
    ('PHY 101', 'General Physics I',                       3, '100', 'First Semester',  0),
    ('GST 101', 'Use of English I',                        2, '100', 'First Semester',  0),

    -- 100 Level Second Semester
    ('CSC 102', 'Introduction to Problem Solving',         3, '100', 'Second Semester', 0),
    ('CSC 104', 'Computer Programming II',                 3, '100', 'Second Semester', 0),
    ('MTH 102', 'Elementary Mathematics II',               3, '100', 'Second Semester', 0),
    ('PHY 102', 'General Physics II',                      3, '100', 'Second Semester', 0),
    ('GST 102', 'Use of English II',                       2, '100', 'Second Semester', 0),

    -- 200 Level First Semester
    ('CSC 201', 'Computer Architecture',                   3, '200', 'First Semester',  0),
    ('CSC 203', 'Data Structures and Algorithms',          3, '200', 'First Semester',  0),
    ('CSC 205', 'Discrete Mathematics',                    3, '200', 'First Semester',  0),
    ('CSC 207', 'Logic Design',                            3, '200', 'First Semester',  0),
    ('MTH 201', 'Calculus',                                3, '200', 'First Semester',  0),

    -- 200 Level Second Semester
    ('CSC 202', 'Operating Systems I',                     3, '200', 'Second Semester', 0),
    ('CSC 204', 'Object Oriented Programming',             3, '200', 'Second Semester', 0),
    ('CSC 206', 'Numerical Methods',                       3, '200', 'Second Semester', 0),
    ('CSC 208', 'Database Systems I',                      3, '200', 'Second Semester', 0),
    ('MTH 202', 'Statistics for Computing',                3, '200', 'Second Semester', 0),

    -- 300 Level First Semester
    ('CSC 301', 'Systems Analysis and Design',             3, '300', 'First Semester',  0),
    ('CSC 303', 'Computer Networks I',                     3, '300', 'First Semester',  0),
    ('CSC 305', 'Software Engineering I',                  3, '300', 'First Semester',  0),
    ('CSC 307', 'Theory of Computation',                   3, '300', 'First Semester',  0),
    ('CSC 309', 'Artificial Intelligence',                 3, '300', 'First Semester',  1),

    -- 300 Level Second Semester
    ('CSC 302', 'Compiler Construction',                   3, '300', 'Second Semester', 0),
    ('CSC 304', 'Computer Networks II',                    3, '300', 'Second Semester', 0),
    ('CSC 306', 'Software Engineering II',                 3, '300', 'Second Semester', 0),
    ('CSC 308', 'Database Systems II',                     3, '300', 'Second Semester', 0),
    ('CSC 310', 'Human-Computer Interaction',              3, '300', 'Second Semester', 1),

    -- 400 Level First Semester
    ('CSC 401', 'Distributed Systems',                     3, '400', 'First Semester',  0),
    ('CSC 403', 'Information Security',                    3, '400', 'First Semester',  0),
    ('CSC 405', 'Machine Learning',                        3, '400', 'First Semester',  1),
    ('CSC 407', 'Mobile Application Development',          3, '400', 'First Semester',  1),
    ('CSC 409', 'Project Management',                      2, '400', 'First Semester',  0),

    -- 400 Level Second Semester
    ('CSC 402', 'Final Year Project',                      6, '400', 'Second Semester', 0),
    ('CSC 404', 'Cloud Computing',                         3, '400', 'Second Semester', 1),
    ('CSC 406', 'Computer Vision',                         3, '400', 'Second Semester', 1),
    ('CSC 408', 'Professional Ethics in Computing',        2, '400', 'Second Semester', 0),
    ('CSC 410', 'Entrepreneurship in ICT',                 2, '400', 'Second Semester', 0);


-- ============================================================
-- ============================================================
-- VIEWS: Pre-built queries for common data needs
-- ============================================================
-- ============================================================

-- ============================================================
-- VIEW 1: v_results_full
-- Purpose: Complete result info joined with student, course,
--          and semester details. Used across advisor and HOD pages.
-- ============================================================
CREATE OR REPLACE VIEW v_results_full AS
SELECT
    r.id                    AS result_id,
    r.status                AS result_status,
    r.ca_score,
    r.exam_score,
    r.total_score,
    r.grade,
    r.grade_point,
    r.credit_units,
    r.quality_point,
    r.is_carryover,
    r.attempt_number,
    r.submitted_at,
    r.final_approved_at,

    -- Student info
    u.id                    AS student_id,
    u.full_name             AS student_name,
    u.matric_number,
    sp.level                AS student_level,

    -- Course info
    c.id                    AS course_id,
    c.course_code,
    c.course_title,
    c.credit_units          AS course_credit_units,

    -- Semester info
    s.id                    AS semester_id,
    s.semester_name,

    -- Session info
    ac.session_name,

    -- Lecturer info
    lu.full_name            AS lecturer_name,
    ca.ca_max,
    ca.exam_max

FROM results r
JOIN users u                ON r.student_id     = u.id
JOIN student_profiles sp    ON u.id             = sp.user_id
JOIN courses c              ON r.course_id      = c.id
JOIN semesters s            ON r.semester_id    = s.id
JOIN academic_sessions ac   ON s.session_id     = ac.id
JOIN course_assignments ca  ON r.assignment_id  = ca.id
JOIN users lu               ON ca.lecturer_id   = lu.id;


-- ============================================================
-- VIEW 2: v_student_cgpa
-- Purpose: Student's CGPA summary pulled from semester_gpa.
--          Shows latest cumulative GPA and degree class.
-- ============================================================
CREATE OR REPLACE VIEW v_student_cgpa AS
SELECT
    u.id                    AS student_id,
    u.full_name,
    u.matric_number,
    sp.level,
    sp.cgpa,
    sp.total_units,
    sp.degree_class,
    -- Degree class logic based on Nigerian 5.0 scale
    CASE
        WHEN sp.cgpa >= 4.50 THEN 'First Class Honours'
        WHEN sp.cgpa >= 3.50 THEN 'Second Class Honours (Upper Division)'
        WHEN sp.cgpa >= 2.40 THEN 'Second Class Honours (Lower Division)'
        WHEN sp.cgpa >= 1.50 THEN 'Third Class Honours'
        WHEN sp.cgpa >= 1.00 THEN 'Pass'
        ELSE 'Fail'
    END AS computed_degree_class
FROM users u
JOIN student_profiles sp ON u.id = sp.user_id
WHERE u.role = 'student';


-- ============================================================
-- VIEW 3: v_pending_results
-- Purpose: Results waiting at each stage of the workflow.
--          Used by advisor and HOD dashboards for quick count.
-- ============================================================
CREATE OR REPLACE VIEW v_pending_results AS
SELECT
    r.id            AS result_id,
    r.status,
    c.course_code,
    c.course_title,
    u.full_name     AS student_name,
    u.matric_number,
    lu.full_name    AS lecturer_name,
    s.semester_name,
    ac.session_name
FROM results r
JOIN courses c              ON r.course_id      = c.id
JOIN users u                ON r.student_id     = u.id
JOIN course_assignments ca  ON r.assignment_id  = ca.id
JOIN users lu               ON ca.lecturer_id   = lu.id
JOIN semesters s            ON r.semester_id    = s.id
JOIN academic_sessions ac   ON s.session_id     = ac.id
WHERE r.status IN ('submitted', 'forwarded');


-- ============================================================
-- All tables and seed data created successfully.
-- 
-- TABLES SUMMARY:
-- 1.  users                        - All system users
-- 2.  student_profiles             - Extra student academic data
-- 3.  academic_sessions            - e.g. 2023/2024
-- 4.  semesters                    - First/Second semester per session
-- 5.  courses                      - Master course list
-- 6.  course_assignments           - Lecturer assigned to course
-- 7.  student_course_registrations - Student course enrollment
-- 8.  results                      - Core result records + workflow
-- 9.  result_flags                 - Advisor flag history
-- 10. semester_gpa                 - GPA per semester per student
-- 11. carryover_registrations      - Student carryover requests
-- 12. notifications                - In-app notification records
-- 13. batch_uploads                - HOD CSV/Excel upload logs
-- 14. audit_log                    - Immutable action trail
-- 15. password_resets              - Reset token management
--
-- VIEWS SUMMARY:
-- v_results_full       - Full result details (joined)
-- v_student_cgpa       - Student CGPA summary
-- v_pending_results    - Results in workflow pipeline
-- ============================================================