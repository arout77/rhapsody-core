<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

/**
 * What the browser gets back. Fixes carry an id and a diff to review; the
 * text to replace stays on the server (see {@see AssistStore}).
 *
 * @phpstan-type FixView array{id: string, file: string, description: string, diff: string, applied: bool}
 */
final readonly class AssistResult
{
    /**
     * @param list<FixView> $fixes
     * @param list<string>  $notices
     */
    public function __construct(
        public string $mode,
        public string $snapshotId,
        public string $explanation,
        public array $fixes,
        public array $notices,
        public int $bytesSent,
        public string $provider,
        public bool $canApply,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode'        => $this->mode,
            'snapshot_id' => $this->snapshotId,
            'explanation' => $this->explanation,
            'fixes'       => $this->fixes,
            'notices'     => $this->notices,
            'bytes_sent'  => $this->bytesSent,
            'provider'    => $this->provider,
            'can_apply'   => $this->canApply,
        ];
    }
}
