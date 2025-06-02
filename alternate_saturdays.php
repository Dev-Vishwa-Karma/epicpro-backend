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

$action = $_GET['action'] ?? 'view';

switch ($action) {
    // -----------------------------------------
    // GET: View Saturdays by Year
    // -----------------------------------------
    case 'view':
        $year = $_GET['year'];
    
        if ($year === null || !is_numeric($year)) {
            sendJsonResponse('error', null, 'Year must be a number if provided.');
        }
        
        $year = (int)$year; 
        $query = "SELECT year, month, date FROM Weekends WHERE year = $year";
        
        $result = $conn->query($query);
    
        if (!$result) {
            sendJsonResponse('error', null, 'Database error: ' . $conn->error);
        }
    
        $saturdays = [];
        while ($row = $result->fetch_assoc()) {
            $saturdays[] = $row;
        }
    
        sendJsonResponse('success', $saturdays, 'Saturdays fetched successfully.');
        break;
    // -----------------------------------------
    // POST: Add Saturdays
    // -----------------------------------------
    case 'add':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            sendJsonResponse('error', null, 'Method not allowed. Use POST.');
        }
    
        // Get the raw POST data and decode it
        $inputData = json_decode(file_get_contents('php://input'), true);
        $saturdays = $inputData['saturdays'] ?? [];
      
        // Check if saturdays is an empty array
        if (empty($saturdays) || !is_array($saturdays)) {
            sendJsonResponse('error', null, 'Saturdays must be a non-empty array.');
        }
    
        $added = 0;
    
        // Loop through each year/month pair
        foreach ($saturdays as $item) {
            if (!isset($item['year'], $item['month'], $item['dates'])) {
                continue;
            }
    
            $year = (int)$item['year'];
            $month = (int)$item['month'];
            $dates = json_encode($item['dates']);
    
            // Check if the record for the specific year and month already exists
            $sqlCheck = "SELECT id FROM Weekends WHERE year = $year AND month = $month LIMIT 1";
            $result = $conn->query($sqlCheck);
          
            if ($result && $result->num_rows > 0) {
              
                // Record exists, update the 'dates' field
                $sqlUpdate = "UPDATE Weekends SET date = '$dates' WHERE year = $year AND month = $month";
              //  var_dump($sqlUpdate);die;
                if ($conn->query($sqlUpdate)) {
                    $added++;
                } else {
                    // Log the error in case of failure
                    error_log("SQL Update Error: " . $conn->error);
                }
            } else {
                //var_dump($dates);die;
                // Record doesn't exist, insert a new record
                $sqlInsert = "INSERT INTO Weekends (year, month, date) VALUES ($year, $month, '$dates')";
                if ($conn->query($sqlInsert)) {
                    $added++;
                } else {
                    // Log the error in case of failure
                    error_log("SQL Insert Error: " . $conn->error);
                }
            }
        }
    
        // Send response based on how many Saturdays were added/updated
        if ($added > 0) {
            sendJsonResponse('success', ['added' => $added], 'Saturdays added/updated successfully.');
        } else {
            sendJsonResponse('error', null, 'No valid Saturdays were added/updated.');
        }
        break;
    
    
    // -----------------------------------------
    // Default
    // -----------------------------------------
    default:
        http_response_code(400);
        sendJsonResponse('error', null, 'Invalid action.');
        break;
}

$conn->close();
