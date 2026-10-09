<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_my_report_card_fallbacks_and_accessible_links_use_the_selected_language(): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->for($user)->create(['public_title' => null, 'public_summary' => null]);
        $this->actingAs($user);
        $this->get(route('reports.mine').'?lang=ar')->assertOk()
            ->assertSee('بلاغ قيد المتابعة')->assertSee('افتح البلاغ لمتابعة حالته.')
            ->assertDontSee('Report in progress')->assertDontSee('Open the report to follow its status.')
            ->assertSee('aria-label="عرض البلاغ '.$report->tracking_code.'"', false);
        $this->get(route('reports.mine').'?lang=en')->assertOk()
            ->assertSee('Report in progress')->assertSee('Open the report to follow its status.')
            ->assertDontSee('بلاغ قيد المتابعة')->assertDontSee('افتح البلاغ لمتابعة حالته.')
            ->assertSee('aria-label="View report '.$report->tracking_code.'"', false);
    }

    public function test_report_cards_preserve_reviewed_content_when_switching_language(): void
    {
        $this->freezeTime();
        $report = Report::factory()->create(['public_title' => 'Reviewed original title', 'public_summary' => 'Reviewed original summary.', 'published_at' => now()]);
        $this->actingAs($report->user);
        foreach (['ar', 'en'] as $locale) {
            foreach (['reports.mine', 'reports.index'] as $route) {
                $this->get(route($route).'?lang='.$locale)->assertOk()
                    ->assertSee('Reviewed original title')->assertSee('Reviewed original summary.')
                    ->assertDontSee('Report in progress')->assertDontSee('بلاغ قيد المتابعة');
            }
        }
    }

    public function test_admin_setup_explains_where_to_get_codes_and_has_a_styled_logout_button(): void
    {
        $administrator = User::factory()->create(['is_admin' => true]);
        $this->actingAs($administrator)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        foreach (['en', 'ar'] as $locale) {
            $response = $this->get(route('mfa.setup').'?lang='.$locale)->assertOk()
                ->assertSee('Enter a setup key manually.')->assertSee('إدخال مفتاح الإعداد يدوياً.')
                ->assertSee('It is not sent by SMS or email')->assertSee('ولا يُرسل برسالة SMS');
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $buttons = (new \DOMXPath($document))->query('//main//form[@action="'.route('logout').'"]/button');
            $this->assertCount(1, $buttons);
            $this->assertSame('button button-secondary button-small', $buttons->item(0)->getAttribute('class'));
        }
        $this->assertNull($administrator->fresh()->mfa_confirmed_at);
    }

    public function test_join_us_keeps_its_button_design_without_active_navigation_state(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $response = $this->get('/register?lang='.$locale)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $navigation = new \DOMXPath($document);
            $joinLinks = $navigation->query('//nav[@id="primary-nav"]/a[@href="'.route('register').'"]');
            $this->assertCount(1, $joinLinks);
            $this->assertSame('button button-small', $joinLinks->item(0)->getAttribute('class'));
            $this->assertFalse($joinLinks->item(0)->hasAttribute('aria-current'));
            $this->assertCount(0, $navigation->query('//nav[@id="primary-nav"]/a[@aria-current="page"]'));
        }
    }

    public function test_authentication_links_render_only_the_selected_language(): void
    {
        $this->get('/login?lang=en')->assertOk()
            ->assertSee('>Administrator sign in</a>', false)->assertDontSee('دخول الإدارة');
        $this->get('/login?lang=ar')->assertOk()
            ->assertSee('>دخول الإدارة</a>', false)->assertDontSee('Administrator sign in');
        $this->get('/register?lang=en')->assertOk()
            ->assertSee('>Privacy</a>', false)->assertSee('>Sign in</a>', false)
            ->assertDontSee('الخصوصية / Privacy')->assertDontSee('تسجيل الدخول / Sign in');
        $this->get('/register?lang=ar')->assertOk()
            ->assertSee('>الخصوصية</a>', false)->assertSee('>تسجيل الدخول</a>', false)
            ->assertDontSee('>Privacy</a>', false)->assertDontSee('>Sign in</a>', false);
    }

    public function test_login_navigation_is_active_on_both_login_pages_but_not_home(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['/login', '/admin/login'] as $path) {
                $response = $this->get($path.'?lang='.$locale)->assertOk();
                $document = new \DOMDocument;
                @$document->loadHTML($response->getContent());
                $activeLinks = (new \DOMXPath($document))->query('//nav[@id="primary-nav"]/a[@aria-current="page"]');
                $this->assertCount(1, $activeLinks);
                $this->assertSame(route('login'), $activeLinks->item(0)->getAttribute('href'));
            }
        }
        $response = $this->get('/?lang=en')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $activeLinks = (new \DOMXPath($document))->query('//nav[@id="primary-nav"]/a[@aria-current="page"]');
        $this->assertCount(1, $activeLinks);
        $this->assertSame(route('home'), $activeLinks->item(0)->getAttribute('href'));
    }

    public function test_privacy_form_options_and_guest_link_follow_selected_language(): void
    {
        $this->get('/privacy?lang=en')->assertOk()
            ->assertSee('>Sign in to request a data review</a>', false)
            ->assertDontSee('سجّل الدخول لطلب مراجعة بياناتك');
        $this->get('/privacy?lang=ar')->assertOk()
            ->assertSee('>سجّل الدخول لطلب مراجعة بياناتك</a>', false)
            ->assertDontSee('Sign in to request a data review');
        $this->actingAs(User::factory()->create())->get('/privacy?lang=en')->assertOk()
            ->assertSee('>Request type</label>', false)->assertSee('>Access</option>', false)
            ->assertSee('>Withdraw external AI consent</option>', false)->assertDontSee('نوع الطلب');
        $this->get('/privacy?lang=ar')->assertOk()
            ->assertSee('>نوع الطلب</label>', false)->assertSee('>الوصول</option>', false)
            ->assertDontSee('Withdraw external AI consent');
    }

    public function test_english_titles_and_direction_persist_between_pages(): void
    {
        $this->get('/?lang=en')->assertOk()->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('<title>Your voice, a better place — Moshkelti</title>', false);
        $this->get('/login')->assertOk()->assertSee('<title>Sign in — Moshkelti</title>', false);
        $this->get('/reports')->assertOk()->assertSee('<title>Reports — Moshkelti</title>', false);
    }

    public function test_arabic_titles_and_flash_messages_are_localized(): void
    {
        $this->withSession(['status' => 'Report received. Track its review here.'])->get('/?lang=ar')
            ->assertOk()->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('<title>صوتك يصنع الفرق — مشكلتي</title>', false)
            ->assertSee('تم استلام بلاغك. يمكنك متابعة مراجعته هنا.')
            ->assertDontSee('Report received. Track its review here.');
    }

    public function test_invalid_locale_cannot_replace_existing_selection(): void
    {
        $this->withSession(['locale' => 'en'])->get('/?lang[]=ar')->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)->assertSessionHas('locale', 'en');
        $this->get('/?lang=unknown')->assertOk()->assertSessionHas('locale', 'en');
    }

    public function test_arabic_validation_and_login_errors_are_displayed(): void
    {
        $this->get('/login?lang=ar');
        $this->followingRedirects()->post('/login', [])->assertSee('حقل الرقم الوطني مطلوب.');
        $this->withSession(['errors' => new ViewErrorBag])->get('/login');
        $this->withSession(['status' => 'The provided credentials are incorrect.'])->get('/login')
            ->assertSee('بيانات تسجيل الدخول غير صحيحة.');
    }

    public function test_old_report_events_translate_without_modifying_citizen_content(): void
    {
        $report = Report::factory()->create(['title' => 'Citizen original title']);
        ReportEvent::create(['report_id' => $report->id, 'from_status' => 'received', 'to_status' => 'processing',
            'message' => 'Your report was received and is being assessed.', 'correlation_id' => $report->correlation_id, 'created_at' => now()]);
        $this->actingAs($report->user)->get(route('reports.show', $report).'?lang=ar')
            ->assertOk()->assertSee('تم استلام بلاغك وهو قيد التقييم.')
            ->assertSee('Citizen original title')->assertDontSee('Your report was received and is being assessed.');
        $this->get(route('reports.show', $report).'?lang=en')->assertOk()
            ->assertSee('Your report was received and is being assessed.');
        $this->assertDatabaseHas('report_events', ['message' => 'Your report was received and is being assessed.']);
    }
}
