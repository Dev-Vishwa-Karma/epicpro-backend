<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'db_connection.php';
require_once 'helpers.php';
header('Content-Type: application/json');

// Optional date filter
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');


if (!validateDate($date)) {
    sendJsonResponse('error', null, "Invalid date format. Expected YYYY-MM-DD.");
    exit;
}

// Set default out time to 11:59 PM of the given date
$default_out_time = $date . ' 23:59:00';

// Find all open breaks for the specified date
$sql = "SELECT id FROM activities 
        WHERE status = 'active' 
        AND DATE(in_time) = '$date' 
        AND deleted_at IS NULL";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $updated_ids = [];
    
    while ($row = $result->fetch_assoc()) {
        $activity_id = $row['id'];

        // Update activity
        $update_sql = "UPDATE activities 
                       SET out_time = '$default_out_time', status = 'completed', updated_at = NOW() 
                       WHERE id = $activity_id AND deleted_at IS NULL";

        if ($conn->query($update_sql)) {
            $updated_ids[] = $activity_id;
        }
    }

    sendJsonResponse('success', "Break(s) auto-closed.");
} else {
    sendJsonResponse('success', [], "No active breaks found for the date $date.");
}

$conn->close();

// Helper: Validate date
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}
?>
