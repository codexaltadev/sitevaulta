# SiteVaulta — Business Requirements Document (BRD)
## Full Production-Ready Multi-Website & Server Backup Automation SaaS
**Tagline:** Your Sites. Your Storage. Your Control.  
**Document Version:** 3.1 (Finalized Dual-Panel: `/admin` with Filament Shield + `/app` with Workspace Tenancy + 100% Dynamic Settings)  
**Prepared For:** Full Production Launch & Subsequent SRS Preparation  
**Core Tech Stack:** Laravel 13 + Filament 5 (Dual-Panel: `/admin` & `/app`) + Filament Shield + Redis/Horizon + MySQL  

---

## 1. Document Purpose & Core Concept

বর্তমান AI-এর যুগে অর্ধ-সমাপ্ত MVP বাজারে ছাড়লে যে কেউ আইডিয়াটি কপি করে ফেলতে পারে। তাই **SiteVaulta** প্রথম দিন থেকেই একটি **Complete, Production-Grade, Framework-Agnostic এবং 100% Dynamic SaaS Platform** হিসেবে বাজারে নামবে।

### মূল ধারণা (Core Philosophy)
1. **100% Framework-Agnostic (Stack-Blind):** ইউজারের ওয়েবসাইটটি **Raw PHP, CodeIgniter, Laravel, WordPress, Node.js বা Static HTML**—যাতেই তৈরি হোক না কেন, SiteVaulta-এর তা জানার প্রয়োজন নেই। ইউজার ডোমেইন ভেরিফাই করে তার **Server ও Database Connection Information** (`SSH/SFTP`, `FTP/FTPS`, `cPanel API`, এবং `MySQL/PostgreSQL`) দেবে এবং সেটির মাধ্যমেই ব্যাকআপ কার্যকর হবে।
2. **BYOS (Bring Your Own Storage):** ইউজার নিজের `Google Drive`, `Amazon S3`, `Cloudflare R2`, বা `VPS/SFTP` স্টোরেজ সংযুক্ত করবে।
3. **Dual-Panel Architecture (`/admin` + `/app`):**
   - **Super Admin Panel (`/admin` — No Tenancy Lock):** এখানে **Filament Shield (`spatie/laravel-permission`)** থাকবে। ফলে **Super Admin** এবং আপনার তৈরি করা যেকোনো স্টাফ রোল (`Support Admin`, `Billing Manager`, `Auditor`) পুরো প্রজেক্টের গ্লোবাল সেটিংস, প্ল্যান, পেমেন্ট এবং সব ইউজার ও ওয়ার্কস্পেস নিয়ন্ত্রণ করতে পারবে।
   - **Customer Workspace Panel (`/app` — Tenant-Isolated):** এখানে কাস্টমার ও এজেন্সিরা তাদের নিজস্ব **Workspace (Tenant)**-এর ভেতরে ওয়েবসাইট, সার্ভার, স্টোরেজ, ব্যাকআপ পলিসি, টিম মেম্বার এবং নিজস্ব ডাইনামিক সেটিংস পরিচালনা করবে। এক কাস্টমার কখনোই অন্য কাস্টমারের ডাটা দেখতে পারবে না।
4. **Zero Static Values (100% Dynamic Configuration):** প্রজেক্টে কোনো সেটিংস, লিমিট, টাইমআউট, ফোরকাস্ট ফ্যাক্টর, পেমেন্ট ক্রেডেনশিয়াল, SMTP, বা ল্যান্ডিং পেজ টেক্সট স্ট্যাটিক (Hardcoded) থাকবে না। Admin (`/admin/settings`) এবং Customer (`/app/{workspace}/settings`) ড্যাশবোর্ড থেকে সব ভ্যালু ডাইনামিকভাবে পরিবর্তন করা যাবে।

---

## 2. Identified Gaps & Finalized Architectural Enhancements

