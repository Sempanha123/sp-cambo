<?php

namespace App\Exceptions;

use RuntimeException;

class FulfillmentClaimException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'claim_unfulfillable',
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
