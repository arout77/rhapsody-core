<?php

declare (strict_types = 1);

namespace Tests\Unit;

use Closure;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Rhapsody\Core\Container;
use Rhapsody\Core\Contracts\FailableJobInterface;
use Rhapsody\Core\Contracts\JobInterface;
use Rhapsody\Core\Logger;
use Rhapsody\Core\Queue\Attributes\Backoff;
use Rhapsody\Core\Queue\Attributes\Timeout;
use Rhapsody\Core\Queue\Attributes\Tries;
use Rhapsody\Core\Queue\DatabaseQueue;
use Rhapsody\Core\Queue\JobOptions;
use Rhapsody\Core\Queue\JobResult;
use Rhapsody\Core\Queue\JobSerializer;
use Rhapsody\Core\Queue\QueueException;
use Rhapsody\Core\Queue\QueueManager;
use Rhapsody\Core\Queue\QueuedJob;
use Rhapsody\Core\Queue\Worker;
use Rhapsody\Core\Queue\WorkerOptions;
use Rhapsody\Core\Services\Encrypter;
use Throwable;

/**
 * The persistent queue in isolation: an in-memory SQLite database, a real
 * signing Encrypter and a bare Container. No bootstrap.php, no application.
 */
final class QueueTest extends TestCase
{
    private PDO $pdo;
    private DatabaseQueue $queue;
    private JobSerializer $serializer;
    private QueueManager $manager;
    private Container $container;
    private Worker $worker;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        QueueTestJob::$ran    = [];
        QueueTestFailingJob::$failures = [];

        $this->pdo = self::memoryDatabase();
        $pdo       = $this->pdo;

        $this->queue      = new DatabaseQueue(static fn(): PDO => $pdo);
        $encrypter        = new Encrypter(str_repeat('k', 32));
        $this->serializer = new JobSerializer(static fn() => $encrypter);
        $this->manager    = new QueueManager($this->queue, $this->serializer);

        $this->container = new Container();
        $this->container->singleton(QueueTestGreeter::class, fn() => new QueueTestGreeter());

        $this->logFile = sys_get_temp_dir() . '/rhapsody-queue-test-' . bin2hex(random_bytes(4)) . '.log';
        $this->worker  = new Worker($this->queue, $this->serializer, $this->container, new Logger($this->logFile));
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Driver
    // ---------------------------------------------------------------------

    public function testPopReservesTheOldestJobAndCountsTheAttempt(): void
    {
        $first = $this->queue->push('one');
        $this->queue->push('two');

        $job = $this->queue->pop();

        $this->assertNotNull($job);
        $this->assertSame($first, $job->id);
        $this->assertSame('one', $job->payload);
        $this->assertSame(1, $job->attempts);
        $this->assertSame('default', $job->queue);
    }

    public function testAReservedJobCannotBeClaimedTwice(): void
    {
        $this->queue->push('only');

        $this->assertNotNull($this->queue->pop());
        $this->assertNull($this->queue->pop());
    }

    public function testQueuesAreIndependent(): void
    {
        $this->queue->push('mail', 'emails');

        $this->assertNull($this->queue->pop('default'));
        $this->assertNotNull($this->queue->pop('emails'));
    }

    public function testADelayedJobWaitsUntilItIsDue(): void
    {
        $this->queue->push('later', 'default', 100);

        $this->assertNull($this->queue->pop());

        $this->pdo->exec('UPDATE jobs SET available_at = ' . (time() - 1));

        $this->assertNotNull($this->queue->pop());
    }

