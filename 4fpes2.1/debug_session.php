<?php
session_start();
echo "<h2>Session Debug Information</h2>";
echo "<pre>";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . "\n";
echo "Session Data:\n";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Actions:</h3>";
echo '<a href="index.php">Go to Login</a><br>';
echo '<a href="dashboard.php">Go to Dashboard</a><br>';
echo '<a href="student/student.php">Go to Student Dashboard</a><br>';
echo '<form method="post" action="auth.php" style="display:inline;">
        <input type="hidden" name="action" value="logout">
        <button type="submit">Logout</button>
      </form>';
?>
