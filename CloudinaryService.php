<?php

include 'db_connection.php';
require_once __DIR__ . '/vendor/autoload.php';

use Cloudinary\Cloudinary;

class CloudinaryService
{
    private $cloudinary;

    private function getConfig(){
        global $conn;
        $config = [];
        $result = $conn->query("SELECT * FROM service_configuration WHERE provider= 'cloudinary'");
        $row = $result->fetch_assoc();
        return json_decode($row['provider_details'], true);
    }

    public function __construct()
    {
        $config = $this->getConfig();
        try {
            if (empty($config) || !isset($config['cloud_name']) || !isset($config['api_key']) || !isset($config['api_secret'])) {
                throw new Exception("Cloudinary configuration is missing or incomplete.");
            }
            $this->cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => $config['cloud_name'],
                    'api_key'    => $config['api_key'],
                    'api_secret' => $config['api_secret'],
                ],
                'url' => [
                    'secure' => true
                ]
            ]);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function uploadSmart($filePath, $originalFileName, $targetDir)
    {
        $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        if (!$extension) {
            $extension = 'pdf';
        }

        $resourceType = 'auto';
        $fileType = @mime_content_type($filePath); 
        
        if ($fileType && (strpos($fileType, 'video/') === 0 || strpos($fileType, 'audio/') === 0)) {
            $resourceType = 'video';
        } elseif ($fileType && strpos($fileType, 'image/') === 0) {
            $resourceType = 'image';
        } elseif (in_array($extension, ['mp4','avi','mov','webm','mp3','wav'])) {
            $resourceType = 'video';
        } elseif (in_array($extension, ['jpg','jpeg','png','gif','webp'])) {
            $resourceType = 'image';
        } else {
            $resourceType = 'raw';
        }

        $cleanFileName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalFileName, PATHINFO_FILENAME));
        $publicId = uniqid() . '-' . $cleanFileName;
        
        if ($resourceType === 'raw') {
            $publicId .= '.' . $extension;
        }

        $folder = trim(str_replace('\\', '/', $targetDir), '/');
        
        $options = [
            'folder' => $folder,
            'resource_type' => $resourceType,
            'public_id' => $publicId
        ];
        
        if ($resourceType === 'image') {
            $options['format'] = 'webp';
        }

        return $this->upload($filePath, $options);
    }

    /**
     * Upload a file to Cloudinary.
     * 
     * @param string $filePath The local file path or remote URL to upload.
     * @param array $options Additional upload options (e.g. ['folder' => 'my_folder']).
     * @return array Result containing success status and upload data or error message.
     */
    public function upload($filePath, array $options = [])
    {
        $defaultOptions = [
            'resource_type' => 'auto'
        ];

        $finalOptions = array_merge($defaultOptions, $options);

        if (isset($finalOptions['resource_type']) && $finalOptions['resource_type'] === 'video') {
            $finalOptions['chunk_size'] = 6000000;
            $finalOptions['timeout'] = 300;
        }

        try {
            $result = $this->cloudinary->uploadApi()->upload($filePath, $finalOptions);
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Delete a file from Cloudinary.
     * 
     * @param string $publicId The public ID of the uploaded asset.
     * @param array $options Additional options.
     * @return array Result containing success status.
     */
    public function delete($publicId, array $options = [])
    {
        try {
            $result = $this->cloudinary->uploadApi()->destroy($publicId, $options);
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
