<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use Rhapsody\Core\Contracts\QueueInterface;
use Rhapsody\Core\Support\Arr;
use Throwable;

/**
 * Queue driver backed by the `jobs` / `failed_jobs` tables (created by the
 * migration that `queue:install` publishes).
 *
 * Plain PDO and portable SQL, so it runs unchanged on MySQL, PostgreSQL,
 * SQL Server and SQLite, on shared hosting as well as under a daemon.
 *
 * Claiming a job is one portable atomic step, no row locks and no
 * driver-specific syntax:
 *
 *   1. SELECT the id of the oldest available job.
 *   2. UPDATE that row, stamping a random reservation token, but only if it
 *      is still unreserved (or its reservation went stale).
 *   3. If exactly one row changed, the job is ours; otherwise another worker
 *      won the race and we try the next candidate.
 *
 * `$retryAfter` is how long a reservation is respected. A worker that dies
 * mid-job leaves a reservation behind; once it is older than this, the job is
 * handed out again. Keep it above your longest job #[Timeout].
 *
 * To use a different database connection, bind your own instance in
 * bootstrap.php:
 *
 *     $services->singleton(QueueInterface::class, fn() => new DatabaseQueue($myPdoProvider));
 */
final class DatabaseQueue implements QueueInterface
{
    private const int CLAIM_ATTEMPTS = 5;

    private readonly string $jobs;
    private readonly string $failed;

