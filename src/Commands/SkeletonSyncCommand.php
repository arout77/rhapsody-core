<?php

namespace Rhapsody\Core\Commands;

use Throwable;
use Rhapsody\Core\Contracts\SkeletonMigrationInterface;

/**
 * Applies any pending skeleton migrations to bring a rhapsody-app
 * installation's own files up to date with what the current version
 * of rhapsody-core expects (new config keys, new stub files, etc.)
 * that a plain `composer update` cannot deliver on its own.
 *
 * Mirrors MigrateCommand's conventions: plain typed $signature/$description
 * properties (read by the dynamic rhapsody runner loop), execute(array $args): void,
 * and exit(1) on a hard failure rather than a return status code.
 */
class SkeletonSyncCommand
{
    // The signature and description used by your dynamic rhapsody runner loop
    public string $signature   = 'skeleton:sync';
    public string $description = 'Apply pending skeleton migrations to sync this app with the current framework version';

    private string $basePath;
    private string $stateFile;

    /**
     * Autowired via the container binding in bootstrap.php
     * @param string $basePath Context-aware application root directory path
     */
    public function __construct(string $basePath)
    {
        $this->basePath  = rtrim($basePath, '/\\');
        $this->stateFile = $this->basePath . '/.skeleton_state';
    }

    /**
     * Automatically called by the refactored CLI dynamic runner
     * @param array $args Remaining command line arguments
     */
    public function execute(array $args): void
    {
        echo "\033[34mRhapsody Skeleton Sync\033[0m\n";
        echo "----------------------------------------\n";

        $applied    = $this->loadState();
        $migrations = $this->discoverMigrations();

        // Sort by id (timestamp-first), not filesystem order
        usort($migrations, fn (SkeletonMigrationInterface $a, SkeletonMigrationInterface $b) => strcmp($a->id(), $b->id()));

        $pending = array_values(array_filter(
            $migrations,
            fn (SkeletonMigrationInterface $m) => !in_array($m->id(), $applied, true)
        ));

        if (empty($pending)) {
            echo "[\033[32mSuccess\033[0m] Skeleton is completely up to date. Nothing to sync.\n\n";
            return;
        }

        foreach ($pending as $migration) {
            echo sprintf(
                "Applying: \033[33m%s\033[0m (%s, introduced in v%s)... ",
                $migration->id(),
                $migration->description(),
                $migration->introducedIn()
            );

            try {
                $migration->apply($this->basePath);

                // Recorded immediately after this single migration succeeds,
                // not batched — so a mid-run failure below doesn't cause
                // re-application of migrations already applied this run.
                $applied[] = $migration->id();
                $this->saveState($applied);

                echo "[\033[32mSUCCESS\033[0m]\n";
            } catch (Throwable $e) {
                echo "[\033[31mFAILED\033[0m]\n";
                echo "[\033[31mError\033[0m] Skeleton sync halted with exception: " . $e->getMessage() . "\n\n";
                exit(1);
            }
        }

        echo "----------------------------------------\n";
        echo "[\033[32mSuccess\033[0m] Successfully applied " . count($pending) . " skeleton migration(s).\n\n";
    }

    /**
     * @return SkeletonMigrationInterface[]
     */
    private function discoverMigrations(): array
    {
        $migrations = [];
        $dir = __DIR__ . '/../Skeleton/Migrations';

        if (!is_dir($dir)) {
            return $migrations;
        }

        foreach (glob($dir . '/*.php') as $file) {
            $migration = require $file;

            if ($migration instanceof SkeletonMigrationInterface) {
                $migrations[] = $migration;
            }
        }

        return $migrations;
    }

    private function loadState(): array
    {
        if (!file_exists($this->stateFile)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($this->stateFile), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function saveState(array $applied): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode(array_values($applied), JSON_PRETTY_PRINT)
        );
    }
}
