<?php

namespace App\Jobs\Backup;

use App\Enums\BackupRunStatus;
use App\Models\BackupChain;
use App\Models\BackupRun;
use App\Services\Security\AuditLogService;
use App\Services\Storage\StorageAdapterManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneExpiredBackupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): int
    {
        $prunedCount = 0;

        // Inspect chains where all runs have passed retention_expires_at
        $chains = BackupChain::with(['workspace', 'backupRuns.artifacts.storageConnection'])
            ->whereIn('status', ['sealed', 'active'])
            ->get();

        foreach ($chains as $chain) {
            // Never automatically delete files from customer storage if their trial/subscription is expired
            if (! $chain->workspace || ! $chain->workspace->hasActiveEntitlement()) {
                continue;
            }

            $unprunedRuns = $chain->backupRuns->where('is_pruned', false);
            if ($unprunedRuns->isEmpty()) {
                continue;
            }

            // Chain-Safe Dependency Rule:
            // If ANY incremental run in this chain has NOT yet expired, we CANNOT prune its Parent Full Backup or earlier incrementals!
            $hasActiveDependentRun = $unprunedRuns->contains(function (BackupRun $run): bool {
                return $run->retention_expires_at === null || $run->retention_expires_at->isFuture();
            });

            if ($hasActiveDependentRun) {
                continue;
            }

            // Also ensure there is at least one newer completed chain for the policy before deleting the last full backup
            $hasNewerCompletedFull = BackupRun::where('backup_policy_id', $chain->backup_policy_id)
                ->where('backup_chain_id', '>', $chain->id)
                ->where('backup_type', 'full')
                ->where('status', BackupRunStatus::Completed)
                ->where('is_pruned', false)
                ->exists();

            if (! $hasNewerCompletedFull) {
                continue;
            }

            // Safe to prune entire expired chain
            foreach ($unprunedRuns as $run) {
                foreach ($run->artifacts as $artifact) {
                    StorageAdapterManager::deleteArtifactFromStorage($artifact);
                    $artifact->update(['verification_status' => 'deleted']);
                }

                $run->update(['is_pruned' => true]);
                $prunedCount++;
            }

            $chain->update(['status' => 'expired']);

            AuditLogService::log(
                event: 'backup.chain.pruned',
                auditable: $chain,
                metadata: ['runs_pruned' => $unprunedRuns->count()],
                workspaceId: $chain->workspace_id
            );
        }

        return $prunedCount;
    }
}

