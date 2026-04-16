<?php
// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "epic_hrr";

// require __DIR__ . '/vendor/autoload.php';
require_once('vendor/autoload.php');

  $options = array(
    'cluster' => 'ap2',
    'useTLS' => true
  );
  $pusher = new Pusher\Pusher(
    'f77b8bad1d56965b1b7c',
    '89524600f019f2273441',
    '2138923',
    $options
  );

// Create a connection
$conn = new mysqli($host, $username, $password, $database);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set charset (recommended for UTF-8)
$conn->set_charset("utf8mb4");
?>
