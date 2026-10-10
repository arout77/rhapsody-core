<?php
declare(strict_types=1);

namespace Rhapsody\Core;

use InvalidArgumentException;
use LogicException;
use Rhapsody\Core\Helpers\Path;
use Rhapsody\Core\Uploads\UploadedFile;
use Rhapsody\Core\Uploads\UploadResult;

/**
 * Validates and stores uploaded files.
 *
 *     $result = new FileUploader($request)
 *         ->images()
 *         ->maxSize('5MB')
 *         ->store('documents');
 *
 *     return $this->json($result->toArray(), $result->ok() ? 200 : 422);
 *
 * @phpstan-type RawFile array{name: string, tmp_name: string, error: int, size: int}
 */
class FileUploader
{
    /** SVG is intentionally absent: it can carry scripts. */
    private const array IMAGE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private const array DOCUMENT_TYPES = [
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** Extensions are derived from the detected MIME type, never trusted from the client. */
    private const array MIME_EXTENSIONS = [
        'image/jpeg'         => 'jpg',
        'image/png'          => 'png',
        'image/gif'          => 'gif',
        'image/webp'         => 'webp',
        'application/pdf'    => 'pdf',
        'text/plain'         => 'txt',
        'text/csv'           => 'csv',
        'application/zip'    => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    /** Fallback extensions that must never be written to disk as executable types. */
    private const array BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht',
        'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'sh', 'bat', 'cmd', 'exe', 'htaccess',
    ];

    /** @var list<string> */
    private array $accepted = [];
    private bool $acceptAny = false;
    private int $maxSize    = 5 * 1024 * 1024;
    private string $directory;

    public function __construct(
        private readonly Request $request,
        ?string $directory = null,
    ) {
        $this->directory = rtrim($directory ?? Path::storage('uploads'), '/\\');
    }

    /**
     * Allow these MIME types. Wildcards are supported: 'image/*'.
     * Can be called more than once; types accumulate.
     */
    public function accept(string ...$types): static
    {
        foreach ($types as $type) {
            $this->accepted[] = strtolower(trim($type));
        }
        $this->acceptAny = false;

        return $this;
    }

    /** JPEG, PNG, GIF and WebP. */
    public function images(): static
    {
        return $this->accept(...self::IMAGE_TYPES);
    }

    /** PDF, plain text, and Word documents. */
    public function documents(): static
    {
        return $this->accept(...self::DOCUMENT_TYPES);
    }

    /** Explicitly opt in to any file type. Executable extensions are still never preserved. */
    public function acceptAny(): static
    {
        $this->acceptAny = true;
        $this->accepted  = [];

        return $this;
    }

    /**
     * Maximum size per file: bytes (5242880) or a string ('512KB', '5MB', '1.5GB').
     *
     * @throws InvalidArgumentException When the size cannot be parsed
     */
    public function maxSize(int|string $size): static
    {
        $this->maxSize = self::parseSize($size);

        return $this;
    }

    /** Store files in this directory instead of storage/uploads. */
    public function to(string $directory): static
    {
        $this->directory = rtrim($directory, '/\\');

        return $this;
    }

    /**
     * Validate and store every file submitted under $field.
     * Works for both single (name="doc") and multiple (name="docs[]") inputs.
     *
     * @throws LogicException When no accepted file types were configured
     */
    public function store(string $field): UploadResult
    {
        if ($this->accepted === [] && ! $this->acceptAny) {
            throw new LogicException(
                'FileUploader needs allowed file types. Call images(), documents(), accept(...) or acceptAny() before store().'
            );
        }

        $incoming = $this->normalize($this->request->getFiles()[$field] ?? null);

        if ($incoming === []) {
            $message = $this->requestTooLargeMessage() ?? "No file was received for \"{$field}\".";

            return new UploadResult(errors: [UploadResult::REQUEST_KEY => [$message]]);
        }

        $files  = [];
        $errors = [];

        foreach ($incoming as $file) {
            $outcome = $this->process($file);

            if ($outcome instanceof UploadedFile) {
                $files[] = $outcome;
            } else {
                $errors[$file['name']][] = $outcome;
            }
        }

        return new UploadResult($files, $errors);
    }

    /**
     * Overridable seam so tests can store files without a real HTTP upload.
     */
    protected function moveFile(string $from, string $to): bool
    {
        return move_uploaded_file($from, $to);
    }

    /**
     * Validates and stores one file.
     *
     * @param  RawFile              $file
     * @return UploadedFile|string  The stored file, or an error message
     */
    private function process(array $file): UploadedFile|string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return self::uploadErrorMessage($file['error']);
        }

        if ($file['size'] > $this->maxSize) {
            return 'File is too large (maximum ' . self::formatSize($this->maxSize) . ').';
        }

