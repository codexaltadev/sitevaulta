# SiteVaulta — UI/UX & Screen-by-Screen Workflow Specification
## Complete Interface Blueprint for Public Portal, Customer Panel (`/app`) & Admin Panel (`/admin`)
**SDLC Phase:** Phase 3 — UI/UX & Workflow Design  
**Document Status:** `✅ COMPLETED`  
**Stack:** Laravel 13 + Filament 5 + Filament Shield + 100% Dynamic Settings  
**Previous Document:** `SiteVaulta_SRS_Specification.md`  
**Next Document:** `SiteVaulta_Implementation_Plan_and_Status_Tracker.md`  

---

## 1. Design System & Dynamic Theme Guidelines

* **Framework:** Filament 5 (Customer Panel `/app` & Super Admin Panel `/admin`) + Tailwind CSS + Alpine.js + Livewire।
* **100% Dynamic Branding:** লোগো, ফেভিকন, অ্যাপ নাম, ট্যাগলাইন এবং প্রাইমারি ব্র্যান্ড কালার—কোনোটিই স্ট্যাটিক থাকবে না; অ্যাডমিনের `System Settings` এবং এজেন্সির `User Settings` থেকে ডাইনামিকভাবে লোড হবে।
* **Dark & Light Mode (`✅ IMPLEMENTED` — বিস্তারিত নিচের Section 1.1-এ):** উভয় প্যানেলেই ইউজার নিজের পছন্দমতো থিম সেট করতে পারবে।

### 1.1 Dark & Light Mode Implementation & Developer Instruction (`✅ IMPLEMENTED`)

**থিম মোড:** `Light`, `Dark`, এবং `System` (ডিফল্ট — ইউজারের OS সেটিং অনুসরণ করে)। ইউজারের পছন্দ ব্রাউজারের `localStorage`-এ **`filament:theme:mode`** key-তে সংরক্ষিত থাকে — Filament প্যানেল ও পাবলিক পেজ একই key ব্যবহার করে, তাই একবার থিম বদলালে পুরো প্রজেক্টে (`/`, `/docs`, `/app`, `/admin`, checkout) একই থিম থাকে। ডার্ক মোড চালু থাকলে `<html>` ট্যাগে `dark` class যুক্ত হয়।

| এরিয়া | কোথায় কনফিগার করা | থিম সুইচার কোথায় |
|---|---|---|
| Customer Panel (`/app`) | `app/Providers/Filament/AppPanelProvider.php` → `->darkMode(true)`, `->themeSwitcher(true)`, `->defaultThemeMode(ThemeMode::System)` | ইউজার মেনু (অ্যাভাটার) → Light / Dark / System |
| Super Admin Panel (`/admin`) | `app/Providers/Filament/AdminPanelProvider.php` → একই সেটিং | ইউজার মেনু (অ্যাভাটার) → Light / Dark / System |
| Landing Page (`/`) | `resources/views/welcome.blade.php` | হেডার নেভিগেশনে ☀️ / 🌙 / 🖥️ বাটন |
| Docs (`/docs`) | `resources/views/docs.blade.php` | উপরের টপবারে টগল বাটন |
| Sandbox Checkout | `resources/views/billing/sandbox-checkout.blade.php` | উপরে-ডানে ফ্লোটিং টগল বাটন |
| Google Drive OAuth Popup | `app/Http/Controllers/GoogleDriveOAuthController.php` | সংরক্ষিত থিম স্বয়ংক্রিয়ভাবে প্রয়োগ হয় |
| PDF (Invoice, Client Report) | `resources/views/billing/invoice-pdf.blade.php`, `resources/views/reports/client-backup-report-pdf.blade.php` | **ইচ্ছাকৃতভাবে সবসময় Light** (প্রিন্ট-ফ্রেন্ডলি, DomPDF) |

