<?php

namespace App\Exceptions;

use RuntimeException;

class ResellerCustomerStatusTransitionException extends RuntimeException
{
    public function __construct(string $message = 'The managed customer cannot transition to the requested status.')
    {
        parent::__construct($message);
    }
}
