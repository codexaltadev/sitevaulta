<?php

namespace App\Jobs\Backup;

use App\Enums\BackupRunStatus;
use App\Enums\BackupStrategy;
use App\Models\BackupArtifact;
use App\Models\BackupChain;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Services\Backup\ArchivePackagerService;
use App\Services\Billing\EntitlementService;
use App\Services\Connectors\ServerConnectorManager;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\AuditLogService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Storage\StorageAdapterManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ExecuteBackupRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 7200;

    public int $backoff = 60;

    public function __construct(
        public int $backupPolicyId,
        public string $triggerType = 'scheduled',
        public ?string $triggerTag = null,
        public ?string $overrideNotificationEmail = null,
        public ?bool $overrideNotifyOnSuccess = null,
        public ?bool $overrideNotifyOnFailure = null
    ) {
        $this->tries = (int) SystemSettingsService::get('backup_engine', 'max_retry_attempts', 3);
        $this->timeout = (int) SystemSettingsService::get('backup_engine', 'job_timeout_seconds', 7200);
        $this->backoff = (int) SystemSettingsService::get('backup_engine', 'retry_backoff_seconds', 60);
    }

    public function handle(): ?BackupRun
    {
        $policy = BackupPolicy::with(['workspace', 'website', 'serverConnection', 'storageConnection'])
            ->find($this->backupPolicyId);

        if (! $policy) {
            return null;
        }

        // 1. Pre-Execution Entitlement Check (Blocks expired trial/subscription at worker level)
        if (! EntitlementService::canExecutePolicy($policy)) {
            return null;
        }

        // Concurrency Lock to prevent overlapping runs for the same policy
        $lock = Cache::lock("sitevaulta:policy-lock:{$policy->id}", 3600);
        if (! $lock->get()) {
            return null;
        }

        $stagingDir = null;
        $run = null;

        try {
            // 2. Resolve Full vs Incremental Chain Strategy
            [$backupType, $chain, $parentRun, $previousManifest] = $this->resolveChainStrategy($policy);

            $run = BackupRun::create([
                'workspace_id' => $policy->workspace_id,
                'website_id' => $policy->website_id,
                'backup_policy_id' => $policy->id,
                'backup_chain_id' => $chain->id,
                'parent_run_id' => $parentRun?->id,
                'trigger_type' => $this->triggerType,
                'trigger_tag' => $this->triggerTag,
                'email_provider_mode' => $policy->email_provider_mode ?: 'workspace_default',
                'notification_email' => $this->overrideNotificationEmail ?? $policy->notification_email,
                'notify_on_success' => $this->overrideNotifyOnSuccess ?? (bool) ($policy->notify_on_success ?? true),
                'notify_on_failure' => $this->overrideNotifyOnFailure ?? (bool) ($policy->notify_on_failure ?? true),
                'backup_type' => $backupType,
                'scope' => $policy->scope,
                'status' => BackupRunStatus::Connecting,
                'started_at' => now()->utc(),
                'retention_expires_at' => now()->utc()->addDays($policy->retention_days),
            ]);

            // 3. Create Ephemeral Staging Directory (/tmp/sitevaulta/run-{uuid})
            $stagingDir = ArchivePackagerService::createEphemeralStagingDir($run->uuid);

            // 4. Extract Files & Database from Customer Server
            $run->update(['status' => BackupRunStatus::Extracting]);

            $extraction = ServerConnectorManager::extractWorkoutToStaging(
                connection: $policy->serverConnection,
                stagingDir: $stagingDir,
                includeFiles: $policy->scope->includesFiles(),
                includeDatabase: $policy->scope->includesDatabase(),
                excludePaths: $policy->exclude_paths ?? [],
                previousManifest: $backupType === 'incremental' ? $previousManifest : null
            );

            // 5. Package, Compress, Encrypt (AES-256-GCM) & Compute SHA-256 Checksum
            $run->update([
                'status' => BackupRunStatus::Packaging,
                'source_files_count' => $extraction->sourceFilesCount,
                'changed_files_count' => $extraction->changedFilesCount,
                'raw_source_size_bytes' => $extraction->rawSourceSizeBytes,
            ]);

            $timestampSlug = now()->utc()->format('Y-m-d_His');
            $triggerPrefix = ($this->triggerType === 'manual') ? 'manual' : 'auto';
            $archiveBaseName = sprintf(
                '%s_%s_%s_%s_%s',
                $policy->website->domain,
                $triggerPrefix,
                $backupType,
                $policy->scope->value,
                $timestampSlug
            );

            $package = ArchivePackagerService::packageAndEncrypt(
                stagingDir: $stagingDir,
                archiveBaseName: $archiveBaseName,
                enableEncryption: $policy->enable_encryption,
                passphrase: $policy->encryption_passphrase
            );

            // 6. Stream Upload to Customer-Owned Storage (BYOS)
            $run->update([
                'status' => BackupRunStatus::Uploading,
                'archive_size_bytes' => $package['size_bytes'],
            ]);

            $remotePath = sprintf(
                '%s/%s/%s/%s/%s',
                rtrim($policy->storageConnection->root_prefix ?: 'sitevaulta-backups', '/'),
                $policy->workspace->slug,
                $policy->website->domain,
                $chain->uuid,
                $package['file_name']
            );

            StorageAdapterManager::uploadArtifactFile(
                storage: $policy->storageConnection,
                localFilePath: $package['local_path'],
                remoteObjectPath: $remotePath
            );

            // 7. Remote Object Existence & Size Integrity Verification
            $run->update(['status' => BackupRunStatus::Verifying]);

            $verified = StorageAdapterManager::verifyRemoteArtifact(
                storage: $policy->storageConnection,
                remoteObjectPath: $remotePath,
                expectedSizeBytes: $package['size_bytes']
            );

            if (! $verified || $package['size_bytes'] <= 0) {
                throw new \RuntimeException('Backup artifact integrity verification failed on remote storage.');
            }

            BackupArtifact::create([
                'workspace_id' => $policy->workspace_id,
                'backup_run_id' => $run->id,
                'storage_connection_id' => $policy->storageConnection->id,
                'artifact_type' => 'combined_archive',
                'remote_path' => $remotePath,
                'file_name' => $package['file_name'],
                'size_bytes' => $package['size_bytes'],
                'sha256_checksum' => $package['sha256_checksum'],
                'compression_algo' => $package['compression_algo'],
                'is_encrypted' => $package['is_encrypted'],
                'encryption_iv' => $package['encryption_iv'],
                'file_index_manifest' => $extraction->fileIndexManifest,
                'verification_status' => 'verified',
                'verified_at' => now()->utc(),
            ]);

            // 8. Update Chain & Check for Smart Size Anomalies
            if ($backupType === 'full') {
                $chain->update([
                    'base_full_run_id' => $run->id,
                    'total_chain_size_bytes' => $package['size_bytes'],
                    'expires_at' => $run->retention_expires_at,
                ]);
            } else {
                $chain->increment('incremental_count');
                $chain->increment('total_chain_size_bytes', $package['size_bytes']);
                $chain->update(['expires_at' => $run->retention_expires_at]);
            }

            [$isAnomaly, $anomalyReason] = $this->detectSizeAnomaly($policy, $backupType, $package['size_bytes'], $run->id);

            $completedAt = now()->utc();
            $run->update([
                'status' => BackupRunStatus::Completed,
                'is_anomaly' => $isAnomaly,
                'anomaly_reason' => $anomalyReason,
                'completed_at' => $completedAt,
                'duration_seconds' => max(1, (int) $run->started_at->diffInSeconds($completedAt)),
            ]);

            $policy->update([
                'last_run_at' => $completedAt,
                'next_run_at' => $completedAt->copy()->addMinutes($policy->interval_minutes ?: 1440),
            ]);

            if ($isAnomaly) {
                WorkspaceAlertDispatcher::dispatch($policy->workspace, 'anomaly_detected', [
                    'website_name' => $policy->website->name,
                    'run_uuid' => $run->uuid,
                    'anomaly_reason' => $anomalyReason,
                    'archive_size' => round($package['size_bytes'] / 1024, 2).' KB',
                ]);
            } else {
                WorkspaceAlertDispatcher::dispatch($policy->workspace, 'backup_succeeded', [
                    'website_name' => $policy->website->name,
                    'domain' => $policy->website->domain,
                    'policy_name' => $policy->name,
                    'run_uuid' => $run->uuid,
                    'archive_size' => round($package['size_bytes'] / 1024, 2).' KB',
                ]);
            }

            WorkspaceAlertDispatcher::sendPolicyRunEmail($policy, $run, isSuccess: true);

            AuditLogService::log(
                event: 'backup.run.completed',
                auditable: $run,
                metadata: [
                    'backup_type' => $backupType,
                    'size_bytes' => $package['size_bytes'],
                    'sha256' => $package['sha256_checksum'],
                ],
                workspaceId: $policy->workspace_id
            );

            return $run->fresh();
        } catch (\Throwable $e) {
            if ($run) {
                $run->update([
                    'status' => BackupRunStatus::Failed,
                    'completed_at' => now()->utc(),
                    'error_code' => 'BACKUP_EXECUTION_ERROR',
                    'error_message' => $e->getMessage(),
                ]);

                WorkspaceAlertDispatcher::dispatch($policy->workspace, 'backup_failed', [
                    'website_name' => $policy->website->name,
                    'domain' => $policy->website->domain,
                    'policy_name' => $policy->name,
                    'status' => $run->status->value,
                    'error_message' => $e->getMessage(),
                ]);

                WorkspaceAlertDispatcher::sendPolicyRunEmail($policy, $run, isSuccess: false, errorMessage: $e->getMessage());
            }

            return $run?->fresh();
        } finally {
            // 9. Guaranteed Zero-Retention Ephemeral Cleanup on SiteVaulta Worker
            if ($stagingDir !== null) {
                ArchivePackagerService::wipeEphemeralStagingDir($stagingDir);
            }
            $lock->release();
        }
    }

    protected function resolveChainStrategy(BackupPolicy $policy): array
    {
        $supportsIncremental = $policy->serverConnection?->supportsIncremental() ?? true;

        if ($policy->strategy === BackupStrategy::FullOnly || ! $supportsIncremental) {
            $chain = BackupChain::create([
                'workspace_id' => $policy->workspace_id,
                'website_id' => $policy->website_id,
                'backup_policy_id' => $policy->id,
                'status' => 'active',
            ]);

            return ['full', $chain, null, null];
        }

        // Look for an active chain that hasn't reached full_reset_every_n
        $activeChain = BackupChain::where('backup_policy_id', $policy->id)
            ->where('status', 'active')
            ->whereNotNull('base_full_run_id')
            ->latest()
            ->first();

        $maxIncrementals = max(1, (int) ($policy->full_reset_every_n ?: 7));

        if (! $activeChain || $activeChain->incremental_count >= $maxIncrementals) {
            if ($activeChain) {
                $activeChain->update(['status' => 'sealed']);
            }

            $newChain = BackupChain::create([
                'workspace_id' => $policy->workspace_id,
                'website_id' => $policy->website_id,
                'backup_policy_id' => $policy->id,
                'status' => 'active',
            ]);

            return ['full', $newChain, null, null];
        }

        // Find the most recent successful run in this chain to diff against
        $lastSuccessfulRun = BackupRun::where('backup_chain_id', $activeChain->id)
            ->where('status', BackupRunStatus::Completed)
            ->latest('id')
            ->first();

        if (! $lastSuccessfulRun) {
            return ['full', $activeChain, null, null];
        }

        $lastArtifact = $lastSuccessfulRun->artifacts()->latest('id')->first();
        $previousManifest = $lastArtifact?->file_index_manifest ?? [];

        return ['incremental', $activeChain, $lastSuccessfulRun, $previousManifest];
    }

    protected function detectSizeAnomaly(BackupPolicy $policy, string $backupType, int $currentBytes, int $excludeRunId): array
    {
        $historicalAvg = BackupRun::where('backup_policy_id', $policy->id)
            ->where('backup_type', $backupType)
            ->where('status', BackupRunStatus::Completed)
            ->where('id', '!=', $excludeRunId)
            ->latest('id')
            ->limit(7)
            ->avg('archive_size_bytes');

        if (! $historicalAvg || $historicalAvg <= 0) {
            return [false, null];
        }

        $dropThreshold = (int) SystemSettingsService::get('backup_engine', 'anomaly_drop_threshold_percent', 50);
        $spikeThreshold = (int) SystemSettingsService::get('backup_engine', 'anomaly_spike_threshold_percent', 200);

        $changeRatio = ($currentBytes / $historicalAvg) * 100;

        if ($changeRatio < (100 - $dropThreshold)) {
            $dropPercent = round(100 - $changeRatio, 1);

            return [true, "Archive size dropped by {$dropPercent}% compared to recent {$backupType} average."];
        }

        if ($changeRatio > (100 + $spikeThreshold)) {
            $spikePercent = round($changeRatio - 100, 1);

            return [true, "Archive size spiked by +{$spikePercent}% compared to recent {$backupType} average."];
        }

        return [false, null];
    }
}
