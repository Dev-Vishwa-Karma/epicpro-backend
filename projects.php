<?php
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Credentials: true");

    include 'db_connection.php';
    require_once 'helpers.php';

    // Set the header for JSON response
    header('Content-Type: application/json');

    $action = !empty($_GET['action']) ? $_GET['action'] : 'view';

    if (isset($action)) {
        switch ($action) {
            case 'view':
                // Get project_name filter from GET parameters if provided
                $project_name_filter = isset($_GET['project_name']) ? trim($_GET['project_name']) : '';

                if (isset($_GET['project_id']) && is_numeric($_GET['project_id']) && $_GET['project_id'] > 0) {
                    $project_id = (int) $_GET['project_id'];

                    $query = "
                        SELECT 
                            p.id AS project_id,
                            p.client_id,
                            p.name AS project_name,
                            p.description AS project_description,
                            p.technology AS project_technology,
                            p.start_date AS project_start_date,
                            p.end_date AS project_end_date,
                            p.created_at,
                            p.created_by,
                            c.name AS client_name,
                            p.team_member_ids
                        FROM projects p
                        LEFT JOIN clients c ON p.client_id = c.id
                        WHERE p.id = $project_id
                    ";

                    // Add project name filter if set (case-insensitive partial match)
                    if (!empty($project_name_filter)) {
                        $project_name_filter_safe = mysqli_real_escape_string($conn, $project_name_filter);
                        $query .= " AND p.name LIKE '%$project_name_filter_safe%'";
                    }

                    $result = mysqli_query($conn, $query);

                    if ($result) {
                        $projects = [];

                        while ($row = mysqli_fetch_assoc($result)) {
                            // Add the project details
                            $projects[$project_id] = [
                                'project_id' => $row['project_id'],
                                'client_id' => $row['client_id'],
                                'project_name' => $row['project_name'],
                                'project_description' => $row['project_description'],
                                'project_technology' => $row['project_technology'],
                                'project_start_date' => $row['project_start_date'],
                                'project_end_date' => $row['project_end_date'],
                                'created_at' => $row['created_at'],
                                'created_by' => $row['created_by'],
                                'client_name' => $row['client_name'],
                                'team_members' => []
                            ];

                            // Decode the team_member_ids JSON field
                            $team_member_ids = json_decode($row['team_member_ids'], true);

                            if (!empty($team_member_ids)) {
                                // Prepare query to fetch team members (employees)
                                $team_member_ids_placeholder = implode(",", $team_member_ids);
                                $team_query = "
                                    SELECT id, first_name, last_name, profile 
                                    FROM employees 
                                    WHERE id IN ($team_member_ids_placeholder)
                                ";

                                $team_result = mysqli_query($conn, $team_query);

                                if ($team_result) {
                                    while ($member = mysqli_fetch_assoc($team_result)) {
                                        // Add each team member to the 'team_members' array
                                        $projects[$project_id]['team_members'][] = [
                                            'employee_id' => $member['id'],
                                            'first_name' => $member['first_name'],
                                            'last_name' => $member['last_name'],
                                            'profile' => $member['profile'],
                                        ];
                                    }
                                }
                            }
                        }

                        if (!empty($projects)) {
                            echo json_encode(['status' => 'success', 'data' => array_values($projects)]);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'No records found']);
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Query execution failed', 'query_error' => mysqli_error($conn)]);
                    }
                } else {
                    // This handles the case when no specific project ID is provided.
                    $logged_in_employee_id = $_GET['logged_in_employee_id'] ?? null;
                    $role = $_GET['role'] ?? '';

                    $query = "
                        SELECT 
                            p.id AS project_id,
                            p.client_id,
                            p.name AS project_name,
                            p.description AS project_description,
                            p.technology AS project_technology,
                            p.start_date AS project_start_date,
                            p.end_date AS project_end_date,
                            p.created_at,
                            p.created_by,
                            c.name AS client_name,
                            p.team_member_ids
                        FROM projects p
                        LEFT JOIN clients c ON p.client_id = c.id
                        WHERE 1=1
                    ";

                    // Role-based filtering
                    if ($role === 'employee' && !empty($logged_in_employee_id)) {
                        $query .= " AND p.id IN (SELECT project_id FROM project_assignments WHERE employee_id = $logged_in_employee_id)";
                    }

                    // Add project name filter if set
                    if (!empty($project_name_filter)) {
                        $project_name_filter_safe = mysqli_real_escape_string($conn, $project_name_filter);
                        $query .= " AND p.name LIKE '%$project_name_filter_safe%'";
                    }

                    $query .= " ORDER BY p.created_at DESC";

                    $result = mysqli_query($conn, $query);

                    if ($result) {
                        $projects = [];

                        while ($row = mysqli_fetch_assoc($result)) {
                            $project_id = $row['project_id'];

                            // Add project details
                            if (!isset($projects[$project_id])) {
                                $projects[$project_id] = [
                                    'project_id' => $row['project_id'],
                                    'client_id' => $row['client_id'],
                                    'project_name' => $row['project_name'],
                                    'project_description' => $row['project_description'],
                                    'project_technology' => $row['project_technology'],
                                    'project_start_date' => $row['project_start_date'],
                                    'project_end_date' => $row['project_end_date'],
                                    'created_at' => $row['created_at'],
                                    'created_by' => $row['created_by'],
                                    'client_name' => $row['client_name'],
                                    'team_members' => []
                                ];
                            }

                            // Decode team_member_ids from JSON
                            $team_member_ids = json_decode($row['team_member_ids'], true);

                            if (!empty($team_member_ids)) {
                                // Prepare the query to fetch team members
                                $team_member_ids_placeholder = implode(",", $team_member_ids);
                                $team_query = "
                                    SELECT id, first_name, last_name, profile FROM employees WHERE id IN ($team_member_ids_placeholder)
                                ";

                                $team_result = mysqli_query($conn, $team_query);

                                if ($team_result) {
                                    // Add each team member to the array
                                    while ($member = mysqli_fetch_assoc($team_result)) {
                                        $projects[$project_id]['team_members'][] = [
                                            'employee_id' => $member['id'],
                                            'first_name' => $member['first_name'],
                                            'last_name' => $member['last_name'],
                                            'profile' => $member['profile'],
                                        ];
                                    }
                                }
                            }
                        }

                        if (!empty($projects)) {
                            echo json_encode(['status' => 'success', 'data' => array_values($projects)]);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'No records found']);
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Query execution failed', 'query_error' => mysqli_error($conn)]);
                    }
                }
                break;
                
            case 'add':
                // Validate and get POST data
                $logged_in_employee_id = $_POST['logged_in_employee_id'] ?? '';
                $project_name = $_POST['project_name'] ?? '';
                $project_description = $_POST['project_description'] ?? '';
                $project_technology = $_POST['project_technology'] ?? '';
                $client_id = !empty($_POST['client_id']) ? $_POST['client_id'] : NULL;
                $team_members_id = $_POST['team_members'] ?? '';
                $project_start_date = !empty($_POST['project_start_date']) ? $_POST['project_start_date'] : NULL;
                $project_end_date = !empty($_POST['project_end_date']) ? $_POST['project_end_date'] : NULL;
                $created_at = date('Y-m-d H:i:s');
                $created_by = $_POST['logged_in_employee_id'] ?? '';
                
                if ($project_name && $project_technology && $team_members_id && $created_by) {
                    // Ensure team members are an array
                    if (!is_array($team_members_id)) {
                        $team_members_id = explode(",", $team_members_id); 
                    }

                    // Insert the project directly with the team_member_ids as JSON
                    $team_member_ids_json = json_encode($team_members_id); // Convert the team members array to JSON format

                    $insert_project_query = "INSERT INTO projects (client_id, name, description, technology, start_date, end_date, created_at, created_by, team_member_ids)
                        VALUES ('$client_id', '$project_name', '$project_description', '$project_technology', '$project_start_date', '$project_end_date', '$created_at', '$created_by', '$team_member_ids_json')";
                    
                    if (mysqli_query($conn, $insert_project_query)) {
                        // Get the last inserted project ID
                        $project_id = mysqli_insert_id($conn);
                        if (!$project_id) {
                            echo json_encode(['error' => 'Failed to retrieve project ID after insertion']);
                            exit();
                        }

                        // Fetch client details
                        $client_query = "SELECT name FROM clients WHERE id = '$client_id'";
                        $client_result = mysqli_query($conn, $client_query);
                        $client_data = mysqli_fetch_assoc($client_result);
                        $client_name = $client_data['name'] ?? null;

                        // Fetch team member details
                        $team_members = [];
                        if (!empty($team_members_id)) {
                            $team_ids = implode(',', $team_members_id); // Join IDs for the query
                            $team_query = "SELECT id, first_name, last_name FROM employees WHERE id IN ($team_ids)";
                            $team_result = mysqli_query($conn, $team_query);
                            
                            while ($member = mysqli_fetch_assoc($team_result)) {
                                $team_members[] = [
                                    'employee_id' => $member['id'],
                                    'first_name' => $member['first_name'],
                                    'last_name' => $member['last_name']
                                ];
                            }
                        }

                        $newProjectData = [
                            'project_id' => $project_id,
                            'project_name' => $project_name,
                            'project_description' => $project_description,
                            'project_technology' => $project_technology,
                            'client_name' => $client_name,
                            'team_members' => $team_members,
                            'team_member_ids' => $team_member_ids_json, // Store team member IDs as JSON
                            'project_start_date' => $project_start_date,
                            'project_end_date' => $project_end_date,
                            'created_at' => $created_at,
                            'created_by' => $created_by
                        ];

                        echo json_encode(['success' => 'Project added successfully', 'newProject' => $newProjectData]);
                    } else {
                        echo json_encode(['error' => 'Failed to add project', 'details' => mysqli_error($conn)]);
                    }
                } else {
                    echo json_encode(['error' => 'Missing required fields']);
                }
                break;

            case 'edit':
                // Validate and get POST data
                $project_id = $_POST['project_id'] ?? '';
                $logged_in_employee_id = $_POST['logged_in_employee_id'] ?? '';
                $project_name = $_POST['project_name'] ?? '';
                $project_description = $_POST['project_description'] ?? '';
                $project_technology = $_POST['project_technology'] ?? '';
                $client_id = !empty($_POST['client_id']) ? $_POST['client_id'] : NULL;
                $team_members_id = $_POST['team_members'] ?? '';
                $project_start_date = !empty($_POST['project_start_date']) ? $_POST['project_start_date'] : NULL;
                $project_end_date = !empty($_POST['project_end_date']) ? $_POST['project_end_date'] : NULL;
                $updated_at = date('Y-m-d H:i:s');
                $updated_by = $_POST['logged_in_employee_id'] ?? '';

                if ($project_id && $logged_in_employee_id) {
                    // Ensure team members are an array
                    if (!is_array($team_members_id)) {
                        $team_members_id = explode(",", $team_members_id); // Convert comma-separated values to an array
                    }

                    // Convert the team member IDs to JSON format
                    $team_member_ids_json = json_encode($team_members_id);

                    // Prepare the SQL UPDATE query
                    $update_project_query = "UPDATE projects 
                                            SET client_id = '$client_id', 
                                                name = '$project_name', 
                                                description = '$project_description', 
                                                technology = '$project_technology', 
                                                start_date = '$project_start_date', 
                                                end_date = '$project_end_date', 
                                                updated_at = '$updated_at', 
                                                updated_by = '$updated_by',
                                                team_member_ids = '$team_member_ids_json'
                                            WHERE id = '$project_id'";

                    if (mysqli_query($conn, $update_project_query)) {
                        // Fetch updated project details
                        $project_query = "SELECT * FROM projects WHERE id = '$project_id'";
                        $project_result = mysqli_query($conn, $project_query);
                        $project_data = mysqli_fetch_assoc($project_result);

                        // Fetch client details
                        $client_query = "SELECT name FROM clients WHERE id = '$client_id'";
                        $client_result = mysqli_query($conn, $client_query);
                        $client_data = mysqli_fetch_assoc($client_result);
                        $client_name = $client_data['name'] ?? null;

                        // Decode the team member IDs from JSON
                        $team_member_ids = json_decode($project_data['team_member_ids'], true); // Returns an array
                        var_dump($team_member_ids);die;
                        // Fetch team member details
                        $team_members = [];
                        if (!empty($team_member_ids)) {
                            $team_ids = implode(',', $team_member_ids); // Join IDs for the query
                            $team_query = "SELECT id, first_name, last_name FROM employees WHERE id IN ($team_ids)";
                            $team_result = mysqli_query($conn, $team_query);
                            
                            while ($member = mysqli_fetch_assoc($team_result)) {
                                $team_members[] = [
                                    'employee_id' => $member['id'],
                                    'first_name' => $member['first_name'],
                                    'last_name' => $member['last_name']
                                ];
                            }
                        }

                        $updatedProjectData = [
                            'project_id' => $project_id,
                            'project_name' => $project_name,
                            'project_description' => $project_description,
                            'project_technology' => $project_technology,
                            'client_name' => $client_name,
                            'team_members' => $team_members,
                            'team_member_ids' => $team_member_ids_json,
                            'project_start_date' => $project_start_date,
                            'project_end_date' => $project_end_date,
                            'updated_at' => $updated_at,
                            'updated_by' => $updated_by
                        ];

                        echo json_encode(['success' => 'Project updated successfully', 'updatedProject' => $updatedProjectData]);
                    } else {
                        echo json_encode(['error' => 'Failed to update project', 'details' => mysqli_error($conn)]);
                    }
                } else {
                    echo json_encode(['error' => 'Missing required fields']);
                }
                break;
            
            case 'update_active_status':
                $json = file_get_contents('php://input');
                $data = json_decode($json, true);

                if (!$data || !isset($data['id']) || !isset($data['is_active'])) {
                    sendJsonResponse('error', null, 'Project id and active status are required');
                    exit;
                }

                $id = (int)$data['id'];
                $is_active = (int)$data['is_active'];
                $updated_at = date('Y-m-d H:i:s');
                $updated_by = isset($data['logged_in_employee_id']) ? (int)$data['logged_in_employee_id'] : null;

                $stmt = $conn->prepare("UPDATE projects SET is_active = ?, updated_at = ?, updated_by = ? WHERE id = ?");
                $stmt->bind_param("isii", $is_active, $updated_at, $updated_by, $id);

                if ($stmt->execute()) {
                    sendJsonResponse('success', ['is_active' => $is_active], 'Status updated');
                } else {
                    sendJsonResponse('error', null, 'Update failed: ' . $stmt->error);
                }
                exit;
                break;

            case 'delete':
                if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                    $project_id = (int)$_GET['id'];
                        $deleteSql = "DELETE FROM projects WHERE id = $project_id";
                        if ($conn->query($deleteSql)) {
                            sendJsonResponse('success', null, 'Project deleted successfully');
                        } else {
                            http_response_code(500);
                            sendJsonResponse('error', null, 'Failed to delete project: ' . $conn->error);
                        }
                    } else {
                        http_response_code(404);
                        sendJsonResponse('error', null, 'Project not found');
                    }
                exit;
                break;
            
            default:
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }
    }
?>