| # | আগের প্ল্যানে যে গ্যাপ ছিল (Identified Gap) | চূড়ান্ত প্রোডাক্টে যেভাবে সমাধান করা হয়েছে (Finalized Solution) |
|---|---|---|
| **1** | **Framework Dependency Confusion:** শুধু Laravel বা নির্দিষ্ট প্লাগইনের ওপর নির্ভরতা। | **Universal Server Connectors:** `SSH/SFTP`, `FTP/FTPS`, `cPanel API`, এবং `MySQL/MariaDB/PostgreSQL` সাপোর্ট—যাতে VPS এবং Shared Hosting-এর যেকোনো ওয়েবসাইট ব্যাকআপ নেওয়া যায়। |
| **2** | **Admin vs Tenant Conflict:** একই প্যানেলে টেন্যান্ট রাখলে Super Admin ও প্রজেক্ট সেটিংস ম্যানেজ করা যায় না। | **Filament 5 Dual-Panel (`/admin` + `/app`):** `/admin` প্যানেলে কোনো টেন্যান্ট লক থাকবে না—এখানে **Filament Shield** দিয়ে Super Admin ও মাল্টিপল রোল এবং প্রজেক্টের সব গ্লোবাল সেটিংস ম্যানেজ হবে। আর `/app` প্যানেলে কাস্টমারদের টেন্যান্ট (`Workspace`) আইসোলেশন থাকবে। |
| **3** | **Hardcoded / Static Settings Problem:** কোডের ভেতর ট্রায়াল দিন, টাইমআউট, বা পেমেন্ট সেটিংস স্ট্যাটিক থাকলে বারবার কোড ডেপ্লয় করতে হয়। | **100% Dynamic Settings Engine:** Admin (`/admin/settings`) এবং Customer (`/app/{workspace}/settings`) উভয়ের জন্য সম্পূর্ণ ডাইনামিক সেটিংস প্যানেল—যেখান থেকে ব্র্যান্ডিং, ট্রায়াল দিন, পেমেন্ট গেটওয়ে কী, SMTP, ফোরকাস্ট প্যারামিটার এবং ল্যান্ডিং পেজের কনটেন্ট কোড ছাড়াই পরিবর্তন করা যাবে। |
| **4** | **Backup Transfer & Server Disk Bottleneck:** হাজার হাজার ফাইল আলাদাভাবে ডাউনলোড করলে সার্ভার ডিস্ক ভরে যায়। | **Hybrid Ephemeral Worker Flow:** SSH/cPanel থাকলে রিমোটলি স্ট্রিম কম্প্রেশন; আর FTP হলে পরিবর্তিত ফাইল স্ট্রিম করে SiteVaulta Worker-এ এনে কম্প্রেস ও এনক্রিপ্ট করে ইউজারের স্টোরেজে আপলোড এবং **সাথে সাথে টেম্প ফাইল ডিলিট**। |
| **5** | **Plaintext Backups in User Storage:** ইউজারের Google Drive বা S3 হ্যাক হলে ডাটা উন্মুক্ত হয়ে যাওয়ার ঝুঁকি। | **Optional AES-256-GCM Archive Encryption:** ব্যাকআপ ফাইল স্টোরেজে আপলোড হওয়ার আগেই পাসওয়ার্ড/কী দিয়ে এনক্রিপ্ট করার সুবিধা। |
| **6** | **Silent Failure & Empty Backup Risk:** ০ বাইট বা অসম্পূর্ণ ব্যাকআপকেও সাধারণ সিস্টেম সফল দেখায়। | **Smart Anomaly Detection & SHA-256 Verification:** চেকসাম ভেরিফিকেশন এবং ফাইল সাইজ হঠাৎ অস্বাভাবিক কমে/বেড়ে গেলে (ডাইনামিক থ্রেশহোল্ড অনুযায়ী) **Anomaly Warning**। |
| **7** | **Restore Complexity:** ইনক্রিমেন্টাল ব্যাকআপ ডাউনলোড করার পর কীভাবে রিস্টোর করবে তার গাইডলাইন। | **Secure Download (`One-Time Token` / `Signed URL`) + Dynamic Restore Guide:** প্রতিটি ব্যাকআপের জন্য কাস্টমাইজড স্টেপ-বাই-স্টেপ রিস্টোর গাইড ও চেকসাম কমান্ড। |
| **8** | **Developer & Agency Needs:** ডিপ্লয়মেন্টের আগে ব্যাকআপ এবং ক্লায়েন্ট রিপোর্ট। | **CI/CD Pre-Deploy Webhook** এবং **White-Label PDF Reports**। |

