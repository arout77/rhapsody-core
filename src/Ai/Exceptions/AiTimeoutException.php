<?php
namespace Rhapsody\Core\Ai\Exceptions;

/**
 * The request took too long — either the connection itself, or the
 * provider didn't finish generating a response within the configured
 * timeout. Generation genuinely can take tens of seconds for long output,
 * so this is expected to happen occasionally under normal use, not just
 * during an outage.
 */
class AiTimeoutException extends AiException
{
}
