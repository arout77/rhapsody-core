<?php
namespace Rhapsody\Core\SEO;

class Sluggify
{
    /**
     * Converts a string into a clean, SEO-friendly URL slug.
     * Example: "1 & 2 Samuel: King David's Reign!" -> "1-2-samuel-king-davids-reign"
     *
     * @param string $text
     * @return string
     */
    public static function transform(string $text): string
    {
        // Replace ampersands with 'and' if preferred, or just remove them
        $text = str_replace('&', 'and', $text);

        // Replace non-letter or digits by a single dash
        $text = preg_replace('~[^\pL\d]+~u', '-', $text) ?? '';

        // Transliterate to ASCII characters if any accents exist
        $converted = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text      = $converted === false ? '' : $converted;

        // Remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text) ?? '';

        // Trim duplicate or trailing dashes
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text) ?? '';

        // Downcase
        $text = strtolower($text);

        if ($text === '') {
            return 'n-a';
        }

        return $text;
    }
}
