# sitevaulta

**SiteVaulta** — Multi-Tenant Website & Database Automated Backup SaaS Platform (Laravel 13 + Filament 5 + Livewire 4).

---

## 🚀 Overview & Completed Architecture (Steps 1–18)

SiteVaulta is a zero-trust, multi-tenant cloud backup and disaster-recovery SaaS platform built for digital agencies, DevOps teams, and website owners. It supports automated and on-demand backups across VPS/cloud servers (`SSH/SFTP`) and shared hosting environments (`cPanel UAPI + 100% Read-Only Streaming Session Fallback`).

### 1. Multi-Tenant & Dual-Panel Architecture
- **Super Admin Panel (`/admin`)**:
  - SaaS KPI Command Center (MRR, ARR, Active Workspaces, Protected Websites, Global Backup Success Rate, Storage Throughput).
  - Global System Settings (`ManageSystemSettings`): General & Dynamic Branding (`logo_light_url`, `logo_dark_url`, `favicon_url`, `support_phone`, `footer_text`), Backup Engine & Security (`expired_metadata_retention_days`, `retry_backoff_seconds`, `ssrf_custom_blocked_ips`, `enforce_2fa`), Payment Gateways (Stripe, SSLCommerz, bKash, Manual Bank), Outbound SMTP with `"Send Test Email"`, and Landing Page & Legal CMS.
  - Plan & Entitlement Management, Subscription & Manual Payment Verification, Platform Audit Logs, and Queue Health Telemetry.
- **Customer Workspace Panel (`/app/{workspace}`)**:
  - Multi-tenant workspace isolation with RBAC roles (`Owner`, `Admin`, `Operator`, `Viewer`).
  - Workspace Settings (`EditWorkspaceSettings`): Timezone, Custom Date/Time Format (`date_format`), TOTP 2FA Toggle (`enable_2fa`), White-Label Agency PDF Logo (`agency_logo_url`), and Quiet Hours / Do-Not-Disturb Window (`quiet_hours_enabled`, `quiet_hours_start`, `quiet_hours_end`).

### 2. Zero-Trust Server Connectors & 100% Read-Only cPanel Engine
- **SSH / SFTP & Database Connectors (`ServerConnectorManager`)**:
  - Supports SSH Password & Private Key authentication (`phpseclib3`), MySQL/MariaDB, and PostgreSQL (direct or over SSH tunnel).
- **cPanel Shared Hosting Connector (`CPanelClient`)**:
  - Uses cPanel UAPI over HTTPS port `2083` (`Fileman/list_files`, `Mysql/list_databases`, `Fileman/get_file_content`).
  - **100% Read-Only Password Session Fallback**: When shared hosting blocks API tokens on binary streaming endpoints (`/getsqlbackup/{db}.sql.gz` and `/download`), `CPanelClient` establishes a single cached `cpsess` web session via `/login/?login_only=1` (or HTTP Basic Auth fallback) and streams the SQL dump and binary files directly—**never** creating, modifying, or deleting any file on the customer's server.
- **SSRF & Network Hardening (`SsrfValidator`)**:
  - Blocks loopback, link-local, RFC1918 private ranges, cloud metadata endpoints (`169.254.169.254`), and admin-defined custom CIDR/IP blocklists (`ssrf_custom_blocked_ips`).

### 3. Backup Engine, Packaging & Cloud Storage Adapters
- **Full & Incremental Chain Engine (`ExecuteBackupRunJob`)**:
  - Supports `full_stack`, `files_only`, and `database_only` scopes with `full_only` or `incremental_with_periodic_full` chain strategies.
  - Pre-Backup Storage & Transfer Forecaster (`StorageForecastService`).
- **AES-256-GCM Encryption & SHA-256 Integrity (`ArchivePackagerService`)**:
  - Packages extracted files, database SQL dumps, and `manifest.json` into compressed archives with optional military-grade AES-256-GCM encryption and SHA-256 checksum verification.
  - Guarantees ephemeral staging cleanup (`/tmp/sitevaulta/run-{uuid}`) in a `finally` block.
- **Multi-Cloud Storage Destinations (`StorageAdapterManager`)**:
  - SiteVaulta Managed Cloud Vault, Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, Custom S3-Compatible Object Storage, and Remote SFTP Storage.
- **Smart Retention & Safe Pruning (`PruneExpiredBackupsJob`)**:
  - Automatically prunes expired backup chains while protecting active incremental parent dependencies, and purges expired metadata records according to `expired_metadata_retention_days`.

### 4. Recovery, Verification, Alerts & White-Label Reporting
- **One-Time Token & Signed URL Downloads (`BackupArtifactDownloadController`)**:
  - Cryptographically generated single-use download tokens with expiry and audit logging.
- **Automated Backup Verification & Anomaly Detection (`RunVerificationDrillJob`)**:
  - Verifies archive integrity, SHA-256 checksums, and flags sudden size anomalies.
- **Multi-Channel Alert Dispatcher (`WorkspaceAlertDispatcher`)**:
  - Dispatches Email, Slack, Discord, Telegram, and Signed Webhook notifications while respecting workspace Quiet Hours.
- **White-Label Client PDF Reports (`ClientReportResource`)**:
  - Generates downloadable PDF executive backup reports branded with the agency's custom logo (`agency_logo_url`).

### 5. Animated Landing Page & Legal CMS
- **Interactive Marketing Landing Page (`/`)**:
  - Built with Tailwind CSS v4, custom dark/light theme switcher, 3D perspective dashboard preview, animated orbital security shield, live pipeline step animations, and dynamic CMS content.
- **Legal Pages (`/terms`, `/privacy`, `/refund-policy`)**:
  - Theme-aware legal pages powered directly by the Super Admin CMS (`landing_cms`).

---

## 🛠️ Tech Stack & Requirements

- **PHP**: 8.3+ / 8.4+
- **Framework**: Laravel 13
- **Admin & App UI**: Filament v5 + Livewire v4 + Tailwind CSS v4
- **Testing**: PHPUnit 12 (`php artisan test --compact`)
- **Code Formatting**: Laravel Pint (`vendor/bin/pint`)

## ⚡ Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
php artisan queue:work --tries=3 --timeout=7200
```
