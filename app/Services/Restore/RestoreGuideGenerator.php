<?php

namespace App\Services\Restore;

use App\Enums\BackupRunStatus;
use App\Models\BackupRun;
use App\Services\Forecast\StorageForecastService;

class RestoreGuideGenerator
{
    public static function generateForRun(BackupRun $run): array
    {
        $run->loadMissing(['website', 'backupPolicy.serverConnection', 'backupChain', 'artifacts']);

        // 1. Collect the full ordered restore chain (Base Full + intermediate Incrementals up to $run)
        $chainRuns = collect([$run]);
        if ($run->backup_chain_id) {
            $chainRuns = BackupRun::with('artifacts')
                ->where('backup_chain_id', $run->backup_chain_id)
                ->where('status', BackupRunStatus::Completed)
                ->where('id', '<=', $run->id)
                ->orderBy('id')
                ->get();
        }

        $chainSteps = [];
        $stepNum = 1;
        foreach ($chainRuns as $chainRun) {
            $artifact = $chainRun->artifacts->first();
            if (! $artifact) {
                continue;
            }

            $chainSteps[] = [
                'order' => $stepNum++,
                'run_uuid' => $chainRun->uuid,
                'backup_type' => strtoupper($chainRun->backup_type),
                'file_name' => $artifact->file_name,
                'size_formatted' => StorageForecastService::formatBytes($artifact->size_bytes),
                'sha256_checksum' => $artifact->sha256_checksum,
                'is_encrypted' => $artifact->is_encrypted,
                'completed_at' => $chainRun->completed_at?->toDateTimeString() . ' UTC',
            ];
        }

        $primaryArtifact = $run->artifacts->first();
        $fileName = $primaryArtifact?->file_name ?? 'backup-archive.zip';
        $sha256 = $primaryArtifact?->sha256_checksum ?? 'N/A';
        $isEncrypted = (bool) ($primaryArtifact?->is_encrypted ?? false);

        $serverConn = $run->backupPolicy?->serverConnection;
        $rootPath = (string) ($serverConn?->file_credentials['root_path'] ?? '/var/www/html');
        $dbDriver = $serverConn?->db_driver?->value ?? 'mysql';
        $dbName = (string) ($serverConn?->db_credentials['database'] ?? 'your_database');

        return [
            'website_name' => $run->website?->name,
            'website_domain' => $run->website?->domain,
            'run_uuid' => $run->uuid,
            'backup_type' => $run->backup_type,
            'scope' => $run->scope?->label(),
            'is_encrypted' => $isEncrypted,
            'chain_steps' => $chainSteps,
            'commands' => [
                'linux_verify_checksum' => "echo \"{$sha256}  {$fileName}\" | sha256sum --check",
                'powershell_verify_checksum' => "Get-FileHash -Path \".\\{$fileName}\" -Algorithm SHA256",
                'decrypt_note' => $isEncrypted
                    ? "Archive is password-protected with native AES-256. Windows / Mac / WinRAR / 7-Zip will automatically ask for your password when opening. Or decrypt via CLI: php artisan sitevaulta:decrypt-archive \"{$fileName}\" --passphrase=\"YOUR_POLICY_PASSPHRASE\""
                    : 'Archive is not encrypted; proceed directly to extraction.',
                'extract_archive' => $isEncrypted
                    ? "unzip -P \"YOUR_POLICY_PASSPHRASE\" -o \"{$fileName}\" -d ./restore-staging/"
                    : "unzip -o \"{$fileName}\" -d ./restore-staging/",
                'restore_files' => "rsync -av ./restore-staging/website-files/ \"{$rootPath}/\"",
                'restore_database' => $dbDriver === 'pgsql'
                    ? "psql -U DB_USER -d \"{$dbName}\" -f ./restore-staging/database-dump.sql"
                    : "mysql -u DB_USER -p \"{$dbName}\" < ./restore-staging/database-dump.sql",
            ],
            'checklist' => [
                '1. Download all required archives in the exact chain order listed above (Base Full Backup first, followed by each Incremental in sequence).',
                '2. Verify the SHA-256 checksum of every downloaded archive before extracting.',
                '3. Test the restore in a staging or local environment first before overwriting your production server.',
                '4. Enable application maintenance mode on your target server before syncing files or importing the database.',
                '5. Extract Base Full Backup first, then overlay each Incremental Backup in chronological order.',
                '6. Import database-dump.sql into your target database and verify application health.',
            ],
        ];
    }
}

