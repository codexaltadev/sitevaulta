<?php

namespace App\DataTransferObjects;

readonly class ConnectorTestResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public array $capabilities = [],
        public ?int $detectedFilesSizeBytes = null,
        public ?int $detectedDbSizeBytes = null,
    ) {}
}

