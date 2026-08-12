<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

use RuntimeException;
use Throwable;

final class SandboxExecutionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
