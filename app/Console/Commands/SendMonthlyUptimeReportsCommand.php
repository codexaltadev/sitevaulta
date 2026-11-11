<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Monitoring\MonthlyUptimeReport;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Settings\WorkspaceSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SendMonthlyUptimeReportsCommand extends Command
{
    protected $signature = 'sitevaulta:send-monthly-uptime-reports {--month= : YYYY-MM to report on (default: last month)} {--force : Send again even if this month was already sent}';

    protected $description = 'Email every workspace its monthly uptime report (uptime %, incidents, downtime, response time per monitor).';

    public function handle(MonthlyUptimeReport $reports): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('month').'-01', 'UTC')->startOfMonth()
            : CarbonImmutable::now('UTC')->subMonthNoOverflow()->startOfMonth();
        $monthKey = $month->format('Y-m');
        $sent = 0;

        Workspace::query()
            ->whereHas('monitors')
            ->with('owner')
            ->lazyById(50)
            ->each(function (Workspace $workspace) use ($reports, $month, $monthKey, &$sent): void {
                if (! WorkspaceSettingsService::get($workspace, 'reports', 'monthly_uptime_report', true)) {
                    return;
                }

                // Once per month per workspace, even if the scheduler runs twice.
                if (! $this->option('force') && WorkspaceSettingsService::get($workspace, 'reports', 'monthly_uptime_report_last_sent') === $monthKey) {
                    return;
                }

                $recipient = WorkspaceSettingsService::get($workspace, 'reports', 'monthly_uptime_report_email') ?: $workspace->owner?->email;

                if (blank($recipient)) {
                    return;
                }

                try {
                    $data = $reports->build($workspace, $month);
                    $html = view('reports.monthly-uptime', [
                        ...$data,
                        'dashboardUrl' => route('filament.app.resources.monitors.index', ['tenant' => $workspace->slug]),
                    ])->render();

                    $result = WorkspaceAlertDispatcher::dispatchEmailWithFallback(
                        workspace: $workspace,
                        recipientEmail: (string) $recipient,
                        subject: "Uptime report for {$month->format('F Y')}",
                        htmlBody: $html,
                        ledgerDescription: 'Monthly uptime report',
                        reference: $workspace,
                        metadata: ['month' => $monthKey],
                    );
                } catch (Throwable $exception) {
                    report($exception);

                    return;
                }

                if ($result['sent'] ?? false) {
                    WorkspaceSettingsService::set($workspace, 'reports', 'monthly_uptime_report_last_sent', $monthKey);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} monthly uptime reports for {$monthKey}");

        return self::SUCCESS;
    }
}
