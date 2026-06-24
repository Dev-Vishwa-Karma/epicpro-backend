<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight (OPTIONS) requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // Respond with 200 status code for OPTIONS requests
    header("HTTP/1.1 200 OK");
    exit();
}

// Include the database connection
include 'db_connection.php';
include 'auth_validate.php';

// Helper function to send JSON response
function sendJsonResponse($status, $data = null, $message = null) {
    header('Content-Type: application/json');
    if ($status === 'success') {
        echo json_encode(['status' => 'success', 'data' => $data, 'message' => $message]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $message]);
    }
    exit;
}

// Helper function to validate user ID
function validateId($id) {
    return isset($id) && is_numeric($id) && $id > 0;
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';

// Main action handler
if (isset($action)) {
    switch ($action) {
        case 'view':
            if (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) {
                // Prepare SELECT statement with WHERE clause using a placeholder to prevent SQL injection
                $stmt = $conn->prepare("SELECT users.id, users.role, users.created_at, employees.user_id, employees.first_name, employees.last_name, employees.email, employees.employee_role 
                FROM users 
                INNER JOIN employees ON users.id = employees.user_id 
                WHERE users.id = ?");
                $stmt->bind_param('i', $_GET['user_id']);
                $stmt->execute();
                $result = $stmt->get_result();    
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    sendJsonResponse('success', $user);
                } else {
                    sendJsonResponse('error', null, 'User not found');
                }
            } else {
                // If no user_id provided, fetch all users
                $stmt = $conn->prepare("SELECT users.id, users.role, users.created_at, employees.user_id, employees.first_name, employees.last_name, employees.email, employees.role 
                FROM users 
                INNER JOIN employees ON users.id = employees.user_id");
                $stmt->execute();
                $result = $stmt->get_result();
                    
                if ($result->num_rows > 0) {
                    $users = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $users);
                } else {
                    sendJsonResponse('error', null, 'No users found');
                }
            }
            break;            

        case 'add':
            // Capture POST data
            $employee_id = $_POST['employee_id'] ?? null;
            $first_name = $_POST['first_name'] ?? null;
            $last_name = $_POST['last_name'] ?? null;
            $email = $_POST['email'] ?? null;
            $mobile = $_POST['mobile_no'] ?? null;
            $role = $_POST['selected_role'] ?? null;
            $password = $_POST['password'] ?? null;

            if ($employee_id && $first_name && $email) {
                // Insert into users table first
                $stmt = $conn->prepare("INSERT INTO users (user_code, role) VALUES (?, ?)");
                $stmt->bind_param('ss', $employee_id, $role);
                if ($stmt->execute()) {
                    $user_id = $stmt->insert_id;

                    // Insert into employees table
                    $stmt = $conn->prepare("INSERT INTO employees (user_id, employee_id, first_name, last_name, email, mobile_no1, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('issssss', $user_id, $employee_id, $first_name, $last_name, $email, $mobile, $password);

                    if ($stmt->execute()) {
                        $created_at = date('Y-m-d H:i:s');
                        sendJsonResponse('success', ['user_id' => $user_id, 'employee_id' => $employee_id, 'first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'role' => $role, 'created_at' => $created_at], 'User added successfully');
                    } else {
                        $error = $stmt->error;
                        sendJsonResponse('error', null, "Failed to add user details: $error");
                    }
                } else {
                    $error = $stmt->error;
                    sendJsonResponse('error', null, "Failed to add user details: $error");
                }
            } else {
                sendJsonResponse('error', null, 'Missing required fields');
            }
            break;

        // Edit existing user case
        case 'edit':
            if (isset($_GET['user_id'])) {
                $id = $_GET['user_id'];

                $first_name = isset($_POST['first_name']) ? $_POST['first_name'] : null;
                $last_name = isset($_POST['last_name']) ? $_POST['last_name'] : null;
                $email = isset($_POST['email']) ? $_POST['email'] : null;
                $selected_role = isset($_POST['selected_role']) ? $_POST['selected_role'] : null;
                $employee_role = isset($_POST['employee_role']) ? $_POST['employee_role'] : null;
                $updated_at = date('Y-m-d H:i:s');

                if ($first_name || $last_name || $email || $selected_role) {
                    // Insert into users table first
                    $stmt = $conn->prepare("UPDATE users SET role = ?, updated_at = ? WHERE id = ?");
                    $stmt->bind_param('ssi', $selected_role, $updated_at, $id);

                    if ($stmt->execute()) {
                        $user_id = $stmt->insert_id;
                        
                        // Update employees table
                        $stmt = $conn->prepare("UPDATE employees SET first_name = ?, last_name = ?, email = ?, employee_role = ?, updated_at = ? WHERE user_id = ?");
                        $stmt->bind_param('sssssi', $first_name, $last_name, $email, $employee_role, $updated_at, $id);
    
                        if ($stmt->execute()) {
                            sendJsonResponse('success', ['user_id' => $id, 'first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'role' => $selected_role, 'employee_role' => $employee_role], 'User updated successfully');
                        } else {
                            sendJsonResponse('error', null, 'Failed to update employee details');
                        }
                    }

                } else {
                    sendJsonResponse('error', null, 'Missing required fields');
                }
            } else {
                sendJsonResponse('error', null, 'Invalid user ID');
            }
            break;

        // Delete user case
        case 'delete':
            if (isset($_GET['user_id']) && validateId($_GET['user_id'])) {
                $id = $_GET['user_id'];

                // Delete from employees table first
                $stmt = $conn->prepare("DELETE FROM employees WHERE user_id = ?");
                $stmt->bind_param('i', $id);

                if ($stmt->execute()) {
                    // Now delete from users table
                    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->bind_param('i', $id);

                    if ($stmt->execute()) {
                        sendJsonResponse('success', null, 'User deleted successfully');
                    } else {
                        sendJsonResponse('error', null, 'Failed to delete user');
                    }
                } else {
                    sendJsonResponse('error', null, 'Failed to delete employee details');
                }
            } else {
                sendJsonResponse('error', null, 'Invalid user ID');
            }
            break;

        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
} else {
    sendJsonResponse('error', null, 'Action parameter is missing');
}

?>
