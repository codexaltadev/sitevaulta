<?php

use App\Http\Controllers\BillingCheckoutController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\GoogleDriveOAuthController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Controllers\WebsiteClientReportController;
use App\Models\Plan;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    $plans = Plan::query()
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get();

    $trialDays = (int) SystemSettingsService::get('trial_billing', 'default_trial_days', 7);

    $heroHeading = SystemSettingsService::get(
        'landing_cms',
        'hero_title',
        SystemSettingsService::get(
            'cms_landing',
            'hero_heading',
            'Automated Server & Database Backups to Your Own Cloud Storage'
        )
    );

    $heroSubheading = SystemSettingsService::get(
        'landing_cms',
        'hero_subtitle',
        SystemSettingsService::get(
            'cms_landing',
            'hero_subheading',
            '100% Framework-Agnostic. Connect any SSH/SFTP, FTP, or cPanel server + MySQL/PostgreSQL and stream encrypted, SHA-256 verified backups to Google Drive, S3, Cloudflare R2, or Remote VPS.'
        )
    );

    $ctaPrimaryText = SystemSettingsService::get(
        'landing_cms',
        'cta_primary_text',
        "Get Started — Free for {$trialDays} Days"
    );

    return view('welcome', [
        'appName' => SystemSettingsService::get('general', 'app_name', 'SiteVaulta'),
        'tagline' => SystemSettingsService::get('general', 'tagline', 'Your Sites. Your Storage. Your Control.'),
        'heroHeading' => $heroHeading,
        'heroSubheading' => $heroSubheading,
        'ctaPrimaryText' => $ctaPrimaryText,
        'supportEmail' => SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com'),
        'supportPhone' => SystemSettingsService::get('general', 'support_phone', ''),
        'footerText' => SystemSettingsService::get('general', 'footer_text', 'Enterprise-grade server and database backup automation with zero storage lock-in. Stream encrypted backups directly to your own cloud.'),
        'logoLightUrl' => SystemSettingsService::get('general', 'logo_light_url', ''),
        'logoDarkUrl' => SystemSettingsService::get('general', 'logo_dark_url', ''),
        'faviconUrl' => SystemSettingsService::get('general', 'favicon_url', ''),
        'trialDays' => $trialDays,
        'plans' => $plans,
    ]);
})->name('home');

Route::get('/docs', function () {
    return view('docs', [
        'appName' => SystemSettingsService::get('general', 'app_name', 'SiteVaulta'),
        'supportEmail' => SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com'),
        'faviconUrl' => SystemSettingsService::get('general', 'favicon_url', ''),
    ]);
})->name('docs');

Route::get('/terms', function () {
    $appName = (string) SystemSettingsService::get('general', 'app_name', 'SiteVaulta');

    return view('legal', [
        'appName' => $appName,
        'supportEmail' => SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com'),
        'faviconUrl' => SystemSettingsService::get('general', 'favicon_url', ''),
        'activeTab' => 'terms',
        'pageTitle' => 'Terms of Service',
        'content' => SystemSettingsService::get(
            'landing_cms',
            'terms_content',
            "1. Acceptance of Terms\nBy registering a workspace on {$appName}, you agree to abide by these Terms of Service. You must verify ownership of any domain before connecting server credentials or running automated backups.\n\n2. Customer-Owned Storage (BYOS)\n{$appName} operates on a Bring-Your-Own-Storage architecture. Backup archives are streamed through ephemeral worker staging and uploaded directly to your connected Google Drive, Amazon S3, Cloudflare R2, or SFTP storage.\n\n3. Acceptable Use & Security\nYou may only connect servers and databases that you own or are explicitly authorized to administer. SSRF protections and domain ownership verification are strictly enforced."
        ),
    ]);
})->name('legal.terms');

Route::get('/privacy', function () {
    $appName = (string) SystemSettingsService::get('general', 'app_name', 'SiteVaulta');

    return view('legal', [
        'appName' => $appName,
        'supportEmail' => SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com'),
        'faviconUrl' => SystemSettingsService::get('general', 'favicon_url', ''),
        'activeTab' => 'privacy',
        'pageTitle' => 'Privacy Policy',
        'content' => SystemSettingsService::get(
            'landing_cms',
            'privacy_content',
            "1. Zero Permanent Plaintext Retention\n{$appName} uses ephemeral worker directories (/tmp/sitevaulta) during backup packaging and encryption. Temporary staging directories are automatically wiped in the job finally block immediately after upload.\n\n2. Credential Encryption at Rest\nAll SSH keys, FTP passwords, database credentials, and cloud storage tokens are encrypted at rest in our database using AES-256 encryption.\n\n3. Optional AES-256-GCM Archive Encryption\nWhen archive encryption is enabled on a backup policy, archives are encrypted before leaving the worker so your cloud storage provider never sees plaintext data."
        ),
    ]);
})->name('legal.privacy');

