<?php
// auto_close_breaks.php - Cron job to auto-close open breaks every day at midnight

// Include the database connection
include 'db_connection.php';

// Get today's date and set default out time to 11:59 PM
$today = date('Y-m-d');
$default_out_time = $today . ' 23:59:00';

// Find all open breaks (active status) for the current day
$sql = "SELECT id FROM activities WHERE status = 'active' AND DATE(in_time) = '$today' AND deleted_at IS NULL";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $activity_id = $row['id'];

        // Update the activity to set out_time and status to auto closed
        $update_sql = "UPDATE activities SET out_time = '$default_out_time', status = 'auto closed', updated_at = NOW() WHERE id = $activity_id AND deleted_at IS NULL";
        $conn->query($update_sql);
    }
}

$conn->close();

// Add this script to a daily cron job:
// 59 23 * * * /usr/bin/php /path/to/auto_close_breaks.php
?>