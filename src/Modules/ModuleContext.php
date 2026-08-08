<?php

namespace Rhapsody\Core\Modules;

use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Events\EventDispatcher;
use Rhapsody\Core\Modules\Facades\EventsFacade;
use Rhapsody\Core\Modules\Facades\RoutesFacade;
use Rhapsody\Core\Modules\Facades\SettingsFacade;
use Rhapsody\Core\Modules\Facades\StorageFacade;
use Rhapsody\Core\Modules\Facades\TwigFacade;
use Rhapsody\Core\Routing\Router;
use Twig\Environment;

/**
 * The only thing a ModuleServiceProvider ever touches — deliberately NOT
 * the raw service container. Every capability is a facade scoped to this
 * module's own manifest and its own slug-namespaced resources, so a module
 * can't reach the container directly, can't collide with another module's
 * routes/storage/settings, and can't do anything its manifest didn't
 * declare (each facade re-checks ModulePermissions itself).
 */
final class ModuleContext
{
    public function __construct(
        private readonly ModuleManifest $manifest,
        private readonly ContainerInterface $container,
        private readonly string $basePath,
    ) {
    }

    public function slug(): string
    {
        return $this->manifest->slug();
    }

    public function manifest(): ModuleManifest
    {
        return $this->manifest;
    }

    public function events(): EventsFacade
    {
        return new EventsFacade(
            $this->container->resolve(EventDispatcher::class),
            $this->manifest->permissions,
            $this->manifest->slug(),
        );
    }

    public function routes(): RoutesFacade
    {
        return new RoutesFacade(
            $this->container->resolve(Router::class),
            $this->manifest->permissions,
            $this->manifest->slug(),
        );
    }

    public function twig(): TwigFacade
    {
        return new TwigFacade(
            $this->container->resolve(Environment::class),
            $this->manifest->permissions,
            $this->manifest->slug(),
        );
    }

    public function storage(): StorageFacade
    {
        return new StorageFacade(
            $this->basePath . '/storage/modules/' . $this->manifest->slug(),
            $this->manifest->permissions,
        );
    }

    public function settings(): SettingsFacade
    {
        return new SettingsFacade(
            $this->basePath . '/storage/modules/' . $this->manifest->slug() . '/settings.json',
            $this->manifest->permissions,
        );
    }
}