Route::get('/refund-policy', function () {
    $appName = (string) SystemSettingsService::get('general', 'app_name', 'SiteVaulta');
    $trialDays = (int) SystemSettingsService::get('trial_billing', 'default_trial_days', 7);

    return view('legal', [
        'appName' => $appName,
        'supportEmail' => SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com'),
        'faviconUrl' => SystemSettingsService::get('general', 'favicon_url', ''),
        'activeTab' => 'refund',
        'pageTitle' => 'Refund & Cancellation Policy',
        'content' => SystemSettingsService::get(
            'landing_cms',
            'refund_policy_content',
            "1. {$trialDays}-Day Free Trial\nEvery new workspace receives a full-featured {$trialDays}-day free trial with no credit card required so you can evaluate server connectors, storage adapters, and restore workflows risk-free.\n\n2. Subscription Renewals & Duplicate Charge Protection\nOur billing engine includes a renewal window guard to prevent accidental duplicate purchases of the same active plan. Same-plan renewals extend your existing period without losing remaining days.\n\n3. Refund Requests\nIf you experience a verified technical issue that prevents backup execution and our engineering support team cannot resolve it within 7 business days of your initial charge, contact support for a full refund review."
        ),
    ]);
})->name('legal.refund-policy');

// Secure Backup Archive Downloads (One-Time Atomic Token & Time-Limited Signed URL)
Route::get('/download/one-time/{token}', [DownloadController::class, 'downloadOneTime'])
    ->name('artifacts.one-time-download');

Route::get('/download/signed/{artifactUuid}', [DownloadController::class, 'downloadSigned'])
    ->middleware('signed')
    ->name('artifacts.signed-download');

// Multi-Gateway Sandbox / Live Checkout Portal & Invoice PDF
Route::get('/billing/checkout/{paymentUuid}', [BillingCheckoutController::class, 'showCheckout'])
    ->name('billing.checkout.show');

Route::match(['get', 'post'], '/billing/checkout/{paymentUuid}/complete', [BillingCheckoutController::class, 'completeCheckout'])
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        PreventRequestForgery::class,
    ])
    ->name('billing.checkout.complete');

Route::match(['get', 'post'], '/billing/checkout/{paymentUuid}/cancel', [BillingCheckoutController::class, 'cancelCheckout'])
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        PreventRequestForgery::class,
    ])
    ->name('billing.checkout.cancel');

Route::get('/billing/invoice/{paymentUuid}/view', [BillingCheckoutController::class, 'viewInvoice'])
    ->middleware('auth')
    ->name('billing.invoice.view');

Route::get('/billing/invoice/{paymentUuid}/pdf', [BillingCheckoutController::class, 'downloadInvoicePdf'])
    ->middleware('auth')
    ->name('billing.invoice.pdf');

// White-Label Client Executive Maintenance & Security Report (Web Preview & A4 PDF Download)
Route::get('/app/{workspaceSlug}/websites/{websiteUuid}/client-report', [WebsiteClientReportController::class, 'viewReport'])
    ->middleware('auth')
    ->name('websites.client-report.view');

Route::get('/app/{workspaceSlug}/websites/{websiteUuid}/client-report/pdf', [WebsiteClientReportController::class, 'downloadPdf'])
    ->middleware('auth')
    ->name('websites.client-report.pdf');

// Google Drive OAuth 2.0 Redirect & Callback (Supports both /vaultix/auth/google/callback and /auth/google/callback)
Route::get('/auth/google/redirect', [GoogleDriveOAuthController::class, 'redirect'])
    ->name('google.oauth.redirect');
Route::get('/vaultix/auth/google/redirect', [GoogleDriveOAuthController::class, 'redirect'])
    ->name('vaultix.google.oauth.redirect');
Route::get('/auth/google/callback', [GoogleDriveOAuthController::class, 'callback'])
    ->name('google.oauth.callback');
Route::get('/vaultix/auth/google/callback', [GoogleDriveOAuthController::class, 'callback'])
    ->name('vaultix.google.oauth.callback');

// Referral Link Tracking Route (/r/{code} or /ref/{code})
Route::get('/r/{code}', function (string $code) {
    $clean = strtoupper(trim($code));
    session(['sitevaulta_referral_code' => $clean]);
    cookie()->queue('sitevaulta_referral_code', $clean, 60 * 24 * 30);

    return redirect('/app/register?ref=' . urlencode($clean));
})->name('referral.redirect');

// Multi-Gateway Billing Webhooks (SSLCOMMERZ BDT, Stripe USD, PayPal USD)
Route::post('/webhooks/sslcommerz/ipn', [PaymentWebhookController::class, 'handleSslCommerzIpn'])
    ->name('webhooks.sslcommerz.ipn');

Route::post('/webhooks/stripe', [PaymentWebhookController::class, 'handleStripeWebhook'])
    ->name('webhooks.stripe');

Route::post('/webhooks/paypal', [PaymentWebhookController::class, 'handlePayPalWebhook'])
    ->name('webhooks.paypal');
