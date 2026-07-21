<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$file = $_GET['file'] ?? '';

// Basic security: don't allow directory traversal
if (empty($file) || strpos($file, '..') !== false) {
    http_response_code(400);
    die("Invalid file parameter.");
}

// If it's a remote URL, redirect to it directly
if (preg_match('/^https?:\/\//', $file)) {
    // Add Cloudinary attachment flag to force download
    if (strpos($file, 'res.cloudinary.com') !== false && strpos($file, '/upload/') !== false) {
        if (strpos($file, '/upload/fl_attachment/') === false) {
            $file = str_replace('/upload/', '/upload/fl_attachment/', $file);
        }
    }
    header('Location: ' . $file);
    exit;
}

// Restrict downloads strictly to the "uploads" directory to prevent downloading source code (e.g., config files)
if (strpos($file, 'uploads/') !== 0) {
    http_response_code(403);
    die("Access denied. Only uploaded files can be downloaded.");
}

$filepath = __DIR__ . '/' . $file;

if (!file_exists($filepath) || !is_file($filepath)) {
    http_response_code(404);
    die("File not found.");
}

$mimeType = mime_content_type($filepath) ?: 'application/octet-stream';
$filename = basename($filepath);

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filepath));

readfile($filepath);
exit;
