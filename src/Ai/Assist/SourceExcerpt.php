<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

/**
 * A few lines of an application file around a stack frame, already masked by
 * {@see SourceContext}. Only files whose excerpt was sent can ever be patched.
 */
final readonly class SourceExcerpt
{
    /**
     * @param string $file      Project-relative path, forward slashes
     * @param bool   $patchable False for vendor code: it is shown to the model but never edited
     */
    public function __construct(
        public string $file,
        public int $startLine,
        public int $endLine,
        public int $errorLine,
        public string $code,
        public bool $patchable,
    ) {
    }

    public function range(): string
    {
        return $this->startLine . '-' . $this->endLine;
    }

    /**
     * The excerpt as the model sees it: `> 25| code` marks the failing line.
     */
    public function numbered(): string
    {
        $out  = [];
        $line = $this->startLine;
        foreach (explode("\n", $this->code) as $text) {
            $out[] = ($line === $this->errorLine ? '>' : ' ') . str_pad((string) $line, 5, ' ', STR_PAD_LEFT) . '| ' . $text;
            $line++;
        }

        return implode("\n", $out);
    }
}
