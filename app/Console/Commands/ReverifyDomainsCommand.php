<?php

namespace App\Console\Commands;

use App\Models\VerifiedDomain;
use App\Services\Monitoring\HostOwnershipVerifier;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ReverifyDomainsCommand extends Command
{
    protected $signature = 'sitevaulta:reverify-domains';

    protected $description = 'Re-check verified domains whose last check is older than a week; revoke after repeated failures.';

    public function handle(HostOwnershipVerifier $verifier): int
    {
        $counts = ['verified' => 0, 'warning' => 0, 'revoked' => 0];

        // A backup website's domain is not revoked automatically: that would silently stop its backups.
        VerifiedDomain::query()
            ->whereNotNull('verified_at')
            ->whereNull('website_id')
            ->where(fn (Builder $query) => $query->whereNull('last_attempted_at')->orWhere('last_attempted_at', '<=', now()->subDays(VerifiedDomain::REVERIFY_AFTER_DAYS)))
            ->with('workspace')
            ->lazyById(100)
            ->each(function (VerifiedDomain $verifiedDomain) use ($verifier, &$counts): void {
                try {
                    $outcome = $verifier->reverify($verifiedDomain);
                } catch (Throwable $exception) {
                    report($exception);

                    return;
                }

                $counts[$outcome]++;

                if ($outcome !== 'verified') {
                    $this->notifyWorkspace($verifiedDomain, $outcome);
                }
            });

        $this->info("Re-verified {$counts['verified']} | warnings {$counts['warning']} | revoked {$counts['revoked']}");

        return self::SUCCESS;
    }

    private function notifyWorkspace(VerifiedDomain $verifiedDomain, string $outcome): void
    {
        $revoked = $outcome === 'revoked';

        AuditLogService::log(
            event: $revoked ? 'monitoring.domain.verification_revoked' : 'monitoring.domain.reverification_failed',
            auditable: $verifiedDomain,
            metadata: ['domain' => $verifiedDomain->domain, 'error' => $verifiedDomain->last_error],
            workspaceId: $verifiedDomain->workspace_id,
        );

        if ($verifiedDomain->workspace === null) {
            return;
        }

        WorkspaceAlertDispatcher::sendDatabaseNotificationToWorkspace(
            workspace: $verifiedDomain->workspace,
            title: $revoked
                ? "Ownership of {$verifiedDomain->domain} is no longer verified"
                : "Could not re-verify {$verifiedDomain->domain}",
            body: $revoked
                ? 'The verification token was not found again, so port checks on this domain have stopped. Put the DNS TXT record, file or meta tag back and click "Verify now".'
                : 'The verification token was not found. Keep the DNS TXT record, file or meta tag in place — if the next weekly check also fails, the verification is removed.',
            status: $revoked ? 'danger' : 'warning',
        );
    }
}
