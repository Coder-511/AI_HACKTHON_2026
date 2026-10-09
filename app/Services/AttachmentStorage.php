<?php

namespace App\Services;

use App\Models\Report;
use App\Models\ReportAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class AttachmentStorage
{
    public function store(Report $report, UploadedFile $file): ReportAttachment
    {
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] > 4096 || $dimensions[1] > 4096 || $dimensions[0] * $dimensions[1] > 8000000
            || $file->getSize() > config('moshkelti.attachment_max_kb') * 1024
            || ! in_array($dimensions['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['attachments' => 'Invalid or oversized image.']);
        }
        $scanStatus = $this->scan($file->getRealPath());
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['attachments' => 'The image could not be safely decoded.']);
        }
        ob_start();
        imagejpeg($image, null, 85);
        $sanitized = ob_get_clean();
        imagedestroy($image);
        if (! is_string($sanitized) || strlen($sanitized) > 5120 * 1024) {
            throw ValidationException::withMessages(['attachments' => 'The processed image is too large.']);
        }
        $path = 'quarantine/'.Str::uuid().'.jpg';
        Storage::disk('private')->put($path, $sanitized);
        try {
            return ReportAttachment::create([
                'report_id' => $report->id, 'path' => $path, 'mime' => 'image/jpeg',
                'sha256' => hash('sha256', $sanitized), 'size' => strlen($sanitized),
                'scan_status' => $scanStatus, 'expires_at' => now()->addDays(config('moshkelti.retention_days')),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
    }

    private function scan(string $path): string
    {
        $binary = config('moshkelti.scanner_binary');
        if (! $binary) {
            if (app()->isProduction() || config('moshkelti.require_scanner')) {
                throw ValidationException::withMessages(['attachments' => 'Uploads are unavailable until malware scanning is configured.']);
            }

            return 'local_sanitized';
        }
        if (! is_file($binary)) {
            throw ValidationException::withMessages(['attachments' => 'The scanner is unavailable.']);
        }
        $process = new Process([$binary, '--no-summary', $path]);
        $process->setTimeout(15);
        try {
            $process->run();
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Scan failed.');
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['attachments' => 'The attachment did not pass security scanning.']);
        }

        return 'clean';
    }
}
