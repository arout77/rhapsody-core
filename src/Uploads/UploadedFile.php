<?php
declare(strict_types=1);

namespace Rhapsody\Core\Uploads;

/**
 * A file that was validated and stored by {@see \Rhapsody\Core\FileUploader}.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $originalName,
        public string $filename,
        public string $path,
        public int $size,
        public string $mimeType,
    ) {}

    public function extension(): string
    {
        return pathinfo($this->filename, PATHINFO_EXTENSION);
    }

    /**
     * Client-safe representation. The absolute server path is deliberately
     * omitted; read it from the $path property when you need it server-side.
     *
     * @return array{original_name: string, filename: string, size: int, mime_type: string}
     */
    public function toArray(): array
    {
        return [
            'original_name' => $this->originalName,
            'filename'      => $this->filename,
            'size'          => $this->size,
            'mime_type'     => $this->mimeType,
        ];
    }
}
