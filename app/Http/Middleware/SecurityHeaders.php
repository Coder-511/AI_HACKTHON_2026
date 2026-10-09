<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->instance('correlation_id', (string) Str::uuid());
        $response = $next($request);
        $mapImages = $request->routeIs('reports.create') ? ' https://tile.openstreetmap.org' : '';
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:".$mapImages."; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', $request->routeIs('reports.create') ? 'strict-origin-when-cross-origin' : 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        $response->headers->set('X-Correlation-ID', app('correlation_id'));
        if ($request->user() || $request->is('login', 'register', 'admin/*', 'reset-password/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
