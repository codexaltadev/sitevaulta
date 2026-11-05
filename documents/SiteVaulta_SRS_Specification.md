# SiteVaulta — Software Requirements Specification (SRS)
## Complete Engineering & System Design Blueprint
**Document Version:** 2.1 (Finalized Dual-Panel: `/admin` with Filament Shield + `/app` with Workspace Tenancy + 100% Dynamic Settings)  
**Parent Document:** `SiteVaulta_BRD_Complete_Specification.md` (v3.1)  
**Target Stack:** PHP 8.3+, Laravel 13, Filament 5 (Dual-Panel), Filament Shield (`spatie/laravel-permission`), MySQL 8.0+, Redis 7+, Laravel Horizon  

---

## 1. Introduction & Dual-Panel Architecture Overview

এই **Software Requirements Specification (SRS)** ডকুমেন্টটি **SiteVaulta** প্ল্যাটফর্মের সম্পূর্ণ কারিগরি ডিজাইন নির্ধারণ করে। প্রজেক্টের প্রধান আর্কিটেকচারাল ভিত্তিগুলো হলো:

1. **Filament 5 Dual-Panel Architecture (`/admin` + `/app`):**
   - **Panel 1 — Super Admin & Staff Panel (`/admin`):**
     - এখানে কোনো Tenant Lock (`->tenant()`) থাকবে **না**।
     - এটি **Filament Shield (`bezhansalleh/filament-shield` + `spatie/laravel-permission`)** দ্বারা পরিচালিত হবে।
     - `super_admin` এবং আপনার তৈরি করা যেকোনো কাস্টম স্টাফ রোল (`support_admin`, `billing_manager`, `auditor` ইত্যাদি) ডাইনামিক পারমিশন অনুযায়ী পুরো প্রজেক্টের **Global System Settings (`/admin/settings`)**, সব Plans, সব Workspaces, সব Users, সব Payments এবং সব Backup Runs নিয়ন্ত্রণ করতে পারবে।
   - **Panel 2 — Customer Workspace Panel (`/app`):**
     - এখানে **Filament 5 Native Multi-Tenancy (`->tenant(Workspace::class, slugAttribute: 'slug')`)** চালু থাকবে।
     - কাস্টমার ও এজেন্সিরা তাদের নিজস্ব Workspace (`/app/{workspace}`)-এর ভেতরে কাজ করবে।
     - Filament স্বয়ংক্রিয়ভাবে প্রতিটি কুয়েরি ওই Workspace-এর সাথে (`workspace_id`) স্কোপ করে রাখবে—ফলে এক কাস্টমার কখনোই অন্য কাস্টমারের কোনো ডাটা দেখতে বা স্পর্শ করতে পারবে না।
2. **100% Dynamic Settings Architecture (Zero Hardcoded Values):**
   - প্রজেক্টের কোনো কনফিগারেশন, লিমিট, ফোরকাস্ট রেশিও, টাইমআউট, পেমেন্ট কী, SMTP, বা ইমেইল টেমপ্লেট কোডে স্ট্যাটিক থাকবে না।
   - গ্লোবাল সেটিংস `system_settings` ও `email_templates` টেবিলে থাকবে (যা `/admin/settings` থেকে এডিট করা যাবে), এবং কাস্টমারের ওয়ার্কস্পেস সেটিংস `workspace_settings` টেবিলে থাকবে (যা `/app/{workspace}/settings` থেকে এডিট করা যাবে)।

---

## 2. Complete Database Schema Specification (MySQL 8.0+)

### 2.1 Users, Workspaces (Tenants), Team Members & Filament Shield RBAC Tables

#### Table 1: `users`
| Column | Type | Attributes / Constraints | Description |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK, Auto Increment | Primary Key |
| `uuid` | `CHAR(36)` | UNIQUE, INDEX | Public UUID |
| `name` | `VARCHAR(120)` | NOT NULL | Full name |
| `email` | `VARCHAR(191)` | UNIQUE, NOT NULL | Login email |
| `email_verified_at` | `TIMESTAMP` | NULLABLE | Verification timestamp |
| `password` | `VARCHAR(255)` | NOT NULL | Hashed password |
| `can_access_admin_panel`| `BOOLEAN` | DEFAULT `false` | True for Super Admin & Staff members (granular permissions handled by Filament Shield) |
| `two_factor_secret` | `TEXT` | NULLABLE, Encrypted | TOTP 2FA secret |
| `two_factor_confirmed_at`| `TIMESTAMP` | NULLABLE | 2FA activation timestamp |
| `is_suspended` | `BOOLEAN` | DEFAULT `false` | Account active/suspended toggle |
| `last_login_at` | `TIMESTAMP` | NULLABLE | Last login timestamp |
| `timestamps` | `TIMESTAMP` | `created_at`, `updated_at` | — |

