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

include 'db_connection.php';
include 'auth_validate.php';
require 'send_mail.php';
require 'helpers.php';
require_once __DIR__ . '/pusher.php';
$config = require __DIR__ . '/config.php';

// Helper function to validate user ID
function validateId($id) {
    return isset($id) && is_numeric($id) && $id > 0;
}

if (!isAdminCheck()) {
    sendJsonResponse('error', null, 'Access denied. You do not have permission to access this route.');
}
//Save notifications
function saveNotification($conn, $data, $id = null) {

    if ($id) {
        $stmt = $conn->prepare("
            UPDATE push_notifications 
            SET title=?, body=?, type=?, status=?, priority=?, filePath=?, updated_at=? 
            WHERE id=?
        ");
        $stmt->bind_param( "sssssssi", $data['title'], $data['body'], $data['type'], $data['status'], $data['priority'], $data['filePath'], $data['updated_at'],$id);

    } else {
        $stmt = $conn->prepare("
            INSERT INTO push_notifications 
            (title, body, type, status, priority, filePath, created_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
         $stmt->bind_param( "sssssssss", $data['title'], $data['body'], $data['type'], $data['status'], $data['priority'], $data['filePath'], $data['created_by'], $data['created_at'], $data['updated_at'] );
    }

    $stmt->execute();
    return $id ? $id : $stmt->insert_id;
}
// Save users who will receive the notifications and trigger pusher event
function insertNotificationUsers($conn, $notification_id, $employees, $created_at, $updated_at, $pusher, $title, $message, $config) {

    $stmt = $conn->prepare("
        INSERT INTO notifications_user 
        (notification_id, employee_id, notification_status, created_at, updated_at) 
        VALUES (?, ?, 'Unread', ?, ?)
    ");
    $receiver = [];
    $errors = [];

    foreach ($employees as $empId) {

        $stmt->bind_param("iiss", $notification_id, $empId, $created_at, $updated_at);

        if ($stmt->execute()) {

            $receiver[] = [
                'employee_id' => $empId,
                'read' => "unread"
            ];

            $pusher->trigger($config['pusher']['channel'], 'new_notification'.$empId, [
                'id' => $notification_id,
                'title' => $title,
                'message' => $message
            ]);

        } else {
            $errors[] = $stmt->error;
        }
    }
    return [$receiver, $errors];
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';
$filter = !empty($_GET['filter']) ? $_GET['filter'] : 'all';
$roleFilter = isset($_GET['role']) ? $_GET['role'] : 'all';

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

            $where = "";

            if ($start_date && $end_date) {
                $where .= " AND DATE(pn.created_at) BETWEEN '$start_date' AND '$end_date'";
            } elseif ($start_date) {
                $where .= " AND DATE(pn.created_at) >= '$start_date'";
            } elseif ($end_date) {
                $where .= " AND DATE(pn.created_at) <= '$end_date'";
            }

            if ($filter && $filter !== 'all') {
                $where .= " AND pn.status = '$filter'";
            }


            $query = '';
            
            // //SENT NOTIFICATIONS
            if($filter === 'sent' || $filter === 'draft'){
                $query = "
                    SELECT 
                        pn.id,
                        pn.title,
                        pn.body,
                        pn.filePath,
                        pn.type,
                        pn.priority,
                        pn.status,
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
            }else{
                $query = "
                    SELECT 
                        pn.id,
                        pn.title,
                        pn.body,
                        pn.filePath,
                        pn.type,
                        pn.created_at,
                        pn.priority,
                        nu.notification_status AS `read`,
                        CONCAT(e.first_name,' ',e.last_name) AS sender_name,
                        re.profile, 
                        re.id As employee_id
                    FROM notifications_user nu
                    LEFT JOIN push_notifications pn ON pn.id = nu.notification_id 
                    LEFT JOIN employees e ON e.id = pn.created_by
                    LEFT JOIN employees re ON re.id = nu.employee_id
                    WHERE nu.employee_id = $user_id AND nu.hidden = 0
                    AND pn.status = 'sent'
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

            $required = ['selectedEmployee','title','body','createdBy','email','type','priority','status'];

            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    sendJsonResponse('error', null, "$field is required");
                }
            }
            $data = [
                'id'          => $_POST['id'] ?? null,
                'title'       => $_POST['title'],
                'body'        => $_POST['body'],
                'type'        => $_POST['type'],
                'priority'    => $_POST['priority'],
                'status'      => $_POST['status'],
                'created_by'  => $_POST['createdBy'],
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
                
            $selectedEmployee = $_POST['selectedEmployee'];
            $to = $_POST['email'];
            $newFiles = handleNotificationFileUpload($_FILES['attach'] ?? []);
            $data['filePath'] = json_encode($newFiles);
            $pusher = getPusher($config);
            
            try {

                $conn->begin_transaction();
                $notification_id = saveNotification($conn, $data, $data['id']);
                if ($data['id']) {
                    $conn->query("DELETE FROM notifications_user WHERE notification_id = {$data['id']}");
                }
                list($receiver, $errors) = insertNotificationUsers( $conn, $notification_id, $selectedEmployee, $data['created_at'], $data['updated_at'], $pusher, $data['title'], $data['body'], $config);
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
            $users = array_map(function ($row) {
                return [
                    "id" => $row['id'],
                    "email" => $row['email'],
                    "name" => $row['first_name'] . " " . $row['last_name'],
                ];
            }, $result->fetch_all(MYSQLI_ASSOC));
            
            if($data['status'] === 'sent'){
                $emailResults = sendMailToUsers( $users, $to, $data['title'], $data['body'], $config['email']);
            }
            foreach ($receiver as &$rec) {
                $empId = $rec['employee_id'];
                $rec['receiver_name'] = current(array_filter($users, fn($u) => $u['id'] == $empId))['name'] ?? '';
            }
            unset($rec);
            $data['id'] = $notification_id;
            $data['receiver'] = $receiver;
            $conn->close();
            echo json_encode([
                "success" => empty($errors),
                "email" => $emailResults ?? null,
                "message" => empty($errors) ? "Notifications stored & pushed successfully" : "Some notifications failed",
                "newNotification" => $data,
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
