<?php
require_once '../../config.php';
requireRole('admin');

// Get faculty performance data
try {
    // Get faculty with their evaluation statistics
    $stmt = $pdo->prepare("SELECT 
                            u.id, u.full_name, u.department,
                            f.employee_id, f.position,
                            COUNT(e.id) as evaluation_count,
                            AVG(e.overall_rating) as avg_rating,
                            MAX(e.created_at) as last_evaluation,
                            MIN(e.created_at) as first_evaluation
                           FROM users u
                           JOIN faculty f ON u.id = f.user_id
                           LEFT JOIN evaluations e ON u.id = e.faculty_id AND e.status = 'submitted'
                           WHERE u.role = 'faculty'
                           GROUP BY u.id, u.full_name, u.department, f.employee_id, f.position
                           ORDER BY u.department, avg_rating DESC");
    $stmt->execute();
    $faculty_performance = $stmt->fetchAll();

    // Get top performers
    $stmt = $pdo->prepare("SELECT 
                            u.full_name, u.department,
                            AVG(e.overall_rating) as avg_rating,
                            COUNT(e.id) as evaluation_count
                           FROM users u
                           JOIN evaluations e ON u.id = e.faculty_id
                           WHERE u.role = 'faculty' AND e.status = 'submitted'
                           GROUP BY u.id, u.full_name, u.department
                           HAVING COUNT(e.id) >= 3
                           ORDER BY avg_rating DESC
                           LIMIT 10");
    $stmt->execute();
    $top_performers = $stmt->fetchAll();

    // Get performance trends by department
    $stmt = $pdo->prepare("SELECT 
                            u.department,
                            DATE_FORMAT(e.created_at, '%Y-%m') as month,
                            AVG(e.overall_rating) as avg_rating,
                            COUNT(e.id) as evaluation_count
                           FROM evaluations e
                           JOIN users u ON e.faculty_id = u.id
                           WHERE e.status = 'submitted' AND e.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                           GROUP BY u.department, DATE_FORMAT(e.created_at, '%Y-%m')
                           ORDER BY u.department, month DESC");
    $stmt->execute();
    $performance_trends = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Performance Report - Faculty Performance Evaluation System</title>
    <link rel="stylesheet" href="../../styles.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .report-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .report-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #fd7e14;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h3 {
            color: #fd7e14;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .rating {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .rating-excellent { background: #d4edda; color: #155724; }
        .rating-good { background: #d1ecf1; color: #0c5460; }
        .rating-average { background: #fff3cd; color: #856404; }
        .rating-poor { background: #f8d7da; color: #721c24; }
        .print-btn {
            background: #fd7e14;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #fd7e14;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #fd7e14;
        }
        @media print {
            .print-btn { display: none; }
            body { margin: 0; background: white; }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <button class="print-btn" onclick="window.print()">Print Report</button>
        
        <div class="report-header">
            <h1>Faculty Performance Report</h1>
            <p>Faculty Performance Evaluation System</p>
            <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <!-- Top Performers -->
            <?php if (!empty($top_performers)): ?>
            <div class="section">
                <h3>Top Performing Faculty (Minimum 3 Evaluations)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Faculty Name</th>
                            <th>Department</th>
                            <th>Average Rating</th>
                            <th>Total Evaluations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_performers as $index => $performer): 
                            $rating = $performer['avg_rating'];
                            $rating_class = '';
                            if ($rating >= 4.5) $rating_class = 'rating-excellent';
                            elseif ($rating >= 4.0) $rating_class = 'rating-good';
                            elseif ($rating >= 3.0) $rating_class = 'rating-average';
                            else $rating_class = 'rating-poor';
                        ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($performer['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($performer['department']); ?></td>
                                <td>
                                    <span class="rating <?php echo $rating_class; ?>">
                                        <?php echo number_format($rating, 2); ?>
                                    </span>
                                </td>
                                <td><?php echo $performer['evaluation_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- All Faculty Performance -->
            <div class="section">
                <h3>All Faculty Performance Summary</h3>
                <?php if (empty($faculty_performance)): ?>
                    <p>No faculty performance data available.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Faculty Name</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Evaluations</th>
                                <th>Average Rating</th>
                                <th>First Evaluation</th>
                                <th>Last Evaluation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($faculty_performance as $faculty): 
                                $rating = $faculty['avg_rating'];
                                $rating_class = '';
                                if ($rating >= 4.5) $rating_class = 'rating-excellent';
                                elseif ($rating >= 4.0) $rating_class = 'rating-good';
                                elseif ($rating >= 3.0) $rating_class = 'rating-average';
                                elseif ($rating > 0) $rating_class = 'rating-poor';
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($faculty['employee_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($faculty['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($faculty['department']); ?></td>
                                    <td><?php echo htmlspecialchars($faculty['position'] ?? 'N/A'); ?></td>
                                    <td><?php echo $faculty['evaluation_count']; ?></td>
                                    <td>
                                        <?php if ($faculty['avg_rating']): ?>
                                            <span class="rating <?php echo $rating_class; ?>">
                                                <?php echo number_format($faculty['avg_rating'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #666;">No evaluations</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $faculty['first_evaluation'] ? date('M j, Y', strtotime($faculty['first_evaluation'])) : 'N/A'; ?></td>
                                    <td><?php echo $faculty['last_evaluation'] ? date('M j, Y', strtotime($faculty['last_evaluation'])) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Performance Trends -->
            <?php if (!empty($performance_trends)): ?>
            <div class="section">
                <h3>Performance Trends by Department (Last 12 Months)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Month</th>
                            <th>Average Rating</th>
                            <th>Evaluations Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $current_dept = '';
                        foreach ($performance_trends as $trend): 
                            $show_dept = $trend['department'] !== $current_dept;
                            $current_dept = $trend['department'];
                            
                            $rating = $trend['avg_rating'];
                            $rating_class = '';
                            if ($rating >= 4.5) $rating_class = 'rating-excellent';
                            elseif ($rating >= 4.0) $rating_class = 'rating-good';
                            elseif ($rating >= 3.0) $rating_class = 'rating-average';
                            else $rating_class = 'rating-poor';
                        ?>
                            <tr>
                                <td><?php echo $show_dept ? htmlspecialchars($trend['department']) : ''; ?></td>
                                <td><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></td>
                                <td>
                                    <span class="rating <?php echo $rating_class; ?>">
                                        <?php echo number_format($trend['avg_rating'], 2); ?>
                                    </span>
                                </td>
                                <td><?php echo $trend['evaluation_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Performance Summary -->
            <div class="section">
                <h3>Performance Summary</h3>
                <div class="stats-grid">
                    <?php
                    $total_faculty = count($faculty_performance);
                    $evaluated_faculty = 0;
                    $total_evaluations = 0;
                    $rating_sum = 0;
                    $rating_count = 0;
                    
                    foreach ($faculty_performance as $faculty) {
                        if ($faculty['evaluation_count'] > 0) {
                            $evaluated_faculty++;
                            $total_evaluations += $faculty['evaluation_count'];
                            if ($faculty['avg_rating']) {
                                $rating_sum += $faculty['avg_rating'];
                                $rating_count++;
                            }
                        }
                    }
                    
                    $overall_avg = $rating_count > 0 ? $rating_sum / $rating_count : 0;
                    $evaluation_rate = $total_faculty > 0 ? ($evaluated_faculty / $total_faculty) * 100 : 0;
                    ?>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_faculty; ?></div>
                        <div class="stat-label">Total Faculty</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $evaluated_faculty; ?></div>
                        <div class="stat-label">Faculty Evaluated</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($evaluation_rate, 1); ?>%</div>
                        <div class="stat-label">Evaluation Rate</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_evaluations; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($overall_avg, 2); ?></div>
                        <div class="stat-label">Overall Average Rating</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