        $mime = new \finfo(FILEINFO_MIME_TYPE)->file($file['tmp_name']);

        if ($mime === false) {
            return 'The file type could not be determined.';
        }

        if (! $this->isAccepted($mime)) {
            return "Files of type {$mime} are not allowed.";
        }

        if (! $this->ensureDirectory()) {
            return 'The upload directory is not writable.';
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $this->extensionFor($mime, $file['name']);
        $path     = $this->directory . DIRECTORY_SEPARATOR . $filename;

        if (! $this->moveFile($file['tmp_name'], $path)) {
            return 'The file could not be saved.';
        }

        return new UploadedFile($file['name'], $filename, $path, $file['size'], $mime);
    }

    private function isAccepted(string $mime): bool
    {
        if ($this->acceptAny) {
            return true;
        }

        return array_any(
            $this->accepted,
            static fn (string $pattern): bool => $pattern === $mime
                || (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1)))
        );
    }

    private function extensionFor(string $mime, string $clientName): string
    {
        if (isset(self::MIME_EXTENSIONS[$mime])) {
            return self::MIME_EXTENSIONS[$mime];
        }

        $extension = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1
            && ! in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return $extension;
        }

        return 'bin';
    }

    private function ensureDirectory(): bool
    {
        return is_dir($this->directory)
            || mkdir($this->directory, 0755, true)
            || is_dir($this->directory);
    }

    /**
     * Flattens PHP's $_FILES shapes (single and multi-file) into one list and
     * drops empty "no file selected" slots.
     *
     * @return list<RawFile>
     */
    private function normalize(mixed $entry): array
    {
        if (! is_array($entry)) {
            return [];
        }

        $names  = $entry['name'] ?? null;
        $tmp    = $entry['tmp_name'] ?? null;
        $errors = $entry['error'] ?? null;
        $sizes  = $entry['size'] ?? null;

        if (is_string($names)) {
            if (! is_string($tmp) || ! is_int($errors) || ! is_int($sizes)
                || $errors === UPLOAD_ERR_NO_FILE) {
                return [];
            }

            return [['name' => $names, 'tmp_name' => $tmp, 'error' => $errors, 'size' => $sizes]];
        }

        if (! is_array($names) || ! is_array($tmp) || ! is_array($errors) || ! is_array($sizes)) {
            return [];
        }

        $files = [];
        foreach ($names as $index => $name) {
            $tmpName = $tmp[$index] ?? null;
            $error   = $errors[$index] ?? null;
            $size    = $sizes[$index] ?? null;

            if (! is_string($name) || ! is_string($tmpName) || ! is_int($error) || ! is_int($size)
                || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $files[] = ['name' => $name, 'tmp_name' => $tmpName, 'error' => $error, 'size' => $size];
        }

        return $files;
    }

    /**
     * When the body exceeds post_max_size PHP discards $_FILES entirely, so
     * "nothing arrived" is really "too big". Say so.
     */
    private function requestTooLargeMessage(): ?string
    {
        $length = $this->request->getServerParams()['CONTENT_LENGTH'] ?? 0;
        $limit  = self::iniBytes('post_max_size');

        if ($limit > 0 && is_numeric($length) && (int) $length > $limit) {
            return 'The upload exceeds the server limit of ' . self::formatSize($limit) . ' per request.';
        }

        return null;
    }

    private static function iniBytes(string $key): int
    {
        $value = ini_get($key);

        if ($value === false || $value === '') {
            return 0;
        }

        try {
            return self::parseSize($value);
        } catch (InvalidArgumentException) {
            return 0; // e.g. "-1" (unlimited)
        }
    }

    private static function parseSize(int|string $size): int
    {
        if (is_int($size)) {
            if ($size < 1) {
                throw new InvalidArgumentException('Maximum upload size must be greater than zero.');
            }

            return $size;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)b?$/i', trim($size), $matches) !== 1) {
            throw new InvalidArgumentException("Invalid size \"{$size}\". Use bytes or a value like '512KB', '5MB' or '1GB'.");
        }

        $multiplier = match (strtolower($matches[2])) {
            'k'     => 1024,
            'm'     => 1024 ** 2,
            'g'     => 1024 ** 3,
            default => 1,
        };

        return (int) round((float) $matches[1] * $multiplier);
    }

    private static function formatSize(int $bytes): string
    {
        foreach ([['GB', 1024 ** 3], ['MB', 1024 ** 2], ['KB', 1024]] as [$unit, $divisor]) {
            if ($bytes >= $divisor) {
                return rtrim(rtrim(number_format($bytes / $divisor, 1, '.', ''), '0'), '.') . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the size limit set by the form.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'The server failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
            default               => 'Unknown upload error.',
        };
    }
}
