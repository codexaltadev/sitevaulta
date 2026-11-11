<?php

namespace App\Services\Verification;

use App\DataTransferObjects\VerificationResult;
use App\Enums\VerificationMethod;
use App\Enums\WebsiteVerificationStatus;
use App\Models\Website;
use App\Models\WebsiteVerification;
use App\Services\Security\AuditLogService;
use App\Services\Security\SsrfValidator;
use Illuminate\Support\Str;

class DomainVerificationService
{
    public static function ensureChallengeExists(Website $website): WebsiteVerification
    {
        $challenge = $website->latestVerification()->first();
        if ($challenge && $challenge->expires_at->isFuture()) {
            return $challenge;
        }

        $token = 'sv-verify-'.Str::lower(Str::random(32));

        return WebsiteVerification::create([
            'workspace_id' => $website->workspace_id,
            'website_id' => $website->id,
            'token' => $token,
            'dns_host' => OwnershipChallengeChecker::DNS_PREFIX.$website->domain,
            'file_path' => OwnershipChallengeChecker::FILE_PATH,
            'meta_tag_name' => OwnershipChallengeChecker::META_TAG_NAME,
            'expires_at' => now()->utc()->addDays(14),
        ]);
    }

    public static function verifyWebsite(Website $website, VerificationMethod $method): VerificationResult
    {
        $challenge = self::ensureChallengeExists($website);

        try {
            SsrfValidator::assertSafeHost($website->domain);
        } catch (\Throwable $e) {
            $challenge->update([
                'last_checked_at' => now()->utc(),
                'last_error' => $e->getMessage(),
            ]);

            return new VerificationResult(false, $method, $e->getMessage());
        }

        // Same checks as domain/monitor ownership verification.
        $result = app(OwnershipChallengeChecker::class)->check($method, (string) $website->domain, $challenge->token, $website->url);

        $challenge->update([
            'last_checked_at' => now()->utc(),
            'last_error' => $result->verified ? null : $result->message,
        ]);

        if ($result->verified) {
            $website->update([
                'verification_status' => WebsiteVerificationStatus::Verified,
                'verified_at' => now()->utc(),
                'verification_method' => $method,
            ]);

            AuditLogService::log(
                event: 'website.verified',
                auditable: $website,
                metadata: ['method' => $method->value, 'domain' => $website->domain],
                workspaceId: $website->workspace_id
            );
        } else {
            $website->update([
                'verification_status' => WebsiteVerificationStatus::Failed,
            ]);
        }

        return $result;
    }
}
