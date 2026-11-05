<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

use JsonException;
use Rhapsody\Core\Support\Json;

/**
 * Reads the model's answer in "fix" mode. Models sometimes wrap JSON in a
 * markdown fence or add a sentence around it, so the outermost object is cut
 * out first. Anything that does not have the expected shape yields null and
 * the caller falls back to showing the raw text with no automatic fix.
 *
 * @phpstan-type Fix array{file: string, description: string, search: string, replace: string}
 */
final class FixResponseParser
{
    public const MAX_FIXES = 3;

    /**
     * @return array{explanation: string, fixes: list<Fix>}|null
     */
    public static function parse(string $text): ?array
    {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        try {
            $data = Json::decodeArray(substr($text, $start, $end - $start + 1));
        } catch (JsonException) {
            return null;
        }

        $explanation = $data['explanation'] ?? null;
        if (! is_string($explanation) || trim($explanation) === '') {
            return null;
        }

        $fixes = [];
        $raw   = $data['fixes'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (count($fixes) >= self::MAX_FIXES) {
                break;
            }

            if (! is_array($item)) {
                continue;
            }

            $file    = $item['file'] ?? null;
            $search  = $item['search'] ?? null;
            $replace = $item['replace'] ?? null;
            $desc    = $item['description'] ?? '';

            if (is_string($file) && is_string($search) && is_string($replace) && is_string($desc)) {
                $fixes[] = ['file' => $file, 'description' => $desc, 'search' => $search, 'replace' => $replace];
            }
        }

        return ['explanation' => trim($explanation), 'fixes' => $fixes];
    }
}
