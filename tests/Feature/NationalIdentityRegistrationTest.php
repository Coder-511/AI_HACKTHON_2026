<?php

namespace Tests\Feature;

use App\Contracts\CitizenRegistry;
use App\Models\IdentityRegistrationChallenge;
use App\Models\RegistryCitizen;
use App\Models\User;
use App\Models\UserRestriction;
use App\Notifications\IdentityVerificationCode;
use App\Notifications\LocalSmsChannel;
use App\Services\DatabaseCitizenRegistry;
use App\Services\NationalIdentity;
use App\Services\RegistryRegistration;
use Database\Seeders\MockCitizenRegistrySeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NationalIdentityRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Password = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'identity.driver' => 'mock_database',
            'identity.lookup_key' => str_repeat('a', 64),
            'identity.registry_lookup_key' => str_repeat('r', 64),
            'database.connections.registry' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('registry');
        $migration = require database_path('migrations/registry/2026_10_09_140516_create_registry_citizens_table.php');
        $migration->up();
        (require database_path('migrations/registry/2026_10_09_143439_add_mobile_to_registry_citizens.php'))->up();
    }

    public function test_registry_is_separate_and_uses_encrypted_synthetic_records(): void
    {
        $this->seed(MockCitizenRegistrySeeder::class);

        $this->assertFalse(Schema::hasTable('registry_citizens'));
        $this->assertTrue(Schema::connection('registry')->hasTable('registry_citizens'));
        $this->assertSame(4, RegistryCitizen::query()->count());
        $record = app(CitizenRegistry::class)->lookup('0000000001');
        $this->assertSame('مواطن تجريبي أول', $record->fullName);
        $raw = DB::connection('registry')->table('registry_citizens')->where('id', $record->subjectId)->first();
        $this->assertNotSame('0000000001', $raw->national_id);
        $this->assertNotSame('citizen1@example.test', $raw->email);
        $this->assertNotSame('مواطن تجريبي أول', $raw->full_name);
        $this->assertNull(app(CitizenRegistry::class)->lookup('0000000004'));
    }

    public function test_verified_registration_uses_only_registry_identity_and_encrypts_the_id(): void
    {
        Notification::fake();
        $citizen = $this->citizen();
        $code = $this->start();
        $this->get(route('register'))->assertOk()->assertSee('Verification code')
            ->assertDontSee($citizen->full_name)->assertDontSee($citizen->email)
            ->assertDontSee('value="0000000001"', false);

        $response = $this->post(route('register.store'), $this->completion($code));

        $response->assertRedirectToRoute('home')->assertSessionMissing('identity_registration');
        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('أحمد الخطيب', $user->name);
        $this->assertSame('citizen@example.test', $user->email);
        $this->assertSame('0000000001', $user->national_id);
        $this->assertSame($citizen->id, $user->registry_subject_id);
        $this->assertNotNull($user->identity_verified_at);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertTrue($user->hasVerifiedCitizenIdentity());
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check(self::Password, $user->password));
        $this->assertNotSame('0000000001', DB::table('users')->value('national_id'));
        foreach (['national_id', 'national_id_hash', 'registry_subject_id', 'identity_verified_at'] as $field) {
            $this->assertArrayNotHasKey($field, $user->toArray());
        }
        $this->assertNotNull(IdentityRegistrationChallenge::query()->sole()->consumed_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.registered', 'actor_id' => $user->id]);
    }

    public function test_verification_is_delivered_only_to_the_registry_mobile_and_code_is_hashed(): void
    {
        Notification::fake();
        $this->freezeTime();
        $citizen = $this->citizen();
        $code = $this->start();
        Notification::assertSentOnDemand(IdentityVerificationCode::class, function ($notification, $channels, $notifiable) use ($citizen): bool {
            return $channels === [LocalSmsChannel::class] && $notifiable->routes['sms'] === $citizen->mobile_number;
        });
        $challenge = IdentityRegistrationChallenge::query()->sole();
        $this->assertTrue(Hash::check($code, $challenge->code_hash));
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertSame(now()->addMinutes(5)->getTimestamp(), $challenge->expires_at->getTimestamp());
        $this->assertArrayNotHasKey('code_hash', $challenge->toArray());
        $this->assertStringNotContainsString('0000000001', json_encode(session()->all()));
    }

    public function test_arabic_digits_are_normalized_without_losing_leading_zeros(): void
    {
        Notification::fake();
        $this->citizen();
        $code = $this->start('٠٠٠٠٠٠٠٠٠١');

        $this->post(route('register.store'), $this->completion($code))->assertRedirectToRoute('home');

        $this->assertSame('0000000001', User::query()->sole()->national_id);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidIds(): array
    {
        return [
            'too short' => ['123456789'], 'too long' => ['12345678901'],
            'SQL injection' => ["' OR 1=1 --"], 'URL' => ['http://127.0.0.1'],
            'HTML' => ['<script>x</script>'], 'array' => [['0000000001']],
            'integer instead of string' => [1], 'letters' => ['abcdefghij'],
            'unicode controls' => ['0000000001\\u{202E}'], 'scientific notation' => ['00000001e1'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_national_ids_are_rejected_without_flashing_sensitive_input(mixed $id): void
    {
        Notification::fake();
        $this->post(route('register.identity'), ['national_id' => $id, 'mobile_number' => '0790000001', 'registry_consent' => '1'])
            ->assertRedirectToRoute('register')->assertSessionHasErrors('national_id')->assertSessionMissing('_old_input.national_id');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('identity_registration_challenges', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registry_lookup_requires_explicit_consent(): void
    {
        Notification::fake();
        $this->post(route('register.identity'), ['national_id' => '0000000001'])->assertSessionHasErrors('registry_consent');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('identity_registration_challenges', 0);
    }

    public function test_client_cannot_supply_identity_email_roles_or_destinations(): void
    {
        Notification::fake();
        $this->citizen();
        $this->post(route('register.identity'), [
            'national_id' => '0000000001', 'mobile_number' => '0790000001', 'registry_consent' => '1',
            'email' => 'attacker@example.test', 'name' => 'Attacker', 'is_admin' => true, 'registry_url' => 'http://169.254.169.254/',
        ])->assertSessionHasErrors('registration');
        Notification::assertNothingSent();
        $code = $this->start();
        $this->post(route('register.store'), [...$this->completion($code), 'national_id' => '0000000002', 'name' => 'Attacker'])
            ->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unknown_inactive_and_existing_ids_have_the_same_public_response(): void
    {
        Notification::fake();
        $this->citizen();
        $this->citizen('0000000002', ['email' => 'inactive@example.test', 'is_active' => false]);
        $existing = User::factory()->create(['email' => 'existing@example.test']);
        $existing->forceFill(['national_id_hash' => app(NationalIdentity::class)->fingerprint('0000000001')])->save();

        foreach (['0000000001', '0000000002', '0000000009'] as $id) {
            $response = $this->post(route('register.identity'), ['national_id' => $id, 'mobile_number' => '0790000001', 'registry_consent' => '1']);
            $response->assertRedirectToRoute('register')
                ->assertSessionHas('status', 'If the ID and mobile match an eligible registry record, an SMS verification code was requested. It expires in five minutes. Local SMS is simulated.');
            $this->get(route('register'))->assertOk()->assertDontSee('existing@example.test')->assertDontSee('inactive@example.test');
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_valid_id_without_session_challenge_cannot_create_an_account(): void
    {
        $this->post(route('register.store'), $this->completion('123456'))->assertSessionHasErrors('registration');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_wrong_codes_increment_attempts_and_lock_after_five_guesses(): void
    {
        Notification::fake();
        $this->citizen();
        $code = $this->start();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('register.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
            $this->assertSame($attempt, IdentityRegistrationChallenge::query()->sole()->attempts);
        }
        $this->travel(61)->seconds();

        $this->post(route('register.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_expired_verification_code_is_rejected_using_server_time(): void
    {
        Notification::fake();
        $this->freezeTime();
        $this->citizen();
        $code = $this->start();
        $this->travel(5)->minutes();

        $this->post(route('register.verify'), ['code' => $code])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_stolen_challenge_id_does_not_work_in_a_different_session(): void
    {
        Notification::fake();
        $this->citizen();
        $code = $this->start();
        $challengeId = session('identity_registration.id');
        $this->withSession(['identity_registration' => ['id' => $challengeId, 'secret' => str_repeat('z', 64)]]);

        $this->post(route('register.verify'), ['code' => $code])->assertSessionHasErrors('code');

        $this->assertDatabaseCount('users', 0);
        $this->assertSame(0, IdentityRegistrationChallenge::query()->sole()->attempts);
    }

    public function test_restart_invalidates_the_previous_challenge_and_rotates_the_session(): void
    {
        Notification::fake();
        $this->citizen();
        $firstCode = $this->start();
        $first = session('identity_registration');
        $sessionId = session()->getId();
        $secondCode = $this->start();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotNull(IdentityRegistrationChallenge::query()->find($first['id'])->consumed_at);
        $this->assertNull(app(RegistryRegistration::class)->complete($first['id'], $first['secret'], self::Password));

        $this->post(route('register.store'), $this->completion($secondCode))->assertRedirectToRoute('home');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_consumed_code_cannot_be_replayed_and_database_enforces_unique_id(): void
    {
        Notification::fake();
        $this->citizen();
        $code = $this->start();
        $challenge = session('identity_registration');
        $this->post(route('register.store'), $this->completion($code))->assertRedirectToRoute('home');

        $this->assertNull(app(RegistryRegistration::class)->complete($challenge['id'], $challenge['secret'], self::Password));
        $this->assertDatabaseCount('users', 1);
        $duplicate = User::factory()->make(['email' => 'second@example.test']);
        $duplicate->forceFill(['national_id_hash' => app(NationalIdentity::class)->fingerprint('0000000001')]);
        $this->expectException(UniqueConstraintViolationException::class);
        $duplicate->save();
    }

    /** @return array<string, array{string}> */
    public static function restrictions(): array
    {
        return ['banned' => ['banned'], 'suspended' => ['temporary_suspend']];
    }

    #[DataProvider('restrictions')]
    public function test_restricted_user_cannot_create_a_second_account_with_the_same_id(string $type): void
    {
        Notification::fake();
        $citizen = $this->citizen();
        $user = User::factory()->create(['email' => mb_strtolower($citizen->email), 'password' => self::Password]);
        $user->forceFill(['national_id_hash' => app(NationalIdentity::class)->fingerprint('0000000001'), 'registry_subject_id' => $citizen->id])->save();
        UserRestriction::factory()->create(['user_id' => $user->id, 'type' => $type, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);

        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 1);
        $this->post(route('login.store'), ['national_id' => $citizen->national_id, 'password' => self::Password])->assertRedirect('/');
        $this->get(route('news.index'))->assertOk();
        $this->assertFalse($user->fresh()->canSubmitIssues());
    }

    public function test_registry_contact_changes_or_inactive_records_cancel_inflight_verification(): void
    {
        Notification::fake();
        $citizen = $this->citizen();
        $code = $this->start();
        $citizen->forceFill(['email' => 'changed@example.test'])->save();

        $this->post(route('register.store'), $this->completion($code))->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);
        $this->assertNotNull(IdentityRegistrationChallenge::query()->sole()->consumed_at);
    }

    public function test_deactivated_registry_record_cannot_complete_registration(): void
    {
        Notification::fake();
        $citizen = $this->citizen();
        $code = $this->start();
        $citizen->forceFill(['is_active' => false])->save();

        $this->post(route('register.store'), $this->completion($code))->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_parallel_challenges_cannot_register_the_same_identity_twice(): void
    {
        Notification::fake();
        $this->citizen();
        $service = app(RegistryRegistration::class);
        $first = $service->start('0000000001', '+962790000001', str_repeat('x', 64));
        $firstCode = Notification::sent(new AnonymousNotifiable, IdentityVerificationCode::class)->last()->code;
        $second = $service->start('0000000001', '+962790000001', str_repeat('y', 64));
        $secondCode = Notification::sent(new AnonymousNotifiable, IdentityVerificationCode::class)->last()->code;

        Notification::assertSentOnDemandTimes(IdentityVerificationCode::class, 2);
        $this->assertTrue($service->verifyCode($first->id, str_repeat('x', 64), $firstCode));
        $this->assertTrue($service->verifyCode($second->id, str_repeat('y', 64), $secondCode));
        $this->assertInstanceOf(User::class, $service->complete($first->id, str_repeat('x', 64), self::Password));
        $this->assertNull($service->complete($second->id, str_repeat('y', 64), self::Password));
        $this->assertDatabaseCount('users', 1);
        $this->assertNotNull($second->fresh()->consumed_at);
    }

    public function test_existing_email_account_is_never_silently_linked_or_overwritten(): void
    {
        Notification::fake();
        $this->citizen();
        $existing = User::factory()->create(['email' => 'citizen@example.test', 'name' => 'Existing administrator', 'is_admin' => true, 'national_id' => null, 'national_id_hash' => null]);

        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 1);
        $this->assertNull($existing->fresh()->national_id_hash);
        $this->assertSame('Existing administrator', $existing->fresh()->name);
        $this->assertTrue($existing->fresh()->is_admin);
    }

    public function test_tampered_registry_identifier_is_rejected_and_never_claimed(): void
    {
        Notification::fake();
        $citizen = $this->citizen();
        $citizen->forceFill(['national_id' => '0000000002'])->save();

        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'identity.registry_unavailable']);
    }

    public function test_failed_mail_delivery_invalidates_the_challenge_and_fails_closed(): void
    {
        $this->citizen();
        Notification::shouldReceive('send')->once()->withArgs(function (AnonymousNotifiable $notifiable, IdentityVerificationCode $notification): bool {
            return $notifiable->routes['sms'] === '+962790000001';
        })
            ->andThrow(new \RuntimeException('Mail unavailable'));

        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');

        $this->assertNotNull(IdentityRegistrationChallenge::query()->sole()->consumed_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'identity.delivery_failed']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_malformed_registry_records_and_database_outage_fail_closed(): void
    {
        Notification::fake();
        $this->citizen('0000000001', ['full_name' => '<script>alert(1)</script>']);
        $this->post(route('register.identity'), ['national_id' => '0000000001', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'identity.registry_unavailable']);
        $this->mock(CitizenRegistry::class)->shouldReceive('lookup')->andThrow(new \RuntimeException('Registry unavailable'));
        $this->post(route('register.identity'), ['national_id' => '0000000002', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registry_adapter_is_disabled_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\LogicException::class);

        app(DatabaseCitizenRegistry::class)->lookup('0000000001');
    }

    public function test_registry_has_no_public_browsing_or_lookup_api(): void
    {
        foreach (['/registry', '/registry/citizens', '/api/registry/0000000001'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_lookup_is_rate_limited_before_repeated_registry_and_mail_calls(): void
    {
        Notification::fake();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('register.identity'), ['national_id' => '0000000009', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');
        }
        $this->post(route('register.identity'), ['national_id' => '0000000009', 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertTooManyRequests();
        $this->assertDatabaseCount('identity_registration_challenges', 3);
        Notification::assertNothingSent();
    }

    public function test_security_audits_contain_no_national_id_email_or_verification_code(): void
    {
        Notification::fake();
        $this->citizen();
        $code = $this->start();
        $this->post(route('register.store'), $this->completion($code))->assertRedirectToRoute('home');

        $audit = json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString('0000000001', $audit);
        $this->assertStringNotContainsString('citizen@example.test', $audit);
        foreach (DB::table('audit_logs')->get() as $event) {
            $this->assertSame([], json_decode($event->metadata, true));
        }
    }

    private function citizen(string $nationalId = '0000000001', array $changes = []): RegistryCitizen
    {
        $citizen = new RegistryCitizen;
        $citizen->forceFill(array_replace([
            'national_id' => $nationalId, 'national_id_hash' => app(NationalIdentity::class)->fingerprint($nationalId, true),
            'full_name' => 'أحمد الخطيب', 'email' => 'CITIZEN@EXAMPLE.TEST', 'governorate' => 'Amman', 'mobile_number' => '+962790000001', 'is_active' => true,
        ], $changes))->save();

        return $citizen;
    }

    private function start(string $nationalId = '0000000001'): string
    {
        $this->post(route('register.identity'), ['national_id' => $nationalId, 'mobile_number' => '0790000001', 'registry_consent' => '1'])->assertRedirectToRoute('register');
        Notification::assertSentOnDemand(IdentityVerificationCode::class);
        $notification = Notification::sent(new AnonymousNotifiable, IdentityVerificationCode::class)->last();
        $this->assertNotNull($notification);

        return $notification->code;
    }

    /** @return array{password: string, password_confirmation: string} */
    private function completion(string $code): array
    {
        $this->post(route('register.verify'), ['code' => $code]);

        return ['password' => self::Password, 'password_confirmation' => self::Password];
    }
}
