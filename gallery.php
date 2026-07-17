<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include the database connection
include 'db_connection.php';
//include 'auth_validate.php';

// Set the header for JSON response
header('Content-Type: application/json');

require_once 'helpers.php';



$action = !empty($_GET['action']) ? $_GET['action'] : 'view';

if (isset($action)) {
    switch ($action) {
        case 'view':
            // Determine the employee ID
            if (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0) {
                $employeeId = (int)$_GET['user_id'];
                $employeeIdCondition = "AND employee_id = ?";
                $params = [$employeeId];
                $paramTypes = "i";
            } else {
                $employeeIdCondition = "";
                $params = [];
                $paramTypes = "";
            }

            // Handle pagination inputs
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : null;
            $offset = ($page - 1) * $limit;
            
            // Handle sort order
            $sortOrder = 'DESC'; // Default to newest
            if (isset($_GET['sortOrder']) && in_array(strtoupper($_GET['sortOrder']), ['ASC', 'DESC'])) {
                $sortOrder = strtoupper($_GET['sortOrder']);
            }

            // Build the base query (now includes $employeeIdCondition properly)
            $query = "SELECT * FROM gallery WHERE 1=1 AND employee_id IN (SELECT id FROM employees WHERE deleted_at IS NULL AND status = 1) $employeeIdCondition";

            // Add pagination to query if limit and offset are set
            if ($limit !== null && $offset !== null) {
                $query .= " ORDER BY created_at $sortOrder LIMIT ? OFFSET ?";
                // Add pagination parameters to the params array
                array_push($params, $limit, $offset);
                $paramTypes .= "ii";
            } else {
                $query .= " ORDER BY created_at $sortOrder";
            }

            // Prepare and bind
            $stmt = $conn->prepare($query);

            if (!empty($paramTypes)) {
                $stmt->bind_param($paramTypes, ...$params);
            }

            // Execute and handle results
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    $events = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $events, null);
                } else {
                    sendJsonResponse('error', null, "No records found: $conn->error");
                }
            } else {
                sendJsonResponse('error', null, "Failed to execute query: $stmt->error");
            }
            break;

        case 'add':
            // Get form data
            $employee_id = isset($_POST['employee_id']) ? $_POST['employee_id'] : null;
            $created_by = isset($_POST['created_by']) ? $_POST['created_by'] : null;
            $images = $_FILES['images']['name'];

            // Check if files are upload
            if (empty($employee_id) || !isset($_FILES['images'])) {
                sendJsonResponse('error', null, "All fields are required");
                exit;
            }

            $uploadedImages = [];
            $galleryDir = 'uploads/gallery/';
            
            // Check if the directory exists, if not, create it with proper permissions
            if (!is_dir($galleryDir)) {
                mkdir($galleryDir, 0777, true);
            }

            foreach ($_FILES['images']['name'] as $key => $imageName) {
                $singleFile = [
                    'name' => $_FILES['images']['name'][$key],
                    'type' => $_FILES['images']['type'][$key],
                    'tmp_name' => $_FILES['images']['tmp_name'][$key],
                    'error' => $_FILES['images']['error'][$key],
                    'size' => $_FILES['images']['size'][$key]
                ];
                $created_at = date('Y-m-d H:i:s');

                try {
                    $imagePath = uploadFile($singleFile, 'uploads/gallery');
                    $stmt = $conn->prepare("INSERT INTO gallery (employee_id, url, created_by) VALUES (?, ?, ?)");
                    $stmt->bind_param("isi", $employee_id, $imagePath, $created_by);
                    
                    if ($stmt->execute()) {
                        $uploadedImageId = $conn->insert_id;

                        // Update the employee's profile field with the latest uploaded image
                        // $updateStmt = $conn->prepare("UPDATE employees SET profile = ? WHERE id = ?");
                        // $updateStmt->bind_param("si", $imagePath, $employee_id);
                        // $updateStmt->execute();
                        // $updateStmt->close();

                        $uploadedImages[] = [
                            'id' => $uploadedImageId,
                            'employee_id' => $employee_id,
                            'url' => $imagePath,
                            'created_at' => $created_at,
                            'created_by' => $created_by
                        ];
                    } else {
                        sendJsonResponse('error', null, "Failed to add image: " . $stmt->error);
                        exit;
                    }
                } catch (Exception $e) {
                    sendJsonResponse('error', null, "Failed to upload image: " . $e->getMessage());
                    exit;
                }
            }
            // Send success response with uploaded images
            sendJsonResponse('success', $uploadedImages, "Images added successfully");
            break;
        case 'view_image':
                if (isset($_GET['img'])) {
                    $imgParam = $_GET['img'];
                    $parsedUrl = parse_url($imgParam);
                    $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : $imgParam;
                    $filename = basename($path);
                    $imagePath = 'uploads/gallery/' . $filename;
                    
                    if (file_exists($imagePath)) {
                        $mimeType = mime_content_type($imagePath);
                        header('Content-Type: ' . $mimeType);
                        readfile($imagePath);
                        exit;
                    } else if (preg_match('/^https?:\/\//', $imgParam)) {
                        header('Location: ' . $imgParam);
                        exit;
                    } else {
                        http_response_code(404);
                        echo "Image not found.";
                    }
                } else {
                    http_response_code(400);
                    echo "Image filename is required.";
                }
            break;
        case 'delete':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                $imageId = intval($_GET['id']);
               
                // Escape the ID just in case (although intval already safe)
                $imageIdEscaped = $conn->real_escape_string($imageId);

                // Fetch image path from DB
                $result = $conn->query("SELECT url FROM gallery WHERE id = $imageIdEscaped");

                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $imagePath = $row['url'];

                    $absolutePath = __DIR__ . '/' . $imagePath;
                     
                    // Use helper to delete (handles Cloudinary and local)
                    deleteFile($imagePath);

                    // Delete the DB record
                    if ($conn->query("DELETE FROM gallery WHERE id = $imageIdEscaped")) {
                        sendJsonResponse('success', null, "Image deleted successfully");
                    } else {
                        sendJsonResponse('error', null, "Failed to delete image record: " . $conn->error);
                    }
                } else {
                    sendJsonResponse('error', null, "Image not found");
                }
            } else {
                sendJsonResponse('error', null, "Valid image ID required");
            }
            break;


        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            exit;
    }
}

// Close the connection
$conn->close();
?>
