<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CitizenOrigin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('moshkelti.citizen_only')) {
            return $next($request);
        }

        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);

        $origin = config('moshkelti.citizen_origin');
        $parts = is_string($origin) ? parse_url($origin) : false;
        abort_unless(is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['port'] ?? null) === 8443
            && filter_var($parts['host'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $parts['host']) === 1
            && count($parts) === 3
            && config('app.url') === $origin, 503);
        abort_unless($request->getSchemeAndHttpHost() === $origin, 400);
        abort_if($request->is('admin', 'admin/*'), 404);

        config([
            'session.cookie' => '__Host-moshkelti_citizen',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.path' => '/',
            'session.domain' => null,
        ]);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
