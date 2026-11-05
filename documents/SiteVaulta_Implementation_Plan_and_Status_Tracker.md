# SiteVaulta — Step-by-Step Implementation Plan & Live Status Tracker
## Ordered Development Sequence, Composer Packages & Task Status Checklist
**SDLC Phase:** Phase 4 — Development Execution Plan & Status Tracking  
**Document Status:** `✅ COMPLETED (All 10 Development Steps Implemented)`  
**Stack:** Laravel 13 + Filament 5 (Dual-Panel: `/admin` & `/app`) + Filament Shield + 100% Dynamic Settings  
**Previous Document:** `SiteVaulta_UI_UX_and_Workflow_Specification.md`  
**Next Document:** `SiteVaulta_QA_Security_and_Deployment_Guide.md`  

---

## 1. Status Legend & Tracking Rule

এই ডকুমেন্টে পুরো প্রজেক্টের ডেভেলপমেন্ট কোন ধাপের পর কোন ধাপ হবে (**Step 1 থেকে Step 10**) তা ক্রমানুসারে সাজানো হয়েছে এবং প্রতিটি ধাপ সম্পন্ন করার পর `[x] ✅ COMPLETED` করা হয়েছে।

* `✅ COMPLETED` — সম্পূর্ণ তৈরি ও যাচাইকৃত
* `🔄 IN PROGRESS` — বর্তমানে কাজ চলছে
* `⏳ PENDING` — আগের ধাপ শেষ হওয়ার পর শুরু হবে

---

## 2. Master Step-by-Step Execution Summary Table

| Step Order | Milestone Name | Dependency (কার পরে কোনটা হবে) | Current Status |
|---:|---|---|---|
| **Pre-Step** | **SDLC Documentation (BRD, SRS, UI/UX, Implementation, QA)** | None (Starting Point) | `✅ COMPLETED` |
| **Step 1** | **Laravel 13 + Filament 5 Dual-Panel (`/admin` & `/app`) + Filament Shield Setup** | Pre-Step এর পর | `✅ COMPLETED` |
| **Step 2** | **Database Migrations, Enums & Models (incl. Workspaces & Dynamic Settings)** | Step 1 এর পর | `✅ COMPLETED` |
| **Step 3** | **Filament Shield Admin RBAC, `/app` Workspace Tenancy & Dynamic Settings Engine** | Step 2 এর পর | `✅ COMPLETED` |
| **Step 4** | **Website Onboarding, SSRF Guard & Domain Verification Engine** | Step 3 এর পর | `✅ COMPLETED` |
| **Step 5** | **Universal Server & Database Connectors (`SSH/SFTP`, `FTP`, `cPanel`, `DB`)** | Step 4 এর পর | `✅ COMPLETED` |
| **Step 6** | **Customer-Owned Storage Adapters (`Google Drive`, `S3`, `R2`, `SFTP`)** | Step 4 এর পর | `✅ COMPLETED` |
| **Step 7** | **Backup Templates, Policies & Dynamic Storage Forecast Calculator** | Step 5 ও Step 6 এর পর | `✅ COMPLETED` |
| **Step 8** | **Hybrid Backup Execution Pipeline, Encryption, Checksum & Retention** | Step 7 এর পর | `✅ COMPLETED` |
| **Step 9** | **Secure Download (`One-Time`/`Presigned`), Restore Guide, Alerts & Reports** | Step 8 এর পর | `✅ COMPLETED` |
| **Step 10** | **Multi-Gateway Billing (`SSLCOMMERZ`, `Stripe`, `PayPal`), Admin & Dynamic Landing** | Step 9 এর পর | `✅ COMPLETED` |
| **Step 12** | **Dark & Light Mode (Both Panels + Public Pages, Shared Theme Preference)** | Step 10 এর পর | `✅ COMPLETED` |
| **Step 13** | **Customer Dashboard Animated Report Widgets & Demo Seeder** | Step 12 এর পর | `✅ COMPLETED` |
| **Step 14** | **Subscription Expiry Date, Remaining Days & SaaS Extension Logic** | Step 13 এর পর | `✅ COMPLETED` |
| **Step 15** | **Stripe & PayPal Real Hosted Gateway Integration (Sandbox & Live)** | Step 14 এর পর | `✅ COMPLETED` |
| **Step 16** | **Asynchronous Non-Blocking Backup Queue & Live Percentage Streaming** | Step 15 এর পর | `✅ COMPLETED` |
| **Step 17** | **Fix Hidden Encrypted Credentials Hydration on Edit Forms** | Step 16 এর পর | `✅ COMPLETED` |
| **Step 18** | **Legal Pages (`/terms`, `/privacy`, `/refund-policy`), SMTP Test Email, 2FA, Quiet Hours, Branding & SSRF Blocklist** | Step 17 এর পর | `✅ COMPLETED` |

