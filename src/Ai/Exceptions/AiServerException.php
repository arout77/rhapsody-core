<?php
namespace Rhapsody\Core\Ai\Exceptions;

/**
 * The provider itself failed (HTTP 5xx) — an outage or internal error on
 * Google's side, not something wrong with the request.
 */
class AiServerException extends AiException
{
}
