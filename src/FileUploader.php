<?php
namespace Rhapsody\Core;

use Rhapsody\Core\Helpers\Path;

class FileUploader
{
    private array $allowedMimes  = [];
    private int $maxSize         = 5 * 1024 * 1024; // 5MB default
    private array $errors        = [];
    private array $uploadedFiles = [];
    private string $uploadDir;

    public function __construct(?string $uploadDir = null)
    {
        $this->uploadDir = $uploadDir ?? Path::storage('uploads');
        if (! is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function setAllowedMimes(array $mimes): self
    {
        $this->allowedMimes = $mimes;
        return $this;
    }

    public function setMaxSize(int $maxSize): self
    {
        $this->maxSize = $maxSize;
        return $this;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function handle(string $fieldName): bool
    {
        $this->errors        = [];
        $this->uploadedFiles = [];

        if (! isset($_FILES[$fieldName])) {
            $this->errors[] = "No files uploaded for field: {$fieldName}";
            return false;
        }

        $files = $_FILES[$fieldName];

        if (! is_array($files['name'])) {
            return $this->handleSingleFile($files);
        }

        $success = true;
        foreach ($files['name'] as $index => $name) {
            $file = [
                'name'     => $files['name'][$index],
                'type'     => $files['type'][$index],
                'tmp_name' => $files['tmp_name'][$index],
                'error'    => $files['error'][$index],
                'size'     => $files['size'][$index],
            ];

            if (! $this->handleSingleFile($file)) {
                $success = false;
            }
        }

        return $success;
    }

    private function handleSingleFile(array $file): bool
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = "Upload error for {$file['name']}: " . $this->getUploadErrorMessage($file['error']);
            return false;
        }

        if ($file['size'] > $this->maxSize) {
            $this->errors[] = "File {$file['name']} exceeds maximum size of " . ($this->maxSize / 1024 / 1024) . "MB";
            return false;
        }

        // Check MIME type
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        // finfo_close() is deprecated in PHP 8.5+, object is automatically freed.
        // Remove the call to avoid deprecation notice.
        // finfo_close($finfo);

        if (! empty($this->allowedMimes) && ! in_array($mimeType, $this->allowedMimes)) {
            $this->errors[] = "File {$file['name']} has invalid MIME type: {$mimeType}";
            return false;
        }

        $extension   = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename    = uniqid() . '.' . $extension;
        $destination = $this->uploadDir . '/' . $filename;

        if (! move_uploaded_file($file['tmp_name'], $destination)) {
            $this->errors[] = "Failed to move uploaded file: {$file['name']}";
            return false;
        }

        $this->uploadedFiles[] = [
            'original_name' => $file['name'],
            'filename'      => $filename,
            'path'          => $destination,
            'size'          => $file['size'],
            'mime_type'     => $mimeType,
        ];

        return true;
    }

    private function getUploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
            UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload',
            default               => 'Unknown upload error',
        };
    }
}
