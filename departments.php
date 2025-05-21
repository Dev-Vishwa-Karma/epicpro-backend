<?php
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Credentials: true");
    
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    include 'db_connection.php';

    // Set the header for JSON response
    header('Content-Type: application/json');

    $action = !empty($_GET['action']) ? $_GET['action'] : 'view';

    if (isset($action)) {
        switch ($action) {
            case 'view':
                if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                    // Prepare SELECT statement with WHERE clause using parameter binding
                    $stmt = $conn->prepare("
                        SELECT d.*, 
                            (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.department_id AND e.deleted_at IS NULL) AS total_employee
                        FROM departments d
                        WHERE d.id = ?
                    ");
                    $stmt->bind_param("i", $_GET['id']);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result) {
                            $departments = $result->fetch_all(MYSQLI_ASSOC);
                            echo json_encode([
                                'status' => 'success',
                                'data'   => $departments
                            ]);
                        } else {
                            echo json_encode(['error' => 'No records found', 'query_error' => $conn->error]);
                        }
                    } else {
                        echo json_encode(['error' => 'Failed to execute query', 'query_error' => $stmt->error]);
                    }
                } else {
                    $result = $conn->query("
                        SELECT d.*, 
                            (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.deleted_at IS NULL) AS total_employee
                        FROM departments d
                    ");
                    if ($result) {
                        $departments = $result->fetch_all(MYSQLI_ASSOC);
                        echo json_encode([
                            'status' => 'success',
                            'data'   => $departments
                        ]);
                    } else {
                        echo json_encode(['error' => 'No records found', 'query_error' => $conn->error]);
                    }
                }
                break;

            case 'add':
                // Validate and get POST data
                $department_name = isset($_POST['department_name']) ? $_POST['department_name'] : null;
                $department_head = isset($_POST['department_head']) ? $_POST['department_head'] : null;
                $created_at = date('Y-m-d H:i:s');
                $updated_at = $created_at;
            
                if ($department_name && $department_head) {
                    // Prepare the SQL insert statement
                    $stmt = $conn->prepare("INSERT INTO departments (department_name, department_head, created_at, updated_at) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $department_name, $department_head, $created_at, $updated_at);
            
                    // Execute the statement and check for success
                    if ($stmt->execute()) {
                        $newDepartmentData = [
                            'id' => $stmt->insert_id,
                            'department_name' => $department_name,
                            'department_head' => $department_head,
                            'created_at' => $created_at,
                            'updated_at' => $updated_at
                        ];

                        echo json_encode(['success' => 'Department added successfully', 'newDepartment' => $newDepartmentData]);
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to add department', 'details' => $stmt->error]);
                    }
                } else {
                    http_response_code(400);
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

                    $updated_at = date('Y-m-d H:i:s'); // Set current timestamp for `updated_at`

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
                        http_response_code(400);
                        echo json_encode(['error' => 'Missing required fields']);
                    }
                    exit;
                } else {
                    http_response_code(400);
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
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to delete record']);
                    }
                    exit;
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid department ID']);
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