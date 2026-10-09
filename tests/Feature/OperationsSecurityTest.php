<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\News;
use App\Models\PrivacyRequest;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\Ai\SafeEndpoint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_privacy_withdrawal_is_available_to_banned_users_and_revokes_report_consent(): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->create(['user_id' => $user->id, 'external_ai_consent' => true]);
        UserRestriction::factory()->create(['user_id' => $user->id, 'type' => 'banned', 'ends_at' => null]);
        $this->actingAs($user)->postJson(route('account.privacy'), ['type' => 'withdraw', 'details' => 'I withdraw external processing consent.'])->assertStatus(202);
        $this->assertFalse($report->fresh()->external_ai_consent);
        $this->assertDatabaseHas('privacy_requests', ['user_id' => $user->id, 'type' => 'withdraw', 'status' => 'pending']);
        $this->get(route('privacy'))->assertOk()->assertSee('account/privacy', false);
    }

    public function test_non_admin_and_unverified_mfa_sessions_do_not_access_private_admin_queues(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true, 'mfa_secret' => 'TEST', 'mfa_confirmed_at' => now()]);
        $this->actingAs($admin)->get(route('admin.index'))->assertRedirectToRoute('mfa.challenge');
        $this->withSession(['admin_mfa_verified' => $admin->id])->get(route('admin.index'))->assertOk();
        $this->get(route('admin.users'))->assertOk();
        $this->get(route('admin.reports.show', Report::factory()->create()))->assertOk();
    }

    public function test_privacy_and_appeal_administration_acknowledges_without_silent_reversal(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'mfa_secret' => 'TEST', 'mfa_confirmed_at' => now()]);
        $citizen = User::factory()->create();
        $restriction = UserRestriction::factory()->create(['user_id' => $citizen->id]);
        $appeal = Appeal::factory()->create(['user_id' => $citizen->id]);
        $privacy = PrivacyRequest::factory()->create(['user_id' => $citizen->id]);
        $this->actingAs($admin)->withSession(['admin_mfa_verified' => $admin->id, 'auth.password_confirmed_at' => now()->getTimestamp()]);
        $this->post(route('admin.appeals.decide', $appeal), ['decision' => 'upheld'])->assertRedirect();
        $this->post(route('admin.privacy.decide', $privacy), ['decision' => 'acknowledged'])->assertRedirect();
        $this->assertNull($restriction->fresh()->revoked_at);
        $this->assertSame('acknowledged', $privacy->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.privacy_acknowledged']);
    }

    public function test_unscanned_local_image_is_not_downloadable_in_production(): void
    {
        $attachment = ReportAttachment::factory()->create(['scan_status' => 'local_sanitized']);
        $this->assertTrue($attachment->isSafeForDownload());
        $this->app->instance('env', 'production');
        $this->assertFalse($attachment->isSafeForDownload());
    }

    public function test_retention_previews_then_removes_only_expired_private_content(): void
    {
        Storage::fake('private');
        $closed = Report::factory()->create(['status' => 'resolved', 'closed_at' => now()->subDays(181)]);
        $active = Report::factory()->create(['status' => 'needs_review']);
        $attachment = ReportAttachment::factory()->create(['report_id' => $closed->id, 'expires_at' => now()->subDay()]);
        Storage::disk('private')->put($attachment->path, 'expired evidence');
        $this->artisan('moshkelti:retention')->assertSuccessful();
        $this->assertNull($closed->fresh()->anonymized_at);
        Storage::disk('private')->assertExists($attachment->path);
        $this->artisan('moshkelti:retention', ['--apply' => true])->assertSuccessful();
        $this->assertNotNull($closed->fresh()->anonymized_at);
        $this->assertNull($active->fresh()->anonymized_at);
        Storage::disk('private')->assertMissing($attachment->path);
    }

    public function test_shared_address_ranges_cannot_be_used_for_outbound_calls(): void
    {
        foreach (['100.64.0.1', '198.18.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1'] as $ip) {
            $this->assertFalse(app(SafeEndpoint::class)->isPublicIp($ip));
        }
        $this->assertTrue(app(SafeEndpoint::class)->isPublicIp('8.8.8.8'));
    }

    public function test_future_or_unpublished_news_does_not_leak(): void
    {
        News::factory()->create(['title' => 'UNPUBLISHED-NEWS', 'published_at' => null]);
        News::factory()->create(['title' => 'FUTURE-NEWS', 'published_at' => now()->addDay()]);
        $this->get(route('news.index'))->assertOk()->assertDontSee('UNPUBLISHED-NEWS')->assertDontSee('FUTURE-NEWS');
    }

    public function test_future_publication_is_not_readable_before_release(): void
    {
        $report = Report::factory()->create(['public_title' => 'FUTURE-PUBLIC-REPORT', 'published_at' => now()->addDay()]);
        $this->getJson(route('reports.show', $report))->assertNotFound();
        $this->getJson(route('reports.index'))->assertDontSee('FUTURE-PUBLIC-REPORT');
    }
}
