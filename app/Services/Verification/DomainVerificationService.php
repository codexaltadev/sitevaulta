<?php

namespace App\Services\Verification;

use App\DataTransferObjects\VerificationResult;
use App\Enums\VerificationMethod;
use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Services\Monitoring\VerifiedDomainSync;
use App\Services\Security\AuditLogService;
use App\Services\Security\SsrfValidator;
use Throwable;

/**
 * Ownership verification of a backup website. The challenge and the result live on the verified_domains
 * row of the website's domain — the same record port checks and the Verified domains page use.
 */
class DomainVerificationService
{
    /**
     * The website's ownership record (created pending, with a fresh token, when missing).
     */
    public static function ensureChallengeExists(Website $website): VerifiedDomain
    {
        $verifiedDomain = $website->verifiedDomain()->first() ?? app(VerifiedDomainSync::class)->linkWebsite($website);

        if ($verifiedDomain === null) {
            throw new \InvalidArgumentException('The website has no domain to verify.');
        }

        $website->setRelation('verifiedDomain', $verifiedDomain);

        return $verifiedDomain;
    }

    public static function verifyWebsite(Website $website, VerificationMethod $method): VerificationResult
    {
        $verifiedDomain = self::ensureChallengeExists($website);

        try {
            SsrfValidator::assertSafeHost($verifiedDomain->domain);
        } catch (Throwable $exception) {
            self::recordFailure($verifiedDomain, $exception->getMessage());

            return new VerificationResult(false, $method, $exception->getMessage());
        }

        // Same checks as domain/monitor ownership verification.
        $result = app(OwnershipChallengeChecker::class)->check($method, $verifiedDomain->domain, $verifiedDomain->token, $website->url);

        if (! $result->verified) {
            self::recordFailure($verifiedDomain, $result->message);

            return $result;
        }

        $verifiedDomain->update([
            'verified_at' => $verifiedDomain->verified_at ?? now()->utc(),
            'verification_method' => $method->value,
            'last_attempted_at' => now()->utc(),
            'last_error' => null,
            'reverify_failures' => 0,
        ]);

        AuditLogService::log(
            event: 'website.verified',
            auditable: $website,
            metadata: ['method' => $method->value, 'domain' => $verifiedDomain->domain],
            workspaceId: $website->workspace_id
        );

        return $result;
    }

    /**
     * A failed manual re-check never revokes a website that is already proven (backups keep running);
     * only an unproven domain records the reason.
     */
    private static function recordFailure(VerifiedDomain $verifiedDomain, string $message): void
    {
        $verifiedDomain->update([
            'last_attempted_at' => now()->utc(),
            'last_error' => $verifiedDomain->isVerified() ? null : mb_substr($message, 0, 500),
        ]);
    }
}
