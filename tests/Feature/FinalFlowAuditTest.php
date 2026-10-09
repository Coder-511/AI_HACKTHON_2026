<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeReport;
use App\Jobs\DispatchReport;
use App\Models\AuthorityUnit;
use App\Models\DispatchOutbox;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportStatusChanged;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinalFlowAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_metrics_exclude_future_publications(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        Report::factory()->for($owner)->create(['published_at' => now(), 'status' => 'resolved']);
        Report::factory()->for($owner)->create(['published_at' => now()->addDay(), 'status' => 'resolved']);
        Report::factory()->for($owner)->create(['published_at' => null]);
        $this->get(route('home'))->assertOk()->assertViewHas('metrics', ['public' => 1, 'resolved' => 1]);
    }

    public function test_admin_home_does_not_offer_a_citizen_reports_link(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['ar', 'en'] as $locale) {
            $response = $this->get(route('home').'?lang='.$locale)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $this->assertCount(0, (new \DOMXPath($document))->query('//a[@href="'.route('reports.mine').'"]'));
        }
    }

    public function test_new_evidence_invalidates_an_older_review_version(): void
    {
        Storage::fake('private');
        Queue::fake();
        Notification::fake();
        $owner = User::factory()->create();
        $report = Report::factory()->for($owner)->create(['status' => 'needs_more_information', 'version' => 3]);
        $this->actingAs($owner)->postJson(route('reports.attachments', $report), [
            'attachment' => UploadedFile::fake()->image('evidence.jpg', 64, 64),
        ])->assertCreated();
        $this->assertSame(4, $report->fresh()->version);
        $this->assertDatabaseCount('report_attachments', 1);
        $admin = User::factory()->create(['is_admin' => true, 'mfa_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', 'mfa_confirmed_at' => now()]);
        $this->actingAs($admin)->withSession(['admin_mfa_verified' => $admin->id, 'auth.password_confirmed_at' => now()->getTimestamp()])
            ->postJson(route('admin.reports.decide', $report), ['version' => 3, 'decision' => 'reject', 'reason' => 'Previously reviewed evidence was insufficient.'])->assertConflict();
        $this->assertSame('needs_more_information', $report->fresh()->status);
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public function test_opening_the_report_form_does_not_consume_submission_quota(): void
    {
        Queue::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create());
        for ($visit = 0; $visit < 4; $visit++) {
            $this->get(route('reports.create'))->assertOk();
        }
        $this->postJson(route('reports.store'), [
            'title' => 'A dangerous public road pothole', 'description' => 'A large pothole is damaging vehicles on this public road.',
            'governorate' => 'Amman', 'category' => 'roads', 'latitude' => 31.95, 'longitude' => 35.91,
            'consent' => '1', 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);
        $this->assertDatabaseCount('reports', 1);
        Queue::assertPushed(AnalyzeReport::class);
    }

    public function test_exhausted_dispatch_records_failure_once_without_exception_content(): void
    {
        Notification::fake();
        $authority = AuthorityUnit::factory()->create();
        $report = Report::factory()->create(['status' => 'routed', 'authority_id' => $authority->id]);
        $outbox = DispatchOutbox::factory()->create(['report_id' => $report->id, 'authority_id' => $authority->id]);
        $job = new DispatchReport($outbox->id);
        $job->failed(new \RuntimeException('Private provider credentials'));
        $job->failed(null);
        $this->assertSame('dispatch_failed', $report->fresh()->status);
        $this->assertSame('failed', $outbox->fresh()->status);
        $this->assertDatabaseCount('report_events', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'dispatch.job_failed']);
        $this->assertStringNotContainsString('Private provider credentials', $report->events()->sole()->message);
        Notification::assertSentTo($report->user, ReportStatusChanged::class);
    }

    public function test_late_dispatch_failure_does_not_overwrite_success(): void
    {
        Notification::fake();
        $authority = AuthorityUnit::factory()->create();
        $report = Report::factory()->create(['status' => 'resolved', 'authority_id' => $authority->id]);
        $outbox = DispatchOutbox::factory()->create(['report_id' => $report->id, 'authority_id' => $authority->id, 'status' => 'sent']);
        (new DispatchReport($outbox->id))->failed(null);
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertDatabaseCount('report_events', 0);
        Notification::assertNothingSent();
    }
}
