<?php

namespace App\Exceptions;

use InvalidArgumentException;

class InferenceIdempotencyException extends InvalidArgumentException
{
    public function __construct(string $message = 'The request identifier was already used for a different inference request.')
    {
        parent::__construct($message);
    }
}
