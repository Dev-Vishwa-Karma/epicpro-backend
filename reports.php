<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");

// Include the database connection
include 'db_connection.php';

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

// Helper function to validate date format
function validateDate($date)
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';

// Main action handler
if (isset($action)) {
    switch ($action) {
        case 'view':
            try {
                if (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) {
                    $employee_id = $_GET['user_id'];
                    $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : null;
                    $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : null;

                    // Validate dates if provided
                    if ($from_date && !validateDate($from_date)) {
                        sendJsonResponse('error', null, 'Invalid from date format');
                        exit;
                    }
                    if ($to_date && !validateDate($to_date)) {
                        sendJsonResponse('error', null, 'Invalid to date format');
                        exit;
                    }

                    // Debug log
                    error_log("Filter Parameters - User ID: $employee_id, From Date: $from_date, To Date: $to_date");

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
                        WHERE reports.employee_id = ?
                    ";

                    // Add date range conditions if provided
                    if ($from_date) {
                        $query .= " AND DATE(reports.created_at) >= ?";
                    }
                    if ($to_date) {
                        $query .= " AND DATE(reports.created_at) <= ?";
                    }

                    $query .= " ORDER BY reports.created_at DESC";

                    // Debug log
                    error_log("Query: $query");

                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        error_log("Prepare failed: " . $conn->error);
                        sendJsonResponse('error', null, 'Database error: ' . $conn->error);
                        exit;
                    }
                    
                    // Bind parameters based on what's provided
                    if ($from_date && $to_date) {
                        $stmt->bind_param("iss", $employee_id, $from_date, $to_date);
                    } elseif ($from_date) {
                        $stmt->bind_param("is", $employee_id, $from_date);
                    } elseif ($to_date) {
                        $stmt->bind_param("is", $employee_id, $to_date);
                    } else {
                        $stmt->bind_param("i", $employee_id);
                    }

                    if (!$stmt->execute()) {
                        error_log("Execute failed: " . $stmt->error);
                        sendJsonResponse('error', null, 'Query execution failed: ' . $stmt->error);
                        exit;
                    }

                    $result = $stmt->get_result();
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
                                    'full_name' => $row['employee_name'],
                                ];
                            }
                        }
                        error_log("Found " . count($reports) . " reports");
                        sendJsonResponse('success', array_values($reports), 'Report has been fetched successfully');
                    } else {
                        error_log("No reports found");
                        sendJsonResponse('success', [], 'No reports found for the selected criteria');
                    }
                } else {
                    // Handle case for all reports (admin view)
                    $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : null;
                    $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : null;
                    $employee_id = isset($_GET['employee_id']) ? $_GET['employee_id'] : null;

                    // Validate dates if provided
                    if ($from_date && !validateDate($from_date)) {
                        sendJsonResponse('error', null, 'Invalid from date format');
                        exit;
                    }
                    if ($to_date && !validateDate($to_date)) {
                        sendJsonResponse('error', null, 'Invalid to date format');
                        exit;
                    }

                    // Debug log
                    error_log("Filter Parameters - From Date: $from_date, To Date: $to_date, Employee ID: $employee_id");

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

                    // Add employee filter if provided
                    if ($employee_id) {
                        $query .= " AND reports.employee_id = ?";
                    }

                    // Add date range conditions if provided
                    if ($from_date) {
                        $query .= " AND DATE(reports.created_at) >= ?";
                    }
                    if ($to_date) {
                        $query .= " AND DATE(reports.created_at) <= ?";
                    }

                    $query .= " ORDER BY reports.created_at DESC";

                    // Debug log
                    error_log("Query: $query");

                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        error_log("Prepare failed: " . $conn->error);
                        sendJsonResponse('error', null, 'Database error: ' . $conn->error);
                        exit;
                    }

                    // Bind parameters based on what's provided
                    if ($employee_id && $from_date && $to_date) {
                        $stmt->bind_param("iss", $employee_id, $from_date, $to_date);
                    } elseif ($employee_id && $from_date) {
                        $stmt->bind_param("is", $employee_id, $from_date);
                    } elseif ($employee_id && $to_date) {
                        $stmt->bind_param("is", $employee_id, $to_date);
                    } elseif ($employee_id) {
                        $stmt->bind_param("i", $employee_id);
                    } elseif ($from_date && $to_date) {
                        $stmt->bind_param("ss", $from_date, $to_date);
                    } elseif ($from_date) {
                        $stmt->bind_param("s", $from_date);
                    } elseif ($to_date) {
                        $stmt->bind_param("s", $to_date);
                    }

                    if (!$stmt->execute()) {
                        error_log("Execute failed: " . $stmt->error);
                        sendJsonResponse('error', null, 'Query execution failed: ' . $stmt->error);
                        exit;
                    }

                    $result = $stmt->get_result();
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
                        error_log("Found " . count($reports) . " reports");
                        sendJsonResponse('success', array_values($reports), 'Reports have been fetched successfully');
                    } else {
                        error_log("No reports found");
                        sendJsonResponse('success', [], 'No reports found for the selected criteria');
                    }
                }
            } catch (Exception $e) {
                error_log("Exception: " . $e->getMessage());
                sendJsonResponse('error', null, 'An error occurred: ' . $e->getMessage());
            }
            break;

        case 'add-report-by-user':
            try {
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

                // Validate the data
                if (empty($employee_id) || empty($report) || empty($start_time) || empty($end_time)) {
                    sendJsonResponse('error', null, "All fields are required");
                    exit;
                }

                // Prepare the insert query
                $stmt = $conn->prepare("INSERT INTO reports (employee_id, report, start_time, end_time, break_duration_in_minutes, total_working_hours, total_hours, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                if (!$stmt) {
                    error_log("Prepare failed: " . $conn->error);
                    sendJsonResponse('error', null, 'Database error: ' . $conn->error);
                    exit;
                }

                // Bind the parameters
                $stmt->bind_param("issssssss", $employee_id, $report, $start_time, $end_time, $break_duration_in_minutes, $todays_working_hours, $todays_total_hours, $created_at, $updated_at);

                // Execute the query
                if ($stmt->execute()) {
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
                    sendJsonResponse('success', $reportsData, "Report has been submitted successfully.");
                } else {
                    error_log("Execute failed: " . $stmt->error);
                    sendJsonResponse('error', null, "Failed to submit report: " . $stmt->error);
                }
            } catch (Exception $e) {
                error_log("Exception: " . $e->getMessage());
                sendJsonResponse('error', null, 'An error occurred: ' . $e->getMessage());
            }
            break;

        case 'update-report-by-user':
            try {
                if (isset($_GET['report_id']) && is_numeric($_GET['report_id']) && $_GET['report_id'] > 0) {
                    $id = $_GET['report_id'];
                    // Validate and get POST data
                    $report = $_POST['report'];
                    $start_time = $_POST['start_time'];
                    $end_time = $_POST['end_time'];
                    $break_duration_in_minutes = $_POST['break_duration_in_minutes'];
                    $todays_working_hours = $_POST['todays_working_hours'];
                    $todays_total_hours = $_POST['todays_total_hours'];
                    $updated_at = date('Y-m-d H:i:s');

                    // Prepare the SQL update statement
                    $stmt = $conn->prepare("UPDATE reports SET report = ?, start_time = ?, end_time = ?, break_duration_in_minutes = ?, total_working_hours = ?, total_hours = ?, updated_at = ? WHERE id = ?");

                    if (!$stmt) {
                        error_log("Prepare failed: " . $conn->error);
                        sendJsonResponse('error', null, 'Database error: ' . $conn->error);
                        exit;
                    }

                    $stmt->bind_param("sssssssi", $report, $start_time, $end_time, $break_duration_in_minutes, $todays_working_hours, $todays_total_hours, $updated_at, $id);

                    // Execute the statement and check for success
                    if ($stmt->execute()) {
                        $updatedReportData = [
                            'id' => $id,
                            'start_time' => $start_time,
                            'end_time' => $end_time,
                            'break_duration_in_minutes' => $break_duration_in_minutes,
                            'todays_working_hours' => $todays_working_hours,
                            'todays_total_hours' => $todays_total_hours,
                            'updated_at' => $updated_at
                        ];
                        sendJsonResponse('success', $updatedReportData, 'Report has been updated successfully.');
                    } else {
                        error_log("Execute failed: " . $stmt->error);
                        sendJsonResponse('error', null, 'Failed to update report: ' . $stmt->error);
                    }
                } else {
                    sendJsonResponse('error', null, 'Invalid Report ID');
                }
            } catch (Exception $e) {
                error_log("Exception: " . $e->getMessage());
                sendJsonResponse('error', null, 'An error occurred: ' . $e->getMessage());
            }
            break;

        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
} else {
    sendJsonResponse('error', null, 'Action parameter is missing');
}