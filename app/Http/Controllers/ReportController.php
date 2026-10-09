<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdditionalInformationRequest;
use App\Http\Requests\AppealRequest;
use App\Http\Requests\AttachmentRequest;
use App\Http\Requests\ListReportsRequest;
use App\Http\Requests\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Jobs\AnalyzeReport;
use App\Models\Appeal;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\User;
use App\Services\AttachmentStorage;
use App\Services\ReportSubmission;
use App\Services\ReportWorkflow;
use App\Services\SecurityAudit;
use App\Services\SubmissionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(ListReportsRequest $request): View|AnonymousResourceCollection
    {
        return $this->listing($request, false);
    }

    public function mine(ListReportsRequest $request): View|AnonymousResourceCollection|RedirectResponse
    {
        if ($request->user()->is_admin) {
            abort_if($request->expectsJson(), 403);

            return redirect()->route($request->user()->canReviewReports() ? 'admin.index' : 'home');
        }

        return $this->listing($request, true);
    }

    private function listing(ListReportsRequest $request, bool $mine): View|AnonymousResourceCollection
    {
        $data = $request->validated();
        $query = Report::query();
        if ($mine) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->whereNotNull('published_at')->where('published_at', '<=', now());
        }
        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        $reports = $query->orderByDesc($data['sort'] ?? 'created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 12)->withQueryString();
        if ($request->expectsJson()) {
            return ReportResource::collection($reports);
        }
        $reports->through(fn (Report $report) => $this->project($report, $mine && $request->user()->submissionAccess()['account_state'] !== 'banned'));

        return view('reports.index', ['reports' => $reports, 'isMine' => $mine]);
    }

    public function create(Request $request): View
    {
        app(SubmissionAccess::class)->ensure($request->user());

        return view('reports.create', ['categories' => config('moshkelti.categories'), 'governorates' => config('moshkelti.governorates'), 'idempotencyKey' => (string) Str::uuid()]);
    }

    public function store(StoreReportRequest $request, ReportSubmission $submission): JsonResponse|RedirectResponse
    {
        $report = $submission->create($request->user(), $request->validated(), $request->file('attachments', []));
        if ($request->expectsJson()) {
            return response()->json(['report_id' => $report->id, 'tracking_code' => $report->tracking_code, 'status' => $report->status], 202);
        }

        return redirect()->route('reports.show', $report)->with('status', 'Report received. Track its review here.');
    }

    public function show(Request $request, Report $report): View|ReportResource
    {
        Gate::authorize('view', $report);
        // Administrative private access must use the MFA-protected administration route.
        $private = $request->user()?->id === $report->user_id && $request->user()->submissionAccess()['account_state'] !== 'banned';
        if (! $private && ! $report->published_at && $request->user()?->id !== $report->user_id) {
            abort(404);
        }
        if ($request->expectsJson()) {
            return new ReportResource($report);
        }
        $urls = [];
        if ($private) {
            $report->load(['events', 'attachments']);
            foreach ($report->attachments as $attachment) {
                if ($attachment->isSafeForDownload()) {
                    $urls[$attachment->id] = URL::temporarySignedRoute('attachments.download', now()->addMinutes(5), ['attachment' => $attachment->id]);
                }
            }
        }

        return view('reports.show', ['report' => $private ? $report : $this->project($report, false, $request->user()?->id === $report->user_id),
            'private' => $private, 'attachmentUrls' => $urls]);
    }

    private function project(Report $report, bool $private, bool $owner = false): Report
    {
        if ($private) {
            return $report;
        }
        $copy = new Report;
        $copy->setRawAttributes(array_intersect_key($report->getAttributes(), array_flip([
            'id', 'tracking_code', 'status', 'category', 'severity', 'public_title', 'public_summary', 'published_at', 'created_at', 'version',
        ])));
        if ($owner) {
            $copy->guidance = $report->guidance;
        }

        return $copy;
    }

    public function information(AdditionalInformationRequest $request, Report $report): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $report);
        $data = $request->validated();
        DB::transaction(function () use ($request, $report, $data): void {
            $owner = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            app(SubmissionAccess::class)->ensure($owner);
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless($locked->user_id === $owner->id, 404);
            Gate::authorize('update', $locked);
            abort_unless($locked->status === 'needs_more_information' && $locked->version === (int) $data['version'], 409, 'Report changed; reload before editing.');
            $locked->description = $data['description'];
            $locked->save();
            $locked = app(ReportWorkflow::class)->transition($locked, 'processing', 'Additional information received.', $owner);
            AnalyzeReport::dispatch($locked->id, $locked->version)->afterCommit();
        });

        return $request->expectsJson() ? response()->json(['status' => 'processing']) : redirect()->route('reports.show', $report);
    }

    public function attachment(AttachmentRequest $request, Report $report, AttachmentStorage $storage): JsonResponse
    {
        Gate::authorize('update', $report);
        $request->validated();
        DB::transaction(function () use ($request, $report, $storage): void {
            $owner = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            app(SubmissionAccess::class)->ensure($owner);
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless($locked->user_id === $owner->id && $locked->status === 'needs_more_information', 409);
            Gate::authorize('update', $locked);
            abort_unless($locked->attachments()->count() < config('moshkelti.attachment_limit'), 422, 'Attachment limit reached.');
            $storage->store($locked, $request->file('attachment'));
            $locked->version++;
            $locked->save();
            app(SecurityAudit::class)->record('report.evidence_added', $owner, $locked);
        });

        return response()->json(['status' => 'attachment_received'], 201);
    }

    public function download(Request $request, ReportAttachment $attachment): StreamedResponse
    {
        $this->authorizeAttachment($request, $attachment);

        return Storage::disk('private')->download($attachment->path, 'report-evidence.jpg', ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store']);
    }

    public function preview(Request $request, ReportAttachment $attachment): StreamedResponse
    {
        $this->authorizeAttachment($request, $attachment);
        abort_unless($attachment->mime === 'image/jpeg'
            && Storage::disk('private')->mimeType($attachment->path) === 'image/jpeg', 404);

        return Storage::disk('private')->response($attachment->path, 'report-evidence.jpg', [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store, private',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], 'inline');
    }

    private function authorizeAttachment(Request $request, ReportAttachment $attachment): void
    {
        $report = $attachment->report;
        Gate::authorize('download', $report);
        // Admin access additionally requires the MFA session, never just is_admin.
        if ($request->user()->is_admin) {
            abort_unless($request->user()->mfa_confirmed_at !== null && $request->user()->mfa_secret
                && $request->session()->get('admin_mfa_verified') === $request->user()->id, 403);
        }
        abort_unless($attachment->isSafeForDownload(), 404);
        abort_unless(Storage::disk('private')->exists($attachment->path), 404);
    }

    public function appeal(AppealRequest $request, Report $report): RedirectResponse|JsonResponse
    {
        Gate::authorize('appeal', $report);
        $data = $request->validated();
        Appeal::create(['user_id' => $request->user()->id, 'report_id' => $report->id, 'reason' => $data['reason']]);
        app(SecurityAudit::class)->record('appeal.report_created', $request->user(), $report);

        return $request->expectsJson() ? response()->json(['status' => 'appeal_received'], 202) : back()->with('status', 'Appeal received for human review.');
    }
}
