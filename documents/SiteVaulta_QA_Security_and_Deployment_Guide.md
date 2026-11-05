# SiteVaulta — QA Test Plan, Security Audit & Production Deployment Guide
## Verification Matrix, Security Checklist & Server Runbook
**SDLC Phase:** Phase 5 — Testing, Security Verification & Production Deployment  
**Document Status:** `✅ COMPLETED & VERIFIED BY AUTOMATED TEST SUITE`  
**Stack:** Laravel 13 + Filament 5 + Filament Shield + 100% Dynamic Settings  
**Previous Document:** `SiteVaulta_Implementation_Plan_and_Status_Tracker.md`  

---

## 1. Comprehensive QA Test Matrix (Acceptance Criteria)

`tests/Feature/SiteVaultaEndToEndTest.php`-এর মাধ্যমে নিচের প্রতিটি টেস্ট কেস যাচাই ও পাশ (`PASS`) করা হয়েছে:

### 1.1 Role-Based Isolation (`Filament Shield` & Workspace Tenancy) Tests
| Test ID | Test Scenario | Expected Result | Status |
|---|---|---|---|
| `QA-RBAC-01` | Customer A (`/app`) তার ব্রাউজার থেকে `/admin` বা অন্য Workspace-এ অ্যাক্সেস করার চেষ্টা করে। | প্যানেল ও টেন্যান্ট গার্ড ব্লক করে; কেউ কারো ডাটা কখনোই দেখতে পারে না। | `[x] ✅ COMPLETED` |
| `QA-RBAC-02` | Super Admin (`/admin/shield/roles`) থেকে স্টাফ রোল ও পারমিশন কন্ট্রোল করে। | স্টাফ রোল অনুযায়ী মেনু ও অ্যাকশন নিয়ন্ত্রিত হয়। | `[x] ✅ COMPLETED` |
| `QA-RBAC-03` | কাস্টমারের SSH Key, DB Password বা S3 Secret ডাটাবেসে `encrypted:array` কাস্ট দিয়ে সংরক্ষিত থাকে। | ক্রেডেনশিয়াল ফিল্ড মাস্কড ও এনক্রিপ্টেড থাকে। | `[x] ✅ COMPLETED` |

### 1.2 100% Dynamic Settings Verification Tests (Zero Static Values)
| Test ID | Test Scenario | Expected Result | Status |
|---|---|---|---|
| `QA-DYN-01` | Admin `/admin/settings` থেকে App Name, Free Trial Days ও গ্লোবাল প্যারামিটার পরিবর্তন করে। | কোড পরিবর্তন ছাড়াই রানটাইমে ও ল্যান্ডিং পেজে সাথে সাথে কার্যকর হয়। | `[x] ✅ COMPLETED` |
| `QA-DYN-02` | Admin `/admin/settings` থেকে SMTP, SSLCOMMERZ বা Stripe Key পরিবর্তন করে এবং ফোরকাস্ট রেশিও আপডেট করে। | `.env` ফাইলে হাত না দিয়েই রানটাইমে নতুন কী ও ফোরকাস্ট ক্যালকুলেশন কার্যকর হয়। | `[x] ✅ COMPLETED` |
| `QA-DYN-03` | Customer `/app/{workspace}/profile` থেকে তার নিজস্ব ডিফল্ট Exclude Paths, Default Retention Days এবং Timezone পরিবর্তন করে। | পলিসি ফর্মে ইউজারের সেট করা ডাইনামিক ডিফল্ট মানগুলো স্বয়ংক্রিয়ভাবে বসে যায়। | `[x] ✅ COMPLETED` |

### 1.3 Domain Verification & SSRF Security Tests
| Test ID | Test Scenario | Expected Result | Status |
|---|---|---|---|
| `QA-SEC-01` | ইউজার ওয়েবসাইট URL বা FTP/SSH Host হিসেবে `127.0.0.1`, `localhost`, `169.254.169.254`, বা প্রাইভেট আইপি দেয়। | `SsrfValidator::assertSafeHost()` সাথে সাথে ব্লক করে। | `[x] ✅ COMPLETED` |
| `QA-VER-01` | DNS TXT, HTML File এবং Meta Tag—তিনটি মেথডেই সঠিক টোকেন দিয়ে ডোমেইন ভেরিফাই টেস্ট। | স্ট্যাটাস `verified` হয় এবং ভেরিফাই হওয়ার পরেই সার্ভার ব্যাকআপ চালু করা যায়। | `[x] ✅ COMPLETED` |

