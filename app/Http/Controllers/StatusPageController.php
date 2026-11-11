<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Http\Requests\SubscribeStatusPageRequest;
use App\Http\Requests\UnlockStatusPageRequest;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Services\Monitoring\MonitorUptimeStatistics;
use App\Services\Monitoring\StatusPageNotifier;
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
        return $this->render($request, $this->findPublishedPage($slug), $statistics);
    }

    /**
     * Serves a page on its own custom domain (status.client.com): "/" shows it, "/unlock" and "/subscribe"
     * take the forms. Called by ServeStatusPageOnCustomDomain before normal routing.
     */
    public function showOnCustomDomain(Request $request, StatusPage $statusPage): mixed
    {
        return match (true) {
            $request->isMethod('GET') && $request->path() === '/' => $this->render($request, $statusPage, app(MonitorUptimeStatistics::class)),
            $request->isMethod('POST') && $request->path() === 'unlock' => $this->unlock(app(UnlockStatusPageRequest::class), $statusPage->slug),
            $request->isMethod('POST') && $request->path() === 'subscribe' => $this->subscribe(app(SubscribeStatusPageRequest::class), $statusPage->slug, app(StatusPageNotifier::class)),
            default => abort(404),
        };
    }

    public function subscribe(SubscribeStatusPageRequest $request, string $slug, StatusPageNotifier $notifier): RedirectResponse
    {
        $statusPage = $this->findPublishedPage($slug);

        abort_unless($statusPage->allow_subscriptions, 404);

        if ($statusPage->isPasswordProtected() && ! $request->session()->get($statusPage->unlockSessionKey())) {
            abort(403);
        }

        $subscriber = $statusPage->subscribers()->firstOrCreate(['email' => mb_strtolower(trim($request->validated('email')))]);

        // Always the same answer, so the form cannot be used to find out who is subscribed.
        if ($subscriber->confirmed_at === null) {
            $notifier->sendConfirmation($subscriber);
        }

        return back()->with('status_page_message', 'Check your inbox: we sent a link to confirm your subscription.');
    }

    public function confirmSubscription(string $token): RedirectResponse
    {
        $subscriber = StatusPageSubscriber::query()->with('statusPage')->where('token', $token)->firstOrFail();
        $subscriber->confirmed_at ??= now();
        $subscriber->save();

        return redirect()->to($subscriber->statusPage->publicUrl())
            ->with('status_page_message', 'You are subscribed. We will email you when something changes.');
    }

    public function unsubscribe(string $token): RedirectResponse
    {
        $subscriber = StatusPageSubscriber::query()->with('statusPage')->where('token', $token)->firstOrFail();
        $pageUrl = $subscriber->statusPage->publicUrl();
        $subscriber->delete();

        return redirect()->to($pageUrl)->with('status_page_message', 'You are unsubscribed. You will not get any more emails.');
    }

    private function render(Request $request, StatusPage $statusPage, MonitorUptimeStatistics $statistics): View
    {
        $onCustomDomain = filled($statusPage->custom_domain) && $request->getHost() === $statusPage->custom_domain;
        $formUrls = [
            'unlock' => $onCustomDomain ? url('/unlock') : route('status-pages.unlock', ['slug' => $statusPage->slug]),
            'subscribe' => $onCustomDomain ? url('/subscribe') : route('status-pages.subscribe', ['slug' => $statusPage->slug]),
        ];

        if ($statusPage->isPasswordProtected() && ! $request->session()->get($statusPage->unlockSessionKey())) {
            return view('status-pages.locked', ['statusPage' => $statusPage, 'formUrls' => $formUrls]);
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
            'formUrls' => $formUrls,
            'announcements' => $statusPage->announcements()->open()->get(),
            'pastAnnouncements' => $statusPage->announcements()->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(self::INCIDENT_HISTORY_DAYS))->get(),
        ]);
    }

    public function unlock(UnlockStatusPageRequest $request, string $slug): RedirectResponse
    {
        $statusPage = $this->findPublishedPage($slug);

        if (! $statusPage->isPasswordProtected() || ! Hash::check($request->validated('password'), $statusPage->password)) {
            return back()->withErrors(['password' => 'That password is not correct.']);
        }

        $request->session()->put($statusPage->unlockSessionKey(), true);

        // On a custom domain the visitor goes back to "/" there; otherwise to /status/{slug}.
        return redirect()->to($request->getHost() === $statusPage->custom_domain ? url('/') : route('status-pages.show', ['slug' => $statusPage->slug]));
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
