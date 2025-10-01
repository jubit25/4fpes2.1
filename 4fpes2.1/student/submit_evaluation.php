<?php
require_once '../config.php';
requireRole('student');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }
        // Enforce evaluation schedule and active period for students
        list($ok, $err, $period) = enforceActiveSemesterYear($pdo);
        if (!$ok) {
            throw new Exception($err);
        }
        
        // Get and validate form data
        $faculty_id = (int)($_POST['faculty_id'] ?? 0);
        $subject = sanitizeInput($_POST['subject'] ?? '');
        // Force semester and academic year to current active period
        $semester = $period['semester'];
        $academic_year = $period['academic_year'];
        $overall_comments = sanitizeInput($_POST['overall_comments'] ?? '');
        // Force anonymity for student evaluations regardless of form input
        $is_anonymous = 1;
        
        if (!$faculty_id || !$subject) {
            throw new Exception('All required fields must be filled');
        }
        
        // Check if faculty exists
        $stmt = $pdo->prepare("SELECT id FROM faculty WHERE id = ?");
        $stmt->execute([$faculty_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid faculty selection');
        }

        // Ensure the selected faculty and subject are part of the student's enrollments
        // Map faculty.id -> users.id (faculty_user_id) then verify against junction table
        $stmt = $pdo->prepare("SELECT f.user_id AS faculty_user_id FROM faculty f WHERE f.id = ?");
        $stmt->execute([$faculty_id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new Exception('Invalid faculty selection');
        }
        $faculty_user_id = (int)$row['faculty_user_id'];

        // Ensure junction table exists (for safety)
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_faculty_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_user_id INT NOT NULL,
            faculty_user_id INT NOT NULL,
            subject_code VARCHAR(50) DEFAULT NULL,
            subject_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_assignment (student_user_id, faculty_user_id, subject_code, subject_name),
            INDEX idx_student (student_user_id),
            INDEX idx_faculty (faculty_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $pdo->prepare("SELECT 1 FROM student_faculty_subjects 
                                WHERE student_user_id = ? AND faculty_user_id = ? AND subject_name = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id'], $faculty_user_id, $subject]);
        if (!$stmt->fetch()) {
            throw new Exception('You can only evaluate faculty for your enrolled subjects.');
        }
        
        // Check for duplicate evaluation
        $stmt = $pdo->prepare("SELECT id FROM evaluations WHERE student_id = ? AND faculty_id = ? AND subject = ? AND semester = ? AND academic_year = ?");
        $stmt->execute([$_SESSION['student_id'], $faculty_id, $subject, $semester, $academic_year]);
        if ($stmt->fetch()) {
            throw new Exception('You have already evaluated this faculty for this subject and semester');
        }
        
        // Ensure evaluations table has evaluator metadata and supports NULL student_id
        try {
            $pdo->exec("ALTER TABLE evaluations 
                ADD COLUMN IF NOT EXISTS evaluator_user_id INT NULL,
                ADD COLUMN IF NOT EXISTS evaluator_role ENUM('student','faculty','dean') NULL,
                ADD COLUMN IF NOT EXISTS is_self BOOLEAN DEFAULT 0");
        } catch (PDOException $e) {
            // Ignore if columns already exist or if server doesn't support IF NOT EXISTS (older MySQL)
        }
        try {
            $pdo->exec("ALTER TABLE evaluations MODIFY student_id INT NULL");
        } catch (PDOException $e) {
            // Ignore if already nullable or lacking privileges
        }

        // Ensure unique indexes exist (safe no-op if already present)
        if (function_exists('ensureEvaluationUniqueIndexes')) {
            ensureEvaluationUniqueIndexes($pdo);
        }

        // Start transaction
        $pdo->beginTransaction();
        
        // Insert evaluation
        // Try inserting with evaluator fields if available; fallback to legacy columns if not
        $inserted = false;
        try {
            $stmt = $pdo->prepare("INSERT INTO evaluations (student_id, faculty_id, semester, academic_year, subject, comments, is_anonymous, evaluator_user_id, evaluator_role, is_self, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'submitted', NOW())");
            $stmt->execute([$_SESSION['student_id'], $faculty_id, $semester, $academic_year, $subject, $overall_comments, $is_anonymous, $_SESSION['user_id'], 'student']);
            $inserted = true;
        } catch (PDOException $e) {
            // Fallback to legacy insert if evaluator columns are not present
            $stmt = $pdo->prepare("INSERT INTO evaluations (student_id, faculty_id, semester, academic_year, subject, comments, is_anonymous, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())");
            $stmt->execute([$_SESSION['student_id'], $faculty_id, $semester, $academic_year, $subject, $overall_comments, $is_anonymous]);
        }
        
        $evaluation_id = $pdo->lastInsertId();
        
        // Get all criteria ratings and comments
        $total_rating = 0;
        $criteria_count = 0;
        
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'rating_') === 0) {
                $criterion_id = (int)str_replace('rating_', '', $key);
                $rating = (int)$value;
                $comment_key = 'comment_' . $criterion_id;
                $comment = sanitizeInput($_POST[$comment_key] ?? '');
                
                if ($rating >= 1 && $rating <= 5) {
                    $stmt = $pdo->prepare("INSERT INTO evaluation_responses (evaluation_id, criterion_id, rating, comment) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$evaluation_id, $criterion_id, $rating, $comment]);
                    
                    $total_rating += $rating;
                    $criteria_count++;
                }
            }
        }
        
        // Calculate and update overall rating
        if ($criteria_count > 0) {
            $overall_rating = round($total_rating / $criteria_count, 2);
            $stmt = $pdo->prepare("UPDATE evaluations SET overall_rating = ? WHERE id = ?");
            $stmt->execute([$overall_rating, $evaluation_id]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => '✅ Evaluation submitted successfully. You cannot evaluate this faculty again for the same subject and period.'
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
?>

