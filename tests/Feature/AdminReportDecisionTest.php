<?php

namespace Tests\Feature;

use App\Jobs\DispatchReport;
use App\Models\AuthorityUnit;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportStatusChanged;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminReportDecisionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'mfa_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', 'mfa_confirmed_at' => now()]);
        $this->actingAs($admin)->withSession(['admin_mfa_verified' => $admin->id, 'auth.password_confirmed_at' => now()->getTimestamp()]);
    }

    public function test_browser_form_version_string_can_record_a_valid_review_decision(): void
    {
        Notification::fake();
        $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review', 'version' => 3]);
        $this->post(route('admin.reports.decide', $report), ['decision' => 'reject', 'reason' => 'The reviewed evidence does not support this report.', 'version' => '3'])
            ->assertRedirectToRoute('admin.reports.show', $report)->assertSessionHas('status', 'Review decision recorded.');
        $this->assertSame('rejected', $report->fresh()->status);
        $this->assertSame(4, $report->fresh()->version);
        $this->assertDatabaseCount('report_events', 1);
        Notification::assertSentTo($report->user, ReportStatusChanged::class);
    }

    public function test_stale_browser_form_returns_to_latest_report_without_saving_or_losing_reason(): void
    {
        $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review', 'version' => 4]);
        $this->post(route('admin.reports.decide', $report), ['decision' => 'reject', 'reason' => 'Reason from the stale review form.', 'version' => '3'])
            ->assertRedirectToRoute('admin.reports.show', $report);
        $response = $this->get(route('admin.reports.show', $report).'?lang=ar')->assertOk()
            ->assertSee('name="version" type="hidden" value="4"', false)->assertSee('Reason from the stale review form.');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $error = (new \DOMXPath($document))->query('//*[@data-error-summary]//li')->item(0);
        $this->assertSame('تغيّر البلاغ أثناء مراجعتك له. لم يتم حفظ أي تغيير. راجع أحدث التفاصيل قبل إرسال القرار مجدداً.', $error?->textContent);
        $this->assertSame('needs_review', $report->fresh()->status);
        $this->assertSame(4, $report->fresh()->version);
        $this->assertDatabaseCount('report_events', 0);
        $this->assertDatabaseCount('dispatch_outboxes', 0);
    }

    public function test_password_confirmation_returns_to_review_and_explicit_resubmission_saves_once(): void
    {
        Notification::fake();
        $this->administrator();
        $this->withSession(['auth.password_confirmed_at' => null]);
        $report = Report::factory()->create(['status' => 'needs_review', 'version' => 3]);
        $payload = ['decision' => 'reject', 'reason' => 'This report was reviewed and requires rejection.', 'version' => '3'];
        $this->post(route('admin.reports.decide', $report), $payload)->assertRedirectToRoute('password.confirm');
        $this->get(route('password.confirm'))->assertOk();
        $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirectToRoute('admin.reports.show', $report);
        $this->assertSame('needs_review', $report->fresh()->status);
        $this->assertDatabaseCount('report_events', 0);
        $this->get(route('admin.reports.show', $report).'?lang=ar')->assertOk()
            ->assertSee('تم تأكيد كلمة المرور. لم يتم تنفيذ أي إجراء إداري.');
        $this->post(route('admin.reports.decide', $report), $payload)->assertRedirectToRoute('admin.reports.show', $report);
        $this->assertSame('rejected', $report->fresh()->status);
        $this->assertDatabaseCount('report_events', 1);
        Notification::assertSentTo($report->user, ReportStatusChanged::class);
    }

    public function test_stale_json_request_keeps_conflict_status_without_mutating_report(): void
    {
        $this->administrator();
        $report = Report::factory()->create(['version' => 4]);
        $this->postJson(route('admin.reports.decide', $report).'?lang=en', ['decision' => 'reject', 'reason' => 'Reason from another stale form.', 'version' => '3'])
            ->assertConflict()->assertJsonPath('message', 'This report changed while you were reviewing it. Nothing was saved. Review the latest details before submitting again.');
        $this->assertSame(4, $report->fresh()->version);
        $this->assertDatabaseCount('report_events', 0);
    }

    public function test_invalid_status_transition_rolls_back_all_review_edits(): void
    {
        $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review', 'category' => 'roads', 'version' => 3, 'citizen_reason' => null]);
        $this->post(route('admin.reports.decide', $report), ['decision' => 'resolve', 'reason' => 'This invalid resolution must never be saved.', 'category' => 'lighting', 'version' => '3'])
            ->assertRedirectToRoute('admin.reports.show', $report)->assertSessionHasErrors('decision');
        $this->assertSame('roads', $report->fresh()->category);
        $this->assertNull($report->fresh()->citizen_reason);
        $this->assertSame('needs_review', $report->fresh()->status);
        $this->assertDatabaseCount('report_events', 0);
        $this->postJson(route('admin.reports.decide', $report), ['decision' => 'resolve', 'reason' => 'This invalid resolution must never be saved.', 'version' => '3'])->assertConflict();
    }

    public function test_acceptance_without_an_authority_returns_a_field_error_not_a_generic_error_page(): void
    {
        $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review', 'version' => 3]);
        $this->post(route('admin.reports.decide', $report), ['decision' => 'accept', 'reason' => 'Reviewed evidence but no authority selected.', 'version' => '3'])
            ->assertRedirectToRoute('admin.reports.show', $report)->assertSessionHasErrors('authority_id');
        $this->assertNull($report->fresh()->authority_id);
        $this->assertDatabaseCount('dispatch_outboxes', 0);
    }

    public function test_browser_acceptance_and_retry_do_not_dispatch_twice(): void
    {
        Queue::fake([DispatchReport::class]);
        Notification::fake();
        $this->administrator();
        $authority = AuthorityUnit::factory()->create();
        $report = Report::factory()->create(['status' => 'needs_review', 'version' => 3, 'category' => 'roads', 'governorate' => 'Amman']);
        $payload = ['decision' => 'accept', 'reason' => 'Location and jurisdiction verified for pilot routing.', 'version' => '3', 'authority_id' => $authority->id];
        $this->post(route('admin.reports.decide', $report), $payload)->assertRedirectToRoute('admin.reports.show', $report);
        $this->assertSame('routed', $report->fresh()->status);
        $this->assertSame(5, $report->fresh()->version);
        $this->post(route('admin.reports.decide', $report), $payload)->assertRedirectToRoute('admin.reports.show', $report)->assertSessionHasErrors('version');
        $this->assertDatabaseCount('dispatch_outboxes', 1);
        Queue::assertPushed(DispatchReport::class, 1);
        Notification::assertSentTo($report->user, ReportStatusChanged::class);
    }

    public static function statusActions(): array
    {
        return [
            'processing' => ['processing', ['needs_more_information']],
            'review' => ['needs_review', ['accept', 'reject', 'needs_more_information']],
            'information requested' => ['needs_more_information', ['accept', 'reject']],
            'routed' => ['routed', ['resolve']],
            'acknowledged' => ['acknowledged', ['resolve']],
            'in progress' => ['in_progress', ['resolve']],
            'rejected' => ['rejected', ['reopen']],
            'resolved' => ['resolved', ['reopen']],
            'closed' => ['closed', []],
        ];
    }

    #[DataProvider('statusActions')]
    public function test_review_menu_only_offers_valid_status_actions(string $status, array $expected): void
    {
        $this->administrator();
        $report = Report::factory()->create(['status' => $status]);
        $response = $this->get(route('admin.reports.show', $report))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $options = (new \DOMXPath($document))->query('//select[@id="decision"]/option[@value!=""]');
        $actual = [];
        foreach ($options as $option) {
            $actual[] = $option->getAttribute('value');
        }
        $this->assertSame($expected, $actual);
    }
}
