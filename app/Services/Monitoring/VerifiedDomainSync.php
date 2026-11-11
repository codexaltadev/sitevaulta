<?php

namespace App\Services\Monitoring;

use App\Models\VerifiedDomain;
use App\Models\Website;

/**
 * Mirrors a backup website's ownership verification into verified_domains, so the Verified domains
 * page lists every proven domain and monitoring needs no second verification for it.
 */
class VerifiedDomainSync
{
    public function syncWebsite(Website $website): void
    {
        $domain = filled($website->domain) ? VerifiedDomain::normalize((string) $website->domain) : '';
        $keep = $website->isVerified() && $domain !== '';

        // Rows this website created earlier for another domain, or while it was still verified.
        VerifiedDomain::query()
            ->where('website_id', $website->id)
            ->when($keep, fn ($query) => $query->where('domain', '!=', $domain))
            ->get()
            ->each(fn (VerifiedDomain $verifiedDomain) => $verifiedDomain->verification_method === VerifiedDomain::METHOD_BACKUP_WEBSITE
                ? $verifiedDomain->delete()
                : $verifiedDomain->update(['website_id' => null]));

        if (! $keep) {
            return;
        }

        $verifiedDomain = VerifiedDomain::query()->firstOrNew(['workspace_id' => $website->workspace_id, 'domain' => $domain]);

        // A domain already proven by DNS TXT / file keeps that proof; it is just linked to the website.
        if (! $verifiedDomain->isVerified()) {
            $verifiedDomain->fill([
                'verification_method' => VerifiedDomain::METHOD_BACKUP_WEBSITE,
                'verified_at' => $website->verified_at ?? now(),
                'last_error' => null,
            ]);
        }

        $verifiedDomain->website_id = $website->id;
        $verifiedDomain->save();
    }
}
