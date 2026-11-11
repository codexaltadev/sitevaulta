<?php

namespace App\Console\Commands;

use App\Models\VerifiedDomain;
use App\Services\Monitoring\ProbeHealthCheck;
use App\Services\Verification\OwnershipRecheckService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ReverifyDomainsCommand extends Command
{
    /**
     * Each proven domain is checked once a day; a manual "Verify now" in between counts as that day's check.
     */
    private const RECHECK_AFTER_HOURS = 20;

    protected $signature = 'sitevaulta:reverify-domains';

    protected $description = 'Daily ownership re-check: notify when a verification token disappears and pause backups / port checks after the 7-day grace period.';

    public function handle(OwnershipRecheckService $recheck, ProbeHealthCheck $probeHealth): int
    {
        // Without our own internet every site would look token-less; never start a countdown on that.
        if (! $probeHealth->isHealthy()) {
            $this->warn('Skipped: the monitoring probe has no network right now.');

            return self::SUCCESS;
        }

        $counts = array_fill_keys([
            OwnershipRecheckService::OK, OwnershipRecheckService::INCONCLUSIVE, OwnershipRecheckService::NOTICE,
            OwnershipRecheckService::REMINDER, OwnershipRecheckService::WAITING, OwnershipRecheckService::LAPSED,
            OwnershipRecheckService::RESTORED,
        ], 0);

        VerifiedDomain::query()
            ->whereNotNull('verified_at')
            // Domains in the countdown are always re-checked, so clicking "Verify now" cannot postpone the deadline.
            ->where(fn (Builder $query) => $query->whereNull('last_attempted_at')
                ->orWhere('last_attempted_at', '<=', now()->subHours(self::RECHECK_AFTER_HOURS))
                ->orWhereNotNull('token_missing_since'))
            ->with(['workspace.owner', 'website'])
            ->lazyById(100)
            ->each(function (VerifiedDomain $verifiedDomain) use ($recheck, &$counts): void {
                try {
                    $counts[$recheck->recheck($verifiedDomain)]++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        $this->info(collect($counts)->map(fn (int $count, string $outcome): string => "{$outcome} {$count}")->implode(' | '));

        return self::SUCCESS;
    }
}
