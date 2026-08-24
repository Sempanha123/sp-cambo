<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderConnectionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'provider_connection_unavailable',
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
