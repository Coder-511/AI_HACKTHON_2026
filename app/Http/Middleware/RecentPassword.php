<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (time() - (int) $request->session()->get('auth.password_confirmed_at', 0) > 900) {
            return $request->expectsJson() ? response()->json(['code' => 'password_confirmation_required'], 403) : redirect()->route('password.confirm');
        }

        return $next($request);
    }
}
