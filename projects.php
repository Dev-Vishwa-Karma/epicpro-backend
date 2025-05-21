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
                if (isset($_GET['project_id']) && is_numeric($_GET['project_id']) && $_GET['project_id'] > 0) {
                    $project_id = (int) $_GET['project_id'];

                    // Prepare SELECT statement with WHERE clause using parameter binding
                    $stmt = $conn->prepare("
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
                            c.location AS client_location,
                            e.id AS employee_id,
                            e.first_name,
                            e.last_name
                        FROM projects p
                        LEFT JOIN clients c ON p.client_id = c.id
                        LEFT JOIN project_assignments pa ON p.id = pa.project_id
                        LEFT JOIN employees e ON pa.employee_id = e.id
                        WHERE p.id = ?
                    ");

                    $stmt->bind_param("i", $project_id);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $projects = [];

                        while ($row = $result->fetch_assoc()) {
                            $project_id = $row['project_id'];
                
                            // If project is not added to array, initialize it
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
                                    'client_location' => $row['client_location'],
                                    'team_members' => []
                                ];
                            }
                
                            // Add employee to team_members array if they exist
                            if (!empty($row['employee_id'])) {
                                $projects[$project_id]['team_members'][] = [
                                    'employee_id' => $row['employee_id'],
                                    'first_name' => $row['first_name'],
                                    'last_name' => $row['last_name']
                                ];
                            }
                        }
                
                        if (!empty($projects)) {
                            echo sendJsonResponse(['success', 'data' => array_values($projects), null]);
                        } else {
                            echo sendJsonResponse(['error', null, 'No records found']);
                        }
                    } else {
                        echo sendJsonResponse(['error', null, 'Failed to execute query', 'query_error' => $stmt->error]);
                    }
                } else {
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
                            c.location AS client_location,
                            e.id AS employee_id,
                            e.first_name,
                            e.last_name
                        FROM projects p
                        LEFT JOIN clients c ON p.client_id = c.id
                        LEFT JOIN project_assignments pa ON p.id = pa.project_id
                        LEFT JOIN employees e ON pa.employee_id = e.id
                    ";
                    if ($role === 'employee') {
                        $query .= " WHERE p.id IN (SELECT project_id FROM project_assignments WHERE employee_id = ?) ";
                    }
                    
                    $query .= " ORDER BY p.created_at DESC";
                    
                    $stmt = $conn->prepare($query);
                    
                    if ($role === 'employee') {
                        $stmt->bind_param("i", $logged_in_employee_id);
                    }
                    
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $projects = [];
                    
                        while ($row = $result->fetch_assoc()) {
                            $project_id = $row['project_id'];
                    
                            // If project is not added to array, initialize it
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
                                    'client_location' => $row['client_location'],
                                    'team_members' => [] // Initialize empty array for team members
                                ];
                            }
                    
                            // Add employee to team_members array if they exist
                            if (!empty($row['employee_id'])) {
                                $projects[$project_id]['team_members'][] = [
                                    'employee_id' => $row['employee_id'],
                                    'first_name' => $row['first_name'],
                                    'last_name' => $row['last_name']
                                ];
                            }
                        }

                        $stmt->close();
                        $conn->close();

                        if (!empty($projects)) {
                            echo json_encode(['status' => 'success', 'data' => array_values($projects)]);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'No records found']);
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Query execution failed', 'query_error' => $stmt->error]);
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
                    // Prepare the SQL insert statement
                    $stmt = $conn->prepare("INSERT INTO projects (client_id, name, description, technology, start_date, end_date, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("issssssi", $client_id, $project_name, $project_description, $project_technology, $project_start_date, $project_end_date, $created_at, $created_by);

                    // Execute the statement and check for success
                    if ($stmt->execute()) {
                        // Get the last inserted project ID
                        $project_id = $conn->insert_id;
                        if (!$project_id) {
                            echo json_encode(['error' => 'Failed to retrieve project ID after insertion']);
                            exit();
                        }

                        // Ensure team members are an array
                        if (!is_array($team_members_id)) {
                            $team_members_id = explode(",", $team_members_id); // Convert comma-separated values to an array
                        }

                        // Insert team members details into the project_assignments table
                        $project_assignments_stmt = $conn->prepare(
                            "INSERT INTO project_assignments (project_id, employee_id, created_at, created_by) VALUES (?, ?, ?, ?)"
                        );

                        if (!empty($team_members_id) && is_array($team_members_id)) {
                            foreach ($team_members_id as $team_member_id) {
                                if (empty($team_member_id)) continue;
                                $project_assignments_stmt->bind_param("iisi", $project_id, $team_member_id, $created_at, $created_by);
                                if (!$project_assignments_stmt->execute()) {
                                    echo json_encode(['error' => "Failed to add team member ID $team_member_id: " . $project_assignments_stmt->error]);
                                    exit();
                                }
                            }
                        } else {
                            echo json_encode(['error' => 'Invalid team members data']);
                            exit();
                        }

                        // Fetch client details
                        $client_stmt = $conn->prepare("SELECT name, location FROM clients WHERE id = ?");
                        $client_stmt->bind_param("i", $client_id);
                        $client_stmt->execute();
                        $client_result = $client_stmt->get_result()->fetch_assoc();
                        $client_name = $client_result['name'] ?? null;

                        // Fetch team member details
                        $team_members = [];
                        if (!empty($team_members_id)) {
                            $placeholders = implode(',', array_fill(0, count($team_members_id), '?'));
                            $types = str_repeat('i', count($team_members_id));
                            $team_stmt = $conn->prepare("SELECT id, first_name, last_name FROM employees WHERE id IN ($placeholders)");
                            $team_stmt->bind_param($types, ...$team_members_id);
                            $team_stmt->execute();
                            $team_result = $team_stmt->get_result();
                            
                            while ($member = $team_result->fetch_assoc()) {
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
                            'project_start_date' => $project_start_date,
                            'project_end_date' => $project_end_date,
                            'created_at' => $created_at,
                            'created_by' => $created_by
                        ];

                        echo json_encode(['success' => 'Project added successfully', 'newProject' => $newProjectData]);
                    } else {
                        echo json_encode(['error' => 'Failed to add project', 'details' => $stmt->error]);
                    }
                } else {
                    echo json_encode(['error' => 'Missing required fields']);
                }
                break;

            case 'edit':
                if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                    $id = $_GET['id'];
                    // Read the raw POST data
                    $inputData = json_decode(file_get_contents('php://input'), true);

                    if (!$inputData) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid JSON data']);
                        exit;
                    }

                    // Validate and get the data from the decoded JSON
                    $department_name = isset($inputData['department_name']) ? $inputData['department_name'] : null;
                    $department_head = isset($inputData['department_head']) ? $inputData['department_head'] : null;

                    $updated_at = date('Y-m-d H:i:s');

                    if ($department_name && $department_head) {
                        // Prepare the SQL update statement
                        $stmt = $conn->prepare("UPDATE departments SET department_name = ?, department_head = ?, updated_at = ? WHERE id = ?");
                        $stmt->bind_param("sssi", $department_name, $department_head, $updated_at, $id);

                        // Execute the statement and check for success
                        if ($stmt->execute()) {
                            $updatedDepartmentData = [
                                'id' => $id,
                                'department_name' => $department_name,
                                'department_head' => $department_head,
                                'updated_at' => $updated_at
                            ];
                            echo json_encode(['success' => 'Department updated successfully', 'updatedDepartmentData' => $updatedDepartmentData]);
                        } else {
                            echo json_encode(['error' => 'Failed to update department']);
                        }
                    } else {
                        echo json_encode(['error' => 'Missing required fields']);
                    }
                    exit;
                } else {
                    echo json_encode(['error' => 'Invalid department ID']);
                    exit;
                }
                break;

            case 'delete':
                if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                    // Prepare DELETE statement
                    $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
                    $stmt->bind_param('i', $_GET['id']);
                    if ($stmt->execute()) {
                        echo json_encode(['success' => 'Record deleted successfully']);
                    } else {
                        echo json_encode(['error' => 'Failed to delete record']);
                    }
                    exit;
                } else {
                    echo json_encode(['error' => 'Invalid department ID']);
                    exit;
                }
                break;

            default:
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }
    }
?>