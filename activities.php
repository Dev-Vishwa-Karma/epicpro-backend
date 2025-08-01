<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With,Authorization");

// Handle preflight (OPTIONS) requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit();
}

// Include the database connection
include 'db_connection.php';
include 'auth_validate.php';

$date = date('Y-m-d');
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

function calculateBreakDuration($in_time, $out_time) {

        $in  = new DateTime($in_time);
        $out = new DateTime($out_time);
        $diff = $in->diff($out);

        // Extract parts
        return $diff->days * 24 * 60
                + $diff->h * 60
                + $diff->i;

}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';
$timeline = !empty($_GET['is_timeline']) ? $_GET['is_timeline'] : false;

// Main action handler
if (isset($action)) {
    switch ($action) {
        case 'view':
            $conditions = [];
            $employee_id = (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) ? (int)$_GET['user_id'] : null;
            $start_date = isset($_GET['from_date']) ? $_GET['from_date'] : null;
            $end_date = isset($_GET['to_date']) ? $_GET['to_date'] : null;
            
            // Validate the date range
            if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
                sendJsonResponse('error', null, "Start date cannot be greater than end date.");
                exit;
            }

            // Collect conditions
            if ($employee_id) {
                $conditions[] = "ea.employee_id = $employee_id";
            }
            if ($start_date && $end_date) {
                $conditions[] = "DATE(ea.in_time) BETWEEN '$start_date' AND '$end_date'";
            } elseif ($start_date) {
                $conditions[] = "DATE(ea.in_time) = '$start_date'";
            } elseif ($end_date) {
                $conditions[] = "DATE(ea.in_time) = '$end_date'";
            }

            // Base query for fetching activities
            $sql = "
                SELECT 
                    e.id AS employee_id,
                    e.first_name AS first_name,
                    e.last_name AS last_name,
                    e.profile AS profile,
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
                            THEN DATE_FORMAT(ea.in_time, '%h:%i %p')
                        WHEN DATE(ea.in_time) = CURDATE() - INTERVAL 1 DAY 
                            THEN DATE_FORMAT(ea.in_time, '%h:%i %p')
                        ELSE DATE_FORMAT(ea.in_time, '%h:%i %p')
                    END AS in_time,
            
                    CASE 
                        WHEN DATE(ea.out_time) = CURDATE() 
                            THEN DATE_FORMAT(ea.out_time, '%h:%i %p')
                        WHEN DATE(ea.out_time) = CURDATE() - INTERVAL 1 DAY 
                            THEN DATE_FORMAT(ea.out_time, '%h:%i %p')
                        ELSE DATE_FORMAT(ea.out_time, '%h:%i %p')
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
                WHERE ea.deleted_at IS NULL";

            // Add conditions to the query if any are collected
            if (!empty($conditions)) {
                $sql .= ' AND ' . implode(' AND ', $conditions);
            }

            // Order the results by in_time
            $sql .= " ORDER BY ea.in_time DESC";

            $result = $conn->query($sql);

            if ($result->num_rows > 0) {
                $activities = $result->fetch_all(MYSQLI_ASSOC);
                if ($timeline) {
                    $activities = getActivities($activities);
                }
                sendJsonResponse('success', $activities);
            } else {
                sendJsonResponse('error', null, 'No records found');
            }
            break;

        case 'break_calculation':
            $user_id = $_GET['user_id'];
            $sql = "SELECT * FROM activities where employee_id = $user_id AND activity_type = 'Break' AND status = 'completed' AND DATE(in_time) = '$date'";
            $result = $conn->query($sql);

            if ($result->num_rows > 0) {
                $activities = $result->fetch_all(MYSQLI_ASSOC);
                $break_duration = 0;
                foreach($activities as $activity) {
                    $minutes = calculateBreakDuration($activity['in_time'], $activity['out_time']);
                    $break_duration += $minutes;
                }

                sendJsonResponse('success', ['break_duration' => $break_duration]);
            } else {
                sendJsonResponse('error', null, 'No records found');
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
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = '$date' AND deleted_at IS NULL LIMIT 1");
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
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = '$date' AND deleted_at IS NULL LIMIT 1");
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
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = $date AND deleted_at IS NULL LIMIT 1");
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
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = ? AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('i', $employee_id, $date);
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
                    $row = $result->fetch_assoc();
                    // If there's an active break, record the break out time
                    $currentTime = date('Y-m-d H:i:s');
                    $updateStmt = $conn->prepare("UPDATE activities SET out_time = ?, status = 'completed' WHERE employee_id = ? AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL");
                    $updateStmt->bind_param('si', $currentTime, $employee_id);
                    $updateStmt->execute();
                    sendJsonResponse('success', null, 'Break has been completed!');
                } else {
                    // No active break found
                    sendJsonResponse('error', null, 'No active break is currently recorded for this employee.');
                }
            } elseif ($activity_type == 'Punch' && $status == 'active') {
                $punch_in_time = date('Y-m-d H:i:s');

                // Check if the employee already has an active break
                $stmt = $conn->prepare("SELECT * FROM activities WHERE employee_id = ? AND activity_type = 'Punch' AND DATE(in_time) = ? AND deleted_at IS NULL LIMIT 1");
                $current_date = date('Y-m-d');
                $stmt->bind_param("is", $employee_id, $current_date);
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
                $currentTime = date('Y-m-d H:i:s');

                // Get active punch-in record
                $query = "SELECT * FROM activities WHERE employee_id = $employee_id AND activity_type = 'Punch' AND DATE(in_time) = CURDATE() AND deleted_at IS NULL LIMIT 1";
                $result = mysqli_query($conn, $query);

                if (mysqli_num_rows($result) > 0) {
                    $row = mysqli_fetch_assoc($result);
                    if ($row['status'] == 'active') {
                        // need to close any active break if any
                        $getActiveBreakQuery = "SELECT * FROM activities WHERE employee_id = $employee_id AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL LIMIT 1";
                        
                        $getActiveBreakQueryResult = mysqli_query($conn, $getActiveBreakQuery);
                       
                        if (mysqli_num_rows($getActiveBreakQueryResult) > 0) {
                            sendJsonResponse('error', null, 'You already have active break Check this.');
                            // If there's an active break, record the break out time
                            // $updateBreakQuery = "UPDATE activities SET out_time = '$currentTime', status = 'completed' 
                            //                     WHERE employee_id = $employee_id AND status = 'active' AND activity_type = 'Break' AND deleted_at IS NULL";
                            // mysqli_query($conn, $updateBreakQuery);
                        }else{
                            // If there's an active punch, record the punch out time
                        $description = 'Punch Out'; // Add the description you want
                        $updateQuery = "UPDATE activities SET out_time = '$currentTime', description = '$description', status = 'completed' 
                                        WHERE employee_id = $employee_id AND status = 'active' AND activity_type = 'Punch' AND deleted_at IS NULL";
                        mysqli_query($conn, $updateQuery);
                        // Respond with success
                        sendJsonResponse('success', null, 'The punch-out has been successfully recorded.');
                        }
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
                
                $user_id = $_GET['user_id'];
                $query = "SELECT * FROM activities WHERE employee_id = $user_id AND status = 'active' AND activity_type = 'Punch' AND deleted_at IS NULL LIMIT 1";
                
                $result = $conn->query($query); 
        
                if ($result->num_rows > 0) {
                    $punchDetails = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $punchDetails, 'This employee is already punched in.');
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

function getActivities($activities) {
    $results = [];
    foreach ($activities as $act) {
        if ($act['status'] === 'completed') { 
            $times = ['in_time', 'out_time'];
            foreach ($times as $time_key) {
                $act['type'] = ($time_key === 'in_time') ? $act['activity_type'].'_in' : $act['activity_type'].'_out';
                $act['date'] = ($time_key === 'in_time') ? $act['complete_in_time'] : $act['complete_out_time'];
                $results[] = $act;
            }
        } else {
            $act['type'] = $act['activity_type'].'_in';
            $act['date'] = $act['complete_in_time'];
            $results[] = $act;
        }
    }

    usort($results, function($a, $b) {
        return strtotime($b['date']) <=> strtotime($a['date']);
    });

    return $results;
}