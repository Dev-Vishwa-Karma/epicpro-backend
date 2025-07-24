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
            // Pagination and limit setup
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null; 
            $offset = ($page - 1) * $limit;

            // Filters
            $employee_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
            $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
            $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
            $status = isset($_GET['status']) ? $_GET['status'] : null;

            // Begin constructing the base query with LEFT JOIN to get employee details
            $query = "SELECT 
                        CONCAT(employees.first_name, ' ', employees.last_name) AS full_name,
                        notifications.id,
                        notifications.employee_id,
                        notifications.title,
                        notifications.body,
                        notifications.`type`,
                        notifications.`read`, 
                        notifications.created_at
                    FROM notifications
                    LEFT JOIN employees ON notifications.employee_id = employees.id
                    WHERE employees.role = 'employee'"; // Filter by role

            // Apply employee_id filter if it's provided
            if ($employee_id !== null) {
                $employee_id = (int)$employee_id;
                $query .= " AND notifications.employee_id = $employee_id";
            }

            // Add status filter
            if ($status && in_array($status, ['read', 'unread'])) {
                $query .= " AND notifications.`read` = '$status'";
            }

            // Add date filters
            if ($start_date && $end_date) {
                $query .= " AND DATE(notifications.created_at) BETWEEN '$start_date' AND '$end_date'";
            } elseif ($start_date) {
                $query .= " AND DATE(notifications.created_at) >= '$start_date'";
            } elseif ($end_date) {
                $query .= " AND DATE(notifications.created_at) <= '$end_date'";
            }

            // Add pagination (LIMIT and OFFSET) only if limit is provided
            if ($limit !== null && $limit > 0) {
                $query .= " ORDER BY notifications.created_at DESC LIMIT $limit OFFSET $offset";
            } else {
                // No pagination, just order by creation date
                $query .= " ORDER BY notifications.created_at DESC";
            }

            // Execute the query
            $result = $conn->query($query);

            if ($result) {
                $notifications = [];
                while ($row = $result->fetch_assoc()) {
                    $notifications[] = $row;
                }

                if (count($notifications) > 0) {
                    sendJsonResponse('success', $notifications);
                } else {
                    sendJsonResponse('error', null, 'No notifications found');
                }
            } else {
                sendJsonResponse('error', null, 'Database error: ' . $conn->error);
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
            case 'add':
                $title = isset($_POST['title']) ? $_POST['title'] : null;
                $body = isset($_POST['body']) ? $_POST['body'] : null;
                $type = isset($_POST['type']) ? $_POST['type'] : null;
                $read = isset($_POST['read']) ? $_POST['read'] : 0; 
                $employee_id = isset($_POST['employee_id']) ? $_POST['employee_id'] : null;
                $created_at = date('Y-m-d H:i:s');
                $updated_at = $created_at;
                
                // Validate required fields
                if ($title && $body && $type) {
                    // Prepare the SQL insert statement
                    $stmt = $conn->prepare("INSERT INTO notifications (employee_id, title, body, `type`, `read`, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssiss", $employee_id, $title, $body, $type, $read, $created_at, $updated_at);

                    // Execute the statement and check for success
                    if ($stmt->execute()) {
                        $newNotificationData = [
                            'id' => $stmt->insert_id,
                            'employee_id' => $employee_id,
                            'title' => $title,
                            'body' => $body,
                            'type' => $type,
                            'read' => $read,
                            'created_at' => $created_at,
                            'updated_at' => $updated_at
                        ];

                        echo json_encode(['success' => 'Notification added successfully', 'newNotification' => $newNotificationData]);
                    } else {
                        // If the insert fails, return an error with the details
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to add notification', 'details' => $stmt->error]);
                    }
                } else {
                    // Missing required fields
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required fields']);
                }
        break;

        case 'edit':
            // Validate and get POST data
            $notification_id = $_POST['id'] ?? '';
            $title = $_POST['title'] ?? '';
            $body = $_POST['body'] ?? '';
            $type = $_POST['type'] ?? '';
            $read = $_POST['read'] ?? '';
            $employee_id = $_POST['employee_id'] ?? ''; 

            // Validate notification_id
            if (empty($notification_id) || !is_numeric($notification_id) || $notification_id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid or missing Notification ID']);
                exit;
            }

            // Prepare the SQL UPDATE query using placeholders
            $update_query = "
                UPDATE notifications 
                SET employee_id = ?, title = ?, body = ?, type = ?, `read` = ?
                WHERE id = ?
            ";

            // Prepare the statement
            if ($stmt = mysqli_prepare($conn, $update_query)) {
                // Bind parameters to the prepared statement
                mysqli_stmt_bind_param($stmt, "sssssi", $employee_id, $title, $body, $type, $read, $notification_id);

                // Execute the prepared statement
                if (mysqli_stmt_execute($stmt)) {
                    // Fetch updated notification details
                   $notification_query = "SELECT 
                            CONCAT(employees.first_name, ' ', employees.last_name) AS full_name,
                            notifications.id,
                            notifications.employee_id,
                            notifications.title,
                            notifications.body,
                            notifications.`type`,
                            notifications.`read`, 
                            notifications.created_at
                        FROM notifications
                        JOIN employees ON notifications.employee_id = employees.id
                        WHERE notifications.id = ?";
                    if ($notification_stmt = mysqli_prepare($conn, $notification_query)) {
                        mysqli_stmt_bind_param($notification_stmt, "i", $notification_id);
                        mysqli_stmt_execute($notification_stmt);
                        $notification_result = mysqli_stmt_get_result($notification_stmt);
                        $notification_data = mysqli_fetch_assoc($notification_result);

                        // Prepare the response data
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Notification updated successfully',
                            'updatedNotificationData' => $notification_data
                        ]);
                    } else {
                        echo json_encode(['error' => 'Failed to retrieve updated notification data']);
                    }
                } else {
                    echo json_encode(['error' => 'Failed to update notification', 'details' => mysqli_error($conn)]);
                }

                // Close the statement
                mysqli_stmt_close($stmt);
            } else {
                echo json_encode(['error' => 'Failed to prepare the SQL query']);
            }
            exit;
            break;



        case 'delete':
                if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                    // Prepare DELETE statement
                    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ?");
                    $stmt->bind_param('i', $_GET['id']);
                    if ($stmt->execute()) {
                        echo json_encode(['success' => 'Record deleted successfully']);
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to delete record']);
                    }
                    exit;
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid notification ID']);
                    exit;
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
