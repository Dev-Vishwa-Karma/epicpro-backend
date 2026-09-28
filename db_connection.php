<?php

$config = require __DIR__ . '/config.php';

$db = $config['database'] ?? [];
$host = $db['host'];
$username = $db['username'];
$password = $db['password'];
$database = $db['name'];

// Create a connection
$conn = new mysqli($host, $username, $password, $database);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set charset (recommended for UTF-8)
$conn->set_charset("utf8mb4");
