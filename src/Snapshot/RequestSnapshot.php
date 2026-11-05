<?php
declare(strict_types=1);

namespace Rhapsody\Core\Snapshot;

use JsonException;
use Rhapsody\Core\Support\Json;

/**
 * An already-redacted, JSON-serializable picture of one request.
 *
 * Instances are only ever created by {@see SnapshotBuilder}, which runs every
 * value through the {@see Redactor}. Nothing in here is raw request data.
 */
final readonly class RequestSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param string               $id         Sortable id, also used as the file name
     * @param string               $capturedAt ISO 8601 timestamp with milliseconds
     * @param array<string, mixed> $data       The redacted sections (request, queries, logs, ...)
     */
    public function __construct(
        public string $id,
        public string $capturedAt,
        public array $data,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema'      => self::SCHEMA_VERSION,
            'id'          => $this->id,
            'captured_at' => $this->capturedAt,
        ] + $this->data;
    }

    /**
     * @throws JsonException If the data cannot be encoded
     */
    public function toJson(): string
    {
        return Json::encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