---

## 3. Detailed Ordered Task Checklist (With Status Checkboxes)

### Step 1: Laravel 13 + Filament 5 Dual-Panel (`admin` & `app`) + Filament Shield Setup (`✅ COMPLETED`)
* **কখন শুরু হবে:** সবার প্রথমে।
* **Tasks:**
  - [x] `1.1` Laravel 13 প্রজেক্ট সেটআপ এবং `.env` কনফিগারেশন।
  - [x] `1.2` Filament 5, `bezhansalleh/filament-shield` (`spatie/laravel-permission`) ইনস্টল করা।
  - [x] `1.3` দুটি আলাদা Filament Panel Provider তৈরি করা:
    - **`AdminPanelProvider` (`/admin`):** কোনো টেন্যান্ট লক নেই; `FilamentShieldPlugin` যুক্ত করা হয়েছে যাতে `super_admin` ও স্টাফ রোলগুলো প্রজেক্টের সব সেটিংস ও সব ইউজার কন্ট্রোল করতে পারে।
    - **`AppPanelProvider` (`/app`):** কাস্টমারদের জন্য `->tenant(Workspace::class)` চালু করা হয়েছে যাতে প্রতিটি কাস্টমার তার নিজস্ব Workspace-এ সম্পূর্ণ আলাদা থাকে।
  - [x] `1.4` সব প্রয়োজনীয় Composer প্যাকেজ (`phpseclib`, `aws-s3`, `google/apiclient`, `dompdf`, `stripe-php`) ইনস্টল করা।

---

### Step 2: Database Migrations, PHP Enums & Models (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 1 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `2.1` **PHP Backed Enums তৈরি (`app/Enums/`)** (১৬টি Enum তৈরি সম্পন্ন)।
  - [x] `2.2` **সবগুলো টেবিলের মাইগ্রেশন ফাইল তৈরি ও রান করা (`database/migrations/`):**
    - [x] `users`, `workspaces`, `workspace_user` এবং Spatie Permission/Shield টেবিলসমূহ (`roles`, `permissions`, ইত্যাদি)
    - [x] **100% Dynamic Settings Tables:** `system_settings` (Admin Global Settings), `workspace_settings` (Customer Workspace Settings), `email_templates`
    - [x] `plans`, `subscriptions`, `payments`, `coupons`
    - [x] `websites`, `website_verifications`, `server_connections`
    - [x] `storage_connections`
    - [x] `backup_templates`, `backup_policies`
    - [x] `backup_chains`, `backup_runs`, `backup_artifacts`
    - [x] `download_tokens`, `notification_channels`, `client_reports`, `audit_logs`
  - [x] `2.3` **সব Eloquent Model এবং Workspace Tenant রিলেশনশিপ তৈরি (`app/Models/`)** (২২টি Model তৈরি সম্পন্ন)।

---

