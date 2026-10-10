<?php
declare(strict_types=1);

namespace Rhapsody\Core\Uploads;

/**
 * The outcome of a FileUploader::store() call.
 *
 * Errors are keyed by the client's original filename, so a UI can show each
 * message next to the right file. Problems that are not tied to one file
 * (nothing was sent, request too large) live under {@see self::REQUEST_KEY}.
 */
final readonly class UploadResult
{
    public const string REQUEST_KEY = '_request';

    /**
     * @param list<UploadedFile>          $files  Files that were stored successfully
     * @param array<string, list<string>> $errors Error messages keyed by original filename
     */
    public function __construct(
        public array $files = [],
        public array $errors = [],
    ) {}

    /** True when every file was stored and nothing went wrong. */
    public function ok(): bool
    {
        return $this->errors === [];
    }

    public function failed(): bool
    {
        return ! $this->ok();
    }

    /** Convenience for single-file fields. */
    public function first(): ?UploadedFile
    {
        return $this->files[0] ?? null;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return null;
    }

    /**
     * JSON-ready payload. Never includes absolute server paths.
     *
     * @return array{
     *     success: bool,
     *     files: list<array{original_name: string, filename: string, size: int, mime_type: string}>,
     *     errors: array<string, list<string>>
     * }
     */
    public function toArray(): array
    {
        $files = [];
        foreach ($this->files as $file) {
            $files[] = $file->toArray();
        }

        return [
            'success' => $this->ok(),
            'files'   => $files,
            'errors'  => $this->errors,
        ];
    }
}
