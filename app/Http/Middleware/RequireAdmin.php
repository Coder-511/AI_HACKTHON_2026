<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        abort_unless($user instanceof User && $user->is_admin, 403);

        if ($user->mfa_confirmed_at === null || ! is_string($user->mfa_secret) || $user->mfa_secret === '') {
            $request->session()->forget('admin_mfa_verified');

            return redirect()->route('mfa.setup');
        }

        if ($request->session()->get('admin_mfa_verified') !== $user->getAuthIdentifier()) {
            $request->session()->forget('admin_mfa_verified');

            return redirect()->route('mfa.challenge');
        }

        return $next($request);
    }
}
