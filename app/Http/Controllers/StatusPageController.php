<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Http\Requests\UnlockStatusPageRequest;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\StatusPage;
use App\Services\Monitoring\MonitorUptimeStatistics;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Public, unauthenticated status page. Shows monitor names and health only — never URLs, hosts,
 * error messages or response snapshots, which could leak infrastructure details.
 */
class StatusPageController extends Controller
{
    public const UPTIME_DAYS = 90;

    public const INCIDENT_HISTORY_DAYS = 14;

    public function show(Request $request, string $slug, MonitorUptimeStatistics $statistics): View
    {
        $statusPage = $this->findPublishedPage($slug);

        if ($statusPage->isPasswordProtected() && ! $request->session()->get($statusPage->unlockSessionKey())) {
            return view('status-pages.locked', ['statusPage' => $statusPage]);
        }

        $monitors = $statusPage->monitors()
            ->where('status', '!=', MonitorStatus::Paused)
            ->with(['maintenanceWindows', 'workspace'])
            ->orderBy('name')
            ->get(['monitors.id', 'monitors.workspace_id', 'monitors.name', 'monitors.status']);

        $timezone = $statusPage->workspace?->timezone ?: (string) config('app.timezone');

        return view('status-pages.show', [
            'statusPage' => $statusPage,
            'monitors' => $monitors,
            'uptime' => $statistics->dailyBarsWithUptime($monitors->modelKeys(), self::UPTIME_DAYS),
            'overallState' => $this->overallState($monitors),
            'incidents' => $statusPage->show_incident_history ? $this->recentIncidents($monitors) : collect(),
            'maintenance' => $this->maintenanceSchedule($monitors),
            'timezone' => $timezone,
        ]);
    }

    public function unlock(UnlockStatusPageRequest $request, string $slug): RedirectResponse
    {
        $statusPage = $this->findPublishedPage($slug);

        if (! $statusPage->isPasswordProtected() || ! Hash::check($request->validated('password'), $statusPage->password)) {
            return back()->withErrors(['password' => 'That password is not correct.']);
        }

        $request->session()->put($statusPage->unlockSessionKey(), true);

        return redirect()->route('status-pages.show', ['slug' => $statusPage->slug]);
    }

    private function findPublishedPage(string $slug): StatusPage
    {
        return StatusPage::query()
            ->with('workspace')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     */
    private function overallState(Collection $monitors): string
    {
        $downCount = $monitors->filter(
            fn (Monitor $monitor): bool => $monitor->status === MonitorStatus::Down && ! $monitor->isUnderMaintenance()
        )->count();

        return match (true) {
            $monitors->isEmpty() => 'operational',
            $downCount > 0 && $downCount === $monitors->count() => 'major_outage',
            $downCount > 0 => 'partial_outage',
            $monitors->contains(fn (Monitor $monitor): bool => $monitor->isUnderMaintenance()) => 'maintenance',
            default => 'operational',
        };
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return \Illuminate\Database\Eloquent\Collection<int, MonitorIncident>
     */
    private function recentIncidents(Collection $monitors): \Illuminate\Database\Eloquent\Collection
    {
        return MonitorIncident::query()
            ->whereIn('monitor_id', $monitors->modelKeys())
            ->where('is_excluded_from_reports', false)
            ->where('started_at', '>=', now()->subDays(self::INCIDENT_HISTORY_DAYS))
            ->with('monitor:id,name')
            ->latest('started_at')
            ->limit(20)
            ->get(['id', 'monitor_id', 'status', 'started_at', 'resolved_at', 'duration_seconds']);
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return Collection<int, array{name: string, in_progress: bool, starts_at: CarbonInterface|null, monitors: list<string>}>
     */
    private function maintenanceSchedule(Collection $monitors): Collection
    {
        $horizon = now()->addDays(self::INCIDENT_HISTORY_DAYS);

        return $monitors
            ->flatMap(fn (Monitor $monitor): Collection => $monitor->maintenanceWindows->map(function (MaintenanceWindow $window) use ($monitor): MaintenanceWindow {
                $window->setRelation('workspace', $monitor->workspace);

                return $window;
            }))
            ->unique('id')
            ->map(fn (MaintenanceWindow $window): array => [
                'name' => $window->name,
                'in_progress' => $window->isInProgressAt(now()),
                'starts_at' => $window->nextStartAfter(now()),
                'monitors' => $monitors
                    ->filter(fn (Monitor $monitor): bool => $monitor->maintenanceWindows->contains('id', $window->id))
                    ->pluck('name')
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $entry): bool => $entry['in_progress'] || ($entry['starts_at'] !== null && $entry['starts_at']->lte($horizon)))
            ->sortBy(fn (array $entry): int => $entry['in_progress'] ? 0 : $entry['starts_at']->getTimestamp())
            ->values();
    }
}
