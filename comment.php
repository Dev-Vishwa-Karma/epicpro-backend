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

require_once 'helpers.php';

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
                if ($message && strlen($message) > 4096) {
                    sendJsonResponse('error', null, 'Comment message should not exceed 4,096 characters');
                }
                if (isset($attachments['name']) && is_array($attachments['name']) && count($attachments['name']) > 5) {
                    sendJsonResponse('error', null, 'Attachments should not exceed 5');
                }
                
                if (isset($_FILES['attachments'])) {
                    $files = $_FILES['attachments'];
                    $isMulti = is_array($files['name']);
                    $fileCount = $isMulti ? count($files['name']) : 1;
                    
                    for ($i = 0; $i < $fileCount; $i++) {
                        $error = $isMulti ? $files['error'][$i] : $files['error'];
                        $name = $isMulti ? $files['name'][$i] : $files['name'];
                        if ($error !== UPLOAD_ERR_OK && $error !== UPLOAD_ERR_NO_FILE) {
                            $errorMsg = "File '$name' failed to upload.";
                            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                                $errorMsg = "File '$name' exceeds the server size limit.";
                            }
                            sendJsonResponse('error', null, $errorMsg);
                        }
                    }
                }
                $stmt = $conn->prepare("INSERT INTO comments (module_type, module_id, message, user_id, parent_comment_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sisii", $module_type, $module_id, $message, $user_id, $parent_comment_id);

                if ($stmt->execute()) {
                    $inserted_id = $stmt->insert_id;

                    $getUser = $conn->query("SELECT id, profile, first_name, last_name, email FROM employees WHERE id = " . intval($user_id));
                    $user = $getUser->fetch_assoc();

                    $getComment = $conn->query("SELECT created_at FROM comments WHERE id = " . $inserted_id);
                    $commentData = $getComment->fetch_assoc();

                    $galleryDir = "uploads/comments/" . $module_type;

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
                            $size = $isMulti ? $files['size'][$i] : $files['size'];
                            
                            if ($error === UPLOAD_ERR_OK && !empty($name)) {
                                $singleFile = [
                                    'name' => $name,
                                    'type' => $fileType,
                                    'tmp_name' => $tmpName,
                                    'error' => $error,
                                    'size' => $size
                                ];
                                
                                try {
                                    $destPath = uploadFile($singleFile, $galleryDir, [], 50 * 1024 * 1024);
                                    
                                    $stmtAttach->bind_param("iss", $inserted_id, $destPath, $fileType);
                                    $stmtAttach->execute();
                                    
                                    $attachmentsData[] = [
                                        'id' => $stmtAttach->insert_id,
                                        'source' => $destPath,
                                        'source_type' => $fileType
                                    ];
                                } catch (Exception $e) {
                                    $conn->query("DELETE FROM comments WHERE id = " . intval($inserted_id));
                                    sendJsonResponse('error', null, "Failed to upload file {$name}: " . $e->getMessage());
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
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0;
            $page = isset($_GET['page']) ? intval($_GET['page']) : 1;

            if ($module_type && $module_id) {
                if ($limit > 0) {
                    $offset = ($page - 1) * $limit;
                    $query = "
                        SELECT 
                            c.id, c.message, c.parent_comment_id, c.created_at, c.modified_at, c.deleted_at,
                            e.id AS emp_id, e.first_name, e.last_name, e.email, e.profile,
                            ca.id AS attach_id, ca.source, ca.source_type
                        FROM comments c
                        LEFT JOIN employees e ON c.user_id = e.id
                        LEFT JOIN comment_attachments ca ON c.id = ca.comment_id
                        WHERE c.id IN (
                            SELECT id FROM (
                                SELECT id FROM comments 
                                WHERE module_type = ? AND module_id = ? 
                                ORDER BY created_at DESC LIMIT ? OFFSET ?
                            ) AS recent_comments
                        )
                        OR c.id IN (
                            SELECT parent_comment_id FROM (
                                SELECT parent_comment_id FROM comments 
                                WHERE module_type = ? AND module_id = ? AND parent_comment_id IS NOT NULL 
                                ORDER BY created_at DESC LIMIT ? OFFSET ?
                            ) AS recent_parents
                        )
                        ORDER BY c.created_at ASC
                    ";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("siiisiii", $module_type, $module_id, $limit, $offset, $module_type, $module_id, $limit, $offset);
                } else {
                    $query = "
                        SELECT 
                            c.id, c.message, c.parent_comment_id, c.created_at, c.modified_at, c.deleted_at,
                            e.id AS emp_id, e.first_name, e.last_name, e.email, e.profile,
                            ca.id AS attach_id, ca.source, ca.source_type
                        FROM comments c
                        LEFT JOIN employees e ON c.user_id = e.id
                        LEFT JOIN comment_attachments ca ON c.id = ca.comment_id
                        WHERE c.module_type = ? AND c.module_id = ? 
                        ORDER BY c.created_at ASC
                    ";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("si", $module_type, $module_id);
                }
                
                $stmt->execute();
                $result = $stmt->get_result();
                
                $commentMap = [];

                while ($row = $result->fetch_assoc()) {
                    $cid = $row['id'];
                    if (!isset($commentMap[$cid])) {
                        $commentMap[$cid] = [
                            'id' => $cid,
                            'message' => $row['deleted_at'] ? '<p><i>Message deleted</i></p>' : $row['message'],
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
                    }
                    if (!empty($row['attach_id']) && empty($row['deleted_at'])) {
                        $commentMap[$cid]['attachments'][] = [
                            'id' => $row['attach_id'],
                            'source' => $row['source'],
                            'source_type' => $row['source_type']
                        ];
                    }
                }
                $stmt->close();
                
                $tree = [];
                foreach ($commentMap as $id => &$c) {
                    if ($c['parent_comment_id'] == null || !isset($commentMap[$c['parent_comment_id']])) {
                        $tree[] = &$c;
                    } else {
                        if (empty($commentMap[$c['parent_comment_id']]['deleted_at'])) {
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
                $getComments = $conn->prepare("
                    WITH RECURSIVE comment_tree AS (
                        SELECT id
                        FROM comments
                        WHERE id = ?

                        UNION ALL

                        SELECT c.id
                        FROM comments c
                        INNER JOIN comment_tree ct
                            ON c.parent_comment_id = ct.id
                    )
                    SELECT id
                    FROM comment_tree
                ");
                $getComments->bind_param("i", $comment_id);
                $getComments->execute();
                $getCommentsResult = $getComments->get_result();
                $comments = $getCommentsResult->fetch_all(MYSQLI_ASSOC);

                $commentIds = array_column($comments, 'id');
                $commentIds = array_map('intval', $commentIds);

                if (!empty($commentIds)) {
                    $deleted_msg = '<p><i>Message deleted</i></p>';
                    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
                    $types = str_repeat('i', count($commentIds));
                    $stmt = $conn->prepare("
                        UPDATE comments
                        SET deleted_at = NOW()
                        WHERE id IN ($placeholders)
                    ");

                    $stmt->bind_param($types, ...$commentIds);
                    $stmt->execute();


                    $stmt = $conn->prepare("
                        UPDATE comment_attachments
                        SET deleted_at = NOW()
                        WHERE comment_id IN ($placeholders)
                    ");

                    $stmt->bind_param($types, ...$commentIds);
                    $stmt->execute();
                    
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

            $has_files = isset($_FILES['attachments']);
            $has_edit = isset($_POST['edit_attachments']);

            if ($comment_id && $module_type && $module_id && ($message || $has_files || $has_edit)) {
                if ($message && strlen($message) > 4096) {
                    sendJsonResponse('error', null, 'Comment message should not exceed 4,096 characters');
                }
                
                $newFilesCount = ($has_files && isset($_FILES['attachments']['name'])) ? (is_array($_FILES['attachments']['name']) ? count($_FILES['attachments']['name']) : 1) : 0;
                $existingFilesCount = isset($_POST['existing_attachments']) ? (is_array($_POST['existing_attachments']) ? count($_POST['existing_attachments']) : 1) : 0;
                
                if (($newFilesCount + $existingFilesCount) > 5) {
                    sendJsonResponse('error', null, 'Attachments should not exceed 5');
                }

                if (isset($_FILES['attachments'])) {
                    $files = $_FILES['attachments'];
                    $isMulti = is_array($files['name']);
                    $fileCount = $isMulti ? count($files['name']) : 1;
                    
                    for ($i = 0; $i < $fileCount; $i++) {
                        $error = $isMulti ? $files['error'][$i] : $files['error'];
                        $name = $isMulti ? $files['name'][$i] : $files['name'];
                        if ($error !== UPLOAD_ERR_OK && $error !== UPLOAD_ERR_NO_FILE) {
                            $errorMsg = "File '$name' failed to upload.";
                            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                                $errorMsg = "File '$name' exceeds the server size limit.";
                            }
                            sendJsonResponse('error', null, $errorMsg);
                        }
                    }
                }
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
                            deleteFile($row['source']); // Delete file from server or cloudinary

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

                            
                            $files = $_FILES['attachments'];
                            $isMulti = is_array($files['name']);
                            $fileCount = $isMulti ? count($files['name']) : 1;
                            
                            $stmtAttach = $conn->prepare("INSERT INTO comment_attachments (comment_id, source, source_type) VALUES (?, ?, ?)");
                            
                            for ($i = 0; $i < $fileCount; $i++) {
                                $error = $isMulti ? $files['error'][$i] : $files['error'];
                                $name = $isMulti ? $files['name'][$i] : $files['name'];
                                $tmpName = $isMulti ? $files['tmp_name'][$i] : $files['tmp_name'];
                                $fileType = $isMulti ? $files['type'][$i] : $files['type'];
                                $size = $isMulti ? $files['size'][$i] : $files['size'];
                                
                                if ($error === UPLOAD_ERR_OK && !empty($name)) {
                                    $singleFile = [
                                        'name' => $name,
                                        'type' => $fileType,
                                        'tmp_name' => $tmpName,
                                        'error' => $error,
                                        'size' => $size
                                    ];
                                    
                                    try {
                                        $destPath = uploadFile($singleFile, $galleryDir, [], 50 * 1024 * 1024);
                                        
                                        $stmtAttach->bind_param("iss", $comment_id, $destPath, $fileType);
                                        $stmtAttach->execute();
                                    } catch (Exception $e) {
                                        sendJsonResponse('error', null, "Failed to upload file {$name}: " . $e->getMessage());
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
