<?php

namespace Rhapsody\Core\Modules;

use Rhapsody\Core\Modules\Exceptions\ManifestValidationException;

/**
 * Normalized, read-only view of what a module's manifest requested.
 *
 * Every ModuleContext facade consults this before doing anything on the
 * module's behalf. This is the single source of truth for "is this module
 * allowed to do X" — both at runtime (ModuleContext) and at review time
 * (the marketplace's static analysis can build the same object from a
 * submission's module.json without running any of the module's code).
 */
final class ModulePermissions
{
    /** @param array<string, array<string, mixed>> $grants capability => detail array */
    private function __construct(private readonly array $grants)
    {
    }

    /**
     * @param array<string, mixed> $data the raw "permissions" object from module.json
     */
    public static function fromArray(array $data, string $context = 'module.json'): self
    {
        $grants = [];

        foreach ($data as $capability => $detail) {
            if (! in_array($capability, ModuleManifest::CAPABILITIES, true)) {
                throw new ManifestValidationException(
                    "{$context}: unknown permission \"{$capability}\" — allowed: " .
                    implode(', ', ModuleManifest::CAPABILITIES)
                );
            }

            if ($detail === false) {
                continue; // explicitly declined, equivalent to omitting the key
            }

            $grants[$capability] = is_array($detail) ? $detail : [];
        }

        // "give me all events" is not a valid grant — force an explicit,
        // reviewable whitelist for anything that touches the event bus.
        if (isset($grants['events.listen']) && empty($grants['events.listen']['listen'])) {
            throw new ManifestValidationException(
                "{$context}: \"events.listen\" requires a non-empty \"listen\" array of event class names"
            );
        }

        if (isset($grants['routes.register']['prefix'])
            && ! preg_match('/^[a-z0-9\-]+$/', $grants['routes.register']['prefix'])) {
            throw new ManifestValidationException(
                "{$context}: \"routes.register.prefix\" must be lowercase alphanumeric/dashes only"
            );
        }

        // Root-level routes (no prefix) are an explicit, reviewable
        // whitelist — same reasoning as events.listen — so a module can ask
        // for exactly "/sitemap.xml" without getting the run of every path.
        if (isset($grants['routes.register']['paths'])) {
            foreach ($grants['routes.register']['paths'] as $path) {
                if (! is_string($path) || ! preg_match('#^/[a-z0-9\-/._]+$#', $path)) {
                    throw new ManifestValidationException(
                        "{$context}: \"routes.register.paths\" entries must be absolute paths " .
                        "(lowercase alphanumeric/dash/dot/slash), got \"" . var_export($path, true) . "\""
                    );
                }
            }
        }

        return new self($grants);
    }

    public function can(string $capability): bool
    {
        return isset($this->grants[$capability]);
    }

    /** @return string[] fully-qualified event class names this module may listen for */
    public function grantedEvents(): array
    {
        return $this->grants['events.listen']['listen'] ?? [];
    }

    public function routePrefix(string $fallback): string
    {
        return $this->grants['routes.register']['prefix'] ?? $fallback;
    }

    /** @return string[] exact root-level paths (no namespace prefix) this module may register */
    public function rootPaths(): array
    {
        return $this->grants['routes.register']['paths'] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->grants;
    }
}
