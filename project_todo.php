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
    include 'auth_validate.php';
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

    // Helper: Dates from query params
    function buildDateRange()
    {
        $from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : null;
        $to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : null;
        $day = isset($_GET['day']) ? strtolower(trim($_GET['day'])) : null; // today, yesterday, tomorrow, this_week, monday, ... sunday
        $date = isset($_GET['date']) ? trim($_GET['date']) : null; // exact date

        // If explicit date provided, treat as single day range
        if ($date) {
            return [$date, $date];
        }
        
        // If both from/to provided, use them
        if ($from_date && $to_date) {
            return [$from_date, $to_date];
        }

        // for dates and days
        if ($day) {
            $today = new DateTime('today');
            switch ($day) {
                case 'today':
                    return [$today->format('Y-m-d'), $today->format('Y-m-d')];
                case 'yesterday':
                    $y = clone $today; $y->modify('-1 day');
                    return [$y->format('Y-m-d'), $y->format('Y-m-d')];
                case 'tomorrow':
                    $t = clone $today; $t->modify('+1 day');
                    return [$t->format('Y-m-d'), $t->format('Y-m-d')];
                case 'upto_tomorrow':
                    $t = clone $today; $t->modify('+1 day');
                    return ['1970-01-01', $t->format('Y-m-d')];
                case 'this_week':
                    // Monday to Sunday of current week
                    $start = clone $today; $start->modify('monday this week');
                    $end = clone $start; $end->modify('+6 days');
                    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
                case 'monday': case 'tuesday': case 'wednesday': case 'thursday': case 'friday': case 'saturday': case 'sunday':
                    $target = clone $today;
                    // Find this week's target weekday
                    $target->modify($day . ' this week');
                    return [$target->format('Y-m-d'), $target->format('Y-m-d')];
            }
        }

        // normalize to a single-day filter
        if ($from_date && !$to_date) return [$from_date, $from_date];
        if ($to_date && !$from_date) return [$to_date, $to_date];

        return [null, null];
    }

    $action = !empty($_GET['action']) ? $_GET['action'] : 'view';

    if (isset($action)) {
        switch ($action) {
            case 'view':
                // Get status filter from query, but do not default to 'pending'
                $status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : null;
                $validStatuses = ['pending', 'completed']; // Extend if needed
                if ($status && !in_array($status, $validStatuses)) {
                    $status = null;
                }

                // Build date condition
                list($range_from, $range_to) = buildDateRange();
                $dateCondition = '';
                if ($range_from && $range_to) {
                    // Safe interpolate after basic validation pattern YYYY-MM-DD
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_to)) {
                        sendJsonResponse('error', null, 'Invalid date format. Use YYYY-MM-DD');
                    }
                    $dateCondition = " AND DATE(pt.due_date) BETWEEN '$range_from' AND '$range_to' ";
                }

                if (isset($_GET['employee_id']) && is_numeric($_GET['employee_id']) && $_GET['employee_id'] > 0) {
                    $employee_id = $_GET['employee_id'];
                    if ($status) {
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
                        WHERE pt.employee_id = ? AND pt.status = ? AND e.deleted_at IS NULL" . $dateCondition . "
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
                    } else {
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
                            WHERE pt.employee_id = ? AND e.deleted_at IS NULL" . $dateCondition . "
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
                        $stmt->bind_param("i", $employee_id);
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
                            sendJsonResponse('error', [], 'No todos available for this employee');
                        }
                    } else {
                        sendJsonResponse('error', 'Failed to execute query', ['query_error' => $stmt->error]);
                    }
                } else {
                    $logged_in_employee_id = $_GET['logged_in_employee_id'] ?? null;
                    $role = $_GET['role'] ?? '';

                    if ($status) {
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
                        WHERE pt.status = ? AND e.deleted_at IS NULL" . $dateCondition;
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
                        if ($role === 'employee') {
                        $stmt = $conn->prepare($query);
                            $stmt->bind_param("si", $status, $logged_in_employee_id);
                        } else {
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("s", $status);
                        }
                    } else {
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
                            WHERE e.deleted_at IS NULL" . $dateCondition;
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
                        if ($role === 'employee') {
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("i", $logged_in_employee_id);
                        } else {
                            $stmt = $conn->prepare($query);
                        }
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
                $logged_in_employee_id = isset($_POST['logged_in_employee_id']) ? (int)$_POST['logged_in_employee_id'] : '';
                $logged_in_user_role = $_POST['logged_in_employee_role'] ?? "";
                $employee_id = $_POST['employee_id'] ?? '';  // could be 'all'
                $title = $conn->real_escape_string($_POST['title'] ?? '');
                $due_date = $conn->real_escape_string($_POST['due_date'] ?? '');
                $priority = $conn->real_escape_string($_POST['priority'] ?? '');
                $created_at = date('Y-m-d H:i:s');

                if (in_array(strtolower($logged_in_user_role), ['admin', 'super_admin'])) {
                    $created_by = (int)$logged_in_employee_id;
                }
              
                if ($title && $due_date) {
                    if ($employee_id === 'all') {
                        //ACTIVE USERS ONLY
                        $result = $conn->query("SELECT id, first_name, last_name FROM employees WHERE role ='employee'");
                         
                        if ($result && $result->num_rows > 0) {
                            $todosCreated = [];
                            while ($employee = $result->fetch_assoc()) {
                                
                                $emp_id = (int)$employee['id'];

                                // Insert into project_todo
                                $sql = "
                                    INSERT INTO project_todo 
                                    (employee_id, title, due_date, priority, created_at, created_by) 
                                    VALUES ($emp_id, '$title', '$due_date', '$priority', '$created_at', '$created_by')
                                ";
                             
                                if ($conn->query($sql)) {
                                    $todo_id = $conn->insert_id;

                                    // Insert notification
                                    $notification_body = $conn->real_escape_string("New task assigned: $title due on $due_date.");
                                    $notification_title = "New Todo Assigned";
                                    $notification_type = "task_added";
                                    
                                    $notif_sql = "
                                        INSERT INTO notifications 
                                        (employee_id, body, title, `type`, created_by) 
                                        VALUES ($emp_id, '$notification_body', '$notification_title', '$notification_type', $created_by)
                                    ";
                                    $conn->query($notif_sql);

                                    $todosCreated[] = [
                                        'id' => $todo_id,
                                        'employee_id' => $emp_id,
                                        'first_name' => $employee['first_name'] ?? '',
                                        'last_name' => $employee['last_name'] ?? '',
                                        'title' => $title,
                                        'due_date' => $due_date,
                                        'priority' => $priority,
                                        'created_at' => $created_at,
                                        'created_by' => $created_by
                                    ];
                                }
                            }

                            sendJsonResponse('success', $todosCreated, 'Todo added for all employees successfully');
                        } else {
                            http_response_code(500);
                            sendJsonResponse('error', 'No employees found');
                        }

                    } else {
                        $employee_id = (int)$employee_id;

                        // Insert single todo
                        $sql = "
                            INSERT INTO project_todo 
                            (employee_id, title, due_date, priority, created_at, created_by) 
                            VALUES ($employee_id, '$title', '$due_date', '$priority', '$created_at', $created_by)
                        ";
                        if ($conn->query($sql)) {
                            $todo_id = $conn->insert_id;

                            // Get employee name
                            $emp_result = $conn->query("SELECT first_name, last_name FROM employees WHERE id = $employee_id");
                            $employee = $emp_result ? $emp_result->fetch_assoc() : [];

                            // Insert notification
                            $notification_body = $conn->real_escape_string("New task assigned: $title due on $due_date.");
                            $notification_title = "New Todo Assigned";
                            $notification_type = "task_added";

                            $notif_sql = "
                                INSERT INTO notifications 
                                (employee_id, body, title, `type`, created_by) 
                                VALUES ($employee_id, '$notification_body', '$notification_title', '$notification_type', $created_by)
                            ";
                            if ($conn->query($notif_sql)) {
                                $todosData = [
                                    'id' => $todo_id,
                                    'employee_id' => $employee_id,
                                    'first_name' => $employee['first_name'] ?? '',
                                    'last_name' => $employee['last_name'] ?? '',
                                    'title' => $title,
                                    'due_date' => $due_date,
                                    'priority' => $priority,
                                    'created_at' => $created_at,
                                    'created_by' => $created_by
                                ];
                                sendJsonResponse('success', $todosData, 'Todo added successfully');
                            } else {
                                http_response_code(500);
                                sendJsonResponse('error', 'Failed to send notification');
                            }
                        } else {
                            http_response_code(500);
                            sendJsonResponse('error', 'Failed to add todo');
                        }
                    }
                } else {
                    http_response_code(400);
                    sendJsonResponse('error', 'Missing required fields');
                }
                break;
                
            case 'edit':
                $logged_in_employee_id = isset($_POST['logged_in_employee_id']) ? (int)$_POST['logged_in_employee_id'] : '';
                $logged_in_user_role = $_POST['logged_in_employee_role'] ?? "";
                $todo_id = isset($_POST['todo_id']) ? (int)$_POST['todo_id'] : '';
                $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : '';
                $title = $conn->real_escape_string($_POST['title'] ?? '');
                $due_date = $conn->real_escape_string($_POST['due_date'] ?? '');
                $priority = $conn->real_escape_string($_POST['priority'] ?? '');
                $updated_at = date('Y-m-d H:i:s');

                if (!$todo_id || !$employee_id || !$title || !$due_date) {
                    http_response_code(400);
                    sendJsonResponse('error', 'Missing required fields');
                    break;
                }

                // Ensure the todo exists by id
                $check_sql = "SELECT id FROM project_todo WHERE id = $todo_id LIMIT 1";
                $check_result = $conn->query($check_sql);

                if (!$check_result || $check_result->num_rows === 0) {
                    http_response_code(404);
                    sendJsonResponse('error', 'Todo not found');
                    break;
                }

                $update_sql = "
                    UPDATE project_todo 
                    SET 
                        employee_id = $employee_id,
                        title = '$title',
                        due_date = '$due_date',
                        priority = '$priority',
                        updated_at = '$updated_at',
                        updated_by = $logged_in_employee_id
                    WHERE id = $todo_id
                ";

                if ($conn->query($update_sql)) {
                    // Optional: Update notification or send a new one
                    $notification_body = $conn->real_escape_string("Task updated: $title now due on $due_date.");
                    $notification_title = "Todo Updated";
                    $notification_type = "task_updated";

                    $notif_sql = "
                        INSERT INTO notifications 
                        (employee_id, body, title, type, created_by) 
                        VALUES ($employee_id, '$notification_body', '$notification_title', '$notification_type', $logged_in_employee_id)
                    ";
                    $conn->query($notif_sql);

                    // Fetch updated employee name
                    $emp_result = $conn->query("SELECT first_name, last_name, profile FROM employees WHERE id = $employee_id");
                    $employee = $emp_result ? $emp_result->fetch_assoc() : [];

                    $updatedData = [
                        'id' => $todo_id,
                        'employee_id' => $employee_id,
                        'first_name' => $employee['first_name'] ?? '',
                        'last_name' => $employee['last_name'] ?? '',
                        'profile' => $employee['profile'] ?? null,
                        'title' => $title,
                        'due_date' => $due_date,
                        'priority' => $priority,
                        'updated_at' => $updated_at,
                        'updated_by' => $logged_in_employee_id
                    ];

                    sendJsonResponse('success', $updatedData, 'Todo updated successfully');
                } else {
                    http_response_code(500);
                    sendJsonResponse('error', 'Failed to update todo');
                }

                break;
                
            case 'update_status':
                $id = $_POST['id'] ?? null;
                $status = $_POST['status'] ?? null;
                $due_date = $_POST['due_date'] ?? null;
                $to_do_created_by = $_POST['to_do_created_by'] ?? null; // Who created the task
                $to_do_created_for = $_POST['to_do_created_for'] ?? null; // Who the task is assigned to
                $logged_in_employee_role =  $_POST['logged_in_employee_role'] ?? null;
                $logged_in_employee_name =  $_POST['logged_in_employee_name'] ?? null;
                $updated_at = date('Y-m-d H:i:s');
                $updated_by = null;

                // Get the title and due_date for notification
                $title = '';
                $due_date = '';
                if ($id) {
                    $info_query = $conn->query("SELECT title, due_date FROM project_todo WHERE id = $id LIMIT 1");
                    if ($info_query && $info_query->num_rows > 0) {
                        $info_row = $info_query->fetch_assoc();
                        $title = $info_row['title'];
                        // Format due_date as "02 Sep 2024"
                        $raw_due_date = $info_row['due_date'];
                        if (!empty($raw_due_date)) {
                            $due_date = date('d M Y', strtotime($raw_due_date));
                        }
                    }
                }

                if (!$id || !$status) {
                    sendJsonResponse('error', null, 'Task id and status are required');
                }

                $stmt = $conn->prepare("UPDATE project_todo SET status = ?, updated_at = ?, updated_by = ? WHERE id = ?");
                $stmt->bind_param("ssii", $status, $updated_at, $updated_by, $id);

                if ($stmt->execute()) {
                    $stmt->close();
                  
                    if ($status === 'completed') {
                        // Notify the relevant person based on who updated the task
                        if ($logged_in_employee_role == 'employee') {
                            // Employee completes the task, notify the admin
                            $notification_body = $conn->real_escape_string("Task: $title Due Date: $due_date");
                            $notification_title = "Task Completed by $logged_in_employee_name";
                            $notification_type = "task_completed";
                            $notification_recipient = $to_do_created_by; // Notify the admin (the creator)

                            $notif_sql = "
                                INSERT INTO notifications 
                                (employee_id, body, title, type) 
                                VALUES ($notification_recipient, '$notification_body', '$notification_title', '$notification_type')
                            ";
                            $conn->query($notif_sql);
                        } elseif ($logged_in_employee_role == 'super_admin' || $logged_in_employee_role == 'admin') {
                            // Admin completes the task, notify the employee
                            $notification_body = $conn->real_escape_string("Task: $title Due Date: $due_date");
                            $notification_title = "Task Completed by Admin";
                            $notification_type = "task_completed";
                            $notification_recipient = $to_do_created_for; // Notify the employee (the assignee)

                            $notif_sql = "
                                INSERT INTO notifications 
                                (employee_id, body, title, type) 
                                VALUES ($notification_recipient, '$notification_body', '$notification_title', '$notification_type')
                            ";
                            $conn->query($notif_sql);
                        }
                    }

                    sendJsonResponse('success', 'Todo status updated successfully');
                } else {
                    http_response_code(500);
                    sendJsonResponse('error', 'Failed to update todo status', ['details' => $stmt->error]);
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
                            if(date('Y-m-d', strtotime($task['created_at'])) !== $today){
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
                    }

                    sendJsonResponse('success', [
                        'has_due_today' => $has_due_today,
                        'tasks' => $tasks
                    ], $has_due_today ? 'Employee has tasks due today.' : 'No tasks due today.');
                } else {
                    sendJsonResponse('error', null, 'Query failed: ' . $conn->error);
                }
                break;
                
            case 'delete':
                if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                    $todo_id = (int)$_GET['id'];

                    // Optional: Check if the todo exists before deleting
                    $checkSql = "SELECT id FROM project_todo WHERE id = $todo_id";
                    $checkResult = $conn->query($checkSql);

                    if ($checkResult && $checkResult->num_rows > 0) {
                        $deleteSql = "DELETE FROM project_todo WHERE id = $todo_id";
                        if ($conn->query($deleteSql)) {
                            sendJsonResponse('success', null, 'Todo deleted successfully');
                        } else {
                            http_response_code(500);
                            sendJsonResponse('error', null, 'Failed to delete todo: ' . $conn->error);
                        }
                    } else {
                        http_response_code(404);
                        sendJsonResponse('error', null, 'Todo not found');
                    }
                } else {
                    http_response_code(400);
                    sendJsonResponse('error', null, 'Invalid todo ID');
                }
                exit;
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