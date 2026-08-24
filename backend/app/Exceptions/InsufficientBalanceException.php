<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(public readonly string $billingMode)
    {
        parent::__construct($billingMode === 'CREDIT_BALANCE' ? 'Insufficient credits.' : 'Insufficient token quota.');
    }
}
