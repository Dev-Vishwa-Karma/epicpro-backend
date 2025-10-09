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
            
            if (!$user_id) {
                sendJsonResponse('error', null, 'User ID is required');
            }

            // Get todo preference
            $todoQuery = "SELECT preference_value FROM user_preferences_todo 
                         WHERE user_id = ? AND module = 'dashboard' AND preference_key = 'show_todo'";
            $stmt = $conn->prepare($todoQuery);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $todoResult = $stmt->get_result();
            $todoPreference = $todoResult->fetch_assoc();
            $showTodo = $todoPreference ? $todoPreference['preference_value'] : 'true';
            
            // Get project preference
            $projectQuery = "SELECT preference_value FROM user_preferences_project 
                           WHERE user_id = ? AND module = 'dashboard' AND preference_key = 'show_project'";
            $stmt = $conn->prepare($projectQuery);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $projectResult = $stmt->get_result();
            $projectPreference = $projectResult->fetch_assoc();
            $showProject = $projectPreference ? $projectPreference['preference_value'] : 'true';

            $preferences = [
                'show_todo' => $showTodo === 'true',
                'show_project' => $showProject === 'true'
            ];

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
                $todoValue = $show_todo ? 'true' : 'false';
                $todoStmt = $conn->prepare("INSERT INTO user_preferences_todo (user_id, module, preference_key, preference_value) 
                                          VALUES (?, 'dashboard', 'show_todo', ?) 
                                          ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
                $todoStmt->bind_param("iss", $user_id, $todoValue, $todoValue);
                $todoStmt->execute();
                $todoStmt->close();
            }

            // Update project preference
            if ($show_project !== null) {
                $projectValue = $show_project ? 'true' : 'false';
                $projectStmt = $conn->prepare("INSERT INTO user_preferences_project (user_id, module, preference_key, preference_value) 
                                             VALUES (?, 'dashboard', 'show_project', ?) 
                                             ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
                $projectStmt->bind_param("iss", $user_id, $projectValue, $projectValue);
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
                                ut.user_id,
                                e.first_name,
                                e.last_name,
                                ut.preference_value as show_todo,
                                up.preference_value as show_project
                                FROM user_preferences_todo ut
                                LEFT JOIN user_preferences_project up ON ut.user_id = up.user_id
                                AND up.module = 'dashboard' AND up.preference_key = 'show_project'
                                JOIN employees e ON ut.user_id = e.id
                                WHERE ut.module = 'dashboard' AND ut.preference_key = 'show_todo'
                                ORDER BY e.first_name, e.last_name";

            $result = $conn->query($preferencesQuery);
            $preferences = [];

            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $preferences[] = [
                        'user_id' => $row['user_id'],
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'show_todo' => $row['show_todo'] === 'true',
                        'show_project' => $row['show_project'] === 'true'
                    ];
                }
            }

            sendJsonResponse('success', $preferences, 'Admin preferences fetched successfully');
            break;

       case 'get-global-dashboard-preferences':
        $todoQuery = "SELECT preference_value FROM global_preferences
                    WHERE module = 'dashboard' AND preference_key = 'show_todo'";
        $stmt = $conn->prepare($todoQuery);
        $stmt->execute();
        $todoResult = $stmt->get_result();
        $todoPreference = $todoResult->fetch_assoc();
        $showTodo = $todoPreference ? $todoPreference['preference_value'] : 'true';

        $projectQuery = "SELECT preference_value FROM global_preferences
                        WHERE module = 'dashboard' AND preference_key = 'show_project'";
        $stmt = $conn->prepare($projectQuery);
        $stmt->execute();
        $projectResult = $stmt->get_result();
        $projectPreference = $projectResult->fetch_assoc();
        $showProject = $projectPreference ? $projectPreference['preference_value'] : 'true';

        $preferences = [
            'show_todo' => $showTodo === 'true',
            'show_project' => $showProject === 'true'
        ];

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
            $todoValue = $show_todo ? 'true' : 'false';
            $todoStmt = $conn->prepare("INSERT INTO global_preferences (module, preference_key, preference_value)
                                    VALUES ('dashboard', 'show_todo', ?)
                                    ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
            $todoStmt->bind_param("ss", $todoValue, $todoValue);
            $todoStmt->execute();
            $todoStmt->close();
        }

        if ($show_project !== null) {
            $projectValue = $show_project ? 'true' : 'false';
            $projectStmt = $conn->prepare("INSERT INTO global_preferences (module, preference_key, preference_value)
                                        VALUES ('dashboard', 'show_project', ?)
                                        ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
            $projectStmt->bind_param("ss", $projectValue, $projectValue);
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