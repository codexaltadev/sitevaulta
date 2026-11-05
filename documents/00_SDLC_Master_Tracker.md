# SiteVaulta — Master SDLC Index & Project Status Tracker
**Last Updated:** 2026-10-29  
**Core Tech Stack:** Laravel 13 + Filament 5 (Dual-Panel: `/admin` & `/app`) + Filament Shield + 100% Dynamic Settings  
**Current Active Phase:** `✅ ALL 13 STEPS COMPLETED & PRODUCTION READY`  

---

## 1. SDLC Documentation Suite (কোন ডকুমেন্টের পর কোন ডকুমেন্ট)

আপনার `documents/` ফোল্ডারে স্ট্যান্ডার্ড **SDLC (Software Development Life Cycle)** অনুযায়ী নিচের ৫টি ডকুমেন্ট ক্রমানুসারে তৈরি ও চূড়ান্ত করা হয়েছে:

| SDLC Order | Phase Name | Document File Name | Purpose (কী আছে এতে) | Document Status |
|---:|---|---|---|---|
| **Phase 1** | **Requirement Gathering (BRD)** | `SiteVaulta_BRD_Complete_Specification.md` | বিজনেস মডেল, Dual-Panel (`/admin` + `/app`), Filament Shield, ১০০% ডাইনামিক সেটিংস ও গ্যাপ সমাধান। | `✅ COMPLETED` |
| **Phase 2** | **System & Software Design (SRS)** | `SiteVaulta_SRS_Specification.md` | ২৮টি ডাটাবেস টেবিলের স্কিমা (`workspaces`, `system_settings`, `workspace_settings`, `roles` সহ), `AdminPanelProvider` ও `AppPanelProvider` ডিজাইন। | `✅ COMPLETED` |
| **Phase 3** | **UI/UX & Screen Workflow** | `SiteVaulta_UI_UX_and_Workflow_Specification.md` | CMS-চালিত ল্যান্ডিং পেজ, Customer Panel (`/app`), Super Admin Panel (`/admin`), এবং ডাইনামিক সেটিংস পেজের স্ক্রিন ডিজাইন। | `✅ COMPLETED` |
| **Phase 4** | **Development Plan & Task Checklist** | `SiteVaulta_Implementation_Plan_and_Status_Tracker.md` | কোডিংয়ের ১০টি ক্রমিক ধাপ (`Step 1` থেকে `Step 10`), Composer প্যাকেজ এবং সাব-টাস্ক চেকলিস্ট। | `✅ COMPLETED` |
| **Phase 5** | **QA Testing & Deployment Guide** | `SiteVaulta_QA_Security_and_Deployment_Guide.md` | RBAC, Dynamic Settings, SSRF, ও Backup/Restore টেস্ট কেস ম্যাট্রিক্স এবং প্রোডাকশন সার্ভার কনফিগারেশন। | `✅ COMPLETED` |

---

## 2. Master Development & Launch Sequence (কোনটার পর কোনটা হবে + Live Status)

নিচের টেবিলটি আমাদের মূল **Development & Launch Progress Tracker**। প্রতিটি ধাপ সম্পন্ন করে স্ট্যাটাস `[x] ✅ COMPLETED` করে দেওয়া হয়েছে:

| Execution Order | Milestone / Phase Title | Prerequisite (কোন ধাপের পর হবে) | Detailed Reference | Current Status |
|---:|---|---|---|---|
| **Stage 0** | **Complete SDLC Documentation (5 Docs)** | Starting Point | All 5 files in `documents/` | `[x] ✅ COMPLETED` |
| **Step 1** | **Laravel 13 + Filament 5 Dual-Panel (`/admin` & `/app`) + Shield Setup** | Stage 0 এর পর | `Implementation_Plan` -> Step 1 | `[x] ✅ COMPLETED` |
| **Step 2** | **Database Migrations, Enums & Models (incl. Workspaces & Dynamic Settings)** | Step 1 এর পর | `Implementation_Plan` -> Step 2 | `[x] ✅ COMPLETED` |
| **Step 3** | **Admin Shield RBAC, `/app` Workspace Tenancy & Dynamic Settings Engine** | Step 2 এর পর | `Implementation_Plan` -> Step 3 | `[x] ✅ COMPLETED` |
| **Step 4** | **Website Onboarding Wizard, SSRF Guard & Domain Verification** | Step 3 এর পর | `Implementation_Plan` -> Step 4 | `[x] ✅ COMPLETED` |
| **Step 5** | **Universal Server & DB Connectors (`SSH/SFTP`, `FTP`, `cPanel`, `MySQL/PG`)** | Step 4 এর পর | `Implementation_Plan` -> Step 5 | `[x] ✅ COMPLETED` |
| **Step 6** | **Customer Storage Adapters (`Google Drive`, `S3`, `R2`, `SFTP`)** | Step 4 এর পর | `Implementation_Plan` -> Step 6 | `[x] ✅ COMPLETED` |
| **Step 7** | **Backup Templates, Policies & Dynamic Storage Forecast Engine** | Step 5 ও Step 6 এর পর | `Implementation_Plan` -> Step 7 | `[x] ✅ COMPLETED` |
| **Step 8** | **Hybrid Backup Job Pipeline, AES-256 Encryption, SHA-256 & Retention** | Step 7 এর পর | `Implementation_Plan` -> Step 8 | `[x] ✅ COMPLETED` |
| **Step 9** | **Secure Download (`One-Time`/`Signed`), Restore Guide, Alerts & Reports** | Step 8 এর পর | `Implementation_Plan` -> Step 9 | `[x] ✅ COMPLETED` |
| **Step 10** | **Multi-Gateway Billing (`SSLCOMMERZ`, `Stripe`, `PayPal`), Admin & CMS Landing** | Step 9 এর পর | `Implementation_Plan` -> Step 10 | `[x] ✅ COMPLETED` |
| **Step 11** | **Full QA Verification Matrix & Security Audit** | Step 10 এর পর | `QA_Security_and_Deployment_Guide` | `[x] ✅ COMPLETED` |
| **Step 12** | **Dark & Light Mode (`/app`, `/admin`, Public Pages — Shared Theme Preference)** | Step 11 এর পর | `UI_UX_Specification` -> Section 1.1, `Implementation_Plan` -> Step 12 | `[x] ✅ COMPLETED` |
| **Step 13** | **Customer Dashboard Animated Report Widgets & Demo Data Seeder** | Step 12 এর পর | `UI_UX_Specification` -> Screen 1, `Implementation_Plan` -> Step 13 | `[x] ✅ COMPLETED` |
| **Step 18** | **Legal Pages (`/terms`, `/privacy`, `/refund-policy`), SMTP Test Email, 2FA, Quiet Hours, Branding & SSRF Custom Blocklist** | Step 17 এর পর | `Implementation_Plan` -> Step 18 | `[x] ✅ COMPLETED` |
