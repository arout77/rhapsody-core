<?php
namespace Rhapsody\Core\Commands;

use Exception;
use PDO;
use Rhapsody\Core\Database;

class MigrateCommand
{
    // The signature and description used by your dynamic rhapsody runner loop
    public string $signature   = 'migrate';
    public string $description = 'Execute pending database schema migrations changes';

    private string $basePath;
    private Database $db;

    /**
     * Autowired via the container binding in bootstrap.php
     * * @param string $basePath Context-aware application root directory path
     * @param Database $db Core database utility singleton instance
     */
    public function __construct(string $basePath, Database $db)
    {
        $this->basePath = $basePath;
        $this->db       = $db;
    }

    /**
     * Automatically called by the refactored CLI dynamic runner
     * * @param array $args Remaining command line arguments
     */
    public function execute(array $args): void
    {
        echo "\033[34mRhapsody Migration Engine\033[0m\n";
        echo "----------------------------------------\n";

        $migrationsDir = $this->basePath . '/database/migrations';

        // 1. Ensure migrations directory exists
        if (! is_dir($migrationsDir)) {
            echo "Creating missing migrations directory layout at: {$migrationsDir}\n";
            mkdir($migrationsDir, 0755, true);
        }

        $connection = $this->db->getConnection();

        // 2. Setup tracking metadata table if it does not exist
        $this->ensureMigrationTableExists($connection);

        // 3. Scan directory for migration files (*.sql or *.php)
        $files = glob($migrationsDir . '/*.{sql,php}', GLOB_BRACE);

        if (empty($files)) {
            echo "[\033[33mNotice\033[0m] No migration files found in /database/migrations.\n\n";
            return;
        }

        // Sort files chronologically (assumes YYYY_MM_DD_HHMMSS_name prefix format)
        sort($files);

        // 4. Retrieve already executed migrations from the tracker table
        $executed = $this->getExecutedMigrations($connection);

        $pendingCount = 0;

        // 5. Migration Execution Processing Loop
        foreach ($files as $file) {
            $migrationName = basename($file);

            // Skip if already executed
            if (in_array($migrationName, $executed)) {
                continue;
            }

            $pendingCount++;
            echo "Migrating: \033[33m{$migrationName}\033[0m... ";

            // Wrap each file execution in a distinct database transaction boundary
            $connection->beginTransaction();
            try {
                $ext = pathinfo($file, PATHINFO_EXTENSION);

                if ($ext === 'sql') {
                    // Execute raw SQL file structures
                    $sql = file_get_contents($file);
                    if (trim($sql) !== '') {
                        $connection->exec($sql);
                    }
                } elseif ($ext === 'php') {
                    // Require dynamic object-oriented or procedural PHP migrations scripts
                    $migrationClosure = require $file;
                    if (is_callable($migrationClosure)) {
                        $migrationClosure($connection);
                    }
                }

                // Log migration success record to metadata tracker table
                $stmt = $connection->prepare("INSERT INTO migrations (migration, executed_at) VALUES (:migration, NOW())");
                $stmt->execute([':migration' => $migrationName]);

                $connection->commit();
                echo "[\033[32mSUCCESS\033[0m]\n";
            } catch (Exception $e) {
                $connection->rollBack();
                echo "[\033[31mFAILED\033[0m]\n";
                echo "[\033[31mError\033[0m] Migration halted with exception: " . $e->getMessage() . "\n\n";
                exit(1);
            }
        }

        if ($pendingCount === 0) {
            echo "[\033[32mSuccess\033[0m] Database schema is completely up to date. Nothing to migrate.\n";
        } else {
            echo "----------------------------------------\n";
            echo "[\033[32mSuccess\033[0m] Successfully processed {$pendingCount} migration(s).\n";
        }
        echo "\n";
    }

    /**
     * Initializes the migrations metadata system table if missing.
     */
    private function ensureMigrationTableExists(PDO $connection): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $connection->exec($sql);
    }

    /**
     * Fetches array lists of previously loaded migrations.
     */
    private function getExecutedMigrations(PDO $connection): array
    {
        $stmt = $connection->query("SELECT migration FROM migrations");
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}
