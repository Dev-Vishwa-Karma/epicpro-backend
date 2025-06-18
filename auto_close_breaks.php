<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
    http_response_code(200);
    exit();
}

include "db_connection.php";
require_once "helpers.php";
header("Content-Type: application/json");

// Optional date filter
$date = isset($_GET["date"]) ? $_GET["date"] : date("Y-m-d");
$user_id = isset($_GET["user_id"]) ? $_GET["user_id"] : "";

if (!validateDate($date)) {
    sendJsonResponse(
        "error",
        null,
        "Invalid date format. Expected YYYY-MM-DD."
    );
    exit();
}

// Find all open breaks for the specified date
$sql = "SELECT id, in_time, out_time, activity_type, status FROM activities 
        WHERE employee_id  = $user_id
        AND DATE(in_time) = '$date' 
        AND deleted_at IS NULL
        ORDER BY id DESC";

$results = $conn->query($sql);
$updated_ids = [];
$total_duration_minutes = 0;
if ($results && $results->num_rows > 0) {
    while ($row = $results->fetch_assoc()) {
        $activity_id = $row["id"];
        $in_time = $row["in_time"];
        $activity_type = $row["activity_type"];
        $out_time = "";

        if ($activity_type === "Break") {
            if ($row["status"] === "completed" && $row["out_time"]) {
                $total_duration_minutes +=
                    (strtotime($row["out_time"]) - strtotime($in_time)) / 60;
                $out_time = $row["out_time"];
            } else {
                $out_time = $in_time;
            }
        }

        if ($activity_type === "Punch") {
            // var_dump($row);die;
            $rounded_minutes = (int) $total_duration_minutes;
            $out_time = date(
                "Y-m-d H:i:s",
                strtotime($in_time . " + {$rounded_minutes} minutes + 7 hours")
            );

            if ($row["status"] === "active") {
                $employee_id = $user_id ?? null;
                $report =
                    "Your punch-out for today has been automatically recorded by the system due to the absence of a manual punch-out. Please ensure to manually punch out at the end of your day to maintain accurate attendance records.";
                $start_time = $in_time;
                $break_duration_in_minutes = $rounded_minutes;
                $end_time = $out_time ?? null;

                // Convert to timestamps
                $start_timestamp = strtotime($start_time);
                $end_timestamp = strtotime($end_time);

                // Calculate durations in seconds
                $total_seconds = $end_timestamp - $start_timestamp;
                $break_seconds = $break_duration_in_minutes * 60;
                $working_seconds = $total_seconds - $break_seconds;

                // Convert durations to HH:MM:SS format
                $todays_total_hours = secondsToTime($total_seconds);
                $todays_working_hours = secondsToTime($working_seconds);
                $start_time = secondsToTime($start_time);
                $end_time = secondsToTime($end_time);
                $break_duration_in_minutes = $break_duration_in_minutes;

                $created_at = date("Y-m-d H:i:s");
                $updated_at = $created_at;

                if (
                    empty($employee_id) ||
                    empty($report) ||
                    empty($start_time) ||
                    empty($end_time)
                ) {
                    sendJsonResponse("error", null, "All fields are required");
                    exit();
                }

                $sql = "INSERT INTO reports 
                        (employee_id, report, start_time, end_time, break_duration_in_minutes, total_working_hours, total_hours, created_at, updated_at) 
                        VALUES 
                        ('$employee_id', '$report', '$start_time', '$end_time', '$break_duration_in_minutes', '$todays_working_hours', '$todays_total_hours', '$created_at', '$updated_at')";
                $conn->query($sql);
            }
        }

        //Update activity in DB
        $update_sql = "UPDATE activities 
                       SET out_time = '$out_time', status = 'completed', updated_at = NOW() 
                       WHERE id = $activity_id AND deleted_at IS NULL";

        if ($conn->query($update_sql)) {
            $updated_ids[] = $activity_id;
        }
    }

    sendJsonResponse(
        "success",
        [
            "updated_ids" => $updated_ids,
        ],
        "Break(s) auto-closed."
    );
}

$conn->close();

// Helper: Validate date
function validateDate($date, $format = "Y-m-d")
{
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function secondsToTime($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
}
?>
