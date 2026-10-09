<?php

namespace Tests\Feature;

use App\Models\IdentityRegistrationChallenge;
use App\Models\RegistryCitizen;
use App\Models\User;
use App\Notifications\IdentityVerificationCode;
use App\Notifications\LocalSmsChannel;
use App\Services\NationalIdentity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileIdentityAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Password = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'identity.lookup_key' => str_repeat('a', 64), 'identity.registry_lookup_key' => str_repeat('r', 64),
            'identity.driver' => 'mock_database', 'identity.sms_driver' => 'local',
            'database.connections.registry' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('registry');
        foreach (glob(database_path('migrations/registry/*.php')) as $path) {
            (require $path)->up();
        }
    }

    public function test_three_step_signup_does_not_show_password_fields_until_valid_sms_code(): void
    {
        $this->freezeTime();
        Notification::fake();
        $this->citizen();
        $this->get(route('register'))->assertOk()->assertSee('name="mobile_number"', false)->assertDontSee('name="password"', false);
        $code = $this->start();
        $this->get(route('register'))->assertSee('name="code"', false)->assertDontSee('name="password"', false);
        $this->post(route('register.store'), $this->password())->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);

        $this->post(route('register.verify'), ['code' => $code])->assertRedirectToRoute('register')->assertSessionHasNoErrors();
        $this->get(route('register'))->assertSee('name="password"', false)->assertDontSee('name="code"', false);
        $this->assertDatabaseCount('users', 0);
        $this->post(route('register.store'), $this->password())->assertRedirectToRoute('home');

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('+962790000001', $user->mobile_number);
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->canSubmitIssues());
        $this->assertNotSame('+962790000001', DB::table('users')->value('mobile_number'));
        $this->assertArrayNotHasKey('mobile_number', $user->toArray());
        $this->post(route('logout'));
        $this->post(route('login.store'), ['national_id' => '0000000001', 'password' => self::Password])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_mobile_is_not_sent_an_otp_and_has_no_identity_disclosure(): void
    {
        Notification::fake();
        $this->citizen();
        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => '0790000009', 'registry_consent' => '1'])
            ->assertRedirectToRoute('register')->assertSessionMissing('_old_input.mobile_number');
        Notification::assertNothingSent();
        $this->assertNull(IdentityRegistrationChallenge::query()->sole()->registry_subject_id);
        $this->get(route('register'))->assertDontSee('citizen@example.test')->assertDontSee('مواطن اختباري');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidMobiles(): array
    {
        return ['missing' => [''], 'array' => [['0790000001']], 'landline' => ['061234567'],
            'international other country' => ['+15551234567'], 'letters' => ['079000000x'], 'oversized' => [str_repeat('9', 30)]];
    }

    #[DataProvider('invalidMobiles')]
    public function test_invalid_mobile_is_rejected_before_lookup_or_sms(mixed $mobile): void
    {
        Notification::fake();
        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => $mobile, 'registry_consent' => '1'])
            ->assertSessionHasErrors('mobile_number')->assertSessionMissing('_old_input.mobile_number');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('identity_registration_challenges', 0);
    }

    public function test_arabic_mobile_digits_and_international_form_match_the_same_registry_contact(): void
    {
        Notification::fake();
        $this->citizen();
        $this->post(route('register.identity'), ['national_id' => '٠٠٠٠٠٠٠٠٠١', 'mobile_number' => '٠٧٩٠٠٠٠٠٠١', 'registry_consent' => '1'])
            ->assertRedirectToRoute('register');
        Notification::assertSentOnDemand(IdentityVerificationCode::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['sms'] === '+962790000001');
    }

    public function test_otp_replay_cannot_reverify_or_extend_password_deadline(): void
    {
        $this->freezeTime();
        Notification::fake();
        $this->citizen();
        $code = $this->start();
        $this->post(route('register.verify'), ['code' => $code])->assertSessionHasNoErrors();
        $expiry = IdentityRegistrationChallenge::query()->sole()->expires_at->getTimestamp();
        $this->travel(61)->seconds();
        $this->post(route('register.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertSame($expiry, IdentityRegistrationChallenge::query()->sole()->expires_at->getTimestamp());
        $this->travel(5)->minutes();
        $this->post(route('register.store'), $this->password())->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_mobile_change_after_verification_invalidates_password_creation(): void
    {
        Notification::fake();
        $citizen = $this->citizen();
        $code = $this->start();
        $this->post(route('register.verify'), ['code' => $code])->assertSessionHasNoErrors();
        $citizen->forceFill(['mobile_number' => '+962790000002'])->save();
        $this->post(route('register.store'), $this->password())->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);
        $this->assertNotNull(IdentityRegistrationChallenge::query()->sole()->consumed_at);
    }

    public function test_citizen_login_rejects_email_and_administrator_endpoint_rejects_citizens(): void
    {
        $user = User::factory()->create(['password' => self::Password]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => self::Password])->assertSessionHasErrors(['national_id', 'email']);
        $this->assertGuest();
        $this->post(route('admin.login.store'), ['email' => $user->email, 'password' => self::Password])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get(route('login'))->assertSee('name="national_id"', false)->assertDontSee('name="email"', false);
    }

    public function test_national_id_login_uses_normalized_digits_and_never_flashes_the_identifier(): void
    {
        $user = User::factory()->create(['national_id' => '0000000001', 'password' => self::Password]);
        $this->post(route('login.store'), ['national_id' => '٠٠٠٠٠٠٠٠٠١', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('national_id')->assertSessionMissing('_old_input.national_id')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->post(route('login.store'), ['national_id' => '٠٠٠٠٠٠٠٠٠١', 'password' => self::Password])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_local_sms_channel_writes_only_to_private_demo_sink_and_refuses_production(): void
    {
        Log::shouldReceive('channel')->once()->with('sms')->andReturnSelf();
        Log::shouldReceive('debug')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'SIMULATED SMS')
            && str_contains($message, '123456') && $context === ['destination_suffix' => '0001']);
        $notifiable = (new AnonymousNotifiable)->route('sms', '+962790000001');
        (new LocalSmsChannel)->send($notifiable, new IdentityVerificationCode('123456'));
        $this->app->instance('env', 'production');
        $this->expectException(\LogicException::class);
        (new LocalSmsChannel)->send($notifiable, new IdentityVerificationCode('123456'));
    }

    private function citizen(): RegistryCitizen
    {
        return RegistryCitizen::factory()->create(['national_id' => '0000000001', 'national_id_hash' => app(NationalIdentity::class)->fingerprint('0000000001', true),
            'full_name' => 'مواطن اختباري', 'email' => 'citizen@example.test', 'mobile_number' => '+962790000001',
            'governorate' => 'Amman', 'is_active' => true]);
    }

    private function start(): string
    {
        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => '0790000001', 'registry_consent' => '1'])
            ->assertRedirectToRoute('register')->assertSessionHasNoErrors();
        Notification::assertSentOnDemand(IdentityVerificationCode::class, fn ($notification, $channels, $notifiable) => $channels === [LocalSmsChannel::class]
            && $notifiable->routes['sms'] === '+962790000001');

        return Notification::sent(new AnonymousNotifiable, IdentityVerificationCode::class)->last()->code;
    }

    /** @return array{password: string, password_confirmation: string} */
    private function password(): array
    {
        return ['password' => self::Password, 'password_confirmation' => self::Password];
    }
}
