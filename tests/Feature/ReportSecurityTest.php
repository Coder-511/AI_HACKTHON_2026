<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeReport;
use App\Models\AuditLog;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\ReportSubmission;
use App\Services\SecurityAudit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        Storage::fake('private');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['title' => 'A dangerous public road pothole', 'description' => 'A large pothole is damaging vehicles on this public road.',
            'governorate' => 'Amman', 'category' => 'roads', 'latitude' => 31.95, 'longitude' => 35.91,
            'consent' => '1', 'idempotency_key' => (string) Str::uuid()], $overrides);
    }

    public function test_active_verified_user_can_submit_and_owner_is_server_owned(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('reports.store'), $this->payload())->assertStatus(202)->assertJsonPath('status', 'processing');
        $report = Report::sole();
        $this->assertSame($user->id, $report->user_id);
        $this->assertNotSame($report->title, $report->getRawOriginal('title'));
        Queue::assertPushed(AnalyzeReport::class);
    }

    public static function restrictions(): array
    {
        return [['temporary_suspend'], ['banned']];
    }

    #[DataProvider('restrictions')]
    public function test_restricted_users_keep_reads_but_all_submission_endpoints_are_blocked(string $type): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->create(['user_id' => $user->id, 'status' => 'needs_more_information']);
        UserRestriction::factory()->create(['user_id' => $user->id, 'type' => $type, 'ends_at' => $type === 'banned' ? null : now()->addHour()]);
        $this->actingAs($user)->get(route('news.index'))->assertOk();
        $this->get(route('reports.index'))->assertOk();
        $this->getJson(route('reports.show', $report))->assertOk();
        $this->getJson(route('account.access'))->assertJsonPath('can_read', true)->assertJsonPath('can_submit', false);
        $this->postJson(route('reports.store'), $this->payload())->assertForbidden()->assertJsonPath('code', 'submission_not_allowed');
        $this->postJson(route('reports.attachments', $report), [])->assertForbidden();
        $this->patchJson(route('reports.update', $report), [])->assertForbidden();
        $this->postJson(route('reports.information', $report), [])->assertForbidden();
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_restrictions_are_checked_inside_service_without_middleware(): void
    {
        $user = User::factory()->create();
        UserRestriction::factory()->create(['user_id' => $user->id, 'type' => 'banned', 'ends_at' => null]);
        $this->expectException(HttpResponseException::class);
        app(ReportSubmission::class)->create($user, $this->payload());
    }

    public function test_expired_suspension_and_risk_flag_do_not_block_submission(): void
    {
        $user = User::factory()->create(['risk_flag' => 'high']);
        UserRestriction::factory()->create(['user_id' => $user->id, 'ends_at' => now()->subSecond()]);
        $this->assertTrue($user->canSubmitIssues());
        $this->actingAs($user)->postJson(route('reports.store'), $this->payload())->assertStatus(202);
    }

    public function test_suspension_timer_is_based_on_server_time(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        UserRestriction::factory()->create(['user_id' => $user->id, 'ends_at' => now()->addMinutes(30)]);
        $this->actingAs($user)->getJson(route('account.access'))->assertJsonPath('remaining_seconds', 1800)
            ->assertJsonPath('available_at', now()->addMinutes(30)->toIso8601String());
    }

    public function test_unverified_and_admin_accounts_cannot_create_citizen_reports(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->postJson(route('reports.store'), $this->payload())->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->postJson(route('reports.store'), $this->payload())->assertForbidden();
    }

    public function test_private_report_is_not_discoverable_by_another_user_or_guest(): void
    {
        $report = Report::factory()->create();
        $this->getJson(route('reports.show', $report))->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson(route('reports.show', $report))->assertNotFound();
    }

    public function test_public_report_does_not_expose_private_content_location_or_owner(): void
    {
        $report = Report::factory()->create(['title' => 'SECRET-TITLE', 'description' => 'PRIVATE-CONTACT-DETAILS',
            'public_title' => 'Road damage', 'public_summary' => 'A reviewed public road issue.', 'published_at' => now()]);
        $response = $this->getJson(route('reports.show', $report))->assertOk()->assertJsonPath('data.title', 'Road damage');
        foreach (['description', 'latitude', 'longitude', 'user_id', 'attachments', 'analyses', 'citizen_reason'] as $field) {
            $response->assertJsonMissingPath('data.'.$field);
        }
        $this->get(route('reports.show', $report))->assertOk()->assertDontSee('PRIVATE-CONTACT-DETAILS')->assertDontSee('SECRET-TITLE');
        $this->getJson(route('reports.index'))->assertOk()->assertDontSee('PRIVATE-CONTACT-DETAILS');
    }

    public function test_banned_owner_sees_status_without_private_attachment_or_original_text(): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->create(['user_id' => $user->id]);
        UserRestriction::factory()->create(['user_id' => $user->id, 'type' => 'banned', 'ends_at' => null]);
        $this->actingAs($user)->getJson(route('reports.show', $report))->assertOk()->assertJsonPath('data.status', 'needs_review')->assertJsonMissingPath('data.description');
        $attachment = ReportAttachment::factory()->create(['report_id' => $report->id]);
        $url = URL::temporarySignedRoute('attachments.download', now()->addMinutes(5), ['attachment' => $attachment->id]);
        $this->get($url)->assertNotFound();
    }

    public function test_sql_text_is_data_and_sql_sort_is_rejected(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload(['description' => "A road problem: '; DROP TABLE users; -- remains only plain text."]);
        $this->actingAs($user)->postJson(route('reports.store'), $payload)->assertStatus(202);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($payload['description'], Report::sole()->description);
        $this->getJson(route('reports.index').'?sort=created_at%3Bdrop%20table%20users')->assertUnprocessable();
    }

    public function test_html_and_executable_links_are_rejected_and_legacy_text_is_escaped(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('reports.store'), $this->payload(['description' => '<script>alert(1)</script> road issue']))->assertUnprocessable();
        $this->postJson(route('reports.store'), $this->payload(['description' => 'A road issue javascript:alert(1) attack']))->assertUnprocessable();
        $report = Report::factory()->create(['public_title' => '<img src=x onerror=alert(1)>', 'public_summary' => '<script>alert(1)</script>', 'published_at' => now()]);
        $this->get(route('reports.show', $report))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_user_cannot_supply_owner_status_authority_or_redirect(): void
    {
        $this->actingAs(User::factory()->create())->postJson(route('reports.store'),
            $this->payload(['user_id' => 999, 'status' => 'accepted', 'authority_id' => (string) Str::uuid(), 'redirect' => 'https://evil.example/']))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'status', 'authority_id', 'redirect']);
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_idempotent_replay_creates_one_report_and_changed_payload_conflicts(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        $first = $this->postJson(route('reports.store'), $payload)->assertStatus(202);
        $this->postJson(route('reports.store'), $payload)->assertStatus(202)->assertJsonPath('report_id', $first->json('report_id'));
        $this->postJson(route('reports.store'), array_replace($payload, ['title' => 'Changed submission title']))->assertConflict();
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_report_rate_limit_is_enforced(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('reports.store'), $this->payload())->assertStatus(202);
        }
        $this->postJson(route('reports.store'), $this->payload())->assertStatus(429);
        $this->assertDatabaseCount('reports', 3);
    }

    public function test_bad_oversized_and_non_image_attachments_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson(route('reports.store'), $this->payload(['attachments' => [UploadedFile::fake()->create('bad.php', 10, 'application/x-httpd-php')]]))->assertUnprocessable();
        $this->postJson(route('reports.store'), $this->payload(['attachments' => [UploadedFile::fake()->image('huge.jpg')->size(6000)]]))->assertUnprocessable();
        $this->assertDatabaseCount('report_attachments', 0);
    }

    public function test_image_is_reencoded_stored_privately_and_download_authorized(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('reports.store'), $this->payload(['attachments' => [UploadedFile::fake()->image('untrusted-name.png', 64, 64)]]))->assertStatus(202);
        $attachment = ReportAttachment::sole();
        $this->assertSame('image/jpeg', $attachment->mime);
        $this->assertStringNotContainsString('untrusted-name', $attachment->path);
        Storage::disk('private')->assertExists($attachment->path);
        $this->get(route('attachments.download', $attachment))->assertForbidden();
        $url = URL::temporarySignedRoute('attachments.download', now()->addMinutes(5), ['attachment' => $attachment->id]);
        $this->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_uploads_fail_closed_when_scanning_is_required(): void
    {
        config(['moshkelti.require_scanner' => true, 'moshkelti.scanner_binary' => null]);
        $this->actingAs(User::factory()->create())->postJson(route('reports.store'),
            $this->payload(['attachments' => [UploadedFile::fake()->image('photo.jpg', 64, 64)]]))->assertUnprocessable();
        $this->assertDatabaseCount('reports', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_restricted_user_can_appeal_but_cannot_appeal_another_users_report(): void
    {
        $user = User::factory()->create();
        UserRestriction::factory()->create(['user_id' => $user->id, 'type' => 'banned', 'ends_at' => null]);
        $this->actingAs($user)->postJson(route('account.appeal'), ['reason' => 'Please reconsider this restriction.'])->assertStatus(202);
        $this->postJson(route('reports.appeal', Report::factory()->create()), ['reason' => 'Please reconsider this issue.'])->assertNotFound();
        $this->assertDatabaseCount('appeals', 1);
    }

    public function test_security_headers_and_no_sensitive_audit_metadata(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
        $user = User::factory()->create();
        app(SecurityAudit::class)->record('test.sanitized', $user, null, ['password' => 'secret', 'description' => 'private', 'state' => 'active']);
        $this->assertSame(['state' => 'active'], AuditLog::sole()->metadata);
    }

    public function test_additional_information_is_versioned_and_cannot_change_approved_reports(): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->create(['user_id' => $user->id, 'status' => 'needs_more_information', 'version' => 3]);
        $this->actingAs($user)->postJson(route('reports.information', $report), ['version' => 2, 'description' => 'Additional road location information.'])->assertConflict();
        $this->postJson(route('reports.information', $report), ['version' => 3, 'description' => 'Additional road location information.'])->assertOk();
        $this->assertSame(4, $report->fresh()->version);
        $report->update(['status' => 'accepted']);
        $this->postJson(route('reports.information', $report), ['version' => 4, 'description' => 'Additional road location information.'])->assertNotFound();
    }

    public function test_csrf_protection_is_active_for_state_changing_requests(): void
    {
        $this->app->instance('env', 'local');
        $this->post(route('reports.store'), $this->payload())->assertStatus(419);
    }
}
