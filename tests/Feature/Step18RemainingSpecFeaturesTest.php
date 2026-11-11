<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ManageSystemSettings;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\BackupRunAdmins\BackupRunAdminResource;
use App\Filament\Admin\Resources\Coupons\CouponResource;
use App\Filament\Admin\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Filament\Admin\Resources\Plans\Schemas\PlanForm;
use App\Filament\Admin\Resources\Plans\Tables\PlansTable;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\Workspaces\WorkspaceResource;
use App\Filament\App\Pages\Tenancy\EditWorkspaceSettings;
use App\Filament\App\Resources\BackupPolicies\BackupPolicyResource;
use App\Filament\App\Resources\BackupRuns\BackupRunResource;
use App\Filament\App\Resources\BackupTemplates\BackupTemplateResource;
use App\Filament\App\Resources\ClientReports\ClientReportResource;
use App\Filament\App\Resources\NotificationChannels\NotificationChannelResource;
use App\Filament\App\Resources\ServerConnections\ServerConnectionResource;
use App\Filament\App\Resources\StorageConnections\StorageConnectionResource;
use App\Filament\App\Resources\Websites\Schemas\WebsiteForm;
use App\Filament\App\Resources\Websites\Tables\WebsitesTable;
use App\Filament\App\Resources\Websites\WebsiteResource;
use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\SsrfValidator;
use App\Services\Settings\SystemSettingsService;
use App\Services\Settings\WorkspaceSettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class Step18RemainingSpecFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_legal_pages_and_dynamic_landing_cms_render(): void
    {
        $this->seed();

        SystemSettingsService::set('landing_cms', 'hero_title', 'Custom Enterprise Vault Headline', 'string', false, true);
        SystemSettingsService::set('landing_cms', 'hero_subtitle', 'Custom dynamic subheading for multi-cloud streaming.', 'text', false, true);
        SystemSettingsService::set('landing_cms', 'cta_primary_text', 'Launch Free Enterprise Trial', 'string', false, true);
        SystemSettingsService::set('general', 'support_phone', '+880 1899-112233', 'string', false, true);
        SystemSettingsService::set('general', 'footer_text', 'Custom Dynamic Footer Tagline 2026', 'text', false, true);
        SystemSettingsService::set('landing_cms', 'terms_content', 'Custom Terms Section 99: Verified Domains Only.', 'text', false, true);
        SystemSettingsService::set('landing_cms', 'privacy_content', 'Custom Privacy Clause: Zero Plaintext Disk Storage.', 'text', false, true);
        SystemSettingsService::set('landing_cms', 'refund_policy_content', 'Custom 7-Day Refund Guarantee Clause.', 'text', false, true);

        $home = $this->get('/');
        $home->assertStatus(200);
        $home->assertSee('Custom Enterprise Vault Headline');
        $home->assertSee('Custom dynamic subheading for multi-cloud streaming.');
        $home->assertSee('Launch Free Enterprise Trial');
        $home->assertSee('+880 1899-112233');
        $home->assertSee('Custom Dynamic Footer Tagline 2026');
        $home->assertSee('/terms');
        $home->assertSee('/privacy');
        $home->assertSee('/refund-policy');

        $terms = $this->get('/terms');
        $terms->assertStatus(200);
        $terms->assertSee('Terms of Service');
        $terms->assertSee('Custom Terms Section 99: Verified Domains Only.');

        $privacy = $this->get('/privacy');
        $privacy->assertStatus(200);
        $privacy->assertSee('Privacy Policy');
        $privacy->assertSee('Custom Privacy Clause: Zero Plaintext Disk Storage.');

        $refund = $this->get('/refund-policy');
        $refund->assertStatus(200);
        $refund->assertSee('Refund & Cancellation Policy');
        $refund->assertSee('Custom 7-Day Refund Guarantee Clause.');
    }

    public function test_admin_system_settings_saves_new_fields_and_sends_test_email(): void
    {
        Mail::fake();
        $this->seed();

        $admin = User::where('email', 'admin@sitevaulta.com')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ManageSystemSettings::class)
            ->fillForm([
                'support_phone' => '+880 1711-223344',
                'footer_text' => 'Updated Global Footer Text',
                'logo_light_url' => '/assets/custom-light.svg',
                'logo_dark_url' => '/assets/custom-dark.svg',
                'favicon_url' => '/assets/custom-favicon.ico',
                'expired_metadata_retention_days' => 45,
                'retry_backoff_seconds' => 120,
                'enforce_2fa' => true,
                'ssrf_custom_blocked_ips' => ['203.0.113.99', 'blocked.internal.example'],
                'hero_title' => 'Saved Hero Title From Admin',
                'hero_subtitle' => 'Saved Hero Subtitle From Admin',
                'cta_primary_text' => 'Start Instant Trial Now',
                'terms_content' => 'Updated Terms From Admin CMS',
                'privacy_content' => 'Updated Privacy From Admin CMS',
                'refund_policy_content' => 'Updated Refund Policy From Admin CMS',
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->call('sendTestEmail', 'ops-check@sitevaulta.com');

        $this->assertEquals('+880 1711-223344', SystemSettingsService::get('general', 'support_phone'));
        $this->assertEquals('/assets/custom-light.svg', SystemSettingsService::get('general', 'logo_light_url'));
        $this->assertEquals(45, SystemSettingsService::get('trial_billing', 'expired_metadata_retention_days'));
        $this->assertEquals(120, SystemSettingsService::get('backup_engine', 'retry_backoff_seconds'));
        $this->assertTrue((bool) SystemSettingsService::get('security', 'enforce_2fa'));
        $this->assertContains('203.0.113.99', SystemSettingsService::get('security', 'ssrf_custom_blocked_ips'));
        $this->assertEquals('Updated Terms From Admin CMS', SystemSettingsService::get('landing_cms', 'terms_content'));

        $job = new ExecuteBackupRunJob(backupPolicyId: 1);
        $this->assertEquals(120, $job->backoff);

        $this->assertTrue(
            AuditLog::where('event', 'system.smtp.test_sent')->exists()
        );
    }

    public function test_custom_ssrf_blocklist_blocks_configured_hosts_and_ips(): void
    {
        $this->seed();

        SystemSettingsService::set('security', 'ssrf_custom_blocked_ips', ['203.0.113.55', 'forbidden.example.org'], 'json');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('custom admin blocklist');

        SsrfValidator::assertSafeHost('https://203.0.113.55/backup');
    }

    public function test_customer_workspace_settings_saves_2fa_date_format_quiet_hours_and_agency_logo(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@sitevaulta.com')->firstOrFail();
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();

        $this->assertNull($user->two_factor_secret);

        Auth::login($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($workspace);

        Livewire::actingAs($user)
            ->test(EditWorkspaceSettings::class)
            ->fillForm([
                'name' => 'Demo Web Agency',
                'slug' => 'demo-agency',
                'billing_currency' => 'USD',
                'timezone' => 'UTC',
                'date_format' => 'Y-m-d H:i',
                'enable_2fa' => true,
                'quiet_hours_enabled' => true,
                'quiet_hours_start' => '00:00',
                'quiet_hours_end' => '23:59',
                'agency_company_name' => 'Apex Cloud Agency',
                'agency_logo_url' => 'https://agency.example.com/logo.png',
                'agency_brand_color' => '#059669',
                'agency_support_email' => 'ops@apexagency.example.com',
                'agency_report_footer' => 'Confidential Client Backup Audit',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $workspace->refresh();

        $this->assertNotEmpty($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertEquals('Y-m-d H:i', WorkspaceSettingsService::get($workspace, 'regional', 'date_format'));
        $this->assertTrue((bool) WorkspaceSettingsService::get($workspace, 'security', 'require_2fa'));
        $this->assertTrue((bool) WorkspaceSettingsService::get($workspace, 'notifications', 'quiet_hours_enabled'));
        $this->assertEquals('https://agency.example.com/logo.png', $workspace->whitelabel_config['logo_url'] ?? null);

        // Quiet hours active (00:00 to 23:59): non-critical success alert is muted, but critical failure alert is NOT muted
        $this->assertTrue(WorkspaceAlertDispatcher::isQuietHoursMuted($workspace, 'backup_succeeded'));
        $this->assertFalse(WorkspaceAlertDispatcher::isQuietHoursMuted($workspace, 'backup_failed'));
    }

    public function test_filament_v5_resources_and_separated_schemas_and_tables_are_discovered(): void
    {
        $appResources = Filament::getPanel('app')->getResources();
        $adminResources = Filament::getPanel('admin')->getResources();

        $this->assertContains(WebsiteResource::class, $appResources);
        $this->assertContains(ServerConnectionResource::class, $appResources);
        $this->assertContains(StorageConnectionResource::class, $appResources);
        $this->assertContains(BackupTemplateResource::class, $appResources);
        $this->assertContains(BackupPolicyResource::class, $appResources);
        $this->assertContains(BackupRunResource::class, $appResources);
        $this->assertContains(NotificationChannelResource::class, $appResources);
        $this->assertContains(ClientReportResource::class, $appResources);

        $this->assertContains(WorkspaceResource::class, $adminResources);
        $this->assertContains(PlanResource::class, $adminResources);
        $this->assertContains(CouponResource::class, $adminResources);
        $this->assertContains(PaymentResource::class, $adminResources);
        $this->assertContains(UserResource::class, $adminResources);
        $this->assertContains(BackupRunAdminResource::class, $adminResources);
        $this->assertContains(EmailTemplateResource::class, $adminResources);
        $this->assertContains(AuditLogResource::class, $adminResources);

        $this->assertTrue(class_exists(WebsiteForm::class));
        $this->assertTrue(class_exists(WebsitesTable::class));
        $this->assertTrue(class_exists(PlanForm::class));
        $this->assertTrue(class_exists(PlansTable::class));
    }
}
