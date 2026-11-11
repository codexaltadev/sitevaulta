<?php

namespace App\Services\Verification;

use App\Models\VerifiedDomain;
use App\Services\Monitoring\HostOwnershipVerifier;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\AuditLogService;
use Throwable;

/**
 * Daily re-check of every proven domain. When the token is conclusively gone the owner gets a notice and
 * GRACE_DAYS to put it back (reminders on REMINDER_DAYS); after that the verification lapses, which pauses
 * the website's backups (or the domain's port checks) until the token is back. A check that cannot reach
 * the site or DNS never counts against the owner.
 */
class OwnershipRecheckService
{
    public const OK = 'ok';

    public const INCONCLUSIVE = 'inconclusive';

    public const NOTICE = 'notice';

    public const REMINDER = 'reminder';

    public const WAITING = 'waiting';

    public const LAPSED = 'lapsed';

    public const RESTORED = 'restored';

    /** A run at roughly the same time each day is still "day N" even if it starts a little early. */
    private const DAY_TOLERANCE_SECONDS = 3600;

    public function __construct(private HostOwnershipVerifier $verifier) {}

    /**
     * @return self::OK|self::INCONCLUSIVE|self::NOTICE|self::REMINDER|self::WAITING|self::LAPSED|self::RESTORED
     */
    public function recheck(VerifiedDomain $verifiedDomain): string
    {
        $result = $this->verifier->check($verifiedDomain);

        if ($result->verified) {
            $wasMissing = $verifiedDomain->token_missing_since !== null;
            $verifiedDomain->markProven($result->method->value);

            if ($wasMissing) {
                $this->notify($verifiedDomain, self::RESTORED);

                return self::RESTORED;
            }

            return self::OK;
        }

        if (! $result->conclusive) {
            $verifiedDomain->forceFill(['last_attempted_at' => now()])->save();

            return self::INCONCLUSIVE;
        }

        $verifiedDomain->forceFill([
            'last_attempted_at' => now(),
            'last_error' => mb_substr($result->message, 0, 500),
        ]);

        if ($verifiedDomain->token_missing_since === null) {
            $verifiedDomain->forceFill(['token_missing_since' => now(), 'last_reminder_day' => 0])->save();
            $this->notify($verifiedDomain, self::NOTICE);

            return self::NOTICE;
        }

        if (now()->addSeconds(self::DAY_TOLERANCE_SECONDS)->gte($verifiedDomain->graceEndsAt())) {
            $verifiedDomain->forceFill([
                'verified_at' => null,
                'ownership_lapsed_at' => now(),
                'token_missing_since' => null,
                'last_reminder_day' => null,
            ])->save();
            $this->notify($verifiedDomain, self::LAPSED);

            return self::LAPSED;
        }

        $daysMissing = intdiv((int) $verifiedDomain->token_missing_since->diffInSeconds(now()) + self::DAY_TOLERANCE_SECONDS, 86400);
        $reminderDay = collect(VerifiedDomain::REMINDER_DAYS)->filter(fn (int $day): bool => $day <= $daysMissing)->max();

        if ($reminderDay !== null && $reminderDay > (int) $verifiedDomain->last_reminder_day) {
            $verifiedDomain->forceFill(['last_reminder_day' => $reminderDay])->save();
            $this->notify($verifiedDomain, self::REMINDER);

            return self::REMINDER;
        }

        $verifiedDomain->save();

        return self::WAITING;
    }

    private function notify(VerifiedDomain $verifiedDomain, string $outcome): void
    {
        $workspace = $verifiedDomain->workspace;

        if ($workspace === null) {
            return;
        }

        $website = $verifiedDomain->website;
        $paused = $website !== null ? "backups of {$website->name}" : "port checks on {$verifiedDomain->domain}";
        $deadline = $verifiedDomain->graceEndsAt()?->setTimezone($workspace->timezone ?: 'UTC')->format('M j, Y');
        $daysLeft = $verifiedDomain->graceDaysLeft();

        [$title, $body, $status] = match ($outcome) {
            self::NOTICE => [
                "Verification token missing on {$verifiedDomain->domain}",
                "We could not find your SiteVaulta verification token on {$verifiedDomain->domain} (DNS TXT record, verification file or meta tag). Put it back within ".VerifiedDomain::GRACE_DAYS." days — by {$deadline} — or {$paused} will be paused.",
                'warning',
            ],
            self::REMINDER => [
                "{$daysLeft} ".str('day')->plural((int) $daysLeft)." left to restore the token on {$verifiedDomain->domain}",
                "The verification token is still missing on {$verifiedDomain->domain}. Put it back by {$deadline}, otherwise {$paused} will be paused.",
                'warning',
            ],
            self::LAPSED => [
                ucfirst($paused).' paused',
                "Ownership of {$verifiedDomain->domain} is no longer verified because the token has been missing for ".VerifiedDomain::GRACE_DAYS.' days. Existing backups are kept. Put the token back and click "Verify now" to resume immediately.',
                'danger',
            ],
            default => [
                "Verification token found again on {$verifiedDomain->domain}",
                'Everything continues as normal — nothing was paused.',
                'success',
            ],
        };

        WorkspaceAlertDispatcher::sendDatabaseNotificationToWorkspace($workspace, $title, $body, $status);

        $recipient = $website?->uptime_alert_email ?: $workspace->owner?->email;

        if (filled($recipient)) {
            try {
                WorkspaceAlertDispatcher::dispatchEmailWithFallback(
                    workspace: $workspace,
                    recipientEmail: (string) $recipient,
                    subject: "[SiteVaulta] {$title}",
                    htmlBody: $this->emailBody($verifiedDomain, $body, $outcome),
                    preferredMode: $website?->uptime_email_provider_mode ?: 'workspace_default',
                    ledgerDescription: 'Domain ownership notice',
                    reference: $verifiedDomain,
                    metadata: ['domain' => $verifiedDomain->domain, 'outcome' => $outcome],
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        AuditLogService::log(
            event: "domain.ownership.{$outcome}",
            auditable: $verifiedDomain,
            metadata: ['domain' => $verifiedDomain->domain, 'website_id' => $verifiedDomain->website_id, 'deadline' => $deadline],
            workspaceId: $workspace->id,
        );
    }

    private function emailBody(VerifiedDomain $verifiedDomain, string $body, string $outcome): string
    {
        $instructions = $outcome === self::RESTORED ? '' : '<p>Use any one of these:</p><ul>'
            .'<li><strong>DNS TXT record</strong> — name <code>'.e($verifiedDomain->dnsRecordName()).'</code>, value <code>'.e($verifiedDomain->token).'</code></li>'
            .'<li><strong>Verification file</strong> — <code>https://'.e($verifiedDomain->domain).OwnershipChallengeChecker::ROOT_FILE_PATH.'</code> containing <code>'.e($verifiedDomain->token).'</code></li>'
            .'<li><strong>Meta tag</strong> in the homepage &lt;head&gt; — <code>'.e($verifiedDomain->metaTag()).'</code></li>'
            .'</ul>';

        return '<p>'.e($body).'</p>'.$instructions;
    }
}