**শেয়ার্ড ফাইলসমূহ:**
* `resources/views/partials/theme-head.blade.php` — পাবলিক পেজের `<head>`-এ থিম বুটস্ট্র্যাপ স্ক্রিপ্ট (first paint-এর আগেই থিম প্রয়োগ করে, তাই ভুল থিমের ফ্ল্যাশ হয় না) + টগল বাটনের স্টাইল। `window.svSetTheme('light'|'dark'|'system')` ও `window.svCycleTheme()` ফাংশন দেয়।
* `resources/views/partials/theme-toggle.blade.php` — Light → Dark → System সাইকেল করা টগল বাটন (`['fixed' => true]` দিলে উপরে-ডানে ফ্লোটিং)।
* `resources/views/partials/filament-theme-variables.blade.php` — Filament প্যানেলের কাস্টম inline HTML-এর জন্য থিম-অ্যাওয়্যার CSS ভ্যারিয়েবল (`--sv-*`); `PanelsRenderHook::HEAD_END` দিয়ে দুই প্যানেলেই ইনজেক্ট করা।

> [!IMPORTANT]
> **ডেভেলপার ইনস্ট্রাকশন (নতুন কোনো UI তৈরির সময় অবশ্যই মানতে হবে):**
> 1. **নতুন পাবলিক Blade পেজ:** `<head>`-এ পেজের `<style>`-এর **আগে** `@include('partials.theme-head')` দিন, এবং দৃশ্যমান জায়গায় `@include('partials.theme-toggle')` দিন।
> 2. **রং কখনো হার্ডকোড করবেন না।** পেজের CSS-এ `:root { ... }`-এ Light রং এবং `html.dark { ... }`-এ Dark রং CSS ভ্যারিয়েবল হিসেবে সংজ্ঞায়িত করুন, তারপর শুধু `var(--...)` ব্যবহার করুন।
> 3. **Filament-এর ভেতরে `HtmlString` / inline HTML** (setup guide, forecast card, billing table, modal ইত্যাদি): হেক্স কালার (`#0f172a` ইত্যাদি) নয়, শুধু `--sv-*` ভ্যারিয়েবল ব্যবহার করুন। উদাহরণ: `style='background:var(--sv-bg);border:1px solid var(--sv-border);color:var(--sv-text);'`
> 4. **Filament কম্পোনেন্ট / কাস্টম Blade ভিউ:** Tailwind-এর `dark:` ভ্যারিয়েন্ট ব্যবহার করুন (যেমন `bg-white dark:bg-gray-900`)।
> 5. **PDF ও ইমেইল টেমপ্লেট** সবসময় Light থাকবে — এগুলোতে থিম স্ক্রিপ্ট যুক্ত করবেন না।
> 6. প্রতিটি নতুন স্ক্রিন **Light ও Dark দুই মোডেই** টেস্ট করে তারপর মার্জ করুন।

**`--sv-*` ভ্যারিয়েবল রেফারেন্স (Filament inline HTML):**

| Variable | ব্যবহার | Light | Dark |
|---|---|---|---|
| `--sv-bg` | কার্ড/বক্সের ব্যাকগ্রাউন্ড | `#f8fafc` | `#0f172a` |
| `--sv-surface` | ভেতরের সারফেস, কোড ব্লক, টেবিল হেডার | `#f1f5f9` | `#1e293b` |
| `--sv-border` / `--sv-border-strong` | বর্ডার | `#e2e8f0` / `#cbd5e1` | `#334155` / `#475569` |
| `--sv-text` / `--sv-text-soft` / `--sv-text-dim` | প্রধান / সেকেন্ডারি / টারশিয়ারি টেক্সট | `#0f172a` / `#1e293b` / `#334155` | `#f8fafc` / `#e2e8f0` / `#cbd5e1` |
| `--sv-muted` | লেবেল ও হেল্প টেক্সট | `#64748b` | `#94a3b8` |
| `--sv-primary` / `--sv-primary-strong` | ব্র্যান্ড অ্যাকসেন্ট, লিংক | `#059669` / `#047857` | `#10b981` / `#059669` |
| `--sv-success` / `--sv-warning` / `--sv-danger` / `--sv-info` | স্ট্যাটাস রং | `#047857` / `#b45309` / `#dc2626` / `#0369a1` | `#34d399` / `#fbbf24` / `#f87171` / `#38bdf8` |
| `--sv-code` / `--sv-highlight` | কোড টেক্সট / ব্যাজ হাইলাইট | `#065f46` / `#92400e` | `#a7f3d0` / `#fde68a` |

