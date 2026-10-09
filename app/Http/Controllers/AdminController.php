<?php

namespace App\Http\Controllers;

use App\Http\Requests\RestrictUserRequest;
use App\Http\Requests\ReviewReportRequest;
use App\Models\Appeal;
use App\Models\AuthorityUnit;
use App\Models\PrivacyRequest;
use App\Models\Report;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\AuthorityDispatcher;
use App\Services\PublicReportPublisher;
use App\Services\ReportWorkflow;
use App\Services\SecurityAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        app(SecurityAudit::class)->record('admin.queue_accessed', auth()->user());

        return view('admin.index', ['reports' => Report::with('user')->latest()->orderByDesc('id')->paginate(20),
            'appeals' => Appeal::with('user')->where('status', 'pending')->latest()->limit(30)->get(),
            'privacyRequests' => PrivacyRequest::with('user')->where('status', 'pending')->latest()->limit(30)->get()]);
    }

    public function show(Report $report): View
    {
        $report->load(['user', 'attachments', 'analyses', 'events', 'authority']);
        $urls = [];
        foreach ($report->attachments as $attachment) {
            if ($attachment->isSafeForDownload() && $attachment->mime === 'image/jpeg') {
                $urls[$attachment->id] = URL::temporarySignedRoute('attachments.preview', now()->addMinutes(5), ['attachment' => $attachment->id]);
            }
        }
        app(SecurityAudit::class)->record('admin.report_accessed', auth()->user(), $report);

        return view('admin.report', ['report' => $report, 'authorities' => AuthorityUnit::where('active', true)->where('simulated', true)->get(), 'attachmentUrls' => $urls,
            'allowedReviewDecisions' => app(ReportWorkflow::class)->allowedReviewDecisions($report)]);
    }

    public function decide(ReviewReportRequest $request, Report $report): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $report, $data): void {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            if ($locked->version !== (int) $data['version']) {
                $this->decisionConflict($request, $locked, 'version', 'This report changed while you were reviewing it. Nothing was saved. Review the latest details before submitting again.');
            }
            if (! in_array($data['decision'], app(ReportWorkflow::class)->allowedReviewDecisions($locked), true)) {
                $this->decisionConflict($request, $locked, 'decision', 'This decision is not available for the current report status. Nothing was saved. Choose one of the available actions.');
            }
            $locked->citizen_reason = $data['reason'];
            if (! empty($data['governorate'])) {
                $locked->resolved_governorate = $data['governorate'];
            }
            if (! empty($data['category'])) {
                $locked->category = $data['category'];
            }
            if ($data['decision'] === 'accept') {
                $authority = AuthorityUnit::whereKey($data['authority_id'] ?? null)->where('active', true)->where('simulated', true)
                    ->where('municipality', config('moshkelti.municipality'))
                    ->where('category', $locked->category)->where('governorate', $locked->resolved_governorate ?? $locked->governorate)->first();
                if (! $authority) {
                    throw ValidationException::withMessages(['authority_id' => 'Choose an approved authority matching the reviewed category and governorate.'])
                        ->redirectTo(route('admin.reports.show', $locked));
                }
                $locked->authority_id = $authority->id;
            }
            $locked->save();
            $status = ReportWorkflow::REVIEW_DECISIONS[$data['decision']];
            $message = ['accept' => 'A human reviewer accepted this report.', 'reject' => 'A human reviewer declined this report; a reason and appeal are available.',
                'needs_more_information' => 'A human reviewer requested more information.', 'resolve' => 'The pilot reviewer marked this issue resolved.', 'reopen' => 'The report was reopened for human review.'][$data['decision']];
            try {
                $locked = app(ReportWorkflow::class)->transition($locked, $status, $message, $request->user());
            } catch (\DomainException) {
                $this->decisionConflict($request, $locked, 'decision', 'This decision is not available for the current report status. Nothing was saved. Choose one of the available actions.');
            }
            if ($data['publish'] ?? false) {
                app(PublicReportPublisher::class)->publish($locked, $data['public_title'], $data['public_summary']);
            }
            if ($status === 'accepted') {
                app(AuthorityDispatcher::class)->enqueue($locked);
            }
            app(SecurityAudit::class)->record('admin.report_decided', $request->user(), $locked, ['decision' => $data['decision']]);
        });

        return redirect()->route('admin.reports.show', $report)->with('status', 'Review decision recorded.');
    }

    private function decisionConflict(Request $request, Report $report, string $field, string $message): never
    {
        if ($request->expectsJson()) {
            abort(409, __($message));
        }

        throw ValidationException::withMessages([$field => $message])->redirectTo(route('admin.reports.show', $report));
    }

    public function users(): View
    {
        app(SecurityAudit::class)->record('admin.moderation_accessed', auth()->user());

        return view('admin.users', ['users' => User::with('restrictions')->where('is_admin', false)->orderByDesc('risk_flag')->orderByDesc('id')->paginate(20)]);
    }

    public function restrict(RestrictUserRequest $request, User $user): RedirectResponse
    {
        abort_if($user->is_admin, 403, 'Administrator restrictions require an operator workflow.');
        $data = $request->validated();
        DB::transaction(function () use ($request, $user, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->risk_flag = $data['risk_flag'] ?? null;
            $locked->save();
            if ($data['type'] === 'reversal') {
                $locked->restrictions()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            } else {
                UserRestriction::create(['user_id' => $locked->id, 'created_by' => $request->user()->id,
                    'type' => $data['type'], 'reason' => $data['reason'], 'starts_at' => now(),
                    'ends_at' => $data['type'] === 'temporary_suspend' ? $data['ends_at'] : null]);
            }
            app(SecurityAudit::class)->record('admin.account_restricted', $request->user(), $locked, ['type' => $data['type'], 'risk_flag' => $locked->risk_flag]);
        });

        return redirect()->route('admin.users')->with('status', 'Moderation decision recorded. Reading access is preserved.');
    }

    public function appealDecision(Request $request, Appeal $appeal): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['upheld', 'dismissed'])]]);
        abort_unless($appeal->status === 'pending', 409);
        $appeal->status = $data['decision'];
        $appeal->save();
        app(SecurityAudit::class)->record('admin.appeal_decided', $request->user(), $appeal, ['decision' => $data['decision']]);

        return back()->with('status', 'Appeal reviewed. Any reversal or reopening must be performed separately and audited.');
    }

    public function privacyDecision(Request $request, PrivacyRequest $privacyRequest): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['acknowledged', 'needs_verification'])]]);
        $privacyRequest->status = $data['decision'];
        $privacyRequest->save();
        app(SecurityAudit::class)->record('admin.privacy_acknowledged', $request->user(), $privacyRequest, ['decision' => $data['decision']]);

        return back()->with('status', 'Privacy request acknowledged; do not mark legal fulfillment without verification.');
    }
}
