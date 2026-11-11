<?php

namespace App\Http\Middleware;

use App\Models\StatusPage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a status page on its customer's own domain (status.client.com, a CNAME to us). Runs before routing:
 * "/", "/unlock" and "/subscribe" on that host are rewritten to the page's /status/{slug} routes, so the
 * normal web middleware (session, CSRF, throttling) applies. Anything else on that host is a 404 — the
 * customer's domain never exposes the app, login or other pages.
 */
class ServeStatusPageOnCustomDomain
{
    private const CACHE_SECONDS = 60;

    /**
     * @var array<string, array{string, string}> path => [method, route suffix]
     */
    private const PATHS = [
        '/' => ['GET', ''],
        '/unlock' => ['POST', '/unlock'],
        '/subscribe' => ['POST', '/subscribe'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $host = mb_strtolower($request->getHost());

        // A probe agent has no status pages (and no database to look them up in).
        if (config('monitoring.agent.mode') || $host === mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return $next($request);
        }

        $slug = Cache::remember("status-page-domain:{$host}", self::CACHE_SECONDS, fn (): string => (string) StatusPage::query()
            ->where('custom_domain', $host)
            ->where('is_published', true)
            ->value('slug'));

        if ($slug === '') {
            return $next($request);
        }

        $path = '/'.ltrim($request->getPathInfo(), '/');
        [$method, $suffix] = self::PATHS[$path] ?? [null, null];

        if ($method === null || ! $request->isMethod($method) && ! ($method === 'GET' && $request->isMethod('HEAD'))) {
            abort(404);
        }

        $query = $request->getQueryString();
        $request->server->set('REQUEST_URI', "/status/{$slug}{$suffix}".($query ? "?{$query}" : ''));
        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $request->getContent(),
        );

        return $next($request);
    }
}
