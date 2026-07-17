<?php
namespace Rhapsody\Core;

use Composer\InstalledVersions;

/**
 * Framework identity/version metadata.
 *
 * This used to live on Kernel, but "what version am I" and "handle this
 * request" are unrelated responsibilities — Kernel now owns the request
 * lifecycle (see Kernel::handle()/terminate()).
 */
class FrameworkInfo
{
    public static function getVersion(): string
    {
        // Fallback to a dev string if it's run outside a composer install instance
        if (class_exists(InstalledVersions::class)) {
            return InstalledVersions::getPrettyVersion('arout/rhapsody-core') ?? '1.0.0-dev';
        }
        return '1.0.0-dev';
    }
}
