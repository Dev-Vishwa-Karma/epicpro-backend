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
            if (isset($_GET['employee_id']) && is_numeric($_GET['employee_id']) && $_GET['employee_id'] > 0) {
                // Fetch leaves only for the specific employee
                $stmt = $conn->prepare("SELECT 
                employee_leaves.id,
                employee_leaves.employee_id, 
                employee_leaves.from_date, 
                employee_leaves.to_date,
                employee_leaves.reason, 
                employee_leaves.status,
                employee_leaves.approved_by,
                employee_leaves.created_at, 
                employees.first_name, 
                employees.last_name, 
                employees.email
                FROM employee_leaves
                INNER JOIN employees ON employee_leaves.employee_id = employees.id
                WHERE employee_leaves.employee_id = ?");
                $stmt->bind_param("i", $_GET['employee_id']);

                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        $employee_leaves = $result->fetch_all(MYSQLI_ASSOC);
                        sendJsonResponse('success', $employee_leaves);
                    } else {
                        sendJsonResponse('error', null, "No leaves found: $conn->error");
                    }
                } else {
                    sendJsonResponse('error', null, "Failed to execute query: $stmt->error");
                }
            } else {
                $result = $conn->query("SELECT 
                        employee_leaves.id,
                        employee_leaves.employee_id, 
                        employee_leaves.from_date, 
                        employee_leaves.to_date,
                        employee_leaves.reason, 
                        employee_leaves.status,
                        employee_leaves.approved_by,
                        employee_leaves.created_at, 
                        employees.first_name, 
                        employees.last_name, 
                        employees.email
                    FROM employee_leaves
                    INNER JOIN employees ON employee_leaves.employee_id = employees.id");
                if ($result) {
                    $employee_leaves = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $employee_leaves);
                } else {
                    sendJsonResponse('error', null, "No records found $conn->error");
                }
            }
            break;

        case 'add':
            // Get form data
            $employee_id = isset($_POST['employee_id']) ? $_POST['employee_id'] : null;
            $from_date = $_POST['from_date'];
            $to_date = $_POST['to_date'];
            $reason = $_POST['reason'];
            $status = $_POST['status'];

            // Validate the data (you can add additional validation as needed)
            if (empty($from_date) || empty($to_date) || empty($reason) || empty($status)) {
                echo json_encode(['error' => 'All fields are required.']);
                exit;
            }

            // Prepare the insert query
            $stmt = $conn->prepare("INSERT INTO employee_leaves (employee_id, from_date, to_date, reason, status) VALUES (?, ?, ?, ?, ?)");
            
            // Bind the parameters
            $stmt->bind_param("issss", $employee_id, $from_date, $to_date, $reason, $status);

            // Execute the query
            if ($stmt->execute()) {
                $id = $conn->insert_id;

                // Query to get the employee's first and last name based on employee_id
                $employeeStmt = $conn->prepare("SELECT first_name, last_name FROM employees WHERE id = ?");
                $employeeStmt->bind_param("i", $employee_id);
                $employeeStmt->execute();
                $employeeStmt->store_result();

                // Check if the employee was found
                if ($employeeStmt->num_rows > 0) {
                    $employeeStmt->bind_result($emp_first_name, $emp_last_name);
                    $employeeStmt->fetch();
                } else {
                    // Handle the case where the employee is not found
                    $emp_first_name = "Unknown";
                    $emp_last_name = "Unknown";
                }

                $addEmployeeLeaveData = [
                    'id' => $id,
                    'employee_id' => $employee_id,
                    'first_name' => $emp_first_name,
                    'last_name' => $emp_last_name,  
                    'from_date' => $from_date,
                    'to_date' => $to_date,
                    'reason' => $reason,
                    'status' => $status,
                ];
                // If successful, send success response
                sendJsonResponse('success', $addEmployeeLeaveData, "Leave added successfully");
            } else {
                sendJsonResponse('error', null, "Failed to add leave $stmt->error");
            }
            break;

        case 'edit':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                $id = $_GET['id'];
                // Validate and get POST data
                $employee_id = isset($_POST['employee_id']) ? $_POST['employee_id'] : null;
                $from_date = $_POST['from_date'];
                $to_date = $_POST['to_date'];
                $reason = $_POST['reason'];
                $status = $_POST['status'];
                $updated_at = date('Y-m-d H:i:s'); // Set current timestamp for `updated_at`

                // Prepare the SQL update statement
                $stmt = $conn->prepare("UPDATE employee_leaves SET employee_id = ?, from_date = ?, to_date = ?, reason = ?, status = ?, updated_at = ? WHERE id = ?");
                $stmt->bind_param("isssssi", $employee_id, $from_date, $to_date, $reason, $status, $updated_at, $id);
    
                // Execute the statement and check for success
                if ($stmt->execute()) {
                    // Query to get the employee's first and last name based on employee_id
                    $employeeStmt = $conn->prepare("SELECT first_name, last_name FROM employees WHERE id = ?");
                    $employeeStmt->bind_param("i", $employee_id);
                    $employeeStmt->execute();
                    $employeeStmt->store_result();

                    // Check if the employee was found
                    if ($employeeStmt->num_rows > 0) {
                        $employeeStmt->bind_result($emp_first_name, $emp_last_name);
                        $employeeStmt->fetch();
                    } else {
                        // Handle the case where the employee is not found
                        $emp_first_name = "Unknown";
                        $emp_last_name = "Unknown";
                    }

                    $updatedEmployeeLeaveData = [
                        'id' => $id,
                        'employee_id' => $employee_id,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'from_date' => $from_date,
                        'to_date' => $to_date,
                        'reason' => $reason,
                        'status' => $status,
                        'updated_at' => $updated_at
                    ];
                    sendJsonResponse('success', $updatedEmployeeLeaveData, 'Leave updated successfully');
                } else {
                    sendJsonResponse('error', null, 'Failed to update employee leave');
                }
                exit;
            } else {
                http_response_code(400);
                sendJsonResponse('error', null, 'Invalid employee leave ID');
                exit;
            }
            break;

        case 'delete':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                // Prepare DELETE statement
                $stmt = $conn->prepare("DELETE FROM employee_leaves WHERE id = ?");
                $stmt->bind_param('i', $_GET['id']);
                if ($stmt->execute()) {
                    sendJsonResponse('success', null, 'Leave deleted successfully');
                } else {
                    http_response_code(500);
                    sendJsonResponse('error', null, 'Failed to delete leave');
                }
                exit;
            } else {
                http_response_code(400);
                sendJsonResponse('error', null, 'Invalid employee leave ID');
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
