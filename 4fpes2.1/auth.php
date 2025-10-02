<?php
require_once 'config.php';

$action = '';

// Check for both POST and GET logout requests
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $action = 'logout';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
}

if ($action === 'login') {
    // For students, this field will contain the Student ID
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = sanitizeInput($_POST['role'] ?? '');
    
    if (empty($username) || empty($password) || empty($role)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit();
    }
    
    try {
        // First try database authentication
        if ($role === 'student') {
            // Match by Student ID for students
            $stmt = $pdo->prepare("SELECT u.*, f.id as faculty_id, s.id as student_id 
                                   FROM users u 
                                   LEFT JOIN faculty f ON u.id = f.user_id 
                                   LEFT JOIN students s ON u.id = s.user_id 
                                   WHERE s.student_id = ? AND u.role = ?");
            $stmt->execute([$username, $role]);
        } elseif ($role === 'faculty') {
            // Employees (Faculty): match by Employee ID
            $stmt = $pdo->prepare("SELECT u.*, f.id as faculty_id, s.id as student_id 
                                   FROM users u 
                                   INNER JOIN faculty f ON u.id = f.user_id 
                                   LEFT JOIN students s ON u.id = s.user_id 
                                   WHERE f.employee_id = ? AND u.role = ?");
            $stmt->execute([$username, $role]);
        } elseif ($role === 'dean') {
            // Employees (Deans): match by Employee ID in deans table
            $stmt = $pdo->prepare("SELECT u.*, f.id as faculty_id, s.id as student_id 
                                   FROM users u 
                                   LEFT JOIN faculty f ON u.id = f.user_id 
                                   LEFT JOIN students s ON u.id = s.user_id 
                                   INNER JOIN deans d ON u.id = d.user_id 
                                   WHERE d.employee_id = ? AND u.role = ?");
            $stmt->execute([$username, $role]);
        } else {
            // Admins keep using username
            $stmt = $pdo->prepare("SELECT u.*, f.id as faculty_id, s.id as student_id 
                                   FROM users u 
                                   LEFT JOIN faculty f ON u.id = f.user_id 
                                   LEFT JOIN students s ON u.id = s.user_id 
                                   WHERE u.username = ? AND u.role = ?");
            $stmt->execute([$username, $role]);
        }
        $user = $stmt->fetch();
        
        $authenticated = false;
        
        if ($user && password_verify($password, $user['password'])) {
            $authenticated = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['department'] = $user['department'];
            
            // Store role-specific IDs
            if ($user['role'] === 'faculty') {
                $_SESSION['faculty_id'] = $user['faculty_id'];
            } elseif ($user['role'] === 'student') {
                $_SESSION['student_id'] = $user['student_id'];
            }
        } else {
            // Fallback to JSON authentication for development
            $users_json = file_get_contents('users.json');
            $users = json_decode($users_json, true);
            
            foreach ($users as $json_user) {
                // Handle both 'admin' and 'department_admin' role selections for department admins
                $user_role_match = ($json_user['role'] === $role) || 
                                  ($role === 'department_admin' && $json_user['role'] === 'admin' && 
                                   in_array($json_user['department'] ?? '', ['Technology', 'Education', 'Business']));
                
                // For students in JSON fallback, treat username as their student identifier
                $json_identifier = $json_user['username'];
                if ($json_identifier === $username && 
                    $user_role_match && 
                    $json_user['password'] === $password) {
                    
                    $authenticated = true;
                    $_SESSION['user_id'] = 999; // Temporary ID for JSON users
                    $_SESSION['username'] = $json_user['username'];
                    $_SESSION['role'] = $json_user['role'];
                    $_SESSION['full_name'] = $json_user['username']; // Use username as fallback
                    $_SESSION['department'] = $json_user['department'] ?? '';
                    break;
                }
            }
        }
        
        if ($authenticated) {
            // Track if user is required to change password
            $_SESSION['must_change_password'] = isset($user['must_change_password']) ? (int)$user['must_change_password'] : 0;

            $redirect = 'dashboard.php';
            if (!empty($_SESSION['must_change_password'])) {
                $redirect = 'force_change_password.php';
            }
            echo json_encode([
                'success' => true, 
                'message' => 'Login successful',
                'redirect' => $redirect
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit();
}

if ($action === 'logout') {
    // Fully clear session
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, 
            $params['path'], 
            $params['domain'], 
            $params['secure'], 
            $params['httponly']
        );
    }
    session_destroy();
    
    // Handle AJAX and non-AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        // AJAX request - send JSON response
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'redirect' => '/4fpes2.1/index.php']);
    } else {
        // Regular request - redirect directly
        header('Location: /4fpes2.1/index.php');
    }
    exit();
}

// If not POST request, redirect to login (absolute path)
header('Location: /4fpes2.1/index.php');
exit();
?>
