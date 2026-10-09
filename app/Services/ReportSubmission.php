<?php

namespace App\Services;

use App\Jobs\AnalyzeReport;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportSubmission
{
    public function __construct(private SubmissionAccess $access, private AttachmentStorage $storage, private ReportWorkflow $workflow) {}

    public function create(User $user, array $data, array $files = []): Report
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($user, $data, $files, &$paths): Report {
                $owner = User::query()->lockForUpdate()->findOrFail($user->id);
                $this->access->ensure($owner);
                $payload = array_intersect_key($data, array_flip(['title', 'description', 'category', 'governorate', 'latitude', 'longitude', 'external_ai_consent']));
                $payload['latitude'] = sprintf('%.6f', (float) $data['latitude']);
                $payload['longitude'] = sprintf('%.6f', (float) $data['longitude']);
                $payload['category'] = $data['category'] ?? null;
                $payload['external_ai_consent'] = (bool) ($data['external_ai_consent'] ?? false);
                ksort($payload);
                $payload['files'] = array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $files);
                $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $existing = $owner->reports()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Idempotency key was already used for another payload.');

                    return $existing;
                }
                $report = Report::create([
                    'user_id' => $owner->id, 'tracking_code' => 'MSK-'.strtoupper(Str::random(12)),
                    'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
                    'title' => $data['title'], 'description' => $data['description'],
                    'category' => $data['category'] ?? null, 'governorate' => $data['governorate'],
                    'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
                    'external_ai_consent' => (bool) ($data['external_ai_consent'] ?? false),
                    'consented_at' => now(), 'status' => 'received',
                    'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : (string) Str::uuid(),
                ]);
                foreach ($files as $file) {
                    $paths[] = $this->storage->store($report, $file)->path;
                }
                $report = $this->workflow->transition($report, 'processing', 'Your report was received and is being assessed.', $owner);
                AnalyzeReport::dispatch($report->id, $report->version)->afterCommit();

                return $report;
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }
    }
}
