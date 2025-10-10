<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'db_connection.php';
include 'auth_validate.php';

header('Content-Type: application/json');

function sendJsonResponse($status, $data = null, $message = null)
{
    header('Content-Type: application/json');
    if ($status === 'success') {
        echo json_encode(['status' => 'success', 'data' => $data, 'message' => $message]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $message]);
    }
    exit;
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';

if (isset($action)) {
    switch ($action) {
        case 'get-dashboard-preferences':
            $user_id = $_GET['user_id'] ?? null;
            // user_id = logged in user id
            
            if (!$user_id) {
                sendJsonResponse('error', null, 'User ID is required');
            }

            // Get all dashboard preferences for user
            $query = "SELECT preference_key, preference_value FROM user_preferences 
                     WHERE user_id = ? AND module = 'dashboard'";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $preferences = [
                'show_todo' => true, // Default value 1
                'show_project' => true // Default value 1
            ];
            
            while ($row = $result->fetch_assoc()) {
                if ($row['preference_key'] === 'todo') {
                    $preferences['show_todo'] = (bool)$row['preference_value'];
                } elseif ($row['preference_key'] === 'project') {
                    $preferences['show_project'] = (bool)$row['preference_value'];
                }
            }

            sendJsonResponse('success', $preferences, 'Preferences fetched successfully');
            break;

        case 'update-dashboard-preferences':
            $input = json_decode(file_get_contents('php://input'), true);
            $user_id = $input['user_id'] ?? null;
            $show_todo = $input['show_todo'] ?? null;
            $show_project = $input['show_project'] ?? null;

            if (!$user_id) {
                sendJsonResponse('error', null, 'User ID is required');
            }

            // Update todo preference
            if ($show_todo !== null) {
                $todoValue = $show_todo ? 1 : 0;
                $todoStmt = $conn->prepare("INSERT INTO user_preferences (user_id, module, preference_key, preference_value) 
                                          VALUES (?, 'dashboard', 'todo', ?) 
                                          ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
                $todoStmt->bind_param("iii", $user_id, $todoValue, $todoValue);
                $todoStmt->execute();
                $todoStmt->close();
            }

            // Update project preference
            if ($show_project !== null) {
                $projectValue = $show_project ? 1 : 0;
                $projectStmt = $conn->prepare("INSERT INTO user_preferences (user_id, module, preference_key, preference_value) 
                                             VALUES (?, 'dashboard', 'project', ?) 
                                             ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
                $projectStmt->bind_param("iii", $user_id, $projectValue, $projectValue);
                $projectStmt->execute();
                $projectStmt->close();
            }

            sendJsonResponse('success', null, 'Preferences updated successfully');
            break;

        case 'get-admin-preferences':
            // Get preferences for all users (admin view)
            if (!isAdminCheck()) {
                sendJsonResponse('error', null, 'Admin access required');
            }

            $preferencesQuery = "SELECT
                                up.user_id,
                                e.first_name,
                                e.last_name,
                                MAX(CASE WHEN up.preference_key = 'todo' THEN up.preference_value END) as show_todo,
                                MAX(CASE WHEN up.preference_key = 'project' THEN up.preference_value END) as show_project
                                FROM user_preferences up
                                JOIN employees e ON up.user_id = e.id
                                WHERE up.module = 'dashboard'
                                GROUP BY up.user_id, e.first_name, e.last_name
                                ORDER BY e.first_name, e.last_name";

            $result = $conn->query($preferencesQuery);
            $preferences = [];

            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $preferences[] = [
                        'user_id' => $row['user_id'],
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'show_todo' => (bool)($row['show_todo'] ?? 1),
                        'show_project' => (bool)($row['show_project'] ?? 1)
                    ];
                }
            }

            sendJsonResponse('success', $preferences, 'Admin preferences fetched successfully');
            break;

        case 'get-global-dashboard-preferences':
            $query = "SELECT preference_key, preference_value FROM global_preferences
                     WHERE module = 'dashboard'";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $preferences = [
                'show_todo' => true, // Default value 1
                'show_project' => true // Default value 1
            ];
            
            while ($row = $result->fetch_assoc()) {
                if ($row['preference_key'] === 'todo') {
                    $preferences['show_todo'] = (bool)$row['preference_value'];
                } elseif ($row['preference_key'] === 'project') {
                    $preferences['show_project'] = (bool)$row['preference_value'];
                }
            }

            sendJsonResponse('success', $preferences, 'Global preferences fetched successfully');
            break;

        case 'update-global-dashboard-preferences':
            if (!isAdminCheck()) {
                sendJsonResponse('error', null, 'Admin access required');
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $show_todo = $input['show_todo'] ?? null;
            $show_project = $input['show_project'] ?? null;

            if ($show_todo !== null) {
                $todoValue = $show_todo ? 1 : 0;
                $todoStmt = $conn->prepare("INSERT INTO global_preferences (module, preference_key, preference_value)
                                        VALUES ('dashboard', 'todo', ?)
                                        ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
                $todoStmt->bind_param("ii", $todoValue, $todoValue);
                $todoStmt->execute();
                $todoStmt->close();
            }

            if ($show_project !== null) {
                $projectValue = $show_project ? 1 : 0;
                $projectStmt = $conn->prepare("INSERT INTO global_preferences (module, preference_key, preference_value)
                                            VALUES ('dashboard', 'project', ?)
                                            ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
                $projectStmt->bind_param("ii", $projectValue, $projectValue);
                $projectStmt->execute();
                $projectStmt->close();
            }

            sendJsonResponse('success', null, 'Global preferences updated successfully');
            break;

        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
}
?>