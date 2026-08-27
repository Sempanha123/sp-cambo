<?php

namespace App\Exceptions;

use RuntimeException;

class PackageStockException extends RuntimeException
{
    public function __construct(
        public readonly string $packageName,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct($available <= 0
            ? "{$packageName} is currently out of stock."
            : "Only {$available} unit(s) of {$packageName} are currently available.");
    }
}
