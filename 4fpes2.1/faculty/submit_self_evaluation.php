<?php
require_once '../config.php';
requireRole('faculty');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        // The faculty member can only self-evaluate themselves
        $faculty_id = (int)($_POST['faculty_id'] ?? 0);
        if (!$faculty_id || $faculty_id !== (int)$_SESSION['faculty_id']) {
            throw new Exception('Invalid faculty selection for self-evaluation');
        }

        $subject = sanitizeInput($_POST['subject'] ?? '');
        $semester = sanitizeInput($_POST['semester'] ?? '');
        $academic_year = sanitizeInput($_POST['academic_year'] ?? '');
        $overall_comments = sanitizeInput($_POST['overall_comments'] ?? '');

        if (!$subject || !$semester || !$academic_year) {
            throw new Exception('All required fields must be filled');
        }

        // Ensure schema supports evaluator metadata
        try {
            $pdo->exec("ALTER TABLE evaluations 
                ADD COLUMN IF NOT EXISTS evaluator_user_id INT NULL,
                ADD COLUMN IF NOT EXISTS evaluator_role ENUM('student','faculty','dean') NULL,
                ADD COLUMN IF NOT EXISTS is_self BOOLEAN DEFAULT 0");
        } catch (PDOException $e) { /* ignore */ }
        try {
            $pdo->exec("ALTER TABLE evaluations MODIFY student_id INT NULL");
        } catch (PDOException $e) { /* ignore */ }

        // Prevent duplicate self-evaluation for the same subject/term/year
        $stmt = $pdo->prepare("SELECT id FROM evaluations WHERE faculty_id = ? AND subject = ? AND semester = ? AND academic_year = ? AND COALESCE(is_self,0) = 1 LIMIT 1");
        try { $stmt->execute([$faculty_id, $subject, $semester, $academic_year]); } catch (PDOException $e) { /* older schema fallback */ $stmt = $pdo->prepare("SELECT id FROM evaluations WHERE faculty_id = ? AND subject = ? AND semester = ? AND academic_year = ? LIMIT 1"); $stmt->execute([$faculty_id, $subject, $semester, $academic_year]); }
        if ($stmt->fetch()) {
            throw new Exception('You have already submitted a self-evaluation for this subject and term.');
        }

        $pdo->beginTransaction();

        // Self-evaluation must not be anonymous
        $is_anonymous = 0;

        // Insert evaluation
        try {
            $stmt = $pdo->prepare("INSERT INTO evaluations 
                (student_id, faculty_id, semester, academic_year, subject, comments, is_anonymous, evaluator_user_id, evaluator_role, is_self, status, submitted_at)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'faculty', 1, 'submitted', NOW())");
            $stmt->execute([$faculty_id, $semester, $academic_year, $subject, $overall_comments, $is_anonymous, $_SESSION['user_id']]);
        } catch (PDOException $e) {
            // fallback without evaluator columns; still record as non-anonymous
            $stmt = $pdo->prepare("INSERT INTO evaluations 
                (student_id, faculty_id, semester, academic_year, subject, comments, is_anonymous, status, submitted_at)
                VALUES (NULL, ?, ?, ?, ?, ?, 0, 'submitted', NOW())");
            $stmt->execute([$faculty_id, $semester, $academic_year, $subject, $overall_comments]);
        }

        $evaluation_id = $pdo->lastInsertId();

        // Criteria ratings
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

        if ($criteria_count > 0) {
            $overall_rating = round($total_rating / $criteria_count, 2);
            $stmt = $pdo->prepare("UPDATE evaluations SET overall_rating = ? WHERE id = ?");
            $stmt->execute([$overall_rating, $evaluation_id]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '✅ Your self-evaluation has been submitted successfully.'
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
