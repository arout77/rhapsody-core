<?php
namespace Rhapsody\Core\Composer;

use Composer\Script\Event;
use Rhapsody\Core\Commands\EnvSyncCommand;
use Rhapsody\Core\Commands\MigrateCommand;
use Rhapsody\Core\Database;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Wires the old app:update workflow into Composer's own update
 * lifecycle, now that distribution has moved from "git pull, then run
 * app:update by hand" to Packagist + `composer update`. Registered via
 * the application's own composer.json:
 *
 *   "scripts": {
 *       "pre-update-cmd":  "Rhapsody\\Core\\Composer\\UpdateHooks::maintenanceOn",
 *       "post-update-cmd": "Rhapsody\\Core\\Composer\\UpdateHooks::postUpdate"
 *   }
 *
 * Two things worth knowing before touching this class:
 *
 * 1. This deliberately does NOT reuse bootstrap.php's full container.
 *    Composer script hooks run in a much more minimal context than a
 *    real request — only the autoloader is guaranteed — and
 *    bootstrap.php wires up far more than an update needs (event
 *    dispatcher, module registry, Twig environment...). This replicates
 *    the same handful of raw steps index.php itself performs before it
 *    ever reaches bootstrap.php — load .env, require config.php — and
 *    nothing more.
 *
 * 2. If any step in postUpdate() throws, maintenance mode is
 *    intentionally left ON rather than auto-cleared. A failed migration
 *    or a missing cache directory is exactly the situation where the
 *    site should NOT quietly come back online in a half-updated state.
 *    The error goes to both Composer's own console output and
 *    storage/logs/update.log; the site stays down until a human looks
 *    at it and either fixes the problem and reruns, or manually deletes
 *    storage/framework/down.
 *
 * What this does NOT do: bump a version number anywhere.
 * FrameworkInfo::getVersion() already reads the installed version live
 * from Composer\InstalledVersions — there's no app_version key left in
 * config.php to keep in sync, on purpose. That step from the old
 * process is already permanently satisfied by the current architecture.
 *
 * Cache-clearing scope: this clears the Twig template cache, the
 * compiled route cache, and resets OPcache directly — all three are
 * plain file operations with no external dependency. It does not
 * attempt to flush the application-level Cache facade (whatever
 * CacheInterface driver config.php configures), since constructing the
 * right driver here would mean guessing at config shape this class
 * has no reliable way to confirm. If the app's cache driver is
 * something other than the filesystem, add an explicit flush call for
 * it in clearCaches() below.
 */
class UpdateHooks
{
    public static function maintenanceOn(Event $event): void
    {
        $basePath = self::basePath($event);
        $flag     = $basePath . '/storage/framework/down';

        if (! is_dir(dirname($flag))) {
            mkdir(dirname($flag), 0755, true);
        }

        file_put_contents($flag, json_encode([
            'time'   => date('c'),
            'reason' => 'composer update in progress',
        ]));

        $event->getIO()->write('<info>Maintenance mode ON — updating...</info>');
    }

    public static function postUpdate(Event $event): void
    {
        $basePath = self::basePath($event);
        $io       = $event->getIO();

        try {
            $config = self::minimalBootstrap($basePath);

            $io->write('<info>Syncing .env from .env.example...</info>');
            self::runSymfonyCommand(new EnvSyncCommand($basePath));

            $io->write('<info>Running database migrations...</info>');
            (new MigrateCommand($basePath, Database::getInstance($config)))->execute([]);

            $io->write('<info>Clearing caches...</info>');
            self::clearCaches($basePath);

            self::maintenanceOff($basePath);
            $io->write('<info>Update complete — maintenance mode OFF.</info>');
        } catch (\Throwable $e) {
            self::logFailure($basePath, $e);
            $io->writeError(sprintf(
                '<error>Update step failed: %s. Site remains in maintenance mode — see ' .
                'storage/logs/update.log, fix the issue, then either rerun `composer update` ' .
                'or manually delete storage/framework/down.</error>',
                $e->getMessage()
            ));
            // Deliberately not re-thrown: Composer would report the whole
            // `composer update` run as failed, which would be misleading —
            // the actual package update already succeeded by the time this
            // hook runs. Only our follow-up steps failed.
        }
    }

    private static function runSymfonyCommand(\Symfony\Component\Console\Command\Command $command): void
    {
        // Command::execute() is protected — run() is the correct public
        // entry point for invoking a Symfony Console command outside the
        // normal CLI dispatch path, and handles calling execute() itself
        // in the right order internally.
        $command->run(new ArrayInput([]), new ConsoleOutput());
    }

    private static function clearCaches(string $basePath): void
    {
        self::clearDirectory($basePath . '/storage/cache/twig');

        $routeCache = $basePath . '/storage/cache/routes/routes.php';
        if (file_exists($routeCache)) {
            unlink($routeCache);
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    private static function clearDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? self::clearDirectory($file) : unlink($file);
        }
    }

    private static function maintenanceOff(string $basePath): void
    {
        $flag = $basePath . '/storage/framework/down';
        if (file_exists($flag)) {
            unlink($flag);
        }
    }

    private static function logFailure(string $basePath, \Throwable $e): void
    {
        $logDir = $basePath . '/storage/logs';
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents(
            $logDir . '/update.log',
            '[' . date('c') . "] Update failed: {$e->getMessage()}\n{$e->getTraceAsString()}\n\n",
            FILE_APPEND
        );
    }

    /**
     * Replicates index.php's own pre-bootstrap.php sequence (autoloader
     * is already guaranteed by the time Composer calls this; load .env,
     * require config.php) rather than pulling in the full application
     * container.
     *
     * @return array the application config array
     */
    private static function minimalBootstrap(string $basePath): array
    {
        $repository = \Dotenv\Repository\RepositoryBuilder::createWithDefaultAdapters()
            ->addAdapter(\Dotenv\Repository\Adapter\PutenvAdapter::class)
            ->make();

        \Dotenv\Dotenv::create($repository, $basePath)->load();

        return require $basePath . '/config/config.php';
    }

    /**
     * Resolved from Composer's own configured vendor-dir rather than a
     * hardcoded dirname(__DIR__, N) depth — this class's own location
     * within the package is an implementation detail that could change,
     * or differ under a local path-repository install; the vendor
     * directory Composer itself is actually using is not.
     */
    private static function basePath(Event $event): string
    {
        return dirname($event->getComposer()->getConfig()->get('vendor-dir'));
    }
}
