<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStoreBalanceException extends RuntimeException
{
    public function __construct(
        public readonly int $availableMinor,
        public readonly int $requiredMinor,
        public readonly string $currency,
    ) {
        parent::__construct('Your SP Cambo Store Wallet does not have enough balance for this purchase.');
    }
}
