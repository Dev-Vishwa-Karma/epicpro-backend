<?php
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
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

    $query = "SELECT 
    (SELECT COUNT(*) FROM employees WHERE role IN ('admin', 'super_admin') AND deleted_at IS NULL) AS total_users,
    (SELECT COUNT(*) FROM employees WHERE role = 'employee' AND deleted_at IS NULL) AS total_employees,
    SUM(CASE WHEN event_type = 'holiday' THEN 1 ELSE 0 END) AS total_holidays,
    SUM(CASE WHEN event_type = 'event' THEN 1 ELSE 0 END) AS total_events,
    (SELECT COUNT(*) FROM project_todo WHERE status = 'pending') AS total_pending_todos
    FROM events";



    // Execute the query
    $result = $conn->query($query);

    // Check if query was successful
    if ($result) {
        // Fetch the results as an associative array
        $data = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'status' => 'success',
            'data'   => $data
        ]);
    } else {
        echo "Error: " . $conn->error;
    }

    // Close the connection
    $conn->close();
?>