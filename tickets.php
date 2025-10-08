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

// function to send notifications
function sendNotification($conn, $employee_id, $title, $body, $type, $created_by = null) {
    $escaped_title = $conn->real_escape_string($title);
    $escaped_body = $conn->real_escape_string($body);
    $escaped_type = $conn->real_escape_string($type);
    $created_by = $created_by ? (int)$created_by : 0;
    
    $insert_sql = "INSERT INTO notifications (employee_id, body, title, type, created_by) 
                   VALUES ($employee_id, '$escaped_body', '$escaped_title', '$escaped_type', $created_by)";
    
    return $conn->query($insert_sql);
}

// function to get admin and super_admin IDs for notifications
function getAdminAndSuperAdminIds($conn) {
    $admin_query = "SELECT id FROM employees WHERE role IN ('admin', 'super_admin')";
    $result = $conn->query($admin_query);
    $admin_ids = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $admin_ids[] = $row['id'];
        }
    }
    
    return $admin_ids;
}

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
$id = isset($_GET['role']) ? $_GET['role'] : null;

if (isset($action)) {
    switch ($action) {
        case 'view':
            $searchQuery = isset($_GET['searchQuery']) ? $_GET['searchQuery'] : '';
            $whereClause = '';
            // Only filter by assigned user when a numeric id is provided
            if ($id !== null && is_numeric($id)) {
                $whereClause = " WHERE tickets.assigned_to = " . intval($id);
            }

            if (!empty($searchQuery)) {
                $searchQuery = $conn->real_escape_string($searchQuery);
                $like = "%$searchQuery%";
                // Build comprehensive search across ticket fields and assignee fields
                $whereClause .= ($whereClause ? " AND" : " WHERE") . " (" .
                    " tickets.title LIKE '$like'" .
                    " OR tickets.description LIKE '$like'" .
                    " OR tickets.priority LIKE '$like'" .
                    " OR tickets.status LIKE '$like'" .
                    " OR employees.first_name LIKE '$like'" .
                    " OR employees.last_name LIKE '$like'" .
                    " OR employees.email LIKE '$like'" .
                ")";
            }

            $query = "SELECT
                tickets.id AS ticket_id,
                tickets.title,
                tickets.description,
                tickets.priority,
                tickets.assigned_to,
                tickets.assigned_at,
                tickets.progress,
                tickets.due_date,
                tickets.completed_at,
                tickets.status,
                employees.id AS employee_id,
                employees.first_name AS assigned_first_name,
                employees.last_name AS assigned_last_name,
                employees.email AS assigned_email,
                employees.profile AS assigned_profile
            FROM tickets
            LEFT JOIN employees ON tickets.assigned_to = employees.id
            $whereClause
            ORDER BY tickets.assigned_at DESC";

            $result = $conn->query($query);

            $tickets = [];

            if ($result) {
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $ticket = [
                            'ticket_id' => $row['ticket_id'],
                            'title' => $row['title'],
                            'description' => $row['description'],
                            'priority' => $row['priority'],
                            'assigned_to' => $row['employee_id'] ? [
                                'employee_id' => $row['employee_id'],
                                'first_name' => $row['assigned_first_name'],
                                'last_name' => $row['assigned_last_name'],
                                'email' => $row['assigned_email'],
                                'profile' => $row['assigned_profile']
                            ] : null,
                            'assigned_at' => $row['assigned_at'],
                            'progress' => (int)$row['progress'],
                            'due_date' => $row['due_date'],
                            'completed_at' => $row['completed_at'],
                            'status' => $row['status'],
                            'created_at' => $row['created_at']
                        ];
                        $tickets[] = $ticket;
                    }
                }
                echo json_encode(['success' => true, "data" => $tickets]);
            } else {
                sendJsonResponse(false, NULL, "Query failed: " . $conn->error);
            }

            break;
        case 'add':
            $title = $_POST['title'] ?? NULL;
            $description = $_POST['description'] ?? NULL;
            $priority = $_POST['priority'] ?? NULL;
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;
            $assigned_to = $_POST['assigned_to'] ?? NULL;
            $assigned_by = $_POST['assigned_by'] ?? NULL;
            $assigned_at = date('Y-m-d H:i:s');
            $status = 'to-do';

            if ($title && $description && $priority && $assigned_to && $assigned_by) {
                $stmt = $conn->prepare("INSERT INTO tickets (title, description, priority, due_date, assigned_to, assigned_by, assigned_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssss", $title, $description, $priority, $due_date, $assigned_to, $assigned_by, $assigned_at, $status);

                if ($stmt->execute()) {
                    $getUser = $conn->query("SELECT id, profile, first_name, last_name, email from employees where id = " . $assigned_to . "");
                    $user = $getUser->fetch_assoc();

                    $formatted_due_date = date('d M Y', strtotime($due_date));
                    // Send notification to assigned employee
                    $notification_title = "New Ticket Assigned";
                    $notification_body = "New ticket assigned: $title <br> Due on $formatted_due_date.";
                    sendNotification($conn, $assigned_to, $notification_title, $notification_body, 'ticket_assigned', $assigned_by);
                    
                    $newTicketData = [
                        'id' => $stmt->insert_id,
                        'title' => $title,
                        'description' => $description,
                        'priority' => $priority,
                        'due_date' => $due_date,
                        'status' => $status,
                        'assigned_to' => $user['id'] ? [
                            'employee_id' => $user['id'],
                            'first_name' => $user['first_name'],
                            'last_name' => $user['last_name'],
                            'email' => $user['email'],
                            'profile' => $user['profile']
                        ] : NULL,
                        'progress' => 0,
                        'assigned_at' => $assigned_at,
                    ];

                    sendJsonResponse('success', $newTicketData, 'Ticket added successfully');
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to add ticket', 'details' => $stmt->error]);
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields']);
            }

            break;
        case 'get':
            if (isset($_GET['ticket_id']) && is_numeric($_GET['ticket_id']) && $_GET['ticket_id'] > 0) {
                $ticket_id = $_GET['ticket_id'];

                // Fetch the ticket details
                $ticketQuery = "SELECT
                    tickets.id AS ticket_id,
                    tickets.title,
                    tickets.description,
                    tickets.priority,
                    tickets.assigned_to,
                    tickets.assigned_at,
                    tickets.progress,
                    tickets.due_date,
                    tickets.status,
                    tickets.completed_at,
                    employees.id AS employee_id,
                    employees.first_name AS assigned_first_name,
                    employees.last_name AS assigned_last_name,
                    employees.email AS assigned_email,
                    employees.profile AS assigned_profile
                FROM tickets
                LEFT JOIN employees ON tickets.assigned_to = employees.id
                WHERE tickets.id = ?";
                $stmt = $conn->prepare($ticketQuery);
                $stmt->bind_param("i", $ticket_id);
                $stmt->execute();
                $ticketResult = $stmt->get_result();

                if ($ticketResult && $ticketResult->num_rows > 0) {
                    $ticketData = [];
                    $ticket = $ticketResult->fetch_assoc();
                    $ticketData = [
                        'ticket_id' => $ticket['ticket_id'],
                        'title' => $ticket['title'],
                        'description' => $ticket['description'],
                        'priority' => $ticket['priority'],
                        'assigned_to' => $ticket['employee_id'] ? [
                            'employee_id' => $ticket['employee_id'],
                            'first_name' => $ticket['assigned_first_name'],
                            'last_name' => $ticket['assigned_last_name'],
                            'email' => $ticket['assigned_email'],
                            'profile' => $ticket['assigned_profile']
                        ] : null,
                        'assigned_at' => $ticket['assigned_at'],
                        'progress' => (int)$ticket['progress'],
                        'due_date' => $ticket['due_date'],
                        'completed_at' => $ticket['completed_at'],
                        'status' => $ticket['status']
                    ];

                    // Fetch comments for this ticket
                    $commentQuery = "SELECT
                        comments.id AS comment_id,
                        comments.comment AS comment_comment,
                        comments.created_at AS comment_created_at,
                        comments.comment_by AS comment_author_id,
                        employees2.id AS commenter_id,
                        employees2.first_name AS commenter_first_name,
                        employees2.last_name AS commenter_last_name,
                        employees2.email AS commenter_email,
                        employees2.profile AS commenter_profile
                    FROM comments
                    LEFT JOIN employees AS employees2 ON comments.comment_by = employees2.id
                    WHERE comments.ticket_id = ?";
                    $stmt = $conn->prepare($commentQuery);
                    $stmt->bind_param("i", $ticket_id);
                    $stmt->execute();
                    $commentResult = $stmt->get_result();

                    $comments = [];
                    while ($commentRow = $commentResult->fetch_assoc()) {
                        $comments[] = [
                            'comment_id' => $commentRow['comment_id'],
                            'comment_comment' => $commentRow['comment_comment'],
                            'comment_created_at' => $commentRow['comment_created_at'],
                            'commented_by' => $commentRow['commenter_id'] ? [
                                'employee_id' => $commentRow['commenter_id'],
                                'first_name' => $commentRow['commenter_first_name'],
                                'last_name' => $commentRow['commenter_last_name'],
                                'email' => $commentRow['commenter_email'],
                                'profile' => $commentRow['commenter_profile']
                            ] : null
                        ];
                    }

                    // Fetch logs for this ticket
                    $logQuery = "SELECT
                        ticket_logs.id AS log_id,
                        ticket_logs.date AS log_date,
                        ticket_logs.hours_worked AS log_working_hours
                    FROM ticket_logs
                    WHERE ticket_logs.ticket_id = ?";
                    $stmt = $conn->prepare($logQuery);
                    $stmt->bind_param("i", $ticket_id);
                    $stmt->execute();
                    $logResult = $stmt->get_result();

                    $logs = [];
                    while ($logRow = $logResult->fetch_assoc()) {
                        $logs[] = [
                            'log_id' => $logRow['log_id'],
                            'log_date' => $logRow['log_date'],
                            'log_working_hours' => $logRow['log_working_hours']
                        ];
                    }

                    // Combine ticket data, comments, and logs into the response
                    $response = [
                        'ticket' => $ticketData,
                        'comments' => $comments,
                        'logs' => $logs
                    ];

                    // Send the response as JSON
                    echo json_encode([
                        'status' => 'success',
                        'data' => $response
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Ticket not found'
                    ]);
                }
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid ticket ID'
                ]);
            }
            break;

        case 'edit':
            $ticket_id = $_GET['id'] ?? NULL;
            $title = $_POST['title'] ?? NULL;
            $description = $_POST['description'] ?? NULL;
            $priority = $_POST['priority'] ?? NULL;
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;
            $assigned_to = $_POST['assigned_to'] ?? NULL;
            $progress = $_POST['progress'] !== '' ? (int)$_POST['progress'] : 0;
            $status = $progress == 100 ? 'completed' : ($progress > 0 ? 'in-progress' : 'to-do');
            $completed_at = $progress == 100 ? !empty($_POST['completed_at']) ? $_POST['completed_at'] : date('Y-m-d H:i:s') : NULL;
            
            if ($ticket_id && $title && $description && $priority && $assigned_to) {
                $stmt = $conn->prepare("UPDATE tickets SET title = ?, description = ?, priority = ?, due_date = ?, assigned_to = ?, progress = ?, completed_at = ?, status = ? WHERE id = ?");
                $stmt->bind_param("ssssssssi", $title, $description, $priority, $due_date, $assigned_to, $progress, $completed_at, $status, $ticket_id);

                if ($stmt->execute()) {
                    // Get the current user's role to determine if we should send notifications
                    $current_user_id = $_POST['updated_by'] ?? null;
                    if ($current_user_id) {
                        $user_query = "SELECT role FROM employees WHERE id = " . (int)$current_user_id;
                        $user_result = $conn->query($user_query);
                        $user_role = $user_result ? $user_result->fetch_assoc()['role'] : null;
                        // When Admin Updates/Edit the Ticket, Notify the Assigned Employee
                        if (in_array($user_role, ['admin', 'super_admin'])) {
                            $notification_title = "Ticket Updated By Admin";
                            $notification_body = "Ticket Title: $title <br> Progress to $progress%" . ($status === 'completed' ? " (COMPLETED)" : "");
                            sendNotification($conn, $assigned_to, $notification_title, $notification_body, 'ticket_progress_updated', $current_user_id);
                        }
                    }
                    
                    sendJsonResponse('success', null, 'Ticket updated successfully');
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to update ticket', 'details' => $stmt->error]);
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields']);
            }
            break;

        case 'delete':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                $ticket_id = $_GET['id'];
                $conn->begin_transaction();
                $stmt = $conn->prepare("DELETE FROM comments WHERE ticket_id = ?");
                $stmt->bind_param("i", $ticket_id);
                $stmt->execute();

                $stmt = $conn->prepare("DELETE FROM tickets WHERE id = ?");
                $stmt->bind_param("i", $ticket_id);
                $stmt->execute();

                $conn->commit();
                sendJsonResponse('success', null, 'Ticket and associated comments deleted successfully');
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid ticket ID']);
            }
            break;
        case 'add-progress-logs':
            $ticket_id = $_POST['ticket_id'];
            $date = $_POST['date'];
            $progress = $_POST['progress'];
            $updated_by = $_POST['updated_by'];
            $working_hours = $_POST['working_hours'];
            $status = ($progress == 100) ? 'completed' : (($progress > 0) ? 'in-progress' : 'to-do');
            $completed_at = $progress == 100 ? $date : NULL;

            $updateQuery = "UPDATE tickets SET progress = ?, status = ?, completed_at = ? WHERE id = ?";
            if ($updateStmt = $conn->prepare($updateQuery)) {
                $updateStmt->bind_param("issi", $progress, $status, $completed_at, $ticket_id);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                echo "Error preparing update statement: " . $conn->error . "\n";
                sendJsonResponse('error', $response, "Error preparing update SQL.");
            }

            $checkQuery = "SELECT COUNT(*) FROM ticket_logs WHERE ticket_id = ? AND date = ?";
            if ($checkStmt = $conn->prepare($checkQuery)) {
                $checkStmt->bind_param("is", $ticket_id, $date);
                $checkStmt->execute();
                $checkStmt->bind_result($count);
                $checkStmt->fetch();
                $checkStmt->close();

                if ($count > 0) {
                    sendJsonResponse('error', $response, "Progress for this ticket has already been logged for this date.");
                    return;
                }
            } else {
                echo "Error preparing check statement: " . $conn->error . "\n";
                sendJsonResponse('error', $response, "Error preparing check SQL.");
            }

            $insertQuery = "INSERT INTO ticket_logs (ticket_id, hours_worked, date, updated_by) VALUES (?, ?, ?, ?)";
            if ($insertStmt = $conn->prepare($insertQuery)) {
                $insertStmt->bind_param("iisi", $ticket_id, $working_hours, $date, $updated_by);
                $insertStmt->execute();
                $insertStmt->close();

                // Get the current user's role to determine if we should send notifications
                $user_query = "SELECT role FROM employees WHERE id = " . (int)$updated_by;
                $user_result = $conn->query($user_query);
                $user_role = $user_result ? $user_result->fetch_assoc()['role'] : null;
                
                // If employee is updating progress, notify admins
                if ($user_role === 'employee') {
                    // Get ticket title for notification
                    $ticket_query = "SELECT title FROM tickets WHERE id = " . (int)$ticket_id;
                    $ticket_result = $conn->query($ticket_query);
                    $ticket_title = $ticket_result ? $ticket_result->fetch_assoc()['title'] : 'Unknown Ticket';
                    
                    $admin_ids = getAdminAndSuperAdminIds($conn);
                    $notification_title = "Ticket Progress Updated";
                    $notification_body = "Ticket updated: $ticket_title <br> Progress to $progress%" . ($status === 'completed' ? " (COMPLETED)" : "");
                    
                    foreach ($admin_ids as $admin_id) {
                        sendNotification($conn, $admin_id, $notification_title, $notification_body, 'ticket_progress_updated', $updated_by);
                    }
                }
                sendJsonResponse('success', ["progress" => $progress]);
            } else {
                echo "Error preparing insert statement: " . $conn->error . "\n";
                sendJsonResponse('error', $response, "Error preparing insert SQL.");
            }

            break;
    }
}
