<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;

class SubmissionAccess
{
    public function details(User $user): array
    {
        $restrictions = $user->restrictions()->whereNull('revoked_at')->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->get();
        $ban = $restrictions->firstWhere('type', 'banned');
        $suspension = $restrictions->where('type', 'temporary_suspend')->sortByDesc('ends_at')->first();
        $state = $ban ? 'banned' : ($suspension ? 'temporarily_suspended' : 'active');
        $available = $ban ? null : $suspension?->ends_at;
        $canSubmit = $state === 'active' && ! $user->is_admin && ($user->hasVerifiedEmail() || $user->hasVerifiedCitizenIdentity());

        return [
            'code' => $canSubmit ? 'submission_allowed' : 'submission_not_allowed',
            'can_read' => true, 'can_submit' => $canSubmit, 'account_state' => $state,
            'available_at' => $available?->toIso8601String(), 'server_now' => now()->toIso8601String(),
            'remaining_seconds' => $available ? max(0, $available->getTimestamp() - now()->getTimestamp()) : 0,
            'reason' => $state !== 'active' ? 'account_restricted' : ($user->is_admin ? 'admin_read_only' : ($canSubmit ? null : 'verification_required')),
        ];
    }

    public function ensure(User $user): void
    {
        $access = $this->details($user);
        if (! $access['can_submit']) {
            RateLimiter::attempt('blocked-submission-audit:'.$user->id, 1,
                fn () => app(SecurityAudit::class)->record('submission.blocked', $user, null, ['state' => $access['account_state']]), 60);
            throw new HttpResponseException(response()->json($access, 403));
        }
    }
}