### Step 3: Admin Shield RBAC, Workspace Tenancy & 100% Dynamic Settings Engine (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 2 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `3.1` `SystemSettingsService`, `WorkspaceSettingsService`, এবং `AppServiceProvider` তৈরি করা।
  - [x] `3.2` Admin Panel (`/admin`)-এ **8-Tab Global System Settings Page (`/admin/settings`)**, `EmailTemplateResource`, এবং `Filament Shield` রোল ম্যানেজার সেটআপ করা।
  - [x] `3.3` Customer Panel (`/app`)-এ **4-Tab Workspace Dynamic Settings Page (`/app/{workspace}/profile`)** এবং Workspace Team Member ম্যানেজমেন্ট (`ManageTeamMembers`) তৈরি করা।
  - [x] `3.4` ডিফল্ট রোল (`super_admin`, `support_admin`, `billing_manager`, `system_auditor`), ডিফল্ট `system_settings`, `email_templates`, এবং `plans` সিডার তৈরি করা।
  - [x] `3.5` ডাইনামিক ফ্রি ট্রায়াল (`SystemSettingsService::get('trial_billing', 'default_trial_days')` অনুযায়ী) ও `EntitlementService` তৈরি করা।

---

### Step 4: Website Onboarding, SSRF Protection & Domain Verification (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 3 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `4.1` `SsrfValidator` সার্ভিস তৈরি করা।
  - [x] `4.2` `DomainVerificationService` (`DNS TXT`, `HTML File`, `Meta Tag` ভেরিফায়ার) তৈরি করা।
  - [x] `4.3` Customer Panel (`/app`)-এ `WebsiteResource` এবং লাইভ ভেরিফিকেশন অ্যাকশন তৈরি করা।

---

### Step 5: Universal Server & Database Connectors (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 4 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `5.1` `ServerConnectorManager` (`SSH/SFTP`, `FTP/FTPS`, `cPanel API`, `Vaultix Agent`) তৈরি করা।
  - [x] `5.2` `MySQL`, `MariaDB`, `PostgreSQL` ডাটাবেস ডাম্পার ও SSH টানেল সাপোর্ট যুক্ত করা।
  - [x] `5.3` Filament-এ `ServerConnectionResource` এবং **`[Test Server & DB]`** লাইভ বাটন যুক্ত করা।

---

### Step 6: Customer-Owned Storage Adapters (`BYOS`) (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 4 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `6.1` `StorageAdapterManager` (`Google Drive`, `Amazon S3`, `Cloudflare R2`, `S3-Compatible`, `Remote SFTP VPS`) তৈরি করা।
  - [x] `6.2` Filament-এ `StorageConnectionResource` এবং **`[Test Write/Read/Delete]`** অ্যাকশন তৈরি করা।

---

### Step 7: Backup Templates, Policies & Dynamic Storage Forecast Engine (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 5 এবং Step 6 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `7.1` `StorageForecastService` তৈরি করা (ডাইনামিক কম্প্রেশন রেশিও ও ডেল্টা রেট ব্যবহার করে)।
  - [x] `7.2` Filament-এ `BackupTemplateResource` এবং `BackupPolicyResource` তৈরি করা (যার ডিফল্ট ফিল্ডগুলো ইউজারের `WorkspaceSettingsService` থেকে ডাইনামিকভাবে আসে এবং Live Storage Forecast কার্ড দেখায়)।

---

### Step 8: Hybrid Backup Execution Engine, Encryption, Checksum & Retention (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 7 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `8.1` `ArchivePackagerService` (`/tmp/sitevaulta` এফেমেরাল স্টেজিং + `AES-256-GCM` এনক্রিপশন + `SHA-256` চেকসাম) তৈরি করা।
  - [x] `8.2` `ExecuteBackupRunJob` তৈরি করা (ডাইনামিক টাইমআউট, রিট্রাই এবং ডাইনামিক অ্যানোমালি ডিটেকশন থ্রেশহোল্ড সহ)।
  - [x] `8.3` `DispatchScheduledBackupsCommand` এবং `PruneExpiredBackupsJob` (Chain-Safe Retention) তৈরি করা।

---

### Step 9: Secure Download, Dynamic Restore Guide, Webhooks & Alerts (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 8 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `9.1` `DownloadTokenService` এবং `DownloadController` (`Presigned URL` এবং অ্যাটমিক `One-Time Token` ডাউনলোড)।
  - [x] `9.2` `RestoreGuideGenerator` এবং Filament-এ `BackupRunResource` (Restore Guide মডালসহ) তৈরি করা।
  - [x] `9.3` `WorkspaceAlertDispatcher`, `NotificationChannelResource`, `PreDeployWebhookController`, এবং `ClientReportResource` তৈরি করা।

