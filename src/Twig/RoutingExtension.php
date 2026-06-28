<?php
namespace Rhapsody\Core\Twig;

use Rhapsody\Core\Routing\Router;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class RoutingExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('route', [$this, 'generateUrl']),
        ];
    }

    /**
     * Twig function: {{ route('login') }} or {{ route('user.profile', {id: 42}) }}
     */
    public function generateUrl(string $name, array $params = []): string
    {
        return Router::generateUrl($name, $params);
    }
}
