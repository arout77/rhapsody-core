<?php
namespace Rhapsody\Core\Ai;

use Rhapsody\Core\Ai\Exceptions\AiException;

/**
 * A normalized result from an AI provider — deliberately not a raw decoded
 * JSON array, so callers have a stable contract even if a provider's
 * response shape shifts, and so a second provider (Claude, OpenAI, ...)
 * can be added later behind the same shape.
 *
 * Distinguishing truncation/safety-blocks from exceptions is intentional:
 * those are legitimate, expected outcomes of a successful API call, not
 * failures to communicate with the provider. A caller decides what a
 * truncated or blocked response means for their use case (e.g. "let the
 * user know it was cut off and offer to continue") rather than having that
 * decision made for them via a thrown exception.
 */
final class AiResponse
{
    public function __construct(
        private readonly string $text,
        private readonly ?string $finishReason,
        private readonly array $usage,
        private readonly array $raw
    ) {
    }

    public static function fromGeminiPayload(?array $data): self
    {
        if ($data === null) {
            throw new AiException('Received an empty or invalid response from Gemini.');
        }

        // A prompt can be blocked before any candidate is even generated
        // (e.g. the prompt itself trips a safety filter).
        if (empty($data['candidates']) && isset($data['promptFeedback']['blockReason'])) {
            return new self(
                text: '',
                finishReason: 'SAFETY',
                usage: self::extractUsage($data),
                raw: $data
            );
        }

        $candidate = $data['candidates'][0] ?? null;
        if ($candidate === null) {
            throw new AiException('Gemini returned no candidates in its response.');
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        return new self(
            text: $text,
            finishReason: $candidate['finishReason'] ?? null,
            usage: self::extractUsage($data),
            raw: $data
        );
    }

    private static function extractUsage(array $data): array
    {
        $meta = $data['usageMetadata'] ?? [];

        return [
            'prompt_tokens'     => $meta['promptTokenCount'] ?? 0,
            'completion_tokens' => $meta['candidatesTokenCount'] ?? 0,
            'total_tokens'      => $meta['totalTokenCount'] ?? 0,
        ];
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    /**
     * True if generation was cut off by maxOutputTokens rather than
     * finishing naturally — the text is real, just incomplete.
     */
    public function wasTruncated(): bool
    {
        return $this->finishReason === 'MAX_TOKENS';
    }

    /**
     * True if the prompt or response was blocked by Gemini's safety
     * filters, or flagged for recitation (near-verbatim reproduction of
     * training data). $text will be empty (or partial) in this case.
     */
    public function wasBlocked(): bool
    {
        return in_array($this->finishReason, ['SAFETY', 'RECITATION'], true);
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public function getUsage(): array
    {
        return $this->usage;
    }

    /**
     * Escape hatch: the full decoded provider payload, for anything this
     * value object doesn't expose directly.
     */
    public function getRaw(): array
    {
        return $this->raw;
    }
}
