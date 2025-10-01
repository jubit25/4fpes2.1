<?php
require_once '../config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        try {
            // Validate CSRF token
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }
            
            // Get and validate form data
            $username = sanitizeInput($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = sanitizeInput($_POST['role'] ?? '');
            $full_name = sanitizeInput($_POST['full_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $department = sanitizeInput($_POST['department'] ?? '');
            // Normalize department values to canonical names used across the system
            $deptMap = [
                'School of Technology' => 'Technology',
                'School of Business' => 'Business',
                'School of Education' => 'Education',
                'SOT' => 'Technology',
                'SOB' => 'Business',
                'SOE' => 'Education',
            ];
            if (isset($deptMap[$department])) {
                $department = $deptMap[$department];
            }
            
            // For Students, username is auto-set to Student ID;
            // For Faculty/Dean, username will be auto-set to Employee ID.
            if ($role === 'student' || $role === 'faculty' || $role === 'dean') {
                if (!$password || !$role || !$full_name || !$department) {
                    throw new Exception('All required fields must be filled');
                }
            } else {
                // Admins still require a manual username
                if (!$username || !$password || !$role || !$full_name || !$department) {
                    throw new Exception('All required fields must be filled');
                }
            }

            // Validate full name: only ASCII letters and spaces; must contain at least one letter
            if (!preg_match('/^(?=.*[A-Za-z])[A-Za-z ]+$/', $full_name)) {
                throw new Exception('Full Name must only contain letters and spaces.');
            }
            
            if (!in_array($role, ['student', 'faculty', 'dean', 'admin'])) {
                throw new Exception('Invalid role selected');
            }
            
            // Helper to generate next Employee ID per role using the employees table (Faculty: F-###, Dean: D-###)
            $generateEmployeeId = function(PDO $pdo, string $role): string {
                $roleMap = [
                    'faculty' => ['prefix' => 'F-', 'enum' => 'Faculty'],
                    'dean'    => ['prefix' => 'D-', 'enum' => 'Dean'],
                ];
                if (!isset($roleMap[$role])) {
                    throw new Exception('Unsupported role for employee ID generation');
                }
                $prefix = $roleMap[$role]['prefix'];
                $enumRole = $roleMap[$role]['enum'];
                $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(employee_id, 3) AS UNSIGNED)) AS max_seq FROM employees WHERE role = ? AND employee_id LIKE CONCAT(?, '%')");
                $stmt->execute([$enumRole, $prefix]);
                $row = $stmt->fetch();
                $next = (int)($row['max_seq'] ?? 0) + 1;
                return sprintf('%s%03d', $prefix, $next);
            };

            // Pre-generate IDs for roles that auto-derive username
            $pre_employee_id = null;
            if ($role === 'faculty' || $role === 'dean') {
                // Generate once here and reuse; also use as username
                // Minimal retry strategy to avoid collision with concurrent inserts
                $attemptsGen = 0;
                while (true) {
                    try {
                        $pre_employee_id = $generateEmployeeId($pdo, $role);
                        break;
                    } catch (Exception $eGen) {
                        if ($attemptsGen < 2) { $attemptsGen++; continue; }
                        throw $eGen;
                    }
                }
                $username = $pre_employee_id;
            }

            // Check if username already exists
            // Skip for students (set later), otherwise ensure uniqueness of provided/auto username
            if ($role !== 'student') {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    throw new Exception('Username already exists');
                }
            }
            
            // Additional role-based required fields
            if (in_array($role, ['faculty','dean'], true) && !$email) {
                throw new Exception('Email is required for Faculty and Dean');
            }

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Ensure employees table exists BEFORE starting the transaction (avoid implicit commits from DDL)
            // employees: unified registry for Faculty and Deans with auto-generated IDs (F-###, D-###)
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    employee_id VARCHAR(10) NOT NULL UNIQUE,
                    name VARCHAR(100) NOT NULL,
                    department VARCHAR(50) NOT NULL,
                    role ENUM('Faculty','Dean') NOT NULL,
                    subject_assigned VARCHAR(100) NULL,
                    email VARCHAR(100) NULL,
                    password VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX (role),
                    INDEX (department)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            } catch (PDOException $e) {
                // If this fails, add_user will error within the transaction and roll back
            }

            // Ensure mapping table exists BEFORE starting transaction (avoid implicit commits from DDL)
            if ($role === 'faculty') {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS faculty_subjects (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        faculty_user_id INT NOT NULL,
                        department VARCHAR(100),
                        subject_code VARCHAR(50),
                        subject_name VARCHAR(255),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (faculty_user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                } catch (PDOException $e) {
                    // Non-fatal: subject assignment will be skipped if table missing
                }
                // Also ensure the subjects table exists BEFORE starting the transaction to avoid implicit commits
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS subjects (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        department VARCHAR(100) NOT NULL,
                        code VARCHAR(50) NOT NULL,
                        name VARCHAR(255) NOT NULL,
                        UNIQUE KEY unique_subject (department, code)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                } catch (PDOException $e) {
                    // If this fails, subject validation/assignment will be skipped below
                }
            }

            // Ensure deans table exists BEFORE starting the transaction
            if ($role === 'dean') {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS deans (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        employee_id VARCHAR(20) UNIQUE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                } catch (PDOException $e) {
                    // Non-fatal; if this fails, add_user will error within the transaction and roll back
                }
            }

            // Ensure auxiliary tables/columns BEFORE starting the transaction
            // because MySQL DDL will implicitly commit and end the transaction
            if ($role === 'student') {
                try {
                    $pdo->exec("ALTER TABLE students ADD COLUMN gender ENUM('Male','Female') NULL AFTER user_id");
                } catch (PDOException $e2) { /* ignore if already exists */ }
            }

            // Note: $generateEmployeeId moved above to allow pre-generation before insert

            // Start transaction
            $pdo->beginTransaction();

            // If role is student, pre-generate Student ID to use as username
            $pre_student_id = null;
            if ($role === 'student') {
                // Helper (local) to generate next Student ID by gender will be re-used below; here we compute early
                $gender = sanitizeInput($_POST['gender'] ?? '');
                if (!in_array($gender, ['Male','Female'], true)) {
                    throw new Exception('Gender is required for students');
                }
                $prefix = ($gender === 'Male') ? '222' : '221';
                $stmt = $pdo->prepare("SELECT MAX(CAST(RIGHT(student_id, 3) AS UNSIGNED)) AS max_seq FROM students WHERE student_id LIKE CONCAT(?, '-%')");
                $stmt->execute([$prefix]);
                $row = $stmt->fetch();
                $next = (int)($row['max_seq'] ?? 0) + 1;
                $pre_student_id = sprintf('%s-%03d', $prefix, $next);
                // Override username with the pre-generated Student ID
                $username = $pre_student_id;
            }

            // Insert user
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, full_name, email, department) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hashed_password, $role, $full_name, $email, $department]);

            $user_id = $pdo->lastInsertId();
            
            // Insert role-specific data
            if ($role === 'faculty') {
                $position = sanitizeInput($_POST['position'] ?? '');
                $hire_date = $_POST['hire_date'] ?? null;

                // Use pre-generated Employee ID (also used as username)
                $employee_id = $pre_employee_id;
                $stmt = $pdo->prepare("INSERT INTO faculty (user_id, employee_id, position, hire_date) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $employee_id, $position, $hire_date]);

                // Store selected subjects (if any), but validate strictly against allowed list from DB
                $subjects = $_POST['subjects'] ?? [];
                if (!is_array($subjects) || count($subjects) === 0) {
                    throw new Exception('Please assign at least one subject for Faculty');
                }
                if (is_array($subjects) && !empty($subjects)) {
                    // Build allowed set from DB subjects for this department
                    $allowed = [];
                    $subStmt = $pdo->prepare("SELECT code, name FROM subjects WHERE department = ?");
                    $subStmt->execute([$department]);
                    foreach ($subStmt->fetchAll() as $row) {
                        $allowed[$row['code'] . '::' . $row['name']] = true;
                    }
                    // Validate every selected subject
                    foreach ($subjects as $sub) {
                        if (!is_string($sub) || $sub === '' || !isset($allowed[$sub])) {
                            throw new Exception('Invalid subject selection detected. Please choose from the available subjects list.');
                        }
                    }

                    // Insert after validation passes
                    $ins = $pdo->prepare("INSERT INTO faculty_subjects (faculty_user_id, department, subject_code, subject_name) VALUES (?, ?, ?, ?)");
                    foreach ($subjects as $sub) {
                        $parts = explode('::', $sub, 2);
                        $code = sanitizeInput($parts[0] ?? '');
                        $name = sanitizeInput($parts[1] ?? '');
                        $ins->execute([$user_id, $department, $code, $name]);
                    }
                }

                // Also register into employees table
                // Map department to code (SOT/SOB/SOE)
                $deptCodeMap = [
                    'Technology' => 'SOT',
                    'Business'   => 'SOB',
                    'Education'  => 'SOE',
                ];
                $deptCode = $deptCodeMap[$department] ?? $department;

                // Prepare subject_assigned as comma-separated subject codes
                $subjectAssigned = null;
                if (is_array($subjects) && !empty($subjects)) {
                    $codes = [];
                    foreach ($subjects as $sub) {
                        $parts = explode('::', (string)$sub, 2);
                        $codes[] = sanitizeInput($parts[0] ?? '');
                    }
                    $subjectAssigned = implode(',', array_filter($codes));
                    if ($subjectAssigned === '') { $subjectAssigned = null; }
                }

                // Duplicate prevention in employees (by email when provided, or by name+role+dept)
                $empRole = 'Faculty';
                if ($email) {
                    $chk = $pdo->prepare("SELECT 1 FROM employees WHERE email = ? LIMIT 1");
                    $chk->execute([$email]);
                    if ($chk->fetch()) {
                        throw new Exception('Duplicate email found in employees table');
                    }
                }
                $chk2 = $pdo->prepare("SELECT 1 FROM employees WHERE name = ? AND role = ? AND department = ? LIMIT 1");
                $chk2->execute([$full_name, $empRole, $deptCode]);
                if ($chk2->fetch()) {
                    throw new Exception('Duplicate employee (name/role/department) found in employees table');
                }

                // Insert into employees
                $empIns = $pdo->prepare("INSERT INTO employees (employee_id, name, department, role, subject_assigned, email, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $empIns->execute([$employee_id, $full_name, $deptCode, $empRole, $subjectAssigned, $email ?: null, $hashed_password]);
                
            } elseif ($role === 'dean') {
                // Use pre-generated Employee ID (also used as username)
                $employee_id = $pre_employee_id;
                $stmt = $pdo->prepare("INSERT INTO deans (user_id, employee_id) VALUES (?, ?)");
                $stmt->execute([$user_id, $employee_id]);

                // Also register into employees table
                $deptCodeMap = [
                    'Technology' => 'SOT',
                    'Business'   => 'SOB',
                    'Education'  => 'SOE',
                ];
                $deptCode = $deptCodeMap[$department] ?? $department;
                $empRole = 'Dean';

                // Duplicate prevention
                if ($email) {
                    $chk = $pdo->prepare("SELECT 1 FROM employees WHERE email = ? LIMIT 1");
                    $chk->execute([$email]);
                    if ($chk->fetch()) {
                        throw new Exception('Duplicate email found in employees table');
                    }
                }
                $chk2 = $pdo->prepare("SELECT 1 FROM employees WHERE name = ? AND role = ? AND department = ? LIMIT 1");
                $chk2->execute([$full_name, $empRole, $deptCode]);
                if ($chk2->fetch()) {
                    throw new Exception('Duplicate employee (name/role/department) found in employees table');
                }

                // Insert into employees (deans typically have no subjects assigned)
                $empIns = $pdo->prepare("INSERT INTO employees (employee_id, name, department, role, subject_assigned, email, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $empIns->execute([$employee_id, $full_name, $deptCode, $empRole, null, $email ?: null, $hashed_password]);

            } elseif ($role === 'student') {
                // Collect inputs
                $gender = sanitizeInput($_POST['gender'] ?? '');
                $year_level = sanitizeInput($_POST['year_level'] ?? '');
                $program = sanitizeInput($_POST['program'] ?? '');

                if (!in_array($gender, ['Male','Female'], true)) {
                    throw new Exception('Gender is required for students');
                }

                // Use the pre-generated Student ID as both student_id and the user's username
                $new_student_id = $pre_student_id ?: $username;

                // Insert student record
                $stmt = $pdo->prepare("INSERT INTO students (user_id, student_id, year_level, program, gender) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $new_student_id, $year_level, $program, $gender]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'User added successfully!'
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
    }
    
    elseif ($action === 'edit_user') {
        try {
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('Invalid user ID');
            }
            
            // Get and validate form data
            $full_name = sanitizeInput($_POST['full_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $department = sanitizeInput($_POST['department'] ?? '');
            // Normalize department values to canonical names used across the system
            $deptMap = [
                'School of Technology' => 'Technology',
                'School of Business' => 'Business',
                'School of Education' => 'Education',
                'SOT' => 'Technology',
                'SOB' => 'Business',
                'SOE' => 'Education',
            ];
            if (isset($deptMap[$department])) {
                $department = $deptMap[$department];
            }
            
            if (!$full_name || !$department) {
                throw new Exception('Full name and department are required');
            }

            // Validate full name: only ASCII letters and spaces; must contain at least one letter
            if (!preg_match('/^(?=.*[A-Za-z])[A-Za-z ]+$/', $full_name)) {
                throw new Exception('Full Name must only contain letters and spaces.');
            }
            
            // Get user's current role
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Update user basic info
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, department = ? WHERE id = ?");
            $stmt->execute([$full_name, $email, $department, $user_id]);
            
            // Update role-specific data
            if ($user['role'] === 'faculty') {
                $employee_id = sanitizeInput($_POST['employee_id'] ?? '');
                $position = sanitizeInput($_POST['position'] ?? '');
                $hire_date = $_POST['hire_date'] ?? null;
                // Normalize empty employee_id to NULL
                if ($employee_id === '') {
                    $employee_id = null;
                }

                // Validate unique employee_id for other users when provided
                if ($employee_id !== null) {
                    $check = $pdo->prepare("SELECT id FROM faculty WHERE employee_id = ? AND user_id <> ? LIMIT 1");
                    $check->execute([$employee_id, $user_id]);
                    if ($check->fetch()) {
                        throw new Exception('Employee ID already exists. Please use a unique Employee ID.');
                    }
                }

                $stmt = $pdo->prepare("UPDATE faculty SET employee_id = ?, position = ?, hire_date = ? WHERE user_id = ?");
                $stmt->execute([$employee_id, $position, $hire_date, $user_id]);
                
            } elseif ($user['role'] === 'student') {
                // Do not allow editing student_id here; keep it system-generated
                $year_level = sanitizeInput($_POST['year_level'] ?? '');
                $program = sanitizeInput($_POST['program'] ?? '');

                $stmt = $pdo->prepare("UPDATE students SET year_level = ?, program = ? WHERE user_id = ?");
                $stmt->execute([$year_level, $program, $user_id]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully!'
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
    }
    
    elseif ($action === 'get_user') {
        try {
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('Invalid user ID');
            }
            
            // Get user with role-specific data
            $stmt = $pdo->prepare("SELECT 
                                    u.id, u.username, u.full_name, u.email, u.department, u.role,
                                    f.employee_id, f.position, f.hire_date,
                                    s.student_id, s.year_level, s.program
                                   FROM users u
                                   LEFT JOIN faculty f ON u.id = f.user_id
                                   LEFT JOIN students s ON u.id = s.user_id
                                   WHERE u.id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            echo json_encode([
                'success' => true,
                'user' => $user
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    elseif ($action === 'delete_user') {
        try {
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('Invalid user ID');
            }
            
            // Prevent admin from deleting themselves
            if ($user_id == $_SESSION['user_id']) {
                throw new Exception('You cannot delete your own account');
            }
            
            // Delete user (cascade will handle related records)
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully!'
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    // Reset password to default '123' for Students, Faculty, and Deans
    elseif ($action === 'reset_password') {
        try {
            // Validate CSRF token
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            $target_user_id = (int)($_POST['user_id'] ?? 0);
            if (!$target_user_id) {
                throw new Exception('Invalid user ID');
            }

            // Load target user info
            $stmt = $pdo->prepare("SELECT id, username, full_name, role, department FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$target_user_id]);
            $target = $stmt->fetch();

            if (!$target) {
                throw new Exception('User not found');
            }

            // Only allow reset for Students, Faculty, and Deans (not Admins)
            if (!in_array($target['role'], ['student', 'faculty', 'dean'])) {
                throw new Exception('Password reset is only allowed for Students, Faculty, and Deans');
            }

            // Department scope enforcement
            $adminDept = $_SESSION['department'] ?? '';
            // Treat non-academic departments (e.g., IT Department) or empty as super-admin
            $isSuperAdmin = !in_array($adminDept, ['Technology', 'Education', 'Business']);
            if (!$isSuperAdmin) {
                if (strcasecmp($adminDept, (string)$target['department']) !== 0) {
                    throw new Exception('You can only reset passwords for users in your department');
                }
            }

            // Hash default password '123'
            $new_hash = password_hash('123', PASSWORD_DEFAULT);

            // Update password
            $upd = $pdo->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$new_hash, $target_user_id]);

            // Ensure audit_log table exists
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    actor_user_id INT NOT NULL,
                    actor_username VARCHAR(50) NOT NULL,
                    action VARCHAR(100) NOT NULL,
                    target_user_id INT NOT NULL,
                    target_username VARCHAR(50) NOT NULL,
                    target_role VARCHAR(20) NOT NULL,
                    details TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX (actor_user_id),
                    INDEX (target_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            } catch (PDOException $e) {
                // Non-fatal; continue without blocking the reset
            }

            // Write audit log
            try {
                $al = $pdo->prepare("INSERT INTO audit_log (actor_user_id, actor_username, action, target_user_id, target_username, target_role, details) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $al->execute([
                    $_SESSION['user_id'] ?? 0,
                    $_SESSION['username'] ?? 'unknown',
                    'password_reset',
                    $target['id'],
                    $target['username'],
                    $target['role'],
                    'Password reset to default (123)'
                ]);
            } catch (PDOException $e) {
                // Ignore audit failures
            }

            // Success response with standardized confirmation message
            echo json_encode([
                'success' => true,
                'message' => 'Password has been reset successfully! Default password is 123.'
            ]);

        } catch (Exception $e) {
            // Log error for debugging without exposing sensitive details
            error_log('[manage_users.php] reset_password failed: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Failed to reset password. Please try again or check the database connection.'
            ]);
        }
    }

    // List password reset requests for admin dashboard
    elseif ($action === 'list_reset_requests') {
        try {
            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(50) NOT NULL,
                status ENUM('Pending','Resolved') NOT NULL DEFAULT 'Pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL DEFAULT NULL,
                INDEX (identifier),
                INDEX (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $stmt = $pdo->prepare("SELECT id, identifier, status, created_at, resolved_at FROM password_reset_requests ORDER BY status='Pending' DESC, created_at DESC");
            $stmt->execute();
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to load requests']);
        }
    }

    // Reset a user's password to default (123) by Student ID or Employee ID
    elseif ($action === 'reset_by_identifier') {
        try {
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }
            $identifier = sanitizeInput($_POST['identifier'] ?? '');
            if ($identifier === '') {
                throw new Exception('Missing identifier');
            }
            // Try student
            $user = null;
            $q = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.role FROM users u INNER JOIN students s ON u.id = s.user_id WHERE s.student_id = ? LIMIT 1");
            $q->execute([$identifier]);
            $user = $q->fetch();
            if (!$user) {
                // Try faculty
                $q = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.role FROM users u INNER JOIN faculty f ON u.id = f.user_id WHERE f.employee_id = ? LIMIT 1");
                $q->execute([$identifier]);
                $user = $q->fetch();
            }
            if (!$user) {
                // Try dean
                $q = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.role FROM users u INNER JOIN deans d ON u.id = d.user_id WHERE d.employee_id = ? LIMIT 1");
                $q->execute([$identifier]);
                $user = $q->fetch();
            }
            if (!$user) {
                throw new Exception('No user found for identifier');
            }
            if (!in_array($user['role'], ['student','faculty','dean'], true)) {
                throw new Exception('Only Students, Faculty, and Deans can be reset via identifier');
            }
            $new_hash = password_hash('123', PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$new_hash, (int)$user['id']]);
            echo json_encode(['success' => true, 'message' => 'Password reset to default (123) for ' . ($user['full_name'] ?: $user['username'])]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // Mark a password reset request as Resolved
    elseif ($action === 'resolve_reset_request') {
        try {
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }
            $request_id = (int)($_POST['request_id'] ?? 0);
            if (!$request_id) {
                throw new Exception('Invalid request ID');
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(50) NOT NULL,
                status ENUM('Pending','Resolved') NOT NULL DEFAULT 'Pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL DEFAULT NULL,
                INDEX (identifier),
                INDEX (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $stmt = $pdo->prepare("UPDATE password_reset_requests SET status = 'Resolved', resolved_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$request_id]);
            echo json_encode(['success' => true, 'message' => 'Request marked as Resolved']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    exit();
}

// If not POST request, redirect to admin dashboard
header('Location: admin.php');
exit();
?>
