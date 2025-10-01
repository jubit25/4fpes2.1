<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'faculty_evaluation_system');

// Start session
session_start();

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ---------------- Semester/Academic Year Helpers ----------------
// Returns array ['semester' => '1st Semester'|'2nd Semester', 'academic_year' => 'YYYY-YYYY+1'] based on current date
function getCurrentSemesterYear(DateTime $now = null) {
    $now = $now ?: new DateTime('now');
    $y = (int)$now->format('Y');
    $m = (int)$now->format('n');
    if ($m >= 8 && $m <= 12) { // Aug-Dec
        $semester = '1st Semester';
        $academic_year = sprintf('%d-%d', $y, $y + 1);
    } else { // Jan-Jun
        $semester = '2nd Semester';
        $academic_year = sprintf('%d-%d', $y - 1, $y);
    }
    return ['semester' => $semester, 'academic_year' => $academic_year];
}

// Derive the active semester/year tied to evaluation schedule. If schedule is open, returns current period.
// If schedule is closed or unscheduled, returns null to indicate evaluations are unavailable.
function getActiveSemesterYear($pdo) {
    list($openNow,,,$sch) = isEvaluationOpenForStudents($pdo);
    if (!$openNow) { return null; }
    // Optionally, we could anchor to schedule window boundaries, but current date suffices while open
    return getCurrentSemesterYear(new DateTime('now'));
}

// Enforce that evaluations use the active semester/year. Returns [bool ok, string error|null, array period|null]
function enforceActiveSemesterYear($pdo) {
    $period = getActiveSemesterYear($pdo);
    if (!$period) {
        return [false, 'Evaluation is not available for this semester. Please wait for the current evaluation schedule.', null];
    }
    return [true, null, $period];
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /4fpes2.1/index.php');
        exit();
    }
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        header('Location: /4fpes2.1/dashboard.php');
        exit();
    }
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------------- Evaluation Schedule Helpers ----------------
// Global schedule, applies to all departments/students
function ensureEvaluationScheduleTable($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_schedule (
            id INT PRIMARY KEY,
            start_at DATETIME NULL,
            end_at DATETIME NULL,
            override_mode ENUM('auto','open','closed') DEFAULT 'auto',
            notice VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        // Ensure singleton row exists
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM evaluation_schedule WHERE id = 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || (int)$row['c'] === 0) {
            $pdo->prepare("INSERT INTO evaluation_schedule (id, start_at, end_at, override_mode, notice) VALUES (1, NULL, NULL, 'auto', NULL)")->execute();
        }
    } catch (PDOException $e) {
        // ignore creation errors; callers should handle absence gracefully
    }
}

function getEvaluationSchedule($pdo) {
    ensureEvaluationScheduleTable($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM evaluation_schedule WHERE id = 1 LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: [
            'id' => 1,
            'start_at' => null,
            'end_at' => null,
            'override_mode' => 'auto',
            'notice' => null,
            'updated_at' => null
        ];
    } catch (PDOException $e) {
        return [
            'id' => 1,
            'start_at' => null,
            'end_at' => null,
            'override_mode' => 'auto',
            'notice' => null,
            'updated_at' => null
        ];
    }
}

// Returns [is_open(bool), state(string: 'open'|'closed'), reason(string: 'override'|'schedule'|'unscheduled'), schedule(array)]
function isEvaluationOpenForStudents($pdo) {
    $sch = getEvaluationSchedule($pdo);
    $override = $sch['override_mode'] ?? 'auto';
    $now = new DateTime('now');

    if ($override === 'open') {
        return [true, 'open', 'override', $sch];
    }
    if ($override === 'closed') {
        return [false, 'closed', 'override', $sch];
    }
    // auto mode: rely on schedule window
    $startAt = !empty($sch['start_at']) ? new DateTime($sch['start_at']) : null;
    $endAt = !empty($sch['end_at']) ? new DateTime($sch['end_at']) : null;
    if ($startAt && $endAt) {
        if ($now >= $startAt && $now <= $endAt) {
            return [true, 'open', 'schedule', $sch];
        }
        return [false, 'closed', 'schedule', $sch];
    }
    // no schedule set
    return [false, 'closed', 'unscheduled', $sch];
}

function saveEvaluationSchedule($pdo, $startAt, $endAt, $notice = null) {
    ensureEvaluationScheduleTable($pdo);
    $stmt = $pdo->prepare("UPDATE evaluation_schedule SET start_at = ?, end_at = ?, notice = ? WHERE id = 1");
    $stmt->execute([$startAt ?: null, $endAt ?: null, $notice]);
}

function setEvaluationOverride($pdo, $mode) {
    ensureEvaluationScheduleTable($pdo);
    // $mode must be one of auto|open|closed
    if (!in_array($mode, ['auto','open','closed'], true)) { $mode = 'auto'; }
    $stmt = $pdo->prepare("UPDATE evaluation_schedule SET override_mode = ? WHERE id = 1");
    $stmt->execute([$mode]);
}

// Ensure unique indexes to enforce one evaluation per faculty per period
function ensureEvaluationUniqueIndexes($pdo) {
    try {
        // Check and create uniq_student_eval
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = 'evaluations' AND index_name = 'uniq_student_eval'");
        $stmt->execute([DB_NAME]);
        $row = $stmt->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $pdo->exec("CREATE UNIQUE INDEX uniq_student_eval ON evaluations (student_id, faculty_id, subject, semester, academic_year)");
        }
    } catch (PDOException $e) { /* ignore */ }

    try {
        // Check and create uniq_dean_eval
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = 'evaluations' AND index_name = 'uniq_dean_eval'");
        $stmt->execute([DB_NAME]);
        $row = $stmt->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $pdo->exec("CREATE UNIQUE INDEX uniq_dean_eval ON evaluations (evaluator_user_id, evaluator_role, faculty_id, subject, semester, academic_year)");
        }
    } catch (PDOException $e) { /* ignore */ }
}
?>
