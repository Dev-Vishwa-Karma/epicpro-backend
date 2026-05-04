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

//Helper funtion to check authenticity.
if (!isAdminCheck()) {
    sendJsonResponse('error', null, 'Access denied. You do not have permission to access this route.');
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';
$filter = !empty($_GET['filter']) ? $_GET['filter'] : 'all';
$search = !empty($_GET['search']) ? json_decode($_GET['search'], true) : [];
$roleFilter = isset($_GET['role']) ? $_GET['role'] : 'all';

// Main action handler
if (isset($action)) {
    switch ($action) {

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
            $pusher = getPusher($config);

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
            $conn->close();
            echo json_encode([
                "email" => $emailResults,
                "success" => empty($emailResults),
                "message" => empty(!$emailResults) ? "Email successfully" : "Some Connects failed",
            ]);
            break;
    }
} else {
    sendJsonResponse('error', null, 'Action parameter is missing');
}

?>
