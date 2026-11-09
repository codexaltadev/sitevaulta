<?php

namespace App\Http\Controllers;

use App\Enums\BackupRunStatus;
use App\Models\BackupRun;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Security\WebsiteSecurityScannerService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WebsiteClientReportController extends Controller
{
    public function viewReport(Request $request, string $workspaceSlug, string $websiteUuid): View
    {
        $data = $this->buildReportViewData($request, $workspaceSlug, $websiteUuid, isWebPreview: true);

        return view('reports.website-client-report', $data);
    }

    public function downloadPdf(Request $request, string $workspaceSlug, string $websiteUuid)
    {
        $data = $this->buildReportViewData($request, $workspaceSlug, $websiteUuid, isWebPreview: false);
        /** @var Website $website */
        $website = $data['website'];

        $filename = 'Maintenance-Report-'.Str::slug($website->domain ?: $website->name).'-'.now()->format('Y-m-d').'.pdf';

        $pdf = Pdf::loadView('reports.website-client-report', $data)
            ->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $filename
        );
    }

    protected function buildReportViewData(
        Request $request,
        string $workspaceSlug,
        string $websiteUuid,
        bool $isWebPreview
    ): array {
        $workspace = Workspace::where('slug', $workspaceSlug)->firstOrFail();

        $user = $request->user();
        if ($user && ! $user->can_access_admin_panel && ! $user->canAccessTenant($workspace)) {
            abort(403, 'Unauthorized access to this workspace client report.');
        }

        $website = Website::with(['serverConnections', 'backupPolicies'])
            ->where('workspace_id', $workspace->id)
            ->where(function ($q) use ($websiteUuid): void {
                $q->where('uuid', $websiteUuid)->orWhere('id', $websiteUuid);
            })
            ->firstOrFail();

        // Ensure fresh telemetry if never checked
        if (! $website->last_uptime_checked_at) {
            WebsiteUptimeMonitorService::checkWebsiteLiveStatus($website);
            $website->refresh();
        }
        if (! $website->last_security_scan_at) {
            WebsiteSecurityScannerService::scanWebsiteAndServer($website, sendAlertIfThreatsFound: false);
            $website->refresh();
        }

        $periodDays = (int) $request->query('period', 30);
        if (! in_array($periodDays, [7, 30, 90], true)) {
            $periodDays = 30;
        }

        $intel = WebsiteUptimeMonitorService::inspectSiteAndServerIntelligence($website);

        $periodStart = now()->subDays($periodDays);
        $periodRunsQuery = BackupRun::with('backupPolicy')
            ->where('website_id', $website->id)
            ->where('created_at', '>=', $periodStart);

        $periodCompletedCount = (clone $periodRunsQuery)->where('status', BackupRunStatus::Completed)->count();
        $periodFailedCount = (clone $periodRunsQuery)->where('status', BackupRunStatus::Failed)->count();
        $recentRuns = BackupRun::with('backupPolicy')
            ->where('website_id', $website->id)
            ->latest('created_at')
            ->limit(15)
            ->get();

        // White-label Agency Branding
        $whitelabel = $workspace->whitelabel_config ?? [];
        $agencyName = trim((string) ($workspace->report_brand_name ?: ($whitelabel['company_name'] ?? $workspace->name)));
        $agencyLogoUrl = trim((string) ($workspace->report_brand_logo_url ?: ($whitelabel['logo_url'] ?? '')));
        $agencyColor = trim((string) ($whitelabel['brand_color'] ?? '#0d9488'));
        $agencySupportEmail = trim((string) ($whitelabel['support_email'] ?? ($workspace->owner?->email ?: SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com'))));
        $agencyFooter = trim((string) ($workspace->report_footer_note ?: ($whitelabel['report_footer'] ?? "Prepared by {$agencyName} • Automated Website Backup, Uptime & Read-Only Security Audit Report")));

        return [
            'isWebPreview' => $isWebPreview,
            'workspace' => $workspace,
            'website' => $website,
            'intel' => $intel,
            'periodDays' => $periodDays,
            'periodStart' => $periodStart,
            'periodCompletedCount' => $periodCompletedCount,
            'periodFailedCount' => $periodFailedCount,
            'recentRuns' => $recentRuns,
            'agencyName' => $agencyName,
            'agencyLogoUrl' => $agencyLogoUrl,
            'agencyColor' => $agencyColor,
            'agencySupportEmail' => $agencySupportEmail,
            'agencyFooter' => $agencyFooter,
            'reportReference' => 'RPT-'.strtoupper( substr(md5($website->uuid.now()->format('Ymd')), 0, 8) ),
        ];
    }
}

