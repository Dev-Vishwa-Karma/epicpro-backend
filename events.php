<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include the database connection
include 'db_connection.php';
include 'auth_validate.php';
// include 'auth_validate.php';
// Set the header for JSON response
header('Content-Type: application/json');

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

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';

if (isset($action)) {
    switch ($action) {
        case 'view':
            $id = isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0 ? (int)$_GET['id'] : null;
            $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : null;
            $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : null;

            if ($from_date && $to_date && strtotime($from_date) > strtotime($to_date)) {
                sendJsonResponse('error', null, "From date cannot be greater than to date.");
                exit;
            }

            $allowed_event_types = ['holiday', 'event'];
            $event_type = isset($_GET['event_type']) && in_array($_GET['event_type'], $allowed_event_types) 
                ? $_GET['event_type'] 
                : null;
            
            $conditions = [];
            if ($id !== null) {
                $conditions[] = "id = $id";
            }
            if ($event_type !== null) {
                $conditions[] = "event_type = '$event_type'";
            }
            if ($from_date && $to_date) {
                $conditions[] = "DATE(events.event_date) BETWEEN '$from_date' AND '$to_date'";
            } elseif ($from_date) {
                $conditions[] = "DATE(events.event_date) = '$from_date'";
            } elseif ($to_date) {
                $conditions[] = "DATE(events.event_date) = '$to_date'";
            }
            
            $whereClause = '';
            if (!empty($conditions)) {
                $whereClause = "WHERE " . implode(" AND ", $conditions);
            }

            $sql = "SELECT * FROM events $whereClause";
            $result = $conn->query($sql);
            
            if ($result) {
                $events = $result->fetch_all(MYSQLI_ASSOC);
                sendJsonResponse('success', $events);
            } else {
                sendJsonResponse('error', null, "Query failed: $conn->error");
            }
            
            break;

        case 'add':
            // Get form data
            $event_name = $_POST['event_name'];
            $event_date = $_POST['event_date'];
            $event_type = $_POST['event_type'];
            $created_by = isset($_POST['created_by']) ? $_POST['created_by'] : null;

            // Validate the data (you can add additional validation as needed)
            if (empty($event_name) || empty($event_date) || empty($event_type)) {
                sendJsonResponse('error', null, "All fields are required");
                exit;
            }

            // Prepare the insert query for the event
            $stmt = $conn->prepare("INSERT INTO events (event_name, event_date, event_type, created_by) VALUES (?, ?, ?, ?)");
            
            // Bind the parameters
            $stmt->bind_param("sssi", $event_name, $event_date, $event_type, $created_by);

            // Execute the query
            if ($stmt->execute()) {
                $id = $conn->insert_id;

                // Get the list of all employees, excluding the one who created the event
                $notif_sql = "SELECT id, first_name, last_name FROM employees WHERE id != ?";
                $notif_stmt = $conn->prepare($notif_sql);
                
                // Check if the prepare statement failed
                if ($notif_stmt === false) {
                    die('SQL Error: ' . $conn->error);
                }

                $notif_stmt->bind_param("i", $created_by);
                $notif_stmt->execute();
                $result = $notif_stmt->get_result();

                // Send notifications to all employees
                while ($employee = $result->fetch_assoc()) {
                    $notification_body = $conn->real_escape_string("$event_name has been on $event_date.");
                    $notification_title = "New Event";
                    $notification_type = "event_added";
                    $notification_recipient = $employee['id']; // Notify the current employee

                    $notif_insert_sql = "
                        INSERT INTO notifications 
                        (employee_id, body, title, type) 
                        VALUES ($notification_recipient, '$notification_body', '$notification_title', '$notification_type')
                    ";

                    $conn->query($notif_insert_sql);
                }

                // Prepare the response data
                $addEventData = [
                    'id' => $id,
                    'event_name' => $event_name,
                    'event_date' => $event_date,  
                    'event_type' => $event_type,
                    'created_by' => $created_by
                ];

                // If successful, send success response
                sendJsonResponse('success', $addEventData, "Event added successfully");
            } else {
                sendJsonResponse('error', null, "Failed to add Event: $stmt->error");
            }
            break;

        case 'edit':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                $id = $_GET['id'];
                // Validate and get POST data
                $event_name = $_POST['event_name'];
                $event_date = $_POST['event_date'];
                $event_type = $_POST['event_type'];
                $updated_at = date('Y-m-d H:i:s');
 
                // Prepare the SQL update statement
                $stmt = $conn->prepare("UPDATE events SET event_name = ?, event_date = ?, event_type = ?, updated_at = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $event_name, $event_date, $event_type, $updated_at, $id);
    
                // Execute the statement and check for success
                if ($stmt->execute()) {

                    $updatedEventData = [
                        'id' => $id,
                        'event_name' => $event_name,
                        'event_date' => $event_date,
                        'event_type' => $event_type,
                        'updated_at' => $updated_at
                    ];
                    sendJsonResponse('success', $updatedEventData, 'Event updated successfully');
                } else {
                    sendJsonResponse('error', null, 'Failed to update event');
                }
                exit;
            } else {
                http_response_code(400);
                sendJsonResponse('error', null, 'Invalid Event ID');
                exit;
            }
            break;

        case 'delete':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                // Prepare DELETE statement
                $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
                $stmt->bind_param('i', $_GET['id']);
                if ($stmt->execute()) {
                    sendJsonResponse('success', null, 'Holiday deleted successfully');
                } else {
                    http_response_code(500);
                    sendJsonResponse('error', null, 'Failed to delete holiday');
                }
                exit;
            } else {
                http_response_code(400);
                sendJsonResponse('error', null, 'Invalid holiday ID');
                exit;
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            exit;
    }
}

// Close the connection
$conn->close();
?>
