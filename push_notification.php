<?php
ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
header("Access-Control-Allow-Credentials: true");


// Handle preflight (OPTIONS) requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // Respond with 200 status code for OPTIONS requests
    header("HTTP/1.1 200 OK");
    exit();
}

// Include the database connection
include 'db_connection.php';
include 'auth_validate.php';
require 'send_mail.php';
$config = require __DIR__ . '/config.php';

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
$filter = !empty($_GET['filter']) ? $_GET['filter'] : 'all';

// Main action handler
if (isset($action)) {
    switch ($action) {

        case 'get_push_notification':

            $user_id = (int)($_GET['user_id'] ?? 0);
            $start_date = $_GET['start_date'] ?? null;
            $end_date = $_GET['end_date'] ?? null;

            if (!$user_id) {
                sendJsonResponse('error', null, 'User ID is required');
            }

           // COMMON DATE FILTER
            $where = "";
            if ($start_date && $end_date) {
                $where = " AND DATE(pn.created_at) BETWEEN '$start_date' AND '$end_date'";
            } elseif ($start_date) {
                $where = " AND DATE(pn.created_at) >= '$start_date'";
            } elseif ($end_date) {
                $where = " AND DATE(pn.created_at) <= '$end_date'";
            }

            if($filter === 'manual'){
                $where = " AND pn.is_automated = 0";

            }else if($filter === 'automated'){
                $where = " AND pn.is_automated = 1";
            }

            $query = '';
            
            // //SENT NOTIFICATIONS
            if($filter === 'sent'){
                $query = "
                    SELECT 
                        pn.id,
                        pn.title,
                        pn.body,
                        pn.filePath,
                        pn.type,
                        pn.is_automated,
                        pn.created_at,
                        CONCAT(e.first_name,' ',e.last_name) AS sender_name,
                        COALESCE(
                            JSON_ARRAYAGG(
                                JSON_OBJECT(
                                    'id', ne.id,
                                    'read', ne.notification_status,
                                    'employee_id', ne.employee_id,
                                    'receiver_name', CONCAT(re.first_name,' ',re.last_name),
                                    'profile', re.profile
                                )
                            ),
                            JSON_ARRAY()
                        ) AS receiver
                    FROM push_notifications pn
                    LEFT JOIN employees e ON e.id = pn.created_by
                    LEFT JOIN notifications_user ne ON ne.notification_id = pn.id
                    LEFT JOIN employees re ON re.id = ne.employee_id
                    WHERE pn.created_by = $user_id
                    $where
                    GROUP BY pn.id
                    ORDER BY pn.id DESC
                ";
            }

            if($filter !== 'sent'){
                $query = "
                    SELECT 
                        pn.id,
                        pn.title,
                        pn.body,
                        pn.filePath,
                        pn.type,
                        pn.is_automated,
                        pn.created_at,
                        nu.notification_status AS `read`,
                        CONCAT(e.first_name,' ',e.last_name) AS sender_name,
                        re.profile, 
                        re.id As employee_id
                    FROM notifications_user nu
                    LEFT JOIN push_notifications pn ON pn.id = nu.notification_id 
                    LEFT JOIN employees e ON e.id = pn.created_by
                    LEFT JOIN employees re ON re.id = nu.employee_id
                    WHERE nu.employee_id = $user_id AND nu.hidden = 0
                    $where
                    ORDER BY pn.id DESC
                ";
            }

            $result = $conn->query($query);
            $data = [];

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            }
            sendJsonResponse('success', $data);

            break;


        case 'add':
            if (
                empty($_POST['selectedEmployee']) ||
                empty($_POST['title']) ||
                empty($_POST['body']) ||
                empty($_POST['createdBy']) ||
                empty($_POST['email']) ||
                empty($_POST['type']) ||
                empty($_FILES['attach'])
            ) {
                echo json_encode([
                    "status" => false,
                    "message" => "All fields are required"
                ]);
                exit;
            }
            $selectedEmployee = $_POST['selectedEmployee'];
            $title = $_POST['title'];
            $message = $_POST['body'];
            $type = $_POST['type'];
            $created_by = $_POST['createdBy'];
            $file = $_FILES['attach'];
            $to = $_POST['email'];
            $created_at = date('Y-m-d H:i:s');
            $updated_at = $created_at;


            // Handle file uploads
            $uploadedFiles = [];
            // $$baseUploadDir = __DIR__ . '/uploads/';
            $baseUploadDir = __DIR__ . '/uploads/';
            // ensure base folder exists
            if (!is_dir($baseUploadDir)) {
                mkdir($baseUploadDir, 0777, true);
            }

            foreach ($_FILES['attach']['name'] as $key => $filename) {

                $tmpName = $_FILES['attach']['tmp_name'][$key];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // decide folder
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $folder = 'images/';
                } elseif (in_array($ext, ['zip', 'rar', '7z'])) {
                    $folder = 'zip/';
                } else {
                    $folder = 'files/';
                }

                // FULL PATH
                $uploadDir = $baseUploadDir . $folder;

                // create folder safely
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $newName = uniqid('file_', true) . "." . $ext;
                $destination = $uploadDir . $newName;

                if (move_uploaded_file($tmpName, $destination)) {
                    $uploadedFiles[] = 'uploads/' . $folder . $newName;
                }
            }

            $filePaths = json_encode($uploadedFiles);
            $insertedNotifications = [];
            $errors = [];

            try {

                $conn->begin_transaction();

                // Insert notification {issssiss}:- employee_id, 
                $stmtMain = $conn->prepare("INSERT INTO push_notifications (title, body, type, filePath, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtMain->bind_param("ssssiss", $title, $message, $type, $filePaths, $created_by, $created_at, $updated_at);
                
                if (!$stmtMain->execute()) {
                    echo json_encode([
                        "status" => false,
                        "message" => "Failed to create notification",
                        "error" => $stmtMain->error
                    ]);
                    exit;
                }

                $notification_id = $stmtMain->insert_id;
                $stmtMain->close();

                //Inserted into notification_employee
                $stmt = $conn->prepare("
                    INSERT INTO notifications_user 
                    (notification_id, employee_id, notification_status, created_at, updated_at) 
                    VALUES (?, ?, 'Unread', ?, ?)
                ");

                $insertedNotifications = [];
                $errors = [];

                
                foreach ($selectedEmployee as $empId) {

                    $stmt->bind_param("iiss", $notification_id, $empId, $created_at, $updated_at);

                    if ($stmt->execute()) {

                        $newNotificationData = [
                            'id' => $notification_id,
                            'employee_id' => $empId,
                            'title' => $title,
                            'body' => $message,
                            'type' => $type,
                            'read' => "unread",
                            'created_at' => $created_at,
                            'updated_at' => $updated_at
                        ];

                        $insertedNotifications[] = $newNotificationData;

                        // Trigger pusher event
                        $eventTarget = 'new_notification' . $empId;
                        $pusher->trigger('my-channel', $eventTarget, [
                            'id' => $notification_id,
                            'selectedEmployee' => $empId,
                            'title' => $title,
                            'message' => $message
                        ]);

                    } else {
                        $errors[] = [
                            'employeeId' => $empId,
                            'error' => $stmt->error
                        ];
                    }
                }
                $stmt->close();

                $conn->commit();
            } catch (\Throwable $th) {
                $conn->rollback();

                echo json_encode([
                    "success" => false,
                    "message" => "Transaction failed. Rolled back.",
                    "error" => $th->getMessage()
                ]);
                exit;
            }

            $employIds = implode(',', array_map('intval', $selectedEmployee));
            $query = "SELECT id, first_name, last_name, email FROM employees WHERE id IN ($employIds)";
            $result = $conn->query($query);

            $users = [];

            while ($row = $result->fetch_assoc()) {
                $users[] = [
                    "id" => $row['id'],
                    "email" => $row['email'],
                    "name" => $row['first_name']." ".$row['last_name'],
                ];
            }

            $conn->close();
            $emailResults = sendMailToUsers(
                $users,
                $to,
                $title,
                $message,
                $uploadedFiles,
                $config
            );
            echo json_encode([
                "success" => empty($errors),
                "email" => $emailResults,
                "message" => empty($errors) ? "Notifications stored & pushed successfully" : "Some notifications failed",
                "newNotification" => $insertedNotifications,
                "errors" => $errors
            ]);
            break;

        case 'mark_read':
            if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
                $user_id = (int)$_GET['user_id'];

                if (isset($_GET['notification_id']) && is_numeric($_GET['notification_id'])) {
                    $notification_id = (int)$_GET['notification_id'];

                    $stmt = $conn->prepare("
                        UPDATE notifications_user 
                        SET notification_status = 'read' 
                        WHERE employee_id = ? AND notification_id = ?
                    ");

                    if (!$stmt) {
                        sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
                    }

                    $stmt->bind_param('ii', $user_id, $notification_id);
                    
                } else {
                    // Mark all create_push_notification as read for this user
                    $stmt = $conn->prepare("
                        UPDATE notifications_user 
                        SET notification_status = 'read' 
                        WHERE employee_id = ?
                    ");

                    if (!$stmt) {
                        sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
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

        case 'is_removed':

                $id = (int)($_GET['id'] ?? 0);
                $employee_id = (int)($_GET['employee_id'] ?? 0);
                $hidden = (int)($_GET['hidden'] ?? 1);

                if (!$id || !$employee_id) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid request']);
                    exit;
                }

                $stmt = $conn->prepare("
                    UPDATE notifications_user 
                    SET hidden = ?, 
                    hide_date = NOW() 
                    WHERE notification_id = ? 
                    AND employee_id = ?
                ");

                if (!$stmt) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Prepare failed']);
                    exit;
                }

                $stmt->bind_param("iii", $hidden, $id, $employee_id);

                if ($stmt->execute()) {
                    echo json_encode(['success' => 'Record hidden successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to hide record']);
                }

                exit;

            break;

        case 'update_status':

            if (!isset($_GET['notification_id']) || !is_numeric($_GET['notification_id'])) {
                sendJsonResponse('error', null, 'Invalid Notification ID');
                exit;
            }

            $notification_id = (int) $_GET['notification_id'];
            $status = $_GET['status'] ?? null;

             // Allowed statuses
            $allowed_status = ['0', '1', 'unread', 'read', 'completed', 'ready_to_discuss'];

            if (!in_array($status, $allowed_status, true)) {
                sendJsonResponse('error', null, 'Invalid status value');
                exit;
            }

            $stmt = $conn->prepare(" UPDATE notifications_user SET notification_status = ? WHERE notification_id = ? ");

            if (!$stmt) {
                sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
                exit;
            }

            $stmt->bind_param('si', $status, $notification_id);

            if ($stmt->execute()) {
                sendJsonResponse('success', null, 'Notification status updated successfully');
            } else {
                sendJsonResponse('error', null, 'Failed to update notification status');
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
