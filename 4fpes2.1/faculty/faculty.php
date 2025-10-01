<?php
require_once '../config.php';
requireRole('faculty');

// Get faculty info
$stmt = $pdo->prepare("SELECT f.*, u.full_name, u.department FROM faculty f 
                       JOIN users u ON f.user_id = u.id 
                       WHERE f.id = ?");
$stmt->execute([$_SESSION['faculty_id']]);
$faculty = $stmt->fetch();

// Get evaluations for this faculty (support both new and legacy schemas)
try {
    $stmt = $pdo->prepare("SELECT 
                               e.*, 
                               s.student_id, 
                               us.full_name AS student_name,
                               CASE 
                                   WHEN COALESCE(e.is_self, 0) = 1 THEN COALESCE(ue.full_name, 'Self Evaluation')
                                   ELSE 'Anonymous'
                               END AS evaluator_display
                           FROM evaluations e 
                           LEFT JOIN students s ON e.student_id = s.id 
                           LEFT JOIN users us ON s.user_id = us.id 
                           LEFT JOIN users ue ON e.evaluator_user_id = ue.id
                           WHERE e.faculty_id = ? AND e.status = 'submitted'
                           ORDER BY e.submitted_at DESC");
    $stmt->execute([$_SESSION['faculty_id']]);
    $evaluations = $stmt->fetchAll();
} catch (PDOException $ex) {
    // Legacy schema fallback (no is_self/evaluator_role/evaluator_user_id)
    $stmt = $pdo->prepare("SELECT 
                               e.*, 
                               s.student_id, 
                               us.full_name AS student_name,
                               CASE WHEN COALESCE(e.is_self, 0) = 1 THEN us.full_name ELSE 'Anonymous' END AS evaluator_display
                           FROM evaluations e 
                           LEFT JOIN students s ON e.student_id = s.id 
                           LEFT JOIN users us ON s.user_id = us.id 
                           WHERE e.faculty_id = ? AND e.status = 'submitted'
                           ORDER BY e.submitted_at DESC");
    $stmt->execute([$_SESSION['faculty_id']]);
    $evaluations = $stmt->fetchAll();
}

// Get evaluation statistics
$stmt = $pdo->prepare("SELECT 
                        COUNT(*) as total_evaluations,
                        AVG(overall_rating) as avg_rating,
                        MIN(overall_rating) as min_rating,
                        MAX(overall_rating) as max_rating
                       FROM evaluations 
                       WHERE faculty_id = ? AND status = 'submitted'");
$stmt->execute([$_SESSION['faculty_id']]);
$stats = $stmt->fetch();

// Get ratings by criteria
$stmt = $pdo->prepare("SELECT ec.category, ec.criterion, AVG(er.rating) as avg_rating, COUNT(er.rating) as count
                       FROM evaluation_responses er
                       JOIN evaluation_criteria ec ON er.criterion_id = ec.id
                       JOIN evaluations e ON er.evaluation_id = e.id
                       WHERE e.faculty_id = ? AND e.status = 'submitted'
                       GROUP BY ec.id, ec.category, ec.criterion
                       ORDER BY ec.category, ec.criterion");
$stmt->execute([$_SESSION['faculty_id']]);
$criteria_ratings = $stmt->fetchAll();

// Group criteria ratings by category
$grouped_ratings = [];
foreach ($criteria_ratings as $rating) {
    $grouped_ratings[$rating['category']][] = $rating;
}

// Get semester-wise performance
$stmt = $pdo->prepare("SELECT semester, academic_year, AVG(overall_rating) as avg_rating, COUNT(*) as count
                       FROM evaluations 
                       WHERE faculty_id = ? AND status = 'submitted'
                       GROUP BY semester, academic_year
                       ORDER BY academic_year DESC, semester");
$stmt->execute([$_SESSION['faculty_id']]);
$semester_performance = $stmt->fetchAll();

// Get all faculty for directory
$stmt = $pdo->prepare("SELECT f.id, u.full_name, u.department, f.position, f.employee_id, f.hire_date,
                       COUNT(e.id) as evaluation_count, AVG(e.overall_rating) as avg_rating
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       LEFT JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted'
                       WHERE f.id != ?
                       GROUP BY f.id, u.full_name, u.department, f.position, f.employee_id, f.hire_date
                       ORDER BY u.full_name");
$stmt->execute([$_SESSION['faculty_id']]);
$all_faculty = $stmt->fetchAll();

// Get assigned subjects for this faculty (by users.id referenced from faculty.user_id)
$subjects = [];
try {
    $subStmt = $pdo->prepare("SELECT fs.subject_code, fs.subject_name
                               FROM faculty_subjects fs
                               JOIN faculty f ON fs.faculty_user_id = f.user_id
                               WHERE f.id = ?
                               ORDER BY fs.subject_name");
    $subStmt->execute([$_SESSION['faculty_id']]);
    $subjects = $subStmt->fetchAll();
} catch (PDOException $e) {
    $subjects = [];
}

// Get students assigned to this faculty by student_faculty_subjects
$assigned_students = [];
try {
    $assignStmt = $pdo->prepare("SELECT sus.student_user_id, su.full_name AS student_name, s.student_id,
                                        sfs.subject_code, sfs.subject_name
                                 FROM faculty f
                                 JOIN student_faculty_subjects sfs ON sfs.faculty_user_id = f.user_id
                                 JOIN users su ON su.id = sfs.student_user_id
                                 LEFT JOIN students s ON s.user_id = su.id
                                 WHERE f.id = ?
                                 ORDER BY su.full_name, sfs.subject_name");
    $assignStmt->execute([$_SESSION['faculty_id']]);
    $assigned_students = $assignStmt->fetchAll();
} catch (PDOException $e) {
    $assigned_students = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard - Performance Evaluation</title>
    <link rel="stylesheet" href="../styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--gray-color);
            font-size: 0.9rem;
        }
        
        .chart-container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }
        
        .evaluations-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .evaluations-table th, .evaluations-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e1e5e9;
        }
        
        .evaluations-table th {
            background: var(--bg-color);
            font-weight: 600;
        }
        
        .rating-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .rating-excellent { background: var(--secondary-color); color: white; }
        .rating-good { background: var(--primary-color); color: white; }
        .rating-average { background: var(--warning-color); color: white; }
        .rating-poor { background: var(--danger-color); color: white; }
        
        .criteria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .criteria-category {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }
        
        .criteria-category h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .criterion-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .criterion-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <h2>Faculty Portal</h2>
            <a href="#" onclick="showSection('overview')">Overview</a>
            <a href="#" onclick="showSection('evaluations')">My Evaluations</a>
            <a href="#" onclick="showSection('analytics')">Performance Analytics</a>
            <a href="#" onclick="showSection('directory')">Faculty Directory</a>
            <a href="#" onclick="showSection('profile')">Profile</a>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>

        <div class="main-content">
            <div class="welcome-header">
                <h1>Welcome, <?php echo htmlspecialchars($faculty['full_name']); ?>!</h1>
                <p>Employee ID: <?php echo htmlspecialchars($faculty['employee_id']); ?> | Position: <?php echo htmlspecialchars($faculty['position']); ?></p>
            </div>

            <!-- Overview Section -->
            <div id="overview-section" class="content-section">
                <h2>Performance Overview</h2>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['total_evaluations'] ?? 0; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['avg_rating'] ? number_format($stats['avg_rating'], 2) : 'N/A'; ?></div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['max_rating'] ?? 'N/A'; ?></div>
                        <div class="stat-label">Highest Rating</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['min_rating'] ?? 'N/A'; ?></div>
                        <div class="stat-label">Lowest Rating</div>
                    </div>
                </div>

                <?php if (!empty($semester_performance)): ?>
                <div class="chart-container">
                    <h3>Semester Performance Trend</h3>
                    <canvas id="performanceChart" width="400" height="200"></canvas>
                </div>
                <?php endif; ?>

                <?php if (!empty($grouped_ratings)): ?>
                <div class="criteria-grid">
                    <?php foreach ($grouped_ratings as $category => $ratings): ?>
                        <div class="criteria-category">
                            <h4><?php echo htmlspecialchars($category); ?></h4>
                            <?php foreach ($ratings as $rating): ?>
                                <div class="criterion-item">
                                    <span><?php echo htmlspecialchars($rating['criterion']); ?></span>
                                    <span class="rating-badge rating-<?php 
                                        $avg = $rating['avg_rating'];
                                        if ($avg >= 4.5) echo 'excellent';
                                        elseif ($avg >= 3.5) echo 'good';
                                        elseif ($avg >= 2.5) echo 'average';
                                        else echo 'poor';
                                    ?>">
                                        <?php echo number_format($rating['avg_rating'], 2); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Assigned Students Section -->
            <div id="assigned-students-section" class="content-section">
                <h2>My Assigned Students & Subjects</h2>
                <?php if (empty($assigned_students)): ?>
                    <p>No students assigned yet.</p>
                <?php else: ?>
                    <?php
                    // Group by student
                    $by_student = [];
                    foreach ($assigned_students as $row) {
                        $sid = $row['student_user_id'];
                        if (!isset($by_student[$sid])) {
                            $by_student[$sid] = [
                                'name' => $row['student_name'],
                                'student_id' => $row['student_id'] ?? null,
                                'subjects' => []
                            ];
                        }
                        $by_student[$sid]['subjects'][] = [
                            'code' => $row['subject_code'],
                            'name' => $row['subject_name']
                        ];
                    }
                    ?>
                    <table class="evaluations-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Subjects</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($by_student as $stu): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($stu['name']); ?></td>
                                    <td><?php echo htmlspecialchars($stu['student_id'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php foreach ($stu['subjects'] as $s): ?>
                                            <span style="display:inline-block; background:#eef2f7; color:#334155; padding:4px 10px; border-radius:999px; margin:2px; font-size:12px; border:1px solid #e5e7eb;">
                                                <?php echo htmlspecialchars(($s['code'] ? $s['code'].' - ' : '').$s['name']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Evaluations Section -->
            <div id="evaluations-section" class="content-section" style="display: none;">
                <h2>My Evaluations</h2>
                
                <?php if (empty($evaluations)): ?>
                    <p>No evaluations received yet.</p>
                <?php else: ?>
                    <table class="evaluations-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Semester</th>
                                <th>Academic Year</th>
                                <th>Overall Rating</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($evaluations as $eval): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($eval['subject']); ?></td>
                                    <td><?php echo htmlspecialchars($eval['semester']); ?></td>
                                    <td><?php echo htmlspecialchars($eval['academic_year']); ?></td>
                                    <td>
                                        <span class="rating-badge rating-<?php 
                                            $rating = $eval['overall_rating'];
                                            if ($rating >= 4.5) echo 'excellent';
                                            elseif ($rating >= 3.5) echo 'good';
                                            elseif ($rating >= 2.5) echo 'average';
                                            else echo 'poor';
                                        ?>">
                                            <?php echo number_format($eval['overall_rating'], 2); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($eval['submitted_at'])); ?></td>
                                    <td>
                                        <button onclick="viewEvaluation(<?php echo $eval['id']; ?>)" class="btn-small">View Details</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Analytics Section -->
            <div id="analytics-section" class="content-section" style="display: none;">
                <h2>Performance Analytics</h2>
                
                <?php if (!empty($criteria_ratings)): ?>
                <div class="chart-container">
                    <h3>Performance by Criteria</h3>
                    <canvas id="criteriaChart" width="400" height="200"></canvas>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($semester_performance)): ?>
                <div class="chart-container">
                    <h3>Semester Performance Details</h3>
                    <table class="evaluations-table">
                        <thead>
                            <tr>
                                <th>Academic Year</th>
                                <th>Semester</th>
                                <th>Average Rating</th>
                                <th>Number of Evaluations</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($semester_performance as $perf): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($perf['academic_year']); ?></td>
                                    <td><?php echo htmlspecialchars($perf['semester']); ?></td>
                                    <td><?php echo number_format($perf['avg_rating'], 2); ?></td>
                                    <td><?php echo $perf['count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Profile Section -->
            <div id="profile-section" class="content-section" style="display: none;">
                <h2>My Profile</h2>
                <div class="profile-info">
                    <div class="info-group">
                        <label>Full Name:</label>
                        <span><?php echo htmlspecialchars($faculty['full_name']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Employee ID:</label>
                        <span><?php echo htmlspecialchars($faculty['employee_id']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Position:</label>
                        <span><?php echo htmlspecialchars($faculty['position']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Department:</label>
                        <span><?php echo htmlspecialchars($faculty['department']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Hire Date:</label>
                        <span><?php echo $faculty['hire_date'] ? date('M j, Y', strtotime($faculty['hire_date'])) : 'N/A'; ?></span>
                    </div>
                    <div class="info-group">
                        <label>Assigned Subjects:</label>
                        <span>
                            <?php if (empty($subjects)): ?>
                                <em>No subjects assigned</em>
                            <?php else: ?>
                                <?php foreach ($subjects as $s): ?>
                                    <span style="display:inline-block; background:#eef2f7; color:#334155; padding:4px 10px; border-radius:999px; margin:2px; font-size:12px; border:1px solid #e5e7eb;">
                                        <?php echo htmlspecialchars(($s['subject_code'] ? $s['subject_code'] . ' - ' : '') . $s['subject_name']); ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="profile-info" style="margin-top:1rem;">
                    <h3 style="margin:0 0 .5rem;">Edit Password</h3>
                    <form id="faculty-change-password-form" class="forgot-card" onsubmit="return false;">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required />
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required />
                            <div class="hint" style="color:#6b7280; font-size:.9rem;">At least 8 characters, include letters and numbers.</div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required />
                        </div>
                        <div id="fac-pw-msg" class="error-message" style="display:none;"></div>
                        <div id="fac-pw-success" class="success-message" style="display:none; color:#166534; background:#dcfce7; padding:.75rem; border-radius:10px;">Password changed successfully.</div>
                        <button type="submit" class="btn-primary">Save Password</button>
                    </form>
                </div>
            </div>

            <!-- Faculty Directory Section -->
            <div id="directory-section" class="content-section" style="display: none;">
                <h2>Faculty Directory</h2>
                
                <div class="search-container">
                    <input type="text" id="faculty_directory_search" placeholder="Search faculty by name, department, or position..." onkeyup="filterFacultyDirectory()">
                </div>
                
                <div class="faculty-directory">
                    <table class="performance-table" id="faculty_directory_table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Employee ID</th>
                                <th>Hire Date</th>
                                <th>Evaluations</th>
                                <th>Avg Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_faculty as $colleague): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($colleague['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($colleague['department']); ?></td>
                                    <td><?php echo htmlspecialchars($colleague['position']); ?></td>
                                    <td><?php echo htmlspecialchars($colleague['employee_id']); ?></td>
                                    <td><?php echo $colleague['hire_date'] ? date('M j, Y', strtotime($colleague['hire_date'])) : 'N/A'; ?></td>
                                    <td><?php echo $colleague['evaluation_count']; ?></td>
                                    <td>
                                        <?php if ($colleague['avg_rating']): ?>
                                            <span class="rating-badge">
                                                <?php echo number_format($colleague['avg_rating'], 2); ?>/5.0
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--secondary-color);">No evaluations</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Faculty directory search functionality
        function filterFacultyDirectory() {
            const searchInput = document.getElementById('faculty_directory_search');
            const table = document.getElementById('faculty_directory_table');
            const searchTerm = searchInput.value.toLowerCase();
            
            if (!table) return;
            
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let shouldShow = false;
                
                // Search in name, department, and position columns (0, 1, 2)
                for (let j = 0; j < 3; j++) {
                    if (cells[j] && cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        shouldShow = true;
                        break;
                    }
                }
                
                rows[i].style.display = shouldShow ? '' : 'none';
            }
        }

        // Navigation functions
        function showSection(sectionName) {
            const sections = document.querySelectorAll('.content-section');
            sections.forEach(section => {
                section.style.display = 'none';
            });
            
            const targetSection = document.getElementById(sectionName + '-section');
            if (targetSection) {
                targetSection.style.display = 'block';
            }
        }

        // Logout function
        function logout() {
            fetch('../auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=logout'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                window.location.href = '../index.php';
            });
        }

        // View evaluation details
        function viewEvaluation(evaluationId) {
            // Redirect to the evaluation details page
            if (!evaluationId || isNaN(evaluationId)) return;
            window.location.href = 'evaluation_details.php?id=' + encodeURIComponent(evaluationId);
        }

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Performance trend chart
            <?php if (!empty($semester_performance)): ?>
            const performanceCtx = document.getElementById('performanceChart');
            if (performanceCtx) {
                new Chart(performanceCtx, {
                    type: 'line',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($p) { return "'" . $p['semester'] . " " . $p['academic_year'] . "'"; }, $semester_performance)); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_column($semester_performance, 'avg_rating')); ?>],
                            borderColor: 'rgb(52, 152, 219)',
                            backgroundColor: 'rgba(52, 152, 219, 0.1)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Criteria performance chart
            <?php if (!empty($criteria_ratings)): ?>
            const criteriaCtx = document.getElementById('criteriaChart');
            if (criteriaCtx) {
                new Chart(criteriaCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($r) { return "'" . addslashes($r['criterion']) . "'"; }, $criteria_ratings)); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_column($criteria_ratings, 'avg_rating')); ?>],
                            backgroundColor: 'rgba(46, 204, 113, 0.8)',
                            borderColor: 'rgb(46, 204, 113)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Show overview section by default
            showSection('overview');
        });

        // Password change handler (Faculty)
        (function(){
            const form = document.getElementById('faculty-change-password-form');
            if (!form) return;
            const err = document.getElementById('fac-pw-msg');
            const ok = document.getElementById('fac-pw-success');
            form.addEventListener('submit', function(){
                err.style.display = 'none'; ok.style.display = 'none';
                const fd = new FormData(form);
                const np = fd.get('new_password')+''; const cp = fd.get('confirm_password')+'';
                if (np.length < 8 || !/[A-Za-z]/.test(np) || !/\d/.test(np)) {
                    err.textContent = 'Password must be at least 8 characters and include letters and numbers.';
                    err.style.display = 'block';
                    return;
                }
                if (np !== cp) {
                    err.textContent = 'New Password and Confirm New Password do not match.';
                    err.style.display = 'block';
                    return;
                }
                fetch('../api/change_password.php', { method:'POST', body: fd })
                    .then(r=>r.json())
                    .then(data=>{
                        if (data.success) {
                            ok.textContent = data.message || 'Password changed successfully. Logging out...';
                            ok.style.display = 'block';
                            setTimeout(()=>{ window.location.href = data.redirect || '../index.php'; }, 1200);
                        } else {
                            err.textContent = data.message || 'Unable to change password.';
                            err.style.display = 'block';
                        }
                    })
                    .catch(()=>{ err.textContent = 'Network error. Please try again.'; err.style.display='block'; });
            });
        })();
    </script>
</body>
</html>
