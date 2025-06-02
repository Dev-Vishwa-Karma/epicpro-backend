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
            $employee_id = isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0 ? (int)$_GET['user_id'] : null;
            $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
            $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        
            // Validate dates
            if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
                sendJsonResponse('error', null, "Start date cannot be greater than end date.");
                exit;
            }
        
            // Base query
            $query = "
                SELECT 
                    reports.id AS id,
                    reports.employee_id,
                    reports.report,
                    reports.start_time,
                    reports.end_time,
                    reports.break_duration_in_minutes,
                    reports.total_working_hours,
                    reports.total_hours,
                    reports.created_at,
                    reports.created_by,
                    reports.updated_at,
                    reports.updated_by,
                    reports.deleted_at,
                    reports.deleted_by,
                    CONCAT(e.first_name, ' ', e.last_name) AS employee_name
                FROM reports
                LEFT JOIN employees e ON reports.employee_id = e.id
                WHERE 1=1
            ";
        
            // Add filters
            if ($employee_id) {
                $query .= " AND reports.employee_id = $employee_id";
            }
        
            if ($start_date && $end_date) {
                $query .= " AND DATE(reports.created_at) BETWEEN '$start_date' AND '$end_date'";
            } elseif ($start_date) {
                $query .= " AND DATE(reports.created_at) = '$start_date'";
            } elseif ($end_date) {
                $query .= " AND DATE(reports.created_at) = '$end_date'";
            }
        
            $query .= " ORDER BY reports.created_at DESC";
        
            $result = $conn->query($query);
            if ($result && $result->num_rows > 0) {
                $reports = [];
                while ($row = $result->fetch_assoc()) {
                    $id = $row['id'];
                    if (!isset($reports[$id])) {
                        $reports[$id] = [
                            'id' => $row['id'],
                            'employee_id' => $row['employee_id'],
                            'report' => $row['report'],
                            'start_time' => $row['start_time'],
                            'end_time' => $row['end_time'],
                            'break_duration_in_minutes' => $row['break_duration_in_minutes'],
                            'todays_working_hours' => $row['total_working_hours'],
                            'todays_total_hours' => $row['total_hours'],
                            'created_at' => $row['created_at'],
                            'full_name' => $row['employee_name']
                        ];
                    }
                }
                sendJsonResponse('success', array_values($reports), 'Report(s) fetched successfully');
            } else {
                sendJsonResponse('error', [], 'Reports not available');
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
