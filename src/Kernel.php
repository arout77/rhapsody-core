<?php
namespace Rhapsody\Core;

use Composer\InstalledVersions;

class Kernel
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
