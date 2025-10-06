<?php

// ini_set('upload_max_filesize', '200M');
// ini_set('max_file_uploads', '20');
// ini_set('memory_limit', '256M');
// ini_set('max_execution_time', '300');
// ini_set('max_input_time', '300');

ini_set('display_errors', '1');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

include 'db_connection.php';
include 'auth_validate.php';
require_once 'helpers.php';

$table = 'resources';
$uploadDir = 'uploads/resources';
$action = !empty($_GET['action']) ? $_GET['action'] : 'view';    

function validateType($type) {
    $allowed = ['Git', 'Excel', 'Codebase'];
    return in_array($type, $allowed);
}

if (isset($action)) {
    switch ($action) {
        case 'view':
            $type = $_GET['type'] ?? null;
            $search = $_GET['search'] ?? null;
            $where = '';
            if ($type && validateType($type)) {
                $where .= "WHERE type = '" . $conn->real_escape_string($type) . "'";
            }

            if ($search) {
                if ($where) {
                    $where .= " AND title LIKE '%" . $conn->real_escape_string($search) . "%'";
                } else {
                    $where .= "WHERE title LIKE '%" . $conn->real_escape_string($search) . "%'";
                }
            }

            // Build the SQL query
            $sql = "SELECT * FROM $table $where ORDER BY id DESC";
            $result = $conn->query($sql);
            if ($result) {
                $links = $result->fetch_all(MYSQLI_ASSOC);
                sendJsonResponse('success', $links);
            } else {
                sendJsonResponse('error', null, 'No records found');
            }
            break;
        case 'add':
            $type = $_POST['type'] ?? '';
            $type = ucfirst(strtolower($type));
            $title = $_POST['title'] ?? '';
            $url = $_POST['url'] ?? '';
            $filePath = '';
            if (!validateType($type)) {
                sendJsonResponse('error', null, 'Invalid file type');
            }
            if (!$title) {
                sendJsonResponse('error', null, 'Title is required');
            }
            if ($type === 'Git' && !$url) {
                sendJsonResponse('error', null, 'URL is required for Git');
            }
            if (($type === 'Excel' || $type === 'Codebase')) {
                if (empty($url) && empty($_FILES['file_path']['name'])) {
                    sendJsonResponse('error', null, 'Either URL or File is required for Excel/Codebase');
                }
                if (!empty($_FILES['file_path']['name'])) {
                    // if ($_FILES['file_path']['size'] > 5 * 1024 * 1024) {
                    //     sendJsonResponse('error', null, 'File size must not exceed 5MB');
                    // }
                    try {
                        $filePath = uploadFile($_FILES['file_path'], $uploadDir, [], 512 * 1024 * 1024);
                    } catch (Exception $e) {
                        sendJsonResponse('error', null, $e->getMessage());
                    }
                }
            }
            $stmt = $conn->prepare("INSERT INTO $table (type, title, url, file_path) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param('ssss', $type, $title, $url, $filePath);
            if ($stmt->execute()) {
                sendJsonResponse('success', ['id' => $stmt->insert_id], 'Data added successfully');
            } else {
                sendJsonResponse('error', null, 'Failed to add link: ' . $stmt->error);
            }
            break;
        case 'edit':
            $id = $_POST['id'] ?? 0;
            $type = $_POST['type'] ?? '';
            $type = ucfirst(strtolower($type));
            $title = $_POST['title'] ?? '';
            $url = $_POST['url'] ?? '';
            $filePath = $_POST['file_path'] ?? '';
            if (!validateType($type)) {
                sendJsonResponse('error', null, 'Invalid file type');
            }
            if (!$id || !$title) {
                sendJsonResponse('error', null, 'ID and Title are required');
            }
            if ($type === 'Git' && !$url) {
                sendJsonResponse('error', null, 'URL is required for Git');
            }
            if (($type === 'Excel' || $type === 'Codebase')) {
                if (empty($url) && empty($_FILES['file_path']['name']) && empty($filePath)) {
                    sendJsonResponse('error', null, 'Either URL or File is required for Excel/Codebase');
                }
                if (!empty($_FILES['file_path']['name'])) {
                    // if ($_FILES['file_path']['size'] > 5 * 1024 * 1024) {
                    //     sendJsonResponse('error', null, 'File size must not exceed 5MB');
                    // }
                    try {
                        $filePath = uploadFile($_FILES['file_path'], $uploadDir, [], 512 * 1024 * 1024);
                    } catch (Exception $e) {
                        sendJsonResponse('error', null, $e->getMessage());
                    }
                }
            }
            $stmt = $conn->prepare("UPDATE $table SET type=?, title=?, url=?, file_path=? WHERE id=?");
            if (!$stmt) {
                sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param('ssssi', $type, $title, $url, $filePath, $id);
            if ($stmt->execute()) {
                sendJsonResponse('success', null, 'Link updated successfully');
            } else {
                sendJsonResponse('error', null, 'Failed to update link: ' . $stmt->error);
            }
            break;
        case 'delete':
            // GET: id
            $id = $_GET['id'] ?? 0;
            if (!$id) {
                sendJsonResponse('error', null, 'ID is required');
            }
            $stmt = $conn->prepare("DELETE FROM $table WHERE id=?");
            if (!$stmt) {
                sendJsonResponse('error', null, 'Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                sendJsonResponse('success', null, 'Link deleted successfully');
            } else {
                sendJsonResponse('error', null, 'Failed to delete link: ' . $stmt->error);
            }
            break;
        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
}

$conn->close();