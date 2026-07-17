<?php

require_once __DIR__ . '/vendor/autoload.php';

use Cloudinary\Cloudinary;

class CloudinaryService
{
    private $cloudinary;

    public function __construct()
    {
        $config = require __DIR__ . '/config.php';
        
        if (!isset($config['cloudinary'])) {
            throw new Exception("Cloudinary configuration not found in config.php.");
        }

        $cloudinaryConfig = $config['cloudinary'];

        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => $cloudinaryConfig['cloud_name'],
                'api_key'    => $cloudinaryConfig['api_key'],
                'api_secret' => $cloudinaryConfig['api_secret'],
            ],
            'url' => [
                'secure' => true
            ]
        ]);
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
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
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
