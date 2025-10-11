<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

// Include the database connection
include 'db_connection.php';

// Set the header for JSON response
header('Content-Type: application/json');

// Helper function to send JSON response
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

// Main action handler
if (isset($action)) {
    switch ($action) {
        case 'check-login':
            $email = $_POST['email'] ?? null;
            $password = $_POST['password'] ?? null;

            /** Validate */
            if (!$email) {
                sendJsonResponse('error', null, 'Email is required');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJsonResponse('error', null, 'Please enter a valid email address');
            }
            if (!$password) {
                sendJsonResponse('error', null, 'Password is required');
            }

            $password = md5($password);
            $stmt = $conn->prepare("SELECT id, first_name, last_name, email, role, dob, gender, status, profile, deleted_at FROM employees WHERE email = ? AND password = ? ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("ss", $email, $password);
            $stmt->execute();
            $result = $stmt->get_result();

            /** Validate email */
            if ($result->num_rows == 0) {
                sendJsonResponse('error', null, 'Please enter a valid registered email address and password.');
            } else {
                $result = $result->fetch_assoc();

                if (!empty($result['deleted_at'])) {
                    sendJsonResponse('error', null, 'This account has been deleted. Please contact the administrator.');
                }

                if ($result['status'] === 0) {
                    sendJsonResponse('error', null, 'Your account is deactivated. Please contact the administrator.');
                }

                $dt = new DateTime();
                $dt->add(new DateInterval('PT12H'));
                
                $result['access_token'] = base64_encode($result['id'].'|'.$result['email']. '|'.$result['role'].'|'.$dt->format('Y-m-d H:i:s'));
                
                $result['token_type'] = 'Bearer';

                sendJsonResponse('success', $result, 'Login successful!');
            }

            break;
        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
} else {
    sendJsonResponse('error', null, 'Action parameter is missing');
}