---

## 2. Public Marketing Website Structure (`/`) — 100% CMS Driven & Interactive (`✅ REDESIGNED & COMPLETED`)

পাবলিক ওয়েবসাইটের প্রতিটি সেকশন আধুনিক SaaS স্ট্যান্ডার্ড অনুযায়ী অ্যানিমেটেড, মোবাইল রেসপন্সিভ এবং অ্যাডমিন প্যানেলের **Landing Page & Legal CMS Settings** থেকে ডাইনামিকভাবে নিয়ন্ত্রিত:
1. **Home / Landing Page (`/`):**
   - **Modern Header & Mobile Nav:** ব্র্যান্ড লোগো, ডাইনামিক `$appName`, মসৃণ ন্যাভিগেশন লিংক, গ্লোবাল থিম টগল (☀️/🌙/🖥️), "Sign In" ও "Start Free Trial" CTA এবং রেসপন্সিভ মোবাইল হ্যামবার্গার ড্রয়ার (লুকানো অ্যাডমিন লিঙ্ক বাদ দিয়ে সিকিউর করা হয়েছে)।
   - **Hero Section & Live Animated Pipeline Visualization:**
     - ডাইনামিক Hero Title, Subtitle, `$trialDays`-ডে ফ্রি ট্রায়াল ব্যাজ এবং অ্যাকশন বাটন।
     - **Live Animated Vector Backup Pipeline:** অরিজিন সার্ভার (MySQL, Web Files, cPanel) থেকে ডাটা কীভাবে SiteVaulta Core Engine (AES-256-GCM এনক্রিপশন ও SHA-256 চেকার)-এর মাধ্যমে কাস্টমারের নিজস্ব ক্লাউড স্টোরেজে (Google Drive, AWS S3, Cloudflare R2, Remote VPS) সুরক্ষিতভাবে স্ট্রিম হচ্ছে তার লাইভ নোড ও ড্যাশড প্যাকেট অ্যানিমেশন।
     - **Real-Time Live Status Simulator:** ব্যাকআপের বিভিন্ন ধাপ (Domain Guard -> Dumping DB -> AES-256 Encryption -> Cloud Upload -> SHA-256 Verified) স্বয়ংক্রিয়ভাবে প্রগ্রেস বারসহ লাইভ প্রদর্শন।
   - **Integration Marquee:** সমর্থিত ফ্রেমওয়ার্ক (Laravel, WordPress, Node.js, Django, cPanel) ও ক্লাউড স্টোরেজের একটানা মসৃণ মারকুই অ্যানিমেশন।
   - **Trust & Metric Highlights:** ব্যাকআপ সাকসেস রেট (99.98%), ০ এমবি প্লেনটেক্সট ও আপ টু ৮০% স্টোরেজ সেভিংস মেট্রিক কার্ড।
   - **How It Works (3 Simple Steps):** অরিজিন ভেরিফাই -> ক্লাউড স্টোরেজ কানেক্ট -> অটোমেশন ও অ্যালার্ট।
   - **Bento Feature Grid:** মাউস-ট্র্যাকিং রেডিয়াল গ্লো ইফেক্টযুক্ত ৬টি আধুনিক টেকনোলজি কার্ড।
   - **Interactive Live Pre-Backup Storage Forecaster:**
     - স্লাইডার দিয়ে ওয়েবসাইট ও ডাটাবেস সাইজ (GB) এবং রিটেনশন উইন্ডো (দিন) পরিবর্তন করে তাৎক্ষণিক Steady-State Cloud Storage হিসেব করা।
     - "Chain Incremental" বনাম "Standalone Full Backups" ভিজ্যুয়াল তুলনা এবং সেভিংস পার্সেন্টেজ বার।
   - **Developer Terminal Simulator:** ৩টি ইন্টারঅ্যাক্টিভ ট্যাব (1-Click Restore, CI/CD Pre-Deploy Webhook, SSRF & Verify) যেখানে রিয়েল শেল কমান্ড প্রিভিউ করা যায়।
   - **Dynamic Pricing Engine (`BDT ৳` & `USD $` + Monthly / Yearly Toggle):**
     - ডাটাবেসের `plans` টেবিল থেকে লাইভ লোড হওয়া প্ল্যানসমূহ।
     - কারেন্সি সুইচ (টাকা ৳ / ডলার $) এবং সাইকেল সুইচ (Monthly / Yearly) সহ রিয়েলটাইম ডিসকাউন্ট নোট।
     - সমর্থিত পেমেন্ট মেথড ব্যাজ (bKash, Nagad, Rocket, Cards, SSLCOMMERZ, Stripe, PayPal)।
   - **Interactive FAQ Accordion:** ক্লাউড স্টোরেজ, এনক্রিপশন ও ব্যাকআপ সংক্রান্ত প্রয়োজনীয় প্রশ্নের ড্রপডাউন উত্তর।
   - **Final Conversion CTA Banner:** গ্রেডিয়েন্ট শিফটিং অ্যানিমেশন ও স্ট্যাটাস ইন্ডিকেটরযুক্ত ফুটার।
