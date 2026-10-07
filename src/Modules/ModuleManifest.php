<?php

namespace Rhapsody\Core\Modules;

use Rhapsody\Core\Modules\Exceptions\ManifestValidationException;

/**
 * A parsed, validated module.json manifest.
 *
 * The manifest is the entire attack surface a module can request — nothing
 * in a module's PHP code can reach further than what's declared here,
 * because ModuleContext refuses to hand out capabilities that weren't
 * granted (see ModulePermissions). That's what makes automated marketplace
 * review tractable: the manifest can be checked in milliseconds, before a
 * single line of the module's actual code has run. Keep validation here
 * strict — it's cheaper to reject a bad manifest here than to catch a
 * misbehaving module later in the sandbox stage.
 *
 * Mirrors schema/module.schema.json; that file is the language-agnostic
 * version used by the marketplace's non-PHP tooling (CI linters, the
 * submission API), this class is the runtime/PHP-side enforcement.
 */
final class ModuleManifest
{
    /** Closed set of capability keys a module is allowed to request. */
    public const CAPABILITIES = [
        'events.listen',
        'events.dispatch',
        'routes.register',
        'twig.extensions',
        'twig.functions',
        'storage.access',
        'settings.manage',
        'database.access',
        'mail.send',
    ];

    /**
     * Namespaces no module may claim when requesting events.dispatch —
     * the framework's own and the host application's.
     */
    private const RESERVED_NAMESPACES = ['Rhapsody\\', 'App\\'];

    public const CATEGORIES = [
        'seo', 'commerce', 'content', 'analytics', 'integration',
        'security', 'media', 'utility', 'other',
    ];

    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $coreConstraint,
        public readonly string $title,
        public readonly string $description,
        public readonly string $category,
        public readonly string $license,
        public readonly string $provider,
        public readonly ModulePermissions $permissions,
        public readonly array $settingsSchema,
        public readonly array $raw,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new ManifestValidationException("module.json not found at {$path}");
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            throw new ManifestValidationException("module.json at {$path} is not valid JSON");
        }

        return self::fromArray($json, $path);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $context = 'module.json'): self
    {
        foreach (['name', 'type', 'version', 'rhapsody_core', 'title', 'provider'] as $required) {
            if (empty($data[$required])) {
                throw new ManifestValidationException("{$context}: missing required field \"{$required}\"");
            }
        }

        if ($data['type'] !== 'rhapsody-module') {
            throw new ManifestValidationException("{$context}: \"type\" must be \"rhapsody-module\", got \"{$data['type']}\"");
        }

        if (! preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#', $data['name'])) {
            throw new ManifestValidationException("{$context}: \"name\" must look like \"vendor-slug/module-slug\"");
        }

        if (! preg_match('/^\d+\.\d+\.\d+(-[\w.]+)?$/', $data['version'])) {
            throw new ManifestValidationException("{$context}: \"version\" must be valid semver, got \"{$data['version']}\"");
        }

        $category = $data['category'] ?? 'other';
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new ManifestValidationException(
                "{$context}: \"category\" must be one of: " . implode(', ', self::CATEGORIES)
            );
        }

        $permissions = ModulePermissions::fromArray($data['permissions'] ?? [], $context);

        if ($permissions->can('events.dispatch')) {
            self::assertDispatchableEventsAreOwned(
                (string) $data['provider'],
                $permissions->dispatchableEvents(),
                $context
            );
        }

        return new self(
            name: $data['name'],
            version: $data['version'],
            coreConstraint: $data['rhapsody_core'],
            title: $data['title'],
            description: $data['description'] ?? '',
            category: $category,
            license: $data['license'] ?? 'proprietary',
            provider: $data['provider'],
            permissions: $permissions,
            settingsSchema: $data['settings_schema'] ?? [],
            raw: $data,
        );
    }

    /**
     * The "write only inside a boundary the framework derives" rule,
     * applied to events: a module may only dispatch event classes that
     * live under its OWN provider's namespace. The boundary is derived
     * from the manifest's "provider" field (never a value the module
     * supplies separately), exactly like slug() and tablePrefix().
     *
     * "Arout\Forms\ModuleProvider" owns "Arout\Forms\*", so it may
     * declare "Arout\Forms\Events\FormSubmitted" — but never a core
     * event, another module's event, or anything in the app's namespace.
     *
     * @param string[] $eventClasses
     */
    private static function assertDispatchableEventsAreOwned(string $provider, array $eventClasses, string $context): void
    {
        $separator = strrpos($provider, '\\');
        if ($separator === false || $separator === 0) {
            throw new ManifestValidationException(
                "{$context}: \"events.dispatch\" requires \"provider\" to be a namespaced class name, got \"{$provider}\""
            );
        }

        $namespace = substr($provider, 0, $separator) . '\\';

        foreach (self::RESERVED_NAMESPACES as $reserved) {
            if (str_starts_with($namespace, $reserved)) {
                throw new ManifestValidationException(
                    "{$context}: a module whose provider lives in \"{$reserved}*\" may not request \"events.dispatch\" — " .
                    'that namespace is reserved for the framework and the host application'
                );
            }
        }

        foreach ($eventClasses as $eventClass) {
            if (! str_starts_with($eventClass, $namespace)) {
                throw new ManifestValidationException(
                    "{$context}: \"events.dispatch\" entry \"{$eventClass}\" is outside this module's own namespace " .
                    "(\"{$namespace}*\", taken from its provider class). Modules may only dispatch their own events."
                );
            }
        }
    }

    /**
     * "acme/seo-sitemap" -> "acme-seo-sitemap" — used to namespace routes,
     * scoped storage, and settings keys so modules can't collide with
     * each other or with core.
     */
    public function slug(): string
    {
        return str_replace('/', '-', $this->name);
    }

    /**
     * "acme/welcome-bonus" -> "mod_acme_welcome_bonus_" — every table a
     * module may write to must start with this. Deliberately derived
     * from the manifest's own name field, exactly like slug(), rather
     * than being a value the module can declare in its permissions
     * block: a self-declared prefix would let a module simply claim
     * "users" and get write access to a table it doesn't own. Read
     * access (DatabaseFacade::query()/find()/findBy()) is not scoped by
     * this prefix — only writes are.
     */
    public function tablePrefix(): string
    {
        return 'mod_' . str_replace('-', '_', $this->slug()) . '_';
    }
}
