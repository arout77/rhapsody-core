<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Queue;

use Closure;
use Rhapsody\Core\Contracts\EncrypterInterface;
use Rhapsody\Core\Contracts\JobInterface;
use Throwable;

/**
 * Turns jobs into signed payload strings and back.
 *
 * A payload is `base64(serialize($job))` signed with the application key
 * under its own context, so a payload nobody here signed (a tampered row, a
 * forged insert) is rejected before unserialize() ever sees it. Base64
 * keeps the NUL bytes PHP puts in serialised private properties out of the
 * text column, which PostgreSQL would refuse.
 *
 * The encrypter is fetched lazily, so commands that never touch a payload
 * (queue:failed, bin/rhapsody help) work without APP_KEY.
 */
final class JobSerializer
{
    private const string CONTEXT = 'queue:job';

    /**
     * @param Closure(): EncrypterInterface $encrypter
     */
    public function __construct(private readonly Closure $encrypter)
    {
    }

    /**
     * @throws QueueException If the job cannot be serialised (e.g. it holds a closure)
     */
    public function serialize(JobInterface $job): string
    {
        try {
            $raw = serialize($job);
        } catch (Throwable $e) {
            throw new QueueException(
                sprintf(
                    '%s cannot be queued: %s. Keep job properties to plain values such as ids and strings.',
                    $job::class,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        return ($this->encrypter)()->sign(base64_encode($raw), self::CONTEXT);
    }

    /**
     * @throws QueueException If the signature is wrong or the payload is not a job
     */
    public function unserialize(string $payload): JobInterface
    {
        $encoded = ($this->encrypter)()->unsign($payload, self::CONTEXT);
        if ($encoded === null) {
            throw new QueueException('Queue payload signature is invalid (wrong APP_KEY, or the payload was altered).');
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new QueueException('Queue payload is not valid base64.');
        }

        $job = unserialize($raw);
        if (! $job instanceof JobInterface) {
            throw new QueueException('Queue payload does not contain a job (the job class may no longer exist).');
        }

        return $job;
    }
}