### 1.4 Backup Execution, Incremental Chain & Ephemeral Cleanup Tests
| Test ID | Test Scenario | Expected Result | Status |
|---|---|---|---|
| `QA-BKP-01` | প্রথম ব্যাকআপ রান (`Full Backup` — Files + MySQL/PostgreSQL DB)। | ফাইল ও ডাটাবেস ডাম্প কম্প্রেস ও এনক্রিপ্ট হয়ে ইউজারের স্টোরেজে আপলোড হয়, `SHA-256` ভেরিফাই হয় এবং `/tmp/sitevaulta/run-*` ফোল্ডার পরিষ্কার হয়ে যায়। | `[x] ✅ COMPLETED` |
| `QA-BKP-02` | দ্বিতীয় ব্যাকআপ (`Incremental #1`) রান করা। | শুধুমাত্র পরিবর্তিত ফাইলগুলো আর্কাইভে যায় এবং প্যারেন্ট Full রানের সাথে একই `backup_chain_id` লিংক তৈরি হয়। | `[x] ✅ COMPLETED` |
| `QA-BKP-03` | রিটেনশন ক্লিনআপ জব (`PruneExpiredBackupsJob`) চালানো যখন একটি Incremental ব্যাকআপ এখনো অ্যাক্টিভ আছে। | যতক্ষণ একটি চেইনের সর্বশেষ Incremental ব্যাকআপের মেয়াদ শেষ না হচ্ছে, ততক্ষণ তার Base Full Backup কখনোই প্রুন হয় না। | `[x] ✅ COMPLETED` |

### 1.5 Secure Download, Restore Guide & Multi-Gateway Billing Tests
| Test ID | Test Scenario | Expected Result | Status |
|---|---|---|---|
| `QA-DL-01` | `One-Time Token` দিয়ে ব্যাকআপ ফাইল ডাউনলোড সম্পন্ন করার পর একই লিংকে আবার হিট করা। | প্রথমবার ডাউনলোড সফল (`200 OK` + `X-SiteVaulta-Checksum-SHA256`) হয়; দ্বিতীয়বার `410 Gone` দেখায়। | `[x] ✅ COMPLETED` |
| `QA-RES-01` | `RestoreGuideGenerator::generateForRun()` দিয়ে ডাইনামিক রিস্টোর গাইড তৈরি করা। | সঠিক চেইন অর্ডার, `SHA-256` ভেরিফিকেশন কমান্ড এবং ফাইল/ডাটাবেস রিস্টোর কমান্ড তৈরি হয়। | `[x] ✅ COMPLETED` |
| `QA-BIL-01` | SSLCOMMERZ, Stripe এবং PayPal পেমেন্ট ওয়েবহুক টেস্ট + ৭ দিনের ট্রায়াল এক্সপায়ারি টেস্ট। | ট্রায়াল শেষ হলে পলিসি `inactive_entitlement` হয় এবং পেমেন্ট ওয়েবহুক সফল হলে সাবস্ক্রিপশন ও পলিসি পুনরায় `active` হয় (Idempotent)। | `[x] ✅ COMPLETED` |

### 1.6 Dark & Light Mode (Theme) Tests
বিস্তারিত ইমপ্লিমেন্টেশন ও ডেভেলপার ইনস্ট্রাকশন: `SiteVaulta_UI_UX_and_Workflow_Specification.md` → Section 1.1

| Test ID | Test Scenario | Expected Result | Status |
|---|---|---|---|
| `QA-THM-01` | `/app` ও `/admin`-এ লগইন করে ইউজার মেনু থেকে Light / Dark / System সিলেক্ট করা। | পুরো প্যানেল সাথে সাথে থিম বদলায়; পেজ রিলোডের পরও পছন্দ বজায় থাকে। | `[x] ✅ COMPLETED` |
| `QA-THM-02` | প্রথমবার ভিজিট (কোনো সংরক্ষিত পছন্দ নেই) এবং OS থিম Dark/Light পরিবর্তন করা। | ডিফল্ট `System` মোড OS থিম অনুসরণ করে; প্রথম লোডে ভুল থিমের ফ্ল্যাশ হয় না। | `[x] ✅ COMPLETED` |
| `QA-THM-03` | `/` বা `/docs`-এ টগল বাটন দিয়ে থিম বদলে তারপর `/app`-এ যাওয়া (এবং উল্টোটা)। | একই `localStorage` key (`filament:theme:mode`) শেয়ার হওয়ায় পাবলিক পেজ ও প্যানেলে একই থিম থাকে; অন্য খোলা ট্যাবও সিঙ্ক হয়। | `[x] ✅ COMPLETED` |
| `QA-THM-04` | Light মোডে Storage Connection setup guide, Notification Channel guide, Backup Policy Storage Forecaster, Billing invoice table, Plan yearly preview দেখা। | সব কাস্টম inline HTML কার্ড `--sv-*` ভ্যারিয়েবল ব্যবহার করে — Light মোডে হালকা ব্যাকগ্রাউন্ড ও পাঠযোগ্য গাঢ় টেক্সট দেখায়। | `[x] ✅ COMPLETED` |
| `QA-THM-05` | Dark মোড চালু অবস্থায় Invoice PDF ও Client Report PDF ডাউনলোড করা। | PDF সবসময় প্রিন্ট-ফ্রেন্ডলি Light লেআউটে তৈরি হয়। | `[x] ✅ COMPLETED` |

---

## 2. Production Server & Deployment Architecture

* **OS:** Ubuntu 24.04 LTS
* **Framework & Runtime:** Laravel 13 + Filament 5 on PHP 8.3+ FPM + Nginx
* **Database & Queue:** MySQL 8.0+ / SQLite & Redis / Database Queue (`sitevaulta:dispatch-scheduled-backups` every minute)
* **Ephemeral Staging Disk (`/tmp/sitevaulta`):** NVMe SSD staging directory with automatic cleanup in job `finally` block.
