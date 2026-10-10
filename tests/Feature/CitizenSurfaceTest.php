<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeReport;
use App\Models\Report;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\AttachmentStorage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CitizenSurfaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Origin = 'https://10.35.190.193:8443';

    private function enableCitizenSurface(): void
    {
        config(['moshkelti.citizen_only' => true, 'moshkelti.citizen_origin' => self::Origin, 'app.url' => self::Origin]);
    }

    public function test_every_registered_admin_endpoint_is_unavailable_to_citizens_and_guests(): void
    {
        $this->enableCitizenSurface();
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin')) {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', (string) Str::uuid(), $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->call($method, self::Origin.'/'.$path)->assertNotFound();
            }
        }
        $this->actingAs(User::factory()->create())->get(self::Origin.'/admin/login')->assertNotFound();
    }

    public function test_injected_admin_session_is_refused_even_on_public_pages(): void
    {
        $this->enableCitizenSurface();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->withSession(['admin_mfa_verified' => 'injected'])->get(self::Origin.'/news')->assertForbidden();
        $this->assertGuest();
        $this->assertNull(session('admin_mfa_verified'));
    }

    public function test_citizen_login_has_no_admin_link_and_uses_secure_isolated_cookie(): void
    {
        $this->enableCitizenSurface();
        $response = $this->get(self::Origin.'/login?lang=en')->assertOk()->assertDontSee('Administrator sign in');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $cookies = collect($response->headers->getCookies());
        $cookie = $cookies->first(fn ($cookie) => $cookie->getName() === '__Host-moshkelti_citizen');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $response->assertSee(self::Origin.'/login', false);
    }

    public function test_plaintext_wrong_port_and_unapproved_host_are_refused(): void
    {
        $this->enableCitizenSurface();
        $response = $this->get('http://10.35.190.193:8443/login')->assertStatus(400)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('https://10.35.190.193/login')->assertStatus(400);
        $this->get('https://example.test:8443/login')->assertStatus(400);
    }

    public function test_owner_evidence_links_are_signed_https_inline_previews_and_still_private(): void
    {
        $this->enableCitizenSurface();
        $this->freezeTime();
        Storage::fake('private');
        config(['moshkelti.require_scanner' => false, 'moshkelti.scanner_binary' => null]);
        $report = Report::factory()->create();
        $attachment = app(AttachmentStorage::class)->store($report, UploadedFile::fake()->image('evidence.png', 64, 64));
        Storage::disk('private')->assertExists($attachment->path);
        $this->actingAs($report->user);
        $response = $this->get(self::Origin.'/reports/'.$report->id)->assertOk();
        $url = $response->viewData('attachmentUrls')[$attachment->id];
        $this->assertStringStartsWith(self::Origin.'/attachments/'.$attachment->id.'/preview?', $url);
        $preview = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringStartsWith('inline;', $preview->headers->get('Content-Disposition'));
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($report->user)->get(str_replace('/preview?', '?', $url))->assertForbidden();
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_server_configuration_fails_closed(string $origin): void
    {
        $this->enableCitizenSurface();
        config(['moshkelti.citizen_origin' => $origin]);
        $this->get(self::Origin.'/login')->assertStatus(503);
    }

    public static function invalidOrigins(): array
    {
        return [[''], ['http://10.35.190.193:8443'], ['https://127.0.0.1:8443'],
            ['https://8.8.8.8:8443'], ['https://10.35.190.193:8443/path']];
    }

    public function test_loopback_proxy_preserves_https_origin_and_remote_client_cannot_spoof_it(): void
    {
        $this->enableCitizenSurface();
        $headers = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '8443'];
        $this->call('GET', 'http://10.35.190.193:8443/login', [], [], [], $headers)
            ->assertOk()->assertSee(self::Origin.'/login', false);
        $headers['REMOTE_ADDR'] = '10.35.190.20';
        $this->call('GET', 'http://10.35.190.193:8443/login', [], [], [], $headers)->assertStatus(400);
    }

    public function test_active_citizen_reuses_submission_queue_and_idempotency(): void
    {
        $this->enableCitizenSurface();
        Queue::fake([AnalyzeReport::class]);
        $this->actingAs(User::factory()->create());
        $payload = ['title' => 'A public road pothole', 'description' => 'A large pothole is damaging vehicles on this public road.',
            'governorate' => 'Amman', 'category' => 'roads', 'latitude' => 31.95, 'longitude' => 35.91,
            'consent' => '1', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson(self::Origin.'/reports', $payload)->assertStatus(202);
        $this->postJson(self::Origin.'/reports', $payload)->assertStatus(202);
        $this->assertDatabaseCount('reports', 1);
        Queue::assertPushed(AnalyzeReport::class, 1);
    }

    #[DataProvider('restrictions')]
    public function test_restricted_citizens_keep_read_only_access_and_appeals(string $type): void
    {
        $this->enableCitizenSurface();
        $user = User::factory()->create();
        UserRestriction::factory()->create(['user_id' => $user->id, 'type' => $type, 'ends_at' => $type === 'banned' ? null : now()->addHour()]);
        $report = Report::factory()->for($user)->create();
        $this->actingAs($user)->get(self::Origin.'/news')->assertOk();
        $this->get(self::Origin.'/my-reports')->assertOk();
        $this->postJson(self::Origin.'/reports', [])->assertForbidden()->assertJsonPath('code', 'submission_not_allowed');
        $this->postJson(self::Origin.'/reports/'.$report->id.'/attachments', [])->assertForbidden();
        $this->postJson(self::Origin.'/account/appeal', ['reason' => 'Please review this account restriction fairly.'])->assertStatus(202);
    }

    public static function restrictions(): array
    {
        return [['temporary_suspend'], ['banned']];
    }

    public function test_desktop_administrator_access_is_unchanged_and_client_flags_cannot_enable_mode(): void
    {
        config(['moshkelti.citizen_only' => false]);
        $this->get('/admin/login')->assertOk();
        $admin = User::factory()->create(['is_admin' => true, 'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now()]);
        $this->actingAs($admin)->withSession(['admin_mfa_verified' => $admin->id])
            ->withHeaders(['User-Agent' => 'MoshkeltiCitizen', 'X-Citizen-Only' => 'true'])
            ->get('/admin?citizen_only=true')->assertOk();
    }
}
