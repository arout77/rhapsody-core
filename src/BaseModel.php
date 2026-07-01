<?php
namespace Rhapsody\Core;

use PDO;

/**
 * The base model which all other models will extend.
 */
abstract class BaseModel
{
    protected PDO $db;

    public function __construct(Database $databaseWrapper)
    {
        // Extract the raw, native PDO connection out of the injected wrapper
        $this->db = $databaseWrapper->getConnection();
    }
}
