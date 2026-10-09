<?php

namespace Tests\Feature;

use App\Models\AuthorityUnit;
use App\Models\Report;
use App\Models\ReportAnalysis;
use App\Models\ReportAttachment;
use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRoleExperienceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(): User
    {
        $administrator = User::factory()->create(['is_admin' => true, 'mfa_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', 'mfa_confirmed_at' => now()]);
        $this->actingAs($administrator)->withSession(['admin_mfa_verified' => $administrator->id, 'auth.password_confirmed_at' => now()->getTimestamp()]);

        return $administrator;
    }

    public function test_normal_admin_has_review_navigation_without_false_restriction_notice(): void
    {
        $this->administrator();
        $response = $this->get(route('admin.index').'?lang=en')->assertOk()
            ->assertSee('Admin dashboard')->assertSee('Accounts and restrictions')->assertSee('Public view')
            ->assertDontSee('Your account is read-only')->assertDontSee('Request an account restriction review');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $this->assertCount(0, (new \DOMXPath($document))->query('//nav/a[@href="'.route('reports.mine').'"]'));
        $this->get(route('reports.mine'))->assertRedirectToRoute('admin.index');
        $this->getJson(route('reports.mine'))->assertForbidden();
        $this->getJson(route('reports.create'))->assertForbidden();
    }

    public function test_active_citizen_keeps_submission_and_personal_reports(): void
    {
        $this->actingAs(User::factory()->create())->get('/?lang=en')->assertOk()
            ->assertSee('My reports')->assertSee('Report an issue')->assertDontSee('Admin dashboard')
            ->assertDontSee('Your account is read-only');
    }

    public function test_public_view_hides_personal_report_links_for_admins_in_both_languages(): void
    {
        $this->administrator();
        foreach (['en', 'ar'] as $locale) {
            $response = $this->get(route('reports.index').'?lang='.$locale)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $this->assertCount(0, (new \DOMXPath($document))->query('//a[@href="'.route('reports.mine').'"]'));
        }
    }

    public function test_public_view_keeps_personal_report_tab_for_citizens(): void
    {
        $this->actingAs(User::factory()->create());
        $response = $this->get(route('reports.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $this->assertCount(1, (new \DOMXPath($document))->query('//nav[@class="tabs"]/a[@href="'.route('reports.mine').'"]'));
    }

    public static function restrictions(): array
    {
        return ['suspended' => ['temporary_suspend'], 'banned' => ['banned']];
    }

    #[DataProvider('restrictions')]
    public function test_restricted_admin_keeps_public_reads_but_cannot_review_or_download_evidence(string $type): void
    {
        $this->freezeTime();
        $administrator = $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review']);
        $attachment = ReportAttachment::factory()->for($report)->create();
        UserRestriction::factory()->for($administrator)->create(['type' => $type, 'ends_at' => $type === 'banned' ? null : now()->addHour()]);
        $this->get('/?lang=en')->assertOk()->assertSee('Administrative access is restricted')
            ->assertSee('Contact the system operator')->assertDontSee('Request an account restriction review');
        $this->get(route('admin.index'))->assertRedirectToRoute('home')->assertSessionMissing('admin_mfa_verified');
        $this->getJson(route('admin.reports.show', $report))->assertForbidden();
        $this->getJson(route('admin.users'))->assertForbidden();
        $this->postJson(route('admin.reports.decide', $report), [])->assertForbidden();
        $this->postJson(route('admin.users.restrict', $report->user), [])->assertForbidden();
        $this->get(URL::temporarySignedRoute('attachments.download', now()->addMinutes(5), ['attachment' => $attachment->id]))->assertNotFound();
        $this->get(route('reports.index'))->assertOk();
        $this->get(route('news.index'))->assertOk();
        $this->assertSame('needs_review', $report->fresh()->status);
    }

    #[DataProvider('restrictions')]
    public function test_restricted_citizen_still_sees_read_only_notice_and_can_read_own_reports(string $type): void
    {
        $user = User::factory()->create();
        UserRestriction::factory()->for($user)->create(['type' => $type, 'ends_at' => $type === 'banned' ? null : now()->addHour()]);
        $this->actingAs($user)->get('/?lang=en')->assertOk()->assertSee('Your account is read-only')
            ->assertSee('Request an account restriction review')->assertSee('My reports')->assertDontSee('Report an issue');
        $this->get(route('reports.mine'))->assertOk();
    }

    public function test_expired_admin_suspension_does_not_block_review(): void
    {
        $this->freezeTime();
        $administrator = $this->administrator();
        UserRestriction::factory()->for($administrator)->create(['starts_at' => now()->subHour(), 'ends_at' => now()->subMinute()]);
        $this->get(route('admin.index'))->assertOk()->assertDontSee('Administrative access is restricted');
    }

    public function test_review_page_distinguishes_queued_and_delayed_analysis(): void
    {
        $this->freezeTime();
        $this->administrator();
        $report = Report::factory()->create(['status' => 'processing', 'updated_at' => now()]);
        $this->get(route('admin.reports.show', $report))->assertOk()->assertSee('Analysis is queued or running.')
            ->assertDontSee('Processing is delayed.');
        $this->travel(3)->minutes();
        $this->get(route('admin.reports.show', $report))->assertOk()->assertSee('Processing is delayed.')
            ->assertSee('A delay does not mean the report was rejected.');
    }

    public function test_review_without_analysis_requires_manual_assessment_instead_of_claiming_ai_results(): void
    {
        $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review']);
        $this->get(route('admin.reports.show', $report))->assertOk()->assertSee('No automated result is stored.')
            ->assertDontSee('Analysis is queued or running.');
    }

    public function test_review_displays_missing_information_safely_instead_of_omitting_array_results(): void
    {
        $this->administrator();
        $report = Report::factory()->create(['status' => 'needs_review']);
        ReportAnalysis::factory()->for($report)->create(['result' => ['missing_information' => ['Please provide a nearby landmark.', '<script>alert(1)</script>']]]);
        $this->get(route('admin.reports.show', $report))->assertOk()->assertSee('Please provide a nearby landmark.')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_unassigned_authority_is_explained_separately_from_ai_results_in_both_languages(): void
    {
        $this->administrator();
        $report = Report::factory()->create(['authority_id' => null]);
        ReportAnalysis::factory()->for($report)->create(['driver' => 'gemini', 'result' => ['suggested_authority_id' => null, 'requires_human_review' => true]]);
        $this->get(route('admin.reports.show', $report).'?lang=en')->assertOk()
            ->assertSee('Pending admin selection')->assertSee('Authority selection is separate from automated triage.')
            ->assertDontSee('suggested authority id')->assertDontSee('suggested_authority_id');
        $this->get(route('admin.reports.show', $report).'?lang=ar')->assertOk()
            ->assertSee('بانتظار تحديد الجهة بواسطة مسؤول المراجعة')->assertDontSee('الجهة المقترحة');
        $this->assertNull($report->fresh()->authority_id);
    }

    public function test_review_questions_support_both_languages_and_translate_known_legacy_questions(): void
    {
        $this->administrator();
        $report = Report::factory()->create();
        ReportAnalysis::factory()->for($report)->create(['result' => ['missing_information' => [
            ['ar' => 'أين يقع الطريق؟', 'en' => 'Where is the road?'],
            'Can you specify the exact location and street name?',
            'What is the specific nature of the problem on the road?',
        ]]]);
        $this->get(route('admin.reports.show', $report).'?lang=ar')->assertOk()
            ->assertSee('<span lang="ar" dir="rtl">أين يقع الطريق؟</span>', false)
            ->assertSee('<span lang="ar">هل يمكنك تحديد الموقع الدقيق واسم الشارع؟</span>', false)
            ->assertSee('<span lang="ar">ما طبيعة المشكلة بالتحديد في الطريق؟</span>', false);
        $this->get(route('admin.reports.show', $report).'?lang=en')->assertOk()
            ->assertSee('<span lang="en" dir="ltr">Where is the road?</span>', false)
            ->assertSee('<span lang="en">Can you specify the exact location and street name?</span>', false);
    }

    public function test_assigned_authority_is_shown_instead_of_pending_and_its_name_is_escaped(): void
    {
        $this->administrator();
        $authority = AuthorityUnit::factory()->create(['name' => 'Roads <script>alert(1)</script>']);
        $report = Report::factory()->create(['authority_id' => $authority->id]);
        ReportAnalysis::factory()->for($report)->create(['result' => ['suggested_authority_id' => null]]);
        $this->get(route('admin.reports.show', $report))->assertOk()
            ->assertSee('Assigned in the report record, not by AI.')->assertDontSee('Pending admin selection')
            ->assertSee('Roads &lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame($authority->id, $report->fresh()->authority_id);
    }
}
