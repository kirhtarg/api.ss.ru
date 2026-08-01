<?php

namespace App\Exceptions;

use RuntimeException;

class PartnerPaymentConflictException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
