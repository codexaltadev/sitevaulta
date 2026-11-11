<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorType;
use App\Models\Monitor;

/**
 * Runs one quick live test when a monitor is saved, so setup mistakes surface immediately instead of
 * as false outages later: a server that blocks ping, or a DNS record type the domain does not have.
 */
class MonitorSetupAdvisor
{
    public function __construct(private NetworkProbe $networkProbe) {}

    /**
     * @return list<array{level: 'warning'|'danger', title: string, body: string}>
     */
    public function adviseFor(Monitor $monitor): array
    {
        return collect([$monitor])
            ->merge($monitor->children()->get())
            ->filter(fn (Monitor $candidate): bool => filled($candidate->host))
            ->map(fn (Monitor $candidate): ?array => match ($candidate->type) {
                MonitorType::Ping => $this->pingAdviceFor($candidate),
                MonitorType::Dns => $this->dnsAdviceFor($candidate),
                default => null,
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{level: 'warning'|'danger', title: string, body: string}|null
     */
    private function dnsAdviceFor(Monitor $dnsMonitor): ?array
    {
        $host = (string) $dnsMonitor->host;
        $selected = $dnsMonitor->dnsRecordTypes();
        $found = [];

        foreach (array_unique([...$selected, 'A', 'AAAA', 'CNAME', 'MX']) as $type) {
            $records = $this->networkProbe->dnsRecords($host, $type);

            if ($records === null) {
                return null;
            }

            $found[$type] = $records;
        }

        $missing = array_values(array_filter($selected, fn (string $type): bool => $found[$type] === []));

        if ($missing === []) {
            return null;
        }

        $existing = collect($found)
            ->reject(fn (array $values, string $type): bool => $values === [] || in_array($type, $selected, true));
        $isRootDomainCname = in_array('CNAME', $missing, true) && substr_count($host, '.') <= 2 && $found['A'] !== [];

        return [
            'level' => 'warning',
            'title' => "{$host} has no ".implode(' / ', $missing).' record',
            'body' => ($existing->isEmpty()
                    ? ''
                    : 'It does have '.$existing->map(fn (array $values, string $type): string => "{$type} (".implode(', ', array_slice($values, 0, 2)).')')->implode(', ').'. ')
                .($isRootDomainCname ? 'A root domain cannot have a CNAME — untick CNAME. ' : '')
                .'Untick the missing types, otherwise this check stays "Unknown".',
        ];
    }

    /**
     * @return array{level: 'warning'|'danger', title: string, body: string}|null
     */
    private function pingAdviceFor(Monitor $pingMonitor): ?array
    {
        $host = (string) $pingMonitor->host;
        $ping = $this->networkProbe->ping($host, 2);

        if ($ping['reachable']) {
            return null;
        }

        if (str_starts_with((string) $ping['error'], 'DNS')) {
            return [
                'level' => 'danger',
                'title' => "{$host} does not resolve",
                'body' => 'The domain has no DNS record right now, so the Ping check will report Down until DNS is fixed.',
            ];
        }

        if ($pingMonitor->ping_strict_icmp) {
            return [
                'level' => 'danger',
                'title' => "{$host} does not answer ping",
                'body' => 'Strict ICMP is on, so this Ping check will report Down. Turn Strict ICMP off to measure over TCP 443/80 instead.',
            ];
        }

        foreach ([443, 80] as $port) {
            if ($this->networkProbe->tcpConnect($host, $port, 3)['connected']) {
                return [
                    'level' => 'warning',
                    'title' => "{$host} blocks ping (firewall)",
                    'body' => "No problem — reachability and latency will be measured with a TCP connect on port {$port} instead, so you won't get false alerts.",
                ];
            }
        }

        return [
            'level' => 'danger',
            'title' => "{$host} is not reachable",
            'body' => 'No ping reply and ports 443/80 are closed. The Ping check will report Down — check the hostname or the server firewall.',
        ];
    }
}
