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
        $conclusive = true;

        foreach (VerificationMethod::cases() as $method) {
            $result = $this->check($method, $domain, $token, $siteUrl);

            if ($result->verified) {
                return $result;
            }

            $failures[] = $result->message;
            $conclusive = $conclusive && $result->conclusive;
        }

        // One short line per method, so pages can show them as a list. "Gone" is only certain when every
        // method got a real answer; one unreachable method means the token may still be there.
        return new VerificationResult(false, VerificationMethod::DnsTxt, implode("\n", $failures), conclusive: $conclusive);
    }

    private function checkDnsTxt(string $domain, string $token): VerificationResult
    {
        $resolverAnswered = true;

        foreach ([self::DNS_PREFIX.$domain, $domain] as $name) {
            $records = $this->networkProbe->dnsRecords($name, 'TXT');
            $resolverAnswered = $resolverAnswered && $records !== null;

            foreach ($records ?? [] as $txt) {
                if (str_contains(mb_strtolower($txt), $token)) {
                    return new VerificationResult(true, VerificationMethod::DnsTxt, "DNS TXT record matched on {$name}.");
                }
            }
        }

        return new VerificationResult(
            false,
            VerificationMethod::DnsTxt,
            $resolverAnswered ? 'DNS TXT record: not found yet. New records can take a few minutes to appear.' : 'DNS TXT record: the DNS lookup failed, so it could not be checked.',
            conclusive: $resolverAnswered,
        );
    }

    /**
     * @param  list<string>  $baseUrls
     */
    private function checkPage(VerificationMethod $method, array $baseUrls, string $token): VerificationResult
    {
        $isMetaTag = $method === VerificationMethod::MetaTag;
        $paths = $isMetaTag ? ['/'] : self::FILE_PATHS;
        $answeredWithoutToken = false;
        $httpStatuses = [];
        $unreachable = false;

        foreach ($baseUrls as $baseUrl) {
            foreach ($paths as $path) {
                $url = $baseUrl.$path;
                $response = $this->fetch($url);

                if (is_string($response)) {
                    $unreachable = true;

                    continue;
                }

                $body = mb_strtolower(substr($response->body(), 0, $isMetaTag ? 65536 : 4096));

                if ($response->successful() && str_contains($body, $token) && (! $isMetaTag || str_contains($body, self::META_TAG_NAME))) {
                    return new VerificationResult(true, $method, $isMetaTag ? "Meta tag matched on {$url}." : "Verification file matched at {$url}.");
                }

                $response->successful() ? $answeredWithoutToken = true : $httpStatuses[] = $response->status();
            }
        }

        $label = $isMetaTag ? 'Meta tag' : 'Verification file';
        $statuses = implode(', ', array_unique($httpStatuses));

        // A real answer: the page loaded without the token, or the file is definitely not there (404 / 410).
        // Down, 5xx, 401/403 (often a firewall) or unreachable says nothing about the token.
        $conclusive = $answeredWithoutToken || (! $isMetaTag && array_intersect($httpStatuses, [404, 410]) !== []);

        return new VerificationResult(false, $method, $label.': '.match (true) {
            $answeredWithoutToken && $isMetaTag => 'not found in the homepage <head>.',
            $answeredWithoutToken => 'the file was found, but it does not contain your token.',
            $httpStatuses !== [] && $isMetaTag => "the homepage returned HTTP {$statuses}.",
            $httpStatuses !== [] && array_unique($httpStatuses) === [404] => 'not uploaded yet (404 Not Found).',
            $httpStatuses !== [] => "the site returned HTTP {$statuses}.",
            $unreachable => 'could not reach the site.',
            default => 'not found.',
        }, conclusive: $conclusive);
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
