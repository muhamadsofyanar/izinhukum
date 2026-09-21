<?php

namespace App\Services\WhatsApp;

use RuntimeException;
use Throwable;

class StarSenderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly bool $outcomeUnknown = false,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