---

## 3. Why Laravel 13 + Filament 5 Dual-Panel (`admin` & `app`) is the Best Architecture

1. **Panel 1: Super Admin & Staff Panel (`/admin`):**
   - **No Tenancy Restriction:** এখানে কোনো টেন্যান্ট বাধা নেই। পুরো প্ল্যাটফর্মের মালিক এবং স্টাফরা এখানে লগইন করবেন।
   - **Powered by `Filament Shield` (`spatie/laravel-permission`):** `super_admin` ছাড়াও আপনি ড্যাশবোর্ড থেকে যেকোনো নতুন রোল (`Support Executive`, `Finance Manager`, `QA Auditor`) তৈরি করতে পারবেন এবং কোন রোল কোন মডিউলের `view`, `create`, `update`, `delete` করতে পারবে তা চেকবক্স দিয়ে নির্ধারণ করতে পারবেন।
   - **Global System Settings Control:** প্রজেক্টের সব গ্লোবাল সেটিংস এখান থেকে নিয়ন্ত্রিত হবে।
2. **Panel 2: Customer Workspace Panel (`/app`):**
   - **Native Workspace Isolation:** কাস্টমাররা `/app`-এ লগইন করে তাদের নিজস্ব Workspace-এ কাজ করবে। Filament স্বয়ংক্রিয়ভাবে প্রতিটি কুয়েরি ওই Workspace-এর ভেতরে লক করে রাখবে—ফলে এক ইউজার অন্য ইউজারের কোনো ডাটা কখনোই দেখতে পারবে না।
   - **Workspace Team & Dynamic Settings:** কাস্টমাররা তাদের নিজস্ব টিম মেম্বার (`Owner`, `Admin`, `Operator`, `Viewer`) এবং তাদের নিজস্ব Workspace Settings নিজেরাই পরিবর্তন করতে পারবে।

---

## 4. 100% Dynamic Settings Architecture (No Static Values Anywhere)

### 4.1 Super Admin Dynamic Settings (`/admin/settings`)
অ্যাডমিন প্যানেল থেকে নিচের সব সেটিংস যেকোনো সময় পরিবর্তন করা যাবে:
1. **General & Branding Settings:** প্ল্যাটফর্মের নাম (`SiteVaulta`), ট্যাগলাইন, লোগো (Light/Dark), ফেভিকন, সাপোর্ট ইমেইল, ফোন নম্বর, ফুটার টেক্সট, ডিফল্ট টাইমজোন ও ডিফল্ট ভাষা।
2. **Trial, Subscription & Quota Settings:** ফ্রি ট্রায়ালের মেয়াদ (যেমন `7` দিন বা `14` দিন—ডাইনামিক), গ্রেস পিরিয়ড দিন (`3` দিন), এক্সপায়ার্ড অ্যাকাউন্টের মেটাডাটা রিটেনশন দিন, ট্যাক্স/ভ্যাট পার্সেন্টেজ।
3. **Backup Engine & Forecast Settings:**
   - কোন কোন File Protocol (`SSH/SFTP`, `FTP`, `cPanel`, `Agent`) এবং Storage Provider (`Google Drive`, `S3`, `R2`, `SFTP`) চালু বা বন্ধ থাকবে।
   - ডিফল্ট গ্লোবাল Exclude ফোল্ডার লিস্ট (`.git`, `node_modules`, `vendor`, ইত্যাদি)।
   - স্টোরেজ ফোরকাস্টের ডিফল্ট কম্প্রেশন রেশিও (`0.60`), ইনক্রিমেন্টাল চেঞ্জ রেট (`0.05`), ডিফল্ট ফুল রিসেট কাউন্ট (`7`)।
   - ব্যাকআপ জব টাইমআউট, ম্যাক্স রিট্রাই কাউন্ট, রিট্রাই ব্যাকঅফ সময় এবং Anomaly Detection থ্রেশহোল্ড (`50%` সাইজ ড্রপ বা `200%` বৃদ্ধি)।
