<?php

namespace App\Services\Forecast;

use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Models\BackupPolicy;
use App\Models\StorageConnection;
use App\Models\Website;
use App\Services\Settings\SystemSettingsService;

class StorageForecastService
{
    public static function calculate(
        ?Website $website,
        BackupScope $scope,
        BackupStrategy $strategy,
        int $intervalMinutes,
        int $retentionDays,
        int $fullResetEveryN = 7,
        ?StorageConnection $storage = null
    ): array {
        $intervalMinutes = max(15, $intervalMinutes);
        $retentionDays = max(1, $retentionDays);
        $fullResetEveryN = max(1, $fullResetEveryN);

        // Source sizes (fallback to realistic defaults if not yet scanned: 500MB files, 100MB DB)
        $filesRawBytes = $website?->known_files_size_bytes ?: (500 * 1024 * 1024);
        $dbRawBytes = $website?->known_db_size_bytes ?: (100 * 1024 * 1024);

        $rawSourceBytes = match ($scope) {
            BackupScope::FullStack => $filesRawBytes + $dbRawBytes,
            BackupScope::FilesOnly => $filesRawBytes,
            BackupScope::DatabaseOnly => $dbRawBytes,
        };

        // Dynamic ratios from Admin System Settings
        $compressionRatio = (float) SystemSettingsService::get('backup_engine', 'default_compression_ratio', 0.60);
        $deltaRate = (float) SystemSettingsService::get('backup_engine', 'default_incremental_delta_rate', 0.05);

        // Formula: N = floor((1440 / I) * R)
        $totalBackupsInWindow = max(1, (int) floor((1440 / $intervalMinutes) * $retentionDays));
        $estimatedFirstFullBytes = (int) round($rawSourceBytes * $compressionRatio);

        if ($strategy === BackupStrategy::FullOnly) {
            $fullCount = $totalBackupsInWindow;
            $incrementalCount = 0;
            $estimatedIncrementalBytes = 0;
            $projectedPeakStorageBytes = $fullCount * $estimatedFirstFullBytes;
        } else {
            $fullCount = (int) ceil($totalBackupsInWindow / $fullResetEveryN);
            $incrementalCount = max(0, $totalBackupsInWindow - $fullCount);
            $estimatedIncrementalBytes = (int) round($estimatedFirstFullBytes * $deltaRate);
            $projectedPeakStorageBytes = ($fullCount * $estimatedFirstFullBytes)
                + ($incrementalCount * $estimatedIncrementalBytes);
        }

        // Monthly transfer estimate (30 days)
        $runsPerMonth = max(1, (int) floor((1440 / $intervalMinutes) * 30));
        if ($strategy === BackupStrategy::FullOnly) {
            $monthlyTransferBytes = $runsPerMonth * $estimatedFirstFullBytes;
        } else {
            $monthlyFulls = (int) ceil($runsPerMonth / $fullResetEveryN);
            $monthlyIncs = max(0, $runsPerMonth - $monthlyFulls);
            $monthlyTransferBytes = ($monthlyFulls * $estimatedFirstFullBytes) + ($monthlyIncs * $estimatedIncrementalBytes);
        }

        $quotaStatus = 'unknown';
        $quotaMessage = 'Connect a storage provider with known quota to compare capacity.';

        if ($storage && $storage->quota_total_bytes > 0) {
            $availableBytes = max(0, $storage->quota_total_bytes - $storage->quota_used_bytes);
            if ($projectedPeakStorageBytes <= ($availableBytes * 0.75)) {
                $quotaStatus = 'healthy';
                $quotaMessage = 'Fits comfortably within your available storage quota (' . self::formatBytes($availableBytes) . ' free).';
            } elseif ($projectedPeakStorageBytes <= $availableBytes) {
                $quotaStatus = 'warning';
                $quotaMessage = 'Approaching storage quota limit (' . self::formatBytes($availableBytes) . ' free).';
            } else {
                $quotaStatus = 'exceeded';
                $quotaMessage = 'Projected usage exceeds available storage quota (' . self::formatBytes($availableBytes) . ' free)!';
            }
        }

        return [
            'raw_source_bytes' => $rawSourceBytes,
            'raw_source_formatted' => self::formatBytes($rawSourceBytes),
            'compression_ratio' => $compressionRatio,
            'incremental_delta_rate' => $deltaRate,
            'total_backups_in_window' => $totalBackupsInWindow,
            'full_backups_count' => $fullCount,
            'incremental_backups_count' => $incrementalCount,
            'first_full_bytes' => $estimatedFirstFullBytes,
            'first_full_formatted' => self::formatBytes($estimatedFirstFullBytes),
            'incremental_bytes' => $estimatedIncrementalBytes,
            'incremental_formatted' => self::formatBytes($estimatedIncrementalBytes),
            'projected_peak_storage_bytes' => $projectedPeakStorageBytes,
            'projected_peak_storage_formatted' => self::formatBytes($projectedPeakStorageBytes),
            'monthly_transfer_bytes' => $monthlyTransferBytes,
            'monthly_transfer_formatted' => self::formatBytes($monthlyTransferBytes),
            'quota_status' => $quotaStatus,
            'quota_message' => $quotaMessage,
        ];
    }

    public static function formatBytes(int|float $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = (int) floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);
        $val = $bytes / (1024 ** $pow);

        return round($val, 2) . ' ' . $units[$pow];
    }
}

