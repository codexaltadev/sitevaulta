<?php

namespace App\Http\Controllers;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\ProbeHealthCheck;
use App\Services\Monitoring\ProbeSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The probe agent side (only with MONITORING_AGENT_MODE=true). Answers signed requests from the main server:
 * "health" proves the agent is up and shares the secret; "check" re-runs one monitor's reachability check
 * from this location. Nothing is stored here.
 */
class ProbeAgentController extends Controller
{
    /**
     * @var list<MonitorType>
     */
    public const CONFIRMABLE_TYPES = [MonitorType::Http, MonitorType::Keyword, MonitorType::Ping, MonitorType::Port];

    public function health(Request $request, ProbeHealthCheck $probeHealth): JsonResponse
    {
        $this->authorizeSignedRequest($request);

        return response()->json(['ok' => true, 'network' => $probeHealth->isHealthy()]);
    }

    public function check(Request $request, MonitorCheckRunner $runner, ProbeHealthCheck $probeHealth): JsonResponse
    {
        $this->authorizeSignedRequest($request);

        $data = $request->validate([
            'type' => ['required', 'string'],
            'url' => ['nullable', 'string', 'max:500'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'ports' => ['nullable', 'array', 'max:'.Monitor::MAX_PORTS],
            'ports.*' => ['integer', 'between:1,65535'],
            'http_method' => ['nullable', 'string', 'in:GET,HEAD,POST'],
            'timeout_seconds' => ['required', 'integer', 'between:1,60'],
            'follow_redirects' => ['boolean'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'keyword_should_exist' => ['boolean'],
            'keyword_case_sensitive' => ['boolean'],
            'ping_strict_icmp' => ['boolean'],
        ]);

        $type = MonitorType::tryFrom($data['type']);
        abort_unless(in_array($type, self::CONFIRMABLE_TYPES, true), 422, 'This check type is not confirmed from other locations.');

        // Our own network is down: say so instead of reporting the site as down.
        if (! $probeHealth->isHealthy()) {
            return response()->json(['verdict' => 'unavailable', 'error' => 'The probe agent has no network right now.']);
        }

        $monitor = new Monitor;
        $monitor->forceFill([...$data, 'type' => $type]);
        $result = $runner->probeReachability($monitor);

        return response()->json([
            'verdict' => $result['is_up'] ? 'up' : 'down',
            'error' => $result['error'],
            'root_cause_type' => $result['root_cause_type'],
            'http_status' => $result['http_status'],
            'response_time_ms' => $result['response_time_ms'],
        ]);
    }

    private function authorizeSignedRequest(Request $request): void
    {
        abort_unless((bool) config('monitoring.agent.mode'), 404);

        abort_unless(ProbeSignature::verify(
            config('monitoring.agent.secret'),
            $request->getContent(),
            $request->header(ProbeSignature::TIMESTAMP_HEADER),
            $request->header(ProbeSignature::SIGNATURE_HEADER),
            (int) config('monitoring.agent.max_clock_skew_seconds', 60),
        ), 401, 'Invalid probe signature.');
    }
}
