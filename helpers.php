<?php 

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

    // File upload helper function
    function uploadFile($file, $targetDir, $allowedTypes = [], $maxSize = 2 * 1024 * 1024)
    {
        if ($file['error'] === UPLOAD_ERR_OK) {
            $fileType = mime_content_type($file['tmp_name']);
            error_log("Detected MIME Type: " . $fileType);
            $fileSize = $file['size'];

            // Validate file type
            if (!empty($allowedTypes) && !in_array($fileType, $allowedTypes)) {
                sendJsonResponse('error', null, "Invalid file type: $fileType");
            }

            // Validate file size
            if ($fileSize > $maxSize) {
                throw new Exception("File size exceeds the maximum allowed size of $maxSize bytes");
            }

            $originalFileName = $file['name'];
            $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
            
            if (!$extension) {
                $extension = 'pdf'; // Set default extension if missing
            }

            // Generate a unique file name
            $uniqueFileName = uniqid() . '-' . basename($file['name']);

            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $uniqueFileName;

            // Ensure the target directory exists and set permissions
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
                chmod($targetDir, 0777);
            }

            // Move the file to the target directory
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                return $targetPath;
            } else {
                throw new Exception("Failed to move uploaded file.");
            }
        } else {
            throw new Exception("File upload error: " . $file['error']);
        }
    }

?>