<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Defer;

use Closure;
use Rhapsody\Core\Contracts\DeferrerInterface;
use Rhapsody\Core\Logger;
use Throwable;

/**
 * Collects tasks during a request and runs them after the response is out.
 *
 * Under PHP-FPM / LiteSpeed the client connection is closed first, so the
 * user never waits for deferred work. Elsewhere (Apache mod_php, the built-in
 * server) the body is fully flushed and the tasks run afterwards; the page is
 * complete in the browser, only the connection stays open a little longer.
 */
final class Deferrer implements DeferrerInterface
{
    /**
     * Safety valve against a task that endlessly defers more tasks.
     */
    private const int MAX_TASKS_PER_FLUSH = 1000;

    /**
     * @var list<Closure(): mixed>
     */
    private array $tasks = [];

    private bool $flushing = false;

    public function __construct(
        private readonly Logger $logger,
        private readonly DeferMode $mode,
    ) {
    }

    public function defer(callable $task): void
    {
        $closure = $task(...);

        if ($this->mode === DeferMode::Immediate) {
            $this->run($closure);

            return;
        }

        $this->tasks[] = $closure;
    }

    public function flush(): void
    {
        if ($this->tasks === [] || $this->flushing) {
            return;
        }

        $this->flushing = true;

        try {
            $this->finishResponse();
            ignore_user_abort(true);

            $executed = 0;
            while (($task = array_shift($this->tasks)) !== null) {
                if (++$executed > self::MAX_TASKS_PER_FLUSH) {
                    $this->report(new \RuntimeException(
                        'Deferred task limit of ' . self::MAX_TASKS_PER_FLUSH . ' reached; remaining tasks discarded.'
                    ));
                    $this->tasks = [];
                    break;
                }

                $this->run($task);
            }
        } finally {
            $this->flushing = false;
        }
    }

    public function discard(): void
    {
        $this->tasks = [];
    }

    public function pending(): int
    {
        return count($this->tasks);
    }

    /**
     * Release the client as early as the SAPI allows.
     */
    private function finishResponse(): void
    {
        // A persistent worker has already handed the response to the server.
        if ($this->mode === DeferMode::Worker) {
            return;
        }

        // Release the session file lock so this user's next request is not
        // blocked while deferred work runs. Session writes after this point
        // are not persisted.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            $level = ob_get_level();
            ob_end_flush();
            if (ob_get_level() >= $level) {
                break;
            }
        }
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * Run one task in isolation: its output is discarded and any failure is
     * logged instead of escaping.
     *
     * @param Closure(): mixed $task
     */
    private function run(Closure $task): void
    {
        $capture = $this->mode !== DeferMode::Immediate;
        $level   = ob_get_level();

        if ($capture) {
            ob_start();
        }

        try {
            $task();
        } catch (Throwable $e) {
            $this->report($e);
        } finally {
            if ($capture) {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }
        }
    }

    private function report(Throwable $e): void
    {
        $message = sprintf(
            'Deferred task failed: %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        try {
            $this->logger->log($message, 'ERROR');
        } catch (Throwable) {
            error_log($message);
        }
    }
}
