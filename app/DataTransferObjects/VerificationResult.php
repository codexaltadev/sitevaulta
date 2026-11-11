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
        /**
         * For a failure: true when the site / DNS really answered and the token is not there; false when we
         * could not tell (site down, DNS timeout, 5xx, firewall) — which must never count against the owner.
         */
        public bool $conclusive = true,
    ) {}
}
