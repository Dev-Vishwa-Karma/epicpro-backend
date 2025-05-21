<?php
// Database configuration
$host = "localhost";
$username = "root";
$password = "root";
$database = "epic_hr";

// Create a connection
$conn = new mysqli($host, $username, $password, $database);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set charset (recommended for UTF-8)
$conn->set_charset("utf8mb4");
?>
