<?php

namespace App\Http\Controllers;

use App\Http\Requests\AppealRequest;
use App\Http\Requests\PrivacyRequest as PrivacyInputRequest;
use App\Models\Appeal;
use App\Models\PrivacyRequest;
use App\Services\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function access(Request $request): JsonResponse
    {
        return response()->json($request->user()->submissionAccess());
    }

    public function appeal(AppealRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        Appeal::create(['user_id' => $request->user()->id, 'reason' => $data['reason']]);
        app(SecurityAudit::class)->record('appeal.account_created', $request->user());

        return $request->expectsJson() ? response()->json(['status' => 'appeal_received'], 202) : back()->with('status', 'Your appeal was received. Your reading access remains available.');
    }

    public function privacy(PrivacyInputRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        PrivacyRequest::create(['user_id' => $request->user()->id, 'type' => $data['type'], 'details' => $data['details'] ?? null]);
        if ($data['type'] === 'withdraw') {
            $request->user()->reports()->update(['external_ai_consent' => false]);
        }
        app(SecurityAudit::class)->record('privacy.request_created', $request->user(), null, ['type' => $data['type']]);

        return $request->expectsJson() ? response()->json(['status' => 'privacy_request_received'], 202) : back()->with('status', 'Privacy request recorded for authorized review.');
    }
}
