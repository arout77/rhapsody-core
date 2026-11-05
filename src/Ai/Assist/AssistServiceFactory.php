<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

use Rhapsody\Core\Ai\AiClientFactory;
use Rhapsody\Core\Contracts\AiClientInterface;
use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Helpers\Path;
use Rhapsody\Core\Snapshot\Redactor;
use Rhapsody\Core\Snapshot\SnapshotSettings;
use Rhapsody\Core\Snapshot\SnapshotStore;

/**
 * Wires {@see AssistService} for the web endpoints: development only, the AI
 * client comes from the container when the app bound one (core binds a
 * default) and from the `ai` config otherwise.
 */
final class AssistServiceFactory
{
    public static function fromContainer(ContainerInterface $container): AssistService
    {
        $config   = self::config($container);
        $settings = AssistSettings::fromConfig($config);
        $snapshot = SnapshotSettings::fromConfig($config);

        $provider = static function () use ($container, $config): AiClientInterface {
            if ($container->has(AiClientInterface::class)) {
                return $container->resolve(AiClientInterface::class);
            }

            return AiClientFactory::fromConfig($config);
        };

        return new AssistService(
            new SnapshotStore(Path::storage('debug/snapshots'), $snapshot->keep),
            new AssistStore(Path::storage('debug/assist'), $settings->keep),
            new PatchTool(Path::root()),
            new Redactor($snapshot->redactKeys),
            $settings,
            Path::root(),
            $provider,
            AssistSettings::isDevelopment($config),
            Path::logs('ai-requests.jsonl')
        );
    }

    public static function enabled(ContainerInterface $container): bool
    {
        $config = self::config($container);

        return AssistSettings::isDevelopment($config) && AssistSettings::fromConfig($config)->enabled;
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
