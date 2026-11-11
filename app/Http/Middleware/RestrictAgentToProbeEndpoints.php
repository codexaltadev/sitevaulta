<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a probe agent server (MONITORING_AGENT_MODE=true) the application exposes nothing but the signed
 * /probe-agent endpoints (and /up for uptime checks of the agent itself). It also runs without a database:
 * sessions live in memory and the cache on disk, so an agent needs no MySQL at all.
 */
class RestrictAgentToProbeEndpoints
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('monitoring.agent.mode')) {
            return $next($request);
        }

        config(['session.driver' => 'array', 'cache.default' => 'file']);
        app('cache')->setDefaultDriver('file');

        if (! $request->is('probe-agent/*', 'up')) {
            abort(404);
        }

        return $next($request);
    }
}
