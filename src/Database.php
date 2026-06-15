<?php
namespace Rhapsody\Core;

use Exception;
use PDO;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct(array $config)
    {
        // 1. Change 'db' to 'database' to match config.php
        if (empty($config['database'])) {
            throw new Exception("Database configuration parameters are missing. Enter your credentials in <root directory> .env file");
        }

        $dbConfig = $config['database'];

        // 2. Adjust key names ('dbname' and 'user' to match config.php)
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            $dbConfig['host'] ?? '127.0.0.1',
            $dbConfig['port'] ?? '3306',
            $dbConfig['dbname'] ?? '',
            $dbConfig['charset'] ?? 'utf8mb4'
        );

        $this->connection = new PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['password'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function getInstance(array $config = []): Database
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}