#### Table 2: `workspaces` (Customer Tenants in `/app` Panel)
| Column | Type | Attributes / Constraints | Description |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK, Auto Increment | Primary Key |
| `uuid` | `CHAR(36)` | UNIQUE, INDEX | Public tenant UUID |
| `name` | `VARCHAR(120)` | NOT NULL | Workspace / Agency name |
| `slug` | `VARCHAR(140)` | UNIQUE, NOT NULL | URL slug in `/app/{slug}` |
| `owner_id` | `BIGINT UNSIGNED` | FK -> `users.id` | Primary billing owner |
| `is_suspended` | `BOOLEAN` | DEFAULT `false` | Admin suspension flag |
| `timestamps` | `TIMESTAMP` | `created_at`, `updated_at` | — |

#### Table 3: `workspace_user` (Customer Team Members inside a Workspace)
| Column | Type | Attributes / Constraints | Description |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK | Primary Key |
| `workspace_id` | `BIGINT UNSIGNED` | FK -> `workspaces.id` ON DELETE CASCADE | Tenant ID |
| `user_id` | `BIGINT UNSIGNED` | FK -> `users.id` ON DELETE CASCADE | Member user ID |
| `role` | `ENUM('owner','admin','operator','viewer')` | DEFAULT `'operator'` | Role inside this workspace |
| `permissions` | `JSON` | NULLABLE | Optional custom per-member permissions inside workspace |
| `timestamps` | `TIMESTAMP` | UNIQUE(`workspace_id`, `user_id`) | — |

#### Table 4–8: `Filament Shield` Admin RBAC Tables (`spatie/laravel-permission`)
* `roles` (`id`, `name`, `guard_name`, `description`, `timestamps`) — যেমন: `super_admin`, `support_admin`, `billing_manager`, `system_auditor` এবং অ্যাডমিন প্যানেল থেকে তৈরি যেকোনো নতুন রোল।
* `permissions` (`id`, `name`, `guard_name`, `timestamps`)
* `model_has_roles`, `model_has_permissions`, `role_has_permissions`

---

### 2.2 100% Dynamic Settings & CMS Tables (No Static Values)

#### Table 9: `system_settings` (Admin-Controlled Global Dynamic Configuration in `/admin/settings`)
| Column | Type | Attributes / Constraints | Description |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK | Primary Key |
| `group` | `VARCHAR(60)` | INDEX, NOT NULL | `general`, `trial_billing`, `backup_engine`, `forecast`, `security`, `payment_sslcommerz`, `payment_stripe`, `payment_paypal`, `smtp`, `oauth_google`, `landing_cms` |
| `key` | `VARCHAR(100)` | NOT NULL | E.g., `default_trial_days`, `compression_ratio`, `anomaly_drop_percent` |
| `value` | `LONGTEXT` | NULLABLE | Stored value (encrypted if `is_encrypted = true`) |
| `type` | `ENUM('string','integer','float','boolean','json','text','file')` | DEFAULT `'string'` | Data type for casting |
| `is_encrypted` | `BOOLEAN` | DEFAULT `false` | True for API keys, SMTP passwords, Gateway secrets |
| `is_public` | `BOOLEAN` | DEFAULT `false` | True if exposed to landing page |
| `label` | `VARCHAR(150)` | NOT NULL | Label in Admin Settings UI |
| `helper_text` | `VARCHAR(255)` | NULLABLE | Description in Admin UI |
| `updated_by` | `BIGINT UNSIGNED` | FK -> `users.id`, NULLABLE | Last admin who updated |
| `timestamps` | `TIMESTAMP` | UNIQUE(`group`, `key`) | — |

#### Table 10: `workspace_settings` (Customer-Controlled Dynamic Preferences in `/app/{workspace}/settings`)
| Column | Type | Attributes / Constraints | Description |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK | Primary Key |
| `workspace_id` | `BIGINT UNSIGNED` | FK -> `workspaces.id` ON DELETE CASCADE, INDEX | Tenant ID |
| `group` | `VARCHAR(60)` | NOT NULL | `regional`, `backup_defaults`, `notifications`, `whitelabel_branding`, `security` |
| `key` | `VARCHAR(100)` | NOT NULL | Setting key (`timezone`, `currency`, `default_exclude_paths`, `default_retention_days`, `agency_logo`, `brand_color`, etc.) |
| `value` | `LONGTEXT` | NULLABLE | Dynamic value |
| `type` | `ENUM('string','integer','float','boolean','json')` | DEFAULT `'string'` | Value type |
| `timestamps` | `TIMESTAMP` | UNIQUE(`workspace_id`, `group`, `key`) | — |

