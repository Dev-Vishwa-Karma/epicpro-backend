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

isAdmin();

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

$action = !empty($_GET['action']) ? $_GET['action'] : '';
$input = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    case 'get':

        $service = $_GET['service'] ?? '';
        if ($service) {
            $stmt = $conn->prepare("SELECT * FROM config_settings WHERE service=?");
            $stmt->bind_param("s", $service);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $row['service_details'] = json_decode($row['service_details'], true);
                sendJsonResponse('success', $row);
            }

            sendJsonResponse('error', null, 'service not found');
        } else {
            $result = $conn->query("SELECT * FROM config_settings");
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $row['service_details'] = json_decode($row['service_details'], true);
                $data[] = $row;
            }

            sendJsonResponse('success', $data);
        }
        break;

    case 'create':
        $service = $input['service'] ?? '';
        $service_details = $input['service_details'] ?? [];

        if (!$service) {
            sendJsonResponse('error', null, 'service is required');
        }

        $json = json_encode($service_details);

        $stmt = $conn->prepare("
            INSERT INTO config_settings
            (service, service_details)
            VALUES (?, ?)
        ");

        $stmt->bind_param("ss", $service, $json);

        if ($stmt->execute()) {
            sendJsonResponse('success', ['id' => $stmt->insert_id]);
        }

        sendJsonResponse('error', null, $stmt->error);

        break;

    case 'update':

        $service = $input['service'] ?? '';
        $service_details = $input['service_details'] ?? [];

        if (!$service) {
            sendJsonResponse('error', null, 'service is required');
        }

        $json = json_encode($service_details);

        $stmt = $conn->prepare("
            UPDATE config_settings
            SET service_details=?,
                updated_at=CURRENT_TIMESTAMP
            WHERE service=?
        ");

        $stmt->bind_param("ss", $json, $service);

        if ($stmt->execute()) {
            sendJsonResponse('success', null, 'Updated successfully');
        }

        sendJsonResponse('error', null, $stmt->error);

        break;

    case 'delete':

        $service = $input['service'] ?? ($_GET['service'] ?? '');

        if (!$service) {
            sendJsonResponse('error', null, 'service is required');
        }

        $stmt = $conn->prepare("
            DELETE FROM config_settings
            WHERE service=?
        ");

        $stmt->bind_param("s", $service);

        if ($stmt->execute()) {
            sendJsonResponse('success', null, 'Deleted successfully');
        }

        sendJsonResponse('error', null, $stmt->error);

        break;

    default:
        sendJsonResponse('error', null, 'Invalid action');
        break;
}
?>
