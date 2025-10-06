<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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

function sendJsonResponse($status, $data = null, $message = null)
{
    header('Content-Type: application/json');
    if ($status === 'success') {
        echo json_encode(['status' => 'success', 'data' => $data, 'message' => $message]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $message]);
    }
    exit;
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';
if (isset($action)) {
    switch ($action) {
        case 'add':
            $ticket_id = $_POST['ticket_id'] ?? NULL;
            $comment = $_POST['comment'] ?? NULL;
            $comment_by = $_POST['comment_by'] ?? NULL;

            if ($ticket_id && $comment && $comment_by) {
                $stmt = $conn->prepare("INSERT INTO comments (ticket_id, comment, comment_by, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("isi", $ticket_id, $comment, $comment_by);

                if ($stmt->execute()) {
                    $inserted_id = $stmt->insert_id;

                    $getUser = $conn->query("SELECT id, profile, first_name, last_name, email FROM employees WHERE id = " . intval($comment_by));
                    $user = $getUser->fetch_assoc();

                    $getComment = $conn->query("SELECT created_at FROM comments WHERE id = " . $inserted_id);
                    $commentData = $getComment->fetch_assoc();

                    $newComment = [
                        'id' => $inserted_id,
                        'ticket_id' => $ticket_id,
                        'comment_comment' => $comment,
                        'commented_by' => $user ? [
                            'employee_id' => $user['id'],
                            'first_name' => $user['first_name'],
                            'last_name' => $user['last_name'],
                            'email' => $user['email'],
                            'profile' => $user['profile']
                        ] : NULL,
                        'comment_created_at' => $commentData['created_at'] ?? null,
                    ];

                    sendJsonResponse('success', $newComment, 'Comment added successfully');
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to add comment']);
                }
                $stmt->close();
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            }
            break;
    }
}
