<?php

namespace Rhapsody\Core\Modules\Contracts;

use Rhapsody\Core\Modules\ModuleContext;

/**
 * The single entry point a module exposes to the framework. The class named
 * in module.json's "provider" field must implement this.
 *
 * Every method only ever receives a ModuleContext — never the raw
 * container, Router, or EventDispatcher — so everything the module does is
 * mediated by the permission checks declared in its own manifest.
 */
interface ModuleServiceProviderInterface
{
    /**
     * Runs on every request (after the module passes its compatibility and
     * permission checks). Register event listeners, routes, Twig
     * extensions, etc. here. Keep this fast — it runs on the hot path.
     */
    public function boot(ModuleContext $context): void;

    /**
     * Runs once, when the module is first installed/activated. Use it to
     * seed default settings, run migrations, etc. Not called on every boot.
     */
    public function install(ModuleContext $context): void;

    /**
     * Runs once, when the module is deactivated/removed. Clean up whatever
     * install() created (settings, scoped storage, DB tables).
     */
    public function uninstall(ModuleContext $context): void;
}
