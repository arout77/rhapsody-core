<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

/**
 * Exactly what would be sent to the AI provider: the two strings plus a
 * human-readable breakdown. The "review before sending" step shows this, and
 * the very same object is what gets sent, so the preview cannot drift from
 * reality.
 *
 * @phpstan-type SectionInfo array{key: string, label: string, bytes: int, enabled: bool, required: bool}
 */
final readonly class AssistRequest
{
    /**
     * @param list<SectionInfo>   $sections
     * @param list<SourceExcerpt> $excerpts
     * @param list<string>        $notices
     */
    public function __construct(
        public string $mode,
        public string $snapshotId,
        public string $system,
        public string $prompt,
        public array $sections,
        public array $excerpts,
        public array $notices,
    ) {
    }

    public function bytes(): int
    {
        return strlen($this->system) + strlen($this->prompt);
    }

    /**
     * @return list<string> Keys of the sections that are included
     */
    public function includedKeys(): array
    {
        $keys = [];
        foreach ($this->sections as $section) {
            if ($section['enabled']) {
                $keys[] = $section['key'];
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPreview(string $provider): array
    {
        $excerpts = [];
        foreach ($this->excerpts as $excerpt) {
            $excerpts[] = [
                'file'      => $excerpt->file,
                'lines'     => $excerpt->range(),
                'patchable' => $excerpt->patchable,
            ];
        }

        return [
            'mode'     => $this->mode,
            'provider' => $provider,
            'bytes'    => $this->bytes(),
            'sections' => $this->sections,
            'excerpts' => $excerpts,
            'notices'  => $this->notices,
            'payload'  => "SYSTEM PROMPT\n=============\n" . $this->system . "\n\nMESSAGE\n=======\n" . $this->prompt,
        ];
    }
}
