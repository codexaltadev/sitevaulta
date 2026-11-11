<?php

namespace App\Services\Monitoring;

use App\Http\Controllers\ProbeAgentController;
use App\Models\Monitor;
use App\Models\ProbeLocation;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Main-server side of multi-location checks: asks every usable probe agent, in parallel, to re-check a
 * monitor, and checks the agents' health. An agent that does not answer is "unavailable", never "down".
 */
class RemoteProbeClient
{
    public function supports(Monitor $monitor): bool
    {
        return in_array($monitor->type, ProbeAgentController::CONFIRMABLE_TYPES, true);
    }

    /**
     * @return list<array{location: ProbeLocation, verdict: 'up'|'down'|'unavailable', error: string|null}>
     */
    public function confirm(Monitor $monitor): array
    {
        $locations = ProbeLocation::query()->usable()->orderBy('id')->get();

        if ($locations->isEmpty() || ! $this->supports($monitor)) {
            return [];
        }

        $body = json_encode($this->payload($monitor), JSON_THROW_ON_ERROR);
        $timeout = min(60, (int) $monitor->timeout_seconds * 2 + 10);

        $responses = Http::pool(fn (Pool $pool): array => $locations
            ->map(fn (ProbeLocation $location) => $pool->as((string) $location->id)
                ->withHeaders(ProbeSignature::headers($location->secret, $body))
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post($location->endpoint('check')))
            ->all());

        return $locations->map(function (ProbeLocation $location) use ($responses): array {
            $response = $responses[(string) $location->id] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                return ['location' => $location, 'verdict' => 'unavailable', 'error' => $response instanceof Response ? "HTTP {$response->status()}" : 'no answer'];
            }

            $verdict = $response->json('verdict');

            return [
                'location' => $location,
                'verdict' => in_array($verdict, ['up', 'down'], true) ? $verdict : 'unavailable',
                'error' => $response->json('error'),
            ];
        })->values()->all();
    }

    /**
     * Pings the agent with a signed request and records the outcome on the location.
     */
    public function checkHealth(ProbeLocation $location): bool
    {
        $body = json_encode(['ping' => true], JSON_THROW_ON_ERROR);

        try {
            $response = Http::withHeaders(ProbeSignature::headers($location->secret, $body))
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post($location->endpoint('health'));
            $healthy = $response->successful() && $response->json('ok') === true;
            $error = $healthy ? null : ($response->status() === 401 ? 'The agent rejected the signature — the secret on the agent does not match.' : "HTTP {$response->status()}");
        } catch (Throwable $exception) {
            $healthy = false;
            $error = mb_substr($exception->getMessage(), 0, 250);
        }

        $location->forceFill([
            'health_status' => $healthy ? ProbeLocation::HEALTHY : ProbeLocation::UNREACHABLE,
            'last_seen_at' => $healthy ? now() : $location->last_seen_at,
            'last_error' => $error,
        ])->save();

        return $healthy;
    }

    /**
     * Only what a reachability check needs — no names, workspace or alert settings leave the main server.
     *
     * @return array<string, mixed>
     */
    private function payload(Monitor $monitor): array
    {
        return [
            'type' => $monitor->type->value,
            'url' => $monitor->url,
            'host' => $monitor->host,
            'port' => $monitor->port,
            'ports' => $monitor->type->value === 'port' ? $monitor->portList() : null,
            'http_method' => $monitor->http_method,
            'timeout_seconds' => (int) $monitor->timeout_seconds,
            'follow_redirects' => (bool) $monitor->follow_redirects,
            'keyword' => $monitor->keyword,
            'keyword_should_exist' => (bool) $monitor->keyword_should_exist,
            'keyword_case_sensitive' => (bool) $monitor->keyword_case_sensitive,
            'ping_strict_icmp' => (bool) $monitor->ping_strict_icmp,
        ];
    }
}
