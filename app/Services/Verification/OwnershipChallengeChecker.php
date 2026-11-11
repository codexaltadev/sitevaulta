<?php

namespace App\Services\Verification;

use App\DataTransferObjects\VerificationResult;
use App\Enums\VerificationMethod;
use App\Services\Monitoring\DestinationGuard;
use App\Services\Monitoring\MonitoringIdentity;
use App\Services\Monitoring\NetworkProbe;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one place that looks for an ownership token — used both by backup-website verification and by
 * domain/monitor verification, so every flow offers the same three proofs:
 * a DNS TXT record, a file (in /.well-known or the web root), or a <meta> tag on the homepage.
 *
 * Every HTTP hop is checked by the destination guard and pinned to the validated IP (no SSRF).
 */
class OwnershipChallengeChecker
{
    public const DNS_PREFIX = '_sitevaulta-challenge.';

    public const FILE_PATH = '/.well-known/sitevaulta-verify.txt';

    /** Same file in the web root, for servers where creating a .well-known folder is inconvenient. */
    public const ROOT_FILE_PATH = '/sitevaulta-verify.txt';

    /**
     * @var list<string>
     */
    public const FILE_PATHS = [self::FILE_PATH, self::ROOT_FILE_PATH];

    public const META_TAG_NAME = 'sitevaulta-verification';

    private const MAX_REDIRECTS = 2;

    public function __construct(
        private NetworkProbe $networkProbe,
        private DestinationGuard $destinationGuard,
        private MonitoringIdentity $monitoringIdentity,
    ) {}

    /**
     * @param  string|null  $siteUrl  homepage to use for the file and meta tag; defaults to https:// then http:// on the domain
     */
    public function check(VerificationMethod $method, string $domain, string $token, ?string $siteUrl = null): VerificationResult
    {
        $token = mb_strtolower($token);

        return match ($method) {
            VerificationMethod::DnsTxt => $this->checkDnsTxt($domain, $token),
            VerificationMethod::HtmlFile, VerificationMethod::MetaTag => $this->checkPage($method, $this->baseUrls($domain, $siteUrl), $token),
        };
    }

    /**
     * Tries every method (DNS first) and returns the first match, or a combined explanation.
     */
    public function checkAny(string $domain, string $token, ?string $siteUrl = null): VerificationResult
    {
        $failures = [];

        foreach (VerificationMethod::cases() as $method) {
            $result = $this->check($method, $domain, $token, $siteUrl);

            if ($result->verified) {
                return $result;
            }

            $failures[] = $result->message;
        }

        return new VerificationResult(false, VerificationMethod::DnsTxt, implode(' ', $failures));
    }

    private function checkDnsTxt(string $domain, string $token): VerificationResult
    {
        foreach ([self::DNS_PREFIX.$domain, $domain] as $name) {
            foreach ($this->networkProbe->dnsRecords($name, 'TXT') ?? [] as $txt) {
                if (str_contains(mb_strtolower($txt), $token)) {
                    return new VerificationResult(true, VerificationMethod::DnsTxt, "DNS TXT record matched on {$name}.");
                }
            }
        }

        return new VerificationResult(false, VerificationMethod::DnsTxt, 'DNS: token not found in a TXT record on '.self::DNS_PREFIX."{$domain} or {$domain} (DNS changes can take a few minutes).");
    }

    /**
     * @param  list<string>  $baseUrls
     */
    private function checkPage(VerificationMethod $method, array $baseUrls, string $token): VerificationResult
    {
        $isMetaTag = $method === VerificationMethod::MetaTag;
        $paths = $isMetaTag ? ['/'] : self::FILE_PATHS;
        $problems = [];

        foreach ($baseUrls as $baseUrl) {
            foreach ($paths as $path) {
                $url = $baseUrl.$path;
                $response = $this->fetch($url);

                if (is_string($response)) {
                    $problems[] = $response;

                    continue;
                }

                $body = mb_strtolower(substr($response->body(), 0, $isMetaTag ? 65536 : 4096));

                if ($response->successful() && str_contains($body, $token) && (! $isMetaTag || str_contains($body, self::META_TAG_NAME))) {
                    return new VerificationResult(true, $method, $isMetaTag ? "Meta tag matched on {$url}." : "Verification file matched at {$url}.");
                }

                $problems[] = $response->successful() ? "{$url} does not contain the token." : "{$url} returned HTTP {$response->status()}.";
            }
        }

        return new VerificationResult(false, $method, ($isMetaTag
            ? 'Meta tag: <meta name="'.self::META_TAG_NAME.'" content="…"> not found on the homepage. '
            : 'File: ').implode(' ', array_unique($problems)));
    }

    /**
     * Follows up to two redirects by hand, checking and pinning every hop.
     */
    private function fetch(string $url): Response|string
    {
        for ($hop = 0; ; $hop++) {
            $destination = $this->destinationGuard->inspectUrl($url);

            if (! $destination['allowed']) {
                return (string) $destination['reason'];
            }

            $pin = $destination['ips'] === []
                ? []
                : ['curl' => [CURLOPT_RESOLVE => ["{$destination['host']}:{$destination['port']}:{$destination['ips'][0]}"]]];

            try {
                $response = Http::connectTimeout(5)
                    ->timeout(8)
                    ->withUserAgent($this->monitoringIdentity->userAgent())
                    ->withOptions(['allow_redirects' => false, ...$pin])
                    ->get($url);
            } catch (ConnectionException) {
                return "Could not connect to {$url}.";
            }

            $location = (string) $response->header('Location');

            if (! $response->redirect() || $location === '' || $hop >= self::MAX_REDIRECTS) {
                return $response;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }
    }

    /**
     * @return list<string>
     */
    private function baseUrls(string $domain, ?string $siteUrl): array
    {
        // A backup website may live in a sub-folder (https://example.com/blog): its URL is the base.
        if (filled($siteUrl) && str_contains((string) $siteUrl, '://')) {
            return [rtrim((string) $siteUrl, '/')];
        }

        return ["https://{$domain}", "http://{$domain}"];
    }
}
