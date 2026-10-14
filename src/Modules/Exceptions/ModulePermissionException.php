<?php

namespace Rhapsody\Core\Modules\Exceptions;

/**
 * Thrown when module code tries to use a capability it never declared in
 * module.json (e.g. registering a route without "routes.register").
 *
 * This should never fire against a module that passed marketplace review —
 * static analysis checks for exactly this mismatch before publication. If
 * it fires in production, something bypassed the review pipeline, so it's
 * intentionally a hard failure rather than a silent no-op.
 */
class ModulePermissionException extends \RuntimeException
{
}