2. **Assets:**
   - Stylesheet: `public/assets/landing/landing.css` (No-build pure CSS, Dark/Light theme aware, reduced-motion friendly)
   - JavaScript: `public/assets/landing/landing.js` (Vanilla JS, Intersection Observer, Forecaster & Pricing Calculator)
3. **Documentation (`/docs`) & Legal Pages (`/terms`, `/privacy`, `/refund-policy`)**।

---

## 3. Customer Panel (`/app`) — Role-Based User-Scoped Screens

এখানে কোনো জটিল টেন্যান্ট স্লাগ (`/app/{workspace}`) থাকবে না। ইউজার সরাসরি `/app`-এ লগইন করবে এবং `OwnedByUserScope` ও `Filament Shield`-এর মাধ্যমে শুধুমাত্র নিজের ডাটা দেখবে।

### Screen 1: Main Customer Dashboard (`/app`) (`✅ ENHANCED WITH ANIMATED REPORT WIDGETS`)

কাস্টমার প্যানেলের ড্যাশবোর্ডটি ডাইনামিক টেন্যান্ট-আইসোলেটেড আর্কিটেকচারে তৈরি; প্রতিটি ইউজার লগইন করলে শুধুমাত্র তার নিজস্ব ডেটা দেখতে পায়। একই সাথে ডেভেলপার টেস্ট বা ট্রায়ালের জন্য সমৃদ্ধ ডেমো ডাটা সিডার সংযুক্ত রয়েছে।

ড্যাশবোর্ডের ৪টি মূল লাইভ উইজেট:
1. **`WorkspaceHealthPulseWidget` (Hero Mission Control & Live Status Guard):**
   - **সবুজ পালসিং রাডার শিল্ড:** "Mission Control: All Systems Shielded" এবং লাইভ পালস ইন্ডিকেটর (সব ডোমেইন ভেরিফাইড ও সক্রিয় থাকলে)।
   - **লাইভ কাউন্টার:** ভেরিফাইড ওয়েবসাইট অনুপাত, অ্যাক্টিভ ব্যাকআপ পলিসি সংখ্যা, এবং পরবর্তী শিডিউল রানের অটো কাউন্টডাউন।
   - **রিয়েল-টাইম রানিং ব্যাকআপ স্ট্রিম ও লাইভ পার্সেন্টেজ (%) মিটার:** কোনো ব্যাকআপ চালু থাকলে লাইভ অ্যানিমেটেড প্রগ্রেস বার, সুনির্দিষ্ট অগ্রগতি শতকরা হার (`Queued: 5%`, `Connecting: 15%`, `Extracting: 40%`, `Packaging: 65%`, `Uploading: 85%`, `Verifying: 95%`, `Completed: 100%`) এবং চলমান স্টেজ লাইভ প্রতি ৩ সেকেন্ডে অটো-পোলিংয়ের মাধ্যমে প্রদর্শিত হয়। ইউজার ব্রাউজার বন্ধ করলেও সার্ভার ব্যাকগ্রাউন্ড কিউতে নির্বিঘ্নে ব্যাকআপ চলতে থাকে।
   - **সর্বশেষ রিকভারি পয়েন্ট:** সর্বশেষ সফল ব্যাকআপের ফাইলের সাইজ, ডিউরেশন এবং `SHA-256` হ্যাশ পিল।
   - **ফ্রেশ ইউজার অনবোর্ডিং কার্ড:** নতুন রেজিস্টার্ড ওয়ার্কস্পেসে (যেখানে এখনো সাইট যুক্ত হয়নি) ৩-ধাপের সুন্দর গাইড এবং ডেভেলপমেন্ট টেস্টিংয়ের জন্য **"✨ Populate Demo Data"** লাইভ বাটন।
