<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

// Include the database connection
include 'db_connection.php';

// Set the header for JSON response
header('Content-Type: application/json');

// Helper function to send JSON response
function sendJsonResponse($status, $data = null, $message = null) {
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
        case 'view':
            if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                // Prepare SELECT statement with WHERE clause using parameter binding
                $stmt = $conn->prepare("SELECT * FROM gallery WHERE employee_id = ?");
                $stmt->bind_param("i", $_GET['id']);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        $events = $result->fetch_all(MYSQLI_ASSOC);
                        sendJsonResponse('success', $events, null);
                    } else {
                        sendJsonResponse('error', null, "No leaves found : $conn->error");
                    }
                } else {
                    sendJsonResponse('error', null, "Failed to execute query : $stmt->error");
                }
            } else {
                $result = $conn->query("SELECT * FROM gallery");
                if ($result) {
                    $events = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $events);
                } else {
                    sendJsonResponse('error', null, "No records found $conn->error");
                }
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
                $tmpName = $_FILES['images']['tmp_name'][$key];
                $imagePath = $galleryDir . time() . "_" . basename($imageName);
                $created_at = date('Y-m-d H:i:s');

                // Move file to uploads directory
                if (move_uploaded_file($tmpName, $imagePath)) {
                    $stmt = $conn->prepare("INSERT INTO gallery (employee_id, url, created_by) VALUES (?, ?, ?)");
                    $stmt->bind_param("isi", $employee_id, $imagePath, $created_by);
                    
                    if ($stmt->execute()) {
                        $uploadedImageId = $conn->insert_id;

                        // Update the employee's profile field with the latest uploaded image
                        $updateStmt = $conn->prepare("UPDATE employees SET profile = ? WHERE id = ?");
                        $updateStmt->bind_param("si", $imagePath, $employee_id);
                        $updateStmt->execute();
                        $updateStmt->close();

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
                } else {
                    sendJsonResponse('error', null, "Failed to upload image");
                    exit;
                }
            }
            // Send success response with uploaded images
            sendJsonResponse('success', $uploadedImages, "Images added successfully");
            break;
        case 'view_image':
                if (isset($_GET['img'])) {
                    $imagePath = 'uploads/gallery/' . basename($_GET['img']); // Sanitize filename
                    if (file_exists($imagePath)) {
                        $mimeType = mime_content_type($imagePath);
                        header('Content-Type: ' . $mimeType);
                        readfile($imagePath);
                    } else {
                        http_response_code(404);
                        echo "Image not found.";
                    }
                } else {
                    http_response_code(400);
                    echo "Image filename is required.";
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
