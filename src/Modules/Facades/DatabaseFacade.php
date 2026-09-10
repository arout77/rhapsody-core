<?php

namespace Rhapsody\Core\Modules\Facades;

use Doctrine\ORM\EntityManager;
use PDO;
use Rhapsody\Core\Modules\Exceptions\ModulePermissionException;
use Rhapsody\Core\Modules\ModulePermissions;

/**
 * Database access scoped to a module's own table namespace.
 *
 * The rule this whole class exists to enforce: full CRUD (and basic DDL)
 * on any table whose name starts with this module's own prefix
 * ("mod_{slug}_"), read-only on everything else. The prefix itself is
 * never a value the module supplies — it comes from
 * ModuleManifest::tablePrefix(), derived the same way slug() is, so
 * there's no permissions-block field a module could set to claim write
 * access to a table it doesn't own.
 *
 * Two ways in, same rule either way:
 *   - Raw SQL: query() is read-only (SELECT/WITH only); insert()/
 *     update()/delete()/migrate() take the table name as an explicit
 *     parameter (not embedded in a SQL string) so it can be checked
 *     with a plain string comparison rather than parsed out of
 *     arbitrary SQL — regexing table names out of free-form SQL is not
 *     a fight worth having.
 *   - Doctrine: find()/findBy() work against any entity (read-only);
 *     save()/remove() check the entity's *mapped table name* via
 *     Doctrine's own metadata before persisting. The raw EntityManager
 *     is never handed back — it exposes both a raw Connection and
 *     unrestricted persist()/remove() on any entity, which would defeat
 *     this whole facade.
 */
final class DatabaseFacade
{
    private const DDL_VERBS = '/^\s*(CREATE|ALTER|DROP)\s+TABLE\s+/i';

    public function __construct(
        private readonly PDO $connection,
        private readonly EntityManager $entityManager,
        private readonly ModulePermissions $permissions,
        private readonly string $tablePrefix,
    ) {
    }

    // ---------------------------------------------------------------
    // Raw SQL — read (any table)
    // ---------------------------------------------------------------

    /**
     * Read-only. Only SELECT/WITH statements are accepted — anything
     * else, including a write keyword appearing anywhere in the string
     * (e.g. a stacked statement), is rejected before it reaches the
     * database at all.
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $this->assertAllowed();
        $this->assertReadOnlySql($sql);

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------
    // Raw SQL — structured CRUD (own tables only for writes)
    // ---------------------------------------------------------------

    /** Read helper for any table — not scoped to the module's own prefix. */
    public function select(string $table, array $where = [], array $columns = ['*']): array
    {
        $this->assertAllowed();

        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM ' . $this->quoteIdentifier($table);
        [$whereSql, $params] = $this->buildWhere($where);
        $sql .= $whereSql;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(string $table, array $data): int
    {
        $this->assertAllowed();
        $this->assertOwnTable($table);

        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c) => ':' . $c, $columns);

        $sql = 'INSERT INTO ' . $this->quoteIdentifier($table)
            . ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $this->assertAllowed();
        $this->assertOwnTable($table);

        if (empty($where)) {
            throw new ModulePermissionException('update() requires a non-empty $where — an unconditional update on a real table is almost never what you want.');
        }

        $setSql = implode(', ', array_map(
            static fn (string $c) => $c . ' = :set_' . $c,
            array_keys($data)
        ));
        $setParams = array_combine(
            array_map(static fn (string $c) => 'set_' . $c, array_keys($data)),
            array_values($data)
        );

        [$whereSql, $whereParams] = $this->buildWhere($where, 'where_');

        $sql  = 'UPDATE ' . $this->quoteIdentifier($table) . ' SET ' . $setSql . $whereSql;
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([...$setParams, ...$whereParams]);

        return $stmt->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $this->assertAllowed();
        $this->assertOwnTable($table);

        if (empty($where)) {
            throw new ModulePermissionException('delete() requires a non-empty $where — an unconditional delete on a real table is almost never what you want.');
        }

        [$whereSql, $params] = $this->buildWhere($where);

