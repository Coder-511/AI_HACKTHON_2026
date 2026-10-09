<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AdminVerificationFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(bool $verified = true): User
    {
        $administrator = User::factory()->create(['is_admin' => true, 'password' => 'secure-test-password',
            'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now()]);
        $this->actingAs($administrator)->withSession(['admin_mfa_verified' => $verified ? $administrator->id : null]);

        return $administrator;
    }

    public function test_dashboard_shows_security_status_without_security_navigation_tabs(): void
    {
        $this->administrator();
        $response = $this->get(route('admin.index'))->assertOk()->assertSee('Two-factor: verified for this session')
            ->assertSee('Password: confirmation required before saving');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertCount(0, $xpath->query('//nav[@class="admin-toolbar"]/a[@href="'.route('password.confirm').'"]'));
        $this->assertCount(0, $xpath->query('//nav[@class="admin-toolbar"]/a[@href="'.route('mfa.challenge').'"]'));
    }

    public function test_password_confirmation_returns_to_review_without_replaying_the_decision(): void
    {
        $this->freezeTime();
        $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review']);
        $this->post(route('admin.reports.decide', $report), ['decision' => 'reject', 'reason' => 'This is a test reason.', 'version' => $report->version])
            ->assertRedirectToRoute('password.confirm');
        $this->assertSame('needs_review', $report->fresh()->status);
        $this->get(route('password.confirm'))->assertOk()->assertSee('no action was performed');
        $this->post(route('password.confirm.store'), ['password' => 'secure-test-password'])
            ->assertRedirectToRoute('admin.reports.show', $report)->assertSessionMissing('admin.verification_return')
            ->assertSessionHas('status', 'Password confirmed. No administrative action was performed. Review the current page and submit the action explicitly.');
        $this->assertSame('needs_review', $report->fresh()->status);
        $this->get(route('admin.reports.show', $report))->assertOk()->assertSee('Password: recently confirmed');
    }

    public function test_mfa_returns_to_the_original_private_review_page(): void
    {
        $this->travelTo(Carbon::createFromTimestampUTC(59));
        $administrator = $this->administrator(false);
        $report = Report::factory()->create();
        $this->get(route('admin.reports.show', $report))->assertRedirectToRoute('mfa.challenge');
        $this->post(route('mfa.verify'), ['code' => '287082'])->assertRedirectToRoute('admin.reports.show', $report)
            ->assertSessionHas('admin_mfa_verified', $administrator->id)->assertSessionMissing('admin.verification_return');
    }

    public function test_already_verified_mfa_link_returns_to_dashboard_not_home(): void
    {
        $this->administrator();
        $this->get(route('mfa.challenge'))->assertRedirectToRoute('admin.index');
    }

    public function test_return_target_is_user_bound_expiring_and_allowlisted(): void
    {
        $this->freezeTime();
        $administrator = $this->administrator();
        foreach ([
            ['user_id' => $administrator->id, 'route' => 'https://attacker.example/', 'created_at' => now()->getTimestamp()],
            ['user_id' => $administrator->id, 'route' => 'admin.users', 'created_at' => now()->subMinutes(11)->getTimestamp()],
            ['user_id' => $administrator->id + 1, 'route' => 'admin.users', 'created_at' => now()->getTimestamp()],
            ['user_id' => $administrator->id, 'route' => 'admin.users', 'created_at' => now()->addMinute()->getTimestamp()],
        ] as $target) {
            $this->withSession(['admin.verification_return' => $target, 'url.intended' => 'https://attacker.example/'])
                ->post(route('password.confirm.store'), ['password' => 'secure-test-password', 'return_url' => 'https://attacker.example/'])
                ->assertRedirectToRoute('admin.index')->assertSessionMissing('admin.verification_return')->assertSessionMissing('url.intended');
        }
    }

    public function test_future_password_timestamp_cannot_authorize_a_sensitive_action(): void
    {
        $this->freezeTime();
        $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review']);
        $this->withSession(['auth.password_confirmed_at' => now()->addHour()->getTimestamp()])
            ->postJson(route('admin.reports.decide', $report), [])->assertForbidden()
            ->assertJsonPath('code', 'password_confirmation_required');
        $this->assertSame('needs_review', $report->fresh()->status);
    }

    public function test_on_demand_signed_confirmation_link_returns_to_current_admin_page(): void
    {
        $this->administrator();
        $report = Report::factory()->create();
        $link = URL::temporarySignedRoute('password.confirm', now()->addMinutes(10), ['context' => 'admin.reports.show', 'report' => $report->id]);
        $this->get($link)->assertOk();
        $this->post(route('password.confirm.store'), ['password' => 'secure-test-password'])
            ->assertRedirectToRoute('admin.reports.show', $report);
    }

    public function test_unsigned_confirmation_context_cannot_select_a_return_page(): void
    {
        $this->administrator();
        $this->get(route('password.confirm', ['context' => 'admin.users']))->assertOk()->assertSessionMissing('admin.verification_return');
        $this->post(route('password.confirm.store'), ['password' => 'secure-test-password'])->assertRedirectToRoute('admin.index');
    }
}
