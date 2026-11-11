<?php

namespace App\Services\Monitoring;

use App\DataTransferObjects\VerificationResult;
use App\Models\ServerConnection;
use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Verification\OwnershipChallengeChecker;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Illuminate\Support\Collection;

/**
 * Decides whether a workspace has proven it controls a host before invasive checks (port scans) may
 * run against it. Proof is any of: a backup website verified for that domain, a server the workspace
 * connected with real credentials, or a domain verified here by DNS TXT / file / meta tag.
 *
 * Every host name is proven on its own: owning example.com says nothing about shop.example.com, which
 * may be hosted by someone else. Only "www." is treated as the same host. A name still counts as owned
 * when it resolves to the IP of an owned domain or server, because a port check would hit that same machine.
 */
class HostOwnershipVerifier
{
    public function __construct(
        private NetworkProbe $networkProbe,
        private OwnershipChallengeChecker $challengeChecker,
    ) {}

    /**
     * @return array{owned: bool, via: string|null}
     */
    public function ownership(Workspace $workspace, string $host): array
    {
        $host = mb_strtolower(trim($host, ' .[]'));
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $ownedDomains = $this->ownedDomains($workspace);
        $serverHosts = $this->serverHosts($workspace);

        if (! $isIp && $ownedDomains->has($domain = self::withoutWww($host))) {
            return ['owned' => true, 'via' => "{$ownedDomains[$domain]} {$domain}"];
        }

        if ($serverHosts->contains($host)) {
            return ['owned' => true, 'via' => "server connection {$host}"];
        }

        // An IP (or a name pointing at one) is owned when it is where an owned domain or server lives.
        $hostIps = $this->networkProbe->resolveIps($host);

        if ($hostIps !== []) {
            $ownedIps = $ownedDomains->keys()
                ->merge($serverHosts)
                ->flatMap(fn (string $name): array => $this->networkProbe->resolveIps($name))
                ->unique();

            if ($ownedIps->intersect($hostIps)->isNotEmpty()) {
                return ['owned' => true, 'via' => 'an IP address of one of your verified domains or servers'];
            }
        }

        return ['owned' => false, 'via' => null];
    }

    /**
     * DNS-free ownership lookup for list pages: one query per source, then plain domain matching.
     * Returns only the owned hosts, each with how ownership was proven.
     *
     * @param  iterable<int, string|null>  $hosts
     * @return array<string, string> host => e.g. "verified website example.com"
     */
    public function ownedHostLabels(Workspace $workspace, iterable $hosts): array
    {
        $ownedDomains = $this->ownedDomains($workspace);
        $serverHosts = $this->serverHosts($workspace);
        $labels = [];

        foreach ($hosts as $host) {
            $host = mb_strtolower(trim((string) $host, ' .[]'));

            if ($host === '' || isset($labels[$host])) {
                continue;
            }

            if ($ownedDomains->has($domain = self::withoutWww($host))) {
                $labels[$host] = "{$ownedDomains[$domain]} {$domain}";
            } elseif ($serverHosts->contains($host)) {
                $labels[$host] = "server connection {$host}";
            }
        }

        return $labels;
    }

    /**
     * Every host name the workspace has proven it owns (verified domains, verified backup websites and
     * connected servers), e.g. to suggest them in the port-check form.
     *
     * @return list<string>
     */
    public function ownedHostNames(Workspace $workspace): array
    {
        return $this->ownedDomains($workspace)->keys()
            ->merge($this->serverHosts($workspace))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Looks for the token with the same checks as backup-website verification:
     * DNS TXT, then the verification file (/.well-known or web root), then the homepage meta tag.
     */
    public function verify(VerifiedDomain $verifiedDomain): bool
    {
        $result = $this->check($verifiedDomain);

        if ($result->verified) {
            $verifiedDomain->markProven($result->method->value);

            return true;
        }

        // A failed manual attempt never revokes an existing proof; only the daily re-check (with its
        // 7-day grace period) can do that.
        $verifiedDomain->forceFill([
            'last_attempted_at' => now(),
            'last_error' => mb_substr($result->message, 0, 500),
        ])->save();

        return false;
    }

    /**
     * Looks for the token by every method; a backup website's own URL is used for the file and meta tag
     * (it may live in a sub-folder).
     */
    public function check(VerifiedDomain $verifiedDomain): VerificationResult
    {
        return $this->challengeChecker->checkAny($verifiedDomain->domain, $verifiedDomain->token, $verifiedDomain->website?->url);
    }

    /**
     * @return Collection<string, string> domain => how it was proven
     */
    private function ownedDomains(Workspace $workspace): Collection
    {
        // Backup websites' domains live here too (linked by website_id), so this is the whole picture.
        return $workspace->verifiedDomains()
            ->whereNotNull('verified_at')
            ->get(['domain', 'website_id'])
            ->toBase()
            ->mapWithKeys(fn (VerifiedDomain $verifiedDomain): array => [
                $verifiedDomain->domain => $verifiedDomain->isFromBackupWebsite() ? 'verified website' : 'verified domain',
            ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function serverHosts(Workspace $workspace): Collection
    {
        // Only servers that once authenticated successfully count: `capabilities` is written exclusively by
        // the real connector test (SSH/FTP/cPanel login), unlike `health_status`, which a bare TCP probe sets.
        return $workspace->serverConnections()
            ->get()
            ->toBase()
            ->filter(fn (ServerConnection $connection): bool => filled($connection->capabilities))
            ->map(fn (ServerConnection $connection): string => mb_strtolower((string) WebsiteUptimeMonitorService::resolveServerEndpoint($connection)['host']))
            ->filter()
            ->unique()
            ->values();
    }

    private static function withoutWww(string $host): string
    {
        return (string) preg_replace('/^www\./', '', $host);
    }
}
