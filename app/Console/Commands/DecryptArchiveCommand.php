<?php

namespace App\Console\Commands;

use App\Services\Backup\ArchivePackagerService;
use Illuminate\Console\Command;

class DecryptArchiveCommand extends Command
{
    protected $signature = 'sitevaulta:decrypt-archive 
                            {file : Path to the encrypted archive file (.zip or .enc)} 
                            {--passphrase= : Passphrase used during backup encryption}
                            {--output= : Optional output unencrypted .zip destination path}';

    protected $description = 'Decrypt a SiteVaulta AES-256 encrypted backup archive into a standard unencrypted .zip archive';

    public function handle(): int
    {
        $filePath = (string) $this->argument('file');

        if (! file_exists($filePath)) {
            $this->error("File not found at [{$filePath}].");
            return self::FAILURE;
        }

        $passphrase = (string) $this->option('passphrase');
        if (empty($passphrase)) {
            $passphrase = (string) $this->secret('Enter the Archive Encryption Passphrase:');
        }

        if (empty($passphrase)) {
            $this->error('A decryption passphrase is required.');
            return self::FAILURE;
        }

        $this->info("Decrypting [{$filePath}]...");

        $outputPath = (string) $this->option('output');
        if (empty($outputPath)) {
            if (str_ends_with($filePath, '.enc')) {
                $outputPath = substr($filePath, 0, -4);
            } else {
                $outputPath = preg_replace('/\.zip$/i', '_decrypted.zip', $filePath);
            }
        }

        $success = ArchivePackagerService::decryptToPlainZip($filePath, $outputPath, $passphrase);

        if (! $success || ! file_exists($outputPath)) {
            $this->error('❌ Decryption failed! The passphrase provided is incorrect, or the archive file is corrupted.');
            return self::FAILURE;
        }

        $sizeMb = round(filesize($outputPath) / 1024 / 1024, 2);

        $this->info("✅ Archive successfully decrypted!");
        $this->info("Output File: {$outputPath} ({$sizeMb} MB)");
        $this->info("You can now open or extract this .zip file directly without any password.");

        return self::SUCCESS;
    }
}

