<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
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

    $action = !empty($_GET['action']) ? $_GET['action'] : 'view';

    if (isset($action)) {
        switch ($action) {
            case 'view':
                // Get status filter from query, default to 'pending'
                $status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'pending';
                $validStatuses = ['pending', 'completed']; // Extend if needed
                if (!in_array($status, $validStatuses)) {
                    $status = 'pending';
                }

                if (isset($_GET['employee_id']) && is_numeric($_GET['employee_id']) && $_GET['employee_id'] > 0) {
                    $employee_id = $_GET['employee_id'];
                    $query = "
                        SELECT 
                            pt.id AS todo_id,
                            pt.employee_id,
                            pt.title,
                            pt.due_date,
                            pt.priority,
                            pt.status,
                            pt.created_at,
                            pt.created_by,
                            pt.updated_at,
                            pt.updated_by,
                            pt.deleted_at,
                            pt.deleted_by,
                            e.first_name,
                            e.last_name,
                            e.profile
                        FROM project_todo pt
                        LEFT JOIN employees e ON pt.employee_id = e.id
                        WHERE pt.employee_id = ? AND pt.status = ?
                        ORDER BY 
                            ABS(DATEDIFF(pt.due_date, CURDATE())) ASC,
                            CASE 
                                WHEN pt.priority = 'high' THEN 1
                                WHEN pt.priority = 'medium' THEN 2
                                WHEN pt.priority = 'low' THEN 3
                                ELSE 4
                            END ASC
                    ";

                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("is", $employee_id, $status);

                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result && $result->num_rows > 0) {
                            $projectTodos = [];
                            while ($row = $result->fetch_assoc()) {
                                $todo_id = $row['todo_id'];
                                if (!isset($projectTodos[$todo_id])) {
                                    $projectTodos[$todo_id] = [
                                        'id' => $row['todo_id'],
                                        'employee_id' => $row['employee_id'],
                                        'title' => $row['title'],
                                        'due_date' => $row['due_date'],
                                        'priority' => $row['priority'],
                                        'todoStatus' => $row['status'],
                                        'created_at' => $row['created_at'],
                                        'created_by' => $row['created_by'],
                                        'first_name' => $row['first_name'],
                                        'last_name' => $row['last_name'],
                                        'profile' => $row['profile'],
                                    ];
                                }
                            }
                            sendJsonResponse('success', array_values($projectTodos), 'Todo fetched successfully');
                        } else {
                            sendJsonResponse('error', [], 'No todos available for this employee');
                        }
                    } else {
                        sendJsonResponse('error', 'Failed to execute query', ['query_error' => $stmt->error]);
                    }
                } else {
                    $logged_in_employee_id = $_GET['logged_in_employee_id'] ?? null;
                    $role = $_GET['role'] ?? '';

                    $query = "
                        SELECT 
                            pt.id AS todo_id,
                            pt.employee_id,
                            pt.title,
                            pt.due_date,
                            pt.priority,
                            pt.status,
                            pt.created_at,
                            pt.created_by,
                            pt.updated_at,
                            pt.updated_by,
                            pt.deleted_at,
                            pt.deleted_by,
                            e.first_name,
                            e.last_name,
                            e.profile
                        FROM project_todo pt
                        LEFT JOIN employees e ON pt.employee_id = e.id
                        WHERE pt.status = ?
                    ";

                    if ($role === 'employee') {
                        $query .= " AND pt.employee_id = ?";
                    }

                    $query .= "
                        ORDER BY 
                            ABS(DATEDIFF(pt.due_date, CURDATE())) ASC,
                            CASE 
                                WHEN LOWER(pt.priority) = 'high' THEN 1
                                WHEN LOWER(pt.priority) = 'medium' THEN 2
                                WHEN LOWER(pt.priority) = 'low' THEN 3
                                ELSE 4
                            END ASC
                    ";

                    $stmt = $conn->prepare($query);

                    if ($role === 'employee') {
                        $stmt->bind_param("si", $status, $logged_in_employee_id);
                    } else {
                        $stmt->bind_param("s", $status);
                    }

                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result && $result->num_rows > 0) {
                            $projectTodos = [];
                            while ($row = $result->fetch_assoc()) {
                                $todo_id = $row['todo_id'];
                                if (!isset($projectTodos[$todo_id])) {
                                    $projectTodos[$todo_id] = [
                                        'id' => $row['todo_id'],
                                        'employee_id' => $row['employee_id'],
                                        'title' => $row['title'],
                                        'due_date' => $row['due_date'],
                                        'priority' => $row['priority'],
                                        'todoStatus' => $row['status'],
                                        'created_at' => $row['created_at'],
                                        'created_by' => $row['created_by'],
                                        'first_name' => $row['first_name'],
                                        'last_name' => $row['last_name'],
                                        'profile' => $row['profile'],
                                    ];
                                }
                            }
                            sendJsonResponse('success', array_values($projectTodos), 'Todo fetched successfully');
                        } else {
                            sendJsonResponse('error', [], 'No todos available.');
                        }
                    } else {
                        sendJsonResponse('error', 'Failed to execute query', ['query_error' => $stmt->error]);
                    }
                }
                break;

            case 'add':
                // Validate and get POST data
                $logged_in_employee_id = isset($_POST['logged_in_employee_id']) ? (int)$_POST['logged_in_employee_id'] : '';
                $logged_in_user_role = $_POST['logged_in_employee_role'] ?? "";
                $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : '';
                $title = $_POST['title'] ?? '';
                $due_date = $_POST['due_date'] ?? '';
                $priority = $_POST['priority'] ?? '';
                $status = $_POST['status'] ?? '';
                $created_at = date('Y-m-d H:i:s');

                if (in_array(strtolower($logged_in_user_role), ['admin', 'super_admin'])) {
                    $created_by = $logged_in_employee_id;
                }
            
                if ($title && $due_date) {
                    // Prepare the SQL insert statement
                    $stmt = $conn->prepare("INSERT INTO project_todo (employee_id, title, due_date, priority, status, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssssi", $employee_id, $title, $due_date, $priority, $status, $created_at, $created_by);
            
                    // Execute the statement and check for success
                    if ($stmt->execute()) {
                        $todo_id = $stmt->insert_id;
                        $stmt->close(); 
                        // Fetch the employee's first and last name
                        $stmt = $conn->prepare("
                            SELECT first_name, last_name 
                            FROM employees 
                            WHERE id = ?
                        ");

                        $stmt->bind_param("i", $employee_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $employee = $result->fetch_assoc();
                        $stmt->close();
                        $todosData = [
                            'id' => $todo_id,
                            'employee_id' => $employee_id,
                            'first_name' => $employee['first_name'] ?? '',
                            'last_name' => $employee['last_name'] ?? '',
                            'title' => $title,
                            'due_date' => $due_date,
                            'priority' => $priority,
                            'todoStatus' => $status,
                            'created_at' => $created_at,
                            'created_by' => $created_by
                        ];

                    
                        $notification_body = "New task assigned: $title due on $due_date.";
                        $notification_title = "New Todo Assigned";
                        $notification_type = "task"; 

                        $stmt = $conn->prepare("INSERT INTO notifications (employee_id, body, title, `type`, created_by) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssi", $employee_id, $notification_body, $notification_title, $notification_type, $created_by);

                        // Execute the statement to insert the notification
                        if ($stmt->execute()) {
                            $stmt->close();
                            sendJsonResponse('success', $todosData, 'Todo added successfully');
                        } else {
                            $stmt->close();
                            http_response_code(500);
                            sendJsonResponse('error', 'Failed to send notification', ['details' => $stmt->error]);
                        }
                    } else {
                        http_response_code(500);
                        sendJsonResponse('error', 'Failed to add todo', ['details' => $stmt->error]);
                    }
                } else {
                    http_response_code(400);
                    sendJsonResponse('error', 'Missing required fields');
                }
                break;
            case 'update':
                // Validate and get POST data
                $id = $_POST['id'] ?? null;
                $status = $_POST['status'] ?? null;
                $updated_at = date('Y-m-d H:i:s');
                $updated_by = null;

                if (!$id || !$status) {
                    sendJsonResponse('error', null, 'Task id required');
                }

                $stmt = $conn->prepare("UPDATE project_todo SET status = ?,  updated_at = ?, updated_by = ? WHERE id = ?");
                $stmt->bind_param("ssii", $status, $updated_at, $updated_by, $id);
                if ($stmt->execute()) {
                    $stmt->close();
                    sendJsonResponse('success', 'Todo status updated successfully');
                }  else {
                    http_response_code(500);
                    sendJsonResponse('error', 'Failed to add todo', ['details' => $stmt->error]);
                }
                break;
            case 'due_today_check':
                $employee_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

                if (!$employee_id) {
                    sendJsonResponse('error', null, 'Employee ID is required');
                }

                $today = date('Y-m-d');

                $query = "
                    SELECT 
                        id, title, due_date, priority, status, created_at 
                    FROM project_todo 
                    WHERE employee_id = $employee_id 
                    AND DATE(due_date) = '$today'
                    AND status = 'pending'
                    AND deleted_at IS NULL
                    ORDER BY due_date ASC
                ";

                $result = $conn->query($query);

                if ($result) {
                    $tasks = [];
                    while ($row = $result->fetch_assoc()) {
                        $tasks[] = [
                            'id' => $row['id'],
                            'title' => $row['title'],
                            'due_date' => $row['due_date'],
                            'priority' => $row['priority'],
                            'status' => $row['status'],
                            'created_at' => $row['created_at']
                        ];
                    }

                    $has_due_today = count($tasks) > 0;

                    if ($has_due_today) {
                        $created_by = 0; // System or admin ID

                        foreach ($tasks as $task) {
                            $title = $conn->real_escape_string($task['title']);
                            $due_date = $conn->real_escape_string($task['due_date']);

                            $notification_body = $conn->real_escape_string("Task due on: $title (Due: $due_date)");
                            $notification_title = $conn->real_escape_string("Task Due");
                            $notification_type = $conn->real_escape_string("task_due");

                            // Check for duplicate notification
                            $checkQuery = "
                                SELECT * FROM notifications 
                                WHERE employee_id = $employee_id 
                                AND body = '$notification_body'
                                AND DATE(created_at) = CURDATE()
                                LIMIT 1
                            ";
                            $checkResult = $conn->query($checkQuery);

                            if ($checkResult && $checkResult->num_rows == 0) {
                                $insertQuery = "
                                    INSERT INTO notifications (employee_id, body, title, `type`, created_by)
                                    VALUES ($employee_id, '$notification_body', '$notification_title', '$notification_type', $created_by)
                                ";
                                $conn->query($insertQuery);
                            }
                        }
                    }

                    sendJsonResponse('success', [
                        'has_due_today' => $has_due_today,
                        'tasks' => $tasks
                    ], $has_due_today ? 'Employee has tasks due today.' : 'No tasks due today.');
                } else {
                    sendJsonResponse('error', null, 'Query failed: ' . $conn->error);
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