<?php

namespace Tests;

use App\Models\Website;
use App\Services\Verification\DomainVerificationService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Proves the website's domain ownership the way a successful check does: on its verified_domains row.
     */
    protected function verifyWebsiteOwnership(Website $website, string $method = 'dns_txt'): Website
    {
        DomainVerificationService::ensureChallengeExists($website)->update([
            'verified_at' => now(),
            'verification_method' => $method,
            'last_error' => null,
        ]);

        return $website->unsetRelation('verifiedDomain');
    }
}
