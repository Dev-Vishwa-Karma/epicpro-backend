<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

// Include the database connection
include 'db_connection.php';

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
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                // Prepare SELECT statement with WHERE clause using parameter binding
                $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
                $stmt->bind_param("i", $_GET['id']);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        $events = $result->fetch_all(MYSQLI_ASSOC);
                        sendJsonResponse('success', $events, null);
                    } else {
                        sendJsonResponse('error', null, "No leaves found : $conn->error");
                    }
                } else {
                    sendJsonResponse('error', null, "Failed to execute query : $stmt->error");
                }
            } else {
                $result = $conn->query("SELECT * FROM events");
                if ($result) {
                    $events = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $events);
                } else {
                    sendJsonResponse('error', null, "No records found $conn->error");
                }
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

            // Prepare the insert query
            $stmt = $conn->prepare("INSERT INTO events (event_name, event_date, event_type, created_by) VALUES (?, ?, ?, ?, ?)");
            
            // Bind the parameters
            $stmt->bind_param("isssi", $event_name, $event_date, $event_type, $created_by);

            // Execute the query
            if ($stmt->execute()) {
                $id = $conn->insert_id;

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
                sendJsonResponse('error', null, "Failed to add Event $stmt->error");
            }
            break;

        // case 'edit':
        //     if (isset($_GET['event_id']) && is_numeric($_GET['event_id']) && $_GET['event_id'] > 0) {
        //         $id = $_GET['event_id'];
        //         // Validate and get POST data
        //         $event_name = $_POST['event_name'];
        //         $event_date = $_POST['event_date'];
        //         $event_type = $_POST['event_type'];
        //         $updated_at = date('Y-m-d H:i:s');

        //         // Prepare the SQL update statement
        //         $stmt = $conn->prepare("UPDATE events SET event_name = ?, event_date = ?, event_type = ?, updated_at = ? WHERE id = ?");
        //         $stmt->bind_param("issssi", $event_name, $event_date, $event_type, $updated_at, $id);
    
        //         // Execute the statement and check for success
        //         if ($stmt->execute()) {

        //             $updatedEventData = [
        //                 'id' => $id,
        //                 'event_name' => $event_name,
        //                 'event_date' => $event_date,
        //                 'event_type' => $event_type,
        //                 'updated_at' => $updated_at
        //             ];
        //             sendJsonResponse('success', $updatedEventData, 'Event updated successfully');
        //         } else {
        //             sendJsonResponse('error', null, 'Failed to update event');
        //         }
        //         exit;
        //     } else {
        //         http_response_code(400);
        //         sendJsonResponse('error', null, 'Invalid Event ID');
        //         exit;
        //     }
        //     break;

        case 'delete':
            if (isset($_GET['event_id']) && is_numeric($_GET['event_id']) && $_GET['event_id'] > 0) {
                // Prepare DELETE statement
                $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
                $stmt->bind_param('i', $_GET['event_id']);
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
