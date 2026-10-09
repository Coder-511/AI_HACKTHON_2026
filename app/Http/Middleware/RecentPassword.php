<?php

namespace App\Http\Middleware;

use App\Services\AdminVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(AdminVerification::class)->passwordConfirmed($request)) {
            if (! $request->expectsJson()) {
                app(AdminVerification::class)->remember($request);
            }

            return $request->expectsJson() ? response()->json(['code' => 'password_confirmation_required'], 403) : redirect()->route('password.confirm');
        }

        return $next($request);
    }
}
