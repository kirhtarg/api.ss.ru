<?php

namespace App\Exceptions;

use RuntimeException;

class PartnerQuoteConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?array $currentQuote = null,
    ) {
        parent::__construct($errorCode);
    }
}
