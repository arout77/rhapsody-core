<?php
namespace Rhapsody\Core\Exceptions;

class HttpException extends \Exception
{
    protected int $statusCode;

    // Change the default $code to match $statusCode
    public function __construct(int $statusCode, string $message = "", int $code = null,  ? \Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        // Forward the statusCode to the native parent exception code if no explicit code is passed
        $code = $code ?? $statusCode;

        parent::__construct($message ?: "HTTP {$statusCode}", $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
