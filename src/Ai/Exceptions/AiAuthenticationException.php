<?php
namespace Rhapsody\Core\Ai\Exceptions;

/**
 * The API key is missing, invalid, or not authorized (HTTP 401/403, or
 * caught locally before any request is sent if no key is configured at all).
 */
class AiAuthenticationException extends AiException
{
}
