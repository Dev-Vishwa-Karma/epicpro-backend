<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

include 'db_connection.php';
require_once 'helpers.php';

// Set the header for JSON response
header('Content-Type: application/json');

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJsonResponse('error', null, 'Method not allowed. Use POST.');
}

// Get data from POST (FormData) using $_POST
$year = $_POST['year'] ?? null;
$saturdays = $_POST['saturdays'] ?? [];

// Validate input
if (!$year || !is_numeric($year)) {
    sendJsonResponse('error', null, 'Invalid or missing year.');
}

if (empty($saturdays)) {
    sendJsonResponse('error', null, 'Saturdays must be a non-empty array.');
}

$year = (int)$year; // Ensure $year is an integer
$added = 0;

// Check if data for the given year already exists, and delete it
$sql_delete = "DELETE FROM Weekends WHERE year = $year";
if (!$conn->query($sql_delete)) {
    error_log('MySQL Error while deleting data: ' . $conn->error);
}

// Loop through the array of Saturdays and process each date
foreach ($saturdays as $key => $value) {
    // Trim the date to remove any unwanted spaces
    $date = trim($value);
    
    // Insert into the database
    $sql_insert = "INSERT INTO Weekends (year, date) VALUES ($year, '$value')";
    if ($conn->query($sql_insert)) {
        $added++;
    } else {
        // Log MySQL error if insertion fails
        error_log('MySQL Error while inserting data: ' . $conn->error);
    }
}

$conn->close();

// Respond with a JSON message
if ($added > 0) {
    sendJsonResponse('success', ['year' => $year, 'added' => $added], 'Saturdays added successfully.');
} else {
    sendJsonResponse('error', null, 'No valid Saturdays were added.');
}
?>
