<?php

namespace App\DataTransferObjects;

readonly class ExtractionResult
{
    public function __construct(
        public bool $success,
        public int $sourceFilesCount,
        public int $changedFilesCount,
        public int $rawSourceSizeBytes,
        public array $fileIndexManifest = [],
        public ?string $errorMessage = null,
    ) {}
}

