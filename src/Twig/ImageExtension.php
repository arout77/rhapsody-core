<?php
namespace Rhapsody\Core\Twig;

use Rhapsody\Core\Helpers\ImageSigner;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adds image_url() to Twig:
 *
 *   <img src="{{ image_url('rhap.png', {w: 300, q: 80}) }}" alt="">
 */
final class ImageExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('image_url', [ImageSigner::class, 'url']),
        ];
    }
}