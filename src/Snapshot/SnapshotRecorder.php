<?php
declare(strict_types=1);

namespace Rhapsody\Core\Snapshot;

use Rhapsody\Core\Contracts\ContainerInterface;
use Rhapsody\Core\Debug;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\Helpers\Path;
use Rhapsody\Core\Routing\Router;
use Throwable;

/**
 * Glue between the request lifecycle and the snapshot classes. Called from
 * Kernel::terminate() for requests that finished, and from ErrorHandler for
 * requests that threw.
 *
 * It is development-only and can never break a request: every failure while
 * recording (full disk, unwritable folder, bad data) is swallowed and the
 * method just returns null.
 *
 * @example
 * $path = SnapshotRecorder::recordCollected($config); // null when disabled
 */
final class SnapshotRecorder
{
    /**
     * Record the request Debug has already collected.
     *
     * @param  array<string, mixed> $config
     * @return string|null          The snapshot file, or null when nothing was recorded
     */
    public static function recordCollected(array $config): ?string
    {
        return self::record($config, null, null);
    }

    /**
     * Record a request that ended in an exception. Debug::end() never ran for
     * it, so the data is collected here first.
     *
     * @param  array<string, mixed> $config
     * @return string|null          The snapshot file, or null when nothing was recorded
     */
    public static function recordFailure(array $config, Throwable $exception, ?ContainerInterface $container): ?string
    {
        return self::record($config, $exception, $container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function record(array $config, ?Throwable $exception, ?ContainerInterface $container): ?string
    {
        if (! SnapshotSettings::isDevelopment($config)) {
            return null;
        }

        $settings = SnapshotSettings::fromConfig($config);
        if (! $settings->enabled || self::ignored($settings)) {
            return null;
        }

        try {
            $debug = Debug::getInstance();
            if (! $debug->isStarted()) {
                return null;
            }

            if (! $debug->hasCollected()) {
                if ($exception === null) {
                    return null;
                }

                $status = $exception instanceof HttpException ? $exception->getStatusCode() : 500;
                $debug->collect($status, $config, $container, Router::getMatchedRoute());
            }

            $snapshot = (new SnapshotBuilder(new Redactor($settings->redactKeys), $settings))
                ->build($debug->getData(), $exception);

            return (new SnapshotStore(Path::storage('debug/snapshots'), $settings->keep))->save($snapshot);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ignored(SnapshotSettings $settings): bool
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? null;
        $path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : null;
        if (! is_string($path)) {
            return false;
        }

        foreach ($settings->ignorePrefixes as $prefix) {
            if (str_contains($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