2. **`WorkspaceOverviewWidget` (Compact Stats Grid):**
   - Verified Websites, Active Policies, Verified Backup Runs এবং Current Subscription Plan স্ট্যাটাস।
3. **`StorageSavingsRadarWidget` (Interactive Comparative Storage & Multi-Cloud Meter):**
   - **ভিজ্যুয়াল কম্প্যারিজন বার:** "Traditional Naive Daily Full Backups" (লাল/অ্যাম্বার বার) বনাম "SiteVaulta Smart Compressed Delta Chains" (সবুজ/সায়ান বার)।
   - **লাইভ সেভিংস ব্যাজ:** "⚡ Saved X GB (X%) Cloud Storage & Bandwidth via SiteVaulta Delta Chains"।
   - **মাল্টি-ক্লাউড ডেস্টিনেশন গ্রিড:** Google Drive, Amazon S3, Cloudflare R2 বা Remote VPS-এর ড্রাইভার আইকন, ভেরিফিকেশন টাইম এবং "Healthy" স্ট্যাটাস।
4. **`RecentBackupStreamWidget` (Live Recovery Points Stream):**
   - সাম্প্রতিক ব্যাকআপ রানের লাইভ টেবিল।
   - স্ট্যাটাস পালসিং ডট (🟢 Completed, 🟡 In Progress, 🔴 Failed), টার্গেট ডোমেইন ও স্কোপ ব্যাজ, কম্প্রেসড সাইজ, ডিউরেশন, ট্রাংকেটেড `SHA-256` ক্রিপ্টোগ্রাফিক হ্যাশ এবং ১-ক্লিক রিস্টোর গাইড লিঙ্ক।
5. **ডেমো ডাটা সিডার টুলস:**
   - কমান্ড: `php artisan sitevaulta:seed-demo {workspace?}` অথবা `php artisan sitevaulta:seed-demo --all`।
   - ৩টি বাস্তবসম্মত ওয়েবসাইট (`shop-fashionbd.com`, `api.travelmatrix.io`, `crm.fintechcloud.net`), ৩টি ক্লাউড স্টোরেজ ড্রাইভার এবং ফুল ও ইনক্রিমেন্টাল ব্যাকআপ চেইন হিস্ট্রি তৈরি করে।

### Screen 2: Websites & 3-Step Server Connection Wizard (`/app/websites`)
* **Step 1:** Website Info (`Name`, `URL`, `Environment`, `Estimated Size`)।
* **Step 2:** Domain Ownership Verification (`DNS TXT` | `HTML File` | `Meta Tag` + `"Verify Now"` বাটন)।
* **Step 3:** Universal Server & Database Connection (শুধুমাত্র অ্যাডমিন সেটিংসে চালু থাকা প্রোটোকলগুলো অপশন হিসেবে আসবে: `SSH/SFTP`, `FTP/FTPS`, `cPanel API`, `Vaultix Agent` + `MySQL/MariaDB/PostgreSQL` with SSH Tunnel + **`[⚡ Test Connection]`** বাটন)।

### Screen 3: Storage Connections (`/app/storage-connections`)
* অ্যাডমিন সেটিংসে অনুমোদিত স্টোরেজ ড্রাইভারসমূহ (`Google Drive`, `Amazon S3`, `Cloudflare R2`, `S3-Compatible`, `SFTP Storage`) + **`[Test Write & Verify]`** বাটন।

