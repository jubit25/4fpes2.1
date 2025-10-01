<?php
// Enable error display during debugging (set to 0 for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// Best-effort: ensure the password_reset_requests table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(50) NOT NULL,
        role ENUM('Student','Faculty','Dean') NOT NULL,
        status ENUM('Pending','Resolved') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    // Log but do not expose details to user
    if (function_exists('error_log')) {
        @error_log('forgot_password_report: table ensure failed - ' . $e->getMessage());
    }
}

// If logged in, still allow reporting (no redirect)

$message = '';
$error = '';
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $identifier = sanitizeInput($_POST['identifier'] ?? '');
        $role = sanitizeInput($_POST['role'] ?? '');
        //
        // Normalize role to expected ENUM case: Student, Faculty, Dean
        $role_map = [
            'student' => 'Student',
            'faculty' => 'Faculty',
            'dean' => 'Dean',
            'Student' => 'Student',
            'Faculty' => 'Faculty',
            'Dean' => 'Dean',
        ];
        $role_enum = $role_map[$role] ?? '';

        if (empty($identifier) || empty($role_enum)) {
            $error = 'Identifier and Role are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO password_reset_requests (identifier, role) VALUES (?, ?)");
                $stmt->execute([$identifier, $role_enum]);
                $message = 'Your password reset request has been submitted to the System Admin.';
            } catch (PDOException $e) {
                if (function_exists('error_log')) {
                    @error_log('forgot_password_report: insert failed - ' . $e->getMessage());
                }
                $error = 'Failed to submit request. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Forgot Password</title>
  <link rel="stylesheet" href="styles.css" />
  <style>
    /* Page-specific styles */
    body { background:#f8fafc; color:#111; }
    .forgot-wrapper { max-width: 760px; margin: 2.5rem auto; padding: 0 1rem; }
    .forgot-hero { display:flex; align-items:center; justify-content:center; margin-bottom: 1rem; position: relative; height: 110px; }
    /* Blue badge behind */
    .badge-gear { position:absolute; width: 120px; height: 120px; background:#60a5fa; border-radius: 50%; filter: drop-shadow(0 6px 18px rgba(0,0,0,.12)); display:flex; align-items:center; justify-content:center; }
    .badge-gear::before { content:''; width: 86px; height:86px; background:#ffffff; border-radius:50%; box-shadow: inset 0 0 0 6px #60a5fa; }
    /* Pink ribbon */
    .ribbon { position:relative; background:#f472b6; color:#fff; padding: 14px 26px; border-radius: 999px; font: 700 1.05rem/1.1 "Inter", system-ui, Arial; letter-spacing: .3px; text-transform: uppercase; box-shadow: 0 10px 24px rgba(244,114,182,.25); }
    .ribbon::before, .ribbon::after { content:''; position:absolute; top:0; bottom:0; width:26px; background:#f472b6; }
    .ribbon::before { left:-18px; border-top-left-radius:999px; border-bottom-left-radius:999px; }
    .ribbon::after  { right:-18px; border-top-right-radius:999px; border-bottom-right-radius:999px; }
    .forgot-card { background:#fff; border-radius:14px; box-shadow: 0 10px 28px rgba(0,0,0,0.10); padding: 22px; }
    .forgot-card h2 { margin: 0 0 10px 0; font-weight:700; }
    .forgot-card .form-group { margin-bottom: 1rem; }
    .forgot-card label { display:block; margin-bottom: .4rem; font-weight:600; color:#1f2937; }
    .forgot-card input[type="text"], .forgot-card select { width:100%; padding: 10px 12px; border: 2px solid #e1e5e9; border-radius: 10px; transition: border-color .2s ease, box-shadow .2s ease; }
    .forgot-card input:focus, .forgot-card select:focus { outline:none; border-color:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.15); }
    .actions { display:flex; align-items:center; gap:.75rem; }
    .btn-outline-primary { display:inline-block; border:2px solid #10b981; color:#0f5132; background:transparent; padding:.65rem 1rem; border-radius:10px; font-weight:700; transition: all .2s ease; }
    .btn-outline-primary:hover { background:rgba(16,185,129,.08); box-shadow:0 6px 14px rgba(16,185,129,.2); transform: translateY(-1px); }
    .link-muted { color:#475569; text-decoration:none; }
    .link-muted:hover { text-decoration:underline; }
  </style>
</head>
<body>
  <div class="forgot-wrapper">
    <div class="forgot-hero" aria-hidden="true">
      <div class="badge-gear"></div>
      <div class="ribbon">Forgot Password</div>
    </div>
    <div class="forgot-card">
    <h2 style="display:none;">Forgot Password</h2>
    <p style="color:#4b5563;">Enter your Student ID or Employee ID and select your role. The System Admin will reset your password and notify you.</p>

    <?php if (!empty($message)): ?>
      <div class="success-message" style="display:block;"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="error-message" style="display:block;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form class="forgot-card" method="POST" action="">
      <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>" />
      <div class="form-group">
        <label for="identifier">Student ID / Employee ID</label>
        <input type="text" id="identifier" name="identifier" placeholder="e.g., STU001 or FAC001" required />
      </div>
      <div class="form-group">
        <label for="role">Role</label>
        <select id="role" name="role" required>
          <option value="">-- Select Role --</option>
          <option value="Student">Student</option>
          <option value="Faculty">Faculty</option>
          <option value="Dean">Dean</option>
        </select>
      </div>
      <div class="actions">
        <button type="submit" class="btn-outline-primary">Submit Request</button>
        <a class="link-muted" href="index.php">Back to Login</a>
      </div>
    </form>
    </div>
  </div>
</body>
</html>