    public function testAnAbandonedReservationIsHandedOutAgain(): void
    {
        $this->queue->push('crashy');
        $first = $this->queue->pop();
        $this->assertNotNull($first);

        // The worker died; its reservation is now older than the retry-after window.
        $this->pdo->exec('UPDATE jobs SET reserved_at = ' . (time() - 1000));

        $second = $this->queue->pop();

        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->attempts);
        $this->assertSame(false, $first->reservation === $second->reservation);
    }

    public function testAWorkerThatLostItsReservationCannotChangeTheJob(): void
    {
        $this->queue->push('contested');
        $first = $this->queue->pop();
        $this->assertNotNull($first);

        $this->pdo->exec('UPDATE jobs SET reserved_at = ' . (time() - 1000));
        $second = $this->queue->pop();
        $this->assertNotNull($second);

        $this->queue->delete($first);   // stale token: ignored
        $this->queue->fail($first, 'late'); // ignored too

        $this->assertSame(1, $this->queue->size());
        $this->assertSame([], $this->queue->failedJobs());

        $this->queue->delete($second);
        $this->assertSame(0, $this->queue->size());
    }

    public function testReleaseKeepsTheAttemptCountAndDelaysTheJob(): void
    {
        $this->queue->push('again');
        $job = $this->queue->pop();
        $this->assertNotNull($job);

        $this->queue->release($job, 60);
        $notYet = $this->queue->pop();
        $this->assertNull($notYet);

        $this->pdo->exec('UPDATE jobs SET available_at = 0');
        $retried = $this->queue->pop();

        $this->assertNotNull($retried);
        $this->assertSame(2, $retried->attempts);
    }

    public function testFailMovesTheJobAndRetryBringsItBack(): void
    {
        $this->queue->push('doomed', 'emails');
        $job = $this->queue->pop('emails');
        $this->assertNotNull($job);

        $this->queue->fail($job, "RuntimeException: boom\n#0 trace");

        $this->assertSame(0, $this->queue->size('emails'));
        $failed = $this->queue->failedJobs();
        $this->assertCount(1, $failed);
        $this->assertSame('doomed', $failed[0]->payload);
        $this->assertSame('emails', $failed[0]->queue);

        $this->assertTrue($this->queue->retry($failed[0]->id));
        $this->assertFalse($this->queue->retry($failed[0]->id));

        $again = $this->queue->pop('emails');
        $this->assertNotNull($again);
        $this->assertSame(1, $again->attempts);
        $this->assertSame([], $this->queue->failedJobs());
    }

    public function testForgetAndFlushRemoveFailedJobs(): void
    {
        foreach (['a', 'b', 'c'] as $payload) {
            $this->queue->push($payload);
            $job = $this->queue->pop();
            $this->assertNotNull($job);
            $this->queue->fail($job, 'x');
        }

        $ids = array_map(static fn($f) => $f->id, $this->queue->failedJobs());

        $this->assertTrue($this->queue->forget($ids[0]));
        $this->assertFalse($this->queue->forget($ids[0]));
        $this->assertSame(2, $this->queue->flush());
        $this->assertSame([], $this->queue->failedJobs());
    }

    public function testMissingTablesExplainHowToFixIt(): void
    {
        $bare  = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $queue = new DatabaseQueue(static fn(): PDO => $bare);

        try {
            $queue->pop();
            $this->fail('Expected a QueueException.');
        } catch (QueueException $e) {
            $this->assertStringContainsString('queue:install', $e->getMessage());
        }
    }

    public function testTableNamesAreValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseQueue(fn(): PDO => $this->pdo, 90, 'jobs; DROP TABLE users');
    }

    // ---------------------------------------------------------------------
    // Serializer + manager
    // ---------------------------------------------------------------------

    public function testAJobSurvivesASignedRoundTrip(): void
    {
        $payload = $this->serializer->serialize(new QueueTestJob('hello'));
        $job     = $this->serializer->unserialize($payload);

        $this->assertInstanceOf(QueueTestJob::class, $job);
        $this->assertSame('hello', $job->label);
    }

    public function testAPrivatePropertyJobFitsATextColumn(): void
    {
        $payload = $this->serializer->serialize(new QueueTestJob('x'));

        $this->assertSame(false, str_contains($payload, "\0"));
    }

    public function testATamperedPayloadIsRejectedBeforeUnserialize(): void
    {
        $payload = $this->serializer->serialize(new QueueTestJob('hello'));
        $forged  = 'X' . $payload;

        $this->expectException(QueueException::class);

        $this->serializer->unserialize($forged);
    }

    public function testAPayloadSignedForAnotherPurposeIsRejected(): void
    {
        $encrypter = new Encrypter(str_repeat('k', 32));
        $payload   = $encrypter->sign(base64_encode(serialize(new QueueTestJob('x'))), 'cookie:remember_me');

        $this->expectException(QueueException::class);

        $this->serializer->unserialize($payload);
    }

    public function testAJobHoldingAClosureIsRefusedWithAClearMessage(): void
    {
        try {
            $this->serializer->serialize(new QueueTestClosureJob(static fn() => 1));
            $this->fail('Expected a QueueException.');
        } catch (QueueException $e) {
            $this->assertStringContainsString(QueueTestClosureJob::class, $e->getMessage());
        }
    }

    public function testDispatchStoresTheJobOnTheNamedQueueWithADelay(): void
    {
        $id = $this->manager->dispatch(new QueueTestJob('a'), queue: 'emails', delay: 100);

        $this->assertSame(1, $this->manager->size('emails'));
        $this->assertSame(0, $this->manager->size('default'));
        $this->assertNull($this->queue->pop('emails'));
        $this->assertSame(1, $id);
    }

    public function testDispatchRejectsBadArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager->dispatch(new QueueTestJob('a'), delay: -1);
    }

    // ---------------------------------------------------------------------
    // Job options
    // ---------------------------------------------------------------------

    public function testJobOptionsDefaultsAndAttributes(): void
    {
        $defaults = JobOptions::for(new QueueTestJob('x'));
        $this->assertSame(3, $defaults->tries);
        $this->assertSame(10, $defaults->backoffSeconds);
        $this->assertSame(60, $defaults->timeout);

        $custom = JobOptions::for(new QueueTestFailingJob());
        $this->assertSame(2, $custom->tries);
        $this->assertSame(5, $custom->backoffSeconds);
        $this->assertSame(30, $custom->timeout);
    }

    public function testBackoffDoublesUnlessToldOtherwise(): void
    {
        $exponential = new JobOptions(backoffSeconds: 10);
        $this->assertSame([10, 20, 40, 80], array_map($exponential->backoffFor(...), [1, 2, 3, 4]));

        $flat = new JobOptions(backoffSeconds: 10, exponentialBackoff: false);
        $this->assertSame([10, 10, 10], array_map($flat->backoffFor(...), [1, 2, 3]));

        $this->assertSame(86400, $exponential->backoffFor(500));
    }

    // ---------------------------------------------------------------------
    // Worker
    // ---------------------------------------------------------------------

    public function testTheWorkerRunsAJobWithContainerInjectedServices(): void
    {
        $this->manager->dispatch(new QueueTestJob('hi'));

        $outcome = $this->worker->runNext(['default']);

        $this->assertNotNull($outcome);
        $this->assertSame(JobResult::Processed, $outcome->result);
        $this->assertSame(QueueTestJob::class, $outcome->job);
        $this->assertSame(['hi:greeted'], QueueTestJob::$ran);
        $this->assertSame(0, $this->queue->size());
    }

    public function testTheWorkerReportsAnEmptyQueue(): void
    {
        $this->assertNull($this->worker->runNext(['default']));
    }

    public function testQueuesAreCheckedInTheOrderGiven(): void
    {
        $this->manager->dispatch(new QueueTestJob('low'), queue: 'low');
        $this->manager->dispatch(new QueueTestJob('high'), queue: 'high');

        $this->worker->runNext(['high', 'low']);

        $this->assertSame(['high:greeted'], QueueTestJob::$ran);
    }

    public function testAFailingJobIsRetriedWithBackoffThenFailsForGood(): void
    {
        $this->manager->dispatch(new QueueTestFailingJob());

        $first = $this->worker->runNext(['default']);
        $this->assertNotNull($first);
        $this->assertSame(JobResult::Released, $first->result);
        $this->assertSame(1, $this->queue->size());
        $waiting = $this->worker->runNext(['default']); // still waiting out the backoff
        $this->assertNull($waiting);

        $this->pdo->exec('UPDATE jobs SET available_at = 0');

        $second = $this->worker->runNext(['default']);
        $this->assertNotNull($second);
        $this->assertSame(JobResult::Failed, $second->result);
        $this->assertSame(2, $second->attempts);
        $this->assertSame(0, $this->queue->size());
        $this->assertCount(1, $this->queue->failedJobs());
        $this->assertStringContainsString('always fails', $this->queue->failedJobs()[0]->exception);
        $this->assertSame(['always fails'], QueueTestFailingJob::$failures);
    }

    public function testAJobThatKeepsKillingItsWorkerEventuallyFails(): void
    {
        $this->manager->dispatch(new QueueTestFailingJob());

        // Two earlier workers reserved it and died without reporting back.
        $this->pdo->exec('UPDATE jobs SET attempts = 2, reserved_at = ' . (time() - 1000));

        $outcome = $this->worker->runNext(['default']);

        $this->assertNotNull($outcome);
        $this->assertSame(JobResult::Failed, $outcome->result);
        $this->assertStringContainsString('without finishing', (string) $outcome->error);
        $this->assertCount(1, $this->queue->failedJobs());
    }

    public function testAnUnreadablePayloadGoesStraightToTheFailedJobs(): void
    {
        $this->queue->push('not-a-signed-payload');

        $outcome = $this->worker->runNext(['default']);

        $this->assertNotNull($outcome);
        $this->assertSame(JobResult::Failed, $outcome->result);
        $this->assertCount(1, $this->queue->failedJobs());
    }

    public function testAJobWithoutHandleFailsImmediately(): void
    {
        $this->manager->dispatch(new QueueTestNoHandleJob());

        $outcome = $this->worker->runNext(['default']);

        $this->assertNotNull($outcome);
        $this->assertSame(JobResult::Failed, $outcome->result);
        $this->assertStringContainsString('handle()', (string) $outcome->error);
    }

    public function testStopWhenEmptyDrainsTheQueueAndExits(): void
    {
        foreach (['a', 'b', 'c'] as $label) {
            $this->manager->dispatch(new QueueTestJob($label));
        }

        $seen    = 0;
        $handled = $this->worker->work(
            new WorkerOptions(stopWhenEmpty: true),
            function () use (&$seen): void {
                $seen++;
            }
        );

        $this->assertSame(3, $handled);
        $this->assertSame(3, $seen);
        $this->assertSame(['a:greeted', 'b:greeted', 'c:greeted'], QueueTestJob::$ran);
    }

    public function testMaxJobsStopsTheLoop(): void
    {
        foreach (['a', 'b', 'c'] as $label) {
            $this->manager->dispatch(new QueueTestJob($label));
        }

        $handled = $this->worker->work(new WorkerOptions(maxJobs: 2));

        $this->assertSame(2, $handled);
        $this->assertSame(1, $this->queue->size());
    }

    public function testWorkerOptionsRejectNonsense(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkerOptions(sleep: 0);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private static function memoryDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Mirrors the migration that queue:install publishes.
        $pdo->exec(
            'CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue VARCHAR(100) NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                reservation VARCHAR(64) NULL,
                reserved_at INTEGER NULL,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                priority INTEGER NOT NULL DEFAULT 0,
                meta TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue VARCHAR(100) NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at INTEGER NOT NULL
            )'
        );

        return $pdo;
    }
}

final class QueueTestGreeter
{
    public function greet(string $label): string
    {
        return $label . ':greeted';
    }
}

final class QueueTestJob implements JobInterface
{
    /**
     * @var list<string>
     */
    public static array $ran = [];

    public function __construct(public readonly string $label, private readonly int $secret = 7)
    {
    }

    public function handle(QueueTestGreeter $greeter): void
    {
        self::$ran[] = $greeter->greet($this->label);
    }

    public function secret(): int
    {
        return $this->secret;
    }
}

#[Tries(2)]
#[Backoff(5)]
#[Timeout(30)]
final class QueueTestFailingJob implements FailableJobInterface
{
    /**
     * @var list<string>
     */
    public static array $failures = [];

    public function handle(): void
    {
        throw new \RuntimeException('always fails');
    }

    public function failed(Throwable $exception): void
    {
        self::$failures[] = $exception->getMessage();
    }
}

final class QueueTestNoHandleJob implements JobInterface
{
}

final class QueueTestClosureJob implements JobInterface
{
    public function __construct(public readonly Closure $callback)
    {
    }

    public function handle(): void
    {
    }
}