---

### Step 10: Multi-Gateway Billing (`SSLCOMMERZ`, `Stripe`, `PayPal`), Admin Panel & Dynamic Landing Page (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 9 সম্পন্ন হওয়ার পর।
* **Tasks:**
  - [x] `10.1` `MultiGatewayBillingService` এবং `PaymentWebhookController` (`SSLCOMMERZ`, `Stripe`, `PayPal`) ইমপ্লিমেন্ট করা।
  - [x] `10.2` Customer Panel (`/app`)-এ `ManageBillingSubscription` পেজ তৈরি করা।
  - [x] `10.3` Super Admin Panel (`/admin`)-এর সব রিসোর্স (`WorkspaceResource`, `UserResource`, `PlanResource`, `CouponResource`, `BackupRunAdminResource`, `PaymentResource`, `AuditLogResource`) এবং `AdminExecutiveStatsWidget` সম্পন্ন করা।
  - [x] `10.4` ১০০% ডাইনামিক CMS-চালিত Public Landing Page (`/`), লাইভ অ্যানিমেটেড ক্লাউড ব্যাকআপ পাইপলাইন সিমুলেশন, ইন্টারঅ্যাক্টিভ প্রি-ব্যাকআপ স্টোরেজ ফোরকাস্টার ক্যালকুলেটর, মাল্টি-কারেন্সি (BDT ৳ ও USD $) ও বিলিং সাইকেল (Monthly/Yearly) প্রাইসিং ইঞ্জিন, ডেভেলপার টার্মিনাল সিমুলেটর এবং `/docs` পেজ তৈরি ও আধুনিকায়ন সম্পন্ন করা।

---

### Step 12: Dark & Light Mode (Project-Wide Theme Support) (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 10 সম্পন্ন হওয়ার পর (UI Enhancement)।
* **বিস্তারিত ও ডেভেলপার ইনস্ট্রাকশন:** `SiteVaulta_UI_UX_and_Workflow_Specification.md` → **Section 1.1**
* **Tasks:**
  - [x] `12.1` `AdminPanelProvider` ও `AppPanelProvider`-এ `->darkMode(true)`, `->themeSwitcher(true)`, `->defaultThemeMode(ThemeMode::System)` চালু করা (ইউজার মেনু থেকে Light / Dark / System)।
  - [x] `12.2` শেয়ার্ড পার্শিয়াল `partials/theme-head.blade.php` (no-flash থিম বুটস্ট্র্যাপ, `localStorage` key `filament:theme:mode` — Filament-এর সাথে শেয়ার্ড) এবং `partials/theme-toggle.blade.php` তৈরি করা।
  - [x] `12.3` পাবলিক পেজ (`welcome`, `docs`, `billing/sandbox-checkout`) ও Google Drive OAuth পপআপ CSS ভ্যারিয়েবল (`:root` = Light, `html.dark` = Dark) দিয়ে থিম-অ্যাওয়্যার করা এবং টগল বাটন যুক্ত করা।
  - [x] `12.4` Filament-এর ভেতরের সব হার্ডকোডেড ডার্ক inline HTML কালার (Storage/Notification setup guide, Storage Forecaster, Billing table, Plan preview, Restore modal ইত্যাদি) `--sv-*` CSS ভ্যারিয়েবলে রূপান্তর করা; ভ্যারিয়েবলগুলো `partials/filament-theme-variables.blade.php` থেকে `PanelsRenderHook::HEAD_END` দিয়ে লোড হয়।
  - [x] `12.5` PDF (Invoice, Client Report) ইচ্ছাকৃতভাবে প্রিন্ট-ফ্রেন্ডলি Light রাখা।

---

