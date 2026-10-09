<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteIdentityRegistrationRequest;
use App\Http\Requests\StartIdentityRegistrationRequest;
use App\Models\IdentityRegistrationChallenge;
use App\Services\RegistryRegistration;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdentityRegistrationController extends Controller implements HasMiddleware
{
    public function __construct(private RegistryRegistration $registration) {}

    public static function middleware(): array
    {
        return [function (Request $request, Closure $next): Response {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }];
    }

    public function create(Request $request): View
    {
        return view('auth.register', ['challengePending' => is_string($request->session()->get('identity_registration.id'))]);
    }

    public function start(StartIdentityRegistrationRequest $request): RedirectResponse
    {
        $previous = $request->session()->get('identity_registration.id');
        $previousSecret = $request->session()->get('identity_registration.secret');
        if (is_string($previous) && is_string($previousSecret)) {
            IdentityRegistrationChallenge::query()->whereKey($previous)
                ->where('session_secret_hash', hash('sha256', $previousSecret))->update(['consumed_at' => now()]);
        }

        $secret = Str::random(64);
        $request->session()->regenerate();
        $challenge = $this->registration->start($request->validated('national_id'), $secret);
        $request->session()->put('identity_registration', ['id' => $challenge->id, 'secret' => $secret]);

        return redirect()->route('register')->with('status', 'If this ID is eligible, a verification code was sent to its registered email. The code expires in five minutes.');
    }

    public function store(CompleteIdentityRegistrationRequest $request): RedirectResponse
    {
        $challenge = $request->session()->get('identity_registration');
        $user = is_array($challenge) && is_string($challenge['id'] ?? null) && is_string($challenge['secret'] ?? null)
            ? $this->registration->complete($challenge['id'], $challenge['secret'], $request->validated('code'), $request->validated('password')) : null;

        if (! $user) {
            return redirect()->route('register')->withErrors(['registration' => 'Unable to verify registration. Check the code or request a new one.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['identity_registration', 'admin_mfa_verified', 'admin_mfa_enrollment', 'auth.password_confirmed_at', 'url.intended']);

        return redirect()->route('home')->with('status', 'Your citizen account is verified. Sign in next time using the email held in the registry and your password.');
    }
}
