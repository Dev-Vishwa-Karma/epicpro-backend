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
require_once __DIR__ . '/pusher.php';
$config = require __DIR__ . '/config.php';

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
$pusher = getPusher($config);
if (isset($action)) {
    switch ($action) {
        case 'add':
            $module_type = $_POST['module_type'] ?? NULL;
            $module_id = $_POST['module_id'] ?? NULL;
            $message = $_POST['message'] ?? NULL;
            $user_id = $_POST['user_id'] ?? NULL;
            $parent_comment_id = !empty($_POST['parent_comment_id']) ? $_POST['parent_comment_id'] : NULL;

            // Fallback for old API calls (e.g. from tickets.php if frontend wasn't updated)
            if (!$module_type && isset($_POST['ticket_id'])) $module_type = 'ticket';
            if (!$module_id && isset($_POST['ticket_id'])) $module_id = $_POST['ticket_id'];
            if (!$message && isset($_POST['comment'])) $message = $_POST['comment'];
            if (!$user_id && isset($_POST['comment_by'])) $user_id = $_POST['comment_by'];

            if ($module_type && $module_id && $message && $user_id) {
                $stmt = $conn->prepare("INSERT INTO comments (module_type, module_id, message, user_id, parent_comment_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sisii", $module_type, $module_id, $message, $user_id, $parent_comment_id);

                if ($stmt->execute()) {
                    $inserted_id = $stmt->insert_id;

                    $getUser = $conn->query("SELECT id, profile, first_name, last_name, email FROM employees WHERE id = " . intval($user_id));
                    $user = $getUser->fetch_assoc();

                    $getComment = $conn->query("SELECT created_at FROM comments WHERE id = " . $inserted_id);
                    $commentData = $getComment->fetch_assoc();

                    $newComment = [
                        'id' => $inserted_id,
                        'module_type' => $module_type,
                        'module_id' => $module_id,
                        'message' => $message,
                        'parent_comment_id' => $parent_comment_id,
                        'commented_by' => $user ? [
                            'employee_id' => $user['id'],
                            'first_name' => $user['first_name'],
                            'last_name' => $user['last_name'],
                            'email' => $user['email'],
                            'profile' => $user['profile']
                        ] : NULL,
                        'created_at' => $commentData['created_at'] ?? null,
                        'replies' => []
                    ];

                    // To maintain backward compatibility with any frontend code relying on these fields
                    $newComment['comment_comment'] = $message;
                    $newComment['comment_created_at'] = $commentData['created_at'] ?? null;
                    if ($module_type === 'ticket') {
                        $newComment['ticket_id'] = $module_id;
                    }

                    $pusher->trigger($config['pusher']['channel'], 'comment_updated_' . $module_type . '_' . $module_id, [
                        'status' => 'success',
                        'action' => 'add',
                        'comment' => $newComment
                    ]);

                    sendJsonResponse('success', $newComment, 'Comment added successfully');
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to add comment']);
                }
                $stmt->close();
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            }
            break;

        case 'view':
            $module_type = $_GET['module_type'] ?? NULL;
            $module_id = $_GET['module_id'] ?? NULL;

            if ($module_type && $module_id) {
                $query = "
                    SELECT 
                        c.id, c.message, c.parent_comment_id, c.created_at, c.modified_at,
                        e.id AS emp_id, e.first_name, e.last_name, e.email, e.profile
                    FROM comments c
                    LEFT JOIN employees e ON c.user_id = e.id
                    WHERE c.module_type = ? AND c.module_id = ? AND c.deleted_at IS NULL
                    ORDER BY c.created_at ASC
                ";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("si", $module_type, $module_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $commentMap = [];

                while ($row = $result->fetch_assoc()) {
                    $comment = [
                        'id' => $row['id'],
                        'message' => $row['message'],
                        'parent_comment_id' => $row['parent_comment_id'],
                        'created_at' => $row['created_at'],
                        'modified_at' => $row['modified_at'],
                        'commented_by' => $row['emp_id'] ? [
                            'employee_id' => $row['emp_id'],
                            'first_name' => $row['first_name'],
                            'last_name' => $row['last_name'],
                            'email' => $row['email'],
                            'profile' => $row['profile']
                        ] : NULL,
                        'replies' => []
                    ];
                    $commentMap[$row['id']] = $comment;
                }
                
                $tree = [];
                foreach ($commentMap as $id => &$c) {
                    if ($c['parent_comment_id'] == null) {
                        $tree[] = &$c;
                    } else {
                        if (isset($commentMap[$c['parent_comment_id']])) {
                            $commentMap[$c['parent_comment_id']]['replies'][] = &$c;
                        }
                    }
                }

                sendJsonResponse('success', $tree, 'Comments retrieved successfully');
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Missing required fields: module_type and module_id']);
            }
            break;

        case 'delete':
            $comment_id = $_POST['comment_id'] ?? NULL;
            $module_type = $_POST['module_type'] ?? NULL;
            $module_id = $_POST['module_id'] ?? NULL;
            if ($comment_id) {
                $stmt = $conn->prepare("UPDATE comments SET deleted_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $comment_id);
                if ($stmt->execute()) {
                    $pusher->trigger($config['pusher']['channel'], 'comment_updated_' . $module_type . '_' . $module_id, [
                        'status' => 'success',
                        'action' => 'delete',
                        'comment_id' => $comment_id
                    ]);
                    sendJsonResponse('success', null, 'Comment deleted successfully');
                } else {
                    sendJsonResponse('error', null, 'Failed to delete comment');
                }
                $stmt->close();
            } else {
                sendJsonResponse('error', null, 'Missing comment_id');
            }
            break;

        case 'edit':
            $comment_id = $_POST['comment_id'] ?? NULL;
            $message = $_POST['message'] ?? NULL;
            $module_type = $_POST['module_type'] ?? NULL;
            $module_id = $_POST['module_id'] ?? NULL;
            if ($comment_id && $message) {
                $stmt = $conn->prepare("UPDATE comments SET message = ?, modified_at = NOW() WHERE id = ? AND deleted_at IS NULL");
                $stmt->bind_param("si", $message, $comment_id);
                if ($stmt->execute()) {
                    $pusher->trigger($config['pusher']['channel'], 'comment_updated_' . $module_type . '_' . $module_id, [
                        'status' => 'success',
                        'action' => 'edit',
                        'comment' => [
                            'id' => $comment_id,
                            'message' => $message
                        ]
                    ]);
                    sendJsonResponse('success', null, 'Comment updated successfully');
                } else {
                    sendJsonResponse('error', null, 'Failed to update comment');
                }
                $stmt->close();
            } else {
                sendJsonResponse('error', null, 'Missing comment_id or message');
            }
            break;
    }
}
?>