### Step 13: Customer Dashboard Animated Report Widgets & Demo Seeder (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 12 সম্পন্ন হওয়ার পর।
* **উদ্দেশ্য:** লাইভ অ্যানিমেটেড মিশন কন্ট্রোল উইজেট, স্টোরেজ ডেল্টা সেভিংস মিটার, রিসেন্ট ব্যাকআপ স্ট্রিম এবং টেস্টের জন্য রিচ ডেমো ডাটা সিডার তৈরি করা। রিয়েল প্রোডাকশনে প্রতিটি ইউজার তার নিজস্ব ডাটায় এই ভিজ্যুয়াল রিপোর্ট দেখবে।
* **Tasks:**
  - [x] `13.1` `DemoWorkspaceSeeder` ও `php artisan sitevaulta:seed-demo {workspace?}` কমান্ড তৈরি করা (৩টি রিয়েলিস্টিক ওয়েবসাইট, ৩টি ক্লাউড স্টোরেজ, ইনক্রিমেন্টাল চেইন ও SHA-256 ভেরিফাইড রান সহ)।
  - [x] `13.2` `WorkspaceHealthPulseWidget` তৈরি করা (পালসিং শিল্ড মিশন কন্ট্রোল, লাইভ রানিং ব্যাকআপ স্টেজ প্রগ্রেস বার, এবং ফ্রেশ নতুন ওয়ার্কস্পেসের জন্য ৩-ধাপের অনবোর্ডিং গাইড)।
  - [x] `13.3` `StorageSavingsRadarWidget` তৈরি করা (ঐতিহ্যগত ফুল ব্যাকআপ বনাম ইনক্রিমেন্টাল ডেল্টা চেইনের ভিজ্যুয়াল কম্প্যারিজন বার, ক্লাউড স্টোরেজ সেভিংস % ক্যালকুলেটর ও BYOS কার্ড)।
  - [x] `13.4` `RecentBackupStreamWidget` তৈরি করা (রিসেন্ট ব্যাকআপ রানের লাইভ টেবিল, পালসিং স্ট্যাটাস, কম্প্রেসড সাইজ, SHA-256 হ্যাশ পিল ও ১-ক্লিক রিস্টোর গাইড লিঙ্ক)।
  - [x] `13.5` `AppDashboardWidgetsTest` ফিচার টেস্ট তৈরি ও ভেরিফাই করা (ডেমো ডাটা এবং নতুন ফ্রেশ ওয়ার্কস্পেস উভয় অবস্থাতেই ১০০% পাস)।

---

