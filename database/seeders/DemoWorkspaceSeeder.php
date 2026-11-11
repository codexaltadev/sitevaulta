<?php

namespace Database\Seeders;

use App\Enums\BackupRunStatus;
use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Enums\DatabaseDriver;
use App\Enums\DownloadMode;
use App\Enums\FileProtocol;
use App\Enums\MaintenanceScheduleType;
use App\Enums\MonitorIncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\ScheduleFrequency;
use App\Enums\StorageDriver;
use App\Models\BackupArtifact;
use App\Models\BackupChain;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\ServerConnection;
use App\Models\StatusPage;
use App\Models\StorageConnection;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Verification\DomainVerificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoWorkspaceSeeder extends Seeder
{
    /**
     * Seeds rich, realistic demonstration data for a given workspace.
     */
    public function run(?Workspace $workspace = null): void
    {
        if (! $workspace) {
            $workspace = Workspace::where('slug', 'demo-agency')->first()
                ?? Workspace::where('slug', 'sitevaulta-hq')->first()
                ?? Workspace::first();
        }

        if (! $workspace) {
            return;
        }

        $this->seedDemoForWorkspace($workspace);
    }

    public function seedDemoForWorkspace(Workspace $workspace): void
    {
        // ---------------------------------------------------------------------
        // 1. Storage Connections (Google Drive, AWS S3, Cloudflare R2)
        // ---------------------------------------------------------------------
        $gdrive = StorageConnection::updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Google Drive (OAuth - Primary)'],
            [
                'driver' => StorageDriver::GoogleDrive,
                'credentials' => [
                    'client_id' => 'demo-client-id.apps.googleusercontent.com',
                    'client_secret' => 'demo-client-secret',
                    'refresh_token' => 'demo-refresh-token-1//049182a',
                    'folder_name' => 'sitevaulta-agency-backups',
                    'simulation_mode' => true,
                ],
                'root_prefix' => 'sitevaulta-backups',
                'supports_presigned_url' => false,
                'quota_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
                'quota_used_bytes' => 18 * 1024 * 1024 * 1024,  // 18 GB
                'health_status' => 'healthy',
                'last_verified_at' => now()->subMinutes(12),
            ]
        );

        $s3 = StorageConnection::updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Amazon S3 US-East Vault'],
            [
                'driver' => StorageDriver::S3,
                'credentials' => [
                    'key' => 'AKIA_DEMO_KEY_9921',
                    'secret' => 'demo-secret-key-38491848',
                    'region' => 'us-east-1',
                    'bucket' => 'sitevaulta-production-vault',
                    'simulation_mode' => true,
                ],
                'root_prefix' => 'daily-archives',
                'supports_presigned_url' => true,
                'quota_total_bytes' => 500 * 1024 * 1024 * 1024, // 500 GB
                'quota_used_bytes' => 52 * 1024 * 1024 * 1024,  // 52 GB
                'health_status' => 'healthy',
                'last_verified_at' => now()->subHours(1),
            ]
        );

        $r2 = StorageConnection::updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Cloudflare R2 Zero-Egress'],
            [
                'driver' => StorageDriver::CloudflareR2,
                'credentials' => [
                    'key' => 'r2_demo_access_token',
                    'secret' => 'r2_demo_secret_token_8819',
                    'bucket' => 'agency-cold-storage',
                    'region' => 'auto',
                    'endpoint' => 'https://demo-account.r2.cloudflarestorage.com',
                    'simulation_mode' => true,
                ],
                'root_prefix' => 'cold-backups',
                'supports_presigned_url' => true,
                'quota_total_bytes' => 200 * 1024 * 1024 * 1024, // 200 GB
                'quota_used_bytes' => 14 * 1024 * 1024 * 1024,  // 14 GB
                'health_status' => 'healthy',
                'last_verified_at' => now()->subMinutes(45),
            ]
        );

        // ---------------------------------------------------------------------
        // 2. Websites & Verified Server Connectors
        // ---------------------------------------------------------------------
        // Website 1: WooCommerce Production Store
        $site1 = Website::updateOrCreate(
            ['workspace_id' => $workspace->id, 'domain' => 'shop-fashionbd.com'],
            [
                'name' => 'FashionBD Online Store (WooCommerce)',
                'url' => 'https://shop-fashionbd.com',
                'environment' => 'production',
                'webhook_secret' => 'sv_wh_live_'.Str::random(24),
                'known_files_size_bytes' => 420 * 1024 * 1024, // 420 MB
                'known_db_size_bytes' => 75 * 1024 * 1024,   // 75 MB
                'status' => 'active',
            ]
        );

        ServerConnection::updateOrCreate(
            ['workspace_id' => $workspace->id, 'website_id' => $site1->id],
            [
                'name' => 'FashionBD VPS (SSH/SFTP + MySQL)',
                'file_protocol' => FileProtocol::SshSftp,
                'file_credentials' => [
                    'host' => '103.114.98.42',
                    'port' => 22,
                    'username' => 'forge',
                    'root_path' => '/home/forge/shop-fashionbd.com',
                    'simulation_mode' => true,
                ],
                'db_driver' => DatabaseDriver::MySql,
                'db_use_ssh_tunnel' => true,
                'db_credentials' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'fashionbd_prod',
                    'username' => 'fashion_db_usr',
                ],
                'capabilities' => ['files' => true, 'db' => true, 'inc_files' => true],
                'health_status' => 'healthy',
                'last_tested_at' => now()->subMinutes(30),
            ]
        );

        // Website 2: Travel Agency API (Laravel + PostgreSQL)
        $site2 = Website::updateOrCreate(
            ['workspace_id' => $workspace->id, 'domain' => 'api.travelmatrix.io'],
            [
                'name' => 'TravelMatrix Global API (Laravel)',
                'url' => 'https://api.travelmatrix.io',
                'environment' => 'production',
                'webhook_secret' => 'sv_wh_live_'.Str::random(24),
                'known_files_size_bytes' => 180 * 1024 * 1024, // 180 MB
                'known_db_size_bytes' => 160 * 1024 * 1024,  // 160 MB
                'status' => 'active',
            ]
        );

        ServerConnection::updateOrCreate(
            ['workspace_id' => $workspace->id, 'website_id' => $site2->id],
            [
                'name' => 'TravelMatrix Dedicated App Server (PostgreSQL)',
                'file_protocol' => FileProtocol::SshSftp,
                'file_credentials' => [
                    'host' => '159.89.172.90',
                    'port' => 2202,
                    'username' => 'deployer',
                    'root_path' => '/var/www/travelmatrix-api',
                    'simulation_mode' => true,
                ],
                'db_driver' => DatabaseDriver::PostgreSql,
                'db_use_ssh_tunnel' => false,
                'db_credentials' => [
                    'host' => '10.120.0.8',
                    'port' => 5432,
                    'database' => 'travelmatrix_production',
                    'username' => 'tm_pg_user',
                ],
                'capabilities' => ['files' => true, 'db' => true, 'inc_files' => true],
                'health_status' => 'healthy',
                'last_tested_at' => now()->subHours(2),
            ]
        );

        // Website 3: Fintech Internal CRM
        $site3 = Website::updateOrCreate(
            ['workspace_id' => $workspace->id, 'domain' => 'crm.fintechcloud.net'],
            [
                'name' => 'Fintech Enterprise CRM (Node.js)',
                'url' => 'https://crm.fintechcloud.net',
                'environment' => 'production',
                'webhook_secret' => 'sv_wh_live_'.Str::random(24),
                'known_files_size_bytes' => 650 * 1024 * 1024, // 650 MB
                'known_db_size_bytes' => 110 * 1024 * 1024,  // 110 MB
                'status' => 'active',
            ]
        );

        // Ownership lives on each website's verified_domains row.
        foreach ([[$site1, 'dns_txt', 28], [$site2, 'html_file', 14], [$site3, 'meta_tag', 7]] as [$site, $method, $daysAgo]) {
            DomainVerificationService::ensureChallengeExists($site)->update(['verification_method' => $method, 'verified_at' => now()->subDays($daysAgo)]);
        }

        ServerConnection::updateOrCreate(
            ['workspace_id' => $workspace->id, 'website_id' => $site3->id],
            [
                'name' => 'Fintech cPanel Host & Managed MySQL',
                'file_protocol' => FileProtocol::CPanelApi,
                'file_credentials' => [
                    'host' => 'cpanel.fintechcloud.net',
                    'port' => 2083,
                    'username' => 'fintechcrm',
                    'root_path' => '/public_html',
                    'simulation_mode' => true,
                ],
                'db_driver' => DatabaseDriver::MySql,
                'db_use_ssh_tunnel' => false,
                'db_credentials' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'database' => 'fintech_crm_db',
                    'username' => 'fintech_sql',
                ],
                'capabilities' => ['files' => true, 'db' => true, 'inc_files' => false],
                'health_status' => 'healthy',
                'last_tested_at' => now()->subHours(4),
            ]
        );

        // ---------------------------------------------------------------------
        // 3. Backup Policies
        // ---------------------------------------------------------------------
        $policy1 = BackupPolicy::updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'FashionBD Daily Incremental Chain'],
            [
                'website_id' => $site1->id,
                'server_connection_id' => $site1->serverConnections->first()?->id,
                'storage_connection_id' => $gdrive->id,
                'scope' => BackupScope::FullStack,
                'strategy' => BackupStrategy::IncrementalWithPeriodicFull,
                'full_reset_every_n' => 7,
                'schedule_frequency' => ScheduleFrequency::Daily,
                'interval_minutes' => 1440,
                'retention_days' => 14,
                'exclude_paths' => ['.git', 'wp-content/cache/*', 'wp-content/uploads/temp/*'],
                'enable_encryption' => true,
                'download_mode' => DownloadMode::OneTimeToken,
                'notification_email' => 'demo@sitevaulta.com',
                'notify_on_success' => true,
                'notify_on_failure' => true,
                'status' => 'active',
                'last_run_at' => now()->subHours(6),
                'next_run_at' => now()->addHours(2)->addMinutes(15),
            ]
        );

        $policy2 = BackupPolicy::updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'TravelMatrix 6-Hourly DB Snapshots'],
            [
                'website_id' => $site2->id,
                'server_connection_id' => $site2->serverConnections->first()?->id,
                'storage_connection_id' => $s3->id,
                'scope' => BackupScope::DatabaseOnly,
                'strategy' => BackupStrategy::FullOnly,
                'full_reset_every_n' => 1,
                'schedule_frequency' => ScheduleFrequency::EverySixHours,
                'interval_minutes' => 360,
                'retention_days' => 7,
                'exclude_paths' => [],
                'enable_encryption' => true,
                'download_mode' => DownloadMode::PresignedUrl,
                'status' => 'active',
                'last_run_at' => now()->subHours(2),
                'next_run_at' => now()->addHours(3)->addMinutes(45),
            ]
        );

        $policy3 = BackupPolicy::updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Fintech CRM Weekly Cold Archive'],
            [
                'website_id' => $site3->id,
                'server_connection_id' => $site3->serverConnections->first()?->id,
                'storage_connection_id' => $r2->id,
                'scope' => BackupScope::FullStack,
                'strategy' => BackupStrategy::FullOnly,
                'full_reset_every_n' => 1,
                'schedule_frequency' => ScheduleFrequency::Weekly,
                'interval_minutes' => 10080,
                'retention_days' => 30,
                'exclude_paths' => ['node_modules', '.cache'],
                'enable_encryption' => true,
                'download_mode' => DownloadMode::OneTimeToken,
                'notification_email' => 'demo@sitevaulta.com',
                'notify_on_success' => true,
                'notify_on_failure' => true,
                'status' => 'active',
                'last_run_at' => now()->subDays(3),
                'next_run_at' => now()->addDays(4),
            ]
        );

        // ---------------------------------------------------------------------
        // 4. Backup Chains & Historical Backup Runs (Real & Simulated Metrics)
        // ---------------------------------------------------------------------
        // Chain A: FashionBD Incremental Chain
        $chain1 = BackupChain::updateOrCreate(
            ['workspace_id' => $workspace->id, 'website_id' => $site1->id, 'backup_policy_id' => $policy1->id],
            [
                'incremental_count' => 3,
                'total_chain_size_bytes' => (260 + 18 + 24 + 16) * 1024 * 1024,
                'status' => 'active',
                'expires_at' => now()->addDays(11),
            ]
        );

        // Run 1: Base Full
        $run1 = BackupRun::updateOrCreate(
            ['workspace_id' => $workspace->id, 'backup_chain_id' => $chain1->id, 'backup_type' => 'full'],
            [
                'website_id' => $site1->id,
                'backup_policy_id' => $policy1->id,
                'trigger_type' => 'scheduled',
                'trigger_tag' => 'Schedule-Daily-Full',
                'scope' => BackupScope::FullStack,
                'status' => BackupRunStatus::Completed,
                'source_files_count' => 14820,
                'changed_files_count' => 14820,
                'raw_source_size_bytes' => 495 * 1024 * 1024, // 495 MB
                'archive_size_bytes' => 260 * 1024 * 1024,    // 260 MB
                'is_anomaly' => false,
                'attempt_count' => 1,
                'started_at' => now()->subDays(3)->setTime(2, 0, 0),
                'completed_at' => now()->subDays(3)->setTime(2, 0, 34),
                'duration_seconds' => 34,
                'retention_expires_at' => now()->addDays(11),
            ]
        );

        $chain1->update(['base_full_run_id' => $run1->id]);

        BackupArtifact::updateOrCreate(
            ['workspace_id' => $workspace->id, 'backup_run_id' => $run1->id],
            [
                'storage_connection_id' => $gdrive->id,
                'artifact_type' => 'combined_zip',
                'remote_path' => 'sitevaulta-backups/shop-fashionbd.com/run_'.$run1->id.'_full.enc.zip',
                'file_name' => 'shop-fashionbd_2026-10-26_base_full.enc.zip',
                'size_bytes' => 260 * 1024 * 1024,
                'sha256_checksum' => 'c83f982a1740b2e88a31e84712fc6572a91b48e30198f82163b782914cda9021',
                'compression_algo' => 'zip/deflate',
                'is_encrypted' => true,
                'verification_status' => 'verified',
                'verified_at' => now()->subDays(3)->setTime(2, 0, 35),
            ]
        );

        // Run 2: Incremental Delta #1
        $run2 = BackupRun::updateOrCreate(
            ['workspace_id' => $workspace->id, 'parent_run_id' => $run1->id, 'backup_type' => 'incremental'],
            [
                'website_id' => $site1->id,
                'backup_policy_id' => $policy1->id,
                'backup_chain_id' => $chain1->id,
                'trigger_type' => 'scheduled',
                'trigger_tag' => 'Delta-Inc-1',
                'scope' => BackupScope::FullStack,
                'status' => BackupRunStatus::Completed,
                'source_files_count' => 14835,
                'changed_files_count' => 142,
                'raw_source_size_bytes' => 497 * 1024 * 1024,
                'archive_size_bytes' => 18 * 1024 * 1024, // 18 MB only!
                'is_anomaly' => false,
                'attempt_count' => 1,
                'started_at' => now()->subDays(2)->setTime(2, 0, 0),
                'completed_at' => now()->subDays(2)->setTime(2, 0, 12),
                'duration_seconds' => 12,
                'retention_expires_at' => now()->addDays(12),
            ]
        );

        BackupArtifact::updateOrCreate(
            ['workspace_id' => $workspace->id, 'backup_run_id' => $run2->id],
            [
                'storage_connection_id' => $gdrive->id,
                'artifact_type' => 'combined_zip',
                'remote_path' => 'sitevaulta-backups/shop-fashionbd.com/run_'.$run2->id.'_inc1.enc.zip',
                'file_name' => 'shop-fashionbd_2026-10-27_delta_inc1.enc.zip',
                'size_bytes' => 18 * 1024 * 1024,
                'sha256_checksum' => 'a91b248e30198f82163b782914cda9021c83f982a1740b2e88a31e84712fc657',
                'compression_algo' => 'zip/deflate',
                'is_encrypted' => true,
                'verification_status' => 'verified',
                'verified_at' => now()->subDays(2)->setTime(2, 0, 13),
            ]
        );

        // Run 3: Incremental Delta #2
        $run3 = BackupRun::updateOrCreate(
            ['workspace_id' => $workspace->id, 'parent_run_id' => $run2->id, 'backup_type' => 'incremental'],
            [
                'website_id' => $site1->id,
                'backup_policy_id' => $policy1->id,
                'backup_chain_id' => $chain1->id,
                'trigger_type' => 'scheduled',
                'trigger_tag' => 'Delta-Inc-2',
                'scope' => BackupScope::FullStack,
                'status' => BackupRunStatus::Completed,
                'source_files_count' => 14890,
                'changed_files_count' => 205,
                'raw_source_size_bytes' => 502 * 1024 * 1024,
                'archive_size_bytes' => 24 * 1024 * 1024, // 24 MB
                'is_anomaly' => false,
                'attempt_count' => 1,
                'started_at' => now()->subDay()->setTime(2, 0, 0),
                'completed_at' => now()->subDay()->setTime(2, 0, 14),
                'duration_seconds' => 14,
                'retention_expires_at' => now()->addDays(13),
            ]
        );

        BackupArtifact::updateOrCreate(
            ['workspace_id' => $workspace->id, 'backup_run_id' => $run3->id],
            [
                'storage_connection_id' => $gdrive->id,
                'artifact_type' => 'combined_zip',
                'remote_path' => 'sitevaulta-backups/shop-fashionbd.com/run_'.$run3->id.'_inc2.enc.zip',
                'file_name' => 'shop-fashionbd_2026-10-28_delta_inc2.enc.zip',
                'size_bytes' => 24 * 1024 * 1024,
                'sha256_checksum' => 'e47c12fc6572a91b48e30198f82163b782914cda9021c83f982a1740b2e88a31',
                'compression_algo' => 'zip/deflate',
                'is_encrypted' => true,
                'verification_status' => 'verified',
                'verified_at' => now()->subDay()->setTime(2, 0, 15),
            ]
        );

        // Run 4: Incremental Delta #3 (6 hours ago)
        $run4 = BackupRun::updateOrCreate(
            ['workspace_id' => $workspace->id, 'parent_run_id' => $run3->id, 'backup_type' => 'incremental'],
            [
                'website_id' => $site1->id,
                'backup_policy_id' => $policy1->id,
                'backup_chain_id' => $chain1->id,
                'trigger_type' => 'scheduled',
                'trigger_tag' => 'Delta-Inc-3',
                'scope' => BackupScope::FullStack,
                'status' => BackupRunStatus::Completed,
                'source_files_count' => 14930,
                'changed_files_count' => 98,
                'raw_source_size_bytes' => 505 * 1024 * 1024,
                'archive_size_bytes' => 16 * 1024 * 1024, // 16 MB
                'is_anomaly' => false,
                'attempt_count' => 1,
                'started_at' => now()->subHours(6),
                'completed_at' => now()->subHours(6)->addSeconds(11),
                'duration_seconds' => 11,
                'retention_expires_at' => now()->addDays(14),
            ]
        );

        BackupArtifact::updateOrCreate(
            ['workspace_id' => $workspace->id, 'backup_run_id' => $run4->id],
            [
                'storage_connection_id' => $gdrive->id,
                'artifact_type' => 'combined_zip',
                'remote_path' => 'sitevaulta-backups/shop-fashionbd.com/run_'.$run4->id.'_inc3.enc.zip',
                'file_name' => 'shop-fashionbd_2026-10-29_delta_inc3.enc.zip',
                'size_bytes' => 16 * 1024 * 1024,
                'sha256_checksum' => '7f2d8914cda9021c83f982a1740b2e88a31e84712fc6572a91b48e30198f8216',
                'compression_algo' => 'zip/deflate',
                'is_encrypted' => true,
                'verification_status' => 'verified',
                'verified_at' => now()->subHours(6)->addSeconds(12),
            ]
        );

        // Chain B: TravelMatrix API DB Backups
        $chain2 = BackupChain::updateOrCreate(
            ['workspace_id' => $workspace->id, 'website_id' => $site2->id, 'backup_policy_id' => $policy2->id],
            [
                'incremental_count' => 0,
                'total_chain_size_bytes' => 95 * 1024 * 1024,
                'status' => 'active',
                'expires_at' => now()->addDays(7),
            ]
        );

        // Run 5: TravelMatrix PostgreSQL Snapshot
        $run5 = BackupRun::updateOrCreate(
            ['workspace_id' => $workspace->id, 'website_id' => $site2->id, 'backup_policy_id' => $policy2->id, 'backup_type' => 'full'],
            [
                'backup_chain_id' => $chain2->id,
                'trigger_type' => 'scheduled',
                'trigger_tag' => '6Hourly-DB-Dump',
                'scope' => BackupScope::DatabaseOnly,
                'status' => BackupRunStatus::Completed,
                'source_files_count' => 0,
                'changed_files_count' => 0,
                'raw_source_size_bytes' => 160 * 1024 * 1024,
                'archive_size_bytes' => 45 * 1024 * 1024, // 45 MB compressed
                'is_anomaly' => false,
                'attempt_count' => 1,
                'started_at' => now()->subHours(2),
                'completed_at' => now()->subHours(2)->addSeconds(18),
                'duration_seconds' => 18,
                'retention_expires_at' => now()->addDays(7),
            ]
        );

        BackupArtifact::updateOrCreate(
            ['workspace_id' => $workspace->id, 'backup_run_id' => $run5->id],
            [
                'storage_connection_id' => $s3->id,
                'artifact_type' => 'db_dump_sql',
                'remote_path' => 'daily-archives/api.travelmatrix.io/pg_dump_'.$run5->id.'.sql.gz.enc',
                'file_name' => 'travelmatrix_pg_2026-10-29_snapshot.sql.gz.enc',
                'size_bytes' => 45 * 1024 * 1024,
                'sha256_checksum' => '3b89f82163b782914cda9021c83f982a1740b2e88a31e84712fc6572a91b48e3',
                'compression_algo' => 'gzip',
                'is_encrypted' => true,
                'verification_status' => 'verified',
                'verified_at' => now()->subHours(2)->addSeconds(19),
            ]
        );

        // Run 6: CI/CD Pre-Deploy Webhook Trigger (FashionBD release v2.4.1)
        $run6 = BackupRun::updateOrCreate(
            ['workspace_id' => $workspace->id, 'trigger_tag' => 'PreDeploy-v2.4.1'],
            [
                'website_id' => $site1->id,
                'backup_policy_id' => $policy1->id,
                'trigger_type' => 'webhook',
                'backup_type' => 'full',
                'scope' => BackupScope::DatabaseOnly,
                'status' => BackupRunStatus::Completed,
                'source_files_count' => 0,
                'changed_files_count' => 0,
                'raw_source_size_bytes' => 78 * 1024 * 1024,
                'archive_size_bytes' => 22 * 1024 * 1024,
                'is_anomaly' => false,
                'attempt_count' => 1,
                'started_at' => now()->subMinutes(55),
                'completed_at' => now()->subMinutes(55)->addSeconds(15),
                'duration_seconds' => 15,
                'retention_expires_at' => now()->addDays(14),
            ]
        );

        BackupArtifact::updateOrCreate(
            ['workspace_id' => $workspace->id, 'backup_run_id' => $run6->id],
            [
                'storage_connection_id' => $gdrive->id,
                'artifact_type' => 'db_dump_sql',
                'remote_path' => 'sitevaulta-backups/shop-fashionbd.com/predeploy_'.$run6->id.'.sql.gz.enc',
                'file_name' => 'shop-fashionbd_predeploy_v2.4.1.sql.gz.enc',
                'size_bytes' => 22 * 1024 * 1024,
                'sha256_checksum' => '9a02d84712fc6572a91b48e30198f82163b782914cda9021c83f982a1740b2e8',
                'compression_algo' => 'gzip',
                'is_encrypted' => true,
                'verification_status' => 'verified',
                'verified_at' => now()->subMinutes(54),
            ]
        );

        $this->seedUptimeMonitors($workspace, $site1, $site2, $site3);
    }

    /**
     * Seeds UptimeRobot-style monitors with 24 hours of 5-minute check history,
     * one resolved incident and one ongoing DNS incident (with activity log).
     */
    private function seedUptimeMonitors(Workspace $workspace, Website $shop, Website $api, Website $crm): void
    {
        $now = now()->utc()->startOfMinute();

        $definitions = [
            ['name' => 'shop-fashionbd.com', 'website_id' => $shop->id, 'type' => MonitorType::Http, 'url' => 'https://shop-fashionbd.com/', 'status' => MonitorStatus::Up, 'base_ms' => 420],
            ['name' => 'FashionBD checkout keyword', 'website_id' => null, 'type' => MonitorType::Keyword, 'url' => 'https://checkout.shop-fashionbd.com/', 'keyword' => 'Place order', 'status' => MonitorStatus::Up, 'base_ms' => 610],
            ['name' => 'api.travelmatrix.io', 'website_id' => $api->id, 'type' => MonitorType::Http, 'url' => 'https://api.travelmatrix.io/', 'status' => MonitorStatus::Down, 'base_ms' => 180],
            ['name' => 'crm.fintechcloud.net', 'website_id' => $crm->id, 'type' => MonitorType::Http, 'url' => 'https://crm.fintechcloud.net/', 'status' => MonitorStatus::Up, 'base_ms' => 260],
            ['name' => 'TravelMatrix PostgreSQL', 'website_id' => null, 'type' => MonitorType::Port, 'url' => null, 'host' => 'db.travelmatrix.io', 'port' => 5432, 'status' => MonitorStatus::Paused, 'base_ms' => 35],
            ['name' => 'shop-fashionbd.com SSL certificate', 'website_id' => null, 'parent_website_id' => $shop->id, 'type' => MonitorType::Ssl, 'url' => null, 'host' => 'shop-fashionbd.com', 'status' => MonitorStatus::Up, 'base_ms' => 140],
            ['name' => 'travelmatrix.io MX records', 'website_id' => null, 'type' => MonitorType::Dns, 'url' => null, 'host' => 'travelmatrix.io', 'dns_record_type' => 'MX', 'status' => MonitorStatus::Up, 'base_ms' => 30],
        ];

        foreach ($definitions as $definition) {
            $isDown = $definition['status'] === MonitorStatus::Down;

            // Website-linked monitors are auto-created with the website, so match those by website_id.
            $monitor = Monitor::updateOrCreate(
                $definition['website_id'] !== null
                    ? ['workspace_id' => $workspace->id, 'website_id' => $definition['website_id']]
                    : ['workspace_id' => $workspace->id, 'name' => $definition['name']],
                [
                    'name' => $definition['name'],
                    'website_id' => $definition['website_id'],
                    // Same-host checks (e.g. SSL for the shop) are extra checks of the shop's main monitor.
                    'parent_id' => isset($definition['parent_website_id'])
                        ? Monitor::where('workspace_id', $workspace->id)->whereNull('parent_id')->where('website_id', $definition['parent_website_id'])->value('id')
                        : null,
                    'type' => $definition['type'],
                    'url' => $definition['url'],
                    'host' => $definition['host'] ?? null,
                    'port' => $definition['port'] ?? null,
                    'keyword' => $definition['keyword'] ?? null,
                    'dns_record_type' => $definition['dns_record_type'] ?? null,
                    'interval_seconds' => 300,
                    'status' => $definition['status'],
                    'consecutive_failures' => $isDown ? 8 : 0,
                    'last_response_time_ms' => $isDown ? null : $definition['base_ms'],
                    'last_http_status_code' => $isDown ? null : ($definition['type'] === MonitorType::Port ? null : 200),
                    'last_error' => $isDown ? 'DNS Resolving problem' : null,
                    'last_checked_at' => $now->copy()->subMinutes(2),
                    'last_status_changed_at' => $isDown ? $now->copy()->subMinutes(40) : $now->copy()->subHours(26),
                ]
            );

            $monitor->checks()->delete();
            $monitor->incidents()->delete();

            $checks = [];

            for ($minutesAgo = 24 * 60; $minutesAgo >= 5; $minutesAgo -= 5) {
                $checkedAt = $now->copy()->subMinutes($minutesAgo);
                $failed = ($isDown && $minutesAgo <= 40)
                    || ($definition['name'] === 'shop-fashionbd.com' && $minutesAgo >= 180 && $minutesAgo < 195);

                $checks[] = [
                    'monitor_id' => $monitor->id,
                    'status' => $failed ? MonitorStatus::Down->value : MonitorStatus::Up->value,
                    'http_status_code' => $failed || $definition['type'] === MonitorType::Port ? null : 200,
                    'response_time_ms' => $failed ? null : max(5, $definition['base_ms'] + random_int(-60, 140)),
                    'region' => 'primary',
                    'error_message' => $failed ? ($isDown ? 'DNS Resolving problem' : 'HTTP 502 Bad Gateway') : null,
                    'checked_at' => $checkedAt,
                ];
            }

            foreach (array_chunk($checks, 100) as $chunk) {
                MonitorCheck::insert($chunk);
            }
        }

        $shopMonitor = Monitor::where('workspace_id', $workspace->id)->where('name', 'shop-fashionbd.com')->first();
        $resolved = $shopMonitor->incidents()->create([
            'workspace_id' => $workspace->id,
            'status' => MonitorIncidentStatus::Resolved,
            'root_cause_type' => 'http',
            'root_cause' => 'HTTP 502 Bad Gateway',
            'request_snapshot' => ['method' => 'GET', 'url' => 'https://shop-fashionbd.com/', 'headers' => ['User-Agent' => 'SiteVaulta-Monitor/1.0']],
            'response_headers' => ['server' => ['nginx'], 'content-type' => ['text/html']],
            'response_body' => '<html><head><title>502 Bad Gateway</title></head><body><center><h1>502 Bad Gateway</h1></center></body></html>',
            'started_at' => $now->copy()->subMinutes(195),
            'resolved_at' => $now->copy()->subMinutes(180),
            'duration_seconds' => 15 * 60,
        ]);
        $resolved->events()->createMany([
            ['type' => 'detected', 'message' => 'HTTP 502 Bad Gateway detected', 'probe_location' => MonitorCheckRunner::PROBE_LOCATION, 'occurred_at' => $now->copy()->subMinutes(195)],
            ['type' => 'confirmed', 'message' => 'HTTP 502 Bad Gateway confirmed by re-check', 'probe_location' => MonitorCheckRunner::PROBE_LOCATION, 'occurred_at' => $now->copy()->subMinutes(195)],
            ['type' => 'alert_sent', 'message' => 'Down alert sent to workspace members and alert channels', 'delivery_status' => 'success', 'occurred_at' => $now->copy()->subMinutes(195)],
            ['type' => 'resolved', 'message' => 'Monitor is back up (438 ms)', 'probe_location' => MonitorCheckRunner::PROBE_LOCATION, 'occurred_at' => $now->copy()->subMinutes(180)],
            ['type' => 'alert_sent', 'message' => 'Recovery alert sent to workspace members and alert channels', 'delivery_status' => 'success', 'occurred_at' => $now->copy()->subMinutes(180)],
        ]);

        Monitor::updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Nightly backup cron'],
            [
                'type' => MonitorType::Heartbeat,
                'interval_seconds' => 86400,
                'heartbeat_grace_seconds' => 3600,
                'status' => MonitorStatus::Up,
                'last_heartbeat_at' => $now->copy()->subHours(5),
                'last_checked_at' => $now->copy()->subMinute(),
                'last_status_changed_at' => $now->copy()->subDays(3),
            ]
        );

        $crmMonitor = Monitor::where('workspace_id', $workspace->id)->where('website_id', $crm->id)->first();
        $maintenance = MaintenanceWindow::updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Weekly CRM server updates'],
            [
                'schedule_type' => MaintenanceScheduleType::Weekly,
                'start_time' => '02:00',
                'days_of_week' => [0],
                'duration_minutes' => 60,
                'is_active' => true,
            ]
        );
        $maintenance->monitors()->sync([$crmMonitor->id]);

        $statusPage = StatusPage::updateOrCreate(
            ['slug' => "{$workspace->slug}-status"],
            [
                'workspace_id' => $workspace->id,
                'name' => "{$workspace->name} — Service Status",
                'description' => 'Live availability of our client websites and APIs.',
                'is_published' => true,
            ]
        );
        $statusPage->monitors()->sync(
            Monitor::where('workspace_id', $workspace->id)->where('status', '!=', MonitorStatus::Paused)->pluck('id')
        );

        $apiMonitor = Monitor::where('workspace_id', $workspace->id)->where('name', 'api.travelmatrix.io')->first();
        $ongoing = $apiMonitor->incidents()->create([
            'workspace_id' => $workspace->id,
            'status' => MonitorIncidentStatus::Ongoing,
            'root_cause_type' => 'dns',
            'root_cause' => 'DNS Resolving problem',
            'request_snapshot' => ['method' => 'GET', 'url' => 'https://api.travelmatrix.io/', 'headers' => ['User-Agent' => 'SiteVaulta-Monitor/1.0']],
            'response_body' => 'cURL error 6: Could not resolve host: api.travelmatrix.io',
            'started_at' => $now->copy()->subMinutes(40),
        ]);
        $ongoing->events()->createMany([
            ['type' => 'detected', 'message' => 'DNS Resolving problem detected', 'probe_location' => MonitorCheckRunner::PROBE_LOCATION, 'occurred_at' => $now->copy()->subMinutes(40)],
            ['type' => 'confirmed', 'message' => 'DNS Resolving problem confirmed by re-check', 'probe_location' => MonitorCheckRunner::PROBE_LOCATION, 'occurred_at' => $now->copy()->subMinutes(40)],
            ['type' => 'alert_sent', 'message' => 'Down alert sent to workspace members and alert channels', 'delivery_status' => 'success', 'occurred_at' => $now->copy()->subMinutes(40)],
        ]);
    }
}
