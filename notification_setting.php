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
require 'helpers.php';

// Helper function to validate user ID
function validateId($id) {
    return isset($id) && is_numeric($id) && $id > 0;
}

if (!isAdminCheck()) {
    sendJsonResponse('error', null, 'Access denied. You do not have permission to access this route.');
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';
$userId = $_GET['user_ids'] ?? null;

if(isset($action)){

    switch ($action) {
        case 'view':
            try{
                $sql = "SELECT `key`, `value` FROM connects_settings WHERE created_by = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $data = $result->fetch_assoc();
                sendJsonResponse('success', $data, 'Notification settings retrieved successfully.');

            }catch(Exception $e){
                sendJsonResponse('error', null, $e->getMessage());
            }
            break;
        
        case 'update':
            $required =  ['key', 'value', 'created_by'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    sendJsonResponse('error', null, "Field '$field' is required.");
                }
            }

            $key = $_POST['key'];
            $value = $_POST['value'];
            $created_by = $_POST['created_by'];

            try{
                $conn->begin_transaction();
                $sql = "SELECT id FROM connects_settings WHERE created_by = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $created_by);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $id = $row['id'] ?? null;
                $created_at = $updated_at = date('Y-m-d H:i:s');

                if ($result->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE connects_settings SET `key` = ?, `value` = ?, updated_at = ? WHERE id = ?");
                    $stmt->bind_param("sssi", $key, $value, $updated_at, $id);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("INSERT INTO connects_settings (`key`, `value`, created_by) VALUES (?, ?, ?) ");
                    $stmt->bind_param("ssi", $key, $value, $created_by);
                    $stmt->execute();
                }
                $conn->commit();
            }catch(Exception $e){
                $conn->rollback();
                sendJsonResponse('error', null, $e->getMessage());
            }
            $data =[
                'id' => $id,
                'key' => $key,
                'value' => $value,
                'created_by' => $created_by
            ];
            sendJsonResponse('success', $data, 'Notification settings updated successfully.');
            break;
    }

}






?>
