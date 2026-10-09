<?php
declare(strict_types=1);
namespace CaptionIA\Services;

use RuntimeException;

/** Sanitized upstream status with no user secret or upstream error body. */
final class GroqGatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 503, public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
