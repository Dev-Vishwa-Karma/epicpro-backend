<?php
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Credentials: true");
    
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    // Set the header for JSON response
    header('Content-Type: application/json');

    include 'db_connection.php';
    require_once 'helpers.php';

    $action = !empty($_GET['action']) ? $_GET['action'] : 'view';

    if (isset($action)) {
        switch ($action) {
            case 'view':
                if (isset($_GET['client_id']) && is_numeric($_GET['client_id']) && $_GET['client_id'] > 0) {
                    $client_id = (int) $_GET['client_id'];

                    $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
                    $stmt->bind_param("i", $client_id);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result) {
                            $clients = $result->fetch_all(MYSQLI_ASSOC);
                            sendJsonResponse('success', $clients, null);
                        } else {
                            sendJsonResponse('error', null, "Clients not found : $conn->error");
                        }
                    } else {
                        sendJsonResponse('error', null, "Failed to execute query : $stmt->error");
                    }
                } else {
                    $result = $conn->query("SELECT * FROM clients");
                    if ($result) {
                        $clients = $result->fetch_all(MYSQLI_ASSOC);
                        sendJsonResponse('success', $clients);
                    } else {
                        sendJsonResponse('error', null, "No records found $conn->error");
                    }
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