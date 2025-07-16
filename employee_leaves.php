<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include the database connection
include 'db_connection.php';
include 'auth_validate.php';

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
                $employee_id = (int)$_GET['employee_id'];
                $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
                $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
                $status = isset($_GET['status']) ? $_GET['status'] : null;

                if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
                    sendJsonResponse('error', null, "Start date cannot be greater than end date.");
                    exit;
                }

                $query = "SELECT 
                    employee_leaves.id,
                    employee_leaves.employee_id, 
                    employee_leaves.from_date, 
                    employee_leaves.to_date,
                    employee_leaves.reason, 
                    employee_leaves.status,
                    employee_leaves.is_half_day,
                    employee_leaves.approved_by,
                    employee_leaves.created_at, 
                    employees.first_name, 
                    employees.last_name, 
                    employees.email
                FROM employee_leaves
                INNER JOIN employees ON employee_leaves.employee_id = employees.id
                WHERE employee_leaves.employee_id = $employee_id";

                // Status filter
                if ($status && in_array($status, ['pending', 'cancelled', 'approved', 'rejected'])) {
                    $query .= " AND employee_leaves.status = '$status'";
                }

                // Date filters
                if ($start_date && $end_date) {
                    $query .= " AND DATE(employee_leaves.from_date) <= '$end_date' AND DATE(employee_leaves.to_date) >= '$start_date'";
                } elseif ($start_date) {
                    $query .= " AND DATE(employee_leaves.from_date) <= '$start_date' AND DATE(employee_leaves.to_date) >= '$start_date'";
                } elseif ($end_date) {
                    $query .= " AND DATE(employee_leaves.from_date) <= '$end_date' AND DATE(employee_leaves.to_date) >= '$end_date'";
                }

                $result = $conn->query($query);

                if ($result && $result->num_rows > 0) {
                    $employee_leaves = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $employee_leaves);
                } else {
                    sendJsonResponse('error', null, "No leaves found for this employee.");
                }

            } else {
                // Return all records if no specific employee ID is given
                $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
                $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
                $status = isset($_GET['status']) ? $_GET['status'] : null;

                $query = "SELECT 
                    employee_leaves.id,
                    employee_leaves.employee_id, 
                    employee_leaves.from_date, 
                    employee_leaves.to_date,
                    employee_leaves.reason, 
                    employee_leaves.status,
                    employee_leaves.is_half_day,
                    employee_leaves.approved_by,
                    employee_leaves.created_at, 
                    employees.first_name, 
                    employees.last_name, 
                    employees.email
                FROM employee_leaves
                INNER JOIN employees ON employee_leaves.employee_id = employees.id";

                // Status filter
                if ($status && in_array($status, ['pending', 'cancelled', 'approved', 'rejected'])) {
                    $query .= " WHERE employee_leaves.status = '$status'";
                }

                // Date filters (added to the else block)
                if ($start_date && $end_date) {
                    $query .= (strpos($query, 'WHERE') !== false ? " AND" : " WHERE") . " DATE(employee_leaves.from_date) <= '$end_date' AND DATE(employee_leaves.to_date) >= '$start_date'";
                } elseif ($start_date) {
                    $query .= (strpos($query, 'WHERE') !== false ? " AND" : " WHERE") . " DATE(employee_leaves.from_date) <= '$start_date' AND DATE(employee_leaves.to_date) >= '$start_date'";
                } elseif ($end_date) {
                    $query .= (strpos($query, 'WHERE') !== false ? " AND" : " WHERE") . " DATE(employee_leaves.from_date) <= '$end_date' AND DATE(employee_leaves.to_date) >= '$end_date'";
                }

        
                $result = $conn->query($query);

                if ($result) {
                    $employee_leaves = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $employee_leaves);
                } else {
                    sendJsonResponse('error', null, "No records found: " . $conn->error);
                }
            }
            break;
        case 'add':
            // Get form data
            $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
            $from_date = $_POST['from_date'];
            $to_date = $_POST['to_date'];
            $reason = $_POST['reason'];
            $status = $_POST['status'];
            $is_half_day = isset($_POST['is_half_day']) ? (int)$_POST['is_half_day'] : 0;

            // Validate the data
            if (empty($from_date) || empty($to_date) || empty($reason) || empty($status)) {
                echo json_encode(['error' => 'All fields are required.']);
                exit;
            }

            // Build and execute the insert query
            $insertSql = "INSERT INTO employee_leaves (employee_id, from_date, to_date, reason, status, is_half_day) 
                        VALUES ($employee_id, '$from_date', '$to_date', '$reason', '$status', $is_half_day)";

            if ($conn->query($insertSql)) {
                $id = $conn->insert_id;

                // Fetch employee name
                $empSql = "SELECT first_name, last_name FROM employees WHERE id = $employee_id";
                $empResult = $conn->query($empSql);

                if ($empResult && $empResult->num_rows > 0) {
                    $empRow = $empResult->fetch_assoc();
                    $emp_first_name = $empRow['first_name'];
                    $emp_last_name = $empRow['last_name'];
                } else {
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
                    'is_half_day' => $is_half_day
                ];

                sendJsonResponse('success', $addEmployeeLeaveData, "Leave added successfully");
            } else {
                sendJsonResponse('error', null, "Failed to add leave: " . $conn->error);
            }
            break;

        case 'edit':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                $id = (int)$_GET['id'];
            
                // Check if record exists
                $checkSql = "SELECT id FROM employee_leaves WHERE id = $id";
                $checkResult = $conn->query($checkSql);
            
                if ($checkResult && $checkResult->num_rows > 0) {
                    // Get POST data
                    $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
                    $from_date = $_POST['from_date'];
                    $to_date = $_POST['to_date'];
                    $reason = $_POST['reason'];
                    $status = $_POST['status'];
                    $is_half_day = isset($_POST['is_half_day']) ? (int)$_POST['is_half_day'] : 0;
                    $updated_at = date('Y-m-d H:i:s');
            
                    // Update query
                    $updateSql = "UPDATE employee_leaves SET 
                        employee_id = $employee_id, 
                        from_date = '$from_date', 
                        to_date = '$to_date', 
                        reason = '$reason', 
                        status = '$status', 
                        is_half_day = $is_half_day,
                        updated_at = '$updated_at' 
                        WHERE id = $id";
            
                    if ($conn->query($updateSql)) {
                        // Fetch employee name
                        $empSql = "SELECT first_name, last_name FROM employees WHERE id = $employee_id";
                        $empResult = $conn->query($empSql);
            
                        if ($empResult && $empResult->num_rows > 0) {
                            $empRow = $empResult->fetch_assoc();
                            $emp_first_name = $empRow['first_name'];
                            $emp_last_name = $empRow['last_name'];
                        } else {
                            $emp_first_name = "Unknown";
                            $emp_last_name = "Unknown";
                        }
            
                        $updatedEmployeeLeaveData = [
                            'id' => $id,
                            'employee_id' => $employee_id,
                            'first_name' => $emp_first_name,
                            'last_name' => $emp_last_name,
                            'from_date' => $from_date,
                            'to_date' => $to_date,
                            'reason' => $reason,
                            'status' => $status,
                            'is_half_day' => $is_half_day,
                            'updated_at' => $updated_at
                        ];
            
                        sendJsonResponse('success', $updatedEmployeeLeaveData, 'Leave updated successfully');
                    } else {
                        sendJsonResponse('error', null, 'Failed to update employee leave');
                    }
                } else {
                    sendJsonResponse('error', null, 'Employee leave record not found');
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
                $id = (int)$_GET['id'];
            
                // Direct delete query
                $deleteSql = "DELETE FROM employee_leaves WHERE id = $id";
                if ($conn->query($deleteSql)) {
                    sendJsonResponse('success', null, 'Leave deleted successfully');
                } else {
                    http_response_code(500);
                    sendJsonResponse('error', null, 'Failed to delete leave: ' . $conn->error);
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
