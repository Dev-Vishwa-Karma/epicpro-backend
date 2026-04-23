<?php

$config = require __DIR__ . '/config.php';

$host = $config['database']['host'];
$username = $config['database']['username'];
$password = $config['database']['password'];
$database = $config['database']['name'];

// Create a connection
$conn = new mysqli($host, $username, $password, $database);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set charset (recommended for UTF-8)
$conn->set_charset("utf8mb4");
?>
