<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");

// Handle preflight (OPTIONS) requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit();
}

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

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';

// Main action handler
if (isset($action)) {
    switch ($action) {
        case 'view':
            if (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) {
                $stmt_activities = $conn->prepare("
            SELECT 
                e.id AS employee_id,
                e.first_name AS first_name,
                e.last_name AS last_name,
                e.address_line1 AS location,
                ea.activity_type AS activity_type,
                ea.description AS description,
                ea.status AS status,
                ea.created_by AS created_by,
                ea.updated_by AS updated_by,
                CONCAT(e.first_name, ' ', e.last_name) AS full_name,
                ea.in_time as complete_in_time,
                ea.out_time as complete_out_time,
                ea.id as activity_id,
                CASE 
                WHEN DATE(ea.in_time) = CURDATE() 
                    THEN CONCAT(DATE_FORMAT(ea.in_time, '%H:%i'), ' - Today')
                WHEN DATE(ea.in_time) = CURDATE() - INTERVAL 1 DAY 
                    THEN CONCAT(DATE_FORMAT(ea.in_time, '%H:%i'), ' - Yesterday')
                ELSE DATE_FORMAT(ea.in_time, '%d-%M-%Y %h:%i %p')
                END AS in_time,

                CASE 
                WHEN DATE(ea.out_time) = CURDATE() 
                    THEN CONCAT(DATE_FORMAT(ea.out_time, '%H:%i'), ' - Today')
                WHEN DATE(ea.out_time) = CURDATE() - INTERVAL 1 DAY 
                    THEN CONCAT(DATE_FORMAT(ea.out_time, '%H:%i'), ' - Yesterday')
                ELSE DATE_FORMAT(ea.out_time, '%d-%M-%Y %h:%i %p')
                END AS out_time,

                CASE 
                WHEN TIMESTAMPDIFF(SECOND, ea.in_time, ea.out_time) < 60 
                    THEN CONCAT(TIMESTAMPDIFF(SECOND, ea.in_time, ea.out_time), ' seconds')
                WHEN TIMESTAMPDIFF(MINUTE, ea.in_time, ea.out_time) < 60 
                    THEN CONCAT(TIMESTAMPDIFF(MINUTE, ea.in_time, ea.out_time), ' minutes')
                ELSE 
                    CONCAT(
                        FLOOR(TIMESTAMPDIFF(SECOND, ea.in_time, ea.out_time) / 3600), ' hours ',
                        MOD(FLOOR(TIMESTAMPDIFF(SECOND, ea.in_time, ea.out_time) / 60), 60), ' minutes'
                    )
                END AS duration,
                
                ea.description
            FROM activities ea
            JOIN employees e ON ea.employee_id = e.id
            WHERE ea.deleted_at IS NULL AND ea.employee_id = ?
            ORDER BY ea.in_time DESC
            ");
                $stmt_activities->bind_param('i', $_GET['user_id']);
                $stmt_activities->execute();
                $result = $stmt_activities->get_result();

                if ($result->num_rows > 0) {
                    $activities = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $activities);
                } else {
                    sendJsonResponse('error', null, 'No records found');
                }
            } else {
                // If no user_id provided, fetch all users
                $stmt_activities = $conn->prepare("
            SELECT 
                e.id AS employee_id,
                e.first_name AS first_name,
                e.last_name AS last_name,
                e.address_line1 AS location,
                ea.activity_type AS activity_type,
                ea.description AS description,
                ea.status AS status,
                ea.created_by AS created_by,
                ea.updated_by AS updated_by,
                CONCAT(e.first_name, ' ', e.last_name) AS full_name,
                ea.in_time as complete_in_time,
                ea.out_time as complete_out_time,
                ea.id as activity_id,
                CASE 
                WHEN DATE(ea.in_time) = CURDATE() 
                    THEN CONCAT(DATE_FORMAT(ea.in_time, '%H:%i'), ' - Today')
                WHEN DATE(ea.in_time) = CURDATE() - INTERVAL 1 DAY 
                    THEN CONCAT(DATE_FORMAT(ea.in_time, '%H:%i'), ' - Yesterday')
                ELSE DATE_FORMAT(ea.in_time, '%d-%M-%Y %h:%i %p')
                END AS in_time,

                CASE 
                WHEN DATE(ea.out_time) = CURDATE() 
                    THEN CONCAT(DATE_FORMAT(ea.out_time, '%H:%i'), ' - Today')
                WHEN DATE(ea.out_time) = CURDATE() - INTERVAL 1 DAY 
                    THEN CONCAT(DATE_FORMAT(ea.out_time, '%H:%i'), ' - Yesterday')
                ELSE DATE_FORMAT(ea.out_time, '%d-%M-%Y %h:%i %p')
                END AS out_time,

                CASE 
                WHEN TIMESTAMPDIFF(SECOND, ea.in_time, ea.out_time) < 60 
                    THEN CONCAT(TIMESTAMPDIFF(SECOND, ea.in_time, ea.out_time), ' seconds')
                WHEN TIMESTAMPDIFF(MINUTE, ea.in_time, ea.out_time) < 60 
                    THEN CONCAT(TIMESTAMPDIFF(MINUTE, ea.in_time, ea.out_time), ' minutes')
                ELSE 
                    CONCAT(
                        FLOOR(TIMESTAMPDIFF(SECOND, ea.in_time, ea.out_time) / 3600), ' hours ',
                        MOD(FLOOR(TIMESTAMPDIFF(SECOND, ea.in_time, ea.out_time) / 60), 60), ' minutes'
                    )
                END AS duration,
                
                ea.description
            FROM activities ea
            JOIN employees e ON ea.employee_id = e.id
            WHERE ea.deleted_at IS NULL
            ORDER BY ea.in_time DESC
            ");
                $stmt_activities->execute();
                $result = $stmt_activities->get_result();

                if ($result->num_rows > 0) {
                    $activities = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $activities);
                } else {
                    sendJsonResponse('error', null, 'No records found');
                }
            }
            break;

        case 'add-by-admin':
            // Capture POST data
            $employee_id = $_POST['employee_id'] ?? null; // required
            $activity_type = $_POST['activity_type'] ?? null; //required
            $description = $_POST['description'] ?? null;
            $status = $_POST['status'] ?? null;
            $created_by = $_POST['created_by'] ?? null;
            $updated_by = $_POST['updated_by'] ?? null;
            date_default_timezone_set('Asia/Kolkata');

            /** Validate */
            if (!$employee_id) {
                sendJsonResponse('error', null, 'Employee Id is required');
            }
            if (!$status) {
                sendJsonResponse('error', null, 'Status is required');
            }

            if ($activity_type == 'Break' && $status == 'active') {

                // Check if the employee has clocked in today
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows == 0) {
                    sendJsonResponse('error', null, 'The employee has not recorded their punch in for today.');
                } else {
                    $row = $result->fetch_assoc();
                    if ($row['status'] == 'completed') {
                        sendJsonResponse('error', null, 'The employee has already punch out for today.');
                    }
                }
                // Check if the employee has clocked in today end

                $in_time = date('Y-m-d H:i:s');

                // Check if the employee already has an active break
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    sendJsonResponse('error', null, 'The employee is already on an active break');
                }

                $stmt = $conn->prepare("INSERT INTO activities (employee_id, activity_type, description, in_time, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('issssi', $employee_id, $activity_type, $description, $in_time, $status, $created_by);
                if (!$stmt->execute()) {
                    sendJsonResponse('error', null, "Something went wrong while adding the break details. Please try again.");
                }
                $employee_activity_id = $stmt->insert_id;
                sendJsonResponse('success', ['user_id' => $employee_activity_id], 'Break has been started!');
            } elseif ($activity_type == 'Break' && $status == 'completed') {

                // Check if the employee has clocked in today
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows == 0) {
                    sendJsonResponse('error', null, 'The employee has not recorded their punch in for today.');
                } else {
                    $row = $result->fetch_assoc();
                    if ($row['status'] == 'completed') {
                        sendJsonResponse('error', null, 'The employee has already punch out for today.');
                    }
                }

                $out_time = date('Y-m-d H:i:s');

                // For Break Out, check if there's an active break first
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    // If there's an active break, record the break out time
                    $currentTime = date('Y-m-d H:i:s');
                    $updateStmt = $conn->prepare("UPDATE activities SET out_time = ?, status = 'completed', updated_by = ? WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL");
                    $updateStmt->bind_param('ssi', $currentTime, $updated_by, $employee_id);
                    $updateStmt->execute();
                    // Respond with success
                    sendJsonResponse('success', null, 'Break has been completed!');
                } else {
                    // No active break found
                    sendJsonResponse('error', null, 'No active break is currently recorded for this employee.');
                }
            } 
            elseif ($activity_type == 'Punch' && $status == 'active') {
                $punch_in_time = date('Y-m-d H:i:s');

                // Check if the employee already has an active break
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    if ($row['status'] == 'active') {
                        sendJsonResponse('error', null, 'This Employee already punched in for today.');
                    } elseif ($row['status'] == 'completed') {
                        sendJsonResponse('error', null, 'This Employee already punched out for today.');
                    }
                }

                $stmt = $conn->prepare("INSERT INTO activities (employee_id, activity_type, in_time, status, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('issss', $employee_id, $activity_type, $punch_in_time, $status, $created_by);
                if (!$stmt->execute()) {
                    sendJsonResponse('error', null, "Something went wrong while adding the punch in details. Please try again.");
                }
                $employee_activity_id = $stmt->insert_id;
                sendJsonResponse('success', ['user_id' => $employee_activity_id], 'The punch-in has been successfully recorded!');
            }
            elseif ($activity_type == 'Punch' && $status == 'completed') {

                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    if ($row['status'] == 'active') {
                        // If there's an active punch, record the punch out time
                        $currentTime = date('Y-m-d H:i:s');
                        $updateStmt = $conn->prepare("UPDATE activities SET out_time = ?, description = ?, status = 'completed', updated_by = ? WHERE employee_id = ? AND status = 'active' AND activity_type = 'Punch' AND deleted_at IS NULL");
                        $updateStmt->bind_param('sssi', $currentTime, $description, $updated_by, $employee_id);
                        $updateStmt->execute();

                        // need to close any active break if any
                        $getActiveBreakQuery = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL LIMIT 1");
                        $getActiveBreakQuery->bind_param('i', $employee_id);
                        $getActiveBreakQuery->execute();
                        $getActiveBreakQueryResult = $getActiveBreakQuery->get_result();
                        if ($getActiveBreakQueryResult->num_rows > 0) {
                            // If there's an active break, record the break out time
                            $updateStmt = $conn->prepare("UPDATE activities SET out_time = ?, status = 'completed' WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL");
                            $updateStmt->bind_param('si', $currentTime, $employee_id);
                            $updateStmt->execute();
                        }
                        // end: need to close any active break if any

                        // Respond with success
                        sendJsonResponse('success', null, 'The punch-out has been successfully recorded.');
                    } elseif ($row['status'] == 'completed') {
                        sendJsonResponse('error', null, 'This Employee already punched out for today.');
                    }
                } else {
                    // No active punch found
                    sendJsonResponse('error', null, 'No active punch-in record found for this employee');
                }
            }
            else {
                // Respond with error if the user is not an admin
                sendJsonResponse('error', null, 'You do not have the required permissions to perform this action');
            }
            break;

        case 'get_break_status':
            if (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) {

                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param("i", $_GET['user_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    sendJsonResponse('success', null, 'This employee is already break in.');
                } else {
                    sendJsonResponse('error', null, 'This employee is not breaked in.');
                }
            }
            break;

        case 'add-by-user':
            // Capture POST data
            $employee_id = $_POST['employee_id'] ?? null; // required
            $activity_type = $_POST['activity_type'] ?? null; //required
            $description = $_POST['description'] ?? null;
            $status = $_POST['status'] ?? null;
            date_default_timezone_set('Asia/Kolkata');

            /** Validate */
            if (!$employee_id) {
                sendJsonResponse('error', null, 'Employee Id is required');
            }
            if (!$status) {
                sendJsonResponse('error', null, 'Status is required');
            }

            if ($activity_type == 'Break' && $status == 'active') {

                // Check if the employee has clocked in today
                /* $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows == 0) {
                    sendJsonResponse('error', null, 'The employee has not recorded their punch in for today.');
                } else {
                    $row = $result->fetch_assoc();
                    if ($row['status'] == 'completed') {
                        sendJsonResponse('error', null, 'The employee has already punch out for today.');
                    }
                } */

                $in_time = date('Y-m-d H:i:s');

                // Check if the employee already has an active break
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    sendJsonResponse('error', null, 'The employee is already on an active break');
                }

                $stmt = $conn->prepare("INSERT INTO activities (employee_id, activity_type, description, in_time, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('issss', $employee_id, $activity_type, $description, $in_time, $status);
                if (!$stmt->execute()) {
                    sendJsonResponse('error', null, "Something went wrong while adding the break details. Please try again.");
                }
                $employee_activity_id = $stmt->insert_id;
                sendJsonResponse('success', ['user_id' => $employee_activity_id], 'Break has been started!');
            } elseif ($activity_type == 'Break' && $status == 'completed') {

                // Check if the employee has clocked in today
                /* $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows == 0) {
                    sendJsonResponse('error', null, 'The employee has not recorded their punch in for today.');
                } else {
                    $row = $result->fetch_assoc();
                    if ($row['status'] == 'completed') {
                        sendJsonResponse('error', null, 'The employee has already punch out for today.');
                    }
                } */

                $out_time = date('Y-m-d H:i:s');

                // For Break Out, check if there's an active break first
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    // If there's an active break, record the break out time
                    $currentTime = date('Y-m-d H:i:s');
                    $updateStmt = $conn->prepare("UPDATE activities SET out_time = ?, status = 'completed' WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL");
                    $updateStmt->bind_param('si', $currentTime, $employee_id);
                    $updateStmt->execute();
                    // Respond with success
                    sendJsonResponse('success', null, 'Break has been completed!');
                } else {
                    // No active break found
                    sendJsonResponse('error', null, 'No active break is currently recorded for this employee.');
                }
            } elseif ($activity_type == 'Punch' && $status == 'active') {
                $punch_in_time = date('Y-m-d H:i:s');

                // Check if the employee already has an active break
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    if ($row['status'] == 'active') {
                        sendJsonResponse('error', null, 'You already punched in for today.');
                    } elseif ($row['status'] == 'completed') {
                        sendJsonResponse('error', null, 'You already punched out for today.');
                    }
                }

                $stmt = $conn->prepare("INSERT INTO activities (employee_id, activity_type, in_time, status) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('isss', $employee_id, $activity_type, $punch_in_time, $status);
                if (!$stmt->execute()) {
                    sendJsonResponse('error', null, "Something went wrong while adding the punch in details. Please try again.");
                }
                $employee_activity_id = $stmt->insert_id;
                sendJsonResponse('success', ['user_id' => $employee_activity_id], 'The punch-in has been successfully recorded!');
            } elseif ($activity_type == 'Punch' && $status == 'completed') {

                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    if ($row['status'] == 'active') {
                        // If there's an active punch, record the punch out time
                        $currentTime = date('Y-m-d H:i:s');
                        $updateStmt = $conn->prepare("UPDATE activities SET out_time = ?, description = ?, status = 'completed' WHERE employee_id = ? AND status = 'active' AND activity_type = 'Punch' AND deleted_at IS NULL");
                        $updateStmt->bind_param('ssi', $currentTime, $description, $employee_id);
                        $updateStmt->execute();

                        // need to close any active break if any
                        $getActiveBreakQuery = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL LIMIT 1");
                        $getActiveBreakQuery->bind_param('i', $employee_id);
                        $getActiveBreakQuery->execute();
                        $getActiveBreakQueryResult = $getActiveBreakQuery->get_result();
                        if ($getActiveBreakQueryResult->num_rows > 0) {
                            // If there's an active break, record the break out time
                            $updateStmt = $conn->prepare("UPDATE activities SET out_time = ?, status = 'completed' WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL");
                            $updateStmt->bind_param('si', $currentTime, $employee_id);
                            $updateStmt->execute();
                        }
                        // end: need to close any active break if any

                        // Respond with success
                        sendJsonResponse('success', null, 'The punch-out has been successfully recorded.');
                    } elseif ($row['status'] == 'completed') {
                        sendJsonResponse('error', null, 'You already punched out for today.');
                    }
                } else {
                    // No active punch found
                    sendJsonResponse('error', null, 'No active punch-in record found for this employee');
                }
            } else {
                // Respond with error if the user is not an admin
                sendJsonResponse('error', null, 'You do not have the required permissions to perform this action');
            }
            break;

        case 'get_punch_status':
            if (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) {

                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND status = 'active' AND activity_type = 'Punch' AND deleted_at IS NULL LIMIT 1");
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

        case 'delete':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {

                $currentTime = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE activities SET deleted_at = ?, deleted_by = ? WHERE id = ?");
                $stmt->bind_param("sii", $currentTime, $_GET['user_id'], $_GET['id']);
                if (!$stmt->execute()) {
                    sendJsonResponse('error', null, 'Something went wrong while deleting the record. Please try again.');
                } else {
                    sendJsonResponse('success', null, 'The Record has been Deleted!');
                }
            } else {
                sendJsonResponse('error', null, 'Invalid Request!');
            }
            break;

        case 'edit-report-by-admin':
            // Capture POST data
            $activity_id = $_POST['activity_id']; //required
            $description = $_POST['description'] ?? '';
            $in_time = $_POST['in_time']; //required
            $out_time = $_POST['out_time'] ?? null;
            $status = $_POST['status']; //required
            $updated_by = $_POST['updated_by']; //required
            date_default_timezone_set('Asia/Kolkata');

            //Validate
            if (!$activity_id) {
                sendJsonResponse('error', null, 'Invalid Request!');
            }
            if (!$in_time) {
                sendJsonResponse('error', null, 'In-Time is required!');
            }
            if (!$status) {
                sendJsonResponse('error', null, 'Status is required!');
            }

            $stmt = $conn->prepare("UPDATE activities SET description = ?, in_time = ?, out_time = ?,  WHERE id = ?");
            

        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
} else {
    sendJsonResponse('error', null, 'Action parameter is missing');
}
