<?php
namespace Rhapsody\Core;

use PDO;

/**
 * The base model which all other models will extend.
 */
abstract class BaseModel
{
    protected PDO $db;

    public function __construct()
    {
        // Get the singleton wrapper instance
        $databaseWrapper = Database::getInstance();

        // Extract the raw, native PDO connection out of the wrapper
        $this->db = $databaseWrapper->getConnection();
    }
}