4. **Download & Security Settings:** Presigned URL মেয়াদ (মিনিট), One-Time Download Token মেয়াদ (মিনিট), ম্যাক্স ডাউনলোড রিজিউম রিট্রাই সংখ্যা, 2FA এনফোর্সমেন্ট টগল, SSRF ব্লকলিস্ট আইপি রেঞ্জ।
5. **Payment Gateways & Currency Settings:**
   - **SSLCOMMERZ:** Active/Inactive টগল, Sandbox/Live মোড, Store ID, Store Password।
   - **Stripe:** Active/Inactive টগল, Publishable Key, Secret Key, Webhook Secret।
   - **PayPal:** Active/Inactive টগল, Sandbox/Live মোড, Client ID, Client Secret, Webhook ID।
6. **SMTP, OAuth & System Integrations Settings:** ইমেইল সার্ভার (SMTP Host, Port, Username, Password, Encryption) + `"Send Test Email"` বাটন এবং Google Drive OAuth (`Client ID`, `Client Secret`)।
7. **Dynamic Email Templates & Landing Page CMS:** সব সিস্টেম ইমেইল টেমপ্লেট এবং ল্যান্ডিং পেজের সব কনটেন্ট ও লিগ্যাল পেজ।

### 4.2 Customer Workspace Dynamic Settings (`/app/{workspace}/settings`)
ইউজার নিজে তার Workspace থেকে নিচের সব সেটিংস পরিবর্তন করতে পারবে:
1. **Workspace & Regional Preferences:** Workspace নাম, নিজস্ব টাইমজোন (`Asia/Dhaka`, `UTC` ইত্যাদি), প্রিফার্ড কারেন্সি (`BDT` / `USD`), তারিখ ফরম্যাট ও 2FA।
2. **Default Backup Preferences:** নতুন পলিসি তৈরির সময় ডিফল্ট Exclude Paths, ডিফল্ট Encryption অন/অফ, ডিফল্ট Download Mode (`One-Time Token` বা `Signed URL`) এবং ডিফল্ট রিটেনশন দিন।
3. **Notification Routing Preferences:** কোন ধরনের ইভেন্টে কোন চ্যানেলে (`Email`, `Slack`, `Discord`, `Telegram`, `Custom Webhook`) মেসেজ যাবে এবং Quiet Hours।
4. **Agency White-Label Branding Settings:** ক্লায়েন্ট পিডিএফ রিপোর্টের জন্য নিজের এজেন্সির নাম, লোগো, ব্র্যান্ড কালার ও কাস্টম ফুটার।

---

## 5. Complete Functional Scope Summary
1. **Dual-Panel Access (`/admin` with Filament Shield + `/app` with Workspace Tenancy)**
2. **Website Onboarding & Domain Ownership Verification (`DNS TXT`, `HTML File`, `Meta Tag`)**
3. **Universal Server & Database Connectors (`SSH/SFTP`, `FTP/FTPS`, `cPanel API`, `MySQL/MariaDB/PostgreSQL` + SSH Tunnel)**
4. **Customer-Owned Storage Integrations (`Google Drive`, `Amazon S3`, `Cloudflare R2`, `S3-Compatible`, `VPS/SFTP`)**
5. **Backup Policies, Reusable Templates, Full/Incremental Engine & AES-256 Encryption**
6. **Dynamic Pre-Backup Storage Forecaster**
7. **Secure Download (`One-Time Token` / `Presigned URL`) & Dynamic Context-Aware Restore Guide**
8. **Dynamic Plans, 7-Day Free Trial Lifecycle & Multi-Gateway Billing (`SSLCOMMERZ`, `Stripe`, `PayPal`)**
