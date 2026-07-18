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

        $provider = $_GET['provider'] ?? '';
        if ($provider) {
            $stmt = $conn->prepare("SELECT * FROM service_configuration WHERE provider=?");
            $stmt->bind_param("s", $provider);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $row['provider_details'] = json_decode($row['provider_details'], true);
                sendJsonResponse('success', $row);
            }

            sendJsonResponse('error', null, 'Provider not found');
        } else {
            $result = $conn->query("SELECT * FROM service_configuration");
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $row['provider_details'] = json_decode($row['provider_details'], true);
                $data[] = $row;
            }

            sendJsonResponse('success', $data);
        }
        break;

    case 'create':
        $provider = $input['provider'] ?? '';
        $provider_details = $input['provider_details'] ?? [];

        if (!$provider) {
            sendJsonResponse('error', null, 'Provider is required');
        }

        $json = json_encode($provider_details);

        $stmt = $conn->prepare("
            INSERT INTO service_configuration
            (provider, provider_details)
            VALUES (?, ?)
        ");

        $stmt->bind_param("ss", $provider, $json);

        if ($stmt->execute()) {
            sendJsonResponse('success', ['id' => $stmt->insert_id]);
        }

        sendJsonResponse('error', null, $stmt->error);

        break;

    case 'update':

        $provider = $input['provider'] ?? '';
        $provider_details = $input['provider_details'] ?? [];

        if (!$provider) {
            sendJsonResponse('error', null, 'Provider is required');
        }

        $json = json_encode($provider_details);

        $stmt = $conn->prepare("
            UPDATE service_configuration
            SET provider_details=?,
                updated_at=CURRENT_TIMESTAMP
            WHERE provider=?
        ");

        $stmt->bind_param("ss", $json, $provider);

        if ($stmt->execute()) {
            sendJsonResponse('success', null, 'Updated successfully');
        }

        sendJsonResponse('error', null, $stmt->error);

        break;

    case 'delete':

        $provider = $input['provider'] ?? ($_GET['provider'] ?? '');

        if (!$provider) {
            sendJsonResponse('error', null, 'Provider is required');
        }

        $stmt = $conn->prepare("
            DELETE FROM service_configuration
            WHERE provider=?
        ");

        $stmt->bind_param("s", $provider);

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