### Screen 4: Backup Policies & Live Storage Forecaster (`/app/backup-policies`)
* **Left Column:** পলিসি কনফিগারেশন ফর্ম। এখানে Exclude Paths, Retention Days, Encryption ও Download Mode-এর ডিফল্ট ভ্যালুগুলো ইউজারের নিজস্ব **Dynamic User Settings (`/app/settings`)** থেকে অটো-ফিল হবে!
* **Right Column:** **Live Storage & Transfer Forecast Card**—ফর্ম পরিবর্তনের সাথে সাথে সম্ভাব্য ব্যাকআপ সংখ্যা ও মোট স্টোরেজ (GB) ক্যালকুলেট করে দেখাবে।

### Screen 5: Backup History, Secure Download & Restore Guide (`/app/backup-runs`)
* প্রতিটি ব্যাকআপের স্ট্যাটাস, `SHA-256` ভেরিফিকেশন ব্যাজ, **`[⬇️ Secure Download]`** মডাল (`One-Time Token` / `Presigned URL`) এবং **`[📖 View Restore Guide]`** স্লাইড-ওভার ড্রয়ার।

### Screen 6: Customer Dynamic Settings Hub (`/app/settings`)
ইউজারের নিজস্ব ডাইনামিক সেটিংস পেজ (৪টি ট্যাব):
1. **Tab 1 — Regional & Security:** Timezone, Preferred Currency (`BDT`/`USD`), Language, 2FA Setup।
2. **Tab 2 — My Default Backup Rules:** নতুন পলিসির জন্য ডিফল্ট Exclude Folders, Default Retention Days, Default Encryption Toggle, Default Download Mode।
3. **Tab 3 — Notification Preferences:** কোন ইভেন্টে কোন চ্যানেলে অ্যালার্ট যাবে এবং Quiet Hours।
4. **Tab 4 — Agency White-Label Branding:** ক্লায়েন্ট পিডিএফ রিপোর্টের জন্য এজেন্সি লোগো, নাম, কালার ও কাস্টম ফুটার।

### Screen 7: Sub-Users & Team Permissions (`/app/team`)
* Pro ও Agency প্ল্যানের ইউজাররা নিজেদের টিম মেম্বার যোগ করে `Operator` বা `Viewer` রোল দিতে পারবে।

### Screen 8: Subscription Validity & SaaS Renewal/Extension Engine (`/app/billing`)
* **Live Subscription & Validity Indicator:**
  - প্রতিটি সাবস্ক্রিপশনের মেয়াদ শেষ হওয়ার তারিখ (`Expiry Date / Renewal Date`) এবং বাকি থাকা দিন (`Remaining Days` countdown) ড্যাশবোর্ডের ওভারভিউ কার্ড, হেলথ পালস উইজেট এবং বিলিং পেজে সুস্পষ্টভাবে প্রদর্শিত হয়।
  - মেয়াদ শেষ হওয়ার ৭ দিন আগে থেকে স্বয়ংক্রিয় Warning Alert (`⚠️ Attention: Expiring Soon`) প্রদর্শন করে ইউজারকে সতর্ক করা হয়।
* **Standard SaaS Renewal Window & Duplicate Charge Prevention:**
  - **Renewal Window Policy (ইন্ডাস্ট্রি স্ট্যান্ডার্ড):** কোনো ইউজারের সক্রিয় প্ল্যানে ৭ দিনের বেশি মেয়াদ বাকি থাকলে সিস্টেম তাকে ভুলবশত একই প্যাকেজ পুনরায় কেনা থেকে বিরত রাখে (`🛡️ Active Subscription in Good Standing`) এবং অতিরিক্ত কোটার প্রয়োজনে তাকে **Plan Upgrade** করার পরামর্শ দেয়।
  - মেয়াদ শেষ হতে ৭ দিন বা তার কম বাকি থাকলে (অথবা ট্রায়াল/গ্রেস পিরিয়ডে) রিনিউয়াল উইন্ডো সক্রিয় হয়ে যায় এবং ইউজার নিরাপদে **Period Extension** করতে পারে।
  - **Period Extension (একই প্যাকেজ রিনিউ):** রিনিউয়াল উইন্ডোতে রিনিউ করলে বর্তমান অবশিষ্ট দিন নষ্ট না হয়ে `current_period_ends_at` থেকে পরবর্তী ১ মাস বা ১ বছর যোগ হয়ে মেয়াদ বাড়ে।
  - **Plan Upgrade / Switch:** ইউজার যেকোনো সময় উচ্চতর প্যাকেজে আপগ্রেড করতে পারে, যা অবিলম্বে কার্যকর হয়।
  - **Billing Cycle Switch:** যেকোনো সময় মান্থলি থেকে ইয়ার্লি সুইচ করা যায় এবং ২০% পর্যন্ত ছাড় পাওয়া যায়।
  - **Dynamic Admin Setting:** অ্যাডমিন প্যানেল (`/admin/settings` → Trial & Billing Rules) থেকে `renewal_window_days` ডায়নামিকভাবে পরিবর্তন করা যায় (ডিফল্ট ৭ দিন)।
