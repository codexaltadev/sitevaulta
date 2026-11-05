<?php

namespace App\Services\Restore;

use App\Enums\DownloadMode;
use App\Models\BackupArtifact;
use App\Models\DownloadToken;
use App\Services\Security\AuditLogService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Storage\StorageAdapterManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DownloadTokenService
{
    /**
     * Issues a one-time atomic token or presigned URL for an artifact.
     */
    public static function issueDownloadLink(
        BackupArtifact $artifact,
        ?DownloadMode $mode = null,
        ?string $decryptionPassphrase = null,
        ?string $customReEncryptPassphrase = null
    ): array {
        $mode ??= DownloadMode::OneTimeToken;
        if (! empty($decryptionPassphrase) || ! empty($customReEncryptPassphrase)) {
            // When user requests on-the-fly decrypted zip or custom re-encryption, route through server handler
            $mode = DownloadMode::OneTimeToken;
        }

        $ttlMinutes = $mode === DownloadMode::PresignedUrl
            ? (int) SystemSettingsService::get('security', 'presigned_url_ttl_minutes', 15)
            : (int) SystemSettingsService::get('security', 'one_time_token_ttl_minutes', 15);

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = now()->utc()->addMinutes($ttlMinutes);

        if (! empty($decryptionPassphrase)) {
            $payload = [
                'master_passphrase' => $decryptionPassphrase,
                'custom_passphrase' => $customReEncryptPassphrase,
            ];
            \Illuminate\Support\Facades\Cache::put("sitevaulta:download_decrypt:{$tokenHash}", $payload, $expiresAt);
        }

        $tokenRecord = DownloadToken::create([
            'token_hash' => $tokenHash,
            'workspace_id' => $artifact->workspace_id,
            'user_id' => auth()->id(),
            'backup_artifact_id' => $artifact->id,
            'mode' => $mode,
            'status' => 'issued',
            'ip_address' => request()?->ip(),
            'expires_at' => $expiresAt,
        ]);

        AuditLogService::log(
            event: 'artifact.download_token.issued',
            auditable: $artifact,
            metadata: ['mode' => $mode->value, 'token_id' => $tokenRecord->id],
            workspaceId: $artifact->workspace_id
        );

        if ($mode === DownloadMode::PresignedUrl && $artifact->storageConnection?->supports_presigned_url) {
            $url = StorageAdapterManager::createPresignedDownloadUrl($artifact, $ttlMinutes);
        } else {
            $url = route('artifacts.one-time-download', ['token' => $plainToken]);
        }

        return [
            'url' => $url,
            'mode' => $mode->value,
            'expires_at' => $expiresAt->toIso8601String(),
            'ttl_minutes' => $ttlMinutes,
        ];
    }

    /**
     * Atomically claims and validates a one-time token for download.
     */
    public static function claimOneTimeToken(string $plainToken): DownloadToken
    {
        $tokenHash = hash('sha256', $plainToken);
        $maxRetries = (int) SystemSettingsService::get('security', 'max_download_resume_retries', 3);

        return DB::transaction(function () use ($tokenHash, $maxRetries): DownloadToken {
            /** @var ?DownloadToken $token */
            $token = DownloadToken::with(['backupArtifact.storageConnection'])
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $token) {
                throw new NotFoundHttpException('Invalid download token.');
            }

            if ($token->status === 'consumed') {
                throw new GoneHttpException('This one-time download link has already been used and is no longer valid.');
            }

            if ($token->status === 'revoked' || $token->expires_at->isPast()) {
                $token->update(['status' => 'expired']);
                throw new GoneHttpException('This download link has expired. Please generate a new link from your dashboard.');
            }

            if ($token->status === 'streaming') {
                if ($token->retry_count >= $maxRetries) {
                    $token->update(['status' => 'expired']);
                    throw new GoneHttpException('Maximum interrupted download retry limit reached.');
                }
                $token->increment('retry_count');
            } else {
                $token->update([
                    'status' => 'streaming',
                    'claimed_at' => now()->utc(),
                ]);
            }

            return $token;
        });
    }

    public static function markConsumed(DownloadToken $token): void
    {
        $token->update([
            'status' => 'consumed',
            'consumed_at' => now()->utc(),
        ]);

        AuditLogService::log(
            event: 'artifact.downloaded',
            auditable: $token->backupArtifact,
            metadata: ['token_id' => $token->id, 'mode' => $token->mode->value],
            workspaceId: $token->workspace_id,
            userId: $token->user_id
        );
    }
}

