<?php

namespace App\Services\Verification;

use App\DataTransferObjects\VerificationResult;
use App\Enums\VerificationMethod;
use App\Enums\WebsiteVerificationStatus;
use App\Models\Website;
use App\Models\WebsiteVerification;
use App\Services\Security\AuditLogService;
use App\Services\Security\SsrfValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DomainVerificationService
{
    public static function ensureChallengeExists(Website $website): WebsiteVerification
    {
        $challenge = $website->latestVerification;
        if ($challenge && $challenge->expires_at->isFuture()) {
            return $challenge;
        }

        $token = 'sv-verify-' . Str::lower(Str::random(32));

        return WebsiteVerification::create([
            'workspace_id' => $website->workspace_id,
            'website_id' => $website->id,
            'token' => $token,
            'dns_host' => '_sitevaulta-challenge.' . $website->domain,
            'file_path' => '/.well-known/sitevaulta-verify.txt',
            'meta_tag_name' => 'sitevaulta-verification',
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

        $result = match ($method) {
            VerificationMethod::DnsTxt => self::verifyDnsTxt($website, $challenge),
            VerificationMethod::HtmlFile => self::verifyHtmlFile($website, $challenge),
            VerificationMethod::MetaTag => self::verifyMetaTag($website, $challenge),
        };

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

    protected static function verifyDnsTxt(Website $website, WebsiteVerification $challenge): VerificationResult
    {
        $hostsToCheck = [
            $challenge->dns_host,
            $website->domain,
        ];

        foreach ($hostsToCheck as $host) {
            $records = @dns_get_record($host, DNS_TXT) ?: [];
            foreach ($records as $record) {
                $txt = (string) ($record['txt'] ?? '');
                if (str_contains($txt, $challenge->token)) {
                    return new VerificationResult(
                        verified: true,
                        method: VerificationMethod::DnsTxt,
                        message: "DNS TXT record matched on {$host}."
                    );
                }
            }
        }

        return new VerificationResult(
            verified: false,
            method: VerificationMethod::DnsTxt,
            message: "DNS TXT token [{$challenge->token}] was not found on {$challenge->dns_host} or {$website->domain}. Please wait for DNS propagation or check your record."
        );
    }

    protected static function verifyHtmlFile(Website $website, WebsiteVerification $challenge): VerificationResult
    {
        $baseUrl = rtrim($website->url, '/');
        $fileUrl = $baseUrl . $challenge->file_path;

        try {
            $response = Http::timeout(6)
                ->withUserAgent('SiteVaulta-DomainVerifier/1.0')
                ->maxRedirects(1)
                ->get($fileUrl);

            if ($response->successful() && str_contains($response->body(), $challenge->token)) {
                return new VerificationResult(
                    verified: true,
                    method: VerificationMethod::HtmlFile,
                    message: "Verification file matched at {$fileUrl}."
                );
            }

            return new VerificationResult(
                verified: false,
                method: VerificationMethod::HtmlFile,
                message: "HTTP {$response->status()} returned from {$fileUrl}, or verification token did not match."
            );
        } catch (\Throwable $e) {
            return new VerificationResult(
                verified: false,
                method: VerificationMethod::HtmlFile,
                message: "Could not fetch {$fileUrl}: " . $e->getMessage()
            );
        }
    }

    protected static function verifyMetaTag(Website $website, WebsiteVerification $challenge): VerificationResult
    {
        try {
            $response = Http::timeout(6)
                ->withUserAgent('SiteVaulta-DomainVerifier/1.0')
                ->maxRedirects(2)
                ->get($website->url);

            if (! $response->successful()) {
                return new VerificationResult(
                    verified: false,
                    method: VerificationMethod::MetaTag,
                    message: "Homepage returned HTTP {$response->status()}."
                );
            }

            $htmlHead = substr($response->body(), 0, 65536);
            if (str_contains($htmlHead, $challenge->token) && str_contains($htmlHead, $challenge->meta_tag_name)) {
                return new VerificationResult(
                    verified: true,
                    method: VerificationMethod::MetaTag,
                    message: "Meta tag [{$challenge->meta_tag_name}] matched on {$website->url}."
                );
            }

            return new VerificationResult(
                verified: false,
                method: VerificationMethod::MetaTag,
                message: "Meta tag <meta name=\"{$challenge->meta_tag_name}\" content=\"{$challenge->token}\"> was not found in the homepage HTML."
            );
        } catch (\Throwable $e) {
            return new VerificationResult(
                verified: false,
                method: VerificationMethod::MetaTag,
                message: "Could not connect to {$website->url}: " . $e->getMessage()
            );
        }
    }
}

