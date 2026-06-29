<?php
namespace Rhapsody\Core\Twig;

use Rhapsody\Core\Storage\Cookie;
use Rhapsody\Core\Storage\LocalStorage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for cookie and localStorage helpers.
 * Available in every template.
 */
final class StorageExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // Cookie functions
            new TwigFunction('cookie_get', [Cookie::class, 'get']),
            new TwigFunction('cookie_set', [Cookie::class, 'set'], ['is_safe' => ['html']]),
            new TwigFunction('cookie_has', [Cookie::class, 'has']),
            new TwigFunction('cookie_delete', [Cookie::class, 'delete'], ['is_safe' => ['html']]),

            // LocalStorage functions (return <script> tags)
            new TwigFunction('localstorage_set', [LocalStorage::class, 'set'], ['is_safe' => ['html']]),
            new TwigFunction('localstorage_remove', [LocalStorage::class, 'remove'], ['is_safe' => ['html']]),
            new TwigFunction('localstorage_clear', [LocalStorage::class, 'clear'], ['is_safe' => ['html']]),
        ];
    }
}
