<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly ?string $operatorMessage = null,
    ) {
        parent::__construct($message);
    }
}
