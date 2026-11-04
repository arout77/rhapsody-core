<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Docs;

use Rhapsody\Core\Ai\AiClientFactory;
use Rhapsody\Core\Contracts\AiClientInterface;
use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Helpers\Path;

/**
 * Builds the AskService for the CLI command and the web page, so both follow
 * the same rules: AI only in development, the client comes from the
 * container when the app bound one and from the `ai` config otherwise, and
 * a missing or broken client degrades to showing the docs.
 */
final class AskServiceFactory
{
    /** Parsed once per process; the index is read-only, so workers can share it. */
    private static ?DocsIndex $index = null;

    public static function fromContainer(ContainerInterface $container, ?string $gapLogPath = null): AskService
    {
        $config = self::config($container);

        $provider = static function () use ($container, $config): AiClientInterface {
            if ($container->has(AiClientInterface::class)) {
                return $container->resolve(AiClientInterface::class);
            }

            return AiClientFactory::fromConfig($config);
        };

        return new AskService(
            self::index(),
            $provider,
            self::aiAllowed($container),
            $gapLogPath ?? self::defaultGapLogPath()
        );
    }

    /**
     * AI answers are a development-time convenience, never a production feature.
     */
    public static function aiAllowed(ContainerInterface $container): bool
    {
        return (self::config($container)['app_env'] ?? null) === 'development';
    }

    public static function defaultGapLogPath(): string
    {
        return Path::logs('doc-gaps.jsonl');
    }

    private static function index(): DocsIndex
    {
        return self::$index ??= DocsIndex::fromFile(DocsIndex::defaultPath());
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(ContainerInterface $container): array
    {
        if (! $container->has('config')) {
            return [];
        }

        $raw = $container->resolve('config');
        if (! is_array($raw)) {
            return [];
        }

        $config = [];
        foreach ($raw as $key => $value) {
            $config[(string) $key] = $value;
        }

        return $config;
    }
}
