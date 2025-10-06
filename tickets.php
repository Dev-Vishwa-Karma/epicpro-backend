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
$id = isset($_GET['role']) ? $_GET['role'] : null;

if (isset($action)) {
    switch ($action) {
        case 'view':
            $searchQuery = isset($_GET['searchQuery']) ? $_GET['searchQuery'] : '';
            $whereClause = '';
            if ($id !== null) {
                $whereClause = " WHERE tickets.assigned_to = " . $id;
            }

            if (!empty($searchQuery)) {
                $searchQuery = $conn->real_escape_string($searchQuery);
                $whereClause .= ($whereClause ? " AND" : " WHERE") . " tickets.title LIKE '%$searchQuery%'";
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
            ORDER BY tickets.created_at DESC";

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

                $sql .= "SELECT * FROM tickets WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $_GET['ticket_id']);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $conn->query($sql);
            }

            if ($result) {
                $ticket = $result->fetch_all(MYSQLI_ASSOC);
                echo json_encode([
                    'status' => 'success',
                    'data'   => $ticket
                ]);
            } else {
                echo json_encode(['error' => 'No records found']);
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
            $completed_at = $progress == 100 ? $_POST['completed_at'] : NULL;

            if ($ticket_id && $title && $description && $priority && $assigned_to) {
                $stmt = $conn->prepare("UPDATE tickets SET title = ?, description = ?, priority = ?, due_date = ?, assigned_to = ?, progress = ?, completed_at = ?, status = ? WHERE id = ?");
                $stmt->bind_param("ssssssssi", $title, $description, $priority, $due_date, $assigned_to, $progress, $completed_at, $status, $ticket_id);

                if ($stmt->execute()) {
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
        case 'ticket-with-comments':
            if (isset($_GET['ticket_id']) && is_numeric($_GET['ticket_id']) && $_GET['ticket_id'] > 0) {
                $ticket_id = $_GET['ticket_id'];

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
                        employees.profile AS assigned_profile,
                        comments.id AS comment_id,
                        comments.comment AS comment_comment,
                        comments.created_at AS comment_created_at,
                        comments.comment_by AS comment_author_id,
                        employees2.id AS commenter_id,
                        employees2.first_name AS commenter_first_name,
                        employees2.last_name AS commenter_last_name,
                        employees2.email AS commenter_email,
                        employees2.profile AS commenter_profile
                    FROM tickets
                    LEFT JOIN employees ON tickets.assigned_to = employees.id
                    LEFT JOIN comments ON tickets.id = comments.ticket_id
                    LEFT JOIN employees AS employees2 ON comments.comment_by = employees2.id
                    WHERE tickets.id = ?";

                $stmt = $conn->prepare($ticketQuery);
                $stmt->bind_param("i", $ticket_id);
                $stmt->execute();
                $ticketResult = $stmt->get_result();

                if ($ticketResult && $ticketResult->num_rows > 0) {
                    $ticketData = [];
                    $comments = [];
                    $logs = [];

                    while ($row = $ticketResult->fetch_assoc()) {
                        if (empty($ticketData)) {
                            $ticketData = [
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
                                'completed_at' => $row['completed_at']
                            ];
                        }

                        if ($row['comment_id']) {
                            $comments[] = [
                                'comment_id' => $row['comment_id'],
                                'comment_comment' => $row['comment_comment'],
                                'comment_created_at' => $row['comment_created_at'],
                                'commented_by' => $row['commenter_id'] ? [
                                    'employee_id' => $row['commenter_id'],
                                    'first_name' => $row['commenter_first_name'],
                                    'last_name' => $row['commenter_last_name'],
                                    'email' => $row['commenter_email'],
                                    'profile' => $row['commenter_profile']
                                ] : null
                            ];
                        }
                    }

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

                    if ($logResult && $logResult->num_rows > 0) {
                        while ($row = $logResult->fetch_assoc()) {
                            $logs[] = [
                                'log_id' => $row['log_id'],
                                'log_date' => $row['log_date'],
                                'log_working_hours' => $row['log_working_hours']
                            ];
                        }
                    }

                    $response = [
                        'ticket' => $ticketData,
                        'comments' => $comments,
                        'logs' => $logs
                    ];

                    sendJsonResponse('success', $response);
                } else {
                    sendJsonResponse('error', null, 'Ticket not found');
                }
            } else {
                sendJsonResponse('error', null, 'Invalid ticket ID');
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

                sendJsonResponse('success', ["progress" => $progress]);
            } else {
                echo "Error preparing insert statement: " . $conn->error . "\n";
                sendJsonResponse('error', $response, "Error preparing insert SQL.");
            }

            break;
    }
}
