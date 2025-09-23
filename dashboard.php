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
    (SELECT COUNT(*) FROM employees WHERE role = 'employee' AND status = 1 AND deleted_at IS NULL) AS total_employees,
    (SELECT COUNT(*) FROM events WHERE event_type = 'holiday' AND DATE(event_date) >= CURDATE()) AS total_holidays,
    (SELECT COUNT(*) FROM events WHERE event_type = 'event' AND DATE(event_date) >= CURDATE()) AS total_events,
    (SELECT COUNT(*) FROM project_todo WHERE status = 'pending') AS total_pending_todos";



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