<?php
namespace Rhapsody\Core;

use Exception;
use PDO;
use Rhapsody\Core\TraceablePDO;

class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection           = null;
    private array $config;

    private function __construct(array $config)
    {
        // Maintain config validation to match your environment configuration structure.
        // Note: we validate eagerly (fail fast on obviously missing config) but we do
        // NOT open the actual PDO connection here — that's deferred to getConnection()
        // so that pages/requests which never touch the database don't require one.
        if (empty($config['database'])) {
            throw new Exception("Database configuration parameters are missing. Enter your credentials in <root directory> .env file");
        }

        $this->config = $config;
    }

    /**
     * Lazily opens (and caches) the underlying PDO connection on first use.
     */
    private function connect(): PDO
    {
        $dbConfig = $this->config['database'];

        // Detect the target driver, defaulting to 'mysql' to ensure backward compatibility
        $driver = $dbConfig['driver'] ?? 'mysql';

        // Dynamically build the driver-specific connection string (DSN)
        $dsn = $this->buildDsn($dbConfig, $driver);

        $username = $dbConfig['user'] ?? null;
        $password = $dbConfig['password'] ?? null;

        // Instantiate the native PDO instance
        return new TraceablePDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Builds a PDO-compatible Data Source Name (DSN) string based on the selected driver.
     *
     * @param  array       $dbConfig
     * @param  string      $driver
     * @throws Exception
     * @return string
     */
    private function buildDsn(array $dbConfig, string $driver): string
    {
        $host    = $dbConfig['host'] ?? '127.0.0.1';
        $dbname  = $dbConfig['dbname'] ?? '';
        $charset = $dbConfig['charset'] ?? 'utf8mb4';

        switch (strtolower($driver)) {
            case 'mysql':
                $port = $dbConfig['port'] ?? '3306';
                return sprintf("mysql:host=%s;port=%s;dbname=%s;charset=%s", $host, $port, $dbname, $charset);

            case 'pgsql':
                $port = $dbConfig['port'] ?? '5432';
                return sprintf("pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=%s'", $host, $port, $dbname, $charset);

            case 'sqlite':
                // For SQLite, the 'dbname' key should represent the path to your .sqlite file (e.g., ':memory:' or 'storage/database.sqlite')
                return sprintf("sqlite:%s", $dbname);

            case 'sqlsrv':
                $port = $dbConfig['port'] ?? '1433';
                return sprintf("sqlsrv:Server=%s,%s;Database=%s", $host, $port, $dbname);

            default:
                throw new Exception("Unsupported database driver: '{$driver}'. Supported drivers are mysql, pgsql, sqlite, sqlsrv.");
        }
    }

    /**
     * Returns the global Singleton instance of the Database.
     */
    public static function getInstance(array $config = []): Database
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Exposes the active PDO connection context, connecting on first use.
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connection = $this->connect();
        }

        return $this->connection;
    }

    // Protect the Singleton instance against cloning and unserialization lifecycle disruptions
    private function __clone()
    {}

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize a singleton instance.");
    }
}