#### Table 11: `email_templates` (Dynamic Notification & Email Templates in `/admin/email-templates`)
| Column | Type | Attributes / Constraints | Description |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK | Primary Key |
| `code` | `VARCHAR(80)` | UNIQUE, NOT NULL | `welcome_trial`, `trial_expiring`, `backup_failed`, `anomaly_detected`, `invoice_paid` |
| `name` | `VARCHAR(120)` | NOT NULL | Admin display name |
| `subject` | `VARCHAR(255)` | NOT NULL | Dynamic subject with variables |
| `body_html` | `LONGTEXT` | NOT NULL | Rich text / Markdown template body |
| `available_placeholders`| `JSON` | NOT NULL | Allowed variables shown in Admin UI |
| `is_active` | `BOOLEAN` | DEFAULT `true` | Enable/disable template |
| `timestamps` | `TIMESTAMP` | `created_at`, `updated_at` | — |

---

### 2.3 Dynamic Plans, Subscriptions & Multi-Gateway Billing Tables

* **Table 12: `plans`** — `id`, `code`, `name`, `description`, `sort_order`, `is_trial`, `trial_days`, `price_bdt_monthly`, `price_bdt_yearly`, `price_usd_monthly`, `price_usd_yearly`, `max_websites`, `max_policies`, `max_storage_connections`, `max_team_members`, `min_interval_minutes`, `max_retention_days`, `features` (`JSON`), `is_featured`, `is_active`, `timestamps`।
* **Table 13: `subscriptions`** — `id`, `workspace_id` (FK -> `workspaces.id`, INDEX), `plan_id` (FK -> `plans.id`), `billing_cycle`, `status`, `gateway`, `gateway_subscription_id`, `trial_starts_at`, `trial_ends_at`, `current_period_starts_at`, `current_period_ends_at`, `grace_ends_at`, `canceled_at`, `custom_quota_overrides` (`JSON`), `timestamps`।
* **Table 14: `payments`** — `id`, `workspace_id`, `subscription_id`, `invoice_number`, `gateway`, `gateway_transaction_id`, `idempotency_key`, `currency`, `amount`, `discount_amount`, `tax_amount`, `status`, `gateway_payload` (`JSON`), `paid_at`, `timestamps`।
* **Table 15: `coupons`** — `id`, `code`, `discount_type`, `discount_value`, `max_redemptions`, `redeemed_count`, `expires_at`, `is_active`, `timestamps`।

---

### 2.4 Websites, Verification, Server Connections, Storage & Backup Tables (All Tenant-Scoped by `workspace_id`)

প্রতিটি কাস্টমার-রিসোর্স টেবিলে `workspace_id` থাকবে যাতে `/app` প্যানেলে Filament-এর টেন্যান্ট সিস্টেম স্বয়ংক্রিয়ভাবে ডাটা আইসোলেট করে এবং `/admin` প্যানেলে Super Admin সব ওয়ার্কস্পেসের ডাটা দেখতে পারে:

