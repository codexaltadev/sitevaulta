<?php

namespace App\DataTransferObjects;

use App\Enums\VerificationMethod;

readonly class VerificationResult
{
    public function __construct(
        public bool $verified,
        public VerificationMethod $method,
        public string $message,
        public ?string $diagnosticDetail = null,
    ) {}
}

