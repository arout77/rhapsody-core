<?php
namespace Rhapsody\Core\Ai\Exceptions;

/**
 * Base exception for anything that goes wrong talking to an AI provider.
 * Catch this to handle "something failed" generically, or catch one of the
 * specific subclasses below to handle a particular failure mode (timeout,
 * rate limit, bad credentials, provider outage) differently.
 */
class AiException extends \Exception
{
}
