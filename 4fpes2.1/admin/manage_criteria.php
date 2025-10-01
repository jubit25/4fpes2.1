<?php
require_once '../config.php';
requireRole('admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add_criterion':
            $category = sanitizeInput($_POST['category'] ?? '');
            $criterion = sanitizeInput($_POST['criterion'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $weight = floatval($_POST['weight'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($category) || empty($criterion) || $weight <= 0) {
                echo json_encode(['success' => false, 'message' => 'All required fields must be filled and weight must be greater than 0']);
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO evaluation_criteria (category, criterion, description, weight, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$category, $criterion, $description, $weight, $is_active]);
            
            echo json_encode(['success' => true, 'message' => 'Evaluation criterion added successfully']);
            break;
            
        case 'update_criterion':
            $id = intval($_POST['id'] ?? 0);
            $category = sanitizeInput($_POST['category'] ?? '');
            $criterion = sanitizeInput($_POST['criterion'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');
            $weight = floatval($_POST['weight'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if ($id <= 0 || empty($category) || empty($criterion) || $weight <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid data provided']);
                exit();
            }
            
            $stmt = $pdo->prepare("UPDATE evaluation_criteria SET category = ?, criterion = ?, description = ?, weight = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$category, $criterion, $description, $weight, $is_active, $id]);
            
            echo json_encode(['success' => true, 'message' => 'Evaluation criterion updated successfully']);
            break;
            
        case 'delete_criterion':
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid criterion ID']);
                exit();
            }
            
            // Check if criterion is being used in evaluations
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM evaluation_responses WHERE criterion_id = ?");
            $stmt->execute([$id]);
            $usage_count = $stmt->fetch()['count'];
            
            if ($usage_count > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete criterion that is being used in evaluations. Consider deactivating it instead.']);
                exit();
            }
            
            $stmt = $pdo->prepare("DELETE FROM evaluation_criteria WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Evaluation criterion deleted successfully']);
            break;
            
        case 'get_criterion':
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid criterion ID']);
                exit();
            }
            
            $stmt = $pdo->prepare("SELECT * FROM evaluation_criteria WHERE id = ?");
            $stmt->execute([$id]);
            $criterion = $stmt->fetch();
            
            if (!$criterion) {
                echo json_encode(['success' => false, 'message' => 'Criterion not found']);
                exit();
            }
            
            echo json_encode(['success' => true, 'criterion' => $criterion]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
