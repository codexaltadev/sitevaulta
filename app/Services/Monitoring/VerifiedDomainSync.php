<?php

namespace App\Services\Monitoring;

use App\Models\VerifiedDomain;
use App\Models\Website;

/**
 * Links each backup website to the verified_domains row of its domain — the single place where ownership
 * (token, method, verified_at) lives. Nothing is copied: the website reads its status through the link.
 */
class VerifiedDomainSync
{
    /**
     * Ensures the website's domain has a row (pending until proven) and is the only row linked to it.
     */
    public function linkWebsite(Website $website): ?VerifiedDomain
    {
        $domain = filled($website->domain) ? VerifiedDomain::normalize((string) $website->domain) : '';

        // Rows linked for an old domain: an unproven challenge is dropped, real proof stays as a plain domain.
        VerifiedDomain::query()
            ->where('website_id', $website->id)
            ->when($domain !== '', fn ($query) => $query->where('domain', '!=', $domain))
            ->get()
            ->each(fn (VerifiedDomain $old) => $old->isVerified() ? $old->update(['website_id' => null]) : $old->delete());

        if ($domain === '') {
            return null;
        }

        $verifiedDomain = VerifiedDomain::query()->firstOrNew(['workspace_id' => $website->workspace_id, 'domain' => $domain]);
        $verifiedDomain->website_id = $website->id;
        $verifiedDomain->save();

        return $verifiedDomain;
    }
}
