<?php

namespace App\Shop\Exceptions;

use RuntimeException;

class InsufficientStock extends RuntimeException
{
    public function __construct(public readonly int $variationId, public readonly int $available)
    {
        parent::__construct('The requested quantity is no longer available.');
    }
}
