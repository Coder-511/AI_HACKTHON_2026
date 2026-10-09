<?php

use App\Http\Middleware\RecentPassword;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SubmissionAllowed;
use App\Services\SecurityAudit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->trustHosts(at: fn () => ['^'.preg_quote((string) parse_url(config('app.url'), PHP_URL_HOST), '/').'$'], subdomains: false);
        $middleware->prependToPriorityList(ThrottleRequests::class, SubmissionAllowed::class);
        $middleware->alias([
            'submission' => SubmissionAllowed::class,
            'admin.secure' => RequireAdmin::class,
            'recent_password' => RecentPassword::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['national_id', 'code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response): Response {
            if (in_array($response->getStatusCode(), [403, 419, 429], true)) {
                try {
                    $event = [403 => 'security.access_denied', 419 => 'security.request_forgery_blocked', 429 => 'security.rate_limited'][$response->getStatusCode()];
                    RateLimiter::attempt('security-audit:'.$event.':'.hash('sha256', (string) request()->ip()), 1,
                        fn () => app(SecurityAudit::class)->record($event, request()->user()), 60);
                } catch (Throwable) {
                    // A failed audit sink must not expose data or change a denied response.
                }
            }

            return $response;
        });
    })->create();
