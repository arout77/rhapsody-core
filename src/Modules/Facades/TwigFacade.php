<?php

namespace Rhapsody\Core\Modules\Facades;

use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;
use Twig\Environment;
use Twig\Extension\ExtensionInterface;
use Twig\TwigFunction;

final class TwigFacade
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ModulePermissions $permissions,
        private readonly string $slug,
    ) {
    }

    public function addExtension(ExtensionInterface $extension): void
    {
        if (! $this->permissions->can('twig.extensions')) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to register a Twig extension without declaring \"twig.extensions\""
            );
        }
        $this->twig->addExtension($extension);
    }

    public function addFunction(string $name, callable $callback, array $options = []): void
    {
        if (! $this->permissions->can('twig.functions')) {
            throw new ModulePermissionException(
                "Module \"{$this->slug}\" tried to register a Twig function without declaring \"twig.functions\""
            );
        }

        // Namespace-prefix so two modules (or a module and core) can never
        // clobber each other's function names: mod_acme_seo_sitemap_myFunc(...)
        $prefixed = 'mod_' . str_replace('-', '_', $this->slug) . '_' . $name;
        $this->twig->addFunction(new TwigFunction($prefixed, $callback, $options));
    }
}
