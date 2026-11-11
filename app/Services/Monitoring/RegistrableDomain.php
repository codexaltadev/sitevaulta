<?php

namespace App\Services\Monitoring;

/**
 * The domain a customer actually registers and renews: "elearn.daffodilvarsity.edu.bd" → "daffodilvarsity.edu.bd",
 * "www.shop.example.co.uk" → "example.co.uk". Registries only publish expiry dates for these, never for
 * sub-domains. Covers the common second-level suffixes (com.bd, edu.bd, co.uk, com.au…) without a full
 * Public Suffix List.
 */
class RegistrableDomain
{
    /**
     * Second-level labels that ccTLD registries use as public suffixes (e.g. "edu" in "edu.bd").
     *
     * @var list<string>
     */
    private const GENERIC_SECOND_LEVELS = ['ac', 'co', 'com', 'edu', 'gov', 'net', 'org', 'mil', 'info', 'biz', 'nic', 'or', 'ne', 'go', 'gen', 'ltd', 'plc', 'sch', 'res'];

    public static function of(?string $host): ?string
    {
        $host = mb_strtolower(trim((string) $host, " .\t\n\r\0\x0B"));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $labels = explode('.', $host);
        $count = count($labels);

        if ($count <= 2) {
            return $host;
        }

        $topLevel = $labels[$count - 1];
        $secondLevel = $labels[$count - 2];
        $isSuffixPair = strlen($topLevel) === 2 && in_array($secondLevel, self::GENERIC_SECOND_LEVELS, true);

        return implode('.', array_slice($labels, $isSuffixPair ? -3 : -2));
    }
}
