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
            $attachments = $_FILES['attachments'] ?? NULL;
            $parent_comment_id = !empty($_POST['parent_comment_id']) ? $_POST['parent_comment_id'] : NULL;

            if ($module_type && $module_id && $user_id && ($message || $attachments)) {
                $stmt = $conn->prepare("INSERT INTO comments (module_type, module_id, message, user_id, parent_comment_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sisii", $module_type, $module_id, $message, $user_id, $parent_comment_id);

                if ($stmt->execute()) {
                    $inserted_id = $stmt->insert_id;

                    $getUser = $conn->query("SELECT id, profile, first_name, last_name, email FROM employees WHERE id = " . intval($user_id));
                    $user = $getUser->fetch_assoc();

                    $getComment = $conn->query("SELECT created_at FROM comments WHERE id = " . $inserted_id);
                    $commentData = $getComment->fetch_assoc();

                    $galleryDir = "uploads/comments/" . $module_type;
                    if (!is_dir($galleryDir)) {
                        mkdir($galleryDir, 0777, true);
                    }

                    $attachmentsData = [];
                    if (isset($_FILES['attachments'])) {
                        $files = $_FILES['attachments'];
                        $isMulti = is_array($files['name']);
                        $fileCount = $isMulti ? count($files['name']) : 1;
                        
                        $stmtAttach = $conn->prepare("INSERT INTO comment_attachments (comment_id, source, source_type) VALUES (?, ?, ?)");
                        
                        for ($i = 0; $i < $fileCount; $i++) {
                            $error = $isMulti ? $files['error'][$i] : $files['error'];
                            $name = $isMulti ? $files['name'][$i] : $files['name'];
                            $tmpName = $isMulti ? $files['tmp_name'][$i] : $files['tmp_name'];
                            $fileType = $isMulti ? $files['type'][$i] : $files['type'];
                            
                            if ($error === UPLOAD_ERR_OK && !empty($name)) {
                                $actualFileName = str_replace(' ', '_', basename($name));
                                $destPath = $galleryDir . '/' . $actualFileName;
                                
                                if (move_uploaded_file($tmpName, $destPath)) {
                                    $stmtAttach->bind_param("iss", $inserted_id, $destPath, $fileType);
                                    $stmtAttach->execute();
                                    
                                    $attachmentsData[] = [
                                        'id' => $stmtAttach->insert_id,
                                        'source' => $destPath,
                                        'source_type' => $fileType
                                    ];
                                }
                            }
                        }
                        if ($stmtAttach) $stmtAttach->close();
                    }

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
                        'replies' => [],
                        'attachments' => $attachmentsData
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
                        c.id, c.message, c.parent_comment_id, c.created_at, c.modified_at, c.deleted_at,
                        e.id AS emp_id, e.first_name, e.last_name, e.email, e.profile
                    FROM comments c
                    LEFT JOIN employees e ON c.user_id = e.id
                    WHERE c.module_type = ? AND c.module_id = ? 
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
                        'message' => $row['deleted_at'] ? '<p><i>Message deleted</i></p>' : $row['message'] ,
                        'parent_comment_id' => $row['parent_comment_id'],
                        'created_at' => $row['created_at'],
                        'modified_at' => $row['modified_at'],
                        'deleted_at' => $row['deleted_at'],
                        'commented_by' => $row['emp_id'] ? [
                            'employee_id' => $row['emp_id'],
                            'first_name' => $row['first_name'],
                            'last_name' => $row['last_name'],
                            'email' => $row['email'],
                            'profile' => $row['profile']
                        ] : NULL,
                        'replies' => [],
                        'attachments' => []
                    ];
                    $commentMap[$row['id']] = $comment;
                }
                
                $commentIds = array_keys($commentMap);
                if (!empty($commentIds)) {
                    $inClause = implode(',', array_fill(0, count($commentIds), '?'));
                    $attachQuery = "SELECT id, comment_id, source, source_type FROM comment_attachments WHERE comment_id IN ($inClause)";
                    $stmtAttach = $conn->prepare($attachQuery);
                    
                    $types = str_repeat('i', count($commentIds));
                    $stmtAttach->bind_param($types, ...$commentIds);
                    $stmtAttach->execute();
                    $attachResult = $stmtAttach->get_result();
                    
                    while ($attachRow = $attachResult->fetch_assoc()) {
                        $cid = $attachRow['comment_id'];
                        if (isset($commentMap[$cid]) && empty($commentMap[$cid]['deleted_at'])) {
                            $commentMap[$cid]['attachments'][] = [
                                'id' => $attachRow['id'],
                                'source' => $attachRow['source'],
                                'source_type' => $attachRow['source_type']
                            ];
                        }
                    }
                    $stmtAttach->close();
                }
                
                $tree = [];
                foreach ($commentMap as $id => &$c) {
                    if ($c['parent_comment_id'] == null) {
                        $tree[] = &$c;
                    } else {
                        if (isset($commentMap[$c['parent_comment_id']]) && empty($commentMap[$c['parent_comment_id']]['deleted_at'])) {
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

            if ($comment_id && $module_type && $module_id) {
                # TODO : DELETE ATTACHMENTS FROM STORAGE
                // $selStmt = $conn->prepare("
                //     WITH RECURSIVE comment_tree AS (
                //         SELECT id
                //         FROM comments
                //         WHERE id = ?
                //         UNION ALL
                //         SELECT c.id
                //         FROM comments c
                //         INNER JOIN comment_tree ct
                //             ON c.parent_comment_id = ct.id
                //     )
                //     SELECT source
                //     FROM comment_attachments
                //     WHERE comment_id IN (SELECT id FROM comment_tree)
                // ");

                // $selStmt->bind_param("i", $comment_id);
                // $selStmt->execute();
                // $res = $selStmt->get_result();

                // while ($row = $res->fetch_assoc()) {
                //     if (!empty($row['source']) && file_exists($row['source'])) {
                //         unlink($row['source']);
                //     }
                // }

                // $selStmt->close();
                $deleted_msg = '<p><i>Message deleted</i></p>';
                $stmt = $conn->prepare("
                    UPDATE comments
                    SET deleted_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param("i",$comment_id);
                if ($stmt->execute()) {
                    # TODO : HARD DELETE FROM REPLIES
                    // $delStmt = $conn->prepare("
                    //     DELETE FROM comments
                    //     WHERE parent_comment_id = ?
                    // ");
                    // $delStmt->bind_param("i", $comment_id);
                    // $delStmt->execute();
                    // $delStmt->close();

                    $getComment = $conn->query("
                        SELECT deleted_at
                        FROM comments
                        WHERE id = " . intval($comment_id)
                    );

                    $commentData = $getComment->fetch_assoc();
                    $pusher->trigger(
                        $config['pusher']['channel'],
                        'comment_updated_' . $module_type . '_' . $module_id,
                        [
                            'status' => 'success',
                            'action' => 'delete',
                            'comment' => [
                                'id' => $comment_id,
                                'message' => $deleted_msg,
                                'deleted_at' => $commentData['deleted_at'],
                                'replies' => [],
                                'attachments' => []
                            ]
                        ]
                    );

                    sendJsonResponse('success', null, 'Comment deleted successfully');
                } else {
                    sendJsonResponse('error', null, 'Failed to delete comment');
                }
                $stmt->close();
            } else {
                sendJsonResponse('error', null, 'Missing comment_id or module_type or module_id');
            }
            break;

        case 'edit':
            $comment_id = $_POST['comment_id'] ?? NULL;
            $message = $_POST['message'] ?? NULL;
            $module_type = $_POST['module_type'] ?? NULL;
            $module_id = $_POST['module_id'] ?? NULL;

            if ($comment_id && $module_type && $module_id && ($message || $_FILES['attachments'])) {
                $stmt = $conn->prepare("UPDATE comments SET message = ?, modified_at = NOW() WHERE id = ? AND deleted_at IS NULL");
                $stmt->bind_param("si", $message, $comment_id);
                if ($stmt->execute()) {
                    $getComment = $conn->query("SELECT modified_at FROM comments WHERE id = " . intval($comment_id));
                    $commentData = $getComment->fetch_assoc();
                    
                    // Handle attachments replacement
                    if (isset($_POST['edit_attachments']) || isset($_FILES['attachments'])) {
                        $existing_attachments = $_POST['existing_attachments'] ?? [];
                        if (!is_array($existing_attachments)) {
                            $existing_attachments = [];
                        }

                        // First find which ones to delete (those not in existing_attachments)
                        if (empty($existing_attachments)) {
                            $selStmt = $conn->prepare("SELECT id, source FROM comment_attachments WHERE comment_id = ?");
                            $selStmt->bind_param("i", $comment_id);
                        } else {
                            $clean_ids = array_map('intval', $existing_attachments);
                            $in_clause = implode(',', $clean_ids);
                            $sql = "SELECT id, source FROM comment_attachments WHERE comment_id = ? AND id NOT IN ($in_clause)";
                            $selStmt = $conn->prepare($sql);
                            $selStmt->bind_param("i", $comment_id);
                        }
                        
                        $selStmt->execute();
                        $res = $selStmt->get_result();
                        $idsToDelete = [];
                        while ($row = $res->fetch_assoc()) {
                            if (file_exists($row['source'])) {
                                unlink($row['source']); // Delete file from server
                            }
                            $idsToDelete[] = $row['id'];
                        }
                        $selStmt->close();
                        
                        if (!empty($idsToDelete)) {
                            $clean_del_ids = array_map('intval', $idsToDelete);
                            $in_del = implode(',', $clean_del_ids);
                            $conn->query("DELETE FROM comment_attachments WHERE id IN ($in_del)");
                        }

                        // Now recreate attachments (add new ones)
                        if (isset($_FILES['attachments'])) {
                            $galleryDir = "uploads/comments/" . $module_type;
                            if (!is_dir($galleryDir)) {
                                mkdir($galleryDir, 0777, true);
                            }
                            
                            $files = $_FILES['attachments'];
                            $isMulti = is_array($files['name']);
                            $fileCount = $isMulti ? count($files['name']) : 1;
                            
                            $stmtAttach = $conn->prepare("INSERT INTO comment_attachments (comment_id, source, source_type) VALUES (?, ?, ?)");
                            
                            for ($i = 0; $i < $fileCount; $i++) {
                                $error = $isMulti ? $files['error'][$i] : $files['error'];
                                $name = $isMulti ? $files['name'][$i] : $files['name'];
                                $tmpName = $isMulti ? $files['tmp_name'][$i] : $files['tmp_name'];
                                $fileType = $isMulti ? $files['type'][$i] : $files['type'];
                                
                                if ($error === UPLOAD_ERR_OK && !empty($name)) {
                                    $actualFileName = str_replace(' ', '_', basename($name));
                                    $destPath = $galleryDir . '/' . $actualFileName;
                                    
                                    if (move_uploaded_file($tmpName, $destPath)) {
                                        $stmtAttach->bind_param("iss", $comment_id, $destPath, $fileType);
                                        $stmtAttach->execute();
                                    }
                                }
                            }
                            if ($stmtAttach) $stmtAttach->close();
                        }
                    }
                    
                    // Fetch all current attachments for this comment
                    $attachmentsData = [];
                    $getAttachments = $conn->prepare("SELECT id, source, source_type FROM comment_attachments WHERE comment_id = ?");
                    $getAttachments->bind_param("i", $comment_id);
                    $getAttachments->execute();
                    $attachRes = $getAttachments->get_result();
                    while ($attachRow = $attachRes->fetch_assoc()) {
                        $attachmentsData[] = [
                            'id' => $attachRow['id'],
                            'source' => $attachRow['source'],
                            'source_type' => $attachRow['source_type']
                        ];
                    }
                    $getAttachments->close();

                    $pusher->trigger($config['pusher']['channel'], 'comment_updated_' . $module_type . '_' . $module_id, [
                        'status' => 'success',
                        'action' => 'edit',
                        'comment' => [
                            'id' => $comment_id,
                            'message' => $message,
                            'modified_at' => $commentData['modified_at'],
                            'attachments' => $attachmentsData
                        ]
                    ]);
                    sendJsonResponse('success', null, 'Comment updated successfully');
                } else {
                    sendJsonResponse('error', null, 'Failed to update comment');
                }
                $stmt->close();
            } else {
                sendJsonResponse('error', null, 'Missing comment_id or module_type or module_id or message or attachments');
            }
            break;
    }
}
?>