* **Multi-Gateway Hosted Checkout (Sandbox & Live Redirection):**
  - **SSLCOMMERZ (BDT):** অফিসিয়াল `sandbox.sslcommerz.com` ও `securepay.sslcommerz.com`-এ সরাসরি রিডাইরেক্ট করে।
  - **Stripe (USD):** অফিসিয়াল `checkout.stripe.com`-এ রিয়েল হোস্টেড সেশন তৈরি করে সরাসরি Stripe Checkout পেজে নিয়ে যায় (স্যান্ডবক্স `sk_test_...` বা লাইভ `sk_live_...` কি দিয়ে)।
  - **PayPal (USD):** অফিসিয়াল PayPal v2 Orders API দিয়ে `sandbox.paypal.com` বা `paypal.com`-এর Approval পেজে সরাসরি রিডাইরেক্ট করে।
  - গেটওয়ে ক্রেডেনশিয়াল এখনো সেট না থাকলে নিরাপদ লোকাল সিমুলেশন চেকআউটে ফলব্যাক করে। কুপন ডিসকাউন্ট ব্রেকডাউন এবং তাত্ক্ষণিক ইনভয়েস পিডিএফ ভিউ/ডাউনলোড সুবিধা।

---

## 4. Super Admin & Staff Panel (`/admin`) — Powered by Filament 5 + Filament Shield

1. **Executive Overview Dashboard (`/admin`):**
   - গ্লোবাল রেভিনিউ (BDT & USD), অ্যাক্টিভ সাবস্ক্রিপশন, ট্রায়াল ইউজার, গ্লোবাল ব্যাকআপ সাকসেস রেট ও কিউ ওয়ার্কার হেলথ।
2. **Roles & Permissions Manager (`/admin/shield/roles` — Filament Shield):**
   - `super_admin` ছাড়াও যত খুশি কাস্টম রোল (`Support Admin`, `Billing Manager`, `Auditor`) তৈরি এবং প্রতিটির জন্য আলাদাভাবে `View`, `Create`, `Edit`, `Delete` পারমিশন চেকবক্স।
3. **Global Dynamic System Settings (`/admin/settings`):**
   - কোডে হাত না দিয়েই ৮টি ট্যাবে পুরো সিস্টেম নিয়ন্ত্রণ:
     1. `General & Branding`
     2. `Trial & Subscription Rules` (Trial days, Grace period, Tax %)
     3. `Backup Engine & Forecast Ratios` (Compression ratio, Delta rate, Timeouts, Retries, Anomaly threshold %)
     4. `Allowed Protocols & Storage Drivers Toggle`
     5. `Payment Gateways` (`SSLCOMMERZ`, `Stripe`, `PayPal` Live/Sandbox keys & status)
     6. `SMTP & Google OAuth Credentials` (সাথে `"Send Test Email"` বাটন)
     7. `Download Token & Security Rules` (Token expiry minutes, Max retries, SSRF rules)
     8. `Landing Page & Legal CMS`
4. **Dynamic Email Templates (`/admin/email-templates`):**
   - সব ইমেইল ও নোটিফিকেশনের Subject ও Body ভ্যারিয়েবলসহ এডিট করার পেজ।
5. **Users, Subscriptions, Plans, Coupons, Global Backup Monitor & Audit Logs**।