### Step 14: Subscription Expiry Date, Validity Indicators & SaaS Extension Engine (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 13 সম্পন্ন হওয়ার পর।
* **উদ্দেশ্য:** ইউজারের বর্তমান প্যাকেজের মেয়াদ কবে শেষ হবে তা সব স্ক্রিনে স্পষ্টভাবে দেখানো, এবং একই প্যাকেজ রিনিউ করলে যেন অবশিষ্ট দিন না হারিয়ে বর্তমান মেয়াদের শেষ থেকে সময় বাড়ানো (period extension) হয় এবং ইন্ডাস্ট্রির স্ট্যান্ডার্ড Renewal Window পলিসি বাস্তবায়নের মাধ্যমে কাস্টমারের অসাবধানতাবশত ডাবল চার্জ প্রতিরোধ করা।
* **Tasks:**
  - [x] `14.1` `Subscription` মডেলে মেয়াদ সংক্রান্ত হেল্পার মেথড তৈরি করা: `getExpiryDate(): ?Carbon`, `getRemainingDays(): int`, `isExpiringSoon(int $thresholdDays = 7): bool`, `canRenewSamePlan(int $windowDays = 7): bool`, এবং `getValidityDescription(): string`।
  - [x] `14.2` কাস্টমার ড্যাশবোর্ড ওভারভিউ কার্ড (`WorkspaceOverviewWidget`) এবং মিশন কন্ট্রোল উইজেট (`WorkspaceHealthPulseWidget`)-এ লাইভ সাবস্ক্রিপশন মেয়াদ, অবশিষ্ট দিন এবং ওয়ার্নিং ব্যাজ যুক্ত করা।
  - [x] `14.3` বিলিং পেজ (`ManageBillingSubscription`)-এর `entitlement_overview` সেকশনে সাবস্ক্রিপশন মেয়াদ ও রিমেইনিং ডেজ কাউন্টডাউন ব্যানার এবং `live_checkout_summary`-তে প্ল্যান অ্যাকশন (Period Extension / Billing Cycle Switch / Plan Upgrade) লাইভ ব্যাখ্যা যোগ করা।
  - [x] `14.4` ইন্ডাস্ট্রি স্ট্যান্ডার্ড **Renewal Window Guard (৭ দিন)** বাস্তবায়ন: активный প্যাকেজের ৭ দিনের বেশি মেয়াদ বাকি থাকলে সিস্টেম ইউজারকে একই প্যাকেজ পুনরায় কিনে ডাবল পেমেন্ট করা থেকে বিরত রাখে এবং উচ্চতর রিসোর্স লাগলে আপগ্রেড করার পরামর্শ দেয়।
  - [x] `14.5` `ManageSystemSettings` অ্যাডমিন প্যানেলে `renewal_window_days` ডায়নামিক কনফিগারেশন ইনপুট ফিল্ড যুক্ত করা যাতে অ্যাডমিন কোড পরিবর্তন ছাড়াই রিনিউয়াল উইন্ডো দিন নির্ধারণ করতে পারে।
  - [x] `14.6` `MultiGatewayBillingService::fulfillSuccessfulPayment`-এ SaaS সাবস্ক্রিপশন এক্সটেনশন লজিক বাস্তবায়ন: একই প্যাকেজ রিনিউ করলে বর্তমান `current_period_ends_at` থেকে সময় বৃদ্ধি করা (`$baseDate->addMonth()` / `addYear()`), আর অন্য প্ল্যানে আপগ্রেড করলে অবিলম্বে `now()` থেকে সক্রিয় করা।
  - [x] `14.7` বিলিং চেকআউটে ১৫ মিনিটের ডুপ্লিকেট পেন্ডিং ইনভয়েস গার্ড দেওয়া যাতে ইউজার বারবার একই প্যাকেজ চাপলে ডুপ্লিকেট ইনভয়েস তৈরি না হয়ে আগের পেন্ডিং চেকআউটে রিডাইরেক্ট করে।
  - [x] `14.8` `SubscriptionPeriodExtensionTest` ফিচার টেস্ট তৈরি ও সম্পন্ন করা (২০টি টেস্টের সবকটি ১০০% পাস)।

---

### Step 15: Stripe & PayPal Real Hosted Gateway Integration (Sandbox & Live) (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 14 সম্পন্ন হওয়ার পর।
* **উদ্দেশ্য:** SSLCOMMERZ-এর মতো Stripe ও PayPal-এর ক্ষেত্রেও রিয়েল হোস্টেড চেকআউট রিডাইরেকশন নিশ্চিত করা; যাতে অ্যাডমিন সেটিংসে স্যান্ডবক্স বা লাইভ ক্রেডেনশিয়াল দিলে সরাসরি মূল Stripe/PayPal সাইটে গিয়ে পেমেন্ট হয়।
* **Tasks:**
  - [x] `15.1` `BillingCheckoutController::initiateStripeGatewayUrl` ইমপ্লিমেন্ট করা (অফিসিয়াল Stripe Checkout Session তৈরি করে সরাসরি `checkout.stripe.com`-এ রিডাইরেক্ট করা)।
  - [x] `15.2` `BillingCheckoutController::initiatePayPalGatewayUrl` ইমপ্লিমেন্ট করা (PayPal v2 Orders API দিয়ে OAuth টোকেন গ্রহণ ও অর্ডার তৈরি করে অফিসিয়াল `sandbox.paypal.com` বা `paypal.com`-এর Approval URL-এ রিডাইরেক্ট করা)।
  - [x] `15.3` `BillingCheckoutController::completeCheckout`-এ Stripe Checkout Session ভেরিফিকেশন এবং PayPal v2 Order Capture লজিক ইন্টিগ্রেট করা।
  - [x] `15.4` `ManageSystemSettings` অ্যাডমিন প্যানেলে `stripe_sandbox` টগল সুইচ ও প্লেসহোল্ডার টেক্সট যুক্ত করা।
  - [x] `15.5` `ManageBillingSubscription::checkout` আপডেট করা যাতে SSLCOMMERZ, Stripe ও PayPal ক্রেডেনশিয়াল থাকলে সরাসরি মূল গেটওয়েতে রিডাইরেক্ট করে। ক্রেডেনশিয়াল না থাকলে সিমুলেশন স্যান্ডবক্স চেকআউটে ফলব্যাক করে।
  - [x] `15.6` ফিচার টেস্টে PayPal ও Stripe গেটওয়ে ইনিশিয়েশন টেস্ট যোগ করা (২১টি টেস্টের সবকটি ১০০% পাস)।

