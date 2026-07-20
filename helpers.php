<?php 
    include 'db_connection.php';
    require_once __DIR__ . '/CloudinaryService.php';
    $config = require __DIR__ . '/config.php';

    // Helper function to send JSON response
    if (!function_exists('sendJsonResponse')) {
        function sendJsonResponse($status, $data = null, $message = null) {
            header('Content-Type: application/json');
            if ($status === 'success') {
                echo json_encode(['status' => 'success', 'data' => $data, 'message' => $message]);
            } else {
                echo json_encode(['status' => 'error', 'message' => $message]);
            }
            exit;
        }
    }

    function getGlobalStorage()
    {
        $default = 0;
        $query = "SELECT preference_value FROM global_preferences WHERE module = ? AND preference_key = ?";
        $stmt = $GLOBALS['conn']->prepare($query);
        $module = 'dashboard';
        $pref_key = 'enable_cloud_storage';
        $stmt->bind_param("ss", $module, $pref_key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $default = $row['preference_value'];
        }

        $stmt->close();
        return $default;
    }

    function convertToWebP($source, $destination, $quality = 80)
    {
        try {

            $info = getimagesize($source);
            
            switch ($info['mime']) {
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($source);
                    break;

                case 'image/png':
                    $image = @imagecreatefrompng($source);
                    if ($image) {
                        imagepalettetotruecolor($image);
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                    }
                    break;

                case 'image/gif':
                    $image = @imagecreatefromgif($source);
                    break;

                case 'image/webp':
                    // No need to convert if it's already webp, but let's handle it
                    $image = @imagecreatefromwebp($source);
                    break;

                case 'image/bmp':
                    $image = @imagecreatefrombmp($source);
                    break;

                default:
                    return false; // Unsupported format
            }
            
            if (!$image) {
                return false;
            }

            $result = imagewebp($image, $destination, $quality);
            imagedestroy($image);
            
            return $result;
        } catch (Throwable $e) {
            error_log("Error converting to WebP: " . $e->getMessage());
            return false;
        }
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

            // Convert supported images to WebP
            if (strpos($fileType, 'image/') === 0 && !in_array($fileType, ['image/svg+xml', 'image/webp'])) {
                $tempWebpPath = sys_get_temp_dir() . '/' . uniqid('webp_') . '.webp';
                if (convertToWebP($file['tmp_name'], $tempWebpPath)) {
                    $file['tmp_name'] = $tempWebpPath;
                    $file['name'] = pathinfo($file['name'], PATHINFO_FILENAME) . '.webp';
                    $fileType = 'image/webp';
                    $file['size'] = filesize($tempWebpPath);
                    $file['is_converted'] = true;
                }
            }

            $storageDriver = getGlobalStorage() == 1 ? 'cloudinary' : 'local';
            if ($storageDriver === 'cloudinary') {
                try {
                    $cloudinary = new CloudinaryService();
                    $result = $cloudinary->uploadSmart($file['tmp_name'], $file['name'], $targetDir);
                    if ($result['success']) {
                        return $result['data']['secure_url'];
                    } else {
                        throw new Exception("Cloudinary upload failed: " . $result['message']);
                    }
                } catch (Exception $e) {
                    throw new Exception("Cloudinary error: " . $e->getMessage());
                }
            }

            $originalFileName = $file['name'];
            $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
            
            if (!$extension) {
                $extension = 'pdf'; // Set default extension if missing
            }

            // Generate a unique file name explicitly including the extension
            $uniqueFileName = uniqid() . '-' . pathinfo($originalFileName, PATHINFO_FILENAME) . '.' . $extension;

            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $uniqueFileName;

            // Ensure the target directory exists and set permissions
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
                chmod($targetDir, 0777);
            }

            // Move the file to the target directory
            $moved = false;
            if (isset($file['is_converted']) && $file['is_converted']) {
                $moved = rename($file['tmp_name'], $targetPath);
            } else {
                $moved = move_uploaded_file($file['tmp_name'], $targetPath);
            }
            
            if ($moved) {
                return $targetPath;
            } else {
                throw new Exception("Failed to move uploaded file.");
            }
        } else {
            throw new Exception("File upload error: " . $file['error']);
        }
    }

    // Helper for downloading and uploading external files
    function uploadExternalFile($url, $targetDir) {
        $storageDriver = getGlobalStorage() == 1 ? 'cloudinary' : 'local';
        if ($storageDriver === 'cloudinary') {
            try {
                $cloudinary = new CloudinaryService();
                $filename = basename(parse_url($url, PHP_URL_PATH));
                if (empty($filename)) {
                    $filename = 'external_file';
                }
                $result = $cloudinary->uploadSmart($url, $filename, $targetDir);
                if ($result['success']) {
                    return $result['data']['secure_url'];
                } else {
                    throw new Exception("Cloudinary upload failed: " . $result['message']);
                }
            } catch (Exception $e) {
                throw new Exception("Cloudinary error: " . $e->getMessage());
            }
        }

        $fileContent = file_get_contents($url);
        if ($fileContent === false) {
            throw new Exception("Failed to download external file: $url");
        }
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $fileInfo = pathinfo(parse_url($url, PHP_URL_PATH));
        $fileExtension = isset($fileInfo['extension']) ? strtolower($fileInfo['extension']) : 'pdf';
        
        $fileName = uniqid('file_', true) . '.' . $fileExtension;
        $targetPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
        
        if (file_put_contents($targetPath, $fileContent) !== false) {
            return $targetPath;
        } else {
            throw new Exception("Failed to save downloaded file locally.");
        }
    }


    function handleConnectFileUpload($files) {
        $uploadedFiles = [];
        // $baseUploadDir = __DIR__ . '/../uploads/';
        $baseUploadDir = __DIR__ . '/uploads/';

        if (!is_dir($baseUploadDir)) {
            mkdir($baseUploadDir, 0777, true);
        }

        if (isset($files['name']) && !empty($files['name'][0])) {
            foreach ($files['name'] as $key => $filename) {
                
                $error = $files['error'][$key];
                if ($error !== UPLOAD_ERR_OK && $error !== UPLOAD_ERR_NO_FILE) {
                    $errorMsg = "File '$filename' failed to upload.";
                    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                        $errorMsg = "File '$filename' exceeds the server size limit.";
                    }
                    sendJsonResponse('error', null, $errorMsg);
                }
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $tmpName = $files['tmp_name'][$key];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                $isConverted = false;
                $fileType = mime_content_type($tmpName);
                if (strpos($fileType, 'image/') === 0 && !in_array($fileType, ['image/svg+xml', 'image/webp'])) {
                    $tempWebpPath = sys_get_temp_dir() . '/' . uniqid('webp_') . '.webp';
                    if (convertToWebP($tmpName, $tempWebpPath)) {
                        $tmpName = $tempWebpPath;
                        $ext = 'webp';
                        $filename = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
                        $isConverted = true;
                    }
                }

                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $folder = 'images/';
                } elseif (in_array($ext, ['mp4','avi','mov','webm','mp3','wav'])) {
                    $folder = 'media/';
                } elseif (in_array($ext, ['zip','rar','7z'])) {
                    $folder = 'zip/';
                } else {
                    $folder = 'files/';
                }

                $uploadDir = $baseUploadDir . $folder;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $newName = uniqid('file_', true) . "." . $ext;
                $destination = $uploadDir . $newName;
                $storageDriver = getGlobalStorage() == 1 ? 'cloudinary' : 'local';
                if ($storageDriver === 'cloudinary') {
                    try {
                        $cloudinary = new CloudinaryService();
                        $result = $cloudinary->uploadSmart($tmpName, $filename, 'uploads/' . trim($folder, '/'));
                        if ($result['success']) {
                            $uploadedFiles[] = $result['data']['secure_url'];
                        } else {
                            sendJsonResponse('error', null, "Failed to upload file {$filename}: " . $result['message']);
                        }
                    } catch (Exception $e) {
                        sendJsonResponse('error', null, "Failed to upload file {$filename}: " . $e->getMessage());
                    }
                } else {
                    $moved = false;
                    if (isset($isConverted) && $isConverted) {
                        $moved = rename($tmpName, $destination);
                    } else {
                        $moved = move_uploaded_file($tmpName, $destination);
                    }
                    if ($moved) {
                        $uploadedFiles[] = 'uploads/' . $folder . $newName;
                    }
                }
            }
        }
    return $uploadedFiles;
    }

    // Generic file deletion helper
    function deleteFile($filePath) {
        if (empty($filePath)) return;

        // If it's a Cloudinary URL
        if (strpos($filePath, 'res.cloudinary.com') !== false) {
            $matches = [];
            // Extracts the public ID from the Cloudinary URL (removing the version and extension)
            if (preg_match('/upload\/(?:v\d+\/)?([^\.]+)/', $filePath, $matches)) {
                $publicId = $matches[1];
                try {
                    $cloudinary = new CloudinaryService();
                    $cloudinary->delete($publicId);
                } catch (Exception $e) {
                    error_log("Cloudinary delete error: " . $e->getMessage());
                }
            }
        } else {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

?>