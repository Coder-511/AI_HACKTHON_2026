<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReportPolicy
{
    public function view(?User $user, Report $report): Response
    {
        return ($report->published_at && $report->published_at->lessThanOrEqualTo(now())) || ($user && ($user->id === $report->user_id || $user->is_admin))
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Report $report): Response
    {
        return $user->id === $report->user_id && $user->canSubmitIssues()
            && in_array($report->status, ['received', 'processing', 'needs_more_information'], true)
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function download(User $user, Report $report): Response
    {
        return ($user->is_admin || ($user->id === $report->user_id && $user->submissionAccess()['account_state'] !== 'banned'))
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function appeal(User $user, Report $report): Response
    {
        return $user->id === $report->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