---

### Step 16: Asynchronous Non-Blocking Backup Queue & Live Progress Percentage Streaming (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 15 সম্পন্ন হওয়ার পর।
* **উদ্দেশ্য:** সমস্ত ব্যাকআপ যাতে সম্পূর্ণ সার্ভার ব্যাকগ্রাউন্ডে নন-ব্লকিং কিউ হিসেবে চলে (ব্রাউজার টাইমআউট মুক্ত), এবং ড্যাশবোর্ডে চলমান ব্যাকআপের লাইভ অগ্রগতি শতাংশ (%) ও বর্তমান স্টেজ লাইভ দেখা যায়।
* **Tasks:**
  - [x] `16.1` `BackupPolicyResource` এবং `BackupRunAdminResource`-এ `ExecuteBackupRunJob::dispatch` অ্যাসিনক্রোনাস ব্যাকগ্রাউন্ড কিউতে রূপান্তর করা।
  - [x] `16.2` `BackupRunStatus` এবং `BackupRun` মডেলে `progressPercent(): int` ও `stageDescription(): string` মেথড যোগ করা (`Queued: 5%`, `Connecting: 15%`, `Extracting: 40%`, `Packaging: 65%`, `Uploading: 85%`, `Verifying: 95%`, `Completed: 100%`)।
  - [x] `16.3` `WorkspaceHealthPulseWidget` এবং `RecentBackupStreamWidget`-এ ব্যাকআপ অ্যাক্টিভ চলাকালীন সময়ে প্রতি ৩ সেকেন্ডে স্বয়ংক্রিয় লাইভওয়্যার পোলিং (`wire:poll.3s` / `getPollingInterval = '3s'`) চালু করা।
  - [x] `16.4` ড্যাশবোর্ড উইজেটে লাইভ পার্সেন্টেজ ব্যাজ ও মসৃণ অ্যানিমেটেড প্রগ্রেস বার ইন্টিগ্রেট করা।
  - [x] `16.5` `AppDashboardWidgetsTest`-এ লাইভ পার্সেন্টেজ ভেরিফিকেশন টেস্ট যুক্ত করা (২২টি টেস্টের সবকটি ১০০% পাস)।

---

