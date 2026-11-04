<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

/**
 * Splits text into lowercase search terms. Deliberately small and
 * predictable: no dictionary, no external stemmer.
 *
 * camelCase and snake_case split into parts ("AuthMiddleware" gives "auth" and
 * "middleware"), so code identifiers match plain-English questions. Stemming
 * only folds plurals ("routes" and "route"); stripping -ing/-ed would turn
 * "route" and "routing" into different stems more often than it unifies them.
 */
final class Tokenizer
{
    private const STOPWORDS = [
        'a', 'about', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'could',
        'did', 'do', 'does', 'for', 'from', 'how', 'i', 'if', 'in', 'into', 'is', 'it',
        'its', 'me', 'my', 'need', 'no', 'not', 'of', 'on', 'or', 'please', 'should',
        'so', 'that', 'the', 'there', 'this', 'to', 'want', 'we', 'what', 'when',
        'where', 'which', 'why', 'with', 'would', 'you', 'your',
    ];

    /**
     * @return list<string>
     */
    public static function tokenize(string $text): array
    {
        $text  = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $text) ?? $text;
        $parts = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            if (strlen($part) < 2 || in_array($part, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = self::stem($part);
        }

        return $tokens;
    }

    private static function stem(string $word): string
    {
        $length = strlen($word);

        if ($length > 4 && str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }

        if (
            $length > 3
            && str_ends_with($word, 's')
            && ! str_ends_with($word, 'ss')
            && ! str_ends_with($word, 'us')
            && ! str_ends_with($word, 'is')
        ) {
            return substr($word, 0, -1);
        }

        return $word;
    }
}
