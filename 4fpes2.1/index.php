<?php
require_once 'config.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faculty Performance Evaluation System - Login</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="container" id="login-container">
    <h1 class="app-title">Faculty Performance Evaluation System</h1>
    <div class="login-form" role="region" aria-labelledby="login-title">
      <!-- Institution Logo -->
      <div class="login-logo-wrap">
        <img src="img/loginlogo.png" alt="Institution Logo" class="login-logo" />
      </div>
      <h2 id="login-title" class="login-title">Login</h2>
      <hr class="divider" aria-hidden="true" />
      <div id="login-error" class="error-message"></div>

      <form id="login-form">
        <!-- Role Selection -->
        <div class="form-group">
          <label for="role">Role:</label>
          <select id="role" name="role" required>
            <option value="">-- Select Role --</option>
            <option value="student">Student</option>
            <option value="faculty">Faculty</option>
            <option value="dean">Dean</option>
            <option value="department_admin">Department Admin</option>
            <option value="admin">System Admin</option>
          </select>
        </div>

        <div class="form-group">
          <label for="username">Username/ID</label>
          <input type="text" id="username" name="username" placeholder="Enter your username or ID" autocomplete="username" required>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>

        <button type="submit" id="login-btn" class="btn-primary btn-full">Login</button>
      </form>
      
      <div style="margin-top: 0.75rem;">
        <a href="forgot_password_report.php" style="font-size: 0.9rem;">Forgot Password</a>
      </div>
      
      
  </div>
  
  <style>
    .login-logo-wrap {
      display: flex;
      justify-content: center;
      align-items: center;
      margin-bottom: 1rem;
    }
    .login-logo {
      width: 100px;
    }
    .login-form {
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .form-group label {
      display: none;
    }
    .form-group input {
      width: 100%;
    }
    .btn-primary {
      width: 100%;
    }
  </style>
  
  <script src="script.js"></script>
  
</body>
</html>