<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\AttachmentStorage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttachmentPreviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function attachment(): ReportAttachment
    {
        Storage::fake('private');
        config(['moshkelti.require_scanner' => false, 'moshkelti.scanner_binary' => null]);
        $attachment = app(AttachmentStorage::class)->store(Report::factory()->create(), UploadedFile::fake()->image('evidence.png', 64, 64));
        Storage::disk('private')->assertExists($attachment->path);

        return $attachment;
    }

    private function administrator(): User
    {
        $admin = User::factory()->create(['is_admin' => true, 'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now()]);
        $this->actingAs($admin)->withSession(['admin_mfa_verified' => $admin->id]);

        return $admin;
    }

    private function previewUrl(ReportAttachment $attachment): string
    {
        return URL::temporarySignedRoute('attachments.preview', now()->addMinutes(5), ['attachment' => $attachment->id]);
    }

    public function test_verified_admin_can_preview_sanitized_image_inline_in_review_page(): void
    {
        $this->freezeTime();
        $attachment = $this->attachment();
        $this->administrator();
        $url = $this->previewUrl($attachment);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('private')->get($attachment->path), $response->streamedContent());
        $this->get(route('admin.reports.show', $attachment->report))->assertOk()
            ->assertSee('class="attachment-preview"', false)->assertSee('src="'.e($url).'"', false)
            ->assertSee('View image 1')->assertSee('عرض الصورة 1');
    }

    public function test_preview_requires_login_and_admin_mfa_even_with_a_valid_link(): void
    {
        $attachment = $this->attachment();
        $url = $this->previewUrl($attachment);
        $this->get($url)->assertRedirect(route('login'));
        $this->administrator();
        $this->withSession(['admin_mfa_verified' => null])->get($url)->assertForbidden();
    }

    public function test_another_citizen_cannot_preview_private_evidence(): void
    {
        $attachment = $this->attachment();
        $this->actingAs(User::factory()->create())->get($this->previewUrl($attachment))->assertNotFound();
    }

    public function test_restricted_admin_cannot_preview_evidence(): void
    {
        $attachment = $this->attachment();
        $admin = $this->administrator();
        UserRestriction::factory()->create(['user_id' => $admin->id, 'type' => 'banned', 'ends_at' => null]);
        $this->get($this->previewUrl($attachment))->assertNotFound();
    }

    public function test_unsigned_tampered_and_expired_preview_links_are_rejected(): void
    {
        $this->freezeTime();
        $attachment = $this->attachment();
        $this->administrator();
        $url = $this->previewUrl($attachment);
        $this->get(route('attachments.preview', $attachment))->assertForbidden();
        $this->get($url.'&extra=changed')->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public static function unavailableAttachments(): array
    {
        return [
            'unsafe scan' => [['scan_status' => 'infected']],
            'expired retention' => [['expires_at' => '2000-01-01 00:00:00']],
            'active content type' => [['mime' => 'image/svg+xml']],
        ];
    }

    #[DataProvider('unavailableAttachments')]
    public function test_unavailable_attachments_cannot_be_previewed(array $attributes): void
    {
        $attachment = $this->attachment();
        $attachment->update($attributes);
        $this->administrator();
        $this->get($this->previewUrl($attachment))->assertNotFound();
        $this->get(route('admin.reports.show', $attachment->report))->assertOk()->assertSee('No attachments available for preview.');
    }

    public function test_stored_html_cannot_be_previewed_even_with_jpeg_metadata(): void
    {
        $attachment = $this->attachment();
        Storage::disk('private')->put($attachment->path, '<html><script>alert(1)</script></html>');
        $this->administrator();
        $this->get($this->previewUrl($attachment))->assertNotFound();
    }

    public function test_missing_file_returns_not_found_and_explicit_download_still_works(): void
    {
        $attachment = $this->attachment();
        $this->administrator();
        $download = URL::temporarySignedRoute('attachments.download', now()->addMinutes(5), ['attachment' => $attachment->id]);
        $response = $this->get($download)->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        Storage::disk('private')->delete($attachment->path);
        $this->get($this->previewUrl($attachment))->assertNotFound();
    }
}