        $sql  = 'DELETE FROM ' . $this->quoteIdentifier($table) . $whereSql;
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * CREATE TABLE / ALTER TABLE / DROP TABLE on a module's own table —
     * this is the "real database tables" piece from the docs' former
     * What's Coming entry. Typically called once from install() (create)
     * and uninstall() (drop), the same lifecycle storage.access already
     * uses for its own setup/teardown.
     *
     * Deliberately narrow: only these three DDL verbs are recognized,
     * specifically so the table name can be pulled out with a simple,
     * reliable regex instead of a general SQL parser. Anything else
     * (a raw multi-table statement, an unsupported DDL form) is rejected
     * rather than guessed at.
     */
    public function migrate(string $sql): void
    {
        $this->assertAllowed();

        if (! preg_match(self::DDL_VERBS, $sql)) {
            throw new ModulePermissionException('migrate() only accepts CREATE TABLE, ALTER TABLE, or DROP TABLE statements.');
        }

        $table = $this->extractDdlTableName($sql);
        $this->assertOwnTable($table);

        $this->connection->exec($sql);
    }

    // ---------------------------------------------------------------
    // Doctrine — read (any entity)
    // ---------------------------------------------------------------

    public function find(string $entityClass, mixed $id): ?object
    {
        $this->assertAllowed();

        return $this->entityManager->find($entityClass, $id);
    }

    public function findBy(string $entityClass, array $criteria): array
    {
        $this->assertAllowed();

        return $this->entityManager->getRepository($entityClass)->findBy($criteria);
    }

    // ---------------------------------------------------------------
    // Doctrine — write (own entities only)
    // ---------------------------------------------------------------

    public function save(object $entity): void
    {
        $this->assertAllowed();
        $this->assertOwnEntity($entity);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(object $entity): void
    {
        $this->assertAllowed();
        $this->assertOwnEntity($entity);

        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    // ---------------------------------------------------------------
    // Enforcement
    // ---------------------------------------------------------------

    private function assertAllowed(): void
    {
        if (! $this->permissions->can('database.access')) {
            throw new ModulePermissionException('Module tried to access the database without declaring "database.access"');
        }
    }

    private function assertOwnTable(string $table): void
    {
        if (! str_starts_with($table, $this->tablePrefix)) {
            throw new ModulePermissionException(
                "Module tried to write to \"{$table}\", which is outside its own table namespace " .
                "(\"{$this->tablePrefix}*\"). Modules have read-only access to tables they don't own."
            );
        }
    }

    private function assertOwnEntity(object $entity): void
    {
        $tableName = $this->entityManager->getClassMetadata(get_class($entity))->getTableName();
        $this->assertOwnTable($tableName);
    }

    /**
     * Purely lexical — requires the statement to start with SELECT or
     * WITH, then separately rejects any write-like keyword appearing
     * anywhere else in the string (defense-in-depth against a stacked
     * statement slipping past the leading-keyword check).
     */
    private function assertReadOnlySql(string $sql): void
    {
        if (! preg_match('/^\s*(SELECT|WITH)\s/i', $sql)) {
            throw new ModulePermissionException(
                'query() is read-only — only SELECT statements are allowed. ' .
                'Use insert()/update()/delete()/migrate() for writes to your own tables.'
            );
        }

        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REPLACE)\b/i', $sql)) {
            throw new ModulePermissionException('query() rejected a write-like keyword found in a read-only query.');
        }
    }

    private function extractDdlTableName(string $sql): string
    {
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            return $m[1];
        }
        if (preg_match('/^\s*ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            return $m[1];
        }
        if (preg_match('/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            return $m[1];
        }

        throw new ModulePermissionException('migrate() could not determine the target table name from the given SQL.');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function buildWhere(array $where, string $paramPrefix = ''): array
    {
        if (empty($where)) {
            return ['', []];
        }

        $clauses = [];
        $params  = [];
        foreach ($where as $column => $value) {
            $param             = $paramPrefix . $column;
            $clauses[]         = $this->quoteIdentifier($column) . ' = :' . $param;
            $params[$param]    = $value;
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new ModulePermissionException("Invalid identifier: \"{$identifier}\"");
        }

        return '`' . $identifier . '`';
    }
}
