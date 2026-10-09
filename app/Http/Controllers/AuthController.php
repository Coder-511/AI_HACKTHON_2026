<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminMfa;
use App\Services\SecurityAudit;
use Closure;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller implements HasMiddleware
{
    public function __construct(private AdminMfa $mfa, private SecurityAudit $audit) {}

    /** @return array<int, Closure> */
    public static function middleware(): array
    {
        return [function (Request $request, Closure $next): Response {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }];
    }

    public function loginForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $this->normalizeEmail($request);
        $data = $this->validateInput($request, [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'remember' => ['sometimes', 'boolean'],
        ], 'login');
        $key = $this->limitAttempts($request, 'login', $data['email'], 'login', 'email');

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], $request->boolean('remember'))) {
            $this->audit->record('auth.login_failed');

            return redirect()->route('login')->withErrors(['email' => 'The provided credentials are incorrect.'])
                ->withInput($request->only('email'));
        }

        RateLimiter::clear($key);
        $this->renewAuthenticationSession($request);
        $user = $this->authenticatedUser($request);
        $this->audit->record('auth.logged_in', $user);

        return $this->afterAuthentication($user);
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->audit->record('auth.logged_out', $user instanceof User ? $user : null);

        return redirect()->route('login');
    }

    public function verificationNotice(Request $request): View|RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        return $user->hasVerifiedEmail() ? $this->afterAuthentication($user) : view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $user = $this->authenticatedUser($request);
        $wasVerified = $user->hasVerifiedEmail();
        $request->fulfill();

        if (! $wasVerified) {
            $this->audit->record('auth.email_verified', $user, $user);
        }

        return $this->afterAuthentication($user)->with('status', 'Your email address has been verified.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        if ($user->hasVerifiedEmail()) {
            return $this->afterAuthentication($user);
        }

        $this->limitAttempts($request, 'verification', (string) $user->getKey(), 'verification.notice', 'email', 3);
        $user->sendEmailVerificationNotification();
        $this->audit->record('auth.verification_sent', $user);

        return redirect()->route('verification.notice')->with('status', 'A new verification link has been sent.');
    }

    public function forgotForm(): View
    {
        return view('auth.forgot-password');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $this->normalizeEmail($request);
        $data = $this->validateInput($request, ['email' => ['required', 'string', 'email', 'max:254']], 'password.request');
        $this->limitAttempts($request, 'password.email', $data['email'], 'password.request', 'email');
        Password::sendResetLink(['email' => $data['email']]);
        $this->audit->record('auth.password_reset_requested');

        return redirect()->route('password.request')->with('status', 'If an account matches that email, a password reset link will be sent.');
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => is_string($request->input('email')) ? $request->input('email') : '']);
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->normalizeEmail($request);
        $token = $request->input('token');
        $formParameters = is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) ? ['token' => $token] : [];
        $data = $this->validateInput($request, [
            'token' => ['required', 'string', 'max:256'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => $this->newPasswordRules(),
            'password_confirmation' => ['required', 'string', 'max:128'],
        ], $formParameters === [] ? 'password.request' : 'password.reset', $formParameters);
        $this->limitAttempts($request, 'password.reset', $data['email'], 'password.request', 'email');

        $status = Password::reset($data, function (User $user, #[\SensitiveParameter] string $password): void {
            $user->forceFill(['password' => Hash::make($password)])->setRememberToken(Str::random(60));
            $user->save();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getAuthIdentifier())->delete();
            }

            event(new PasswordReset($user));
            $this->audit->record('auth.password_reset', $user, $user);
        });

        if ($status !== Password::PasswordReset) {
            return redirect()->route('password.request')->withErrors(['email' => 'The password reset link is invalid or has expired.']);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your password has been reset. Please sign in.');
    }

    public function confirmForm(Request $request): View
    {
        $this->authenticatedUser($request);

        return view('auth.confirm-password');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $data = $this->validateInput($request, ['password' => ['required', 'string', 'max:128']], 'password.confirm');
        $key = $this->limitAttempts($request, 'password.confirm', (string) $user->getKey(), 'password.confirm', 'password');

        if (! Hash::check($data['password'], $user->password)) {
            $this->audit->record('auth.password_confirmation_failed', $user);

            return redirect()->route('password.confirm')->withErrors(['password' => 'The provided password is incorrect.']);
        }

        RateLimiter::clear($key);
        $request->session()->passwordConfirmed();
        $request->session()->forget('url.intended');
        $this->audit->record('auth.password_confirmed', $user);

        return $this->afterAuthentication($user);
    }

    public function mfaSetup(Request $request): View|RedirectResponse
    {
        $user = $this->administrator($request);

        if ($user->mfa_confirmed_at !== null) {
            return redirect()->route('mfa.challenge');
        }

        if (! $this->recentlyConfirmedPassword($request)) {
            return redirect()->route('password.confirm');
        }

        $pending = $this->pendingEnrollment($request, $user);

        if ($pending === null) {
            $pending = [
                'user_id' => $user->getAuthIdentifier(),
                'secret' => Crypt::encryptString($this->mfa->generateSecret()),
                'expires_at' => now()->addMinutes(10)->getTimestamp(),
            ];
            $request->session()->put('admin_mfa_enrollment', $pending);
        }

        $secret = Crypt::decryptString($pending['secret']);

        return view('auth.mfa-setup', ['secret' => $secret, 'provisioningUri' => $this->mfa->provisioningUri($user, $secret)]);
    }

    public function mfaEnable(Request $request): RedirectResponse
    {
        $user = $this->administrator($request);

        if ($user->mfa_confirmed_at !== null) {
            return redirect()->route('mfa.challenge');
        }

        if (! $this->recentlyConfirmedPassword($request)) {
            return redirect()->route('password.confirm');
        }

        $pending = $this->pendingEnrollment($request, $user);

        if ($pending === null) {
            return redirect()->route('mfa.setup')->withErrors(['code' => 'Your setup has expired. Please start again.']);
        }

        $data = $this->validateInput($request, ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']], 'mfa.setup');
        $key = $this->limitAttempts($request, 'mfa', (string) $user->getKey(), 'mfa.setup', 'code');

        if (! $this->mfa->enable($user, Crypt::decryptString($pending['secret']), $data['code'])) {
            $this->audit->record('auth.mfa_enrollment_failed', $user);

            return redirect()->route('mfa.setup')->withErrors(['code' => 'The code is invalid or has already been used.']);
        }

        $this->completeMfa($request, $user, $key);
        $this->audit->record('auth.mfa_enabled', $user, $user);

        return redirect('/')->with('status', 'Administrator authentication is enabled.');
    }

    public function mfaChallenge(Request $request): View|RedirectResponse
    {
        $user = $this->administrator($request);

        if ($user->mfa_confirmed_at === null || ! $user->mfa_secret) {
            return redirect()->route('mfa.setup');
        }

        return $request->session()->get('admin_mfa_verified') === $user->getAuthIdentifier()
            ? redirect('/') : view('auth.mfa-challenge');
    }

    public function mfaVerify(Request $request): RedirectResponse
    {
        $user = $this->administrator($request);

        if ($user->mfa_confirmed_at === null || ! $user->mfa_secret) {
            return redirect()->route('mfa.setup');
        }

        $data = $this->validateInput($request, ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']], 'mfa.challenge');
        $key = $this->limitAttempts($request, 'mfa', (string) $user->getKey(), 'mfa.challenge', 'code');

        if (! $this->mfa->verify($user, $data['code'])) {
            $this->audit->record('auth.mfa_challenge_failed', $user);

            return redirect()->route('mfa.challenge')->withErrors(['code' => 'The code is invalid or has already been used.']);
        }

        $this->completeMfa($request, $user, $key);
        $this->audit->record('auth.mfa_verified', $user);

        return redirect('/');
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function administrator(Request $request): User
    {
        $user = $this->authenticatedUser($request);
        abort_unless($user->is_admin, 403);

        return $user;
    }

    private function afterAuthentication(User $user): RedirectResponse
    {
        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        if ($user->is_admin) {
            return redirect()->route($user->mfa_confirmed_at === null ? 'mfa.setup' : 'mfa.challenge');
        }

        return redirect('/');
    }

    private function renewAuthenticationSession(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->forget(['admin_mfa_verified', 'admin_mfa_enrollment', 'auth.password_confirmed_at', 'url.intended']);
    }

    private function completeMfa(Request $request, User $user, string $limiterKey): void
    {
        RateLimiter::clear($limiterKey);
        $request->session()->regenerate();
        $request->session()->forget(['admin_mfa_enrollment', 'url.intended']);
        $request->session()->put('admin_mfa_verified', $user->getAuthIdentifier());
    }

    private function recentlyConfirmedPassword(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        return is_int($confirmedAt) && $confirmedAt <= now()->getTimestamp() && $confirmedAt >= now()->getTimestamp() - 900;
    }

    /** @return array{user_id: int|string, secret: string, expires_at: int}|null */
    private function pendingEnrollment(Request $request, User $user): ?array
    {
        $pending = $request->session()->get('admin_mfa_enrollment');

        if (! is_array($pending) || ($pending['user_id'] ?? null) !== $user->getAuthIdentifier()
            || ! is_string($pending['secret'] ?? null) || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] <= now()->getTimestamp()) {
            $request->session()->forget('admin_mfa_enrollment');

            return null;
        }

        return $pending;
    }

    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
    }

    /** @return array<int, string> */
    private function newPasswordRules(): array
    {
        return ['required', 'string', 'min:12', 'max:128', 'confirmed', 'not_regex:/\x00/'];
    }

    /**
     * @param  array<string, array<int, string|Closure>>  $rules
     * @param  array<string, string>  $formParameters
     * @return array<string, mixed>
     */
    private function validateInput(Request $request, array $rules, string $formRoute, array $formParameters = []): array
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator, redirect()->route($formRoute, $formParameters)->withErrors($validator)
                ->withInput(array_filter($request->only(['name', 'email']), fn (mixed $value): bool => is_string($value))));
        }

        return $validator->validated();
    }

    private function limitAttempts(Request $request, string $action, string $identifier, string $formRoute, string $field, int $maximum = 5): string
    {
        $key = 'auth:'.$action.':'.hash('sha256', $identifier.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            $validator = Validator::make([], []);
            $validator->errors()->add($field, 'Too many attempts. Please try again in a minute.');

            throw new ValidationException($validator, redirect()->route($formRoute)->withErrors($validator));
        }

        RateLimiter::hit($key, 60);

        return $key;
    }
}