### Step 17: Fix Hidden Encrypted Credentials Hydration on Edit Forms (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 16 সম্পন্ন হওয়ার পর।
* **সমস্যা:** ইউজার `ServerConnection` (SSH, FTP, cPanel, DB credentials) সেভ করার পর পরবর্তীতে `Edit` পেজে গেলে কোনো ফিল্ডেই পূর্বের সংরক্ষিত তথ্য দেখা যেত না (সব ফাঁকা আসত)।
* **কারণ:** Eloquent মডেলে `protected $hidden = ['file_credentials', 'db_credentials']` থাকার কারণে Filament যখন এডিট ফর্ম লোড করতে `$record->toArray()` কল করত, তখন Laravel স্বয়ংক্রিয়ভাবে ক্রেডেনশিয়াল অ্যারে মুছে ফেলত।
* **Tasks:**
  - [x] `17.1` `ServerConnection` মডেল থেকে `protected $hidden` অপসারণ করা (ডাটাবেজ কলামগুলো ইতোমধ্যে `encrypted:array` দিয়ে ডাটাবেজে স্বয়ংক্রিয়ভাবে AES-256 এনক্রিপ্টেড থাকে)।
  - [x] `17.2` `StorageConnection`, `NotificationChannel`, এবং `BackupPolicy` মডেল থেকেও অপ্রয়োজনীয় `$hidden` অপসারণ করা যাতে ক্লাউড স্টোরেজ, অ্যালার্ট কনফিগ ও এনক্রিপশন পাসফ্রেজ এডিট পেজে ফাঁকা না হয়।
  - [x] `17.3` `ServerConnectionEditTest` ফিচার টেস্ট তৈরি করা যা প্রমাণ করে যে এডিট পেজ ওপেন করলে সমস্ত নেস্টেড ক্রেডেনশিয়াল (`host`, `port`, `username`, `root_path`, `database` ইত্যাদি) ফিল্ডগুলোতে অক্ষুণ্ণ থাকে এবং ফর্ম সাবমিট করলে যথাযথভাবে সংরক্ষিত হয় (২৫টি টেস্টের সবকটি ১০০% পাস)।

---

### Step 18: Public Legal Pages, Dynamic CMS Sync, SMTP Test Email, 2FA, Quiet Hours, Branding & Custom SSRF Blocklist (`✅ COMPLETED`)
* **কখন শুরু হবে:** Step 17 সম্পন্ন হওয়ার পর।
* **উদ্দেশ্য:** BRD, SRS এবং UI/UX ডকুমেন্টেশনে উল্লেখিত বাকি থাকা সমস্ত সূক্ষ্ম ফিচার কোডবেসে শতভাগ বাস্তবায়ন করা।
* **Tasks:**
  - [x] `18.1` পাবলিক লিগ্যাল পেজ (`/terms`, `/privacy`, `/refund-policy`) এবং ডার্ক/লাইট থিম-অ্যাওয়ার `resources/views/legal.blade.php` তৈরি করা এবং ফুটারে লিংক যুক্ত করা।
  - [x] `18.2` `ManageSystemSettings` (`landing_cms`) এবং `routes/web.php`-এর মধ্যে Hero Title, Subtitle, Primary CTA ও Legal Content কী-গুলোর সিঙ্ক্রোনাইজেশন সম্পন্ন করা।
  - [x] `18.3` অ্যাডমিনের SMTP সেটিংস ট্যাবে লাইভ **`"Send Test Email"`** বাটন (`sendTestEmail`) এবং অডিট লগ ইন্টিগ্রেশন যুক্ত করা।
  - [x] `18.4` অ্যাডমিন ও এজেন্সি ব্র্যান্ডিংয়ে ডাইনামিক `Logo (Light/Dark)`, `Favicon`, `Support Phone`, `Footer Text`, এবং ক্লায়েন্ট পিডিএফ রিপোর্টের জন্য `Agency Logo` ফিল্ড যুক্ত করা।
  - [x] `18.5` ইউজারের `Two-Factor Authentication (TOTP 2FA)` সক্রিয়করণ এবং অ্যাডমিন সেটিংসে `Enforce 2FA` টগল যুক্ত করা।
  - [x] `18.6` কাস্টমার ওয়ার্কস্পেস সেটিংসে `Date Format` সিলেক্টর এবং নোটিফিকেশনের জন্য `Quiet Hours` (`quiet_hours_enabled`, `quiet_hours_start`, `quiet_hours_end`) ও `WorkspaceAlertDispatcher::isQuietHoursMuted()` যুক্ত করা।
  - [x] `18.7` অ্যাডমিন সেটিংসে `Expired Account Metadata Retention Days`, `Retry Backoff Time (Seconds)` (`ExecuteBackupRunJob::$backoff`), এবং `Custom SSRF Blocklist IP Ranges` (`SsrfValidator`) যুক্ত করা এবং `Step18RemainingSpecFeaturesTest` দিয়ে যাচাই করা।
