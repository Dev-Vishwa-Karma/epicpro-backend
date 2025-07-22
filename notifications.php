<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");


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
        case 'birthday_notify':
            $today = date('Y-m-d');
            $current_month = date('m');
            $current_day = date('d');

            // Fetch today's birthday employees
            $employee_sql = "
                SELECT id, first_name, last_name, email 
                FROM employees 
                WHERE MONTH(dob) = '$current_month' AND DAY(dob) = '$current_day'
            ";
           
            $employee_result = $conn->query($employee_sql);

            if (!$employee_result) {
                sendJsonResponse('error', null, 'DB error: ' . $conn->error);
                break;
            }

            $birthday_employees = [];

            if ($employee_result->num_rows > 0) {
                while ($row = $employee_result->fetch_assoc()) {
                    $birthday_employees[] = $row;
                }
            }

            if (empty($birthday_employees)) {
                sendJsonResponse('success', null, 'No birthdays today');
                break;
            }

            // Notify all employees (except the ones with birthdays today)
            $all_employee_sql = "SELECT id, first_name, last_name, email FROM employees";
            $all_employee_result = $conn->query($all_employee_sql);

            if (!$all_employee_result) {
                sendJsonResponse('error', null, 'DB error: ' . $conn->error);
                break;
            }

            $all_employees = $all_employee_result->fetch_all(MYSQLI_ASSOC);

            $notification_count = 0;
            $skipped_count = 0;
            $failed_count = 0;

            // Prepare birthday messages
            $birthday_names = array_map(function($emp) {
                return $emp['first_name'] . ' ' . $emp['last_name'];
            }, $birthday_employees);
            $birthday_names_string = implode(", ", $birthday_names);

            $title = "Happy Birthday";
            $body = "Wish Happy Birthday to " . $birthday_names_string;

            foreach ($all_employees as $all_employee) {
                $skip_employee = false;

                foreach ($birthday_employees as $birthday_employee) {
                    if ($all_employee['id'] === $birthday_employee['id']) {
                        $skip_employee = true;
                        $skipped_count++;
                        break;
                    }
                }

                if ($skip_employee) {
                    continue;
                }

                $employee_id = $all_employee['id'];

                $check_sql = "
                    SELECT id FROM notifications 
                    WHERE type = 'Birthday' AND DATE(created_at) = '$today' AND employee_id = $employee_id
                ";
                $check_result = $conn->query($check_sql);

                if ($check_result && $check_result->num_rows == 0) {
                    $escaped_title = $conn->real_escape_string($title);
                    $escaped_body = $conn->real_escape_string($body);

                    $insert_sql = "
                        INSERT INTO notifications (employee_id, body, title, type, `read`) 
                        VALUES ($employee_id, '$escaped_body', '$escaped_title', 'Birthday', 0)
                    ";

                    if ($conn->query($insert_sql)) {
                        $notification_count++;
                    } else {
                        $failed_count++;
                    }
                } else {
                    $skipped_count++;
                }
            }

            if ($notification_count > 0) {
                sendJsonResponse('success', [
                    'message' => "$notification_count notifications were sent successfully.",
                    'skipped' => $skipped_count,
                    'failed' => $failed_count
                ]);
            } else {
                sendJsonResponse('error', null, 'No notifications were sent. Failed to send all notifications.');
            }

            break;

        case 'get_notifications':
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
            $employee_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10; 
            $offset = ($page - 1) * $limit;

            if ($employee_id === null) {
                sendJsonResponse('error', null, 'User ID is required');
                break;
            }

            $employee_id = (int)$employee_id;

            $stmt = $conn->prepare("
                SELECT id, employee_id, `read`, body, title, `type`, created_at 
                FROM notifications 
                WHERE employee_id = ? 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ");

            if (!$stmt) {
                sendJsonResponse('error', null, 'Database error: ' . $conn->error);
                break;
            }

            $stmt->bind_param('iii', $employee_id, $limit, $offset); 
            $stmt->execute();
            $result = $stmt->get_result();

            $notifications = [];

            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
            }

            if (count($notifications) > 0) {
                sendJsonResponse('success', $notifications);
            } else {
                sendJsonResponse('error', null, 'No unread notifications');
            }

            break;
            
       case 'mark_read':
        if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
            $user_id = (int)$_GET['user_id'];

            if (isset($_GET['notification_id']) && is_numeric($_GET['notification_id'])) {
                $notification_id = (int)$_GET['notification_id'];

                // Mark a specific notification as read
                $stmt = $conn->prepare("UPDATE notifications SET `read` = 1 WHERE employee_id = ? AND id = ?");
                if (!$stmt) {
                    sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
                    exit;
                }

                $stmt->bind_param('ii', $user_id, $notification_id);
            } else {
                // Mark all notifications as read for this user
                $stmt = $conn->prepare("UPDATE notifications SET `read` = 1 WHERE employee_id = ?");
                if (!$stmt) {
                    sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
                    exit;
                }

                $stmt->bind_param('i', $user_id);
            }

            if ($stmt->execute()) {
                sendJsonResponse('success', null, 'Notification(s) marked as read');
            } else {
                sendJsonResponse('error', null, 'Failed to mark notification as read');
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
