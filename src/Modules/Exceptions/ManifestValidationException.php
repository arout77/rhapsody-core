<?php

namespace Rhapsody\Core\Modules\Exceptions;

/**
 * Thrown when a module.json fails structural or semantic validation.
 *
 * ModuleRegistry catches this per-module during discovery so one broken
 * manifest disables that module rather than the whole application.
 */
class ManifestValidationException extends \RuntimeException
{
}