    /**
     * @param Closure(): PDO $connection Returns the PDO to use; called on every operation, so it can stay lazy
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly int $retryAfter = 90,
        string $jobsTable = 'jobs',
        string $failedTable = 'failed_jobs',
    ) {
        foreach ([$jobsTable, $failedTable] as $table) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
                throw new InvalidArgumentException("Invalid queue table name \"{$table}\".");
            }
        }
        if ($retryAfter < 1) {
            throw new InvalidArgumentException('retryAfter must be at least 1 second.');
        }

        $this->jobs   = $jobsTable;
        $this->failed = $failedTable;
    }

    public function push(string $payload, string $queue = 'default', int $delay = 0): int
    {
        return $this->guard(function () use ($payload, $queue, $delay): int {
            $pdo = $this->pdo();
            $now = time();

            $pdo->prepare(
                "INSERT INTO {$this->jobs} (queue, payload, attempts, available_at, created_at)
                 VALUES (:queue, :payload, 0, :available_at, :created_at)"
            )->execute([
                'queue'        => $queue,
                'payload'      => $payload,
                'available_at' => $now + max(0, $delay),
                'created_at'   => $now,
            ]);

            // PostgreSQL has no "last insert id" without naming the serial's sequence.
            $sequence = $this->driver($pdo) === 'pgsql' ? "{$this->jobs}_id_seq" : null;

            return (int) $pdo->lastInsertId($sequence);
        });
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        return $this->guard(function () use ($queue): ?QueuedJob {
            $pdo = $this->pdo();

            for ($try = 0; $try < self::CLAIM_ATTEMPTS; $try++) {
                $now = time();
                $id  = $this->nextCandidate($pdo, $queue, $now);
                if ($id === null) {
                    return null;
                }

                $token = bin2hex(random_bytes(16));
                $claim = $pdo->prepare(
                    "UPDATE {$this->jobs}
                     SET reserved_at = :now, reservation = :token, attempts = attempts + 1
                     WHERE id = :id AND (reserved_at IS NULL OR reserved_at <= :stale)"
                );
                $claim->execute([
                    'now'   => $now,
                    'token' => $token,
                    'id'    => $id,
                    'stale' => $now - $this->retryAfter,
                ]);

                if ($claim->rowCount() !== 1) {
                    continue; // another worker got there first
                }

                $read = $pdo->prepare(
                    "SELECT id, queue, payload, attempts, created_at FROM {$this->jobs}
                     WHERE id = :id AND reservation = :token"
                );
                $read->execute(['id' => $id, 'token' => $token]);
                $row = $read->fetch(PDO::FETCH_ASSOC);

                if (! is_array($row)) {
                    continue; // reclaimed again before we could read it
                }

                return new QueuedJob(
                    id: Arr::int($row, 'id'),
                    queue: Arr::string($row, 'queue'),
                    payload: Arr::string($row, 'payload'),
                    attempts: Arr::int($row, 'attempts'),
                    reservation: $token,
                    createdAt: Arr::int($row, 'created_at'),
                );
            }

            return null;
        });
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        $this->guard(function () use ($job, $delay): void {
            $this->pdo()->prepare(
                "UPDATE {$this->jobs}
                 SET reserved_at = NULL, reservation = NULL, available_at = :available_at
                 WHERE id = :id AND reservation = :token"
            )->execute([
                'available_at' => time() + max(0, $delay),
                'id'           => $job->id,
                'token'        => $job->reservation,
            ]);
        });
    }

    public function delete(QueuedJob $job): void
    {
        $this->guard(function () use ($job): void {
            $this->pdo()->prepare(
                "DELETE FROM {$this->jobs} WHERE id = :id AND reservation = :token"
            )->execute(['id' => $job->id, 'token' => $job->reservation]);
        });
    }

    public function fail(QueuedJob $job, string $exception): void
    {
        $this->guard(function () use ($job, $exception): void {
            $pdo = $this->pdo();
            $pdo->beginTransaction();

            try {
                $remove = $pdo->prepare("DELETE FROM {$this->jobs} WHERE id = :id AND reservation = :token");
                $remove->execute(['id' => $job->id, 'token' => $job->reservation]);

                // Reservation lost to another worker: that worker owns the outcome now.
                if ($remove->rowCount() === 1) {
                    $pdo->prepare(
                        "INSERT INTO {$this->failed} (queue, payload, exception, failed_at)
                         VALUES (:queue, :payload, :exception, :failed_at)"
                    )->execute([
                        'queue'     => $job->queue,
                        'payload'   => $job->payload,
                        'exception' => $exception,
                        'failed_at' => time(),
                    ]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $this->rollBack($pdo);

                throw $e;
            }
        });
    }

    public function size(string $queue = 'default'): int
    {
        return $this->guard(function () use ($queue): int {
            $count = $this->pdo()->prepare("SELECT COUNT(*) FROM {$this->jobs} WHERE queue = :queue");
            $count->execute(['queue' => $queue]);

            $value = $count->fetchColumn();

            return is_numeric($value) ? (int) $value : 0;
        });
    }

    public function failedJobs(): array
    {
        return $this->guard(function (): array {
            $rows = $this->pdo()->query(
                "SELECT id, queue, payload, exception, failed_at FROM {$this->failed} ORDER BY id DESC"
            );
            if ($rows === false) {
                return [];
            }

            $failed = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $failed[] = new FailedJob(
                    id: Arr::int($row, 'id'),
                    queue: Arr::string($row, 'queue'),
                    payload: Arr::string($row, 'payload'),
                    exception: Arr::string($row, 'exception'),
                    failedAt: Arr::int($row, 'failed_at'),
                );
            }

            return $failed;
        });
    }

    public function retry(int $failedId): bool
    {
        return $this->guard(function () use ($failedId): bool {
            $pdo = $this->pdo();
            $pdo->beginTransaction();

            try {
                $read = $pdo->prepare("SELECT queue, payload FROM {$this->failed} WHERE id = :id");
                $read->execute(['id' => $failedId]);
                $row = $read->fetch(PDO::FETCH_ASSOC);

                if (! is_array($row)) {
                    $pdo->commit();

                    return false;
                }

                $now = time();
                $pdo->prepare(
                    "INSERT INTO {$this->jobs} (queue, payload, attempts, available_at, created_at)
                     VALUES (:queue, :payload, 0, :available_at, :created_at)"
                )->execute([
                    'queue'        => Arr::string($row, 'queue'),
                    'payload'      => Arr::string($row, 'payload'),
                    'available_at' => $now,
                    'created_at'   => $now,
                ]);
                $pdo->prepare("DELETE FROM {$this->failed} WHERE id = :id")->execute(['id' => $failedId]);

                $pdo->commit();

                return true;
            } catch (Throwable $e) {
                $this->rollBack($pdo);

                throw $e;
            }
        });
    }

    public function forget(int $failedId): bool
    {
        return $this->guard(function () use ($failedId): bool {
            $delete = $this->pdo()->prepare("DELETE FROM {$this->failed} WHERE id = :id");
            $delete->execute(['id' => $failedId]);

            return $delete->rowCount() > 0;
        });
    }

    public function flush(): int
    {
        return $this->guard(function (): int {
            return (int) $this->pdo()->exec("DELETE FROM {$this->failed}");
        });
    }

    private function pdo(): PDO
    {
        return ($this->connection)();
    }

    private function driver(PDO $pdo): string
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return is_string($driver) ? $driver : '';
    }

    /**
     * Id of the oldest job that is due and not currently reserved.
     */
    private function nextCandidate(PDO $pdo, string $queue, int $now): ?int
    {
        $where = "queue = :queue AND available_at <= :now AND (reserved_at IS NULL OR reserved_at <= :stale)";

        $sql = $this->driver($pdo) === 'sqlsrv'
            ? "SELECT TOP 1 id FROM {$this->jobs} WHERE {$where} ORDER BY id ASC"
            : "SELECT id FROM {$this->jobs} WHERE {$where} ORDER BY id ASC LIMIT 1";

        $select = $pdo->prepare($sql);
        $select->execute(['queue' => $queue, 'now' => $now, 'stale' => $now - $this->retryAfter]);

        $id = $select->fetchColumn();

        return is_numeric($id) ? (int) $id : null;
    }

    private function rollBack(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * Runs a driver operation, turning "table does not exist" into a message
     * that says how to fix it.
     *
     * @template T
     * @param    Closure(): T $operation
     * @return   T
     */
    private function guard(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException $e) {
            if ($this->isMissingTable($e)) {
                throw new QueueException(
                    'The queue tables do not exist yet. Run "php rhapsody queue:install --migrate" first.',
                    0,
                    $e
                );
            }

            throw $e;
        }
    }

    private function isMissingTable(PDOException $e): bool
    {
        return in_array((string) $e->getCode(), ['42S02', '42P01'], true) // MySQL / SQL Server, PostgreSQL
            || str_contains($e->getMessage(), 'no such table');            // SQLite
    }
}