* **Table 16: `websites`** — `id`, `uuid`, `workspace_id` (FK -> `workspaces.id`, INDEX), `name`, `url`, `domain`, `environment`, `verification_status`, `verified_at`, `verification_method`, `webhook_secret_hash`, `known_files_size_bytes`, `known_db_size_bytes`, `status`, `timestamps`।
* **Table 17: `website_verifications`** — `id`, `workspace_id`, `website_id`, `token`, `dns_host`, `file_path`, `meta_tag_name`, `last_checked_at`, `last_error`, `expires_at`, `timestamps`।
* **Table 18: `server_connections`** — `id`, `workspace_id` (INDEX), `website_id`, `name`, `file_protocol` (`none`, `ssh_sftp`, `ftp_ftps`, `cpanel_api`, `vaultix_agent`), `file_credentials` (`encrypted:array`), `db_driver` (`none`, `mysql`, `mariadb`, `pgsql`, `cpanel_mysql`), `db_use_ssh_tunnel` (`boolean`), `db_credentials` (`encrypted:array`), `capabilities` (`JSON`), `health_status`, `last_tested_at`, `last_error`, `timestamps`।
* **Table 19: `storage_connections`** — `id`, `workspace_id` (INDEX), `name`, `driver` (`google_drive`, `s3`, `cloudflare_r2`, `s3_compatible`, `sftp`), `credentials` (`encrypted:array`), `root_prefix`, `supports_presigned_url`, `quota_total_bytes`, `quota_used_bytes`, `health_status`, `last_verified_at`, `last_error`, `timestamps`।
* **Table 20: `backup_templates`** — `id`, `workspace_id` (NULLABLE if Global Admin Default Template, or `workspace_id` for Customer Template), `is_global_default` (`boolean`), `name`, `scope`, `strategy`, `full_reset_every_n`, `schedule_frequency`, `interval_minutes`, `cron_expression`, `retention_days`, `exclude_paths` (`JSON`), `enable_encryption`, `notification_events` (`JSON`), `timestamps`।
* **Table 21: `backup_policies`** — `id`, `uuid`, `workspace_id` (INDEX), `website_id`, `server_connection_id`, `storage_connection_id`, `template_id`, `name`, `scope`, `strategy`, `full_reset_every_n`, `schedule_frequency`, `interval_minutes`, `cron_expression`, `retention_days`, `exclude_paths` (`JSON`), `enable_encryption`, `encryption_passphrase` (`encrypted`), `download_mode`, `forecast_snapshot` (`JSON`), `status`, `last_run_at`, `next_run_at`, `timestamps`।
* **Table 22: `backup_chains`** — `id`, `uuid`, `workspace_id` (INDEX), `website_id`, `backup_policy_id`, `base_full_run_id`, `incremental_count`, `total_chain_size_bytes`, `status`, `expires_at`, `timestamps`।
* **Table 23: `backup_runs`** — `id`, `uuid`, `workspace_id` (INDEX), `website_id`, `backup_policy_id`, `backup_chain_id`, `parent_run_id`, `trigger_type`, `backup_type`, `scope`, `status`, `source_files_count`, `changed_files_count`, `raw_source_size_bytes`, `archive_size_bytes`, `is_anomaly`, `anomaly_reason`, `attempt_count`, `started_at`, `completed_at`, `duration_seconds`, `retention_expires_at`, `is_pruned`, `error_code`, `error_message`, `timestamps`।
* **Table 24: `backup_artifacts`** — `id`, `uuid`, `workspace_id` (INDEX), `backup_run_id`, `storage_connection_id`, `artifact_type`, `remote_path`, `file_name`, `size_bytes`, `sha256_checksum`, `compression_algo`, `is_encrypted`, `encryption_iv`, `file_index_manifest`, `verification_status`, `verified_at`, `timestamps`।
* **Table 25–28:** `download_tokens`, `notification_channels`, `client_reports`, `audit_logs`।

---

## 3. Dual-Panel Configuration (`AdminPanelProvider` & `AppPanelProvider`)

### 3.1 `AdminPanelProvider` (`/admin` — Super Admin & Filament Shield, No Tenancy)
```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->login()
        ->plugins([
            FilamentShieldPlugin::make(), // Full Role & Permission management for Super Admin & Staff
        ])
        ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
        ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
        ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets');
}
```

### 3.2 `AppPanelProvider` (`/app` — Customer Workspace Tenancy)
```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->default()
        ->id('app')
        ->path('app')
        ->login()
        ->registration()
        ->tenant(Workspace::class, slugAttribute: 'slug')
        ->tenantRegistration(RegisterWorkspace::class)
        ->tenantProfile(EditWorkspaceSettings::class)
        ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
        ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
        ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets');
}
```
* **এর ফলে কী হচ্ছে?**
  1. আপনি যখন `/admin`-এ ঢুকবেন, সেখানে কোনো Tenant বা Workspace বাধা নেই। আপনি **Super Admin** হিসেবে পুরো প্রজেক্টের সব সেটিংস (`/admin/settings`), সব প্ল্যান, সব ওয়ার্কস্পেস এবং সব ব্যাকআপ জব কন্ট্রোল করবেন এবং **Filament Shield** দিয়ে স্টাফ রোল ম্যানেজ করবেন।
  2. যখন একজন গ্রাহক `/app`-এ ঢুকবে, সে তার নিজস্ব **Workspace (`Tenant`)**-এর ভেতরে থাকবে এবং শুধুমাত্র নিজের ওয়েবসাইট, সার্ভার, স্টোরেজ ও সেটিংস দেখতে পাবে।
