<?php
namespace Rhapsody\Core;

use PDO;

/**
 * The base model which all other models will extend.
 */
abstract class BaseModel
{
    protected PDO $db;

    /**
     *                                        When omitted, resolves automatically via the
     *                                        service container, falling back to the raw
     *                                        singleton if the container isn't available.
     * @param Database|null $databaseWrapper Optional explicit injection (e.g. for tests/mocks).
     */
    public function __construct(?Database $databaseWrapper = null)
    {
        $databaseWrapper ??= self::resolveDatabase();

        // Extract the raw, native PDO connection out of the injected wrapper
        $this->db = $databaseWrapper->getConnection();
    }

    /**
     * Resolves the Database singleton, preferring the service container
     * (so config-aware lazy instantiation still works correctly in both
     * web and CLI contexts) and falling back to the raw static singleton
     * if the container isn't bootstrapped for some reason.
     */
    protected static function resolveDatabase(): Database
    {
        global $container;

        if (isset($container) && $container->has(Database::class)) {
            return $container->resolve(Database::class);
        }

        return Database::getInstance();
    }
}
