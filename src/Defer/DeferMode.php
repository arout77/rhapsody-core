<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Defer;

/**
 * How the current process delivers responses, which decides how deferred
 * tasks are scheduled.
 */
enum DeferMode {
    /**
     * CLI scripts and commands: there is no response to wait for, so tasks run at once.
     */
    case Immediate;

    /**
     * A persistent HTTP worker (RoadRunner): tasks run after the worker has handed the response over.
     */
    case Worker;

    /**
     * A classic web SAPI (PHP-FPM, LiteSpeed, Apache mod_php, the built-in server).
     */
    case Web;

    public static function detect(): self
    {
        if (PHP_SAPI !== 'cli') {
            return self::Web;
        }

        return defined('RHAPSODY_HTTP_WORKER') && constant('RHAPSODY_HTTP_WORKER') === true
            ? self::Worker
            : self::Immediate;
    }
}
