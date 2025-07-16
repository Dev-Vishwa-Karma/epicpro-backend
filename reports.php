<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");

// Handle preflight (OPTIONS) requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // Respond with 200 status code for OPTIONS requests
    header("HTTP/1.1 200 OK");
    exit();
}

// Include the database connection
include 'db_connection.php';
include 'auth_validate.php';

// Helper function to send JSON response
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

// Helper function to validate user ID
function validateId($id)
{
    return isset($id) && is_numeric($id) && $id > 0;
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';

// Main action handler
if (isset($action)) {
    switch ($action) {
        case 'view':
            $conditions = [];
            $employee_id = (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) ? (int)$_GET['user_id'] : null;
            $start_date = isset($_GET['from_date']) ? $_GET['from_date'] : null;
            $end_date = isset($_GET['to_date']) ? $_GET['to_date'] : null;
        
            if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
                sendJsonResponse('error', null, "Start date cannot be greater than end date.");
                exit;
            }
        
            // Collect conditions
            if ($employee_id) {
                $conditions[] = "reports.employee_id = $employee_id";
            }
            if ($start_date && $end_date) {
                $conditions[] = "DATE(reports.created_at) BETWEEN '$start_date' AND '$end_date'";
            } elseif ($start_date) {
                $conditions[] = "DATE(reports.created_at) = '$start_date'";
            } elseif ($end_date) {
                $conditions[] = "DATE(reports.created_at) = '$end_date'";
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
                    reports.note,
                    reports.created_at,
                    reports.created_by,
                    reports.updated_at,
                    reports.updated_by,
                    reports.deleted_at,
                    reports.deleted_by,
                    CONCAT(e.first_name, ' ', e.last_name) AS employee_name
                FROM reports
                LEFT JOIN employees e ON reports.employee_id = e.id
            ";
        
            // Add WHERE clause only if there are conditions
            if (!empty($conditions)) {
                $query .= ' WHERE ' . implode(' AND ', $conditions);
            }
        
            // Always end with ORDER BY
            $query .= " ORDER BY reports.created_at DESC";
       // var_dump($query);die;
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
                            'note' => $row['note'],
                            'created_at' => $row['created_at'],
                            'full_name' => $row['employee_name'],
                        ];
                    }
                }
                sendJsonResponse('success', array_values($reports), 'Report(s) have been fetched successfully');
            } else {
                sendJsonResponse('error', [], 'Reports not available.');
            }
            break;
        

        case 'add':
            // Capture POST data
            $employee_id = $_POST['employee_id'] ?? null;
            $punch_status = $_POST['punch_status'] ?? null;
            $punch_out_report = $_POST['punch_out_report'] ?? null;
            // date_default_timezone_set('Asia/Kolkata');

            /** Validate */
            if (!$employee_id || !$punch_status) {
                sendJsonResponse('error', null, 'Please provide all required fields');
            }

            if ($punch_status == 'active') {
                $punch_in_time = date('Y-m-d H:i:s');

                // Check if the employee already has an active break
                $stmt = $conn->prepare("SELECT * FROM employee_attendance WHERE employee_id = ? AND status = 'active' LIMIT 1");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    sendJsonResponse('error', null, 'This employee is already punched in.');
                }

                $stmt = $conn->prepare("INSERT INTO employee_attendance (employee_id, punch_in_time, status) VALUES (?, ?, ?)");
                $stmt->bind_param('sss', $employee_id, $punch_in_time, $punch_status);
                if (!$stmt->execute()) {
                    sendJsonResponse('error', null, "Something went wrong while adding the punch details. Please try again.");
                }
                $employee_attendance_id = $stmt->insert_id;
                sendJsonResponse('success', ['user_id' => $employee_attendance_id], 'Punch-in recorded successfully!');
            } elseif ($punch_status == 'completed') {
                $punch_out_time = date('Y-m-d H:i:s');

                // For Break Out, check if there's an active break first
                $stmt = $conn->prepare("SELECT * FROM employee_attendance WHERE employee_id = ? AND status = 'active' LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    // If there's an active punch, record the punch out time
                    $currentTime = date('Y-m-d H:i:s');
                    $updateStmt = $conn->prepare("UPDATE employee_attendance SET punch_out_time = ?, report = ?, status = 'completed' WHERE employee_id = ? AND status = 'active'");
                    $updateStmt->bind_param('ssi', $currentTime, $punch_out_report, $employee_id);
                    $updateStmt->execute();
                    // Respond with success
                    sendJsonResponse('success', null, 'Punch-out completed successfully!');
                } else {
                    // No active punch found
                    sendJsonResponse('error', null, 'No active punch-in record found for this employee.');
                }
            } else {
                // Respond with error if the user is not an admin
                sendJsonResponse('error', null, 'You do not have the required permissions to perform this action');
            }

            break;

        case 'get_punch_status':
            if (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) {

                $stmt = $conn->prepare("SELECT * FROM employee_attendance WHERE employee_id = ? AND status = 'active' LIMIT 1");
                $stmt->bind_param("i", $_GET['user_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    sendJsonResponse('success', null, 'This employee is already punched in.');
                } else {
                    sendJsonResponse('error', null, 'This employee is not punched in.');
                }
            }
            break;
        
            case 'add-report-by-user':
                // Capture POST data
                $employee_id = $_POST['employee_id'] ?? null;
                $report = $_POST['report'] ?? null;
                $start_time = $_POST['start_time'] ?? null;
                $break_duration_in_minutes = $_POST['break_duration_in_minutes'] ?? null;
                $end_time = $_POST['end_time'] ?? null;
                $todays_working_hours = $_POST['todays_working_hours'] ?? null;
                $todays_total_hours = $_POST['todays_total_hours'] ?? null;
                $created_at = date('Y-m-d H:i:s');
                $updated_at = $created_at;
            
                // Validate the data (you can add additional validation as needed)
                if (empty($employee_id) || empty($report) || empty($start_time) || empty($end_time)) {
                    sendJsonResponse('error', null, "All fields are required");
                    exit;
                }

                // Escape the report field to handle special characters (like apostrophes)
                $report = $conn->real_escape_string($report);
            
                // Prepare the SQL query string directly
                $sql = "INSERT INTO reports (employee_id, report, start_time, end_time, break_duration_in_minutes, total_working_hours, total_hours, created_at, updated_at) 
                        VALUES ('$employee_id', '$report', '$start_time', '$end_time', '$break_duration_in_minutes', '$todays_working_hours', '$todays_total_hours', '$created_at', '$updated_at')";
            
                // Execute the query
                if ($conn->query($sql) === TRUE) {
                    $id = $conn->insert_id;
            
                    $reportsData = [
                        'id' => $id,
                        'employee_id' => $employee_id,
                        'report' => $report,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'break_duration_in_minutes' => $break_duration_in_minutes,
                        'total_working_hours' => $todays_working_hours,
                        'total_hours' => $todays_total_hours,
                        'created_at' => $created_at
                    ];
                    // If successful, send success response
                    sendJsonResponse('success', $reportsData, "Report has been submitted successfully.");
                } else {
                    sendJsonResponse('error', null, "Failed to submit report: " . $conn->error);
                }
            
                break;
            
        case 'update-report-by-user';
            // Capture POST data
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                $id = $_GET['id'];
                // Validate and get POST data
                $note = $_POST['note'] ? $_POST['note'] : null;
                $report = $_POST['report'];
                $start_time = $_POST['start_time'];
                $end_time = $_POST['end_time'];
                $break_duration_in_minutes = $_POST['break_duration_in_minutes'];
                $todays_working_hours = $_POST['todays_working_hours'];
                $todays_total_hours = $_POST['todays_total_hours'];
                $updated_at = date('Y-m-d H:i:s');

                // Prepare the SQL update statement
                $stmt = $conn->prepare("UPDATE reports SET note = ?, report = ?, start_time = ?, end_time = ?, break_duration_in_minutes = ?, total_working_hours = ?, total_hours = ?, updated_at = ? WHERE id = ?");

                if (!$stmt) {
                    sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
                    exit;
                }

                $stmt->bind_param("ssssssssi", $note, $report, $start_time, $end_time, $break_duration_in_minutes, $todays_working_hours, $todays_total_hours, $updated_at, $id);

                // Execute the statement and check for success
                if ($stmt->execute()) {

                    $updatedReportData = [
                        'id' => $id,
                        'employee_id' => $employee_id,
                        'note' => $note,
                        'report' => $report,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'break_duration_in_minutes' => $break_duration_in_minutes,
                        'todays_working_hours' => $todays_working_hours,
                        'todays_hours' => $todays_total_hours,
                        'updated_at' => $updated_at
                    ];
                    sendJsonResponse('success', $updatedReportData, 'Report has been updated successfully.');
                } else {
                    sendJsonResponse('error', null, 'Failed to update report'. $stmt->error);
                }
                exit;
            } else {
                http_response_code(400);
                sendJsonResponse('error', null, 'Invalid Report ID');
                exit;
            }
            break;

        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
} else {
    sendJsonResponse('error', null, 'Action parameter is missing');
}
