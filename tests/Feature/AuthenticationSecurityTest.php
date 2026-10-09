<?php

namespace Tests\Feature;

use App\Contracts\CitizenRegistry;
use App\Http\Middleware\RequireAdmin;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserRestriction;
use App\Notifications\IdentityVerificationCode;
use App\Services\AdminMfa;
use App\Services\CitizenIdentity;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Password = 'correct-horse-battery-staple';

    private const TotpSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['identity.lookup_key' => str_repeat('t', 64)]);
        Route::get('/_authentication-security/admin', fn () => response('Administrator access'))
            ->middleware(['web', RequireAdmin::class]);
    }

    public function test_registration_creates_only_a_citizen_after_registry_contact_verification(): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), $this->registrationPayload());

        $response->assertRedirectToRoute('home');
        $user = User::query()->where('email', 'citizen@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertNull($user->mfa_secret);
        $this->assertTrue(Hash::check(self::Password, $user->password));
        Notification::assertSentOnDemand(IdentityVerificationCode::class);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'event' => 'auth.registered']);
    }

    /** @return array<string, array{string, mixed}> */
    public static function unexpectedRegistrationFields(): array
    {
        return [
            'role' => ['role', 'admin'],
            'admin flag' => ['is_admin', true],
            'email verification' => ['email_verified_at', '2026-01-01'],
            'MFA secret' => ['mfa_secret', self::TotpSecret],
            'MFA confirmation' => ['mfa_confirmed_at', '2026-01-01'],
            'MFA replay counter' => ['mfa_last_counter', null],
            'unknown null key' => ['unexpected', null],
            'method override' => ['_method', 'POST'],
        ];
    }

    #[DataProvider('unexpectedRegistrationFields')]
    public function test_registration_rejects_unexpected_fields(string $field, mixed $value): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), [...$this->registrationPayload(), $field => $value]);

        $response->assertRedirectToRoute('register')->assertSessionHasErrors([
            'registration' => 'The registration form contains unexpected fields.',
        ]);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'citizen@example.com']);
        Notification::assertCount(1);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidRegistrationInput(): array
    {
        return [
            'missing code' => [['code' => ''], 'code'],
            'HTML code' => [['code' => '<img src=x onerror=alert(1)>'], 'code'],
            'short code' => [['code' => '12345'], 'code'],
            'long code' => [['code' => '1234567'], 'code'],
            'non-numeric code' => [['code' => 'abcdef'], 'code'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'long password' => [['password' => str_repeat('a', 129), 'password_confirmation' => str_repeat('a', 129)], 'password'],
            'password mismatch' => [['password_confirmation' => 'different-password'], 'password'],
            'null byte password' => [['password' => "long-password\x00", 'password_confirmation' => "long-password\x00"], 'password'],
        ];
    }

    #[DataProvider('invalidRegistrationInput')]
    public function test_registration_validates_verification_codes_and_passwords(array $changes, string $field): void
    {
        $payload = $this->registrationPayload();
        $response = $field === 'code'
            ? $this->post(route('register.verify'), $changes)
            : $this->post(route('register.store'), array_replace($payload, $changes));

        $response->assertRedirectToRoute('register')->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_uses_the_plain_arabic_name_and_normalized_email_from_the_registry(): void
    {
        $response = $this->post(route('register.store'), $this->registrationPayload());

        $response->assertRedirectToRoute('home');
        $this->assertDatabaseHas('users', ['name' => 'أحمد الخطيب', 'email' => 'citizen@example.com']);
        Notification::assertSentOnDemand(IdentityVerificationCode::class);
    }

    public function test_registration_does_not_flash_array_values_into_text_fields(): void
    {
        $this->registrationPayload();
        $response = $this->post(route('register.verify'), ['code' => ['unexpected']]);

        $response->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
        $this->get(route('register'))->assertOk();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_argon2id_preserves_all_128_password_characters(): void
    {
        Notification::fake();
        config(['hashing.driver' => 'argon2id', 'hashing.argon.memory' => 1024, 'hashing.argon.time' => 2]);
        $password = str_repeat('a', 127).'b';

        $response = $this->post(route('register.store'), array_replace($this->registrationPayload(), [
            'password' => $password, 'password_confirmation' => $password,
        ]));

        $response->assertRedirectToRoute('home');
        $user = User::query()->sole();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check(str_repeat('a', 127).'c', $user->password));
        Notification::assertSentOnDemand(IdentityVerificationCode::class);
    }

    public function test_login_rotates_session_clears_privilege_state_and_ignores_external_intended_url(): void
    {
        $user = User::factory()->create(['password' => self::Password]);
        $this->withSession([
            'url.intended' => 'https://attacker.example/', 'admin_mfa_verified' => $user->id,
            'auth.password_confirmed_at' => now()->getTimestamp(), 'admin_mfa_enrollment' => ['secret' => 'stale'],
        ]);
        $oldSessionId = session()->getId();

        $response = $this->post(route('login.store'), ['national_id' => $user->national_id, 'password' => self::Password]);

        $response->assertRedirect('/')->assertSessionMissing('admin_mfa_verified')
            ->assertSessionMissing('auth.password_confirmed_at')->assertSessionMissing('admin_mfa_enrollment')
            ->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, session()->getId());
    }

    /** @return array<string, array{string}> */
    public static function restrictedAccountTypes(): array
    {
        return ['banned' => ['banned'], 'suspended' => ['temporary_suspend']];
    }

    #[DataProvider('restrictedAccountTypes')]
    public function test_restricted_accounts_can_log_in(string $type): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => self::Password]);
        UserRestriction::factory()->create([
            'user_id' => $user->id, 'type' => $type, 'reason' => 'Test restriction',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(),
        ]);

        $response = $this->post(route('login.store'), ['national_id' => $user->national_id, 'password' => self::Password]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->submissionAccess()['can_submit']);
    }

    public function test_unverified_login_redirects_to_verification(): void
    {
        $user = User::factory()->unverified()->create(['password' => self::Password]);

        $response = $this->post(route('login.store'), ['national_id' => $user->national_id, 'password' => self::Password]);

        $response->assertRedirectToRoute('verification.notice');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $user = User::factory()->create(['password' => self::Password]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['national_id' => $user->national_id, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('national_id');
        }

        $response = $this->post(route('login.store'), ['national_id' => $user->national_id, 'password' => self::Password]);

        $response->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_and_rotates_csrf_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['admin_mfa_verified' => $user->id, 'auth.password_confirmed_at' => now()->getTimestamp()]);
        $oldSessionId = session()->getId();
        $oldToken = session()->token();

        $response = $this->post(route('logout'));

        $response->assertRedirectToRoute('login')->assertSessionMissing('admin_mfa_verified')
            ->assertSessionMissing('auth.password_confirmed_at');
        $this->assertGuest();
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
    }

    public function test_verification_accepts_only_the_signed_link_for_the_current_user(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $response = $this->actingAs($user)->get($url);

        $response->assertRedirect('/');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'event' => 'auth.email_verified']);
    }

    public function test_verification_refuses_another_users_signed_link(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $otherUser->id, 'hash' => sha1($otherUser->email),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($otherUser->fresh()->email_verified_at);
    }

    public function test_verification_refuses_unsigned_and_expired_links(): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $parameters = ['id' => $user->id, 'hash' => sha1($user->email)];

        $this->actingAs($user)->get(route('verification.verify', $parameters))->assertForbidden();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->subMinute(), $parameters))->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verification_resend_notifies_unverified_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->post(route('verification.send'));

        $response->assertRedirectToRoute('verification.notice');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_forgot_password_does_not_disclose_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $existing = $this->post(route('password.email'), ['email' => $user->email]);
        $missing = $this->post(route('password.email'), ['email' => 'missing@example.com']);

        $existing->assertRedirectToRoute('password.request')->assertSessionHas('status', 'If an account matches that email, a password reset link will be sent.');
        $missing->assertRedirectToRoute('password.request')->assertSessionHas('status', 'If an account matches that email, a password reset link will be sent.');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_changes_password_rotates_remember_token_and_consumes_reset_token(): void
    {
        $user = User::factory()->create(['password' => self::Password]);
        $oldRememberToken = $user->remember_token;
        $token = Password::createToken($user);

        $response = $this->post(route('password.update'), [
            'email' => $user->email, 'token' => $token,
            'password' => 'a-new-secure-password', 'password_confirmation' => 'a-new-secure-password',
        ]);

        $response->assertRedirectToRoute('login');
        $this->assertGuest();
        $this->assertTrue(Hash::check('a-new-secure-password', $user->fresh()->password));
        $this->assertNotSame($oldRememberToken, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_reset', 'actor_id' => $user->id]);
        $this->post(route('password.update'), [
            'email' => $user->email, 'token' => $token, 'password' => self::Password, 'password_confirmation' => self::Password,
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('a-new-secure-password', $user->fresh()->password));
    }

    public function test_password_reset_rejects_a_short_password_without_consuming_token(): void
    {
        $user = User::factory()->create(['password' => self::Password]);
        $token = Password::createToken($user);

        $response = $this->post(route('password.update'), [
            'email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short',
        ]);

        $response->assertRedirectToRoute('password.reset', ['token' => $token])->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.token');
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertTrue(Hash::check(self::Password, $user->fresh()->password));
    }

    public function test_password_reset_revokes_the_users_database_sessions_and_preserves_other_accounts(): void
    {
        $user = User::factory()->create(['password' => self::Password]);
        $otherUser = User::factory()->create();
        $token = Password::createToken($user);
        foreach (['victim-session-one' => $user->id, 'victim-session-two' => $user->id, 'other-account-session' => $otherUser->id] as $id => $userId) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $userId, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp(),
            ]);
        }
        config(['session.driver' => 'database']);

        $response = $this->post(route('password.update'), [
            'email' => $user->email, 'token' => $token,
            'password' => 'a-new-secure-password', 'password_confirmation' => 'a-new-secure-password',
        ]);

        $response->assertRedirectToRoute('login');
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-account-session', 'user_id' => $otherUser->id]);
        $this->assertTrue(Hash::check('a-new-secure-password', $user->fresh()->password));
    }

    public function test_password_confirmation_sets_timestamp_and_ignores_external_intended_url(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => self::Password]);

        $response = $this->actingAs($user)->withSession(['url.intended' => 'https://attacker.example/'])
            ->post(route('password.confirm.store'), ['password' => self::Password]);

        $response->assertRedirect('/')->assertSessionHas('auth.password_confirmed_at', now()->getTimestamp())
            ->assertSessionMissing('url.intended');
    }

    public function test_wrong_password_does_not_confirm_password(): void
    {
        $user = User::factory()->create(['password' => self::Password]);

        $response = $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'incorrect-password']);

        $response->assertRedirectToRoute('password.confirm')->assertSessionHasErrors('password')
            ->assertSessionMissing('auth.password_confirmed_at')->assertSessionMissing('_old_input.password');
    }

    public function test_admin_login_requires_enrollment_or_challenge_and_clears_old_mfa(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'password' => self::Password]);

        $this->post(route('admin.login.store'), ['email' => $user->email, 'password' => self::Password])
            ->assertRedirectToRoute('mfa.setup');
        $this->post(route('logout'));
        $user->forceFill(['mfa_secret' => self::TotpSecret, 'mfa_confirmed_at' => now()])->save();
        $this->withSession(['admin_mfa_verified' => $user->id])
            ->post(route('admin.login.store'), ['email' => $user->email, 'password' => self::Password])
            ->assertRedirectToRoute('mfa.challenge')->assertSessionMissing('admin_mfa_verified');
    }

    public function test_mfa_setup_requires_recent_password_confirmation(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->get(route('mfa.setup'));

        $response->assertRedirectToRoute('password.confirm')->assertSessionMissing('admin_mfa_enrollment');
    }

    public function test_mfa_enrollment_rejects_stale_or_future_password_confirmation(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->subSeconds(901)->getTimestamp()])
            ->get(route('mfa.setup'))->assertRedirectToRoute('password.confirm');
        $this->withSession(['auth.password_confirmed_at' => now()->addSecond()->getTimestamp()])
            ->get(route('mfa.setup'))->assertRedirectToRoute('password.confirm');

        $this->assertNull($user->fresh()->mfa_secret);
        $this->assertNull(session('admin_mfa_enrollment'));
    }

    public function test_mfa_setup_creates_an_encrypted_user_bound_pending_secret_without_enabling_mfa(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
            ->get(route('mfa.setup'));

        $response->assertOk()->assertViewIs('auth.mfa-setup')->assertHeader('Cache-Control', 'no-store, private');
        $pending = session('admin_mfa_enrollment');
        $secret = Crypt::decryptString($pending['secret']);
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secret);
        $this->assertSame($user->id, $pending['user_id']);
        $this->assertSame(now()->addMinutes(10)->getTimestamp(), $pending['expires_at']);
        $this->assertNotSame($secret, $pending['secret']);
        $this->assertNull($user->fresh()->mfa_confirmed_at);
        $this->assertNull($user->fresh()->mfa_secret);
    }

    public function test_mfa_enrollment_persists_encrypted_secret_consumes_code_and_binds_session(): void
    {
        $this->travelTo(Carbon::createFromTimestampUTC(59));
        $user = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($user)->withSession([
            'auth.password_confirmed_at' => now()->getTimestamp(), 'admin_mfa_enrollment' => $this->pendingEnrollment($user),
        ])->post(route('mfa.enable'), ['code' => '287082', 'secret' => 'untrusted']);

        $response->assertRedirect('/')->assertSessionHas('admin_mfa_verified', $user->id)
            ->assertSessionMissing('admin_mfa_enrollment')->assertSessionMissing('_old_input.code');
        $this->assertSame(self::TotpSecret, $user->fresh()->mfa_secret);
        $this->assertNotSame(self::TotpSecret, $user->fresh()->getRawOriginal('mfa_secret'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'mfa_last_counter' => 1]);
        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
        $this->assertFalse(app(AdminMfa::class)->verify($user, '287082'));
    }

    public function test_mfa_enrollment_refuses_pending_secret_from_another_user_and_expired_setup(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['is_admin' => true]);
        $otherUser = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user)->withSession([
            'auth.password_confirmed_at' => now()->getTimestamp(), 'admin_mfa_enrollment' => $this->pendingEnrollment($otherUser),
        ])->post(route('mfa.enable'), ['code' => '287082'])->assertSessionHasErrors('code');

        $this->withSession(['admin_mfa_enrollment' => array_replace($this->pendingEnrollment($user), ['expires_at' => now()->getTimestamp()])])
            ->post(route('mfa.enable'), ['code' => '287082'])->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->mfa_secret);
        $this->assertNull($otherUser->fresh()->mfa_secret);
        $this->assertNull(session('admin_mfa_verified'));
    }

    public function test_mfa_enrollment_cannot_replace_a_confirmed_secret(): void
    {
        $user = $this->confirmedAdministrator();

        $response = $this->actingAs($user)->withSession([
            'auth.password_confirmed_at' => now()->getTimestamp(), 'admin_mfa_enrollment' => $this->pendingEnrollment($user),
        ])->post(route('mfa.enable'), ['code' => '287082']);

        $response->assertRedirectToRoute('mfa.challenge')->assertSessionMissing('admin_mfa_verified');
        $this->assertSame(self::TotpSecret, $user->fresh()->mfa_secret);
    }

    /** @return array<string, array{int, string}> */
    public static function rfc6238Vectors(): array
    {
        return [
            '59 seconds' => [59, '287082'],
            'leading zero' => [1111111109, '081804'],
            'next counter' => [1111111111, '050471'],
            '2009' => [1234567890, '005924'],
            '2033' => [2000000000, '279037'],
            '64-bit timestamp' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfc6238Vectors')]
    public function test_totp_accepts_rfc6238_sha1_vectors_and_refuses_replay(int $timestamp, string $code): void
    {
        $this->travelTo(Carbon::createFromTimestampUTC($timestamp));
        $user = $this->confirmedAdministrator();
        $staleUser = $user->fresh();

        $this->assertTrue(app(AdminMfa::class)->verify($user, $code));
        $this->assertFalse(app(AdminMfa::class)->verify($staleUser, $code));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'mfa_last_counter' => intdiv($timestamp, 30)]);
    }

    public function test_mfa_challenge_binds_session_and_rotates_session_id(): void
    {
        $this->travelTo(Carbon::createFromTimestampUTC(59));
        $user = $this->confirmedAdministrator();
        $this->actingAs($user)->withSession(['url.intended' => 'https://attacker.example/']);
        $oldSessionId = session()->getId();

        $response = $this->post(route('mfa.verify'), ['code' => '287082']);

        $response->assertRedirect('/')->assertSessionHas('admin_mfa_verified', $user->id)->assertSessionMissing('url.intended');
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'mfa_last_counter' => 1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.mfa_verified', 'actor_id' => $user->id]);
    }

    public function test_mfa_rejects_invalid_code_without_flashing_it(): void
    {
        $user = $this->confirmedAdministrator();

        $response = $this->actingAs($user)->post(route('mfa.verify'), ['code' => 'not-a-code']);

        $response->assertRedirectToRoute('mfa.challenge')->assertSessionHasErrors('code')
            ->assertSessionMissing('_old_input.code')->assertSessionMissing('admin_mfa_verified');
        $this->assertNull($user->fresh()->mfa_last_counter);
    }

    public function test_mfa_is_throttled_after_five_failed_challenges(): void
    {
        $this->travelTo(Carbon::createFromTimestampUTC(59));
        $user = $this->confirmedAdministrator();
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('mfa.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $response = $this->post(route('mfa.verify'), ['code' => '287082']);

        $response->assertTooManyRequests()
            ->assertSessionMissing('admin_mfa_verified');
        $this->assertNull($user->fresh()->mfa_last_counter);
    }

    public function test_totp_accepts_adjacent_counters_but_rejects_expired_codes(): void
    {
        $this->travelTo(Carbon::createFromTimestampUTC(29));
        $futureUser = $this->confirmedAdministrator();
        $pastUser = $this->confirmedAdministrator();
        $expiredUser = $this->confirmedAdministrator();
        $this->assertTrue(app(AdminMfa::class)->verify($futureUser, '287082'));
        $this->travelTo(Carbon::createFromTimestampUTC(89));
        $this->assertTrue(app(AdminMfa::class)->verify($pastUser, '287082'));

        $this->travelTo(Carbon::createFromTimestampUTC(119));

        $this->assertFalse(app(AdminMfa::class)->verify($expiredUser, '287082'));
        $this->assertNull($expiredUser->fresh()->mfa_last_counter);
    }

    public function test_citizens_cannot_access_mfa_enrollment_or_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('mfa.setup'))->assertForbidden();
        $this->post(route('mfa.enable'), ['code' => '287082'])->assertForbidden();
        $this->get(route('mfa.challenge'))->assertForbidden();
        $this->post(route('mfa.verify'), ['code' => '287082'])->assertForbidden();

        $this->assertNull($user->fresh()->mfa_secret);
    }

    public function test_admin_middleware_refuses_guests_and_citizens(): void
    {
        $this->get('/_authentication-security/admin')->assertRedirectToRoute('login');

        $this->actingAs(User::factory()->create())->get('/_authentication-security/admin')->assertForbidden();
    }

    public function test_admin_middleware_requires_enrollment_and_mfa_bound_to_the_current_user(): void
    {
        $unenrolled = User::factory()->create(['is_admin' => true]);
        $enrolled = $this->confirmedAdministrator();
        $otherAdmin = $this->confirmedAdministrator();
        $this->actingAs($unenrolled)->get('/_authentication-security/admin')->assertRedirectToRoute('mfa.setup');
        $this->actingAs($enrolled)->get('/_authentication-security/admin')->assertRedirectToRoute('mfa.challenge');

        $this->withSession(['admin_mfa_verified' => $otherAdmin->id])->get('/_authentication-security/admin')
            ->assertRedirectToRoute('mfa.challenge')->assertSessionMissing('admin_mfa_verified');

        $this->withSession(['admin_mfa_verified' => $enrolled->id])->get('/_authentication-security/admin')->assertOk();
    }

    public function test_auth_audit_metadata_contains_no_credentials_or_authenticator_codes(): void
    {
        $this->travelTo(Carbon::createFromTimestampUTC(59));
        $user = $this->confirmedAdministrator();
        $user->forceFill(['password' => self::Password])->save();
        $this->post(route('admin.login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        $this->post(route('admin.login.store'), ['email' => $user->email, 'password' => self::Password]);
        $this->post(route('mfa.verify'), ['code' => '287082']);

        $logs = AuditLog::query()->where('event', 'like', 'auth.%')->get();

        $this->assertCount(3, $logs);
        foreach ($logs as $log) {
            $this->assertSame([], $log->metadata);
        }
    }

    public function test_auth_templates_never_repopulate_identity_codes_or_passwords(): void
    {
        $this->withSession([
            '_old_input' => ['national_id' => '0000000001', 'code' => '123456', 'password' => self::Password],
        ])->get(route('register'))->assertOk()->assertDontSee('value="0000000001"', false)
            ->assertDontSee('value="123456"', false)->assertDontSee(self::Password);
    }

    public function test_authentication_forms_render_with_the_shared_layout(): void
    {
        $this->get(route('login'))->assertOk()->assertViewIs('auth.login');
        $this->get(route('password.request'))->assertOk()->assertViewIs('auth.forgot-password');
        $this->get(route('password.reset', ['token' => str_repeat('a', 64), 'email' => 'citizen@example.com']))
            ->assertOk()->assertViewIs('auth.reset-password');
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()->assertViewIs('auth.verify-email');
        $this->get(route('password.confirm'))->assertOk()->assertViewIs('auth.confirm-password');
        $this->actingAs($this->confirmedAdministrator())->get(route('mfa.challenge'))->assertOk()->assertViewIs('auth.mfa-challenge');
    }

    /** @return array{password: string, password_confirmation: string} */
    private function registrationPayload(): array
    {
        Notification::fake();
        $citizen = new CitizenIdentity('0199c7ff-aaaa-7000-8000-000000000001', '0000000001', 'أحمد الخطيب', 'citizen@example.com', 'Amman', '+962790000001');
        $this->mock(CitizenRegistry::class, function ($mock) use ($citizen): void {
            $mock->shouldReceive('lookup')->andReturn($citizen);
            $mock->shouldReceive('find')->andReturn($citizen);
        });

        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');
        $code = Notification::sent(new AnonymousNotifiable, IdentityVerificationCode::class)->last()->code;

        $this->post(route('register.verify'), ['code' => $code])->assertRedirectToRoute('register')->assertSessionHasNoErrors();

        return ['password' => self::Password, 'password_confirmation' => self::Password];
    }

    private function confirmedAdministrator(): User
    {
        return User::factory()->create(['is_admin' => true, 'mfa_secret' => self::TotpSecret, 'mfa_confirmed_at' => now()]);
    }

    /** @return array{user_id: int|string, secret: string, expires_at: int} */
    private function pendingEnrollment(User $user): array
    {
        return ['user_id' => $user->id, 'secret' => Crypt::encryptString(self::TotpSecret), 'expires_at' => now()->addMinutes(10)->getTimestamp()];
    }
}
